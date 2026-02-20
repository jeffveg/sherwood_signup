<?php
/**
 * Digital Display: Winners Bracket Only
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/winners.php?id=TOURNAMENT_ID
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

// Get winners bracket matches only
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
           w.team_name as winner_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams w ON m.winner_id = w.id
    WHERE m.tournament_id = ? AND m.bracket_type = 'winners'
    ORDER BY m.round, m.match_number
");
$matchesStmt->execute([$id]);
$matches = $matchesStmt->fetchAll();

// Group by round
$rounds = [];
foreach ($matches as $m) {
    $rounds[$m['round']][] = $m;
}

$isCompact = ($tournament['bracket_display'] ?? 'full') === 'compact';
$hasLosers = false;
$checkLosers = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND bracket_type = 'losers'");
$checkLosers->execute([$id]);
$hasLosers = $checkLosers->fetchColumn() > 0;

$roundKeys = array_keys($rounds);
$totalOriginalRounds = count($roundKeys);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Winners Bracket</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status"><?php echo $hasLosers ? 'Winners Bracket' : 'Bracket'; ?></span>
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
                <p>The winners bracket has not been generated yet.</p>
            </div>
        <?php else: ?>
            <div class="display-card">
                <h2><?php echo $hasLosers ? 'Winners Bracket' : 'Bracket'; ?></h2>
                <div class="display-bracket-container">
                    <div class="bracket-tree" id="winners-bracket">
                        <?php
                        $isFirstDisplayedRound = true;
                        foreach ($roundKeys as $rIdx => $round):
                            $roundMatches = $rounds[$round];
                            $isLastRound = ($rIdx === count($roundKeys) - 1);
                            // In compact mode, skip entire rounds that are all byes
                            if ($isCompact) {
                                $hasRealMatch = false;
                                foreach ($roundMatches as $m) {
                                    $isBye = ($m['status'] === 'completed' && (!$m['team1_id'] || !$m['team2_id']))
                                          || (!$m['team1_id'] && !$m['team2_id']);
                                    if (!$isBye) { $hasRealMatch = true; break; }
                                }
                                if (!$hasRealMatch) continue;
                            }
                            $showLeftConnector = !$isFirstDisplayedRound;
                            $isFirstDisplayedRound = false;
                        ?>
                        <div class="display-bracket-round">
                            <div class="display-bracket-round-title">
                                <?php
                                if ($round === $totalOriginalRounds) echo 'Final';
                                elseif ($round === $totalOriginalRounds - 1) echo 'Semifinals';
                                elseif ($round === $totalOriginalRounds - 2) echo 'Quarterfinals';
                                else echo 'Round ' . $round;
                                ?>
                            </div>
                            <div class="bracket-round-matches">
                                <?php foreach ($roundMatches as $match): ?>
                                <?php
                                $isByeMatch = ($match['status'] === 'completed' && (!$match['team1_id'] || !$match['team2_id']));
                                // In compact mode, skip individual bye matches
                                if ($isCompact && $isByeMatch) continue;
                                $team1Label = !$match['team1_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                $team2Label = !$match['team2_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                ?>
                                <div class="bracket-match-wrapper" data-match-number="<?php echo $match['match_number']; ?>">
                                    <?php if ($showLeftConnector): ?><div class="bracket-connector-left"></div><?php endif; ?>
                                    <div class="display-match <?php echo $isByeMatch ? 'display-match-bye' : ''; ?>">
                                        <div class="display-match-header">
                                            <span>Match #<?php echo $match['match_number']; ?></span>
                                            <span class="display-match-status <?php echo $match['status']; ?>"><?php echo $isByeMatch ? 'Bye' : ucfirst($match['status']); ?></span>
                                        </div>
                                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team1_id'] ? 'loser' : ''); ?>">
                                            <span class="display-match-team-name"><?php echo $match['team1_name'] ? teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'sm') : $team1Label; ?></span>
                                            <span class="display-match-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                                        </div>
                                        <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ($match['status'] === 'completed' && $match['team2_id'] ? 'loser' : ''); ?>">
                                            <span class="display-match-team-name"><?php echo $match['team2_name'] ? teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'sm') : $team2Label; ?></span>
                                            <span class="display-match-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                                        </div>
                                    </div>
                                    <?php if (!$isLastRound): ?><div class="bracket-connector-right"></div><?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script src="/assets/js/bracket.js?v=3"></script>
</body>
</html>
