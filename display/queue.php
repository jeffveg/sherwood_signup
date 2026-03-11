<?php
/**
 * Digital Display: Queue Status
 * Sherwood Adventure Tournament System
 *
 * Public display page for TV/projector showing the live queue state.
 * Shows current game, upcoming matchups, recent results, and queue stats.
 * Auto-refreshes every 10 seconds. No navigation, no auth required.
 *
 * Usage: /display/queue.php?id=TOURNAMENT_ID
 *        /display/queue.php?id=TOURNAMENT_ID&refresh=15
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();
$id = intval($_GET['id'] ?? 0);
$refreshInterval = intval($_GET['refresh'] ?? 10);
if ($refreshInterval < 5) $refreshInterval = 5; // Minimum 5s to prevent excessive server load

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ? AND tournament_type = 'queue'");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    die('Queue tournament not found.');
}

// Current game (in_progress)
$currentStmt = $db->prepare("
    SELECT m.*, t1.team_name AS team1_name, t2.team_name AS team2_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'in_progress'
    LIMIT 1
");
$currentStmt->execute([$id]);
$currentGame = $currentStmt->fetch();

// Waiting checked-in teams (not in a game) — for "Up Next" pairings.
// Uses LEFT JOIN anti-pattern (WHERE m.id IS NULL) to exclude teams
// currently in an in_progress match.
$waitingStmt = $db->prepare("
    SELECT t.id, t.team_name, t.queue_position
    FROM teams t
    LEFT JOIN matches m ON (
        m.tournament_id = t.tournament_id
        AND m.status = 'in_progress'
        AND (m.team1_id = t.id OR m.team2_id = t.id)
    )
    WHERE t.tournament_id = ? AND t.status = 'checked_in' AND m.id IS NULL
    ORDER BY t.queue_position ASC
");
$waitingStmt->execute([$id]);
$waitingTeams = $waitingStmt->fetchAll();

// Recent completed games
$recentStmt = $db->prepare("
    SELECT m.match_number, m.team1_score, m.team2_score,
           t1.team_name AS team1_name, t2.team_name AS team2_name,
           tw.team_name AS winner_name, m.updated_at
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams tw ON m.winner_id = tw.id
    WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'completed'
    ORDER BY m.updated_at DESC
    LIMIT 5
");
$recentStmt->execute([$id]);
$recentGames = $recentStmt->fetchAll();

// Stats
$statsStmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN status = 'registered' THEN 1 ELSE 0 END) AS waiting,
        SUM(CASE WHEN status = 'checked_in' THEN 1 ELSE 0 END) AS checked_in,
        SUM(CASE WHEN status = 'eliminated' THEN 1 ELSE 0 END) AS done
    FROM teams WHERE tournament_id = ? AND status != 'withdrawn'
");
$statsStmt->execute([$id]);
$stats = $statsStmt->fetch();

$gamesPlayed = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND bracket_type = 'queue' AND status = 'completed'");
$gamesPlayed->execute([$id]);
$gameCount = $gamesPlayed->fetchColumn();

// Build "Up Next" pairings from waiting teams
$upNextPairs = [];
for ($i = 0; $i + 1 < count($waitingTeams); $i += 2) {
    $upNextPairs[] = [$waitingTeams[$i], $waitingTeams[$i + 1]];
    if (count($upNextPairs) >= 4) break; // Show at most 4 upcoming pairs
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo h($tournament['name']); ?> - Queue</title>
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
            overflow: hidden;
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
        .display-header .live-dot {
            display: inline-block;
            width: 10px; height: 10px;
            background: #e74c3c;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.3; } }
        .display-header img { height: 50px; }

        .display-body { padding: 24px 32px; display: flex; gap: 24px; height: calc(100vh - 83px); }
        .display-left { flex: 2; display: flex; flex-direction: column; gap: 20px; }
        .display-right { flex: 1; display: flex; flex-direction: column; gap: 20px; }

        .section-title {
            font-family: 'Lato', sans-serif;
            font-size: 16px;
            color: var(--orange);
            text-transform: uppercase;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        /* Now Playing */
        .now-playing {
            background: var(--green);
            border: 3px solid var(--gold);
            border-radius: 12px;
            padding: 32px;
            text-align: center;
        }
        .now-playing .matchup {
            font-family: 'Lato', sans-serif;
            font-size: 36px;
            font-weight: 700;
            color: var(--gold);
        }
        .now-playing .vs { color: #999; font-size: 22px; margin: 0 16px; }
        .now-playing .score {
            font-size: 48px;
            font-weight: 700;
            color: #fff;
            margin-top: 12px;
        }
        .now-playing .score .dash { color: #666; margin: 0 16px; }
        .now-playing.empty { border-style: dashed; border-color: #333; }
        .now-playing.empty p { color: #666; font-size: 20px; }

        /* Up Next */
        .up-next-list { display: flex; flex-direction: column; gap: 8px; }
        .up-next-row {
            background: var(--green);
            border-left: 4px solid var(--teal);
            border-radius: 6px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .up-next-row .pair-num {
            color: var(--teal);
            font-weight: 700;
            font-size: 14px;
            width: 24px;
        }
        .up-next-row .pair-teams {
            font-size: 18px;
            font-weight: 600;
        }
        .up-next-row .pair-vs { color: #888; margin: 0 8px; font-weight: 400; }

        /* Recent Results */
        .recent-list { display: flex; flex-direction: column; gap: 6px; flex: 1; overflow-y: auto; }
        .recent-row {
            background: var(--green);
            border-radius: 6px;
            padding: 10px 14px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .recent-row .game-num { color: var(--gold); font-weight: 700; width: 60px; }
        .recent-row .game-teams { flex: 1; }
        .recent-row .game-score { color: var(--teal); font-weight: 700; }
        .recent-row .game-winner { color: var(--gold); font-weight: 600; margin-left: 8px; }

        /* Stats Bar */
        .stats-bar {
            display: flex;
            gap: 16px;
        }
        .stat-box {
            background: var(--green);
            border-radius: 8px;
            padding: 12px 16px;
            text-align: center;
            flex: 1;
        }
        .stat-box .num { font-size: 28px; font-weight: 700; color: var(--gold); }
        .stat-box .lbl { font-size: 11px; text-transform: uppercase; color: #999; letter-spacing: 1px; }
    </style>
</head>
<body>
    <div class="display-header">
        <h1>
            <span class="live-dot"></span>
            <?php echo h($tournament['name']); ?>
        </h1>
        <img src="https://sherwoodadventure.com/images/c/logo_466608_print-1--500.png" alt="Sherwood">
    </div>

    <div class="display-body">
        <div class="display-left">
            <!-- Now Playing -->
            <div>
                <div class="section-title">Now Playing</div>
                <?php if ($currentGame): ?>
                <div class="now-playing">
                    <div class="matchup">
                        <?php echo h($currentGame['team1_name']); ?>
                        <span class="vs">vs</span>
                        <?php echo h($currentGame['team2_name']); ?>
                    </div>
                    <?php if ($currentGame['team1_score'] !== null && $currentGame['team2_score'] !== null): ?>
                    <div class="score">
                        <?php echo $currentGame['team1_score']; ?>
                        <span class="dash">-</span>
                        <?php echo $currentGame['team2_score']; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="now-playing empty">
                    <p>Waiting for next game...</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Up Next -->
            <div>
                <div class="section-title">Up Next</div>
                <?php if (!empty($upNextPairs)): ?>
                <div class="up-next-list">
                    <?php foreach ($upNextPairs as $i => $pair): ?>
                    <div class="up-next-row">
                        <span class="pair-num"><?php echo $i + 1; ?></span>
                        <span class="pair-teams">
                            <?php echo h($pair[0]['team_name']); ?>
                            <span class="pair-vs">vs</span>
                            <?php echo h($pair[1]['team_name']); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="up-next-row" style="border-left-color: #333; opacity: 0.5;">
                    <span class="pair-teams">Waiting for teams to check in...</span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Stats -->
            <div class="stats-bar">
                <div class="stat-box">
                    <div class="num"><?php echo $stats['checked_in']; ?></div>
                    <div class="lbl">Checked In</div>
                </div>
                <div class="stat-box">
                    <div class="num"><?php echo $stats['waiting']; ?></div>
                    <div class="lbl">Waiting</div>
                </div>
                <div class="stat-box">
                    <div class="num"><?php echo $gameCount; ?></div>
                    <div class="lbl">Games Played</div>
                </div>
                <div class="stat-box">
                    <div class="num"><?php echo $stats['done']; ?></div>
                    <div class="lbl">Done</div>
                </div>
            </div>
        </div>

        <div class="display-right">
            <!-- Recent Results -->
            <div class="section-title">Recent Results</div>
            <div class="recent-list">
                <?php if (empty($recentGames)): ?>
                <div class="recent-row" style="opacity: 0.5;">
                    <span class="game-teams">No games completed yet</span>
                </div>
                <?php else: ?>
                    <?php foreach ($recentGames as $game): ?>
                    <div class="recent-row">
                        <span class="game-num">Game <?php echo $game['match_number']; ?></span>
                        <span class="game-teams">
                            <?php echo h($game['team1_name']); ?> vs <?php echo h($game['team2_name']); ?>
                        </span>
                        <?php if ($game['team1_score'] !== null && $game['team2_score'] !== null): ?>
                        <span class="game-score"><?php echo $game['team1_score']; ?>-<?php echo $game['team2_score']; ?></span>
                        <?php endif; ?>
                        <?php if ($game['winner_name']): ?>
                        <span class="game-winner"><?php echo h($game['winner_name']); ?> wins</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>
