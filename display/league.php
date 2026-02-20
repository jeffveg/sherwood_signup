<?php
/**
 * Digital Display: Live League
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Shows standings, current matches, recent results, and upcoming games.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/league.php?id=TOURNAMENT_ID&refresh=30
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

// Get standings (include time_slot_id for group display)
$standingsStmt = $db->prepare("
    SELECT rrs.*, t.team_name, t.is_forfeit, t.logo_path
    FROM round_robin_standings rrs
    JOIN teams t ON rrs.team_id = t.id
    WHERE rrs.tournament_id = ?
    ORDER BY rrs.time_slot_id, rrs.ranking, rrs.wins DESC, rrs.point_differential DESC
");
$standingsStmt->execute([$id]);
$standings = $standingsStmt->fetchAll();

// Get time slots for group labels
$slotsStmt = $db->prepare("SELECT id, slot_label FROM time_slots WHERE tournament_id = ?");
$slotsStmt->execute([$id]);
$slotLookup = [];
foreach ($slotsStmt->fetchAll() as $ts) { $slotLookup[$ts['id']] = $ts['slot_label']; }

// Check if standings are grouped
$standingsByGroup = [];
foreach ($standings as $s) {
    $gid = $s['time_slot_id'] ?? 'ungrouped';
    $standingsByGroup[$gid][] = $s;
}
$isGroupedDisplay = (count($standingsByGroup) > 1 || !isset($standingsByGroup['ungrouped']));

// Get in-progress matches
$inProgressStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.tournament_id = ? AND m.status = 'in_progress'
    ORDER BY m.round, m.match_number
    LIMIT 10
");
$inProgressStmt->execute([$id]);
$inProgressMatches = $inProgressStmt->fetchAll();

// Get recent completed matches (last 5)
$recentStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
           w.team_name as winner_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams w ON m.winner_id = w.id
    WHERE m.tournament_id = ? AND m.status = 'completed' AND m.bracket_type = 'round_robin'
    ORDER BY m.id DESC
    LIMIT 5
");
$recentStmt->execute([$id]);
$recentMatches = $recentStmt->fetchAll();

// Get upcoming matches (next 5 pending)
$upcomingStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.tournament_id = ? AND m.status = 'pending' AND m.bracket_type = 'round_robin'
    ORDER BY m.round, m.match_number
    LIMIT 5
");
$upcomingStmt->execute([$id]);
$upcomingMatches = $upcomingStmt->fetchAll();

// Get round labels
$roundLabels = [];
try { $roundLabels = getRoundLabels($db, $id); } catch (PDOException $e) { /* table not yet created */ }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Live League</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status">League Standings</span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <div style="margin-top: 8px;">
                <span class="live-indicator"><span class="live-dot"></span> Live</span>
            </div>
        </div>
    </div>

    <div class="display-container">
        <?php if (empty($standings) && empty($inProgressMatches) && empty($recentMatches)): ?>
            <div class="display-empty">
                <p>The league has not started yet. Standings and matches will appear here once games begin.</p>
            </div>
        <?php else: ?>

            <!-- In-Progress Matches -->
            <?php if (!empty($inProgressMatches)): ?>
            <div class="display-card" style="border: 2px solid var(--color-orange); margin-bottom: 20px;">
                <h2 style="color: var(--color-orange);">Now Playing</h2>
                <?php foreach ($inProgressMatches as $match): ?>
                <div class="display-match">
                    <div class="display-match-header">
                        <span><?php echo htmlspecialchars($roundLabels[$match['round']]['label'] ?? "Week {$match['round']}"); ?> - Match #<?php echo $match['match_number']; ?></span>
                        <span class="display-match-status in_progress">In Progress</span>
                    </div>
                    <div class="display-match-team">
                        <span class="display-match-team-name"><?php echo teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'sm'); ?></span>
                        <span class="display-match-team-score"><?php echo $match['team1_score'] ?? '0'; ?></span>
                    </div>
                    <div class="display-match-team">
                        <span class="display-match-team-name"><?php echo teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'sm'); ?></span>
                        <span class="display-match-team-score"><?php echo $match['team2_score'] ?? '0'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Standings Table(s) -->
            <?php if (!empty($standings)): ?>
                <?php foreach ($standingsByGroup as $groupId => $groupStandings): ?>
                <div class="display-card" style="margin-bottom: 16px;">
                    <h2><?php echo $isGroupedDisplay ? htmlspecialchars($slotLookup[$groupId] ?? "Group $groupId") : 'Rankings'; ?></h2>
                    <table class="display-table">
                        <thead>
                            <tr>
                                <th class="rank-col">#</th>
                                <th>Team</th>
                                <th class="stat-col">W</th>
                                <th class="stat-col">L</th>
                                <th class="stat-col">D</th>
                                <th class="stat-col">Tot Pts</th>
                                <th class="stat-col">Avg</th>
                                <th class="stat-col">PF</th>
                                <th class="stat-col">PA</th>
                                <th class="stat-col">+/-</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groupStandings as $s): ?>
                            <?php $gamesPlayed = $s['wins'] + $s['losses'] + $s['draws']; ?>
                            <tr>
                                <td class="rank-col"><?php echo $s['ranking'] ?? '-'; ?></td>
                                <td class="team-name"><?php echo teamNameHtml($s['team_name'], $s['is_forfeit'] ?? 0, $s['logo_path'] ?? null, 'sm'); ?></td>
                                <td class="stat-col"><?php echo $s['wins']; ?></td>
                                <td class="stat-col"><?php echo $s['losses']; ?></td>
                                <td class="stat-col"><?php echo $s['draws']; ?></td>
                                <td class="stat-col" style="font-weight: 700;"><?php echo $s['points_for']; ?></td>
                                <td class="stat-col"><?php echo $gamesPlayed > 0 ? number_format($s['points_for'] / $gamesPlayed, 1) : '0.0'; ?></td>
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
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Recent Results & Upcoming in 2-column grid -->
            <?php if (!empty($recentMatches) || !empty($upcomingMatches)): ?>
            <div class="display-grid-2">
                <!-- Recent Results -->
                <?php if (!empty($recentMatches)): ?>
                <div class="display-card">
                    <h2>Recent Results</h2>
                    <?php foreach ($recentMatches as $match): ?>
                    <div class="display-match">
                        <div class="display-match-header">
                            <span><?php echo htmlspecialchars($roundLabels[$match['round']]['label'] ?? "Week {$match['round']}"); ?></span>
                            <span class="display-match-status completed">Completed</span>
                        </div>
                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : 'loser'; ?>">
                            <span class="display-match-team-name"><?php echo teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'xs'); ?></span>
                            <span class="display-match-team-score"><?php echo $match['team1_score'] ?? '0'; ?></span>
                        </div>
                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : 'loser'; ?>">
                            <span class="display-match-team-name"><?php echo teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'xs'); ?></span>
                            <span class="display-match-team-score"><?php echo $match['team2_score'] ?? '0'; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Upcoming Matches -->
                <?php if (!empty($upcomingMatches)): ?>
                <div class="display-card">
                    <h2>Upcoming Matches</h2>
                    <?php foreach ($upcomingMatches as $match): ?>
                    <div class="display-match">
                        <div class="display-match-header">
                            <span><?php echo htmlspecialchars($roundLabels[$match['round']]['label'] ?? "Week {$match['round']}"); ?> - Match #<?php echo $match['match_number']; ?></span>
                            <span class="display-match-status pending">Upcoming</span>
                        </div>
                        <div class="display-match-team">
                            <span class="display-match-team-name"><?php echo teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'xs'); ?></span>
                            <span class="display-match-team-score">-</span>
                        </div>
                        <div class="display-match-team">
                            <span class="display-match-team-name"><?php echo teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'xs'); ?></span>
                            <span class="display-match-team-score">-</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Column Legend -->
            <div class="display-legend">
                <span><strong>W</strong> Wins</span>
                <span><strong>L</strong> Losses</span>
                <span><strong>D</strong> Draws</span>
                <span><strong>Tot Pts</strong> Total Points Scored</span>
                <span><strong>Avg</strong> Avg Points Per Game</span>
                <span><strong>PF</strong> Points For</span>
                <span><strong>PA</strong> Points Against</span>
                <span><strong>+/-</strong> Point Differential</span>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
