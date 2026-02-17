<?php
/**
 * Admin: Edit Team
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$teamId = intval($_GET['id'] ?? 0);

$stmt = $db->prepare("SELECT t.*, tn.tournament_type, tn.id as tid FROM teams t JOIN tournaments tn ON t.tournament_id = tn.id WHERE t.id = ?");
$stmt->execute([$teamId]);
$team = $stmt->fetch();

if (!$team) {
    setFlash('error', 'Team not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

$tournamentId = $team['tid'];
$hasTimeSlots = in_array($team['tournament_type'], ['round_robin', 'two_stage']);

$timeSlots = [];
if ($hasTimeSlots) {
    $slotStmt = $db->prepare("
        SELECT ts.*, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id AND id != ?) as team_count
        FROM time_slots ts WHERE ts.tournament_id = ? ORDER BY ts.slot_date, ts.slot_time
    ");
    $slotStmt->execute([$teamId, $tournamentId]);
    $timeSlots = $slotStmt->fetchAll();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_name = trim($_POST['team_name'] ?? '');
    $captain_name = trim($_POST['captain_name'] ?? '');
    $captain_email = trim($_POST['captain_email'] ?? '');
    $captain_phone = trim($_POST['captain_phone'] ?? '');
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'registered';
    $seed = intval($_POST['seed'] ?? 0) ?: null;

    if (empty($team_name)) $errors[] = 'Team name is required.';
    if (empty($captain_name)) $errors[] = 'Captain name is required.';
    if (empty($captain_email)) $errors[] = 'Captain email is required.';

    // Check duplicate name
    $check = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND team_name = ? AND id != ?");
    $check->execute([$tournamentId, $team_name, $teamId]);
    if ($check->fetchColumn() > 0) $errors[] = 'Another team with this name already exists.';

    if (empty($errors)) {
        $stmt = $db->prepare("
            UPDATE teams SET team_name = ?, captain_name = ?, captain_email = ?, captain_phone = ?,
                time_slot_id = ?, status = ?, seed = ?
            WHERE id = ?
        ");
        $stmt->execute([$team_name, $captain_name, $captain_email, $captain_phone, $time_slot_id, $status, $seed, $teamId]);

        setFlash('success', 'Team updated successfully!');
        header("Location: /admin/tournament-manage.php?id={$tournamentId}");
        exit;
    }

    $team = array_merge($team, $_POST);
}

$pageTitle = 'Edit Team';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Edit Team: <?php echo h($team['team_name']); ?></h1>
        <a href="/admin/tournament-manage.php?id=<?php echo $tournamentId; ?>" class="btn btn-secondary btn-small">Back</a>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="flash-message flash-error" style="border-radius: var(--border-radius); padding: 16px; margin-bottom: 20px;">
            <ul style="list-style: none;">
                <?php foreach ($errors as $err): ?>
                    <li>&#9888; <?php echo h($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="form-section">
        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="team_name">Team Name *</label>
                    <input type="text" id="team_name" name="team_name" class="form-control"
                           value="<?php echo h($team['team_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="seed">Seed #</label>
                    <input type="number" id="seed" name="seed" class="form-control"
                           value="<?php echo h($team['seed']); ?>" min="1">
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="captain_name">Captain Name *</label>
                    <input type="text" id="captain_name" name="captain_name" class="form-control"
                           value="<?php echo h($team['captain_name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="captain_email">Captain Email *</label>
                    <input type="email" id="captain_email" name="captain_email" class="form-control"
                           value="<?php echo h($team['captain_email']); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="captain_phone">Phone</label>
                    <input type="tel" id="captain_phone" name="captain_phone" class="form-control"
                           value="<?php echo h($team['captain_phone']); ?>">
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <?php foreach (['registered','confirmed','checked_in','eliminated','withdrawn'] as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $team['status'] === $s ? 'selected' : ''; ?>>
                            <?php echo ucfirst($s); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <?php if (!empty($timeSlots)): ?>
            <div class="form-group">
                <label for="time_slot_id">Time Slot</label>
                <select id="time_slot_id" name="time_slot_id" class="form-control">
                    <option value="">-- No Time Slot --</option>
                    <?php foreach ($timeSlots as $slot): ?>
                        <?php $isFull = $slot['team_count'] >= $slot['max_teams']; ?>
                        <option value="<?php echo $slot['id']; ?>"
                                <?php echo ($isFull && $team['time_slot_id'] != $slot['id']) ? 'disabled' : ''; ?>
                                <?php echo $team['time_slot_id'] == $slot['id'] ? 'selected' : ''; ?>>
                            <?php echo h($slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time']))); ?>
                            - <?php echo date('M j', strtotime($slot['slot_date'])); ?>
                            (<?php echo $slot['team_count']; ?>/<?php echo $slot['max_teams']; ?>)
                            <?php echo ($isFull && $team['time_slot_id'] != $slot['id']) ? ' - FULL' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="card" style="background: var(--color-primary-green); margin-top: 10px;">
                <p style="font-size: 13px; margin: 0;">
                    <strong class="text-gold">Registration Code:</strong>
                    <code style="font-size: 14px;"><?php echo h($team['registration_code']); ?></code>
                </p>
                <p style="font-size: 13px; margin: 8px 0 0;">
                    <strong class="text-gold">Registered:</strong>
                    <?php echo date('M j, Y g:i A', strtotime($team['created_at'])); ?>
                </p>
            </div>

            <div class="flex-between mt-2">
                <a href="/admin/tournament-manage.php?id=<?php echo $tournamentId; ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
