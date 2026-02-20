<?php
/**
 * Tournament Actions API
 * Sherwood Adventure Tournament System
 *
 * Handles: open_registration, close_registration, generate_bracket, complete, recalculate_standings
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/bracket-functions.php';
requireAdmin();

$db = getDB();
$tournamentId = intval($_POST['tournament_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

$redirect = "/admin/tournament-manage.php?id={$tournamentId}";

switch ($action) {
    case 'open_registration':
        $db->prepare("UPDATE tournaments SET status = 'registration_open' WHERE id = ?")->execute([$tournamentId]);
        setFlash('success', 'Registration is now open!');
        break;

    case 'close_registration':
        $db->prepare("UPDATE tournaments SET status = 'registration_closed' WHERE id = ?")->execute([$tournamentId]);
        setFlash('success', 'Registration is now closed.');
        break;

    case 'complete':
        $db->prepare("UPDATE tournaments SET status = 'completed' WHERE id = ?")->execute([$tournamentId]);
        setFlash('success', 'Tournament marked as completed.');
        break;

    case 'generate_bracket':
        generateBracket($db, $tournament);
        break;

    case 'recalculate_standings':
        recalculateStandings($db, $tournamentId);
        setFlash('success', 'Standings recalculated.');
        break;

    case 'delete':
        // Delete team logo files before cascade removes the records
        $logos = $db->prepare("SELECT logo_path FROM teams WHERE tournament_id = ? AND logo_path IS NOT NULL");
        $logos->execute([$tournamentId]);
        foreach ($logos->fetchAll() as $logo) {
            $filePath = __DIR__ . '/../uploads/logos/' . $logo['logo_path'];
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }

        // Delete tournament — CASCADE handles matches, teams, standings, time_slots
        $db->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$tournamentId]);

        setFlash('success', "Tournament \"{$tournament['name']}\" and all related data have been permanently deleted.");
        $redirect = '/admin/dashboard.php';
        break;

    default:
        setFlash('error', 'Invalid action.');
}

header("Location: {$redirect}");
exit;

// ============================================================
// BRACKET GENERATION
// ============================================================
function generateBracket($db, $tournament) {
    $tournamentId = $tournament['id'];
    $type = $tournament['tournament_type'];

    // Get active teams
    $teams = $db->prepare("SELECT * FROM teams WHERE tournament_id = ? AND status != 'withdrawn' ORDER BY seed, RAND()");
    $teams->execute([$tournamentId]);
    $teams = $teams->fetchAll();
    $teamCount = count($teams);

    if ($teamCount < $tournament['min_teams']) {
        setFlash('error', "Not enough teams (need at least {$tournament['min_teams']}).");
        return;
    }

    // Delete existing matches
    $db->prepare("DELETE FROM matches WHERE tournament_id = ?")->execute([$tournamentId]);
    $db->prepare("DELETE FROM round_robin_standings WHERE tournament_id = ?")->execute([$tournamentId]);

    // Set status to in_progress
    $db->prepare("UPDATE tournaments SET status = 'in_progress' WHERE id = ?")->execute([$tournamentId]);

    // Also clear round labels
    $db->prepare("DELETE FROM round_labels WHERE tournament_id = ?")->execute([$tournamentId]);

    switch ($type) {
        case 'single_elimination':
            generateSingleElimination($db, $tournamentId, $teams);
            break;
        case 'double_elimination':
            generateDoubleElimination($db, $tournamentId, $teams);
            break;
        case 'round_robin':
            $encounters = $tournament['league_encounters'] ?? 1;
            generateRoundRobin($db, $tournamentId, $teams, $encounters);
            break;
        case 'two_stage':
            generateGroupStage($db, $tournament, $teams);
            break;
        case 'league':
            $encounters = $tournament['league_encounters'] ?? 1;
            generateRoundRobin($db, $tournamentId, $teams, $encounters);
            break;
    }

    setFlash('success', 'Bracket/schedule generated successfully!');
}

function generateSingleElimination($db, $tournamentId, $teams, $bracketType = 'winners') {
    $teamCount = count($teams);
    // Find next power of 2
    $bracketSize = 1;
    while ($bracketSize < $teamCount) $bracketSize *= 2;

    $byes = $bracketSize - $teamCount;
    $totalRounds = intval(log($bracketSize, 2));

    $stmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");

    // Seed teams into first round
    $matchNumber = 0;
    $firstRoundMatches = $bracketSize / 2;

    // Create seeded positions with byes distributed evenly
    // Top seeds get byes; byes are placed in the second slot of the top match positions
    $positions = array_fill(0, $bracketSize, null);
    $byes = $bracketSize - $teamCount;

    if ($byes === 0) {
        // No byes — simple sequential placement
        for ($i = 0; $i < $teamCount; $i++) {
            $positions[$i] = $teams[$i]['id'];
        }
    } else {
        // Place top seeds with byes first (seed 1 gets bye, seed 2 gets bye, etc.)
        // Byes go into the opponent slot of the top-seeded positions
        // Match 1 = positions 0,1; Match 2 = positions 2,3; etc.
        // Seed 1 → position 0 (Match 1, slot 1) with bye at position 1
        // Seed 2 → position (bracketSize-2) (last match, slot 1) with bye at position (bracketSize-1)
        // This spreads byes across the bracket using standard tournament seeding

        // Standard bracket seeding order for positions
        // Build proper seeded bracket positions
        $seedOrder = bracketSeedOrder($bracketSize);
        for ($i = 0; $i < $bracketSize; $i++) {
            $seedIndex = $seedOrder[$i]; // which seed goes in this position
            if ($seedIndex < $teamCount) {
                $positions[$i] = $teams[$seedIndex]['id'];
            }
            // else stays null (bye)
        }
    }

    // Round 1
    for ($i = 0; $i < $firstRoundMatches; $i++) {
        $matchNumber++;
        $team1 = $positions[$i * 2] ?? null;
        $team2 = $positions[$i * 2 + 1] ?? null;

        $status = 'pending';
        if ($team1 === null && $team2 === null) {
            $status = 'bye';
        } elseif ($team1 === null || $team2 === null) {
            $status = 'bye';
        }

        $stmt->execute([$tournamentId, 1, $matchNumber, $bracketType, $team1, $team2, $status]);

        // Auto-advance byes
        if ($status === 'bye') {
            $matchId = $db->lastInsertId();
            $winnerId = $team1 ?? $team2;
            if ($winnerId) {
                $db->prepare("UPDATE matches SET winner_id = ?, status = 'completed' WHERE id = ?")
                   ->execute([$winnerId, $matchId]);
            }
        }
    }

    // Create empty matches for subsequent rounds
    for ($round = 2; $round <= $totalRounds; $round++) {
        $matchesInRound = $bracketSize / pow(2, $round);
        for ($i = 1; $i <= $matchesInRound; $i++) {
            $stmt->execute([$tournamentId, $round, $i, $bracketType, null, null, 'pending']);
        }
    }

    // Advance bye winners to round 2
    advanceByeWinners($db, $tournamentId, $bracketType);
}

function generateDoubleElimination($db, $tournamentId, $teams) {
    // Generate winners bracket
    generateSingleElimination($db, $tournamentId, $teams, 'winners');

    $teamCount = count($teams);
    $bracketSize = 1;
    while ($bracketSize < $teamCount) $bracketSize *= 2;

    // Create losers bracket matches
    // Losers bracket has 2 * (log2(bracketSize) - 1) rounds
    $winnersRounds = intval(log($bracketSize, 2));
    $losersRounds = ($winnersRounds - 1) * 2;

    $stmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
        VALUES (?, ?, ?, 'losers', NULL, NULL, 'pending')
    ");

    $matchesInRound = $bracketSize / 4;
    for ($round = 1; $round <= $losersRounds; $round++) {
        $numMatches = max(1, intval($matchesInRound));
        for ($i = 1; $i <= $numMatches; $i++) {
            $stmt->execute([$tournamentId, $round, $i]);
        }
        // Halve every other round in losers bracket
        if ($round % 2 === 0) {
            $matchesInRound /= 2;
        }
    }

    // Grand final
    $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, status)
        VALUES (?, 1, 1, 'grand_final', 'pending')
    ")->execute([$tournamentId]);
}

function generateRoundRobin($db, $tournamentId, $teams, $encounters = 1) {
    $teamCount = count($teams);
    $stmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
        VALUES (?, ?, ?, 'round_robin', ?, ?, 'pending')
    ");

    // Standard round-robin scheduling
    $teamIds = array_column($teams, 'id');

    // If odd number of teams, add a "bye" placeholder
    if ($teamCount % 2 !== 0) {
        $teamIds[] = null; // bye
        $teamCount++;
    }

    $baseRounds = $teamCount - 1;
    $matchesPerRound = $teamCount / 2;
    $globalMatchNum = 0;

    // Repeat scheduling for each encounter
    for ($enc = 0; $enc < $encounters; $enc++) {
        for ($round = 0; $round < $baseRounds; $round++) {
            $actualRound = ($enc * $baseRounds) + $round + 1;

            for ($match = 0; $match < $matchesPerRound; $match++) {
                if ($match === 0) {
                    $home = 0;
                    $away = ($round % ($teamCount - 1)) + 1;
                } else {
                    $home = (($round + $match) % ($teamCount - 1)) + 1;
                    $away = (($round + $teamCount - 1 - $match) % ($teamCount - 1)) + 1;
                }

                $team1 = $teamIds[$home] ?? null;
                $team2 = $teamIds[$away] ?? null;

                // Skip byes
                if ($team1 === null || $team2 === null) continue;

                $globalMatchNum++;

                // Alternate home/away for even encounters to balance fairness
                if ($enc % 2 === 1) {
                    $stmt->execute([$tournamentId, $actualRound, $globalMatchNum, $team2, $team1]);
                } else {
                    $stmt->execute([$tournamentId, $actualRound, $globalMatchNum, $team1, $team2]);
                }
            }
        }
    }

    // Auto-create round label placeholders
    $totalRounds = $baseRounds * $encounters;
    $labelStmt = $db->prepare("INSERT INTO round_labels (tournament_id, round_number) VALUES (?, ?)");
    for ($r = 1; $r <= $totalRounds; $r++) {
        $labelStmt->execute([$tournamentId, $r]);
    }

    // Initialize standings
    $standingsStmt = $db->prepare("
        INSERT INTO round_robin_standings (tournament_id, team_id, wins, losses, draws, points_for, points_against, point_differential, ranking)
        VALUES (?, ?, 0, 0, 0, 0, 0, 0, NULL)
    ");
    foreach ($teams as $team) {
        $standingsStmt->execute([$tournamentId, $team['id']]);
    }
}

function generateGroupStage($db, $tournament, $teams) {
    $tournamentId = $tournament['id'];

    // Group teams by their time_slot_id (group)
    $groups = [];
    $ungrouped = [];
    foreach ($teams as $team) {
        if ($team['time_slot_id']) {
            $groups[$team['time_slot_id']][] = $team;
        } else {
            $ungrouped[] = $team;
        }
    }

    // Validation: all teams must be in a group for two-stage
    if (!empty($ungrouped)) {
        setFlash('error', 'All teams must be assigned to a group before generating the bracket. ' . count($ungrouped) . ' team(s) have no group assigned.');
        // Revert status
        $db->prepare("UPDATE tournaments SET status = 'registration_closed' WHERE id = ?")->execute([$tournamentId]);
        return;
    }

    // Validation: each group needs at least 2 teams
    foreach ($groups as $slotId => $groupTeams) {
        if (count($groupTeams) < 2) {
            $slotStmt = $db->prepare("SELECT slot_label FROM time_slots WHERE id = ?");
            $slotStmt->execute([$slotId]);
            $slotLabel = $slotStmt->fetchColumn() ?: "Group $slotId";
            setFlash('error', "Group \"{$slotLabel}\" needs at least 2 teams (currently has " . count($groupTeams) . ").");
            $db->prepare("UPDATE tournaments SET status = 'registration_closed' WHERE id = ?")->execute([$tournamentId]);
            return;
        }
    }

    $matchStmt = $db->prepare("
        INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, time_slot_id, status)
        VALUES (?, ?, ?, 'round_robin', ?, ?, ?, 'pending')
    ");

    $standingsStmt = $db->prepare("
        INSERT INTO round_robin_standings (tournament_id, team_id, time_slot_id, wins, losses, draws, points_for, points_against, point_differential, ranking)
        VALUES (?, ?, ?, 0, 0, 0, 0, 0, 0, NULL)
    ");

    // For each group, generate a mini round-robin
    $globalMatchNumber = 0;
    foreach ($groups as $slotId => $groupTeams) {
        $teamIds = array_column($groupTeams, 'id');
        $teamCount = count($teamIds);

        // Add bye placeholder for odd-count groups
        $working = $teamIds;
        if ($teamCount % 2 !== 0) {
            $working[] = null;
            $teamCount++;
        }

        $rounds = $teamCount - 1;
        $matchesPerRound = $teamCount / 2;

        for ($round = 0; $round < $rounds; $round++) {
            for ($match = 0; $match < $matchesPerRound; $match++) {
                if ($match === 0) {
                    $home = 0;
                    $away = ($round % ($teamCount - 1)) + 1;
                } else {
                    $home = (($round + $match) % ($teamCount - 1)) + 1;
                    $away = (($round + $teamCount - 1 - $match) % ($teamCount - 1)) + 1;
                }

                $team1 = $working[$home] ?? null;
                $team2 = $working[$away] ?? null;

                // Skip byes
                if ($team1 === null || $team2 === null) continue;

                $globalMatchNumber++;
                $matchStmt->execute([
                    $tournamentId, $round + 1, $globalMatchNumber,
                    $team1, $team2, $slotId
                ]);
            }
        }

        // Initialize standings for each team in this group
        foreach ($groupTeams as $team) {
            $standingsStmt->execute([$tournamentId, $team['id'], $slotId]);
        }
    }

    // --- Create placeholder elimination bracket ---
    $elimType = $tournament['two_stage_elimination_type'] ?? 'single_elimination';
    $advancePerGroup = $tournament['two_stage_advance_count'] ?? 1;
    $numGroups = count($groups);
    $totalAdvancing = $numGroups * $advancePerGroup;

    if ($totalAdvancing >= 2) {
        $bracketSize = 1;
        while ($bracketSize < $totalAdvancing) $bracketSize *= 2;
        $totalRounds = intval(log($bracketSize, 2));

        $elimStmt = $db->prepare("
            INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
            VALUES (?, ?, ?, 'winners', NULL, NULL, 'pending')
        ");

        // Round 1
        $firstRoundMatches = $bracketSize / 2;
        for ($i = 1; $i <= $firstRoundMatches; $i++) {
            $elimStmt->execute([$tournamentId, 1, $i]);
        }

        // Subsequent rounds
        for ($round = 2; $round <= $totalRounds; $round++) {
            $matchesInRound = $bracketSize / pow(2, $round);
            for ($i = 1; $i <= $matchesInRound; $i++) {
                $elimStmt->execute([$tournamentId, $round, $i]);
            }
        }

        // If double elimination, also create losers bracket and grand final
        if ($elimType === 'double_elimination') {
            $losersRounds = ($totalRounds - 1) * 2;
            $losersStmt = $db->prepare("
                INSERT INTO matches (tournament_id, round, match_number, bracket_type, team1_id, team2_id, status)
                VALUES (?, ?, ?, 'losers', NULL, NULL, 'pending')
            ");
            $matchesInRound = $bracketSize / 4;
            for ($round = 1; $round <= $losersRounds; $round++) {
                $numMatches = max(1, intval($matchesInRound));
                for ($i = 1; $i <= $numMatches; $i++) {
                    $losersStmt->execute([$tournamentId, $round, $i]);
                }
                if ($round % 2 === 0) $matchesInRound /= 2;
            }
            $db->prepare("
                INSERT INTO matches (tournament_id, round, match_number, bracket_type, status)
                VALUES (?, 1, 1, 'grand_final', 'pending')
            ")->execute([$tournamentId]);
        }
    }
}

/**
 * Generate standard tournament seeding order for bracket positions.
 * Returns an array where index = bracket position, value = seed number (0-indexed).
 * This ensures seed 1 and seed 2 can only meet in the final,
 * seeds 1-4 can only meet in semifinals, etc.
 * Byes (null positions) naturally fall to the highest-seeded players.
 */
function bracketSeedOrder($bracketSize) {
    // Start with [0, 1] for a 2-team bracket
    $order = [0, 1];

    // Iteratively build the bracket by doubling the size each time
    while (count($order) < $bracketSize) {
        $newOrder = [];
        $currentSize = count($order);
        $nextSize = $currentSize * 2;
        // For each existing position, pair the seed with its mirror
        // Seed N is paired with (nextSize - 1 - N)
        foreach ($order as $seed) {
            $newOrder[] = $seed;
            $newOrder[] = $nextSize - 1 - $seed;
        }
        $order = $newOrder;
    }

    return $order;
}

function advanceByeWinners($db, $tournamentId, $bracketType) {
    // Get completed bye matches from round 1
    $byes = $db->prepare("
        SELECT id, winner_id, match_number FROM matches
        WHERE tournament_id = ? AND round = 1 AND bracket_type = ? AND status = 'completed' AND winner_id IS NOT NULL
    ");
    $byes->execute([$tournamentId, $bracketType]);
    $byeMatches = $byes->fetchAll();

    foreach ($byeMatches as $bye) {
        // Determine which round 2 match this feeds into
        $r2MatchNum = intval(ceil($bye['match_number'] / 2));

        // Determine if this team goes into team1 or team2 slot
        $slot = ($bye['match_number'] % 2 !== 0) ? 'team1_id' : 'team2_id';

        $db->prepare("
            UPDATE matches SET {$slot} = ?
            WHERE tournament_id = ? AND round = 2 AND match_number = ? AND bracket_type = ?
        ")->execute([$bye['winner_id'], $tournamentId, $r2MatchNum, $bracketType]);
    }

    // After all bye winners are placed, check if any R2 matches have both slots
    // filled (two bye winners). If so, auto-advance one as a bye (higher seed = team1 wins).
    $r2Matches = $db->prepare("
        SELECT * FROM matches
        WHERE tournament_id = ? AND round = 2 AND bracket_type = ? AND status = 'pending'
        AND team1_id IS NOT NULL AND team2_id IS NOT NULL
    ");
    $r2Matches->execute([$tournamentId, $bracketType]);
    foreach ($r2Matches->fetchAll() as $r2) {
        // Both slots filled from byes — auto-advance team1 (higher seed)
        $db->prepare("UPDATE matches SET winner_id = ?, status = 'completed' WHERE id = ?")
           ->execute([$r2['team1_id'], $r2['id']]);

        // Fetch tournament for advanceTeamInBracket
        $tournStmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
        $tournStmt->execute([$tournamentId]);
        $tournament = $tournStmt->fetch();
        if ($tournament) {
            advanceTeamInBracket($db, $tournament, $r2, $r2['team1_id'], $r2['team2_id']);
        }
    }
}

function recalculateStandings($db, $tournamentId) {
    // Reset standings
    $db->prepare("UPDATE round_robin_standings SET wins = 0, losses = 0, draws = 0, points_for = 0, points_against = 0, point_differential = 0 WHERE tournament_id = ?")->execute([$tournamentId]);

    // Get completed round robin matches
    $matches = $db->prepare("
        SELECT * FROM matches
        WHERE tournament_id = ? AND bracket_type = 'round_robin' AND status = 'completed'
    ");
    $matches->execute([$tournamentId]);

    foreach ($matches->fetchAll() as $m) {
        if ($m['team1_score'] !== null && $m['team2_score'] !== null) {
            $s1 = intval($m['team1_score']);
            $s2 = intval($m['team2_score']);

            if ($s1 > $s2) {
                // Team 1 wins
                $db->prepare("UPDATE round_robin_standings SET wins = wins + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s1, $s2, $tournamentId, $m['team1_id']]);
                $db->prepare("UPDATE round_robin_standings SET losses = losses + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s2, $s1, $tournamentId, $m['team2_id']]);
            } elseif ($s2 > $s1) {
                // Team 2 wins
                $db->prepare("UPDATE round_robin_standings SET wins = wins + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s2, $s1, $tournamentId, $m['team2_id']]);
                $db->prepare("UPDATE round_robin_standings SET losses = losses + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s1, $s2, $tournamentId, $m['team1_id']]);
            } else {
                // Draw
                $db->prepare("UPDATE round_robin_standings SET draws = draws + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s1, $s2, $tournamentId, $m['team1_id']]);
                $db->prepare("UPDATE round_robin_standings SET draws = draws + 1, points_for = points_for + ?, points_against = points_against + ? WHERE tournament_id = ? AND team_id = ?")
                   ->execute([$s2, $s1, $tournamentId, $m['team2_id']]);
            }
        }
    }

    // Update point differential
    $db->prepare("UPDATE round_robin_standings SET point_differential = points_for - points_against WHERE tournament_id = ?")->execute([$tournamentId]);

    // Update rankings - check if this is a two-stage tournament (rank per group)
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
                $db->prepare("UPDATE round_robin_standings SET ranking = ? WHERE id = ?")->execute([$rank, $s['id']]);
            }
        }
    } else {
        // Original: rank all teams tournament-wide for standalone round robin
        $standings = $db->prepare("
            SELECT id FROM round_robin_standings
            WHERE tournament_id = ?
            ORDER BY wins DESC, point_differential DESC, points_for DESC
        ");
        $standings->execute([$tournamentId]);
        $rank = 0;
        foreach ($standings->fetchAll() as $s) {
            $rank++;
            $db->prepare("UPDATE round_robin_standings SET ranking = ? WHERE id = ?")->execute([$rank, $s['id']]);
        }
    }
}
