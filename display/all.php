<?php
/**
 * Digital Display: All Brackets (Scaled to Fit)
 * Sherwood Adventure Tournament System
 *
 * Shows winners bracket, losers bracket, and grand final all on one screen.
 * Auto-scales to fit the viewport. Ideal for large TV displays.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/all.php?id=TOURNAMENT_ID
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

// Get all elimination matches (winners, losers, grand_final)
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
           t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
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

$hasLosers = isset($brackets['losers']);
$hasGrandFinal = isset($brackets['grand_final']);
$isCompact = ($tournament['bracket_display'] ?? 'full') === 'compact';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - Full Bracket View</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
    <style>
        body {
            overflow: hidden;
            height: 100vh;
        }
        .display-header {
            padding: 12px 24px;
        }
        .display-header h1 {
            font-size: 24px;
        }
        .scaled-viewport {
            position: relative;
            width: 100vw;
            height: calc(100vh - 60px);
            overflow: hidden;
        }
        .scaled-content {
            transform-origin: top left;
            position: absolute;
            top: 0;
            left: 0;
        }
        .scaled-content .display-container {
            padding: 12px 20px;
        }
        .scaled-content .display-card {
            padding: 12px 16px;
            margin-bottom: 12px;
        }
        .scaled-content .display-card h2 {
            font-size: 18px;
            margin-bottom: 8px;
            padding-bottom: 4px;
        }
        .scaled-content .bracket-tree {
            padding: 10px 5px;
        }
        .scaled-content .bracket-match-wrapper .display-match {
            width: 200px;
        }
        .scaled-content .display-match-team {
            padding: 6px 10px;
            font-size: 13px;
        }
        .scaled-content .display-match-header {
            padding: 4px 10px;
            font-size: 10px;
        }
        .scaled-content .display-match-team-score {
            font-size: 14px;
            min-width: 30px;
        }
        .scaled-content .display-bracket-round {
            min-width: 230px;
        }
        .scaled-content .display-bracket-round-title {
            font-size: 11px;
            margin-bottom: 6px;
            padding-bottom: 4px;
        }
        .scaled-content .bracket-connector-right,
        .scaled-content .bracket-connector-left {
            width: 20px;
        }
        .scaled-content .bracket-match-wrapper {
            padding: 4px 0;
        }
        /* Grand final inline at bottom */
        .grand-final-inline {
            display: flex;
            justify-content: center;
            margin-top: 8px;
        }
        .grand-final-inline .display-match {
            max-width: 300px;
            border: 2px solid var(--color-gold);
        }
    </style>
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status">Full Bracket View</span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <span class="live-indicator" style="margin-left: 12px;"><span class="live-dot"></span> Live</span>
        </div>
    </div>

    <div class="scaled-viewport" id="scaled-viewport">
        <div class="scaled-content" id="scaled-content">
            <div class="display-container">
                <?php if (empty($matches)): ?>
                    <div class="display-empty">
                        <p>The elimination bracket has not been generated yet.</p>
                    </div>
                <?php else: ?>

                    <!-- Winners Bracket -->
                    <?php if (isset($brackets['winners'])): ?>
                    <?php
                    $winnersRoundKeys = array_keys($brackets['winners']);
                    $totalOriginalRounds = count($winnersRoundKeys);
                    ?>
                    <div class="display-card">
                        <h2><?php echo $hasLosers ? 'Winners Bracket' : 'Bracket'; ?></h2>
                        <div class="display-bracket-container">
                            <div class="bracket-tree" id="winners-bracket">
                                <?php
                                $isFirstDisplayedRound = true;
                                foreach ($winnersRoundKeys as $rIdx => $round):
                                    $roundMatches = $brackets['winners'][$round];
                                    $isLastRound = ($rIdx === count($winnersRoundKeys) - 1);
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
                                        if ($isCompact && $isByeMatch) continue;
                                        $team1Label = !$match['team1_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                        $team2Label = !$match['team2_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                        ?>
                                        <div class="bracket-match-wrapper" data-match-number="<?php echo $match['match_number']; ?>">
                                            <?php if ($showLeftConnector): ?><div class="bracket-connector-left"></div><?php endif; ?>
                                            <div class="display-match <?php echo $isByeMatch ? 'display-match-bye' : ''; ?>">
                                                <div class="display-match-header">
                                                    <span>M<?php echo $match['match_number']; ?></span>
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

                    <!-- Losers Bracket -->
                    <?php if ($hasLosers): ?>
                    <div class="display-card">
                        <h2>Losers Bracket</h2>
                        <div class="display-bracket-container">
                            <div class="bracket-tree" id="losers-bracket">
                                <?php
                                $losersRoundKeys = array_keys($brackets['losers']);
                                foreach ($losersRoundKeys as $rIdx => $round):
                                    $roundMatches = $brackets['losers'][$round];
                                    $isLastRound = ($rIdx === count($losersRoundKeys) - 1);
                                ?>
                                <div class="display-bracket-round">
                                    <div class="display-bracket-round-title">Round <?php echo $round; ?></div>
                                    <div class="bracket-round-matches">
                                        <?php foreach ($roundMatches as $match): ?>
                                        <?php
                                        $isByeMatch = ($match['status'] === 'completed' && (!$match['team1_id'] || !$match['team2_id']));
                                        $team1Label = !$match['team1_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                        $team2Label = !$match['team2_id'] && $isByeMatch ? 'BYE' : 'TBD';
                                        ?>
                                        <div class="bracket-match-wrapper" data-match-number="<?php echo $match['match_number']; ?>">
                                            <?php if ($rIdx > 0): ?><div class="bracket-connector-left"></div><?php endif; ?>
                                            <div class="display-match <?php echo $isByeMatch ? 'display-match-bye' : ''; ?>">
                                                <div class="display-match-header">
                                                    <span>M<?php echo $match['match_number']; ?></span>
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

                    <!-- Grand Final -->
                    <?php if ($hasGrandFinal): ?>
                    <div class="grand-final-inline">
                        <div class="display-card" style="max-width: 350px; width: 100%;">
                            <h2 style="text-align: center; color: var(--color-gold);">Grand Final</h2>
                            <?php foreach ($brackets['grand_final'] as $round => $roundMatches): ?>
                                <?php foreach ($roundMatches as $match): ?>
                                <div class="display-match" style="border: 2px solid var(--color-gold);">
                                    <div class="display-match-header">
                                        <span>Grand Final</span>
                                        <span class="display-match-status <?php echo $match['status']; ?>"><?php echo ucfirst($match['status']); ?></span>
                                    </div>
                                    <div class="display-match-team <?php echo $match['winner_id'] == $match['team1_id'] ? 'winner' : ''; ?>">
                                        <span class="display-match-team-name"><?php echo $match['team1_name'] ? teamNameHtml($match['team1_name'], $match['team1_forfeit'] ?? 0, $match['team1_logo'] ?? null, 'sm') : 'TBD'; ?></span>
                                        <span class="display-match-team-score"><?php echo $match['team1_score'] ?? ''; ?></span>
                                    </div>
                                    <div class="display-match-team <?php echo $match['winner_id'] == $match['team2_id'] ? 'winner' : ''; ?>">
                                        <span class="display-match-team-name"><?php echo $match['team2_name'] ? teamNameHtml($match['team2_name'], $match['team2_forfeit'] ?? 0, $match['team2_logo'] ?? null, 'sm') : 'TBD'; ?></span>
                                        <span class="display-match-team-score"><?php echo $match['team2_score'] ?? ''; ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="/assets/js/bracket.js?v=3"></script>
    <script>
    // Auto-scale the content to fit the viewport
    function scaleToFit() {
        var viewport = document.getElementById('scaled-viewport');
        var content = document.getElementById('scaled-content');
        if (!viewport || !content) return;

        // Reset scale first so we measure natural size
        content.style.transform = 'scale(1)';

        var vw = viewport.clientWidth;
        var vh = viewport.clientHeight;
        var cw = content.scrollWidth;
        var ch = content.scrollHeight;

        var scaleX = vw / cw;
        var scaleY = vh / ch;
        var scale = Math.min(scaleX, scaleY, 1); // never scale up, only down

        content.style.transform = 'scale(' + scale + ')';
        content.style.width = (vw / scale) + 'px';
    }

    window.addEventListener('load', function() {
        // Wait a frame for bracket.js to finish drawing connectors
        requestAnimationFrame(function() {
            requestAnimationFrame(scaleToFit);
        });
    });
    window.addEventListener('resize', function() {
        clearTimeout(window._scaleTimer);
        window._scaleTimer = setTimeout(scaleToFit, 200);
    });
    </script>
</body>
</html>
