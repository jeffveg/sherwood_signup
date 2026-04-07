<?php
/**
 * SMS Notification API (JSON REST)
 * Sherwood Adventure Tournament System
 *
 * Called by the Sherwood Timer app after a game ends to trigger SMS
 * notifications to team captains. The Timer determines WHEN to notify
 * (based on game queue position); this endpoint determines WHO gets
 * notified and handles the actual SMS sending via QUO API.
 *
 * Authentication: API key via ?api_key= parameter or X-API-Key header
 * (same key as scoring.php).
 *
 * Actions:
 *   POST ?action=notify_upcoming  — Notify teams about an upcoming match
 *   POST ?action=notify_score     — Notify captains about a completed match score
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sms.php';

// JSON response headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
// AUTHENTICATION (same pattern as api/scoring.php)
// ============================================================
$apiKey = $_GET['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';

if (!defined('SCORING_API_KEY') || $apiKey !== SCORING_API_KEY) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing API key']);
    exit;
}

// ============================================================
// ROUTING
// ============================================================
$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Parse JSON body for POST requests
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?: $_POST;
}

try {
    switch ($action) {
        case 'notify_upcoming':
            handleNotifyUpcoming($db, $input);
            break;

        case 'notify_score':
            handleNotifyScore($db, $input);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error'   => 'Invalid action. Valid: notify_upcoming, notify_score'
            ]);
    }
} catch (Exception $e) {
    error_log("sms-notify.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred.']);
}
exit;

// ============================================================
// HANDLERS
// ============================================================

/**
 * POST ?action=notify_upcoming
 * Body: {
 *     "match_id": 47,
 *     "games_away": 2       // 2 = "~2 games away", 1 = "on deck / next up"
 * }
 *
 * Looks up the teams in the specified match, checks:
 *   1. Tournament has sms_enabled = 1
 *   2. Each team has sms_opt_in = 1
 *   3. Team has a valid captain_phone
 *   4. Notification not already sent (dedup via sms_log unique key)
 * Then sends via QUO API and logs the result.
 */
function handleNotifyUpcoming($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    $gamesAway = max(1, intval($input['games_away'] ?? 2));

    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    // Determine notification type based on proximity
    $notificationType = ($gamesAway <= 1) ? 'on_deck' : 'upcoming';

    // Migration safety: check if sms_enabled column exists
    try {
        $db->query("SELECT sms_enabled FROM tournaments LIMIT 0");
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'SMS columns not found — run migration-sms.sql']);
        return;
    }

    // Fetch match with tournament and team details
    $stmt = $db->prepare("
        SELECT m.id AS match_id, m.tournament_id,
               m.team1_id, t1.team_name AS team1_name, t1.captain_phone AS team1_phone,
               t1.sms_opt_in AS team1_sms,
               m.team2_id, t2.team_name AS team2_name, t2.captain_phone AS team2_phone,
               t2.sms_opt_in AS team2_sms,
               tr.sms_enabled, tr.name AS tournament_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    // Check tournament-level SMS toggle
    if (!$match['sms_enabled']) {
        echo json_encode(['success' => true, 'message' => 'SMS disabled for this tournament', 'sent' => 0, 'skipped' => 0]);
        return;
    }

    $sent = 0;
    $skipped = 0;
    $errors = [];

    // Process each team in the match
    $teams = [
        ['id' => $match['team1_id'], 'name' => $match['team1_name'],
         'phone' => $match['team1_phone'], 'opt_in' => $match['team1_sms'],
         'opponent' => $match['team2_name']],
        ['id' => $match['team2_id'], 'name' => $match['team2_name'],
         'phone' => $match['team2_phone'], 'opt_in' => $match['team2_sms'],
         'opponent' => $match['team1_name']],
    ];

    foreach ($teams as $team) {
        // Skip if no team, not opted in, or no phone
        if (!$team['id'] || !$team['opt_in'] || !$team['phone']) {
            $skipped++;
            continue;
        }

        // Dedup check — already sent this notification?
        if (smsAlreadySent($db, $matchId, $team['id'], $notificationType)) {
            $skipped++;
            continue;
        }

        // Build and send — normalize phone before sending and logging
        $messageBody = buildUpcomingMessage($team['name'], $team['opponent'], $gamesAway);
        $normalizedPhone = normalizePhoneNumber($team['phone']);
        if (!$normalizedPhone) {
            $skipped++;
            continue;
        }
        $result = sendSms($normalizedPhone, $messageBody);

        // Log the attempt (INSERT with duplicate safety net)
        $isDuplicate = logSmsNotification(
            $db, $match['tournament_id'], $matchId, $team['id'],
            $notificationType, $normalizedPhone, $messageBody,
            $result['message_id'],
            $result['success'] ? 'sent' : 'failed',
            $result['error']
        );

        if ($isDuplicate) {
            $skipped++;
        } elseif ($result['success']) {
            $sent++;
        } else {
            $errors[] = $team['name'] . ': ' . $result['error'];
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Notifications processed',
        'type'    => $notificationType,
        'sent'    => $sent,
        'skipped' => $skipped,
        'errors'  => $errors,
    ]);
}

/**
 * POST ?action=notify_score
 * Body: {
 *     "match_id": 47
 * }
 *
 * Sends score results to opted-in captains after a match completes.
 * The match must already have status = 'completed' with scores recorded.
 * Uses the same sms_opt_in flag as upcoming notifications.
 */
function handleNotifyScore($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);

    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    // Fetch completed match with scores and team details
    $stmt = $db->prepare("
        SELECT m.id AS match_id, m.tournament_id, m.status,
               m.team1_id, t1.team_name AS team1_name, t1.captain_phone AS team1_phone,
               t1.sms_opt_in AS team1_sms, m.team1_score,
               m.team2_id, t2.team_name AS team2_name, t2.captain_phone AS team2_phone,
               t2.sms_opt_in AS team2_sms, m.team2_score,
               m.winner_id, tw.team_name AS winner_name,
               tr.sms_enabled
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN teams tw ON m.winner_id = tw.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    if (!$match['sms_enabled']) {
        echo json_encode(['success' => true, 'message' => 'SMS disabled for this tournament', 'sent' => 0]);
        return;
    }

    if ($match['status'] !== 'completed') {
        echo json_encode(['success' => true, 'message' => 'Match not yet completed', 'sent' => 0]);
        return;
    }

    // Build score message (same for both teams)
    $messageBody = buildScoreMessage(
        $match['team1_name'], $match['team1_score'],
        $match['team2_name'], $match['team2_score'],
        $match['winner_name']
    );

    $sent = 0;
    $skipped = 0;

    $teams = [
        ['id' => $match['team1_id'], 'phone' => $match['team1_phone'], 'opt_in' => $match['team1_sms']],
        ['id' => $match['team2_id'], 'phone' => $match['team2_phone'], 'opt_in' => $match['team2_sms']],
    ];

    foreach ($teams as $team) {
        if (!$team['id'] || !$team['opt_in'] || !$team['phone']) {
            $skipped++;
            continue;
        }

        if (smsAlreadySent($db, $matchId, $team['id'], 'score')) {
            $skipped++;
            continue;
        }

        $normalizedPhone = normalizePhoneNumber($team['phone']);
        if (!$normalizedPhone) {
            $skipped++;
            continue;
        }
        $result = sendSms($normalizedPhone, $messageBody);

        logSmsNotification(
            $db, $match['tournament_id'], $matchId, $team['id'],
            'score', $normalizedPhone, $messageBody,
            $result['message_id'],
            $result['success'] ? 'sent' : 'failed',
            $result['error']
        );

        if ($result['success']) {
            $sent++;
        } else {
            $skipped++;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Score notifications processed',
        'sent'    => $sent,
        'skipped' => $skipped,
    ]);
}
