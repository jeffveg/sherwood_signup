<?php
/**
 * Captain Dashboard
 * Sherwood Adventure Tournament System
 *
 * Shows all teams owned by the logged-in captain, grouped by tournament.
 */
require_once __DIR__ . '/../includes/auth.php';
requireTeamLogin('/');

$db = getDB();
$accountId = $_SESSION['team_account_id'];

// Get all teams for this captain
$teamsStmt = $db->prepare("
    SELECT t.*, tn.name as tournament_name, tn.status as tournament_status,
           tn.tournament_type, tn.tournament_number,
           ts.slot_label, ts.slot_date, ts.slot_time
    FROM teams t
    JOIN tournaments tn ON t.tournament_id = tn.id
    LEFT JOIN time_slots ts ON t.time_slot_id = ts.id
    WHERE t.team_account_id = ?
    ORDER BY tn.start_date DESC, t.created_at DESC
");
$teamsStmt->execute([$accountId]);
$allTeams = $teamsStmt->fetchAll();

// Group by tournament
$teamsByTournament = [];
foreach ($allTeams as $team) {
    $teamsByTournament[$team['tournament_id']][] = $team;
}

// Get next upcoming match for each team
$nextMatches = [];
foreach ($allTeams as $team) {
    $matchStmt = $db->prepare("
        SELECT m.*,
               t1.team_name as team1_name, t1.logo_path as team1_logo,
               t2.team_name as team2_name, t2.logo_path as team2_logo
        FROM matches m
        LEFT JOIN teams t1 ON m.team1_id = t1.id
        LEFT JOIN teams t2 ON m.team2_id = t2.id
        WHERE m.tournament_id = ? AND (m.team1_id = ? OR m.team2_id = ?)
              AND m.status IN ('pending', 'in_progress')
        ORDER BY m.round, m.match_number
        LIMIT 1
    ");
    $matchStmt->execute([$team['tournament_id'], $team['id'], $team['id']]);
    $nextMatch = $matchStmt->fetch();
    if ($nextMatch) {
        $nextMatches[$team['id']] = $nextMatch;
    }
}

$pageTitle = 'My Teams';
include __DIR__ . '/../includes/header.php';
?>

<!-- Hero -->
<div class="page-hero">
    <div class="container">
        <h1 class="bounce-in">My Teams</h1>
        <p class="subtitle">
            Welcome, <?php echo h($_SESSION['team_account_name']); ?>
        </p>
    </div>
</div>

<div class="container">
    <?php if (empty($allTeams)): ?>
        <div class="empty-state fade-in">
            <div class="empty-state-icon">&#127942;</div>
            <h3>No Teams Yet</h3>
            <p>You haven't registered any teams. Browse tournaments to sign up!</p>
            <a href="/" class="btn btn-primary">Browse Tournaments</a>
        </div>
    <?php else: ?>
        <?php foreach ($teamsByTournament as $tournamentId => $teams): ?>
            <?php $firstTeam = $teams[0]; ?>
            <div class="card fade-in" style="margin-bottom: 24px;">
                <div class="card-header" style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <h3 style="margin-bottom: 4px;">
                            <a href="/tournament.php?id=<?php echo $tournamentId; ?>" style="text-decoration: none;">
                                <?php echo h($firstTeam['tournament_name']); ?>
                            </a>
                        </h3>
                        <span class="badge badge-type"><?php echo ucwords(str_replace('_', ' ', $firstTeam['tournament_type'])); ?></span>
                        <span class="badge badge-<?php echo $firstTeam['tournament_status'] === 'registration_open' ? 'open' : ($firstTeam['tournament_status'] === 'in_progress' ? 'in-progress' : ($firstTeam['tournament_status'] === 'completed' ? 'completed' : 'draft')); ?>">
                            <?php echo ucwords(str_replace('_', ' ', $firstTeam['tournament_status'])); ?>
                        </span>
                    </div>
                    <span style="font-size: 13px; opacity: 0.5;">
                        #<?php echo h($firstTeam['tournament_number']); ?>
                    </span>
                </div>

                <?php foreach ($teams as $team): ?>
                <div class="captain-team-row">
                    <div class="captain-team-info">
                        <div style="display: flex; align-items: center; gap: 12px;">
                            <?php if ($team['logo_path']): ?>
                                <img src="/uploads/logos/<?php echo h($team['logo_path']); ?>" class="team-logo" width="40" height="40" alt="">
                            <?php endif; ?>
                            <div>
                                <strong style="font-size: 18px;"><?php echo h($team['team_name']); ?></strong>
                                <?php if ($team['status'] === 'withdrawn'): ?>
                                    <span class="badge badge-cancelled" style="margin-left: 8px;">Withdrawn</span>
                                <?php elseif ($team['is_forfeit']): ?>
                                    <span class="badge badge-cancelled" style="margin-left: 8px;">Forfeit</span>
                                <?php endif; ?>
                                <div style="font-size: 13px; opacity: 0.6;">
                                    Code: <strong><?php echo h($team['registration_code']); ?></strong>
                                    <?php if ($team['slot_label']): ?>
                                        &middot; <?php echo h($team['slot_label']); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="captain-team-actions">
                        <?php if (isset($nextMatches[$team['id']])): ?>
                            <?php $nm = $nextMatches[$team['id']]; ?>
                            <span style="font-size: 13px; color: var(--color-teal); margin-right: 12px;">
                                Next: <?php echo h($nm['team1_name']); ?> vs <?php echo h($nm['team2_name']); ?>
                                <?php if ($nm['status'] === 'in_progress'): ?>
                                    <span class="badge badge-in-progress" style="margin-left: 4px;">LIVE</span>
                                <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        <a href="/captain/team.php?id=<?php echo $team['id']; ?>" class="btn btn-secondary btn-small">View / Edit</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
