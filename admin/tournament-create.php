<?php
/**
 * Create Tournament
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Collect form data
    $name = trim($_POST['name'] ?? '');
    $tournament_number = trim($_POST['tournament_number'] ?? '');
    $tournament_type = $_POST['tournament_type'] ?? '';
    $two_stage_elimination_type = $_POST['two_stage_elimination_type'] ?? null;
    $two_stage_advance_count = intval($_POST['two_stage_advance_count'] ?? 4);
    $description = trim($_POST['description'] ?? '');
    $max_teams = intval($_POST['max_teams'] ?? 16);
    $min_teams = intval($_POST['min_teams'] ?? 2);
    $start_date = $_POST['start_date'] ?? null;
    $end_date = $_POST['end_date'] ?? null;
    $registration_deadline = $_POST['registration_deadline'] ?? null;
    $location = trim($_POST['location'] ?? '');
    $rules = trim($_POST['rules'] ?? '');
    $signup_mode = $_POST['signup_mode'] ?? 'simple_form';
    $bracket_display = $_POST['bracket_display'] ?? 'full';
    $status = $_POST['status'] ?? 'draft';

    // Validation
    if (empty($name)) $errors[] = 'Tournament name is required.';
    if (empty($tournament_number)) $errors[] = 'Tournament number is required.';
    if (!in_array($tournament_type, ['single_elimination', 'double_elimination', 'round_robin', 'two_stage', 'league'])) {
        $errors[] = 'Invalid tournament type.';
    }
    if ($tournament_type === 'two_stage' && !in_array($two_stage_elimination_type, ['single_elimination', 'double_elimination'])) {
        $errors[] = 'Two-stage tournaments require an elimination type for stage 2.';
    }
    if ($max_teams < 2) $errors[] = 'Maximum teams must be at least 2.';

    // Check unique tournament number
    if (!empty($tournament_number)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tournaments WHERE tournament_number = ?");
        $stmt->execute([$tournament_number]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Tournament number already exists.';
        }
    }

    if (empty($errors)) {
        $stmt = $db->prepare("
            INSERT INTO tournaments
            (tournament_number, name, description, tournament_type, two_stage_elimination_type,
             two_stage_advance_count, status, signup_mode, bracket_display, max_teams, min_teams,
             start_date, end_date, registration_deadline, location, rules, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $tournament_number, $name, $description, $tournament_type,
            $tournament_type === 'two_stage' ? $two_stage_elimination_type : null,
            $two_stage_advance_count, $status, $signup_mode, $bracket_display, $max_teams, $min_teams,
            $start_date ?: null, $end_date ?: null,
            $registration_deadline ? $registration_deadline . ':00' : null,
            $location, $rules, $_SESSION['admin_id']
        ]);

        $tournamentId = $db->lastInsertId();

        // Create time slots if provided (for round_robin and two_stage)
        if (in_array($tournament_type, ['round_robin', 'two_stage']) && !empty($_POST['slot_dates'])) {
            $slotDates = $_POST['slot_dates'];
            $slotTimes = $_POST['slot_times'];
            $slotLabels = $_POST['slot_labels'];
            $slotMaxTeams = $_POST['slot_max_teams'];

            $slotStmt = $db->prepare("
                INSERT INTO time_slots (tournament_id, slot_date, slot_time, slot_label, max_teams)
                VALUES (?, ?, ?, ?, ?)
            ");

            for ($i = 0; $i < count($slotDates); $i++) {
                if (!empty($slotDates[$i]) && !empty($slotTimes[$i])) {
                    $slotStmt->execute([
                        $tournamentId,
                        $slotDates[$i],
                        $slotTimes[$i],
                        $slotLabels[$i] ?? '',
                        intval($slotMaxTeams[$i] ?? 3)
                    ]);
                }
            }
        }

        setFlash('success', "Tournament \"{$name}\" created successfully!");
        header("Location: /admin/tournament-manage.php?id=" . $tournamentId);
        exit;
    }
}

// Generate a default tournament number
$defaultNumber = generateTournamentNumber();

$pageTitle = 'Create Tournament';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Create Tournament</h1>
        <a href="/admin/dashboard.php" class="btn btn-secondary btn-small">Back to Dashboard</a>
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

    <form method="POST" action="" id="tournament-form">
        <!-- Basic Info -->
        <div class="form-section">
            <h3 class="form-section-title">Basic Information</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="name">Tournament Name *</label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?php echo h($_POST['name'] ?? ''); ?>" required
                           placeholder="e.g., Spring Championship 2025">
                </div>
                <div class="form-group">
                    <label for="tournament_number">Tournament Number *</label>
                    <input type="text" id="tournament_number" name="tournament_number" class="form-control"
                           value="<?php echo h($_POST['tournament_number'] ?? $defaultNumber); ?>" required
                           placeholder="e.g., SA-2025-0001">
                    <span class="form-hint">Used for scoring system integration</span>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control"
                          placeholder="Tournament description, details, prizes..."><?php echo h($_POST['description'] ?? ''); ?></textarea>
            </div>

            <div class="form-group">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" class="form-control"
                       value="<?php echo h($_POST['location'] ?? 'Sherwood Adventure'); ?>"
                       placeholder="e.g., Sherwood Adventure Main Field">
            </div>
        </div>

        <!-- Tournament Type -->
        <div class="form-section">
            <h3 class="form-section-title">Tournament Format</h3>

            <div class="form-group">
                <label for="tournament_type">Tournament Type *</label>
                <select id="tournament_type" name="tournament_type" class="form-control" required>
                    <option value="">-- Select Type --</option>
                    <option value="single_elimination" <?php echo ($_POST['tournament_type'] ?? '') === 'single_elimination' ? 'selected' : ''; ?>>
                        Single Elimination
                    </option>
                    <option value="double_elimination" <?php echo ($_POST['tournament_type'] ?? '') === 'double_elimination' ? 'selected' : ''; ?>>
                        Double Elimination
                    </option>
                    <option value="round_robin" <?php echo ($_POST['tournament_type'] ?? '') === 'round_robin' ? 'selected' : ''; ?>>
                        Round Robin
                    </option>
                    <option value="two_stage" <?php echo ($_POST['tournament_type'] ?? '') === 'two_stage' ? 'selected' : ''; ?>>
                        Two Stage (Round Robin + Elimination)
                    </option>
                    <option value="league" <?php echo ($_POST['tournament_type'] ?? '') === 'league' ? 'selected' : ''; ?>>
                        League (Multi-Day/Week)
                    </option>
                </select>
            </div>

            <!-- Two-Stage Options (hidden by default) -->
            <div id="two-stage-options" class="hidden">
                <div class="form-row">
                    <div class="form-group">
                        <label for="two_stage_elimination_type">Stage 2 Elimination Type</label>
                        <select id="two_stage_elimination_type" name="two_stage_elimination_type" class="form-control">
                            <option value="single_elimination">Single Elimination</option>
                            <option value="double_elimination">Double Elimination</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="two_stage_advance_count">Teams Advancing Per Group</label>
                        <input type="number" id="two_stage_advance_count" name="two_stage_advance_count"
                               class="form-control" value="<?php echo h($_POST['two_stage_advance_count'] ?? '1'); ?>" min="1" max="32">
                        <span class="form-hint">Number of top teams from each group that advance to elimination</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="max_teams">Max Teams</label>
                    <input type="number" id="max_teams" name="max_teams" class="form-control"
                           value="<?php echo h($_POST['max_teams'] ?? '16'); ?>" min="2" max="128">
                </div>
                <div class="form-group">
                    <label for="min_teams">Min Teams</label>
                    <input type="number" id="min_teams" name="min_teams" class="form-control"
                           value="<?php echo h($_POST['min_teams'] ?? '2'); ?>" min="2" max="128">
                </div>
            </div>
        </div>

        <!-- Schedule -->
        <div class="form-section">
            <h3 class="form-section-title">Schedule</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control"
                           value="<?php echo h($_POST['start_date'] ?? ''); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control"
                           value="<?php echo h($_POST['end_date'] ?? ''); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="registration_deadline">Registration Deadline</label>
                <input type="datetime-local" id="registration_deadline" name="registration_deadline" class="form-control"
                       value="<?php echo h($_POST['registration_deadline'] ?? ''); ?>">
            </div>
        </div>

        <!-- Time Slots / Groups (for Round Robin / Two-Stage) -->
        <div id="time-slots-section" class="form-section hidden">
            <h3 class="form-section-title" id="slots-section-title">Time Slots</h3>
            <p id="slots-section-hint" style="margin-bottom: 20px; opacity: 0.7; font-size: 14px;">
                Teams will sign up for specific time slots. Define the available slots below.
            </p>

            <div id="time-slots-container">
                <!-- Slot rows added via JS -->
            </div>

            <button type="button" class="btn btn-secondary btn-small add-slot-btn" onclick="addTimeSlot()">
                + Add Time Slot
            </button>
        </div>

        <!-- Settings -->
        <div class="form-section">
            <h3 class="form-section-title">Settings</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="signup_mode">Signup Mode</label>
                    <select id="signup_mode" name="signup_mode" class="form-control">
                        <option value="simple_form" <?php echo ($_POST['signup_mode'] ?? 'simple_form') === 'simple_form' ? 'selected' : ''; ?>>
                            Simple Form (no account required)
                        </option>
                        <option value="account_based" <?php echo ($_POST['signup_mode'] ?? '') === 'account_based' ? 'selected' : ''; ?>>
                            Account-Based (captains create accounts)
                        </option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Initial Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="draft" <?php echo ($_POST['status'] ?? 'draft') === 'draft' ? 'selected' : ''; ?>>
                            Draft (not visible)
                        </option>
                        <option value="registration_open" <?php echo ($_POST['status'] ?? '') === 'registration_open' ? 'selected' : ''; ?>>
                            Registration Open
                        </option>
                    </select>
                </div>
            </div>

            <div id="bracket-display-option" class="form-row hidden">
                <div class="form-group">
                    <label for="bracket_display">Bracket Display</label>
                    <select id="bracket_display" name="bracket_display" class="form-control">
                        <option value="full" <?php echo ($_POST['bracket_display'] ?? 'full') === 'full' ? 'selected' : ''; ?>>Full (show all rounds including byes)</option>
                        <option value="compact" <?php echo ($_POST['bracket_display'] ?? '') === 'compact' ? 'selected' : ''; ?>>Compact (hide bye rounds, fits on one page)</option>
                    </select>
                    <span class="form-hint">Compact hides first-round byes so seeded teams start in the next round</span>
                </div>
            </div>

            <div class="form-group">
                <label for="rules">Rules / Additional Info</label>
                <textarea id="rules" name="rules" class="form-control"
                          placeholder="Tournament rules, special instructions..."><?php echo h($_POST['rules'] ?? ''); ?></textarea>
            </div>
        </div>

        <!-- Submit -->
        <div class="flex-between">
            <a href="/admin/dashboard.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary btn-large">Create Tournament</button>
        </div>
    </form>
</div>

<script>
// Show/hide two-stage options and time slots based on tournament type
document.getElementById('tournament_type').addEventListener('change', function() {
    const type = this.value;
    const twoStageOpts = document.getElementById('two-stage-options');
    const timeSlotsSection = document.getElementById('time-slots-section');

    twoStageOpts.classList.toggle('hidden', type !== 'two_stage');

    // Show bracket display option for elimination types
    const bracketDisplayOpt = document.getElementById('bracket-display-option');
    const hasElimination = (type === 'single_elimination' || type === 'double_elimination' || type === 'two_stage');
    bracketDisplayOpt.classList.toggle('hidden', !hasElimination);

    const needsSlots = (type === 'round_robin' || type === 'two_stage');
    timeSlotsSection.classList.toggle('hidden', !needsSlots);
    // League doesn't use time slots or two-stage options

    // Update labels based on type (groups vs time slots)
    var isTwoStage = (type === 'two_stage');
    var sectionTitle = document.getElementById('slots-section-title');
    var sectionHint = document.getElementById('slots-section-hint');
    var addBtn = document.querySelector('.add-slot-btn');
    if (sectionTitle) sectionTitle.textContent = isTwoStage ? 'Groups' : 'Time Slots';
    if (sectionHint) sectionHint.textContent = isTwoStage
        ? 'Define groups for the group stage. Teams will sign up for a specific group.'
        : 'Teams will sign up for specific time slots. Define the available slots below.';
    if (addBtn) addBtn.textContent = isTwoStage ? '+ Add Group' : '+ Add Time Slot';

    // Add a default slot if none exist
    if (needsSlots && document.querySelectorAll('.slot-row').length === 0) {
        addTimeSlot();
    }
});

let slotIndex = 0;

function addTimeSlot() {
    const container = document.getElementById('time-slots-container');
    const row = document.createElement('div');
    row.className = 'slot-row';
    row.innerHTML = `
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="slot_dates[]" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Time</label>
            <input type="time" name="slot_times[]" class="form-control" required>
        </div>
        <div class="form-group">
            <label>Label</label>
            <input type="text" name="slot_labels[]" class="form-control" placeholder="e.g., Morning Session">
        </div>
        <div class="form-group">
            <label>Max Teams</label>
            <input type="number" name="slot_max_teams[]" class="form-control" value="3" min="1" max="50">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="button" class="btn btn-danger btn-small" onclick="this.closest('.slot-row').remove()">Remove</button>
        </div>
    `;
    container.appendChild(row);
    slotIndex++;
}

// Trigger change on page load to show correct sections
document.getElementById('tournament_type').dispatchEvent(new Event('change'));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
