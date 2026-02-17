<?php
/**
 * Public Tournament Listing
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/includes/auth.php';

$db = getDB();

// Get publicly visible tournaments
$tournaments = $db->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM teams WHERE tournament_id = t.id AND status != 'withdrawn') as team_count
    FROM tournaments t
    WHERE t.status != 'draft' AND t.status != 'cancelled'
    ORDER BY
        CASE t.status
            WHEN 'registration_open' THEN 1
            WHEN 'in_progress' THEN 2
            WHEN 'registration_closed' THEN 3
            WHEN 'completed' THEN 4
        END,
        t.start_date ASC
")->fetchAll();

$pageTitle = '';
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div class="page-hero">
    <div class="container">
        <h1 class="bounce-in">Sherwood Tournaments</h1>
        <p class="subtitle">Join the adventure. Compete for glory.</p>
    </div>
</div>

<div class="container">
    <?php if (empty($tournaments)): ?>
        <div class="empty-state fade-in">
            <div class="empty-state-icon">&#9876;</div>
            <h3>No Active Tournaments</h3>
            <p>Check back soon for upcoming tournaments!</p>
        </div>
    <?php else: ?>
        <div class="tournament-grid">
            <?php foreach ($tournaments as $t): ?>
            <div class="tournament-card fade-in">
                <div class="tournament-card-header">
                    <div class="flex-between">
                        <h3 style="margin-bottom: 0;"><?php echo h($t['name']); ?></h3>
                        <?php
                        $statusMap = [
                            'registration_open' => ['badge-open', 'Open'],
                            'registration_closed' => ['badge-closed', 'Closed'],
                            'in_progress' => ['badge-in-progress', 'In Progress'],
                            'completed' => ['badge-completed', 'Completed'],
                        ];
                        $badge = $statusMap[$t['status']] ?? ['badge-draft', $t['status']];
                        ?>
                        <span class="badge <?php echo $badge[0]; ?>"><?php echo $badge[1]; ?></span>
                    </div>
                </div>

                <div class="tournament-card-body">
                    <ul class="tournament-meta">
                        <li>
                            <span class="meta-label">Format</span>
                            <span class="badge badge-type"><?php echo h(ucwords(str_replace('_', ' ', $t['tournament_type']))); ?></span>
                            <?php if ($t['tournament_type'] === 'two_stage'): ?>
                                <small class="text-muted">+ <?php echo ucwords(str_replace('_', ' ', $t['two_stage_elimination_type'])); ?></small>
                            <?php endif; ?>
                        </li>
                        <li>
                            <span class="meta-label">Tournament #</span>
                            <code style="color: var(--color-orange);"><?php echo h($t['tournament_number']); ?></code>
                        </li>
                        <li>
                            <span class="meta-label">Teams</span>
                            <span><?php echo $t['team_count']; ?> / <?php echo $t['max_teams']; ?></span>
                            <?php if ($t['team_count'] >= $t['max_teams']): ?>
                                <span class="badge badge-cancelled" style="margin-left: 5px;">Full</span>
                            <?php endif; ?>
                        </li>
                        <?php if ($t['start_date']): ?>
                        <li>
                            <span class="meta-label">Date</span>
                            <span>
                                <?php echo date('M j, Y', strtotime($t['start_date'])); ?>
                                <?php if ($t['end_date'] && $t['end_date'] !== $t['start_date']): ?>
                                    - <?php echo date('M j, Y', strtotime($t['end_date'])); ?>
                                <?php endif; ?>
                            </span>
                        </li>
                        <?php endif; ?>
                        <?php if ($t['location']): ?>
                        <li>
                            <span class="meta-label">Location</span>
                            <span><?php echo h($t['location']); ?></span>
                        </li>
                        <?php endif; ?>
                        <?php if ($t['registration_deadline'] && $t['status'] === 'registration_open'): ?>
                        <li>
                            <span class="meta-label">Deadline</span>
                            <span style="color: var(--color-warning);">
                                <?php echo date('M j, Y g:i A', strtotime($t['registration_deadline'])); ?>
                            </span>
                        </li>
                        <?php endif; ?>
                    </ul>

                    <?php if ($t['description']): ?>
                        <p style="font-size: 14px; opacity: 0.8; margin-bottom: 0;">
                            <?php echo h(substr($t['description'], 0, 150)); ?>
                            <?php echo strlen($t['description']) > 150 ? '...' : ''; ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="tournament-card-footer">
                    <a href="/tournament.php?id=<?php echo $t['id']; ?>" class="btn btn-primary btn-small">View Details</a>
                    <?php if ($t['status'] === 'registration_open' && $t['team_count'] < $t['max_teams']): ?>
                        <a href="/signup.php?tournament_id=<?php echo $t['id']; ?>" class="btn btn-secondary btn-small">Sign Up</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
