<?php
/**
 * Digital Display: Queue Results
 * Sherwood Adventure Tournament System
 *
 * Public display page showing all completed games with scores.
 * Designed for TV/projector or phone viewing. Auto-refreshes every 15 seconds.
 * No navigation, no auth required.
 *
 * Usage: /display/queue-results.php?id=TOURNAMENT_ID
 *        /display/queue-results.php?id=TOURNAMENT_ID&refresh=20
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$refreshInterval = intval($_GET['refresh'] ?? 15);
if ($refreshInterval < 5) $refreshInterval = 5; // Minimum 5s to prevent excessive server load

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ? AND tournament_type = 'queue'");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    die('Queue tournament not found.');
}

// All completed games, newest first
$gamesStmt = $db->prepare("
    SELECT m.match_number, m.team1_id, m.team2_id, m.winner_id,
           m.team1_score, m.team2_score, m.updated_at,
           t1.team_name AS team1_name, t2.team_name AS team2_name,
           tw.team_name AS winner_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams tw ON m.winner_id = tw.id
    WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'completed'
    ORDER BY m.updated_at DESC
");
$gamesStmt->execute([$id]);
$games = $gamesStmt->fetchAll();

// Current game — shown in a "Now Playing" bar at top so spectators
// viewing results can also see what's happening live
$currentStmt = $db->prepare("
    SELECT t1.team_name AS team1_name, t2.team_name AS team2_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'in_progress'
    LIMIT 1
");
$currentStmt->execute([$id]);
$currentGame = $currentStmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo h($tournament['name']); ?> - Results</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #05290b;
            --orange: #ffa133;
            --gold: #fed611;
            --teal: #149cb3;
            --dark: #021205;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: var(--dark);
            color: #e0e0e0;
            font-family: 'PT Sans', sans-serif;
            min-height: 100vh;
        }

        .display-header {
            background: var(--green);
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--gold);
        }
        .display-header h1 {
            font-family: 'Lato', sans-serif;
            font-size: 28px;
            color: var(--gold);
        }
        .display-header .subtitle {
            font-size: 14px;
            color: #999;
            margin-left: 12px;
        }
        .display-header img { height: 50px; }

        .now-playing-bar {
            background: var(--green);
            border-bottom: 2px solid #333;
            padding: 10px 32px;
            text-align: center;
            font-size: 16px;
            color: #999;
        }
        .now-playing-bar .teams {
            color: var(--gold);
            font-weight: 700;
        }
        .now-playing-bar .live-dot {
            display: inline-block;
            width: 8px; height: 8px;
            background: #e74c3c;
            border-radius: 50%;
            margin-right: 6px;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }

        .results-body {
            padding: 24px 32px;
            max-width: 900px;
            margin: 0 auto;
        }

        .section-title {
            font-family: 'Lato', sans-serif;
            font-size: 16px;
            color: var(--orange);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 16px;
        }

        .game-count {
            color: #666;
            font-size: 14px;
            margin-left: 8px;
            text-transform: none;
            letter-spacing: 0;
        }

        .results-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .result-card {
            background: var(--green);
            border-radius: 8px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .result-card .game-num {
            color: var(--teal);
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase;
            min-width: 60px;
        }

        .result-card .matchup {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 18px;
        }

        .result-card .team-name {
            font-weight: 600;
        }
        .result-card .team-name.winner {
            color: var(--gold);
        }
        .result-card .vs {
            color: #666;
            font-size: 14px;
        }

        .result-card .score {
            font-size: 22px;
            font-weight: 700;
            color: var(--teal);
            min-width: 70px;
            text-align: center;
        }
        .result-card .score .dash {
            color: #555;
            margin: 0 4px;
        }

        .result-card .time {
            color: #666;
            font-size: 12px;
            min-width: 60px;
            text-align: right;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #555;
            font-size: 20px;
        }

        /* Scrollable on TV displays */
        @media (min-height: 800px) {
            .results-body {
                max-height: calc(100vh - 140px);
                overflow-y: auto;
            }
        }
    </style>
</head>
<body>
    <div class="display-header">
        <h1>
            <?php echo h($tournament['name']); ?>
            <span class="subtitle">Results</span>
        </h1>
        <img src="https://sherwoodadventure.com/images/logo.png" alt="Sherwood">
    </div>

    <?php if ($currentGame): ?>
    <div class="now-playing-bar">
        <span class="live-dot"></span>
        Now Playing:
        <span class="teams"><?php echo h($currentGame['team1_name']); ?> vs <?php echo h($currentGame['team2_name']); ?></span>
    </div>
    <?php endif; ?>

    <div class="results-body">
        <div class="section-title">
            Completed Games
            <span class="game-count">(<?php echo count($games); ?>)</span>
        </div>

        <?php if (empty($games)): ?>
        <div class="empty-state">No games completed yet</div>
        <?php else: ?>
        <div class="results-list">
            <?php foreach ($games as $game): ?>
            <div class="result-card">
                <span class="game-num">Game <?php echo $game['match_number']; ?></span>
                <div class="matchup">
                    <?php // Highlight winner by ID comparison (not name) to handle duplicate team names ?>
                    <span class="team-name <?php echo ($game['winner_id'] && $game['winner_id'] == $game['team1_id']) ? 'winner' : ''; ?>">
                        <?php echo h($game['team1_name']); ?>
                    </span>
                    <span class="vs">vs</span>
                    <span class="team-name <?php echo ($game['winner_id'] && $game['winner_id'] == $game['team2_id']) ? 'winner' : ''; ?>">
                        <?php echo h($game['team2_name']); ?>
                    </span>
                </div>
                <?php if ($game['team1_score'] !== null && $game['team2_score'] !== null): ?>
                <span class="score">
                    <?php echo $game['team1_score']; ?><span class="dash">-</span><?php echo $game['team2_score']; ?>
                </span>
                <?php endif; ?>
                <span class="time">
                    <?php echo date('g:i A', strtotime($game['updated_at'])); ?>
                </span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
