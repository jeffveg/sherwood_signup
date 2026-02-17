<?php
/**
 * Team Signup Page
 * Sherwood Adventure Tournament System
 *
 * Supports both simple form and account-based signup modes.
 * For Round Robin and Two-Stage tournaments, includes time slot selection.
 */
require_once __DIR__ . '/includes/auth.php';

$db = getDB();
$tournamentId = intval($_GET['tournament_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /');
    exit;
}

// Check if registration is open
if ($tournament['status'] !== 'registration_open') {
    setFlash('error', 'Registration is not currently open for this tournament.');
    header("Location: /tournament.php?id={$tournamentId}");
    exit;
}

// Check if tournament is full
$teamCount = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND status != 'withdrawn'");
$teamCount->execute([$tournamentId]);
$currentTeams = $teamCount->fetchColumn();

if ($currentTeams >= $tournament['max_teams']) {
    setFlash('error', 'This tournament is full.');
    header("Location: /tournament.php?id={$tournamentId}");
    exit;
}

// Check registration deadline
if ($tournament['registration_deadline'] && strtotime($tournament['registration_deadline']) < time()) {
    setFlash('error', 'The registration deadline has passed.');
    header("Location: /tournament.php?id={$tournamentId}");
    exit;
}

$hasTimeSlots = in_array($tournament['tournament_type'], ['round_robin', 'two_stage']);
$timeSlots = [];
if ($hasTimeSlots) {
    $slotStmt = $db->prepare("
        SELECT ts.*, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id AND status != 'withdrawn') as team_count
        FROM time_slots ts WHERE ts.tournament_id = ?
        ORDER BY ts.slot_date, ts.slot_time
    ");
    $slotStmt->execute([$tournamentId]);
    $timeSlots = $slotStmt->fetchAll();
}

$errors = [];
$success = false;
$regCode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $team_name = trim($_POST['team_name'] ?? '');
    $captain_name = trim($_POST['captain_name'] ?? '');
    $captain_email = trim($_POST['captain_email'] ?? '');
    $captain_phone = trim($_POST['captain_phone'] ?? '');
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0) ?: null;

    // Validation
    if (empty($team_name)) $errors[] = 'Team name is required.';
    if (empty($captain_name)) $errors[] = 'Captain name is required.';
    if (empty($captain_email)) $errors[] = 'Email address is required.';
    if (!empty($captain_email) && !filter_var($captain_email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    // Check duplicate team name
    if (!empty($team_name)) {
        $check = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND team_name = ? AND status != 'withdrawn'");
        $check->execute([$tournamentId, $team_name]);
        if ($check->fetchColumn() > 0) {
            $errors[] = 'A team with this name is already registered in this tournament.';
        }
    }

    // Re-check capacity
    $teamCount->execute([$tournamentId]);
    if ($teamCount->fetchColumn() >= $tournament['max_teams']) {
        $errors[] = 'Sorry, the tournament just filled up.';
    }

    // Validate time slot
    if ($hasTimeSlots && !empty($timeSlots)) {
        if (empty($time_slot_id)) {
            $errors[] = ($tournament['tournament_type'] === 'two_stage') ? 'Please select a group.' : 'Please select a time slot.';
        } else {
            $slotCheck = $db->prepare("
                SELECT ts.max_teams, (SELECT COUNT(*) FROM teams WHERE time_slot_id = ts.id AND status != 'withdrawn') as current_count
                FROM time_slots ts WHERE ts.id = ? AND ts.tournament_id = ?
            ");
            $slotCheck->execute([$time_slot_id, $tournamentId]);
            $slotData = $slotCheck->fetch();
            if (!$slotData) {
                $errors[] = 'Invalid time slot selected.';
            } elseif ($slotData['current_count'] >= $slotData['max_teams']) {
                $errors[] = 'The selected time slot is full. Please choose another.';
            }
        }
    }

    if (empty($errors)) {
        $regCode = generateRegistrationCode();

        $stmt = $db->prepare("
            INSERT INTO teams (tournament_id, team_name, captain_name, captain_email, captain_phone, time_slot_id, registration_code, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, 'registered')
        ");
        $stmt->execute([$tournamentId, $team_name, $captain_name, $captain_email, $captain_phone, $time_slot_id, $regCode]);

        $success = true;
    }
}

$pageTitle = 'Sign Up - ' . $tournament['name'];
include __DIR__ . '/includes/header.php';
?>

<!-- Hero -->
<div class="page-hero">
    <div class="container">
        <h1 class="bounce-in">Team Sign Up</h1>
        <p class="subtitle">
            <?php echo h($tournament['name']); ?> &middot; Tournament #<?php echo h($tournament['tournament_number']); ?>
        </p>
    </div>
</div>

<div class="signup-wrapper">
    <?php if ($success): ?>
        <!-- Success State -->
        <div class="signup-card signup-success fade-in">
            <h2 style="color: var(--color-gold);">You're In!</h2>
            <p>Your team has been registered successfully.</p>

            <div>
                <p style="font-size: 14px; color: var(--color-orange); margin-bottom: 5px;">Your Registration Code:</p>
                <div class="reg-code"><?php echo h($regCode); ?></div>
            </div>

            <p style="font-size: 14px; opacity: 0.7; margin-top: 20px;">
                Save this code! You may need it for check-in or reference.
            </p>

            <div class="mt-3">
                <a href="/tournament.php?id=<?php echo $tournamentId; ?>" class="btn btn-primary">View Tournament</a>
                <a href="/" class="btn btn-secondary" style="margin-left: 10px;">All Tournaments</a>
            </div>
        </div>
    <?php else: ?>
        <!-- Signup Form -->
        <div class="signup-card fade-in">
            <?php if (!empty($errors)): ?>
                <div class="flash-message flash-error" style="border-radius: var(--border-radius); padding: 14px; margin-bottom: 20px;">
                    <ul style="list-style: none;">
                        <?php foreach ($errors as $err): ?>
                            <li>&#9888; <?php echo h($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid var(--color-brown-border);">
                <h2 style="margin-bottom: 8px;">Register Your Team</h2>
                <p style="font-size: 14px; opacity: 0.7; margin-bottom: 0;">
                    <?php echo h($tournament['name']); ?>
                    <span class="badge badge-type" style="margin-left: 6px;">
                        <?php echo ucwords(str_replace('_', ' ', $tournament['tournament_type'])); ?>
                    </span>
                </p>
                <p style="font-size: 13px; opacity: 0.5; margin-bottom: 0;">
                    <?php echo $currentTeams; ?> / <?php echo $tournament['max_teams']; ?> teams registered
                    <?php if ($tournament['registration_deadline']): ?>
                        &middot; Deadline: <?php echo date('M j, Y g:i A', strtotime($tournament['registration_deadline'])); ?>
                    <?php endif; ?>
                </p>
            </div>

            <form method="POST" action="" id="signup-form">
                <div class="form-group">
                    <label for="team_name">Team Name *</label>
                    <input type="text" id="team_name" name="team_name" class="form-control"
                           value="<?php echo h($_POST['team_name'] ?? ''); ?>" required
                           placeholder="Enter your team name" maxlength="255">
                </div>

                <div class="form-group">
                    <label for="captain_name">Captain Name *</label>
                    <input type="text" id="captain_name" name="captain_name" class="form-control"
                           value="<?php echo h($_POST['captain_name'] ?? ''); ?>" required
                           placeholder="Team captain's full name" maxlength="255">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="captain_email">Email *</label>
                        <input type="email" id="captain_email" name="captain_email" class="form-control"
                               value="<?php echo h($_POST['captain_email'] ?? ''); ?>" required
                               placeholder="captain@email.com">
                    </div>
                    <div class="form-group">
                        <label for="captain_phone">Phone</label>
                        <input type="tel" id="captain_phone" name="captain_phone" class="form-control"
                               value="<?php echo h($_POST['captain_phone'] ?? ''); ?>"
                               placeholder="(555) 123-4567">
                    </div>
                </div>

                <!-- Time Slot / Group Selection -->
                <?php if ($hasTimeSlots && !empty($timeSlots)): ?>
                <?php $isTwoStage = ($tournament['tournament_type'] === 'two_stage'); ?>
                <div class="form-group">
                    <label><?php echo $isTwoStage ? 'Select a Group *' : 'Select a Time Slot *'; ?></label>
                    <p class="form-hint" style="margin-bottom: 12px;">
                        <?php echo $isTwoStage
                            ? 'Choose which group your team will play in. Each group has limited capacity.'
                            : 'Choose when your team will play. Each slot has limited capacity.'; ?>
                    </p>

                    <input type="hidden" id="time_slot_id" name="time_slot_id"
                           value="<?php echo h($_POST['time_slot_id'] ?? ''); ?>">

                    <div class="time-slots-grid">
                        <?php foreach ($timeSlots as $slot): ?>
                            <?php $isFull = $slot['team_count'] >= $slot['max_teams']; ?>
                            <div class="time-slot-card <?php echo $isFull ? 'slot-full' : ''; ?> <?php echo ($_POST['time_slot_id'] ?? '') == $slot['id'] ? 'slot-selected' : ''; ?>"
                                 <?php if (!$isFull): ?>
                                 onclick="selectTimeSlot(this, <?php echo $slot['id']; ?>)"
                                 <?php endif; ?>
                                 data-slot-id="<?php echo $slot['id']; ?>">
                                <div class="time-slot-time"><?php echo date('g:i A', strtotime($slot['slot_time'])); ?></div>
                                <div class="time-slot-date"><?php echo date('l, M j, Y', strtotime($slot['slot_date'])); ?></div>
                                <?php if ($slot['slot_label']): ?>
                                    <div style="font-size: 13px; color: var(--color-light-gray); margin-bottom: 6px;">
                                        <?php echo h($slot['slot_label']); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="time-slot-capacity">
                                    <?php if ($isFull): ?>
                                        <span class="spots-full">Full</span>
                                    <?php else: ?>
                                        <span class="spots-left"><?php echo $slot['max_teams'] - $slot['team_count']; ?> spots left</span>
                                    <?php endif; ?>
                                    (<?php echo $slot['team_count']; ?>/<?php echo $slot['max_teams']; ?> teams)
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="mt-2">
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">
                        Register Team
                    </button>
                </div>

                <p class="text-center mt-2" style="font-size: 13px; opacity: 0.5;">
                    By registering, your team agrees to follow the tournament rules.
                </p>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
function selectTimeSlot(el, slotId) {
    // Remove selection from all
    document.querySelectorAll('.time-slot-card').forEach(function(card) {
        card.classList.remove('slot-selected');
    });
    // Select this one
    el.classList.add('slot-selected');
    document.getElementById('time_slot_id').value = slotId;
}

// Form validation
var isTwoStage = <?php echo json_encode($tournament['tournament_type'] === 'two_stage'); ?>;
document.getElementById('signup-form')?.addEventListener('submit', function(e) {
    var slotInput = document.getElementById('time_slot_id');
    if (slotInput && slotInput.value === '' && document.querySelectorAll('.time-slot-card:not(.slot-full)').length > 0) {
        e.preventDefault();
        alert(isTwoStage ? 'Please select a group for your team.' : 'Please select a time slot for your team.');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
