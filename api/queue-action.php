<?php
/**
 * Queue Action API (JSON REST)
 * Sherwood Adventure Tournament System
 *
 * AJAX backend for the queue operator page (admin/queue-operator.php).
 * Handles all queue operations: fetching state, check-in, starting/finishing
 * games, reordering, and triggering SMS look-ahead notifications.
 *
 * All actions require admin authentication and return JSON.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sms.php';
requireAdmin();

header('Content-Type: application/json');

$db = getDB();

// Parse JSON body for POST requests
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $input = json_decode($rawBody, true) ?: $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? '';
$tournamentId = intval($input['tournament_id'] ?? $_GET['tournament_id'] ?? 0);

if (!$tournamentId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing tournament_id']);
    exit;
}

// Verify tournament exists and is queue type
$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament || $tournament['tournament_type'] !== 'queue') {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Queue tournament not found']);
    exit;
}

try {
    switch ($action) {
        case 'get_queue':
            handleGetQueue($db, $tournament);
            break;
        case 'checkin':
            handleCheckin($db, $tournament, $input);
            break;
        case 'undo_checkin':
            handleUndoCheckin($db, $tournament, $input);
            break;
        case 'start_game':
            handleStartGame($db, $tournament, $input);
            break;
        case 'finish_game':
            handleFinishGame($db, $tournament, $input);
            break;
        case 'reorder':
            handleReorder($db, $tournament, $input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log("queue-action.php error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
exit;

// ============================================================
// HANDLERS
// ============================================================

/**
 * Return full queue state: teams, active match, completed matches, next suggested pair.
 * This is polled every few seconds by the operator page to keep the UI in sync.
 */
function handleGetQueue($db, $tournament) {
    echo json_encode(['success' => true, 'data' => buildQueueState($db, $tournament)]);
}

/**
 * Mark a team as checked_in (they've arrived at the venue).
 */
function handleCheckin($db, $tournament, $input) {
    $teamId = intval($input['team_id'] ?? 0);
    if (!$teamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing team_id']);
        return;
    }

    $db->prepare("UPDATE teams SET status = 'checked_in' WHERE id = ? AND tournament_id = ? AND status = 'registered'")
       ->execute([$teamId, $tournament['id']]);

    echo json_encode(['success' => true, 'data' => buildQueueState($db, $tournament)]);
}

/**
 * Undo check-in — set team back to registered.
 * Only allowed if the team is not currently in a game.
 */
function handleUndoCheckin($db, $tournament, $input) {
    $teamId = intval($input['team_id'] ?? 0);
    if (!$teamId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing team_id']);
        return;
    }

    // Ensure team is not in an active game
    $inGame = $db->prepare("
        SELECT COUNT(*) FROM matches
        WHERE tournament_id = ? AND status = 'in_progress'
          AND (team1_id = ? OR team2_id = ?)
    ");
    $inGame->execute([$tournament['id'], $teamId, $teamId]);
    if ($inGame->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'error' => 'Cannot undo check-in while team is in a game']);
        return;
    }

    $db->prepare("UPDATE teams SET status = 'registered' WHERE id = ? AND tournament_id = ? AND status = 'checked_in'")
       ->execute([$teamId, $tournament['id']]);

    echo json_encode(['success' => true, 'data' => buildQueueState($db, $tournament)]);
}

/**
 * Create a new match from two specified teams. Sets match status to in_progress.
 * Does NOT change team status — "playing" is detected by JOINing against in_progress matches.
 */
function handleStartGame($db, $tournament, $input) {
    $team1Id = intval($input['team1_id'] ?? 0);
    $team2Id = intval($input['team2_id'] ?? 0);

    if (!$team1Id || !$team2Id || $team1Id === $team2Id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide two different team IDs']);
        return;
    }

    // Verify both teams are checked_in and not already in a game
    $check = $db->prepare("
        SELECT t.id, t.status, t.team_name,
               (SELECT COUNT(*) FROM matches m WHERE m.tournament_id = t.tournament_id
                AND m.status = 'in_progress' AND (m.team1_id = t.id OR m.team2_id = t.id)) AS in_game
        FROM teams t
        WHERE t.id IN (?, ?) AND t.tournament_id = ?
    ");
    $check->execute([$team1Id, $team2Id, $tournament['id']]);
    $teams = $check->fetchAll();

    if (count($teams) < 2) {
        echo json_encode(['success' => false, 'error' => 'One or both teams not found']);
        return;
    }

    foreach ($teams as $t) {
        if ($t['status'] !== 'checked_in') {
            echo json_encode(['success' => false, 'error' => $t['team_name'] . ' is not checked in']);
            return;
        }
        if ($t['in_game'] > 0) {
            echo json_encode(['success' => false, 'error' => $t['team_name'] . ' is already in a game']);
            return;
        }
    }

    // Get next match number
    $maxMatch = $db->prepare("SELECT COALESCE(MAX(match_number), 0) + 1 FROM matches WHERE tournament_id = ? AND bracket_type = 'queue'");
    $maxMatch->execute([$tournament['id']]);
    $matchNumber = $maxMatch->fetchColumn();

    // Create the match
    $stmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
        VALUES (?, 1, ?, 'queue', ?, ?, 'in_progress')
    ");
    $stmt->execute([$tournament['id'], $matchNumber, $team1Id, $team2Id]);

    echo json_encode(['success' => true, 'match_id' => $db->lastInsertId(), 'data' => buildQueueState($db, $tournament)]);
}

/**
 * Mark a match as completed. Scores are optional.
 * Sets both teams to 'eliminated' (done playing).
 * Triggers SMS look-ahead notifications to upcoming teams in the queue.
 */
function handleFinishGame($db, $tournament, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing match_id']);
        return;
    }

    // Fetch the match
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ? AND tournament_id = ? AND bracket_type = 'queue'");
    $stmt->execute([$matchId, $tournament['id']]);
    $match = $stmt->fetch();

    if (!$match) {
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }
    if ($match['status'] === 'completed') {
        echo json_encode(['success' => false, 'error' => 'Match already completed']);
        return;
    }

    // Scores are optional for queue mode
    $team1Score = isset($input['team1_score']) && $input['team1_score'] !== '' ? intval($input['team1_score']) : null;
    $team2Score = isset($input['team2_score']) && $input['team2_score'] !== '' ? intval($input['team2_score']) : null;

    // Determine winner if scores are provided
    $winnerId = null;
    $loserId = null;
    if ($team1Score !== null && $team2Score !== null && $team1Score !== $team2Score) {
        $winnerId = $team1Score > $team2Score ? $match['team1_id'] : $match['team2_id'];
        $loserId = $winnerId === $match['team1_id'] ? $match['team2_id'] : $match['team1_id'];
    }

    // Complete the match
    $db->prepare("
        UPDATE matches SET team1_score = ?, team2_score = ?, winner_id = ?, loser_id = ?, status = 'completed'
        WHERE id = ?
    ")->execute([$team1Score, $team2Score, $winnerId, $loserId, $matchId]);

    // Mark both teams as eliminated (done playing — one-and-done queue)
    $db->prepare("UPDATE teams SET status = 'eliminated' WHERE id IN (?, ?) AND tournament_id = ?")
       ->execute([$match['team1_id'], $match['team2_id'], $tournament['id']]);

    // SMS look-ahead: notify upcoming teams in the queue
    if (!empty($tournament['sms_enabled'])) {
        sendQueueLookAheadNotifications($db, $tournament, $matchId);
    }

    echo json_encode(['success' => true, 'data' => buildQueueState($db, $tournament)]);
}

/**
 * Update queue positions for multiple teams.
 * Accepts an array of {team_id, position} objects.
 */
function handleReorder($db, $tournament, $input) {
    $positions = $input['positions'] ?? [];
    if (empty($positions) || !is_array($positions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide positions array']);
        return;
    }

    $stmt = $db->prepare("UPDATE teams SET queue_position = ? WHERE id = ? AND tournament_id = ?");
    foreach ($positions as $item) {
        $teamId = intval($item['team_id'] ?? 0);
        $position = intval($item['position'] ?? 0);
        if ($teamId && $position > 0) {
            $stmt->execute([$position, $teamId, $tournament['id']]);
        }
    }

    echo json_encode(['success' => true, 'data' => buildQueueState($db, $tournament)]);
}

// ============================================================
// HELPERS
// ============================================================

/**
 * Build the complete queue state for the operator page.
 * Returns teams (with playing status derived from matches), active match,
 * completed matches, and the next suggested pairing.
 */
function buildQueueState($db, $tournament) {
    $tid = $tournament['id'];

    // All teams in queue order (exclude withdrawn)
    $teamsStmt = $db->prepare("
        SELECT t.id, t.team_name, t.captain_name, t.captain_phone, t.status,
               t.queue_position, t.sms_opt_in,
               m_active.id AS active_match_id
        FROM teams t
        LEFT JOIN matches m_active ON (
            m_active.tournament_id = t.tournament_id
            AND m_active.status = 'in_progress'
            AND (m_active.team1_id = t.id OR m_active.team2_id = t.id)
        )
        WHERE t.tournament_id = ? AND t.status != 'withdrawn'
        ORDER BY t.queue_position ASC
    ");
    $teamsStmt->execute([$tid]);
    $teams = $teamsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Active match (in_progress)
    $activeStmt = $db->prepare("
        SELECT m.id, m.match_number, m.team1_id, m.team2_id, m.team1_score, m.team2_score,
               t1.team_name AS team1_name, t2.team_name AS team2_name
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'in_progress'
        LIMIT 1
    ");
    $activeStmt->execute([$tid]);
    $activeMatch = $activeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    // Completed matches (most recent first)
    $completedStmt = $db->prepare("
        SELECT m.id, m.match_number, m.team1_id, m.team2_id,
               m.team1_score, m.team2_score, m.winner_id,
               t1.team_name AS team1_name, t2.team_name AS team2_name,
               tw.team_name AS winner_name, m.updated_at
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN teams tw ON m.winner_id = tw.id
        WHERE m.tournament_id = ? AND m.bracket_type = 'queue' AND m.status = 'completed'
        ORDER BY m.updated_at DESC
        LIMIT 20
    ");
    $completedStmt->execute([$tid]);
    $completedMatches = $completedStmt->fetchAll(PDO::FETCH_ASSOC);

    // Next suggested pair: first two checked_in teams not currently in a game
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
    $waitingStmt->execute([$tid]);
    $waiting = $waitingStmt->fetchAll(PDO::FETCH_ASSOC);

    $nextSuggested = null;
    if (count($waiting) >= 2) {
        $nextSuggested = [
            'team1' => $waiting[0],
            'team2' => $waiting[1],
        ];
    }

    // Stats
    $checkedIn = count(array_filter($teams, fn($t) => $t['status'] === 'checked_in' && !$t['active_match_id']));
    $playing = count(array_filter($teams, fn($t) => $t['active_match_id']));
    $done = count(array_filter($teams, fn($t) => $t['status'] === 'eliminated'));
    $waitingCount = count(array_filter($teams, fn($t) => $t['status'] === 'registered'));

    return [
        'teams' => $teams,
        'active_match' => $activeMatch,
        'completed_matches' => $completedMatches,
        'next_suggested' => $nextSuggested,
        'waiting_checked_in' => $waiting,
        'stats' => [
            'total' => count($teams),
            'waiting' => $waitingCount,
            'checked_in' => $checkedIn,
            'playing' => $playing,
            'done' => $done,
            'games_played' => count($completedMatches),
        ],
    ];
}

/**
 * After a queue game finishes, look ahead in the queue and send SMS
 * notifications to teams that are approaching their turn.
 * Uses a 15-minute dedup window to prevent over-notifying.
 *
 * @param PDO   $db
 * @param array $tournament
 * @param int   $completedMatchId  The match that just finished (used for dedup)
 */
function sendQueueLookAheadNotifications($db, $tournament, $completedMatchId) {
    // Default look-ahead: 3 games (6 teams). Uses SmsLeadGames from timer if available,
    // otherwise falls back to a sensible default for queue mode.
    $lookAhead = 3;

    // Get waiting checked-in teams in queue order
    $waitingStmt = $db->prepare("
        SELECT t.id, t.team_name, t.captain_phone, t.sms_opt_in
        FROM teams t
        LEFT JOIN matches m ON (
            m.tournament_id = t.tournament_id
            AND m.status = 'in_progress'
            AND (m.team1_id = t.id OR m.team2_id = t.id)
        )
        WHERE t.tournament_id = ? AND t.status = 'checked_in' AND m.id IS NULL
        ORDER BY t.queue_position ASC
    ");
    $waitingStmt->execute([$tournament['id']]);
    $waitingTeams = $waitingStmt->fetchAll(PDO::FETCH_ASSOC);

    // Each game consumes 2 teams, so look-ahead of 3 games = 6 teams
    $teamsToNotify = array_slice($waitingTeams, 0, $lookAhead * 2);

    foreach ($teamsToNotify as $i => $team) {
        if (!$team['sms_opt_in'] || empty($team['captain_phone'])) {
            continue;
        }

        $normalizedPhone = normalizePhoneNumber($team['captain_phone']);
        if (!$normalizedPhone) {
            continue;
        }

        // Games away: position in the waiting list / 2 (each game takes 2 teams)
        // Position 0-1 = next game (1 game away), 2-3 = 2 games away, etc.
        $gamesAway = intval($i / 2) + 1;

        // 15-minute dedup: skip if this team was notified recently
        $recentCheck = $db->prepare("
            SELECT COUNT(*) FROM sms_log
            WHERE team_id = ? AND notification_type IN ('upcoming', 'on_deck')
              AND status = 'sent' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $recentCheck->execute([$team['id']]);
        if ($recentCheck->fetchColumn() > 0) {
            continue;
        }

        // Build and send
        $notificationType = ($gamesAway <= 1) ? 'on_deck' : 'upcoming';
        $messageBody = buildQueueNotifyMessage($team['team_name'], $gamesAway);
        $result = sendSms($normalizedPhone, $messageBody);

        // Log using the completed match ID for audit trail
        logSmsNotification(
            $db, $tournament['id'], $completedMatchId, $team['id'],
            $notificationType, $normalizedPhone, $messageBody,
            $result['message_id'],
            $result['success'] ? 'sent' : 'failed',
            $result['error']
        );

        if ($result['success']) {
            error_log("Queue SMS sent to {$team['team_name']} ({$normalizedPhone}): {$gamesAway} games away");
        }
    }
}
