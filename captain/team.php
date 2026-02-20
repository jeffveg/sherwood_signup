<?php
/**
 * Captain Team View / Edit
 * Sherwood Adventure Tournament System
 *
 * Shows team details, match schedule, edit form, and withdraw option.
 */
require_once __DIR__ . '/../includes/auth.php';
requireTeamLogin('/');

$db = getDB();
$teamId = intval($_GET['id'] ?? 0);
$accountId = $_SESSION['team_account_id'];

// Get team and verify ownership
$stmt = $db->prepare("
    SELECT t.*, tn.name as tournament_name, tn.status as tournament_status,
           tn.tournament_type, tn.tournament_number,
           ts.slot_label, ts.slot_date, ts.slot_time
    FROM teams t
    JOIN tournaments tn ON t.tournament_id = tn.id
    LEFT JOIN time_slots ts ON t.time_slot_id = ts.id
    WHERE t.id = ? AND t.team_account_id = ?
");
$stmt->execute([$teamId, $accountId]);
$team = $stmt->fetch();

if (!$team) {
    setFlash('error', 'Team not found or you do not have access.');
    header('Location: /captain/');
    exit;
}

$canEdit = in_array($team['tournament_status'], ['registration_open', 'registration_closed']);

// Get all matches for this team
$matchesStmt = $db->prepare("
    SELECT m.*,
           t1.team_name as team1_name, t1.logo_path as team1_logo, t1.is_forfeit as team1_forfeit,
           t2.team_name as team2_name, t2.logo_path as team2_logo, t2.is_forfeit as team2_forfeit,
           w.team_name as winner_name
    FROM matches m
    LEFT JOIN teams t1 ON m.team1_id = t1.id
    LEFT JOIN teams t2 ON m.team2_id = t2.id
    LEFT JOIN teams w ON m.winner_id = w.id
    WHERE m.tournament_id = ? AND (m.team1_id = ? OR m.team2_id = ?)
    ORDER BY m.bracket_type, m.round, m.match_number
");
$matchesStmt->execute([$team['tournament_id'], $teamId, $teamId]);
$matches = $matchesStmt->fetchAll();

// Get round labels if league/round_robin
$roundLabels = getRoundLabels($db, $team['tournament_id']);
$isLeague = ($team['tournament_type'] === 'league');

$pageTitle = $team['team_name'] . ' - My Teams';
include __DIR__ . '/../includes/header.php';
?>

<!-- Hero -->
<div class="page-hero">
    <div class="container">
        <h1 class="bounce-in"><?php echo h($team['team_name']); ?></h1>
        <p class="subtitle">
            <?php echo h($team['tournament_name']); ?> &middot; #<?php echo h($team['tournament_number']); ?>
        </p>
    </div>
</div>

<div class="container">
    <div style="margin-bottom: 20px;">
        <a href="/captain/" class="btn btn-secondary btn-small">&larr; Back to My Teams</a>
        <a href="/tournament.php?id=<?php echo $team['tournament_id']; ?>" class="btn btn-secondary btn-small" style="margin-left: 8px;">View Tournament</a>
    </div>

    <!-- Team Details Card -->
    <div class="card fade-in" style="margin-bottom: 24px;">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin-bottom: 0;">Team Details</h3>
            <?php if ($team['status'] === 'withdrawn'): ?>
                <span class="badge badge-cancelled">Withdrawn</span>
            <?php elseif ($team['is_forfeit']): ?>
                <span class="badge badge-cancelled">Forfeit</span>
            <?php else: ?>
                <span class="badge badge-open"><?php echo ucfirst($team['status']); ?></span>
            <?php endif; ?>
        </div>

        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px;">
            <div>
                <div style="font-size: 13px; opacity: 0.5; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-heading);">Team Name</div>
                <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px;">
                    <?php if ($team['logo_path']): ?>
                        <img src="/uploads/logos/<?php echo h($team['logo_path']); ?>" class="team-logo" width="32" height="32" alt="">
                    <?php endif; ?>
                    <strong><?php echo h($team['team_name']); ?></strong>
                </div>
            </div>
            <div>
                <div style="font-size: 13px; opacity: 0.5; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-heading);">Registration Code</div>
                <div style="margin-top: 4px; color: var(--color-gold); font-weight: 700;"><?php echo h($team['registration_code']); ?></div>
            </div>
            <div>
                <div style="font-size: 13px; opacity: 0.5; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-heading);">Captain</div>
                <div style="margin-top: 4px;"><?php echo h($team['captain_name']); ?></div>
            </div>
            <div>
                <div style="font-size: 13px; opacity: 0.5; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-heading);">Contact</div>
                <div style="margin-top: 4px;"><?php echo h($team['captain_email']); ?>
                    <?php if ($team['captain_phone']): ?> &middot; <?php echo h($team['captain_phone']); ?><?php endif; ?>
                </div>
            </div>
            <?php if ($team['slot_label']): ?>
            <div>
                <div style="font-size: 13px; opacity: 0.5; text-transform: uppercase; letter-spacing: 0.5px; font-family: var(--font-heading);">Group / Time Slot</div>
                <div style="margin-top: 4px;"><?php echo h($team['slot_label']); ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Edit Form (only during registration) -->
    <?php if ($canEdit && $team['status'] !== 'withdrawn'): ?>
    <div class="card fade-in" style="margin-bottom: 24px;">
        <h3>Edit Team</h3>
        <form method="POST" action="/api/captain-action.php" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_team">
            <input type="hidden" name="team_id" value="<?php echo $teamId; ?>">

            <div class="form-group">
                <label for="team_name_edit">Team Name</label>
                <input type="text" id="team_name_edit" name="team_name" class="form-control"
                       value="<?php echo h($team['team_name']); ?>" required maxlength="255">
            </div>

            <div class="form-group">
                <label for="team_logo_edit">Update Logo <small style="opacity: 0.6;">(optional, max 2MB)</small></label>
                <input type="file" id="team_logo_edit" name="team_logo" class="form-control"
                       accept="image/jpeg,image/png,image/gif,image/webp">
            </div>

            <div style="display: flex; gap: 10px; align-items: center;">
                <button type="submit" class="btn btn-primary btn-small">Save Changes</button>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Match Schedule -->
    <?php if (!empty($matches)): ?>
    <div class="card fade-in" style="margin-bottom: 24px;">
        <h3>Match Schedule</h3>
        <div class="table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Round</th>
                        <th>Opponent</th>
                        <th>Score</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($matches as $m): ?>
                    <?php
                        $isTeam1 = ($m['team1_id'] == $teamId);
                        $opponentName = $isTeam1 ? ($m['team2_name'] ?? 'TBD') : ($m['team1_name'] ?? 'TBD');
                        $myScore = $isTeam1 ? $m['team1_score'] : $m['team2_score'];
                        $theirScore = $isTeam1 ? $m['team2_score'] : $m['team1_score'];
                        $isWinner = ($m['winner_id'] == $teamId);
                        $isLoser = ($m['winner_id'] && $m['winner_id'] != $teamId);

                        // Round label
                        if ($isLeague && isset($roundLabels[$m['round']])) {
                            $roundLabel = $roundLabels[$m['round']]['label'] ?? "Week {$m['round']}";
                        } elseif ($isLeague) {
                            $roundLabel = "Week {$m['round']}";
                        } else {
                            $roundLabel = ucfirst($m['bracket_type']) . " R{$m['round']}";
                        }
                    ?>
                    <tr>
                        <td><?php echo h($roundLabel); ?></td>
                        <td><?php echo h($opponentName); ?></td>
                        <td>
                            <?php if ($m['status'] === 'completed'): ?>
                                <strong><?php echo $myScore; ?></strong> - <?php echo $theirScore; ?>
                            <?php elseif ($m['status'] === 'in_progress'): ?>
                                <span style="color: var(--color-teal);"><?php echo $myScore ?? '0'; ?> - <?php echo $theirScore ?? '0'; ?></span>
                            <?php else: ?>
                                <span style="opacity: 0.4;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['status'] === 'completed'): ?>
                                <?php if ($isWinner): ?>
                                    <span style="color: var(--color-success); font-weight: 700;">WIN</span>
                                <?php elseif ($isLoser): ?>
                                    <span style="color: var(--color-danger);">LOSS</span>
                                <?php else: ?>
                                    <span style="color: var(--color-warning);">DRAW</span>
                                <?php endif; ?>
                            <?php elseif ($m['status'] === 'in_progress'): ?>
                                <span class="badge badge-in-progress">LIVE</span>
                            <?php elseif ($m['status'] === 'bye'): ?>
                                <span style="opacity: 0.4;">BYE</span>
                            <?php else: ?>
                                <span style="opacity: 0.4;">Upcoming</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Withdraw -->
    <?php if ($canEdit && $team['status'] !== 'withdrawn'): ?>
    <div class="card fade-in" style="border-color: var(--color-danger);">
        <h3 style="color: var(--color-danger);">Withdraw Team</h3>
        <p style="font-size: 14px; opacity: 0.7; margin-bottom: 16px;">
            Withdrawing removes your team from the tournament. This cannot be undone.
        </p>
        <form method="POST" action="/api/captain-action.php" onsubmit="return confirm('Are you sure you want to withdraw this team? This cannot be undone.');">
            <input type="hidden" name="action" value="withdraw_team">
            <input type="hidden" name="team_id" value="<?php echo $teamId; ?>">
            <button type="submit" class="btn btn-danger btn-small">Withdraw Team</button>
        </form>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
