<?php
/**
 * Digital Display: Standings
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/standings.php?id=TOURNAMENT_ID
 *        /display/standings.php?id=TOURNAMENT_ID&group=TIME_SLOT_ID  (show one group)
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
    SELECT ts.* FROM time_slots ts
    WHERE ts.tournament_id = ?
    ORDER BY ts.slot_date, ts.slot_time
");
$slotsStmt->execute([$id]);
$timeSlots = $slotsStmt->fetchAll();

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

$isTwoStage = ($tournament['tournament_type'] === 'two_stage');
$advanceCount = $tournament['two_stage_advance_count'] ?? 1;

// Group by time_slot_id for two-stage
$standingsByGroup = [];
foreach ($standings as $s) {
    $gid = $s['time_slot_id'] ?? 'ungrouped';
    $standingsByGroup[$gid][] = $s;
}

$slotLabels = [];
$slotIndex = 0;
foreach ($timeSlots as $slot) {
    $slotIndex++;
    $slotLabels[$slot['id']] = $slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time'])) . ' - ' . date('M j, Y', strtotime($slot['slot_date']));
}

// Build ordered group list for navigation
$groupList = [];
foreach ($timeSlots as $slot) {
    if (isset($standingsByGroup[$slot['id']])) {
        $groupList[] = $slot['id'];
    }
}
if (isset($standingsByGroup['ungrouped'])) {
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
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Standings</title>
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
    </style>
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status">Standings<?php echo ($activeGroup && isset($slotLabels[$activeGroup])) ? ' — ' . htmlspecialchars($slotLabels[$activeGroup]) : ''; ?></span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <div style="margin-top: 8px;">
                <span class="live-indicator"><span class="live-dot"></span> Live</span>
            </div>
        </div>
    </div>

    <div class="display-container">
        <?php if (empty($standings)): ?>
            <div class="display-empty">
                <p>Standings will appear after matches are generated and results recorded.</p>
            </div>
        <?php elseif ($isTwoStage && count($standingsByGroup) > 1): ?>

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
                    <table class="display-table">
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th class="stat-col">W</th>
                                <th class="stat-col">L</th>
                                <th class="stat-col">D</th>
                                <th class="stat-col">PF</th>
                                <th class="stat-col">PA</th>
                                <th class="stat-col">+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStandings as $s): ?>
                            <tr class="<?php echo ($s['ranking'] ?? 999) <= $advanceCount ? 'advancing' : ''; ?>">
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td class="team-name"><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'sm'); ?></td>
                                <td class="stat-col"><?php echo $s['wins']; ?></td>
                                <td class="stat-col"><?php echo $s['losses']; ?></td>
                                <td class="stat-col"><?php echo $s['draws']; ?></td>
                                <td class="stat-col"><?php echo $s['points_for']; ?></td>
                                <td class="stat-col"><?php echo $s['points_against']; ?></td>
                                <td class="stat-col <?php echo $s['point_differential'] >= 0 ? 'diff-positive' : 'diff-negative'; ?>">
                                    <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>

            <p class="display-advance-note">
                &#9650; Highlighted teams advance to the elimination stage (top <?php echo $advanceCount; ?> per group)
            </p>
        <?php else: ?>
            <!-- Single table (standalone RR or single-group) -->
            <div class="display-card">
                <h2><?php echo $isTwoStage ? 'Group Stage Standings' : 'Round Robin Standings'; ?></h2>
                <table class="display-table">
                    <thead>
                        <tr>
                            <th class="rank-col">#</th>
                            <th>Team</th>
                            <th class="stat-col">W</th>
                            <th class="stat-col">L</th>
                            <th class="stat-col">D</th>
                            <th class="stat-col">PF</th>
                            <th class="stat-col">PA</th>
                            <th class="stat-col">+/-</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($standings as $s): ?>
                        <tr class="<?php echo $isTwoStage && ($s['ranking'] ?? 999) <= $advanceCount ? 'advancing' : ''; ?>">
                            <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                            <td class="team-name"><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'sm'); ?></td>
                            <td class="stat-col"><?php echo $s['wins']; ?></td>
                            <td class="stat-col"><?php echo $s['losses']; ?></td>
                            <td class="stat-col"><?php echo $s['draws']; ?></td>
                            <td class="stat-col"><?php echo $s['points_for']; ?></td>
                            <td class="stat-col"><?php echo $s['points_against']; ?></td>
                            <td class="stat-col <?php echo $s['point_differential'] >= 0 ? 'diff-positive' : 'diff-negative'; ?>">
                                <?php echo ($s['point_differential'] >= 0 ? '+' : '') . $s['point_differential']; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Column Legend -->
        <div class="display-legend">
            <span><strong>W</strong> Wins</span>
            <span><strong>L</strong> Losses</span>
            <span><strong>D</strong> Draws</span>
            <span><strong>PF</strong> Points For</span>
            <span><strong>PA</strong> Points Against</span>
            <span><strong>+/-</strong> Point Differential</span>
        </div>
    </div>
</body>
</html>
