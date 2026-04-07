<?php
/**
 * Scoring API (JSON REST)
 * Sherwood Adventure Tournament System
 *
 * External API for the Sherwood Timer scoring system.
 * Replaces Challonge integration — the Timer calls these endpoints
 * to pull matches, start games, and submit scores.
 *
 * Authentication: API key via ?api_key= parameter or X-API-Key header
 *
 * Endpoints (all return JSON):
 *   GET  ?action=list_tournaments           — List active tournaments
 *   GET  ?action=get_tournament&tournament_number=SA-2025-XXXX  — Get all matches for a tournament
 *   GET  ?action=get_match&match_id=123     — Get single match details
 *   POST ?action=start_match                — Mark match as in_progress
 *   POST ?action=end_match                  — Mark match back to pending (undo start)
 *   POST ?action=submit_score               — Submit final score, advance bracket
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/bracket-functions.php';

// Always return JSON
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
// AUTHENTICATION
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
        case 'list_tournaments':
            handleListTournaments($db);
            break;

        case 'get_tournament':
            handleGetTournament($db);
            break;

        case 'get_match':
            handleGetMatch($db);
            break;

        case 'start_match':
            handleStartMatch($db, $input);
            break;

        case 'end_match':
            handleEndMatch($db, $input);
            break;

        case 'submit_score':
            handleSubmitScore($db, $input);
            break;

        case 'update_score':
            handleUpdateScore($db, $input);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Invalid action. Valid actions: list_tournaments, get_tournament, get_match, start_match, end_match, submit_score'
            ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    error_log("scoring.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'An internal server error occurred.']);
}
exit;

// ============================================================
// HANDLERS
// ============================================================

/**
 * GET ?action=list_tournaments
 *
 * Returns all tournaments with status in_progress or registration_closed
 * (i.e., tournaments that have generated brackets and are playable).
 * Timer uses this to let the operator pick which tournament to score.
 */
function handleListTournaments($db) {
    $stmt = $db->query("
        SELECT t.id, t.tournament_number, t.name, t.tournament_type, t.status, t.start_date,
               (SELECT COUNT(*) FROM teams WHERE tournament_id = t.id) as team_count,
               (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id AND status = 'completed') as completed_matches,
               (SELECT COUNT(*) FROM matches WHERE tournament_id = t.id) as total_matches
        FROM tournaments t
        WHERE t.status IN ('in_progress', 'registration_closed')
        ORDER BY t.start_date DESC, t.created_at DESC
    ");
    $tournaments = $stmt->fetchAll();

    $result = [];
    foreach ($tournaments as $t) {
        $result[] = [
            'id'                  => (int)$t['id'],
            'tournament_number'   => $t['tournament_number'],
            'name'                => $t['name'],
            'tournament_type'     => $t['tournament_type'],
            'status'              => $t['status'],
            'start_date'          => $t['start_date'],
            'team_count'          => (int)$t['team_count'],
            'completed_matches'   => (int)$t['completed_matches'],
            'total_matches'       => (int)$t['total_matches'],
        ];
    }

    echo json_encode(['success' => true, 'tournaments' => $result]);
}

/**
 * GET ?action=get_tournament&tournament_number=SA-2025-XXXX
 *     or &tournament_id=5
 *
 * Returns tournament info + all matches with team names.
 * This is what the Timer calls to populate its Games table
 * (replaces Challonge GetOrUpdateGames).
 */
function handleGetTournament($db) {
    $tournamentNumber = $_GET['tournament_number'] ?? '';
    $tournamentId = intval($_GET['tournament_id'] ?? 0);

    if ($tournamentNumber) {
        $stmt = $db->prepare("SELECT * FROM tournaments WHERE tournament_number = ?");
        $stmt->execute([$tournamentNumber]);
    } elseif ($tournamentId) {
        $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
        $stmt->execute([$tournamentId]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide tournament_number or tournament_id']);
        return;
    }

    $tournament = $stmt->fetch();
    if (!$tournament) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Tournament not found']);
        return;
    }

    // Determine the game type mapping for the Timer
    $gameTypeMap = [
        'single_elimination' => 'Elimination',
        'double_elimination' => 'Elimination',
        'round_robin'        => 'Tournament',
        'league'             => 'Tournament',
        'two_stage'          => 'Tournament', // default; elimination matches override below
    ];
    $defaultGameType = $gameTypeMap[$tournament['tournament_type']] ?? 'Normal';

    // Get all matches with team names
    $matchStmt = $db->prepare("
        SELECT m.id as match_id,
               m.round,
               m.match_number,
               m.bracket_type,
               m.team1_id,
               t1.team_name as team1_name,
               m.team2_id,
               t2.team_name as team2_name,
               m.team1_score,
               m.team2_score,
               m.winner_id,
               m.loser_id,
               m.time_slot_id as group_id,
               m.status,
               m.scheduled_time
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.tournament_id = ?
        ORDER BY FIELD(m.bracket_type, 'round_robin', 'winners', 'losers', 'grand_final'),
                 m.time_slot_id, m.round, m.match_number
    ");
    $matchStmt->execute([$tournament['id']]);
    $matches = $matchStmt->fetchAll();

    $matchList = [];
    foreach ($matches as $m) {
        // For two-stage, elimination bracket matches use Elimination game type
        $gameType = $defaultGameType;
        if ($tournament['tournament_type'] === 'two_stage' && $m['bracket_type'] !== 'round_robin') {
            $gameType = 'Elimination';
        }

        // Map status to Timer-friendly values
        $timerStatus = match($m['status']) {
            'pending'     => 'Not Started',
            'in_progress' => 'In Progress',
            'completed'   => 'Finished',
            'bye'         => 'Skipped',
            default       => $m['status'],
        };

        // Determine winner side for Timer ("Green" = team1, "Yellow" = team2)
        $gameWinner = null;
        if ($m['winner_id']) {
            if ($m['winner_id'] == $m['team1_id']) {
                $gameWinner = 'Green';
            } elseif ($m['winner_id'] == $m['team2_id']) {
                $gameWinner = 'Yellow';
            }
        }

        $matchList[] = [
            'match_id'       => (int)$m['match_id'],
            'round'          => (int)$m['round'],
            'match_number'   => (int)$m['match_number'],
            'bracket_type'   => $m['bracket_type'],
            'group_id'       => $m['group_id'] ? (int)$m['group_id'] : null,
            'team1_id'       => $m['team1_id'] ? (int)$m['team1_id'] : null,
            'team1_name'     => $m['team1_name'] ?? null,
            'team2_id'       => $m['team2_id'] ? (int)$m['team2_id'] : null,
            'team2_name'     => $m['team2_name'] ?? null,
            'team1_score'    => $m['team1_score'] !== null ? (int)$m['team1_score'] : null,
            'team2_score'    => $m['team2_score'] !== null ? (int)$m['team2_score'] : null,
            'winner_id'      => $m['winner_id'] ? (int)$m['winner_id'] : null,
            'game_winner'    => $gameWinner,
            'status'         => $m['status'],
            'timer_status'   => $timerStatus,
            'game_type'      => $gameType,
            'scheduled_time' => $m['scheduled_time'],
        ];
    }

    echo json_encode([
        'success' => true,
        'tournament' => [
            'id'                => (int)$tournament['id'],
            'tournament_number' => $tournament['tournament_number'],
            'name'              => $tournament['name'],
            'tournament_type'   => $tournament['tournament_type'],
            'status'            => $tournament['status'],
            'start_date'        => $tournament['start_date'],
        ],
        'matches' => $matchList,
    ]);
}

/**
 * GET ?action=get_match&match_id=123
 *
 * Returns a single match with full details.
 * Timer can call this to check the current state of a match.
 */
function handleGetMatch($db) {
    $matchId = intval($_GET['match_id'] ?? 0);
    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    $stmt = $db->prepare("
        SELECT m.id as match_id, m.tournament_id,
               m.round, m.match_number, m.bracket_type,
               m.team1_id, t1.team_name as team1_name,
               m.team2_id, t2.team_name as team2_name,
               m.team1_score, m.team2_score,
               m.winner_id, m.loser_id,
               m.time_slot_id as group_id,
               m.status, m.scheduled_time,
               tr.tournament_type, tr.tournament_number
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        LEFT JOIN tournaments tr ON m.tournament_id = tr.id
        WHERE m.id = ?
    ");
    $stmt->execute([$matchId]);
    $m = $stmt->fetch();

    if (!$m) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    $gameWinner = null;
    if ($m['winner_id']) {
        $gameWinner = ($m['winner_id'] == $m['team1_id']) ? 'Green' : 'Yellow';
    }

    echo json_encode([
        'success' => true,
        'match' => [
            'match_id'          => (int)$m['match_id'],
            'tournament_id'     => (int)$m['tournament_id'],
            'tournament_number' => $m['tournament_number'],
            'round'             => (int)$m['round'],
            'match_number'      => (int)$m['match_number'],
            'bracket_type'      => $m['bracket_type'],
            'group_id'          => $m['group_id'] ? (int)$m['group_id'] : null,
            'team1_id'          => $m['team1_id'] ? (int)$m['team1_id'] : null,
            'team1_name'        => $m['team1_name'] ?? null,
            'team2_id'          => $m['team2_id'] ? (int)$m['team2_id'] : null,
            'team2_name'        => $m['team2_name'] ?? null,
            'team1_score'       => $m['team1_score'] !== null ? (int)$m['team1_score'] : null,
            'team2_score'       => $m['team2_score'] !== null ? (int)$m['team2_score'] : null,
            'winner_id'         => $m['winner_id'] ? (int)$m['winner_id'] : null,
            'game_winner'       => $gameWinner,
            'status'            => $m['status'],
        ],
    ]);
}

/**
 * POST ?action=start_match
 * Body: { "match_id": 123 }
 *
 * Marks the match as in_progress. Timer calls this when a game starts
 * (replaces Challonge StartMatch).
 */
function handleStartMatch($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    $stmt = $db->prepare("SELECT id, status FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    if ($match['status'] === 'completed') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Match is already completed']);
        return;
    }

    $db->prepare("UPDATE matches SET status = 'in_progress' WHERE id = ?")->execute([$matchId]);

    echo json_encode(['success' => true, 'message' => 'Match marked as in progress']);
}

/**
 * POST ?action=end_match
 * Body: { "match_id": 123 }
 *
 * Reverts a match from in_progress back to pending.
 * Timer calls this if a game is cancelled before completion
 * (replaces Challonge EndMatch / unmark_as_underway).
 */
function handleEndMatch($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    $stmt = $db->prepare("SELECT id, status FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    if ($match['status'] !== 'in_progress') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Match is not in progress (current status: ' . $match['status'] . ')']);
        return;
    }

    $db->prepare("UPDATE matches SET status = 'pending' WHERE id = ?")->execute([$matchId]);

    echo json_encode(['success' => true, 'message' => 'Match reverted to pending']);
}

/**
 * POST ?action=update_score
 * Body: {
 *     "match_id": 123,
 *     "team1_score": 14,   // Green team score (current in-game)
 *     "team2_score": 8     // Yellow team score (current in-game)
 * }
 *
 * Updates scores on an in-progress match WITHOUT completing it.
 * Used for live score updates during gameplay. Does not advance brackets
 * or recalculate standings.
 */
function handleUpdateScore($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    $team1Score = isset($input['team1_score']) ? intval($input['team1_score']) : null;
    $team2Score = isset($input['team2_score']) ? intval($input['team2_score']) : null;

    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    if ($team1Score === null || $team2Score === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide both team1_score and team2_score']);
        return;
    }

    // Fetch the match
    $stmt = $db->prepare("SELECT id, status FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    if ($match['status'] === 'completed') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Match is already completed']);
        return;
    }

    // Update scores only — do not change status, winner, or loser
    $db->prepare("
        UPDATE matches SET team1_score = ?, team2_score = ? WHERE id = ?
    ")->execute([$team1Score, $team2Score, $matchId]);

    echo json_encode([
        'success'      => true,
        'message'      => 'Live scores updated',
        'match_id'     => (int)$matchId,
        'team1_score'  => (int)$team1Score,
        'team2_score'  => (int)$team2Score,
    ]);
}

/**
 * POST ?action=submit_score
 * Body: {
 *     "match_id": 123,
 *     "team1_score": 14,   // Green team score
 *     "team2_score": 8,    // Yellow team score
 *     "winner_id": 12      // (optional) Team ID of winner. If omitted, derived from scores.
 * }
 *
 * Submits final score, determines winner, marks match completed,
 * advances winner in bracket, recalculates standings.
 * This is the big one — replaces Challonge UploadScores.
 */
function handleSubmitScore($db, $input) {
    $matchId = intval($input['match_id'] ?? 0);
    $team1Score = isset($input['team1_score']) ? intval($input['team1_score']) : null;
    $team2Score = isset($input['team2_score']) ? intval($input['team2_score']) : null;
    $winnerIdInput = isset($input['winner_id']) ? intval($input['winner_id']) : null;

    if (!$matchId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide match_id']);
        return;
    }

    if ($team1Score === null || $team2Score === null) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Provide both team1_score and team2_score']);
        return;
    }

    // Fetch the match
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();

    if (!$match) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Match not found']);
        return;
    }

    if ($match['status'] === 'completed') {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Match is already completed']);
        return;
    }

    if (!$match['team1_id'] || !$match['team2_id']) {
        http_response_code(409);
        echo json_encode(['success' => false, 'error' => 'Match does not have both teams assigned yet']);
        return;
    }

    // Determine winner
    $winnerId = $winnerIdInput;
    if (!$winnerId) {
        // Auto-determine from scores
        if ($team1Score > $team2Score) {
            $winnerId = $match['team1_id'];
        } elseif ($team2Score > $team1Score) {
            $winnerId = $match['team2_id'];
        } else {
            // Tie — for elimination brackets, caller must specify winner_id
            if (in_array($match['bracket_type'], ['winners', 'losers', 'grand_final'])) {
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Scores are tied. For elimination matches, provide winner_id to break the tie.']);
                return;
            }
            // For round robin, ties are allowed — no winner
            $winnerId = null;
        }
    }

    // Validate winner_id is one of the teams
    if ($winnerId && $winnerId != $match['team1_id'] && $winnerId != $match['team2_id']) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'winner_id must be team1_id (' . $match['team1_id'] . ') or team2_id (' . $match['team2_id'] . ')']);
        return;
    }

    $loserId = null;
    if ($winnerId) {
        $loserId = ($winnerId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
    }

    // Determine status
    $newStatus = $winnerId ? 'completed' : 'completed'; // Even ties are "completed" in RR

    // Update the match
    $db->prepare("
        UPDATE matches SET team1_score = ?, team2_score = ?, winner_id = ?, loser_id = ?, status = ? WHERE id = ?
    ")->execute([$team1Score, $team2Score, $winnerId, $loserId, $newStatus, $matchId]);

    // Get tournament for bracket advancement
    $tournStmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $tournStmt->execute([$match['tournament_id']]);
    $tournament = $tournStmt->fetch();

    // Advance winner in bracket
    if ($winnerId) {
        advanceTeamInBracket($db, $tournament, $match, $winnerId, $loserId);
    }

    // Recalculate standings for round robin types
    if ($match['bracket_type'] === 'round_robin') {
        recalculateStandingsForTournament($db, $match['tournament_id']);

        if ($tournament['tournament_type'] === 'two_stage') {
            checkAndGenerateEliminationStage($db, $tournament);
        }
    }

    // Determine winner side for Timer response
    $gameWinner = null;
    if ($winnerId) {
        $gameWinner = ($winnerId == $match['team1_id']) ? 'Green' : 'Yellow';
    }

    echo json_encode([
        'success'      => true,
        'message'      => 'Score submitted and bracket updated',
        'match_id'     => (int)$matchId,
        'team1_score'  => (int)$team1Score,
        'team2_score'  => (int)$team2Score,
        'winner_id'    => $winnerId ? (int)$winnerId : null,
        'loser_id'     => $loserId ? (int)$loserId : null,
        'game_winner'  => $gameWinner,
        'status'       => $newStatus,
    ]);
}
