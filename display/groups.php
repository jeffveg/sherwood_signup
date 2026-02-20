<?php
/**
 * Digital Display: Group Stage
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/groups.php?id=TOURNAMENT_ID
 *        /display/groups.php?id=TOURNAMENT_ID&group=TIME_SLOT_ID  (show one group)
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$refreshInterval = intval($_GET['refresh'] ?? 30);
if ($refreshInterval < 5) $refreshInterval = 5;
$filterGroup = isset($_GET['group']) ? intval($_GET['group']) : null;

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
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
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
    SELECT rrs.*, t.team_name, t.is_forfeit, t.logo_path
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

// Get round labels
$roundLabels = [];
try { $roundLabels = getRoundLabels($db, $id); } catch (PDOException $e) { /* table not yet created */ }

// Build ordered group list for navigation
$groupList = [];
foreach ($timeSlots as $slot) {
    if (isset($standingsByGroup[$slot['id']]) || isset($matchesByGroup[$slot['id']])) {
        $groupList[] = $slot['id'];
    }
}
if (isset($standingsByGroup['ungrouped']) || isset($matchesByGroup['ungrouped'])) {
    $groupList[] = 'ungrouped';
}

// Determine which group is active/focused
$activeGroup = $filterGroup;
$showAll = ($filterGroup === null);
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
    <style>
        .group-nav {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 20px;
            justify-content: center;
        }
        .group-nav a {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 20px;
            font-family: 'Lato', sans-serif;
            font-size: 14px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.3s ease;
            border: 1px solid var(--color-brown-border);
            color: var(--color-light-gray);
            background: rgba(39, 41, 42, 0.6);
        }
        .group-nav a:hover {
            border-color: var(--color-orange);
            color: var(--color-orange);
        }
        .group-nav a.active {
            background: rgba(255, 161, 51, 0.15);
            border-color: var(--color-orange);
            color: var(--color-orange);
        }
        .group-row {
            margin-bottom: 20px;
        }
        .group-row .display-card {
            margin-bottom: 0;
        }
        .group-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            align-items: start;
        }
        .group-standings {
            min-width: 0;
        }
        .group-matches {
            min-width: 0;
        }
        @media (max-width: 900px) {
            .group-content {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status"><?php echo $isTwoStage ? 'Group Stage' : 'Round Robin'; ?><?php echo ($activeGroup && isset($slotLabels[$activeGroup])) ? ' — ' . htmlspecialchars($slotLabels[$activeGroup]) : ''; ?></span>
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

            <!-- Group navigation tabs -->
            <?php if (count($groupList) > 1): ?>
            <div class="group-nav">
                <a href="?id=<?php echo $id; ?>&refresh=<?php echo $refreshInterval; ?>"
                   class="<?php echo $showAll ? 'active' : ''; ?>">All Groups</a>
                <?php foreach ($groupList as $gid): ?>
                <a href="?id=<?php echo $id; ?>&group=<?php echo $gid; ?>&refresh=<?php echo $refreshInterval; ?>"
                   class="<?php echo ($activeGroup !== null && $activeGroup == $gid) ? 'active' : ''; ?>">
                    <?php echo $gid !== 'ungrouped' ? htmlspecialchars($slotLabels[$gid] ?? "Group {$gid}") : 'Ungrouped'; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Group rows (stacked, full-width) -->
            <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
            <?php if ($activeGroup !== null && $activeGroup != $groupId) continue; ?>
            <div class="group-row" id="group-<?php echo $groupId; ?>">
                <div class="display-card">
                    <h2><?php echo $groupId !== 'ungrouped' ? htmlspecialchars($slotLabels[$groupId] ?? "Group {$groupId}") : 'Ungrouped'; ?></h2>

                    <div class="group-content">
                        <!-- Standings (left side) -->
                        <div class="group-standings">
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
                                        <td class="team-name"><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'sm'); ?></td>
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
                        </div>

                        <!-- Matches (right side) -->
                        <?php if (isset($matchesByGroup[$groupId])): ?>
                        <div class="group-matches">
                            <?php
                            $roundsInGroup = [];
                            foreach ($matchesByGroup[$groupId] as $m) {
                                $roundsInGroup[$m['round']][] = $m;
                            }
                            ksort($roundsInGroup);
                            ?>
                            <?php foreach ($roundsInGroup as $round => $roundMatches): ?>
                                <div class="display-muted" style="margin: 0 0 6px;"><?php echo htmlspecialchars($roundLabels[$round]['label'] ?? "Round {$round}"); ?><?php if (!empty($roundLabels[$round]['round_date'])): ?> <span class="round-label-date"><?php echo date('M j', strtotime($roundLabels[$round]['round_date'])); ?></span><?php endif; ?></div>
                                <?php foreach ($roundMatches as $match): ?>
                                <div class="display-match">
                                    <div class="display-match-header">
                                        <span>Match #<?php echo $match['match_number']; ?></span>
                                        <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                    </div>
                                    <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                        <span class="display-match-team-name"><?php echo $match['team1_name'] ? teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'sm') : 'TBD'; ?></span>
                                        <span class="display-match-team-score"><?php echo $match['team1_score'] ?? '-'; ?></span>
                                    </div>
                                    <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' ? 'loser' : ''); ?>">
                                        <span class="display-match-team-name"><?php echo $match['team2_name'] ? teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'sm') : 'TBD'; ?></span>
                                        <span class="display-match-team-score"><?php echo $match['team2_score'] ?? '-'; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($isTwoStage): ?>
            <p class="display-advance-note">
                &#9650; Highlighted teams advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
            </p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
