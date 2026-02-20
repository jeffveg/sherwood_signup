<?php
/**
 * Digital Display: Grand Final Only
 * Sherwood Adventure Tournament System
 *
 * Standalone page for TV/projector display.
 * Auto-refreshes every 30 seconds. No navigation, no auth required.
 * Usage: /display/final.php?id=TOURNAMENT_ID
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

// For single elimination, the "final" is the last round of the winners bracket
// For double elimination, we show both the grand_final match AND the winners bracket final
$tournamentType = $tournament['tournament_type'];
$isDoubleElim = ($tournamentType === 'double_elimination');

$finalMatch = null;
$winnersFinalmatch = null;

if ($isDoubleElim) {
    // Get grand final match
    $gfStmt = $db->prepare("
        SELECT m.*,
               t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
               t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
               w.team_name as winner_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN teams w ON m.winner_id = w.id
        WHERE m.tournament_id = ? AND m.bracket_type = 'grand_final'
        LIMIT 1
    ");
    $gfStmt->execute([$id]);
    $finalMatch = $gfStmt->fetch();
} else {
    // Single elimination: final is the last round of winners bracket
    $lastRoundStmt = $db->prepare("
        SELECT MAX(round) FROM matches
        WHERE tournament_id = ? AND bracket_type = 'winners'
    ");
    $lastRoundStmt->execute([$id]);
    $lastRound = $lastRoundStmt->fetchColumn();

    if ($lastRound) {
        $finalStmt = $db->prepare("
            SELECT m.*,
                   t1.team_name as team1_name, t1.is_forfeit as team1_forfeit, t1.logo_path as team1_logo,
                   t2.team_name as team2_name, t2.is_forfeit as team2_forfeit, t2.logo_path as team2_logo,
                   w.team_name as winner_name
            FROM matches m
            LEFT JOIN teams t1 ON m.team1_id = t1.id
            LEFT JOIN teams t2 ON m.team2_id = t2.id
            LEFT JOIN teams w ON m.winner_id = w.id
            WHERE m.tournament_id = ? AND m.bracket_type = 'winners' AND m.round = ?
            LIMIT 1
        ");
        $finalStmt->execute([$id, $lastRound]);
        $finalMatch = $finalStmt->fetch();
    }
}

// Determine champion
$champion = null;
if ($finalMatch && $finalMatch['status'] === 'completed' && $finalMatch['winner_name']) {
    $champion = $finalMatch;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="<?php echo $refreshInterval; ?>">
    <title><?php echo htmlspecialchars($tournament['name']); ?> - <?php echo $isDoubleElim ? 'Grand Final' : 'Final'; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/display.css">
    <style>
        .final-stage {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: calc(100vh - 120px);
            padding: 40px 20px;
        }
        .final-card {
            background: rgba(39, 41, 42, 0.9);
            border: 3px solid var(--color-gold);
            border-radius: 14px;
            padding: 40px 50px;
            max-width: 550px;
            width: 100%;
            box-shadow: 0 0 40px rgba(254, 214, 17, 0.15);
        }
        .final-title {
            font-family: 'Dancing Script', cursive;
            color: var(--color-gold);
            font-size: 42px;
            font-weight: 700;
            text-align: center;
            margin-bottom: 30px;
        }
        .final-match {
            background: rgba(5, 41, 11, 0.5);
            border: 2px solid var(--color-gold);
            border-radius: 10px;
            overflow: hidden;
        }
        .final-match-header {
            background: rgba(254, 214, 17, 0.15);
            padding: 10px 20px;
            font-family: 'Lato', sans-serif;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--color-gold);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .final-team {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 24px;
            border-bottom: 1px solid rgba(254, 214, 17, 0.15);
            font-size: 20px;
            font-weight: 600;
            transition: background 0.3s ease;
        }
        .final-team:last-child {
            border-bottom: none;
        }
        .final-team.winner {
            background: rgba(40, 167, 69, 0.15);
        }
        .final-team.winner .final-team-name {
            color: var(--color-success);
            font-weight: 700;
        }
        .final-team.loser {
            opacity: 0.5;
        }
        .final-team.tbd {
            color: rgba(223, 223, 223, 0.3);
            font-style: italic;
        }
        .final-team-name {
            flex: 1;
        }
        .final-team-score {
            font-family: 'Lato', sans-serif;
            font-weight: 700;
            font-size: 28px;
            color: var(--color-gold);
            min-width: 50px;
            text-align: right;
        }
        .champion-banner {
            text-align: center;
            margin-top: 30px;
            padding: 24px;
            background: linear-gradient(135deg, rgba(254, 214, 17, 0.1), rgba(255, 161, 51, 0.1));
            border: 2px solid var(--color-gold);
            border-radius: 12px;
            animation: championGlow 3s ease-in-out infinite;
        }
        .champion-label {
            font-family: 'Lato', sans-serif;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--color-orange);
            margin-bottom: 8px;
        }
        .champion-name {
            font-family: 'Dancing Script', cursive;
            font-size: 36px;
            font-weight: 700;
            color: var(--color-gold);
        }
        @keyframes championGlow {
            0%, 100% { box-shadow: 0 0 20px rgba(254, 214, 17, 0.15); }
            50% { box-shadow: 0 0 40px rgba(254, 214, 17, 0.3); }
        }
        .vs-divider {
            text-align: center;
            font-family: 'Dancing Script', cursive;
            font-size: 18px;
            color: var(--color-orange);
            padding: 4px 0;
            background: rgba(70, 36, 12, 0.3);
        }
    </style>
</head>
<body>
    <div class="display-header">
        <div>
            <h1><?php echo htmlspecialchars($tournament['name']); ?></h1>
            <span class="display-status"><?php echo $isDoubleElim ? 'Grand Final' : 'Championship Final'; ?></span>
        </div>
        <div style="text-align: right;">
            <span class="display-badge"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $tournament['status']))); ?></span>
            <div style="margin-top: 8px;">
                <span class="live-indicator"><span class="live-dot"></span> Live</span>
            </div>
        </div>
    </div>

    <div class="final-stage">
        <?php if (!$finalMatch): ?>
            <div class="display-empty">
                <p>The final match has not been generated yet.</p>
            </div>
        <?php else: ?>
            <div class="final-card">
                <div class="final-title"><?php echo $isDoubleElim ? 'Grand Final' : 'Championship Final'; ?></div>

                <div class="final-match">
                    <div class="final-match-header">
                        <span><?php echo $isDoubleElim ? 'Grand Final' : 'Final'; ?></span>
                        <span class="display-match-status <?php echo $finalMatch['status']; ?>"><?php echo ucfirst($finalMatch['status']); ?></span>
                    </div>
                    <div class="final-team <?php echo $finalMatch['winner_id'] == $finalMatch['team1_id'] ? 'winner' : ($finalMatch['status'] === 'completed' && $finalMatch['team1_id'] ? 'loser' : ''); ?> <?php echo !$finalMatch['team1_id'] ? 'tbd' : ''; ?>">
                        <span class="final-team-name"><?php echo $finalMatch['team1_name'] ? teamNameHtml($finalMatch['team1_name'], $finalMatch['team1_forfeit'] ?? 0, $finalMatch['team1_logo'] ?? null, 'md') : 'TBD'; ?></span>
                        <span class="final-team-score"><?php echo $finalMatch['team1_score'] ?? ''; ?></span>
                    </div>
                    <div class="vs-divider">vs</div>
                    <div class="final-team <?php echo $finalMatch['winner_id'] == $finalMatch['team2_id'] ? 'winner' : ($finalMatch['status'] === 'completed' && $finalMatch['team2_id'] ? 'loser' : ''); ?> <?php echo !$finalMatch['team2_id'] ? 'tbd' : ''; ?>">
                        <span class="final-team-name"><?php echo $finalMatch['team2_name'] ? teamNameHtml($finalMatch['team2_name'], $finalMatch['team2_forfeit'] ?? 0, $finalMatch['team2_logo'] ?? null, 'md') : 'TBD'; ?></span>
                        <span class="final-team-score"><?php echo $finalMatch['team2_score'] ?? ''; ?></span>
                    </div>
                </div>

                <?php if ($champion): ?>
                <div class="champion-banner">
                    <div class="champion-label">Champion</div>
                    <div class="champion-name"><?php echo teamNameHtml($champion['winner_name'], 0, null, 'lg'); ?></div>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
