<?php
/**
 * Digital Display: Elimination Bracket
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/elimination.php?id=TOURNAMENT_ID
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

// Get elimination matches (winners, losers, grand_final)
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name,
           t2.team_name as team2_name,
           w.team_name as winner_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams w ON m.winner_id = w.id
    WHERE m.tournament_id = ? AND m.bracket_type != 'round_robin'
    ORDER BY m.bracket_type, m.round, m.match_number
");
$matchesStmt->execute([$id]);
$matches = $matchesStmt->fetchAll();

// Group by bracket type and round
$brackets = [];
foreach ($matches as $m) {
    $brackets[$m['bracket_type']][$m['round']][] = $m;
}

$typeLabel = ucwords(str_replace('_', ' ', $tournament['tournament_type']));
$hasLosers = isset($brackets['losers']);
$hasGrandFinal = isset($brackets['grand_final']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Elimination Bracket</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status">Elimination Bracket</span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <div style="margin-top: 8px;">
                <span class="live-indicator"><span class="live-dot"></span> Live</span>
            </div>
        </div>
    </div>

    <div class="display-container">
        <?php if (empty($matches)): ?>
            <div class="display-empty">
                <p>The elimination bracket has not been generated yet.</p>
            </div>
        <?php else: ?>

            <!-- Winners Bracket -->
            <?php if (isset($brackets['winners'])): ?>
            <div class="display-card">
                <h2><?php echo $hasLosers ? 'Winners Bracket' : 'Bracket'; ?></h2>
                <div class="display-bracket-container">
                    <div class="display-bracket">
                        <?php foreach ($brackets['winners'] as $round => $roundMatches): ?>
                        <div class="display-bracket-round">
                            <div class="display-bracket-round-title">
                                <?php
                                $totalRounds = count($brackets['winners']);
                                if ($round === $totalRounds) echo 'Final';
                                elseif ($round === $totalRounds - 1) echo 'Semifinals';
                                elseif ($round === $totalRounds - 2) echo 'Quarterfinals';
                                else echo 'Round ' . $round;
                                ?>
                            </div>
                            <?php foreach ($roundMatches as $match): ?>
                            <div class="display-match">
                                <div class="display-match-header">
                                    <span>Match #<?php echo $match['match_number']; ?></span>
                                    <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team1_id'] ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team1_name'] ? htmlspecialchars($match['team1_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team2_id'] ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team2_name'] ? htmlspecialchars($match['team2_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
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
            <?php if ($hasLosers): ?>
            <div class="display-card">
                <h2>Losers Bracket</h2>
                <div class="display-bracket-container">
                    <div class="display-bracket">
                        <?php foreach ($brackets['losers'] as $round => $roundMatches): ?>
                        <div class="display-bracket-round">
                            <div class="display-bracket-round-title">Round <?php echo $round; ?></div>
                            <?php foreach ($roundMatches as $match): ?>
                            <div class="display-match">
                                <div class="display-match-header">
                                    <span>Match #<?php echo $match['match_number']; ?></span>
                                    <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team1_id'] ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team1_name'] ? htmlspecialchars($match['team1_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                                </div>
                                <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team2_id'] ? 'loser' : ''); ?>">
                                    <span class="display-match-team-name"><?php echo $match['team2_name'] ? htmlspecialchars($match['team2_name']) : 'TBD'; ?></span>
                                    <span class="display-match-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
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
            <?php if ($hasGrandFinal): ?>
            <div class="display-card" style="max-width: 500px; margin: 0 auto;">
                <h2 style="text-align: center; color: var(--color-gold);">Grand Final</h2>
                <?php foreach ($brackets['grand_final'] as $round => $roundMatches): ?>
                    <?php foreach ($roundMatches as $match): ?>
                    <div class="display-match" style="border: 2px solid var(--color-gold);">
                        <div class="display-match-header">
                            <span>Grand Final</span>
                            <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                        </div>
                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ''; ?>">
                            <span class="display-match-team-name"><?php echo $match['team1_name'] ? htmlspecialchars($match['team1_name']) : 'TBD'; ?></span>
                            <span class="display-match-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                        </div>
                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ''; ?>">
                            <span class="display-match-team-name"><?php echo $match['team2_name'] ? htmlspecialchars($match['team2_name']) : 'TBD'; ?></span>
                            <span class="display-match-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
