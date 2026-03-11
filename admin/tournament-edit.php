<?php
/**
 * Edit Tournament
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$id = intval($_GET['id'] ?? 0);

if (!$id) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

// Fetch tournament
$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$id]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

// Fetch existing time slots
$slotStmt = $db->prepare("SELECT * FROM time_slots WHERE tournament_id = ? ORDER BY slot_date, slot_time");
$slotStmt->execute([$id]);
$timeSlots = $slotStmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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

    // Check unique tournament number (excluding self)
    if (!empty($tournament_number)) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM tournaments WHERE tournament_number = ? AND id != ?");
        $stmt->execute([$tournament_number, $id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = 'Tournament number already in use by another tournament.';
        }
    }

    if (empty($errors)) {
        // Check if league_encounters column exists (requires migration Feature 5)
        $hasEncountersCol = false;
        try {
            $colCheck = $db->query("SELECT league_encounters FROM tournaments LIMIT 0");
            $hasEncountersCol = true;
        } catch (PDOException $e) {
            // Column doesn't exist yet — skip it
        }

        // SMS column added by migration-sms.sql
        $hasSmsCol = false;
        try {
            $db->query("SELECT sms_enabled FROM tournaments LIMIT 0");
            $hasSmsCol = true;
        } catch (PDOException $e) { /* SMS migration not yet applied */ }

        // Build SET clause and params dynamically based on available migrations
        $setClauses = 'tournament_number = ?, name = ?, description = ?, tournament_type = ?,
                    two_stage_elimination_type = ?, two_stage_advance_count = ?';
        $params = [
            $tournament_number, $name, $description, $tournament_type,
            $tournament_type === 'two_stage' ? $two_stage_elimination_type : null,
            $two_stage_advance_count
        ];

        if ($hasEncountersCol) {
            $setClauses .= ', league_encounters = ?';
            $params[] = $league_encounters;
        }

        $setClauses .= ', status = ?, signup_mode = ?, bracket_display = ?';
        $params[] = $status;
        $params[] = $signup_mode;
        $params[] = $bracket_display;

        if ($hasSmsCol) {
            $setClauses .= ', sms_enabled = ?';
            $params[] = $sms_enabled;
        }

        $setClauses .= ', max_teams = ?, min_teams = ?, start_date = ?, end_date = ?,
                    registration_deadline = ?, location = ?, rules = ?';
        $params[] = $max_teams;
        $params[] = $min_teams;
        $params[] = $start_date ?: null;
        $params[] = $end_date ?: null;
        $params[] = $registration_deadline ? $registration_deadline . ':00' : null;
        $params[] = $location;
        $params[] = $rules;
        $params[] = $id; // WHERE id = ?

        $stmt = $db->prepare("UPDATE tournaments SET {$setClauses} WHERE id = ?");
        $stmt->execute($params);

        // Update time slots: delete old, insert new
        if (in_array($tournament_type, ['round_robin', 'two_stage', 'league'])) {
            // Only delete slots that don't have teams assigned
            $db->prepare("DELETE FROM time_slots WHERE tournament_id = ? AND id NOT IN (SELECT DISTINCT time_slot_id FROM teams WHERE time_slot_id IS NOT NULL AND tournament_id = ?)")->execute([$id, $id]);

            if (!empty($_POST['slot_dates'])) {
                $slotDates = $_POST['slot_dates'];
                $slotTimes = $_POST['slot_times'];
                $slotLabels = $_POST['slot_labels'];
                $slotMaxTeams = $_POST['slot_max_teams'];
                $slotIds = $_POST['slot_ids'] ?? [];

                $insertStmt = $db->prepare("INSERT INTO time_slots (tournament_id, slot_date, slot_time, slot_label, max_teams) VALUES (?, ?, ?, ?, ?)");
                $updateStmt = $db->prepare("UPDATE time_slots SET slot_date = ?, slot_time = ?, slot_label = ?, max_teams = ? WHERE id = ? AND tournament_id = ?");

                for ($i = 0; $i < count($slotDates); $i++) {
                    if (!empty($slotDates[$i]) && !empty($slotTimes[$i])) {
                        if (!empty($slotIds[$i])) {
                            $updateStmt->execute([$slotDates[$i], $slotTimes[$i], $slotLabels[$i] ?? '', intval($slotMaxTeams[$i] ?? 3), $slotIds[$i], $id]);
                        } else {
                            $insertStmt->execute([$id, $slotDates[$i], $slotTimes[$i], $slotLabels[$i] ?? '', intval($slotMaxTeams[$i] ?? 3)]);
                        }
                    }
                }
            }
        }

        setFlash('success', 'Tournament updated successfully!');
        header("Location: /admin/tournament-edit.php?id=" . $id);
        exit;
    }

    // Re-use POST data on error
    $tournament = array_merge($tournament, $_POST);
}

$pageTitle = 'Edit: ' . $tournament['name'];
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Edit Tournament</h1>
        <div class="admin-actions">
            <a href="/admin/tournament-manage.php?id=<?php echo $id; ?>" class="btn btn-primary btn-small">Manage</a>
            <a href="/admin/dashboard.php" class="btn btn-secondary btn-small">Dashboard</a>
        </div>
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

    <form method="POST" action="">
        <!-- Basic Info -->
        <div class="form-section">
            <h3 class="form-section-title">Basic Information</h3>

            <div class="form-row">
                <div class="form-group">
                    <label for="name">Tournament Name *</label>
                    <input type="text" id="name" name="name" class="form-control"
                           value="<?php echo h($tournament['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label for="tournament_number">Tournament Number *</label>
                    <input type="text" id="tournament_number" name="tournament_number" class="form-control"
                           value="<?php echo h($tournament['tournament_number']); ?>" required>
                    <span class="form-hint">Used for scoring system integration</span>
                </div>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" class="form-control"><?php echo h($tournament['description']); ?></textarea>
            </div>

            <div class="form-group">
                <label for="location">Location</label>
                <input type="text" id="location" name="location" class="form-control"
                       value="<?php echo h($tournament['location']); ?>">
            </div>
        </div>

        <!-- Tournament Type -->
        <div class="form-section">
            <h3 class="form-section-title">Tournament Format</h3>

            <div class="form-group">
                <label for="tournament_type">Tournament Type *</label>
                <select id="tournament_type" name="tournament_type" class="form-control" required>
                    <option value="single_elimination" <?php echo $tournament['tournament_type'] === 'single_elimination' ? 'selected' : ''; ?>>Single Elimination</option>
                    <option value="double_elimination" <?php echo $tournament['tournament_type'] === 'double_elimination' ? 'selected' : ''; ?>>Double Elimination</option>
                    <option value="round_robin" <?php echo $tournament['tournament_type'] === 'round_robin' ? 'selected' : ''; ?>>Round Robin</option>
                    <option value="two_stage" <?php echo $tournament['tournament_type'] === 'two_stage' ? 'selected' : ''; ?>>Two Stage</option>
                    <option value="league" <?php echo $tournament['tournament_type'] === 'league' ? 'selected' : ''; ?>>League (Multi-Day/Week)</option>
                    <option value="queue" <?php echo $tournament['tournament_type'] === 'queue' ? 'selected' : ''; ?>>Queue (Walk-Up)</option>
                </select>
            </div>

            <div id="two-stage-options" class="<?php echo $tournament['tournament_type'] !== 'two_stage' ? 'hidden' : ''; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="two_stage_elimination_type">Stage 2 Elimination Type</label>
                        <select id="two_stage_elimination_type" name="two_stage_elimination_type" class="form-control">
                            <option value="single_elimination" <?php echo ($tournament['two_stage_elimination_type'] ?? '') === 'single_elimination' ? 'selected' : ''; ?>>Single Elimination</option>
                            <option value="double_elimination" <?php echo ($tournament['two_stage_elimination_type'] ?? '') === 'double_elimination' ? 'selected' : ''; ?>>Double Elimination</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="two_stage_advance_count">Teams Advancing Per Group</label>
                        <input type="number" id="two_stage_advance_count" name="two_stage_advance_count"
                               class="form-control" value="<?php echo h($tournament['two_stage_advance_count']); ?>" min="1">
                        <span class="form-hint">Number of top teams from each group that advance to elimination</span>
                    </div>
                </div>
            </div>

            <!-- League Encounters -->
            <div id="league-encounters-option" class="<?php echo !in_array($tournament['tournament_type'], ['league', 'round_robin']) ? 'hidden' : ''; ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label for="league_encounters">Encounters (Meetings)</label>
                        <input type="number" id="league_encounters" name="league_encounters"
                               class="form-control" value="<?php echo h($tournament['league_encounters'] ?? 1); ?>" min="1" max="10">
                        <span class="form-hint">How many times each team plays every other team (e.g., 2 = home &amp; away)</span>
                    </div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="max_teams">Max Teams</label>
                    <input type="number" id="max_teams" name="max_teams" class="form-control"
                           value="<?php echo h($tournament['max_teams']); ?>" min="2">
                </div>
                <div class="form-group">
                    <label for="min_teams">Min Teams</label>
                    <input type="number" id="min_teams" name="min_teams" class="form-control"
                           value="<?php echo h($tournament['min_teams']); ?>" min="2">
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
                           value="<?php echo h($tournament['start_date']); ?>">
                </div>
                <div class="form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control"
                           value="<?php echo h($tournament['end_date']); ?>">
                </div>
            </div>
            <div class="form-group">
                <label for="registration_deadline">Registration Deadline</label>
                <input type="datetime-local" id="registration_deadline" name="registration_deadline" class="form-control"
                       value="<?php echo $tournament['registration_deadline'] ? date('Y-m-d\TH:i', strtotime($tournament['registration_deadline'])) : ''; ?>">
            </div>
        </div>

        <!-- Time Slots / Groups -->
        <div id="time-slots-section" class="form-section <?php echo !in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league']) ? 'hidden' : ''; ?>">
            <h3 class="form-section-title" id="slots-section-title"><?php echo $tournament['tournament_type'] === 'two_stage' ? 'Groups' : 'Time Slots'; ?></h3>
            <p id="slots-section-hint" style="margin-bottom: 20px; opacity: 0.7; font-size: 14px;">
                <?php echo $tournament['tournament_type'] === 'two_stage'
                    ? 'Define groups for the group stage. Teams will sign up for a specific group.'
                    : 'Manage time slots for team sign-ups.'; ?>
            </p>

            <!-- Auto-Generate Time Slots -->
            <div class="auto-generate-panel">
                <button type="button" class="btn btn-secondary btn-small" id="toggle-auto-generate"
                        onclick="toggleAutoGenerate()">
                    <?php echo $tournament['tournament_type'] === 'two_stage' ? '&#9881; Auto-Generate Groups' : '&#9881; Auto-Generate Slots'; ?>
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
                                <option value="week" <?php echo $tournament['tournament_type'] === 'league' ? 'selected' : ''; ?>>Week 1, Week 2...</option>
                                <option value="round" <?php echo $tournament['tournament_type'] === 'round_robin' ? 'selected' : ''; ?>>Round 1, Round 2...</option>
                                <option value="session">Session 1, Session 2...</option>
                                <option value="group" <?php echo $tournament['tournament_type'] === 'two_stage' ? 'selected' : ''; ?>>Group A, Group B...</option>
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
                <?php foreach ($timeSlots as $slot): ?>
                <div class="slot-row">
                    <input type="hidden" name="slot_ids[]" value="<?php echo $slot['id']; ?>">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="slot_dates[]" class="form-control" value="<?php echo h($slot['slot_date']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Time</label>
                        <input type="time" name="slot_times[]" class="form-control" value="<?php echo h($slot['slot_time']); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Label</label>
                        <input type="text" name="slot_labels[]" class="form-control" value="<?php echo h($slot['slot_label']); ?>">
                    </div>
                    <div class="form-group">
                        <label>Max Teams</label>
                        <input type="number" name="slot_max_teams[]" class="form-control" value="<?php echo h($slot['max_teams']); ?>" min="1">
                    </div>
                    <div class="form-group">
                        <label>&nbsp;</label>
                        <button type="button" class="btn btn-danger btn-small" onclick="this.closest('.slot-row').remove()">Remove</button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="btn btn-secondary btn-small add-slot-btn" onclick="addTimeSlot()"><?php echo $tournament['tournament_type'] === 'two_stage' ? '+ Add Group' : '+ Add Time Slot'; ?></button>
        </div>

        <!-- Settings -->
        <div class="form-section">
            <h3 class="form-section-title">Settings</h3>
            <div class="form-row">
                <div class="form-group">
                    <label for="signup_mode">Signup Mode</label>
                    <select id="signup_mode" name="signup_mode" class="form-control">
                        <option value="simple_form" <?php echo $tournament['signup_mode'] === 'simple_form' ? 'selected' : ''; ?>>Simple Form</option>
                        <option value="account_based" <?php echo $tournament['signup_mode'] === 'account_based' ? 'selected' : ''; ?>>Account-Based</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="form-control">
                        <option value="draft" <?php echo $tournament['status'] === 'draft' ? 'selected' : ''; ?>>Draft</option>
                        <option value="registration_open" <?php echo $tournament['status'] === 'registration_open' ? 'selected' : ''; ?>>Registration Open</option>
                        <option value="registration_closed" <?php echo $tournament['status'] === 'registration_closed' ? 'selected' : ''; ?>>Registration Closed</option>
                        <option value="in_progress" <?php echo $tournament['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                        <option value="completed" <?php echo $tournament['status'] === 'completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="cancelled" <?php echo $tournament['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label style="cursor: pointer;">
                        <input type="checkbox" name="sms_enabled" value="1"
                               <?php echo ($tournament['sms_enabled'] ?? 0) ? 'checked' : ''; ?>>
                        Enable SMS Notifications
                    </label>
                    <span class="form-hint">Opted-in captains receive text alerts during games via QUO ($0.01/text)</span>
                </div>
            </div>

            <div id="bracket-display-option" class="form-row <?php echo !in_array($tournament['tournament_type'], ['single_elimination', 'double_elimination', 'two_stage']) ? 'hidden' : ''; ?>">
                <div class="form-group">
                    <label for="bracket_display">Bracket Display</label>
                    <select id="bracket_display" name="bracket_display" class="form-control">
                        <option value="full" <?php echo ($tournament['bracket_display'] ?? 'full') === 'full' ? 'selected' : ''; ?>>Full (show all rounds including byes)</option>
                        <option value="compact" <?php echo ($tournament['bracket_display'] ?? '') === 'compact' ? 'selected' : ''; ?>>Compact (hide bye rounds, fits on one page)</option>
                    </select>
                    <span class="form-hint">Compact hides first-round byes so seeded teams start in the next round</span>
                </div>
            </div>

            <div class="form-group">
                <label for="rules">Rules</label>
                <textarea id="rules" name="rules" class="form-control"><?php echo h($tournament['rules']); ?></textarea>
            </div>
        </div>

        <div class="flex-between">
            <a href="/admin/dashboard.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary btn-large">Save Changes</button>
        </div>
    </form>

    <?php
    // Round Labels Editor (shown for league/round_robin after matches generated)
    $existingRoundLabels = [];
    try {
        if (function_exists('getRoundLabels')) {
            $existingRoundLabels = getRoundLabels($db, $id);
        }
    } catch (PDOException $e) {
        // round_labels table doesn't exist yet — skip
    }
    $hasMatches = $db->prepare("SELECT COUNT(*) FROM matches WHERE tournament_id = ? AND bracket_type = 'round_robin'");
    $hasMatches->execute([$id]);
    $matchCount = $hasMatches->fetchColumn();

    if ($matchCount > 0 && in_array($tournament['tournament_type'], ['league', 'round_robin'])):
        // Get distinct rounds from matches
        $roundsStmt = $db->prepare("SELECT DISTINCT round FROM matches WHERE tournament_id = ? AND bracket_type = 'round_robin' ORDER BY round");
        $roundsStmt->execute([$id]);
        $rounds = $roundsStmt->fetchAll(PDO::FETCH_COLUMN);
    ?>
    <div class="form-section" id="round-labels" style="margin-top: 30px;">
        <h3 class="form-section-title">Round Labels</h3>
        <p style="margin-bottom: 20px; opacity: 0.7; font-size: 14px;">
            Customize the label and date for each round/week. Leave blank to use the default ("Week X" for leagues, "Round X" for round robin).
        </p>

        <form method="POST" action="/api/round-labels.php">
            <input type="hidden" name="tournament_id" value="<?php echo $id; ?>">

            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 80px;">Round</th>
                            <th>Custom Label</th>
                            <th style="width: 180px;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rounds as $round): ?>
                        <?php $label = $existingRoundLabels[$round] ?? []; ?>
                        <tr>
                            <td style="font-weight: 700; color: var(--color-gold);"><?php echo $round; ?></td>
                            <td>
                                <input type="text" name="rounds[<?php echo $round; ?>][label]" class="form-control"
                                       value="<?php echo h($label['label'] ?? ''); ?>"
                                       placeholder="<?php echo $tournament['tournament_type'] === 'league' ? "Week {$round}" : "Round {$round}"; ?>"
                                       style="padding: 8px 12px;">
                            </td>
                            <td>
                                <input type="date" name="rounds[<?php echo $round; ?>][round_date]" class="form-control"
                                       value="<?php echo h($label['round_date'] ?? ''); ?>"
                                       style="padding: 8px 12px;">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 16px;">
                <button type="submit" class="btn btn-primary btn-small">Save Round Labels</button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('tournament_type').addEventListener('change', function() {
    const type = this.value;
    document.getElementById('two-stage-options').classList.toggle('hidden', type !== 'two_stage');
    document.getElementById('time-slots-section').classList.toggle('hidden', type !== 'round_robin' && type !== 'two_stage' && type !== 'league');

    // Show encounters option for league and round_robin (not queue)
    document.getElementById('league-encounters-option').classList.toggle('hidden', type !== 'league' && type !== 'round_robin');

    // Show bracket display option for elimination types (not queue)
    var bracketDisplayOpt = document.getElementById('bracket-display-option');
    var hasElimination = (type === 'single_elimination' || type === 'double_elimination' || type === 'two_stage');
    bracketDisplayOpt.classList.toggle('hidden', !hasElimination);

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
        : 'Manage time slots for team sign-ups.';
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
});

function addTimeSlot() {
    const container = document.getElementById('time-slots-container');
    const row = document.createElement('div');
    row.className = 'slot-row';
    row.innerHTML = `
        <input type="hidden" name="slot_ids[]" value="">
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
            <input type="number" name="slot_max_teams[]" class="form-control" value="3" min="1">
        </div>
        <div class="form-group">
            <label>&nbsp;</label>
            <button type="button" class="btn btn-danger btn-small" onclick="this.closest('.slot-row').remove()">Remove</button>
        </div>
    `;
    container.appendChild(row);
}

// ============================================================
// AUTO-GENERATE TIME SLOTS
// Quick-create multiple time slots based on start date + frequency.
// Generated rows use the same form fields as manual rows (including
// empty slot_ids[] for new slots), so the PHP update handler works unchanged.
// ============================================================

/** Toggle auto-generate panel; pre-fills start date and label prefix. */
function toggleAutoGenerate() {
    var fields = document.getElementById('auto-generate-fields');
    var isHidden = fields.classList.contains('hidden');
    fields.classList.toggle('hidden');

    if (isHidden) {
        var startDate = document.getElementById('start_date');
        var genStartDate = document.getElementById('gen_start_date');
        if (startDate && startDate.value && !genStartDate.value) {
            genStartDate.value = startDate.value;
        }

        var tournamentType = document.getElementById('tournament_type').value;
        var prefixSelect = document.getElementById('gen_label_prefix');
        if (tournamentType === 'league') prefixSelect.value = 'week';
        else if (tournamentType === 'round_robin') prefixSelect.value = 'round';
        else if (tournamentType === 'two_stage') prefixSelect.value = 'group';
    }
}

/** Validate inputs; warn about existing DB-backed slots before replacing. */
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
        // Check how many are saved in DB (have a slot_id value)
        var dbCount = 0;
        existingRows.forEach(function(row) {
            var idInput = row.querySelector('input[name="slot_ids[]"]');
            if (idInput && idInput.value) dbCount++;
        });
        var warning = dbCount > 0
            ? '<br><strong style="color: var(--color-danger);">Note:</strong> ' + dbCount + ' slot(s) exist in the database and may have teams assigned.'
            : '';

        showConfirmModal(
            'Replace Existing Slots?',
            'There are ' + existingRows.length + ' existing time slot(s). Do you want to replace them with the generated slots?' + warning,
            function() {
                existingRows.forEach(function(row) { row.remove(); });
                doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix);
            }
        );
    } else {
        doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix);
    }
}

/** Create slot rows with calculated dates. Includes empty slot_ids[] for new-slot detection. */
function doGenerateSlots(startDate, time, frequency, count, maxTeams, labelPrefix) {
    var currentDate = new Date(startDate + 'T00:00:00');
    var container = document.getElementById('time-slots-container');
    var isEditPage = window.location.href.indexOf('tournament-edit') !== -1;

    for (var i = 0; i < count; i++) {
        var year = currentDate.getFullYear();
        var month = String(currentDate.getMonth() + 1).padStart(2, '0');
        var day = String(currentDate.getDate()).padStart(2, '0');
        var dateStr = year + '-' + month + '-' + day;

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

    document.getElementById('auto-generate-fields').classList.add('hidden');

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

/** Convert 0-based index to letters: 0=A, 1=B, ..., 25=Z, 26=AA. */
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
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
