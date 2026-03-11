<?php
/**
 * Display Hub — All Tournament Displays
 * Sherwood Adventure Tournament System
 *
 * Landing page listing all active/recent tournaments with links
 * to their relevant display pages based on tournament type.
 * No auth required. Easy URL: /display/
 */
require_once __DIR__ . '/../config/database.php';

$db = getDB();

// Get tournaments that are active or recently completed (last 7 days)
$stmt = $db->query("
    SELECT id, name, tournament_type, status, start_date, location
    FROM tournaments
    WHERE status IN ('in_progress', 'registration_open', 'registration_closed', 'completed')
    ORDER BY
        CASE status
            WHEN 'in_progress' THEN 1
            WHEN 'registration_open' THEN 2
            WHEN 'registration_closed' THEN 3
            WHEN 'completed' THEN 4
        END,
        start_date DESC
");
$tournaments = $stmt->fetchAll();

// Map tournament types to their available display pages
function getDisplayLinks($type, $id) {
    $links = [];

    switch ($type) {
        case 'single_elimination':
            $links[] = ['url' => "/display/elimination.php?id={$id}", 'label' => 'Bracket', 'icon' => '&#x1F3C6;'];
            break;

        case 'double_elimination':
            $links[] = ['url' => "/display/all.php?id={$id}", 'label' => 'Full Bracket', 'icon' => '&#x1F3C6;'];
            $links[] = ['url' => "/display/winners.php?id={$id}", 'label' => 'Winners', 'icon' => '&#x2B06;'];
            $links[] = ['url' => "/display/losers.php?id={$id}", 'label' => 'Losers', 'icon' => '&#x2B07;'];
            $links[] = ['url' => "/display/final.php?id={$id}", 'label' => 'Grand Final', 'icon' => '&#x2B50;'];
            break;

        case 'round_robin':
            $links[] = ['url' => "/display/standings.php?id={$id}", 'label' => 'Standings', 'icon' => '&#x1F4CA;'];
            break;

        case 'two_stage':
            $links[] = ['url' => "/display/groups.php?id={$id}", 'label' => 'Groups', 'icon' => '&#x1F465;'];
            $links[] = ['url' => "/display/standings.php?id={$id}", 'label' => 'Standings', 'icon' => '&#x1F4CA;'];
            $links[] = ['url' => "/display/elimination.php?id={$id}", 'label' => 'Bracket', 'icon' => '&#x1F3C6;'];
            break;

        case 'league':
            $links[] = ['url' => "/display/league.php?id={$id}", 'label' => 'Live League', 'icon' => '&#x26BD;'];
            $links[] = ['url' => "/display/standings.php?id={$id}", 'label' => 'Standings', 'icon' => '&#x1F4CA;'];
            break;

        case 'queue':
            $links[] = ['url' => "/display/queue.php?id={$id}", 'label' => 'Live Queue', 'icon' => '&#x1F4CB;'];
            $links[] = ['url' => "/display/queue-results.php?id={$id}", 'label' => 'Results', 'icon' => '&#x1F3C5;'];
            break;
    }

    return $links;
}

function statusBadge($status) {
    $labels = [
        'in_progress' => ['In Progress', '#27ae60'],
        'registration_open' => ['Registration Open', '#149cb3'],
        'registration_closed' => ['Registration Closed', '#e67e22'],
        'completed' => ['Completed', '#7f8c8d'],
    ];
    $info = $labels[$status] ?? ['Unknown', '#666'];
    return '<span class="status-badge" style="background:' . $info[1] . ';">' . $info[0] . '</span>';
}

function typeLabel($type) {
    $labels = [
        'single_elimination' => 'Single Elimination',
        'double_elimination' => 'Double Elimination',
        'round_robin' => 'Round Robin',
        'two_stage' => 'Two-Stage',
        'league' => 'League',
        'queue' => 'Queue',
    ];
    return $labels[$type] ?? ucwords(str_replace('_', ' ', $type));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Displays - Sherwood Adventure</title>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans:wght@400;700&family=Lustria&display=swap" rel="stylesheet">
    <style>
        :root {
            --green: #05290b;
            --orange: #ffa133;
            --gold: #fed611;
            --teal: #149cb3;
            --brown: #46240c;
            --dark: #021205;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            background: var(--dark);
            color: #e0e0e0;
            font-family: 'PT Sans', sans-serif;
            min-height: 100vh;
        }

        .header {
            background: var(--green);
            padding: 24px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 3px solid var(--gold);
        }
        .header h1 {
            font-family: 'Dancing Script', cursive;
            font-size: 36px;
            color: var(--gold);
        }
        .header img { height: 60px; }

        .container {
            max-width: 900px;
            margin: 0 auto;
            padding: 32px 24px;
        }

        .page-subtitle {
            font-family: 'Lato', sans-serif;
            font-size: 16px;
            color: #999;
            text-align: center;
            margin-bottom: 32px;
        }

        .tournament-card {
            background: var(--green);
            border: 2px solid #1a3a1f;
            border-radius: 10px;
            margin-bottom: 16px;
            overflow: hidden;
            transition: border-color 0.2s;
        }
        .tournament-card:hover {
            border-color: var(--teal);
        }

        .card-header {
            padding: 20px 24px 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .card-header h2 {
            font-family: 'Lato', sans-serif;
            font-size: 22px;
            color: var(--gold);
            margin: 0;
        }

        .card-meta {
            padding: 0 24px 8px;
            font-size: 13px;
            color: #888;
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .status-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }

        .display-links {
            padding: 12px 24px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .display-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            background: var(--dark);
            border: 2px solid #2a4a2f;
            border-radius: 8px;
            color: #e0e0e0;
            text-decoration: none;
            font-family: 'Lustria', serif;
            font-size: 14px;
            transition: all 0.2s;
        }
        .display-link:hover {
            border-color: var(--teal);
            color: var(--gold);
            background: #0a1f0d;
        }
        .display-link .link-icon {
            font-size: 18px;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #555;
        }
        .empty-state h3 {
            font-family: 'Lato', sans-serif;
            color: #666;
            margin-bottom: 8px;
        }
        .empty-state p { font-size: 14px; }

        @media (max-width: 600px) {
            .header { padding: 16px; }
            .header h1 { font-size: 28px; }
            .container { padding: 20px 16px; }
            .card-header h2 { font-size: 18px; }
            .display-links { gap: 8px; }
            .display-link { padding: 8px 14px; font-size: 13px; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Tournament Displays</h1>
        <img src="https://sherwoodadventure.com/images/c/logo_466608_print-1--500.png" alt="Sherwood Adventure">
    </div>

    <div class="container">
        <p class="page-subtitle">Select a tournament to view its live display</p>

        <?php if (empty($tournaments)): ?>
        <div class="empty-state">
            <h3>No Active Tournaments</h3>
            <p>There are no tournaments currently running or with open registration.</p>
        </div>
        <?php else: ?>
            <?php foreach ($tournaments as $t): ?>
            <?php $links = getDisplayLinks($t['tournament_type'], $t['id']); ?>
            <div class="tournament-card">
                <div class="card-header">
                    <h2><?php echo h($t['name']); ?></h2>
                    <?php echo statusBadge($t['status']); ?>
                </div>
                <div class="card-meta">
                    <span><?php echo typeLabel($t['tournament_type']); ?></span>
                    <?php if ($t['start_date']): ?>
                    <span><?php echo date('M j, Y', strtotime($t['start_date'])); ?></span>
                    <?php endif; ?>
                    <?php if ($t['location']): ?>
                    <span><?php echo h($t['location']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="display-links">
                    <?php foreach ($links as $link): ?>
                    <a href="<?php echo $link['url']; ?>" class="display-link" target="_blank">
                        <span class="link-icon"><?php echo $link['icon']; ?></span>
                        <?php echo $link['label']; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
