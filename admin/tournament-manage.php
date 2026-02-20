<?php
/**
 * Tournament Management Page
 * Sherwood Adventure Tournament System
 *
 * Tabs: Overview, Teams, Matches/Bracket, Time Slots
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

// Fetch teams
$teamsStmt = $db->prepare("
    SELECT t.*, ts.slot_label, ts.slot_date, ts.slot_time
    FROM teams t
    LEFT JOIN time_slots ts ON t.time_slot_id = ts.id
    WHERE t.tournament_id = ?
    ORDER BY t.seed, t.created_at
");
$teamsStmt->execute([$id]);
$teams = $teamsStmt->fetchAll();

// Fetch matches (include group label for two-stage)
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
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

// Fetch time slots
$slotsStmt = $db->prepare("
    SELECT ts.*,
           (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id) as team_count
    FROM time_slots ts
    WHERE ts.tournament_id = ?
    ORDER BY ts.slot_date, ts.slot_time
");
$slotsStmt->execute([$id]);
$timeSlots = $slotsStmt->fetchAll();

// Fetch standings for round robin (include group info)
$standingsStmt = $db->prepare("
    SELECT rrs.*, t.team_name, t.is_forfeit, t.logo_path
    FROM round_robin_standings rrs
    JOIN teams t ON rrs.team_id = t.id
    WHERE rrs.tournament_id = ?
    ORDER BY rrs.time_slot_id, rrs.ranking, rrs.wins DESC, rrs.point_differential DESC
");
$standingsStmt->execute([$id]);
$standings = $standingsStmt->fetchAll();

// Feature flags based on tournament type
$hasTimeSlots = in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league']);
$isLeague = ($tournament['tournament_type'] === 'league');
$hasStandings = in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league']);

// Detect if this league has grouped matches (teams assigned to time slots).
// This determines whether matches/standings are displayed per-group or as a flat list.
$isLeagueWithGroups = false;
if ($isLeague && !empty($matches)) {
    foreach ($matches as $m) {
        if (!empty($m['time_slot_id'])) { $isLeagueWithGroups = true; break; }
    }
}

$pageTitle = 'Manage: ' . $tournament['name'];
$extraScripts = ['/assets/js/admin.js'];
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <div>
            <h1><?php echo h($tournament['name']); ?></h1>
            <div class="flex gap-1" style="margin-top: 8px;">
                <span class="badge badge-type"><?php echo h(ucwords(str_replace('_', ' ', $tournament['tournament_type']))); ?></span>
                <?php
                $statusMap = [
                    'draft' => 'badge-draft', 'registration_open' => 'badge-open',
                    'registration_closed' => 'badge-closed', 'in_progress' => 'badge-in-progress',
                    'completed' => 'badge-completed', 'cancelled' => 'badge-cancelled',
                ];
                ?>
                <span class="badge <?php echo $statusMap[$tournament['status']] ?? 'badge-draft'; ?>">
                    <?php echo h(ucwords(str_replace('_', ' ', $tournament['status']))); ?>
                </span>
                <code style="color: var(--color-orange); font-size: 13px; padding: 3px 8px; background: rgba(255,161,51,0.1); border-radius: 4px;">
                    #<?php echo h($tournament['tournament_number']); ?>
                </code>
            </div>
        </div>
        <div class="admin-actions">
            <a href="/admin/tournament-edit.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-small">Edit Details</a>
            <a href="/tournament.php?id=<?php echo $id; ?>" class="btn btn-secondary btn-small" target="_blank">Public View</a>
            <a href="/admin/dashboard.php" class="btn btn-secondary btn-small">Dashboard</a>
        </div>
    </div>

    <!-- Tabs -->
    <div class="admin-tabs">
        <button class="admin-tab active" data-tab="overview">Overview</button>
        <button class="admin-tab" data-tab="teams">Teams (<?php echo count($teams); ?>)</button>
        <button class="admin-tab" data-tab="matches">Matches / Bracket</button>
        <?php if ($hasTimeSlots): ?>
        <button class="admin-tab" data-tab="timeslots"><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Groups' : 'Time Slots'; ?></button>
        <?php endif; ?>
        <?php if ($hasStandings): ?>
        <button class="admin-tab" data-tab="standings">Standings</button>
        <?php endif; ?>
    </div>

    <!-- OVERVIEW TAB -->
    <div class="admin-tab-panel active" id="tab-overview">
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo count($teams); ?></div>
                <div class="stat-label">Teams Registered</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo $tournament['max_teams']; ?></div>
                <div class="stat-label">Max Teams</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count($matches); ?></div>
                <div class="stat-label">Matches</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?php echo count(array_filter($matches, fn($m) => $m['status'] === 'completed')); ?></div>
                <div class="stat-label">Completed</div>
            </div>
        </div>

        <div class="form-section">
            <h3 class="form-section-title">Quick Actions</h3>
            <div class="flex gap-1" style="flex-wrap: wrap;">
                <?php if ($tournament['status'] === 'draft'): ?>
                    <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                        <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="action" value="open_registration">
                        <button type="submit" class="btn btn-primary">Open Registration</button>
                    </form>
                <?php endif; ?>

                <?php if ($tournament['status'] === 'registration_open'): ?>
                    <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                        <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="action" value="close_registration">
                        <button type="submit" class="btn btn-secondary">Close Registration</button>
                    </form>
                <?php endif; ?>

                <?php if (in_array($tournament['status'], ['registration_closed', 'registration_open']) && count($teams) >= $tournament['min_teams']): ?>
                    <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                        <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="action" value="generate_bracket">
                        <button type="submit" class="btn btn-primary" onclick="return confirm('Generate bracket/schedule? This will create all matches.')">
                            Generate Bracket / Schedule
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($tournament['status'] === 'in_progress'): ?>
                    <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                        <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="action" value="complete">
                        <button type="submit" class="btn btn-secondary" onclick="return confirm('Mark tournament as completed?')">
                            Mark Completed
                        </button>
                    </form>
                <?php endif; ?>

                <form method="POST" action="/api/tournament-action.php" style="display: inline; margin-left: auto;">
                    <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('PERMANENTLY DELETE this tournament and ALL its teams, matches, and standings? This cannot be undone!')">
                        Delete Tournament
                    </button>
                </form>
            </div>
        </div>

        <?php if ($tournament['description']): ?>
        <div class="form-section">
            <h3 class="form-section-title">Description</h3>
            <p><?php echo nl2br(h($tournament['description'])); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($tournament['rules']): ?>
        <div class="form-section">
            <h3 class="form-section-title">Rules</h3>
            <p><?php echo nl2br(h($tournament['rules'])); ?></p>
        </div>
        <?php endif; ?>
    </div>

    <!-- TEAMS TAB -->
    <div class="admin-tab-panel" id="tab-teams">
        <div class="form-section">
            <div class="flex-between mb-2">
                <h3 class="form-section-title" style="margin-bottom: 0;">Registered Teams</h3>
                <a href="/admin/team-add.php?tournament_id=<?php echo $id; ?>" class="btn btn-primary btn-small">+ Add Team</a>
            </div>

            <?php if (empty($teams)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#128101;</div>
                    <h3>No teams registered yet</h3>
                    <p>Share the signup link or add teams manually.</p>
                </div>
            <?php else: ?>
                <div class="admin-table-wrapper">
                    <table>
                        <thead>
                            <tr>
                                <th>Seed</th>
                                <th>Team Name</th>
                                <th>Captain</th>
                                <th>Email</th>
                                <?php if ($hasTimeSlots): ?><th><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Group' : 'Time Slot'; ?></th><?php endif; ?>
                                <th>Status</th>
                                <th>Reg. Code</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($teams as $i => $team): ?>
                            <tr>
                                <td>
                                    <input type="number" class="form-control" style="width: 60px; padding: 4px 8px; font-size: 13px;"
                                           value="<?php echo $team['seed'] ?? ($i + 1); ?>"
                                           data-team-id="<?php echo $team['id']; ?>"
                                           onchange="updateSeed(this)">
                                </td>
                                <td><strong><?php echo teamNameHtml($team['team_name'], $team['is_forfeit'], $team['logo_path'], 'sm'); ?></strong></td>
                                <td><?php echo h($team['captain_name']); ?></td>
                                <td><a href="mailto:<?php echo h($team['captain_email']); ?>"><?php echo h($team['captain_email']); ?></a></td>
                                <?php if ($hasTimeSlots): ?>
                                <td>
                                    <?php if ($team['slot_label']): ?>
                                        <?php echo h($team['slot_label']); ?><br>
                                        <small class="text-muted"><?php echo date('M j', strtotime($team['slot_date'])); ?> <?php echo date('g:i A', strtotime($team['slot_time'])); ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">None</span>
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td><span class="badge badge-<?php echo $team['status'] === 'registered' ? 'open' : ($team['status'] === 'withdrawn' ? 'cancelled' : 'draft'); ?>"><?php echo h(ucfirst($team['status'])); ?></span></td>
                                <td><code style="font-size: 12px;"><?php echo h($team['registration_code']); ?></code></td>
                                <td>
                                    <div class="admin-table-actions">
                                        <a href="/admin/team-edit.php?id=<?php echo $team['id']; ?>" class="btn btn-secondary btn-small">Edit</a>
                                        <?php if (!$team['is_forfeit']): ?>
                                        <form method="POST" action="/api/team-action.php" style="display: inline;">
                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                            <input type="hidden" name="action" value="forfeit">
                                            <input type="hidden" name="redirect" value="/admin/tournament-manage.php?id=<?php echo $id; ?>">
                                            <button type="submit" class="btn btn-secondary btn-small" onclick="return confirm('Forfeit this team? Their pending matches will be resolved as losses.')" style="color: var(--color-warning);">Forfeit</button>
                                        </form>
                                        <?php else: ?>
                                        <form method="POST" action="/api/team-action.php" style="display: inline;">
                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                            <input type="hidden" name="action" value="unforfeit">
                                            <input type="hidden" name="redirect" value="/admin/tournament-manage.php?id=<?php echo $id; ?>">
                                            <button type="submit" class="btn btn-secondary btn-small">Unforfeit</button>
                                        </form>
                                        <?php endif; ?>
                                        <form method="POST" action="/api/team-action.php" style="display: inline;">
                                            <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                                            <input type="hidden" name="action" value="withdraw">
                                            <input type="hidden" name="redirect" value="/admin/tournament-manage.php?id=<?php echo $id; ?>">
                                            <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('Withdraw this team?')">Withdraw</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- MATCHES TAB -->
    <div class="admin-tab-panel" id="tab-matches">
        <div class="form-section">
            <h3 class="form-section-title">Matches / Bracket</h3>

            <?php if (empty($matches)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">&#9876;</div>
                    <h3>No matches generated yet</h3>
                    <p>Generate the bracket from the Overview tab when enough teams are registered.</p>
                </div>
            <?php else: ?>
                <?php
                // Group matches by bracket type, then by group (for two-stage RR), then by round
                $grouped = [];
                foreach ($matches as $m) {
                    $grouped[$m['bracket_type']][] = $m;
                }

                $isTwoStageManage = ($tournament['tournament_type'] === 'two_stage');
                $showGroupedMatches = ($isTwoStageManage || $isLeagueWithGroups);
                // Build slot label lookup for group headers
                $slotLabelsMatch = [];
                foreach ($timeSlots as $slot) {
                    $slotLabelsMatch[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
                }
                ?>

                <?php foreach ($grouped as $bracketType => $bracketMatches): ?>
                    <h4 style="margin: 20px 0 10px;">
                        <?php
                        if ($bracketType === 'round_robin' && $isTwoStageManage) {
                            echo 'Group Stage';
                        } elseif ($bracketType === 'round_robin' && $isLeagueWithGroups) {
                            echo 'League Schedule';
                        } else {
                            echo h(ucwords(str_replace('_', ' ', $bracketType))) . ' Bracket';
                        }
                        ?>
                    </h4>

                    <?php if ($bracketType === 'round_robin' && $showGroupedMatches): ?>
                        <?php
                        // Organize RR matches by group for two-stage or league with groups
                        $matchesByGroup = [];
                        foreach ($bracketMatches as $m) {
                            $gid = $m['time_slot_id'] ?? 'ungrouped';
                            $matchesByGroup[$gid][] = $m;
                        }
                        ?>
                        <?php foreach ($matchesByGroup as $groupId => $groupMatches): ?>
                            <h4 style="margin: 15px 0 10px; color: var(--color-gold); font-size: 15px;">
                                <?php echo $groupId !== 'ungrouped' ? h($slotLabelsMatch[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?>
                            </h4>
                            <?php
                            // Group by round within this group
                            $roundsInGroup = [];
                            foreach ($groupMatches as $m) {
                                $roundsInGroup[$m['round']][] = $m;
                            }
                            ksort($roundsInGroup);
                            ?>
                            <?php foreach ($roundsInGroup as $round => $roundMatches): ?>
                                <?php usort($roundMatches, function($a, $b) { return ($a['status'] === 'completed') - ($b['status'] === 'completed') ?: $a['match_number'] - $b['match_number']; }); ?>
                                <h4 class="text-muted" style="font-size: 13px; margin: 10px 0 8px;">Round <?php echo abs($round); ?></h4>
                                <?php foreach ($roundMatches as $match): ?>
                                    <?php if (!$match['team1_id'] && !$match['team2_id'] && $match['status'] !== 'completed') continue; ?>
                                    <?php include __DIR__ . '/../includes/_match-editor.php'; ?>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php
                        // Standard: group by round
                        $rounds = [];
                        foreach ($bracketMatches as $m) {
                            $rounds[$m['round']][] = $m;
                        }
                        ksort($rounds);
                        ?>
                        <?php foreach ($rounds as $round => $roundMatches): ?>
                            <?php usort($roundMatches, function($a, $b) { return ($a['status'] === 'completed') - ($b['status'] === 'completed') ?: $a['match_number'] - $b['match_number']; }); ?>
                            <h4 class="text-muted" style="font-size: 13px; margin: 15px 0 8px;"><?php echo $isLeague ? 'Week' : 'Round'; ?> <?php echo abs($round); ?></h4>
                            <?php foreach ($roundMatches as $match): ?>
                                <?php if (!$match['team1_id'] && !$match['team2_id'] && $match['status'] !== 'completed') continue; ?>
                                <?php include __DIR__ . '/../includes/_match-editor.php'; ?>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- TIME SLOTS TAB -->
    <?php if ($hasTimeSlots): ?>
    <div class="admin-tab-panel" id="tab-timeslots">
        <div class="form-section">
            <h3 class="form-section-title"><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Group Assignments' : 'Time Slot Assignments'; ?></h3>

            <?php if (empty($timeSlots)): ?>
                <div class="empty-state">
                    <p>No <?php echo $tournament['tournament_type'] === 'two_stage' ? 'groups' : 'time slots'; ?> configured. <a href="/admin/tournament-edit.php?id=<?php echo $id; ?>">Add <?php echo $tournament['tournament_type'] === 'two_stage' ? 'groups' : 'time slots'; ?> in Edit</a></p>
                </div>
            <?php else: ?>
                <?php foreach ($timeSlots as $slot): ?>
                <div class="card" style="margin-bottom: 16px;">
                    <div class="flex-between">
                        <div>
                            <h4 style="color: var(--color-gold); margin-bottom: 4px;">
                                <?php echo h($slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time']))); ?>
                            </h4>
                            <span class="text-muted" style="font-size: 13px;">
                                <?php echo date('l, M j, Y', strtotime($slot['slot_date'])); ?> at <?php echo date('g:i A', strtotime($slot['slot_time'])); ?>
                            </span>
                        </div>
                        <div>
                            <span class="badge <?php echo $slot['team_count'] >= $slot['max_teams'] ? 'badge-cancelled' : 'badge-open'; ?>">
                                <?php echo $slot['team_count']; ?> / <?php echo $slot['max_teams']; ?> Teams
                            </span>
                        </div>
                    </div>

                    <?php
                    // Get teams in this slot
                    $slotTeamsStmt = $db->prepare("SELECT team_name, captain_name FROM teams WHERE time_slot_id = ?");
                    $slotTeamsStmt->execute([$slot['id']]);
                    $slotTeams = $slotTeamsStmt->fetchAll();
                    ?>
                    <?php if (!empty($slotTeams)): ?>
                        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--color-brown-border);">
                            <?php foreach ($slotTeams as $st): ?>
                                <span style="display: inline-block; background: rgba(255,161,51,0.1); border: 1px solid var(--color-brown-border); border-radius: 6px; padding: 4px 12px; margin: 3px; font-size: 13px;">
                                    <?php echo h($st['team_name']); ?>
                                    <small class="text-muted">(<?php echo h($st['captain_name']); ?>)</small>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- STANDINGS TAB -->
    <?php if ($hasStandings): ?>
    <div class="admin-tab-panel" id="tab-standings">
        <div class="form-section">
            <div class="flex-between mb-2">
                <h3 class="form-section-title" style="margin-bottom: 0;">
                    <?php
                    if ($tournament['tournament_type'] === 'two_stage') echo 'Group Stage Standings';
                    elseif ($isLeague) echo 'League Standings';
                    else echo 'Round Robin Standings';
                    ?>
                </h3>
                <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                    <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">
                    <input type="hidden" name="action" value="recalculate_standings">
                    <button type="submit" class="btn btn-secondary btn-small">Recalculate</button>
                </form>
            </div>

            <?php if (empty($standings)): ?>
                <div class="empty-state">
                    <p>Standings will appear after matches are generated and results recorded.</p>
                </div>
            <?php elseif ($tournament['tournament_type'] === 'two_stage' || $isLeagueWithGroups): ?>
                <?php
                // Group standings by time_slot_id for per-group display
                $standingsByGroup = [];
                foreach ($standings as $s) {
                    $groupId = $s['time_slot_id'] ?? 'ungrouped';
                    $standingsByGroup[$groupId][] = $s;
                }
                // Build a lookup of time slot labels
                $slotLabels = [];
                foreach ($timeSlots as $slot) {
                    $slotLabels[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
                }
                $advanceCount = $tournament['two_stage_advance_count'] ?? 1;
                ?>

                <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
                    <h4 style="margin: 20px 0 10px; color: var(--color-gold); font-size: 16px;">
                        <?php echo $groupId !== 'ungrouped' ? h($slotLabels[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?>
                    </h4>
                    <div class="admin-table-wrapper standings-table" style="margin-bottom: 20px;">
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
                                    <td><strong><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'xs'); ?></strong></td>
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
                <?php endforeach; ?>

                <?php if ($tournament['tournament_type'] === 'two_stage'): ?>
                <p class="text-muted mt-1" style="font-size: 13px;">
                    &#9650; Green rows advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
                </p>
                <?php endif; ?>
            <?php else: ?>
                <div class="admin-table-wrapper standings-table">
                    <table>
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th>W</th>
                                <th>L</th>
                                <th>D</th>
                                <?php if ($isLeague): ?><th>Tot Pts</th><th>Avg</th><?php endif; ?>
                                <th>PF</th>
                                <th>PA</th>
                                <th>+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($standings as $s): ?>
                            <?php $gamesPlayed = $s['wins'] + $s['losses'] + $s['draws']; ?>
                            <tr>
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td><strong><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'xs'); ?></strong></td>
                                <td><?php echo $s['wins']; ?></td>
                                <td><?php echo $s['losses']; ?></td>
                                <td><?php echo $s['draws']; ?></td>
                                <?php if ($isLeague): ?>
                                <td><strong><?php echo $s['points_for']; ?></strong></td>
                                <td><?php echo $gamesPlayed > 0 ? number_format($s['points_for'] / $gamesPlayed, 1) : '0.0'; ?></td>
                                <?php endif; ?>
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
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
