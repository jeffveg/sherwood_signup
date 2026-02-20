<?php
/**
 * Bracket Functions (Shared)
 * Sherwood Adventure Tournament System
 *
 * Shared functions for bracket advancement, standings recalculation,
 * and two-stage elimination stage generation.
 * Used by match-update.php and team-action.php.
 */

/**
 * After placing a team into a match slot, check if the match now has both
 * teams and one of them is forfeited. If so, auto-complete the match
 * (non-forfeited team wins 1-0) and recursively advance.
 */
function autoCompleteIfForfeit($db, $tournament, $matchId) {
    $stmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if (!$match) return;

    // Both slots must be filled
    if (empty($match['team1_id']) || empty($match['team2_id'])) return;

    // Match must still be pending (not already completed)
    if ($match['status'] === 'completed') return;

    // Check if either team is forfeited
    $t1 = $db->prepare("SELECT is_forfeit FROM teams WHERE id = ?");
    $t1->execute([$match['team1_id']]);
    $team1Forfeit = (int)$t1->fetchColumn();

    $t2 = $db->prepare("SELECT is_forfeit FROM teams WHERE id = ?");
    $t2->execute([$match['team2_id']]);
    $team2Forfeit = (int)$t2->fetchColumn();

    // If neither team is forfeited, nothing to auto-complete
    if (!$team1Forfeit && !$team2Forfeit) return;

    // Determine winner: non-forfeited team wins, or if both forfeited, team1 wins by default
    if ($team1Forfeit && !$team2Forfeit) {
        $winnerId = $match['team2_id'];
        $loserId = $match['team1_id'];
        $team1Score = 0;
        $team2Score = 1;
    } elseif (!$team1Forfeit && $team2Forfeit) {
        $winnerId = $match['team1_id'];
        $loserId = $match['team2_id'];
        $team1Score = 1;
        $team2Score = 0;
    } else {
        // Both forfeited — team1 wins by default
        $winnerId = $match['team1_id'];
        $loserId = $match['team2_id'];
        $team1Score = 0;
        $team2Score = 0;
    }

    // Auto-complete the match
    $db->prepare("UPDATE matches SET winner_id = ?, loser_id = ?, team1_score = ?, team2_score = ?, status = 'completed' WHERE id = ?")
       ->execute([$winnerId, $loserId, $team1Score, $team2Score, $matchId]);

    // Re-fetch the updated match and recursively advance
    $updatedStmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
    $updatedStmt->execute([$matchId]);
    $updatedMatch = $updatedStmt->fetch();
    advanceTeamInBracket($db, $tournament, $updatedMatch, $winnerId, $loserId);
}

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
            // Check if destination match can be auto-completed (cascading forfeits)
            autoCompleteIfForfeit($db, $tournament, $nextMatch['id']);
        }

        // Winners bracket final winner → grand final (no next winners round exists)
        if (!$nextMatch && $bracketType === 'winners' && $tournament['tournament_type'] === 'double_elimination') {
            $gf = $db->prepare("SELECT id, team1_id FROM matches WHERE tournament_id = ? AND bracket_type = 'grand_final' LIMIT 1");
            $gf->execute([$tournamentId]);
            $grandFinal = $gf->fetch();
            if ($grandFinal) {
                $slot = empty($grandFinal['team1_id']) ? 'team1_id' : 'team2_id';
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")->execute([$winnerId, $grandFinal['id']]);
                autoCompleteIfForfeit($db, $tournament, $grandFinal['id']);
            }
        }

        // For double elimination, send loser to losers bracket
        if ($tournament['tournament_type'] === 'double_elimination' && $loserId && $bracketType === 'winners') {
            // Map winners round to correct losers round:
            // WR1 → LR1 (odd round, losers play each other)
            // WRn (n>1) → LR 2*(n-1) (even round, drop-in from winners)
            if ($round === 1) {
                $losersRound = 1;
                $losersMatchNum = intval(ceil($matchNum / 2));
            } else {
                $losersRound = 2 * ($round - 1);
                $losersMatchNum = $matchNum; // 1:1 mapping for drop-ins
            }

            $loserMatch = $db->prepare("
                SELECT id, team1_id, team2_id FROM matches
                WHERE tournament_id = ? AND bracket_type = 'losers' AND round = ? AND match_number = ?
            ");
            $loserMatch->execute([$tournamentId, $losersRound, $losersMatchNum]);
            $lm = $loserMatch->fetch();

            if ($lm) {
                if ($round === 1) {
                    // WR1 losers fill both slots of LR1 matches
                    $slot = empty($lm['team1_id']) ? 'team1_id' : 'team2_id';
                } else {
                    // WRn (n>1) losers drop into team2_id slot (feed-in)
                    $slot = 'team2_id';
                }
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")
                   ->execute([$loserId, $lm['id']]);
                // Check if losers bracket match can be auto-completed (cascading forfeits)
                autoCompleteIfForfeit($db, $tournament, $lm['id']);
            }
        }
    }

    if ($bracketType === 'losers') {
        $nextRound = $round + 1;

        // Check if there's a next losers round
        $nextCheck = $db->prepare("
            SELECT COUNT(*) FROM matches
            WHERE tournament_id = ? AND bracket_type = 'losers' AND round = ?
        ");
        $nextCheck->execute([$tournamentId, $nextRound]);
        $hasNextLosersRound = $nextCheck->fetchColumn() > 0;

        if ($hasNextLosersRound) {
            if ($round % 2 === 1) {
                // From odd round (internal): same match count to next even round
                // Match number stays same, winner → team1_id (internal slot)
                $nextMatchNum = $matchNum;
                $slot = 'team1_id';
            } else {
                // From even round (feed-in): halves to next odd round
                $nextMatchNum = intval(ceil($matchNum / 2));
                $slot = ($matchNum % 2 !== 0) ? 'team1_id' : 'team2_id';
            }

            $nextMatch = $db->prepare("
                SELECT id FROM matches
                WHERE tournament_id = ? AND bracket_type = 'losers' AND round = ? AND match_number = ?
            ");
            $nextMatch->execute([$tournamentId, $nextRound, $nextMatchNum]);
            $nm = $nextMatch->fetch();

            if ($nm) {
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")->execute([$winnerId, $nm['id']]);
                // Check if destination match can be auto-completed (cascading forfeits)
                autoCompleteIfForfeit($db, $tournament, $nm['id']);
            }
        } else {
            // No more losers rounds — winner goes to grand final
            $gf = $db->prepare("SELECT id, team1_id FROM matches WHERE tournament_id = ? AND bracket_type = 'grand_final' LIMIT 1");
            $gf->execute([$tournamentId]);
            $grandFinal = $gf->fetch();
            if ($grandFinal) {
                $slot = empty($grandFinal['team1_id']) ? 'team1_id' : 'team2_id';
                $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")->execute([$winnerId, $grandFinal['id']]);
                autoCompleteIfForfeit($db, $tournament, $grandFinal['id']);
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
 * For two-stage tournaments: check each group for completion and advance teams
 * into the pre-created elimination bracket as each group finishes.
 */
function checkAndGenerateEliminationStage($db, $tournament) {
    $tournamentId = $tournament['id'];
    $advancePerGroup = $tournament['two_stage_advance_count'] ?? 1;

    // Check if elimination bracket placeholder matches exist
    $existingStmt = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND bracket_type = 'winners'");
    $existingStmt->execute([$tournamentId]);
    if ($existingStmt->fetchColumn() == 0) return;

    // Get all groups (ordered consistently by time_slot_id)
    $groupsStmt = $db->prepare("SELECT DISTINCT time_slot_id FROM round_robin_standings WHERE tournament_id = ? AND time_slot_id IS NOT NULL ORDER BY time_slot_id");
    $groupsStmt->execute([$tournamentId]);
    $groups = $groupsStmt->fetchAll();
    $numGroups = count($groups);
    if ($numGroups == 0) return;

    $totalAdvancing = $numGroups * $advancePerGroup;
    $bracketSize = 1;
    while ($bracketSize < $totalAdvancing) $bracketSize *= 2;

    // For each group, check if all its RR matches are complete
    foreach ($groups as $gIndex => $group) {
        $slotId = $group['time_slot_id'];

        // Check if this group has incomplete matches
        $pendingStmt = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND bracket_type = 'round_robin' AND time_slot_id = ? AND status != 'completed'");
        $pendingStmt->execute([$tournamentId, $slotId]);
        if ($pendingStmt->fetchColumn() > 0) continue;

        // Get top N teams from this group (ordered by ranking)
        $topTeamsStmt = $db->prepare("SELECT team_id FROM round_robin_standings WHERE tournament_id = ? AND time_slot_id = ? ORDER BY ranking ASC LIMIT ?");
        $topTeamsStmt->execute([$tournamentId, $slotId, $advancePerGroup]);
        $advancers = $topTeamsStmt->fetchAll();

        // Place each advancing team into the bracket
        foreach ($advancers as $rIndex => $adv) {
            $position = $rIndex * $numGroups + $gIndex;
            $matchIndex = intdiv($position, 2);
            $matchNumber = $matchIndex + 1;
            $slot = ($position % 2 === 0) ? 'team1_id' : 'team2_id';

            $bracketStmt = $db->prepare("SELECT id, team1_id, team2_id FROM matches WHERE tournament_id = ? AND bracket_type = 'winners' AND round = 1 AND match_number = ?");
            $bracketStmt->execute([$tournamentId, $matchNumber]);
            $bracketMatch = $bracketStmt->fetch();
            if (!$bracketMatch) continue;

            if ($bracketMatch[$slot]) continue;

            $db->prepare("UPDATE matches SET {$slot} = ? WHERE id = ?")->execute([$adv['team_id'], $bracketMatch['id']]);

            $reloadStmt = $db->prepare("SELECT * FROM matches WHERE id = ?");
            $reloadStmt->execute([$bracketMatch['id']]);
            $updatedMatch = $reloadStmt->fetch();

            $otherPosition = ($position % 2 === 0) ? $position + 1 : $position - 1;
            $otherSlot = ($slot === 'team1_id') ? 'team2_id' : 'team1_id';

            if ($otherPosition >= $totalAdvancing && !$updatedMatch[$otherSlot]) {
                $db->prepare("UPDATE matches SET winner_id = ?, status = 'completed' WHERE id = ?")
                   ->execute([$adv['team_id'], $updatedMatch['id']]);
                advanceTeamInBracket($db, $tournament, $updatedMatch, $adv['team_id'], null);
            }
        }
    }
}
