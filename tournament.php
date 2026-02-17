<?php
/**
 * Public Tournament Detail & Bracket View
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ? AND status != 'draft'");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /');
    exit;
}

// Get teams
$teamsStmt = $db->prepare("
    SELECT t.team_name, t.captain_name, t.status, t.seed,
           ts.slot_label, ts.slot_date, ts.slot_time
    FROM teams t
    LEFT JOIN time_slots ts ON t.time_slot_id = ts.id
    WHERE t.tournament_id = ? AND t.status != 'withdrawn'
    ORDER BY t.seed, t.team_name
");
$teamsStmt->execute([$id]);
$teams = $teamsStmt->fetchAll();

// Get matches (include group label for two-stage)
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name,
           t2.team_name as team2_name,
           w.team_name as winner_name,
           ts.slot_label as group_label
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams w ON m.winner_id = w.id
    LEFT JOIN time_slots ts ON m.time_slot_id = ts.id
    WHERE m.tournament_id = ?
    ORDER BY m.bracket_type, m.time_slot_id, m.round, m.match_number
");
$matchesStmt->execute([$id]);
$matches = $matchesStmt->fetchAll();

// Get time slots
$slotsStmt = $db->prepare("
    SELECT ts.*, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id AND status != 'withdrawn') as team_count
    FROM time_slots ts WHERE ts.tournament_id = ?
    ORDER BY ts.slot_date, ts.slot_time
");
$slotsStmt->execute([$id]);
$timeSlots = $slotsStmt->fetchAll();

// Get standings (include group info, order by group for two-stage)
$standingsStmt = $db->prepare("
    SELECT rrs.*, t.team_name
    FROM round_robin_standings rrs
    JOIN teams t ON rrs.team_id = t.id
    WHERE rrs.tournament_id = ?
    ORDER BY rrs.time_slot_id, rrs.ranking, rrs.wins DESC, rrs.point_differential DESC
");
$standingsStmt->execute([$id]);
$standings = $standingsStmt->fetchAll();

// Group matches by bracket type
$groupedMatches = [];
$groupedMatchesByRound = [];
foreach ($matches as $m) {
    $groupedMatches[$m['bracket_type']][] = $m;
    $groupedMatchesByRound[$m['bracket_type']][$m['round']][] = $m;
}

$hasTimeSlots = in_array($tournament['tournament_type'], ['round_robin', 'two_stage']);
$isRegistrationOpen = ($tournament['status'] === 'registration_open' && count($teams) < $tournament['max_teams']);

$statusLabels = [
    'registration_open' => 'Registration Open',
    'registration_closed' => 'Registration Closed',
    'in_progress' => 'In Progress',
    'completed' => 'Completed',
    'cancelled' => 'Cancelled',
];

$typeLabels = [
    'single_elimination' => 'Single Elimination',
    'double_elimination' => 'Double Elimination',
    'round_robin' => 'Round Robin',
    'two_stage' => 'Two Stage',
];

$pageTitle = $tournament['name'];
$extraScripts = ['/assets/js/bracket.js'];
include __DIR__ . '/includes/header.php';
?>

<!-- Tournament Hero -->
<div class="page-hero">
    <div class="container">
        <h1 class="bounce-in"><?php echo h($tournament['name']); ?></h1>
        <div class="flex-center gap-1" style="flex-wrap: wrap;">
            <span class="badge badge-type" style="font-size: 13px; padding: 5px 14px;">
                <?php echo $typeLabels[$tournament['tournament_type']] ?? $tournament['tournament_type']; ?>
                <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
                    + <?php echo ucwords(str_replace('_', ' ', $tournament['two_stage_elimination_type'])); ?>
                <?php endif; ?>
            </span>
            <?php
            $statusMap = [
                'registration_open' => 'badge-open', 'registration_closed' => 'badge-closed',
                'in_progress' => 'badge-in-progress', 'completed' => 'badge-completed',
            ];
            ?>
            <span class="badge <?php echo $statusMap[$tournament['status']] ?? 'badge-draft'; ?>" style="font-size: 13px; padding: 5px 14px;">
                <?php echo $statusLabels[$tournament['status']] ?? $tournament['status']; ?>
            </span>
        </div>
        <p class="subtitle" style="margin-top: 15px;">
            Tournament #<?php echo h($tournament['tournament_number']); ?>
            <?php if ($tournament['start_date']): ?>
                &middot; <?php echo date('F j, Y', strtotime($tournament['start_date'])); ?>
            <?php endif; ?>
            <?php if ($tournament['location']): ?>
                &middot; <?php echo h($tournament['location']); ?>
            <?php endif; ?>
        </p>
        <?php if ($isRegistrationOpen): ?>
            <div style="margin-top: 20px;">
                <a href="/signup.php?tournament_id=<?php echo $id; ?>" class="btn btn-primary btn-large">Sign Up Your Team</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
    <!-- Two-Stage Tabs -->
    <div class="two-stage-tabs">
        <button class="two-stage-tab active" data-stage="info">Info</button>
        <button class="two-stage-tab" data-stage="round-robin">Group Stage</button>
        <button class="two-stage-tab" data-stage="standings">Standings</button>
        <button class="two-stage-tab" data-stage="elimination">Elimination</button>
        <button class="two-stage-tab" data-stage="teams">Teams (<?php echo count($teams); ?>)</button>
    </div>

    <!-- Info Panel -->
    <div class="stage-panel active" id="stage-info">
    <?php else: ?>
    <!-- Non-two-stage: show info first -->
    <div>
    <?php endif; ?>

        <!-- Tournament Info -->
        <?php if ($tournament['description']): ?>
        <div class="card fade-in">
            <h3>About This Tournament</h3>
            <p><?php echo nl2br(h($tournament['description'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Key Details -->
        <div class="card fade-in">
            <h3>Details</h3>
            <ul class="tournament-meta">
                <li>
                    <span class="meta-label">Teams</span>
                    <span><?php echo count($teams); ?> / <?php echo $tournament['max_teams']; ?> registered</span>
                </li>
                <?php if ($tournament['registration_deadline'] && $tournament['status'] === 'registration_open'): ?>
                <li>
                    <span class="meta-label">Deadline</span>
                    <span style="color: var(--color-warning);"><?php echo date('F j, Y \a\t g:i A', strtotime($tournament['registration_deadline'])); ?></span>
                </li>
                <?php endif; ?>
                <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
                <li>
                    <span class="meta-label">Stage 1</span>
                    <span>Group Stage (<?php echo count($timeSlots); ?> groups)</span>
                </li>
                <li>
                    <span class="meta-label">Stage 2</span>
                    <span><?php echo ucwords(str_replace('_', ' ', $tournament['two_stage_elimination_type'])); ?> (top <?php echo $tournament['two_stage_advance_count']; ?> per group)</span>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <?php if ($tournament['rules']): ?>
        <div class="card fade-in">
            <h3>Rules</h3>
            <p><?php echo nl2br(h($tournament['rules'])); ?></p>
        </div>
        <?php endif; ?>

        <!-- Time Slots (for RR and Two-Stage) -->
        <?php if ($hasTimeSlots && !empty($timeSlots)): ?>
        <div class="card fade-in">
            <h3><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Groups' : 'Schedule / Time Slots'; ?></h3>
            <div class="time-slots-grid">
                <?php foreach ($timeSlots as $slot): ?>
                <div class="time-slot-card <?php echo $slot['team_count'] >= $slot['max_teams'] ? 'slot-full' : ''; ?>">
                    <div class="time-slot-time"><?php echo date('g:i A', strtotime($slot['slot_time'])); ?></div>
                    <div class="time-slot-date"><?php echo date('l, M j', strtotime($slot['slot_date'])); ?></div>
                    <?php if ($slot['slot_label']): ?>
                        <div style="font-size: 13px; color: var(--color-light-gray); margin-bottom: 4px;"><?php echo h($slot['slot_label']); ?></div>
                    <?php endif; ?>
                    <div class="time-slot-capacity">
                        <?php if ($slot['team_count'] >= $slot['max_teams']): ?>
                            <span class="spots-full">Full</span>
                        <?php else: ?>
                            <span class="spots-left"><?php echo $slot['max_teams'] - $slot['team_count']; ?> spots left</span>
                        <?php endif; ?>
                        (<?php echo $slot['team_count']; ?>/<?php echo $slot['max_teams']; ?>)
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
    <!-- Group Stage Standings Panel -->
    <div class="stage-panel" id="stage-round-robin">
        <?php if (!empty($standings)): ?>
            <?php
            // Per-group standings for two-stage
            $standingsByGroup = [];
            foreach ($standings as $s) {
                $groupId = $s['time_slot_id'] ?? 'ungrouped';
                $standingsByGroup[$groupId][] = $s;
            }
            $slotLabelsPublic = [];
            foreach ($timeSlots as $slot) {
                $slotLabelsPublic[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
            }
            $advanceCount = $tournament['two_stage_advance_count'] ?? 1;
            ?>

            <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
            <div class="card fade-in" style="margin-bottom: 20px;">
                <h3 style="color: var(--color-gold);">
                    <?php echo $groupId !== 'ungrouped' ? h($slotLabelsPublic[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?>
                </h3>
                <div class="table-wrapper standings-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th>W</th>
                                <th>L</th>
                                <th>D</th>
                                <th>PF</th>
                                <th>PA</th>
                                <th>+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStandings as $s): ?>
                            <tr class="<?php echo ($s['ranking'] ?? 999) <= $advanceCount ? 'advancing' : ''; ?>">
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td><strong><?php echo h($s['team_name']); ?></strong></td>
                                <td><?php echo $s['wins']; ?></td>
                                <td><?php echo $s['losses']; ?></td>
                                <td><?php echo $s['draws']; ?></td>
                                <td><?php echo $s['points_for']; ?></td>
                                <td><?php echo $s['points_against']; ?></td>
                                <td style="color: <?php echo $s['point_differential'] >= 0 ? 'var(--color-success)' : 'var(--color-danger)'; ?>">
                                    <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Group match results inline -->
                <?php if (isset($groupedMatches['round_robin'])): ?>
                    <?php
                    $groupMatchesFiltered = array_filter($groupedMatches['round_robin'], fn($m) => ($m['time_slot_id'] ?? null) == ($groupId !== 'ungrouped' ? $groupId : null));
                    if (!empty($groupMatchesFiltered)):
                        $roundsInGroup = [];
                        foreach ($groupMatchesFiltered as $m) {
                            $roundsInGroup[$m['round']][] = $m;
                        }
                        ksort($roundsInGroup);
                    ?>
                    <h4 class="text-muted" style="font-size: 14px; margin: 20px 0 10px;">Match Results</h4>
                    <?php foreach ($roundsInGroup as $round => $roundMatches): ?>
                        <h4 class="text-muted" style="font-size: 13px; margin: 10px 0 8px;">Round <?php echo $round; ?></h4>
                        <?php foreach ($roundMatches as $match): ?>
                        <div class="bracket-match" style="margin-bottom: 8px; max-width: 400px;">
                            <div class="bracket-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                <span class="bracket-team-name"><?php echo $match['team1_name'] ? h($match['team1_name']) : 'TBD'; ?></span>
                                <span class="bracket-team-score"><?php echo $match['team1_score'] ?? '-'; ?></span>
                            </div>
                            <div class="bracket-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                <span class="bracket-team-name"><?php echo $match['team2_name'] ? h($match['team2_name']) : 'TBD'; ?></span>
                                <span class="bracket-team-score"><?php echo $match['team2_score'] ?? '-'; ?></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>

            <p class="text-muted" style="font-size: 13px; text-align: center;">
                &#9650; Highlighted teams advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
            </p>
        <?php else: ?>
            <div class="empty-state">
                <p class="text-muted">Group stage standings will appear after matches are generated.</p>
            </div>
        <?php endif; ?>
    </div><!-- /stage-round-robin -->

    <!-- Standings Panel (Two-Stage) -->
    <div class="stage-panel" id="stage-standings">
        <?php if (!empty($standings)): ?>
            <?php
            if (!isset($standingsByGroup)) {
                $standingsByGroup = [];
                foreach ($standings as $s) {
                    $groupId = $s['time_slot_id'] ?? 'ungrouped';
                    $standingsByGroup[$groupId][] = $s;
                }
            }
            if (!isset($slotLabelsPublic)) {
                $slotLabelsPublic = [];
                foreach ($timeSlots as $slot) {
                    $slotLabelsPublic[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
                }
            }
            if (!isset($advanceCount)) {
                $advanceCount = $tournament['two_stage_advance_count'] ?? 1;
            }
            ?>

            <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
            <div class="card fade-in" style="margin-bottom: 20px;">
                <h3 style="color: var(--color-gold);">
                    <?php echo $groupId !== 'ungrouped' ? h($slotLabelsPublic[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?>
                </h3>
                <div class="table-wrapper standings-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th>W</th>
                                <th>L</th>
                                <th>D</th>
                                <th>PF</th>
                                <th>PA</th>
                                <th>+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStandings as $s): ?>
                            <tr class="<?php echo ($s['ranking'] ?? 999) <= $advanceCount ? 'advancing' : ''; ?>">
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td><strong><?php echo h($s['team_name']); ?></strong></td>
                                <td><?php echo $s['wins']; ?></td>
                                <td><?php echo $s['losses']; ?></td>
                                <td><?php echo $s['draws']; ?></td>
                                <td><?php echo $s['points_for']; ?></td>
                                <td><?php echo $s['points_against']; ?></td>
                                <td style="color: <?php echo $s['point_differential'] >= 0 ? 'var(--color-success)' : 'var(--color-danger)'; ?>">
                                    <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>

            <p class="text-muted" style="font-size: 13px; text-align: center;">
                &#9650; Highlighted teams advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
            </p>
        <?php else: ?>
            <div class="empty-state">
                <p class="text-muted">Standings will appear after matches are generated and results recorded.</p>
            </div>
        <?php endif; ?>
    </div><!-- /stage-standings -->

    <!-- Elimination Bracket Panel -->
    <div class="stage-panel" id="stage-elimination">

    <?php elseif (!empty($standings)): ?>
    <!-- Standalone Round Robin standings -->
    <div>
        <div class="card fade-in">
            <h3>Round Robin Standings</h3>
            <div class="table-wrapper standings-table">
                <table>
                    <thead>
                        <tr>
                            <th class="rank-col">#</th>
                            <th>Team</th>
                            <th>W</th>
                            <th>L</th>
                            <th>D</th>
                            <th>PF</th>
                            <th>PA</th>
                            <th>+/-</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($standings as $s): ?>
                        <tr>
                            <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                            <td><strong><?php echo h($s['team_name']); ?></strong></td>
                            <td><?php echo $s['wins']; ?></td>
                            <td><?php echo $s['losses']; ?></td>
                            <td><?php echo $s['draws']; ?></td>
                            <td><?php echo $s['points_for']; ?></td>
                            <td><?php echo $s['points_against']; ?></td>
                            <td style="color: <?php echo $s['point_differential'] >= 0 ? 'var(--color-success)' : 'var(--color-danger)'; ?>">
                                <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- RR Match Results (standard) -->
        <?php if (isset($groupedMatchesByRound['round_robin'])): ?>
        <div class="card fade-in">
            <h3>Round Robin Matches</h3>
            <?php foreach ($groupedMatchesByRound['round_robin'] as $round => $roundMatches): ?>
                <h4 class="text-muted" style="font-size: 14px; margin: 15px 0 8px;">Round <?php echo $round; ?></h4>
                <?php foreach ($roundMatches as $match): ?>
                <div class="bracket-match" style="margin-bottom: 8px; max-width: 400px;">
                    <div class="bracket-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                        <span class="bracket-team-name"><?php echo $match['team1_name'] ? h($match['team1_name']) : 'TBD'; ?></span>
                        <span class="bracket-team-score"><?php echo $match['team1_score'] ?? '-'; ?></span>
                    </div>
                    <div class="bracket-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                        <span class="bracket-team-name"><?php echo $match['team2_name'] ? h($match['team2_name']) : 'TBD'; ?></span>
                        <span class="bracket-team-score"><?php echo $match['team2_score'] ?? '-'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Bracket Display (Single/Double Elimination) -->
    <?php if (isset($groupedMatchesByRound['winners'])): ?>
    <div class="card fade-in">
        <h3>
            <?php if ($tournament['tournament_type'] === 'double_elimination' || (isset($groupedMatchesByRound['losers']))): ?>
                Winners Bracket
            <?php else: ?>
                Bracket
            <?php endif; ?>
        </h3>
        <div class="bracket-container">
            <div class="bracket" id="winners-bracket">
                <?php foreach ($groupedMatchesByRound['winners'] as $round => $roundMatches): ?>
                <div class="bracket-round">
                    <div class="bracket-round-title">
                        <?php
                        $totalRounds = count($groupedMatchesByRound['winners']);
                        if ($round === $totalRounds) echo 'Final';
                        elseif ($round === $totalRounds - 1) echo 'Semifinals';
                        elseif ($round === $totalRounds - 2) echo 'Quarterfinals';
                        else echo 'Round ' . $round;
                        ?>
                    </div>
                    <?php foreach ($roundMatches as $match): ?>
                    <div class="bracket-match">
                        <div class="bracket-match-header">Match <?php echo $match['match_number']; ?></div>
                        <div class="bracket-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team1_id'] ? 'loser' : ''); ?> <?php echo !$match['team1_id'] ? 'tbd' : ''; ?>">
                            <span class="bracket-team-name"><?php echo $match['team1_name'] ? h($match['team1_name']) : 'TBD'; ?></span>
                            <span class="bracket-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                        </div>
                        <div class="bracket-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team2_id'] ? 'loser' : ''); ?> <?php echo !$match['team2_id'] ? 'tbd' : ''; ?>">
                            <span class="bracket-team-name"><?php echo $match['team2_name'] ? h($match['team2_name']) : 'TBD'; ?></span>
                            <span class="bracket-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Losers Bracket -->
    <?php if (isset($groupedMatchesByRound['losers'])): ?>
    <div class="card fade-in">
        <h3>Losers Bracket</h3>
        <div class="bracket-container">
            <div class="bracket" id="losers-bracket">
                <?php foreach ($groupedMatchesByRound['losers'] as $round => $roundMatches): ?>
                <div class="bracket-round">
                    <div class="bracket-round-title">Round <?php echo $round; ?></div>
                    <?php foreach ($roundMatches as $match): ?>
                    <div class="bracket-match">
                        <div class="bracket-match-header">Match <?php echo $match['match_number']; ?></div>
                        <div class="bracket-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team1_id'] ? 'loser' : ''); ?> <?php echo !$match['team1_id'] ? 'tbd' : ''; ?>">
                            <span class="bracket-team-name"><?php echo $match['team1_name'] ? h($match['team1_name']) : 'TBD'; ?></span>
                            <span class="bracket-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                        </div>
                        <div class="bracket-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team2_id'] ? 'loser' : ''); ?> <?php echo !$match['team2_id'] ? 'tbd' : ''; ?>">
                            <span class="bracket-team-name"><?php echo $match['team2_name'] ? h($match['team2_name']) : 'TBD'; ?></span>
                            <span class="bracket-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Grand Final -->
    <?php if (isset($groupedMatchesByRound['grand_final'])): ?>
    <div class="card fade-in">
        <h3 style="color: var(--color-gold);">Grand Final</h3>
        <?php foreach ($groupedMatchesByRound['grand_final'] as $round => $roundMatches): ?>
            <?php foreach ($roundMatches as $match): ?>
            <div class="bracket-match" style="max-width: 300px; margin: 0 auto;">
                <div class="bracket-match-header">Grand Final</div>
                <div class="bracket-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ''; ?> <?php echo !$match['team1_id'] ? 'tbd' : ''; ?>">
                    <span class="bracket-team-name"><?php echo $match['team1_name'] ? h($match['team1_name']) : 'TBD'; ?></span>
                    <span class="bracket-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                </div>
                <div class="bracket-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ''; ?> <?php echo !$match['team2_id'] ? 'tbd' : ''; ?>">
                    <span class="bracket-team-name"><?php echo $match['team2_name'] ? h($match['team2_name']) : 'TBD'; ?></span>
                    <span class="bracket-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
        <?php if (!isset($groupedMatchesByRound['winners']) && !isset($groupedMatchesByRound['losers']) && !isset($groupedMatchesByRound['grand_final'])): ?>
        <div class="empty-state">
            <p class="text-muted">The elimination bracket will appear after the group stage is complete.</p>
        </div>
        <?php endif; ?>
    </div><!-- /stage-elimination -->

    <!-- Teams Panel (Two-Stage) -->
    <div class="stage-panel" id="stage-teams">
    <?php endif; ?>

    <!-- Teams List -->
    <div class="card fade-in">
        <h3>Registered Teams (<?php echo count($teams); ?>)</h3>
        <?php if (empty($teams)): ?>
            <p class="text-muted">No teams registered yet.</p>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Team</th>
                            <th>Captain</th>
                            <?php if ($hasTimeSlots): ?><th><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Group' : 'Time Slot'; ?></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teams as $i => $team): ?>
                        <tr>
                            <td><?php echo $team['seed'] ?? ($i + 1); ?></td>
                            <td><strong><?php echo h($team['team_name']); ?></strong></td>
                            <td><?php echo h($team['captain_name']); ?></td>
                            <?php if ($hasTimeSlots): ?>
                            <td>
                                <?php if ($team['slot_label']): ?>
                                    <?php echo h($team['slot_label']); ?>
                                    <small class="text-muted">(<?php echo date('M j, g:i A', strtotime($team['slot_date'] . ' ' . $team['slot_time'])); ?>)</small>
                                <?php else: ?>
                                    <span class="text-muted">--</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
    </div><!-- /stage-teams -->
    <?php endif; ?>

    <!-- Sign Up CTA -->
    <?php if ($isRegistrationOpen): ?>
    <div class="text-center mt-3 mb-3">
        <a href="/signup.php?tournament_id=<?php echo $id; ?>" class="btn btn-primary btn-large">Sign Up Your Team</a>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
