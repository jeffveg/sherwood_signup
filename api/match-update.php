<?php
/**
 * Match Update API
 * Sherwood Adventure Tournament System
 *
 * Updates match scores and winner, then advances teams in the bracket.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$matchId = intval($_POST['match_id'] ?? 0);
$tournamentId = intval($_POST['tournament_id'] ?? 0);

$redirect = "/admin/tournament-manage.php?id={$tournamentId}";

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

    // For round robin, recalculate standings
    if ($match['bracket_type'] === 'round_robin') {
        recalculateStandingsForTournament($db, $tournamentId);

        // If all round robin matches complete and it's a two_stage tournament, generate elimination bracket
        if ($tournament['tournament_type'] === 'two_stage') {
            checkAndGenerateEliminationStage($db, $tournament);
        }
    }
}

setFlash('success', 'Match result saved!');
header("Location: {$redirect}");
exit;

/**
 * Advance the winner to the next match in the bracket
 */
function advanceTeamInBracket($db, $tournament, $match, $winnerId, $loserId) {
    $tournamentId = $tournament['id'];
    $bracketType = $match['bracket_type'];
    $round = $match['round'];
    $matchNum = $match['match_number'];

    if ($bracketType === 'round_robin') return; // No bracket advancement for RR

    if ($bracketType === 'winners' || $bracketType === 'grand_final') {
        // Advance winner to next round in winners bracket
        $nextRound = $round + 1;
        $nextMatchNum = intval(ceil($matchNum / 2));
        $slot = ($matchNum % 2 !== 0) ? 'team1_id' : 'team2_id';

        $next = $db->prepare("
            SELECT id FROM matches
            WHERE tournament_id = ? AND round = ? AND match_number = ? AND bracket_type = ?
        ");
        $next->execute([$tournamentId, $nextRound, $nextMatchNum, $bracketType]);
        $nextMatch = $next->fetch();

        if ($nextMatch) {
            $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")
               ->execute([$winnerId, $nextMatch['id']]);
        }

        // For double elimination, send loser to losers bracket
        if ($tournament['tournament_type'] === 'double_elimination' && $loserId && $bracketType === 'winners') {
            // Find appropriate losers bracket match
            $losersRound = ($round * 2) - 1;
            $losersMatchNum = intval(ceil($matchNum / 2));

            // Try to place in losers bracket
            $loserMatch = $db->prepare("
                SELECT id, team1_id, team2_id FROM matches
                WHERE tournament_id = ? AND bracket_type = 'losers' AND round = ? AND match_number = ?
            ");
            $loserMatch->execute([$tournamentId, $losersRound, $losersMatchNum]);
            $lm = $loserMatch->fetch();

            if ($lm) {
                $slot = empty($lm['team1_id']) ? 'team1_id' : 'team2_id';
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")
                   ->execute([$loserId, $lm['id']]);
            }
        }
    }

    if ($bracketType === 'losers') {
        // Advance in losers bracket
        $nextRound = $round + 1;
        $nextMatchNum = ($round % 2 === 0) ? intval(ceil($matchNum / 2)) : $matchNum;
        $slot = ($matchNum % 2 !== 0 || $round % 2 !== 0) ? 'team1_id' : 'team2_id';

        $next = $db->prepare("
            SELECT id FROM matches
            WHERE tournament_id = ? AND round = ? AND match_number = ? AND bracket_type = 'losers'
        ");
        $next->execute([$tournamentId, $nextRound, $nextMatchNum]);
        $nextMatch = $next->fetch();

        if ($nextMatch) {
            $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")
               ->execute([$winnerId, $nextMatch['id']]);
        } else {
            // Final of losers bracket goes to grand final
            $gf = $db->prepare("
                SELECT id, team1_id FROM matches WHERE tournament_id = ? AND bracket_type = 'grand_final' LIMIT 1
            ");
            $gf->execute([$tournamentId]);
            $grandFinal = $gf->fetch();
            if ($grandFinal) {
                $slot = empty($grandFinal['team1_id']) ? 'team1_id' : 'team2_id';
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")
                   ->execute([$winnerId, $grandFinal['id']]);
            }
        }
    }
}

/**
 * Recalculate round robin standings
 */
function recalculateStandingsForTournament($db, $tournamentId) {
    // Reset
    $db->prepare("UPDATE round_robin_standings SET wins=0, losses=0, draws=0, points_for=0, points_against=0, point_differential=0 WHERE tournament_id=?")->execute([$tournamentId]);

    $matches = $db->prepare("SELECT * FROM matches WHERE tournament_id=? AND bracket_type='round_robin' AND status='completed'")->execute([$tournamentId]);
    $matches = $db->prepare("SELECT * FROM matches WHERE tournament_id=? AND bracket_type='round_robin' AND status='completed'");
    $matches->execute([$tournamentId]);

    foreach ($matches->fetchAll() as $m) {
        if ($m['team1_score'] !== null && $m['team2_score'] !== null) {
            $s1 = intval($m['team1_score']);
            $s2 = intval($m['team2_score']);

            if ($s1 > $s2) {
                $db->prepare("UPDATE round_robin_standings SET wins=wins+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s1,$s2,$tournamentId,$m['team1_id']]);
                $db->prepare("UPDATE round_robin_standings SET losses=losses+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s2,$s1,$tournamentId,$m['team2_id']]);
            } elseif ($s2 > $s1) {
                $db->prepare("UPDATE round_robin_standings SET wins=wins+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s2,$s1,$tournamentId,$m['team2_id']]);
                $db->prepare("UPDATE round_robin_standings SET losses=losses+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s1,$s2,$tournamentId,$m['team1_id']]);
            } else {
                $db->prepare("UPDATE round_robin_standings SET draws=draws+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s1,$s2,$tournamentId,$m['team1_id']]);
                $db->prepare("UPDATE round_robin_standings SET draws=draws+1, points_for=points_for+?, points_against=points_against+? WHERE tournament_id=? AND team_id=?")->execute([$s2,$s1,$tournamentId,$m['team2_id']]);
            }
        }
    }

    $db->prepare("UPDATE round_robin_standings SET point_differential = points_for - points_against WHERE tournament_id=?")->execute([$tournamentId]);

    // Rank - check if this is a two-stage tournament (rank per group)
    $tournStmt = $db->prepare("SELECT tournament_type FROM tournaments WHERE id = ?");
    $tournStmt->execute([$tournamentId]);
    $tournType = $tournStmt->fetchColumn();

    if ($tournType === 'two_stage') {
        // Rank per group (per time_slot_id)
        $groupsStmt = $db->prepare("SELECT DISTINCT time_slot_id FROM round_robin_standings WHERE tournament_id = ? AND time_slot_id IS NOT NULL");
        $groupsStmt->execute([$tournamentId]);
        foreach ($groupsStmt->fetchAll() as $group) {
            $slotId = $group['time_slot_id'];
            $standings = $db->prepare("SELECT id FROM round_robin_standings WHERE tournament_id = ? AND time_slot_id = ? ORDER BY wins DESC, point_differential DESC, points_for DESC");
            $standings->execute([$tournamentId, $slotId]);
            $rank = 0;
            foreach ($standings->fetchAll() as $s) {
                $rank++;
                $db->prepare("UPDATE round_robin_standings SET ranking=? WHERE id=?")->execute([$rank, $s['id']]);
            }
        }
    } else {
        // Original: rank all teams tournament-wide
        $standings = $db->prepare("SELECT id FROM round_robin_standings WHERE tournament_id=? ORDER BY wins DESC, point_differential DESC, points_for DESC");
        $standings->execute([$tournamentId]);
        $rank = 0;
        foreach ($standings->fetchAll() as $s) {
            $rank++;
            $db->prepare("UPDATE round_robin_standings SET ranking=? WHERE id=?")->execute([$rank, $s['id']]);
        }
    }
}

/**
 * For two-stage tournaments: check if all RR matches are done, then generate elimination bracket
 */
function checkAndGenerateEliminationStage($db, $tournament) {
    $tournamentId = $tournament['id'];

    // Check if all group-stage matches are complete
    $pending = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id=? AND bracket_type='round_robin' AND status != 'completed'");
    $pending->execute([$tournamentId]);
    if ($pending->fetchColumn() > 0) return; // Not all done yet

    // Check if elimination matches already exist
    $existing = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id=? AND bracket_type IN ('winners','losers','grand_final')");
    $existing->execute([$tournamentId]);
    if ($existing->fetchColumn() > 0) return; // Already generated

    $advancePerGroup = $tournament['two_stage_advance_count'] ?? 1;

    // Get all groups that have standings
    $groupsStmt = $db->prepare("SELECT DISTINCT time_slot_id FROM round_robin_standings WHERE tournament_id = ? AND time_slot_id IS NOT NULL ORDER BY time_slot_id");
    $groupsStmt->execute([$tournamentId]);
    $groups = $groupsStmt->fetchAll();

    $advancingTeams = [];

    if (!empty($groups)) {
        // Per-group advancement: get top N from each group
        foreach ($groups as $group) {
            $slotId = $group['time_slot_id'];
            $topTeams = $db->prepare("
                SELECT rrs.team_id, t.* FROM round_robin_standings rrs
                JOIN teams t ON rrs.team_id = t.id
                WHERE rrs.tournament_id = ? AND rrs.time_slot_id = ?
                ORDER BY rrs.ranking ASC
                LIMIT ?
            ");
            $topTeams->execute([$tournamentId, $slotId, $advancePerGroup]);
            $groupAdvancers = $topTeams->fetchAll();
            $advancingTeams = array_merge($advancingTeams, $groupAdvancers);
        }
    } else {
        // Fallback for legacy tournaments without group assignments
        $topTeams = $db->prepare("
            SELECT rrs.team_id, t.* FROM round_robin_standings rrs
            JOIN teams t ON rrs.team_id = t.id
            WHERE rrs.tournament_id = ?
            ORDER BY rrs.ranking ASC
            LIMIT ?
        ");
        $topTeams->execute([$tournamentId, $advancePerGroup]);
        $advancingTeams = $topTeams->fetchAll();
    }

    if (count($advancingTeams) < 2) return;

    // Generate elimination bracket for advancing teams
    $elimType = $tournament['two_stage_elimination_type'] ?? 'single_elimination';
    if ($elimType === 'double_elimination') {
        generateSingleEliminationForStage2($db, $tournamentId, $advancingTeams, 'winners');
    } else {
        generateSingleEliminationForStage2($db, $tournamentId, $advancingTeams, 'winners');
    }
}

function generateSingleEliminationForStage2($db, $tournamentId, $teams, $bracketType) {
    $teamCount = count($teams);
    $bracketSize = 1;
    while ($bracketSize < $teamCount) $bracketSize *= 2;

    $totalRounds = intval(log($bracketSize, 2));

    $stmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    $positions = [];
    for ($i = 0; $i < $bracketSize; $i++) {
        $positions[] = $i < $teamCount ? $teams[$i]['id'] : null;
    }

    $firstRoundMatches = $bracketSize / 2;
    for ($i = 0; $i < $firstRoundMatches; $i++) {
        $matchNumber = $i + 1;
        $team1 = $positions[$i * 2] ?? null;
        $team2 = $positions[$i * 2 + 1] ?? null;

        $status = 'pending';
        if ($team1 === null || $team2 === null) $status = 'bye';

        $stmt->execute([$tournamentId, 1, $matchNumber, $bracketType, $team1, $team2, $status]);

        if ($status === 'bye') {
            $matchId = $db->lastInsertId();
            $winnerId = $team1 ?? $team2;
            if ($winnerId) {
                $db->prepare("UPDATE matches SET winner_id=?, status='completed' WHERE id=?")->execute([$winnerId, $matchId]);
            }
        }
    }

    for ($round = 2; $round <= $totalRounds; $round++) {
        $matchesInRound = $bracketSize / pow(2, $round);
        for ($i = 1; $i <= $matchesInRound; $i++) {
            $stmt->execute([$tournamentId, $round, $i, $bracketType, null, null, 'pending']);
        }
    }
}
