<?php
/**
 * Admin Dashboard
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();

// Get stats
$totalTournaments = $db->query("SELECT COUNT(*) FROM tournaments")->fetchColumn();
$activeTournaments = $db->query("SELECT COUNT(*) FROM tournaments WHERE status IN ('registration_open','registration_closed','in_progress')")->fetchColumn();
$totalTeams = $db->query("SELECT COUNT(*) FROM teams")->fetchColumn();
$recentSignups = $db->query("SELECT COUNT(*) FROM teams WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

// Get recent tournaments
$recentTournaments = $db->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM teams WHERE tournament_id = t.id) as team_count
    FROM tournaments t
    ORDER BY t.created_at DESC
    LIMIT 10
")->fetchAll();

$pageTitle = 'Admin Dashboard';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Dashboard</h1>
        <div class="admin-actions">
            <a href="/admin/tournament-create.php" class="btn btn-primary">Create Tournament</a>
            <a href="/admin/setup.php" class="btn btn-secondary btn-small">Setup</a>
        </div>
    </div>

    <!-- Stats -->
    <div class="stats-grid fade-in">
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalTournaments; ?></div>
            <div class="stat-label">Total Tournaments</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $activeTournaments; ?></div>
            <div class="stat-label">Active</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $totalTeams; ?></div>
            <div class="stat-label">Total Teams</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $recentSignups; ?></div>
            <div class="stat-label">Signups (7 Days)</div>
        </div>
    </div>

    <!-- Recent Tournaments -->
    <div class="form-section">
        <h2 class="form-section-title">Recent Tournaments</h2>

        <?php if (empty($recentTournaments)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">&#9876;</div>
                <h3>No tournaments yet</h3>
                <p>Create your first tournament to get started.</p>
                <a href="/admin/tournament-create.php" class="btn btn-primary">Create Tournament</a>
            </div>
        <?php else: ?>
            <div class="admin-table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Tournament #</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Teams</th>
                            <th>Date</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentTournaments as $t): ?>
                        <tr>
                            <td><code style="color: var(--color-orange);"><?php echo h($t['tournament_number']); ?></code></td>
                            <td><strong><?php echo h($t['name']); ?></strong></td>
                            <td>
                                <span class="badge badge-type">
                                    <?php echo h(ucwords(str_replace('_', ' ', $t['tournament_type']))); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $statusMap = [
                                    'draft' => 'badge-draft',
                                    'registration_open' => 'badge-open',
                                    'registration_closed' => 'badge-closed',
                                    'in_progress' => 'badge-in-progress',
                                    'completed' => 'badge-completed',
                                    'cancelled' => 'badge-cancelled',
                                ];
                                $badgeClass = $statusMap[$t['status']] ?? 'badge-draft';
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo h(ucwords(str_replace('_', ' ', $t['status']))); ?>
                                </span>
                            </td>
                            <td><?php echo $t['team_count']; ?> / <?php echo $t['max_teams']; ?></td>
                            <td><?php echo $t['start_date'] ? date('M j, Y', strtotime($t['start_date'])) : 'TBD'; ?></td>
                            <td>
                                <div class="admin-table-actions">
                                    <a href="/admin/tournament-edit.php?id=<?php echo $t['id']; ?>" class="btn btn-secondary btn-small">Edit</a>
                                    <a href="/admin/tournament-manage.php?id=<?php echo $t['id']; ?>" class="btn btn-primary btn-small">Manage</a>
                                    <form method="POST" action="/api/tournament-action.php" style="display: inline;">
                                        <input type="hidden" name="tournament_id" value="<?php echo $t['id']; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <button type="submit" class="btn btn-danger btn-small" onclick="return confirm('PERMANENTLY DELETE &quot;<?php echo h($t['name']); ?>&quot; and ALL its data? This cannot be undone!')">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
