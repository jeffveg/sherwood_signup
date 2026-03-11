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
    $league_encounters = intval($_POST['league_encounters'] ?? 1);
    if ($league_encounters < 1) $league_encounters = 1;
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
    $sms_enabled = isset($_POST['sms_enabled']) ? 1 : 0;
    $status = $_POST['status'] ?? 'draft';

    // Validation
    if (empty($name)) $errors[] = 'Tournament name is required.';
    if (empty($tournament_number)) $errors[] = 'Tournament number is required.';
    if (!in_array($tournament_type, ['single_elimination', 'double_elimination', 'round_robin', 'two_stage', 'league', 'queue'])) {
        $errors[] = 'Invalid tournament type.';
    }
    if ($tournament_type === 'two_stage' && !in_array($two_stage_elimination_type, ['single_elimination', 'double_elimination'])) {
        $errors[] = 'Two-stage tournaments require an elimination type for stage 2.';
    }
    // Queue has no team limits — registration is controlled by deadline only
    if ($tournament_type !== 'queue' && $max_teams < 2) $errors[] = 'Maximum teams must be at least 2.';

    // Check unique tournament number
    if (!empty($tournament_number)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tournaments WHERE tournament_number = ?");
        $stmt->execute([$tournament_number]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Tournament number already exists.';
        }
    }

    if (empty($errors)) {
        // Migration safety: check if league_encounters column exists.
        // This column is added by Feature 5 in migration-features.sql.
        // If not yet applied, fall back to INSERT without it (defaults to 1 in schema).
        $hasEncountersCol = false;
        try {
            $colCheck = $db->query("SELECT league_encounters FROM tournaments LIMIT 0");
            $hasEncountersCol = true;
        } catch (PDOException $e) {
            // Column doesn't exist — migration Feature 5 not yet applied
        }

        // SMS column added by migration-sms.sql
        $hasSmsCol = false;
        try {
            $db->query("SELECT sms_enabled FROM tournaments LIMIT 0");
            $hasSmsCol = true;
        } catch (PDOException $e) { /* SMS migration not yet applied */ }

        // Build column list and params dynamically based on available migrations
        $cols = 'tournament_number, name, description, tournament_type, two_stage_elimination_type,
                 two_stage_advance_count';
        $placeholders = '?, ?, ?, ?, ?, ?';
        $params = [
            $tournament_number, $name, $description, $tournament_type,
            $tournament_type === 'two_stage' ? $two_stage_elimination_type : null,
            $two_stage_advance_count
        ];

        if ($hasEncountersCol) {
            $cols .= ', league_encounters';
            $placeholders .= ', ?';
            $params[] = $league_encounters;
        }

        $cols .= ', status, signup_mode, bracket_display';
        $placeholders .= ', ?, ?, ?';
        $params[] = $status;
        $params[] = $signup_mode;
        $params[] = $bracket_display;

        if ($hasSmsCol) {
            $cols .= ', sms_enabled';
            $placeholders .= ', ?';
            $params[] = $sms_enabled;
        }

        $cols .= ', max_teams, min_teams, start_date, end_date, registration_deadline, location, rules, created_by';
        $placeholders .= ', ?, ?, ?, ?, ?, ?, ?, ?';
        $params[] = $max_teams;
        $params[] = $min_teams;
        $params[] = $start_date ?: null;
        $params[] = $end_date ?: null;
        $params[] = $registration_deadline ? $registration_deadline . ':00' : null;
        $params[] = $location;
        $params[] = $rules;
        $params[] = $_SESSION['admin_id'];

        $stmt = $db->prepare("INSERT INTO tournaments ({$cols}) VALUES ({$placeholders})");
        $stmt->execute($params);

        $tournamentId = $db->lastInsertId();

        // Create time slots (groups) for tournament types that support them.
        // Time slots define when/where teams play and serve as "groups" for two-stage/league.
        // Slots with empty date or time are silently skipped (user may have added then cleared a row).
        if (in_array($tournament_type, ['round_robin', 'two_stage', 'league']) && !empty($_POST['slot_dates'])) {
            $slotDates = $_POST['slot_dates'];
            $slotTimes = $_POST['slot_times'] ?? [];
            $slotLabels = $_POST['slot_labels'] ?? [];
            $slotMaxTeams = $_POST['slot_max_teams'] ?? [];

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
                        max(1, intval($slotMaxTeams[$i] ?? 3))
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
                    <option value="queue" <?php echo ($_POST['tournament_type'] ?? '') === 'queue' ? 'selected' : ''; ?>>
                        Queue (Walk-Up)
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

            <!-- League Encounters (hidden by default) -->
            <div id="league-encounters-option" class="hidden">
                <div class="form-row">
                    <div class="form-group">
                        <label for="league_encounters">Encounters (Meetings)</label>
                        <input type="number" id="league_encounters" name="league_encounters"
                               class="form-control" value="<?php echo h($_POST['league_encounters'] ?? '1'); ?>" min="1" max="10">
                        <span class="form-hint">How many times each team plays every other team (e.g., 2 = home &amp; away)</span>
                    </div>
                </div>
            </div>

            <div class="form-row" id="team-limits-row">
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

            <!-- Auto-Generate Time Slots -->
            <div class="auto-generate-panel">
                <button type="button" class="btn btn-secondary btn-small" id="toggle-auto-generate"
                        onclick="toggleAutoGenerate()">
                    &#9881; Auto-Generate Slots
                </button>

                <div id="auto-generate-fields" class="hidden" style="margin-top: 16px;">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gen_start_date">Start Date</label>
                            <input type="date" id="gen_start_date" class="form-control">
                            <span class="form-hint">Defaults to tournament start date</span>
                        </div>
                        <div class="form-group">
                            <label for="gen_time">Time of Day</label>
                            <input type="time" id="gen_time" class="form-control" value="10:00">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gen_frequency">Frequency</label>
                            <select id="gen_frequency" class="form-control">
                                <option value="7">Weekly</option>
                                <option value="14">Biweekly (Every 2 Weeks)</option>
                                <option value="21">Every 3 Weeks</option>
                                <option value="28">Monthly (Every 4 Weeks)</option>
                                <option value="1">Daily</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="gen_count">Number of Slots</label>
                            <input type="number" id="gen_count" class="form-control" value="8" min="1" max="52">
                            <span class="form-hint">How many time slots to create</span>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="gen_max_teams">Max Teams Per Slot</label>
                            <input type="number" id="gen_max_teams" class="form-control" value="3" min="1" max="50">
                        </div>
                        <div class="form-group">
                            <label for="gen_label_prefix">Label Style</label>
                            <select id="gen_label_prefix" class="form-control">
                                <option value="week">Week 1, Week 2...</option>
                                <option value="round">Round 1, Round 2...</option>
                                <option value="session">Session 1, Session 2...</option>
                                <option value="group">Group A, Group B...</option>
                                <option value="date">Use date as label</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-top: 12px; display: flex; gap: 10px;">
                        <button type="button" class="btn btn-primary btn-small" onclick="generateTimeSlots()">
                            Generate Slots
                        </button>
                        <button type="button" class="btn btn-secondary btn-small" onclick="toggleAutoGenerate()">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>

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

            <div class="form-row">
                <div class="form-group">
                    <label style="cursor: pointer;">
                        <input type="checkbox" name="sms_enabled" value="1"
                               <?php echo ($_POST['sms_enabled'] ?? '') ? 'checked' : ''; ?>>
                        Enable SMS Notifications
                    </label>
                    <span class="form-hint">Opted-in captains receive text alerts during games via QUO ($0.01/text)</span>
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

    // Show encounters option for league and round_robin (not queue)
    const encountersOpt = document.getElementById('league-encounters-option');
    encountersOpt.classList.toggle('hidden', type !== 'league' && type !== 'round_robin');

    // Show bracket display option for elimination types (not queue)
    const bracketDisplayOpt = document.getElementById('bracket-display-option');
    const hasElimination = (type === 'single_elimination' || type === 'double_elimination' || type === 'two_stage');
    bracketDisplayOpt.classList.toggle('hidden', !hasElimination);

    // Queue type: no time slots, no bracket — SMS is auto-enabled
    const needsSlots = (type === 'round_robin' || type === 'two_stage' || type === 'league');
    timeSlotsSection.classList.toggle('hidden', !needsSlots);

    // Queue type: hide min/max teams (registration controlled by deadline only)
    var teamLimitsRow = document.getElementById('team-limits-row');
    if (teamLimitsRow) teamLimitsRow.classList.toggle('hidden', type === 'queue');

    // Auto-check SMS enabled when queue is selected (SMS is the core feature of queue)
    var smsCheckbox = document.querySelector('input[name="sms_enabled"]');
    if (smsCheckbox && type === 'queue' && !smsCheckbox.checked) {
        smsCheckbox.checked = true;
    }

    // Update labels based on type (groups vs time slots)
    var isTwoStage = (type === 'two_stage');
    var sectionTitle = document.getElementById('slots-section-title');
    var sectionHint = document.getElementById('slots-section-hint');
    var addBtn = document.querySelector('.add-slot-btn');
    if (sectionTitle) sectionTitle.textContent = isTwoStage ? 'Groups' : 'Time Slots';
    if (sectionHint) sectionHint.textContent = isTwoStage
        ? 'Define groups for the group stage. Teams will sign up for a specific group.'
        : 'Define the available time slots below. Teams will select a slot when signing up.';
    if (addBtn) addBtn.textContent = isTwoStage ? '+ Add Group' : '+ Add Time Slot';

    // Auto-set label prefix for auto-generate panel
    var prefixSelect = document.getElementById('gen_label_prefix');
    if (prefixSelect) {
        if (type === 'league') prefixSelect.value = 'week';
        else if (type === 'round_robin') prefixSelect.value = 'round';
        else if (type === 'two_stage') prefixSelect.value = 'group';
    }
    // Update auto-generate button text
    var autoBtn = document.getElementById('toggle-auto-generate');
    if (autoBtn) autoBtn.textContent = isTwoStage ? '\u2699 Auto-Generate Groups' : '\u2699 Auto-Generate Slots';

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

// ============================================================
// AUTO-GENERATE TIME SLOTS
// Provides a quick way to create multiple time slots at once
// based on a start date and frequency (weekly, biweekly, etc.).
// Generated rows use the same form fields as manually-added rows,
// so no backend changes are needed — the PHP POST handler processes
// them identically.
// ============================================================

/**
 * Toggle the auto-generate fields panel open/closed.
 * When opening, pre-fills start date from the tournament start_date field
 * and auto-selects the label prefix based on tournament type.
 */
function toggleAutoGenerate() {
    var fields = document.getElementById('auto-generate-fields');
    var isHidden = fields.classList.contains('hidden');
    fields.classList.toggle('hidden');

    if (isHidden) {
        // Pre-fill start date from tournament start_date field
        var startDate = document.getElementById('start_date');
        var genStartDate = document.getElementById('gen_start_date');
        if (startDate && startDate.value && !genStartDate.value) {
            genStartDate.value = startDate.value;
        }

        // Auto-select label prefix based on tournament type
        var tournamentType = document.getElementById('tournament_type').value;
        var prefixSelect = document.getElementById('gen_label_prefix');
        if (tournamentType === 'league') prefixSelect.value = 'week';
        else if (tournamentType === 'round_robin') prefixSelect.value = 'round';
        else if (tournamentType === 'two_stage') prefixSelect.value = 'group';
    }
}

/**
 * Validate inputs and generate time slot rows.
 * If rows already exist, shows a confirmation modal before replacing.
 */
function generateTimeSlots() {
    var startDate = document.getElementById('gen_start_date').value;
    var time = document.getElementById('gen_time').value;
    var frequency = parseInt(document.getElementById('gen_frequency').value, 10);
    var count = parseInt(document.getElementById('gen_count').value, 10);
    var maxTeams = parseInt(document.getElementById('gen_max_teams').value, 10);
    var labelPrefix = document.getElementById('gen_label_prefix').value;

    if (!startDate) {
        alert('Please select a start date.');
        document.getElementById('gen_start_date').focus();
        return;
    }
    if (!time) {
        alert('Please select a time.');
        document.getElementById('gen_time').focus();
        return;
    }
    if (count < 1 || count > 52) {
        alert('Number of slots must be between 1 and 52.');
        return;
    }

    var container = document.getElementById('time-slots-container');
    var existingRows = container.querySelectorAll('.slot-row');

    if (existingRows.length > 0) {
        showConfirmModal(
            'Replace Existing Slots?',
            'There are ' + existingRows.length + ' existing time slot(s). Do you want to replace them with the generated slots?',
            function() {
                existingRows.forEach(function(row) { row.remove(); });
                doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix);
            }
        );
    } else {
        doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix);
    }
}

/**
 * Create slot rows with calculated dates and labels.
 * Each row matches the exact DOM structure of addTimeSlot() so the
 * existing PHP POST handler processes them identically.
 * On the edit page, includes hidden slot_ids[] with empty value (= new slot).
 */
function doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix) {
    var currentDate = new Date(startDate + 'T00:00:00');
    var container = document.getElementById('time-slots-container');
    var isEditPage = window.location.href.indexOf('tournament-edit') !== -1;

    for (var i = 0; i < count; i++) {
        var year = currentDate.getFullYear();
        var month = String(currentDate.getMonth() + 1).padStart(2, '0');
        var day = String(currentDate.getDate()).padStart(2, '0');
        var dateStr = year + '-' + month + '-' + day;

        // Build label
        var label = '';
        if (labelPrefix === 'week') label = 'Week ' + (i + 1);
        else if (labelPrefix === 'round') label = 'Round ' + (i + 1);
        else if (labelPrefix === 'session') label = 'Session ' + (i + 1);
        else if (labelPrefix === 'group') label = 'Group ' + numberToLetters(i);
        else if (labelPrefix === 'date') {
            label = currentDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric' });
        }

        var row = document.createElement('div');
        row.className = 'slot-row';
        var hiddenIdHtml = isEditPage ? '<input type="hidden" name="slot_ids[]" value="">' : '';
        row.innerHTML = hiddenIdHtml +
            '<div class="form-group">' +
                '<label>Date</label>' +
                '<input type="date" name="slot_dates[]" class="form-control" value="' + dateStr + '" required>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Time</label>' +
                '<input type="time" name="slot_times[]" class="form-control" value="' + time + '" required>' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Label</label>' +
                '<input type="text" name="slot_labels[]" class="form-control" value="' + label + '">' +
            '</div>' +
            '<div class="form-group">' +
                '<label>Max Teams</label>' +
                '<input type="number" name="slot_max_teams[]" class="form-control" value="' + maxTeams + '" min="1" max="50">' +
            '</div>' +
            '<div class="form-group">' +
                '<label>&nbsp;</label>' +
                '<button type="button" class="btn btn-danger btn-small" onclick="this.closest(\'.slot-row\').remove()">Remove</button>' +
            '</div>';

        container.appendChild(row);
        currentDate.setDate(currentDate.getDate() + frequency);
    }

    // Collapse the auto-generate panel
    document.getElementById('auto-generate-fields').classList.add('hidden');

    // Auto-fill end_date if empty
    var endDateField = document.getElementById('end_date');
    if (endDateField && !endDateField.value) {
        var lastDate = new Date(currentDate);
        lastDate.setDate(lastDate.getDate() - frequency);
        var ey = lastDate.getFullYear();
        var em = String(lastDate.getMonth() + 1).padStart(2, '0');
        var ed = String(lastDate.getDate()).padStart(2, '0');
        endDateField.value = ey + '-' + em + '-' + ed;
    }
}

/**
 * Convert a 0-based index to letter label: 0=A, 1=B, ..., 25=Z, 26=AA, etc.
 * Used for "Group A", "Group B" style labels.
 */
function numberToLetters(n) {
    var result = '';
    n = n + 1;
    while (n > 0) {
        n--;
        result = String.fromCharCode(65 + (n % 26)) + result;
        n = Math.floor(n / 26);
    }
    return result;
}

// Trigger change on page load to show correct sections
document.getElementById('tournament_type').dispatchEvent(new Event('change'));
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
