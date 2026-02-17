<?php
/**
 * Digital Display: Group Stage
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/groups.php?id=TOURNAMENT_ID
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$refreshInterval = intval($_GET['refresh'] ?? 30);
if ($refreshInterval < 5) $refreshInterval = 5;

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    die('Tournament not found.');
}

// Get time slots (groups)
$slotsStmt = $db->prepare("
    SELECT ts.*, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id AND status != 'withdrawn') as team_count
    FROM time_slots ts WHERE ts.tournament_id = ?
    ORDER BY ts.slot_date, ts.slot_time
");
$slotsStmt->execute([$id]);
$timeSlots = $slotsStmt->fetchAll();

// Get matches
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
    WHERE m.tournament_id = ? AND m.bracket_type = 'round_robin'
    ORDER BY m.time_slot_id, m.round, m.match_number
");
$matchesStmt->execute([$id]);
$matches = $matchesStmt->fetchAll();

// Get standings
$standingsStmt = $db->prepare("
    SELECT rrs.*, t.team_name
    FROM round_robin_standings rrs
    JOIN teams t ON rrs.team_id = t.id
    WHERE rrs.tournament_id = ?
    ORDER BY rrs.time_slot_id, rrs.ranking, rrs.wins DESC, rrs.point_differential DESC
");
$standingsStmt->execute([$id]);
$standings = $standingsStmt->fetchAll();

// Group by time_slot_id
$standingsByGroup = [];
foreach ($standings as $s) {
    $gid = $s['time_slot_id'] ?? 'ungrouped';
    $standingsByGroup[$gid][] = $s;
}

$matchesByGroup = [];
foreach ($matches as $m) {
    $gid = $m['time_slot_id'] ?? 'ungrouped';
    $matchesByGroup[$gid][] = $m;
}

$slotLabels = [];
foreach ($timeSlots as $slot) {
    $slotLabels[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
}

$advanceCount = $tournament['two_stage_advance_count'] ?? 1;
$isTwoStage = ($tournament['tournament_type'] === 'two_stage');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Group Stage</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status"><?php echo $isTwoStage ? 'Group Stage' : 'Round Robin'; ?></span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <div style="margin-top: 8px;">
                <span class="live-indicator"><span class="live-dot"></span> Live</span>
            </div>
        </div>
    </div>

    <div class="display-container">
        <?php if (empty($standings) && empty($matches)): ?>
            <div class="display-empty">
                <p>Group stage has not started yet.</p>
            </div>
        <?php else: ?>
            <?php
            // Determine grid columns based on number of groups
            $groupCount = count($standingsByGroup);
            $gridClass = $groupCount >= 3 ? 'display-grid-3' : ($groupCount >= 2 ? 'display-grid-2' : '');
            ?>
            <div class="<?php echo $gridClass; ?>">
                <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
                <div class="display-card">
                    <h2><?php echo $groupId !== 'ungrouped' ? htmlspecialchars($slotLabels[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?></h2>

                    <!-- Standings -->
                    <table class="display-table">
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th class="stat-col">W</th>
                                <th class="stat-col">L</th>
                                <th class="stat-col">D</th>
                                <th class="stat-col">+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStandings as $s): ?>
                            <tr class="<?php echo $isTwoStage && ($s['ranking'] ?? 999) <= $advanceCount ? 'advancing' : ''; ?>">
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td class="team-name"><?php echo htmlspecialchars($s['team_name']); ?></td>
                                <td class="stat-col"><?php echo $s['wins']; ?></td>
                                <td class="stat-col"><?php echo $s['losses']; ?></td>
                                <td class="stat-col"><?php echo $s['draws']; ?></td>
                                <td class="stat-col <?php echo $s['point_differential'] >= 0 ? 'diff-positive' : 'diff-negative'; ?>">
                                    <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <!-- Matches for this group -->
                    <?php if (isset($matchesByGroup[$groupId])): ?>
                        <h3 style="margin-top: 20px;">Matches</h3>
                        <?php
                        $roundsInGroup = [];
                        foreach ($matchesByGroup[$groupId] as $m) {
                            $roundsInGroup[$m['round']][] = $m;
                        }
                        ksort($roundsInGroup);
                        ?>
                        <?php foreach ($roundsInGroup as $round => $roundMatches): ?>
                            <div class="display-muted" style="margin: 10px 0 6px;">Round <?php echo $round; ?></div>
                            <?php foreach ($roundMatches as $match): ?>
                            <div class="display-match">
                                <div class="display-match-header">
                                    <span>Match #<?php echo $match['match_number']; ?></span>
                                    <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team1_name'] ? htmlspecialchars($match['team1_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team1_score'] ?? '-'; ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team2_name'] ? htmlspecialchars($match['team2_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team2_score'] ?? '-'; ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if ($isTwoStage): ?>
            <p class="display-advance-note">
                &#9650; Highlighted teams advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
