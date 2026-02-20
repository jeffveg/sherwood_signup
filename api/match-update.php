<?php
/**
 * Match Update API
 * Sherwood Adventure Tournament System
 *
 * Updates match scores and winner, then advances teams in the bracket.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/bracket-functions.php';
requireAdmin();

$db = getDB();
$matchId = intval($_POST['match_id'] ?? 0);
$tournamentId = intval($_POST['tournament_id'] ?? 0);

$redirect = "/admin/tournament-manage.php?id={$tournamentId}#matches";

// Get the match
$stmt = $db->prepare("SELECT * FROM matches WHERE id = ? AND tournament_id = ?");
$stmt->execute([$matchId, $tournamentId]);
$match = $stmt->fetch();

if (!$match) {
    setFlash('error', 'Match not found.');
    header("Location: {$redirect}");
    exit;
}

// Get tournament info
$tournStmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$tournStmt->execute([$tournamentId]);
$tournament = $tournStmt->fetch();

$action = $_POST['action'] ?? 'save_result';

// Handle "Mark In Progress" action
if ($action === 'mark_in_progress') {
    $db->prepare("UPDATE matches SET status = 'in_progress' WHERE id = ?")->execute([$matchId]);
    setFlash('success', 'Match marked as in progress.');
    header("Location: {$redirect}");
    exit;
}

$team1Score = $_POST['team1_score'] !== '' ? intval($_POST['team1_score']) : null;
$team2Score = $_POST['team2_score'] !== '' ? intval($_POST['team2_score']) : null;
$winnerId = intval($_POST['winner_id'] ?? 0) ?: null;

// Determine loser
$loserId = null;
if ($winnerId) {
    $loserId = ($winnerId == $match['team1_id']) ? $match['team2_id'] : $match['team1_id'];
}

// Determine status: completed if winner selected, in_progress if scores entered but no winner
$newStatus = null;
if ($winnerId) {
    $newStatus = 'completed';
} elseif ($team1Score !== null || $team2Score !== null) {
    $newStatus = 'in_progress';
}

// Update match
$updateStmt = $db->prepare("
    UPDATE matches SET team1_score = ?, team2_score = ?, winner_id = ?, loser_id = ?,
        status = CASE
            WHEN ? = 'completed' THEN 'completed'
            WHEN ? = 'in_progress' THEN 'in_progress'
            ELSE status
        END
    WHERE id = ?
");
$updateStmt->execute([$team1Score, $team2Score, $winnerId, $loserId, $newStatus, $newStatus, $matchId]);

// Advance winner to next match in bracket
if ($winnerId) {
    advanceTeamInBracket($db, $tournament, $match, $winnerId, $loserId);

    // For round robin / league, recalculate standings
    if ($match['bracket_type'] === 'round_robin') {
        recalculateStandingsForTournament($db, $tournamentId);

        // If two_stage, check if any group is complete and advance teams to elimination bracket
        if ($tournament['tournament_type'] === 'two_stage') {
            checkAndGenerateEliminationStage($db, $tournament);
        }
    }
}

setFlash('success', 'Match result saved!');
header("Location: {$redirect}");
exit;
