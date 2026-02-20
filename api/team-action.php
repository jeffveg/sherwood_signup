<?php
/**
 * Team Actions API
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/bracket-functions.php';
requireAdmin();

$db = getDB();
$teamId = intval($_POST['team_id'] ?? 0);
$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? '/admin/dashboard.php';

$stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
$stmt->execute([$teamId]);
$team = $stmt->fetch();

if (!$team) {
    setFlash('error', 'Team not found.');
    header("Location: {$redirect}");
    exit;
}

switch ($action) {
    case 'withdraw':
        $db->prepare("UPDATE teams SET status = 'withdrawn' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" has been withdrawn.");
        break;

    case 'confirm':
        $db->prepare("UPDATE teams SET status = 'confirmed' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" confirmed.");
        break;

    case 'checkin':
        $db->prepare("UPDATE teams SET status = 'checked_in' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" checked in.");
        break;

    case 'update_seed':
        $seed = intval($_POST['seed'] ?? 0) ?: null;
        $db->prepare("UPDATE teams SET seed = ? WHERE id = ?")->execute([$seed, $teamId]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        setFlash('success', 'Seed updated.');
        break;

    case 'forfeit':
        $db->prepare("UPDATE teams SET is_forfeit = 1 WHERE id = ?")->execute([$teamId]);

        // Get tournament info for bracket advancement
        $tournStmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
        $tournStmt->execute([$team['tournament_id']]);
        $tournament = $tournStmt->fetch();

        // Auto-resolve all pending/in_progress matches involving this team
        $pendingMatches = $db->prepare("
            SELECT * FROM matches
            WHERE tournament_id = ? AND (team1_id = ? OR team2_id = ?)
            AND status IN ('pending', 'in_progress')
            ORDER BY bracket_type, round, match_number
        ");
        $pendingMatches->execute([$team['tournament_id'], $teamId, $teamId]);

        foreach ($pendingMatches->fetchAll() as $m) {
            $opponentId = ($m['team1_id'] == $teamId) ? $m['team2_id'] : $m['team1_id'];
            if ($opponentId) {
                $winnerId = $opponentId;
                $loserId = $teamId;

                // Set forfeit score: winner gets 1, forfeiting team gets 0
                $team1Score = ($m['team1_id'] == $teamId) ? 0 : 1;
                $team2Score = ($m['team2_id'] == $teamId) ? 0 : 1;

                $db->prepare("UPDATE matches SET winner_id = ?, loser_id = ?, team1_score = ?, team2_score = ?, status = 'completed' WHERE id = ?")
                   ->execute([$winnerId, $loserId, $team1Score, $team2Score, $m['id']]);

                // Advance the winner in the bracket (re-fetch match for updated data)
                $updatedMatch = $db->prepare("SELECT * FROM matches WHERE id = ?");
                $updatedMatch->execute([$m['id']]);
                $matchData = $updatedMatch->fetch();
                advanceTeamInBracket($db, $tournament, $matchData, $winnerId, $loserId);
            }
        }

        // For round robin / league, recalculate standings
        if (in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league'])) {
            recalculateStandingsForTournament($db, $team['tournament_id']);

            if ($tournament['tournament_type'] === 'two_stage') {
                checkAndGenerateEliminationStage($db, $tournament);
            }
        }

        setFlash('success', "Team \"{$team['team_name']}\" has been forfeited. Their pending matches have been resolved and brackets updated.");
        break;

    case 'unforfeit':
        $db->prepare("UPDATE teams SET is_forfeit = 0 WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Forfeit removed for team \"{$team['team_name']}\". Note: match results were not reverted.");
        break;

    default:
        setFlash('error', 'Invalid action.');
}

header("Location: {$redirect}");
exit;
