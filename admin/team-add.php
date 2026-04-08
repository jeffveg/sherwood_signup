<?php
/**
 * Admin: Add Team to Tournament
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$tournament_id = intval($_GET['tournament_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournament_id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

// Fetch time slots if needed
$timeSlots = [];
if (in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league'])) {
    $slotStmt = $db->prepare("
        SELECT ts.*, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id) as team_count
        FROM time_slots ts WHERE ts.tournament_id = ?
        ORDER BY ts.slot_date, ts.slot_time
    ");
    $slotStmt->execute([$tournament_id]);
    $timeSlots = $slotStmt->fetchAll();
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $team_name = trim($_POST['team_name'] ?? '');
    $captain_name = trim($_POST['captain_name'] ?? '');
    $captain_email = trim($_POST['captain_email'] ?? '');
    $captain_phone = trim($_POST['captain_phone'] ?? '');
    $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;
    if ($sms_opt_in && empty($captain_phone)) $sms_opt_in = 0; // Can't opt in without a phone
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0) ?: null;
    $status = $_POST['status'] ?? 'registered';

    if (empty($team_name)) $errors[] = 'Team name is required.';
    if (empty($captain_name)) $errors[] = 'Captain name is required.';
    if (empty($captain_email)) $errors[] = 'Captain email is required.';

    // Check for duplicate team name
    if (!empty($team_name)) {
        $check = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND team_name = ?");
        $check->execute([$tournament_id, $team_name]);
        if ($check->fetchColumn() > 0) $errors[] = 'A team with this name is already registered.';
    }

    // Check team count
    $teamCount = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND status != 'withdrawn'");
    $teamCount->execute([$tournament_id]);
    if ($teamCount->fetchColumn() >= $tournament['max_teams']) {
        $errors[] = 'Tournament is full.';
    }

    // Check time slot capacity
    if ($time_slot_id) {
        $slotCheck = $db->prepare("
            SELECT ts.max_teams, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id) as current
            FROM time_slots ts WHERE ts.id = ? AND ts.tournament_id = ?
        ");
        $slotCheck->execute([$time_slot_id, $tournament_id]);
        $slotData = $slotCheck->fetch();
        if ($slotData && $slotData['current'] >= $slotData['max_teams']) {
            $errors[] = 'Selected time slot is full.';
        }
    }

    if (empty($errors)) {
        $regCode = generateRegistrationCode();

        // Check if sms_opt_in column exists (migration-sms.sql)
        $hasSmsOptIn = false;
        try {
            $db->query("SELECT sms_opt_in FROM teams LIMIT 0");
            $hasSmsOptIn = true;
        } catch (PDOException $e) { /* SMS migration not yet applied */ }

        if ($hasSmsOptIn) {
            $stmt = $db->prepare("
                INSERT INTO teams (tournament_id, team_name, captain_name, captain_email, captain_phone, sms_opt_in, time_slot_id, status, registration_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tournament_id, $team_name, $captain_name, $captain_email, $captain_phone, $sms_opt_in, $time_slot_id, $status, $regCode]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO teams (tournament_id, team_name, captain_name, captain_email, captain_phone, time_slot_id, status, registration_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$tournament_id, $team_name, $captain_name, $captain_email, $captain_phone, $time_slot_id, $status, $regCode]);
        }

        $teamId = $db->lastInsertId();

        // Queue type: auto-assign queue position
        if ($tournament['tournament_type'] === 'queue') {
            $maxPos = $db->prepare("SELECT COALESCE(MAX(queue_position), 0) FROM teams WHERE tournament_id = ? AND status != 'withdrawn'");
            $maxPos->execute([$tournament_id]);
            $nextPos = $maxPos->fetchColumn() + 1;
            $db->prepare("UPDATE teams SET queue_position = ? WHERE id = ?")->execute([$nextPos, $teamId]);
        }

        setFlash('success', "Team \"{$team_name}\" added successfully! Registration code: {$regCode}");
        header("Location: /admin/tournament-manage.php?id={$tournament_id}");
        exit;
    }
}

$pageTitle = 'Add Team';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Add Team to <?php echo h($tournament['name']); ?></h1>
        <a href="/admin/tournament-manage.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary btn-small">Back</a>
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
            <?php echo csrfField(); ?>
            <div class="form-row">
                <div class="form-group">
                    <label for="team_name">Team Name *</label>
                    <input type="text" id="team_name" name="team_name" class="form-control"
                           value="<?php echo h($_POST['team_name'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="captain_name">Captain Name *</label>
                    <input type="text" id="captain_name" name="captain_name" class="form-control"
                           value="<?php echo h($_POST['captain_name'] ?? ''); ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="captain_email">Captain Email *</label>
                    <input type="email" id="captain_email" name="captain_email" class="form-control"
                           value="<?php echo h($_POST['captain_email'] ?? ''); ?>" required>
                </div>
                <div class="form-group">
                    <label for="captain_phone">Phone</label>
                    <input type="tel" id="captain_phone" name="captain_phone" class="form-control"
                           value="<?php echo h($_POST['captain_phone'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label style="cursor: pointer; font-size: 14px;">
                    <input type="checkbox" name="sms_opt_in" value="1" id="sms_opt_in"
                           <?php echo (!empty($_POST['sms_opt_in'])) ? 'checked' : ''; ?>>
                    Receive tournament updates via text
                </label>
                <small class="sms-phone-hint" style="display:none; color:#c0392b; margin-top:4px;">
                    Phone number required for text updates
                </small>
            </div>
            <script>
            (function() {
                var cb = document.getElementById('sms_opt_in');
                var phone = document.getElementById('captain_phone');
                var hint = document.querySelector('.sms-phone-hint');
                if (!cb || !phone || !hint) return;
                function check() {
                    hint.style.display = (cb.checked && !phone.value.trim()) ? 'block' : 'none';
                }
                cb.addEventListener('change', check);
                phone.addEventListener('input', check);
                check();
            })();
            </script>

            <?php if (!empty($timeSlots)): ?>
            <div class="form-group">
                <label for="time_slot_id">Time Slot</label>
                <select id="time_slot_id" name="time_slot_id" class="form-control">
                    <option value="">-- No Time Slot --</option>
                    <?php foreach ($timeSlots as $slot): ?>
                        <?php $isFull = $slot['team_count'] >= $slot['max_teams']; ?>
                        <option value="<?php echo $slot['id']; ?>"
                                <?php echo $isFull ? 'disabled' : ''; ?>
                                <?php echo ($_POST['time_slot_id'] ?? '') == $slot['id'] ? 'selected' : ''; ?>>
                            <?php echo h($slot['slot_label'] ?: date('g:i A', strtotime($slot['slot_time']))); ?>
                            - <?php echo date('M j, Y', strtotime($slot['slot_date'])); ?>
                            (<?php echo $slot['team_count']; ?>/<?php echo $slot['max_teams']; ?> teams)
                            <?php echo $isFull ? ' - FULL' : ''; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status" class="form-control">
                    <option value="registered">Registered</option>
                    <option value="confirmed">Confirmed</option>
                </select>
            </div>

            <p class="sms-policy-notice">Message and data rates may apply. Reply STOP to opt out. <a href="https://sherwoodadventure.com/sms-policy.html" target="_blank">Text Messaging Policy</a></p>

            <div class="flex-between mt-2">
                <a href="/admin/tournament-manage.php?id=<?php echo $tournament_id; ?>" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Add Team</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
