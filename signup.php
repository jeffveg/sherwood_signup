<?php
/**
 * Team Signup Page
 * Sherwood Adventure Tournament System
 *
 * Supports both simple form and account-based signup modes.
 * Account-based: shows login/register tabs, then team form after auth.
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

$hasTimeSlots = in_array($tournament['tournament_type'], ['round_robin', 'two_stage', 'league']);
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

$isAccountBased = ($tournament['signup_mode'] === 'account_based');
$errors = [];
$authErrors = [];
$success = false;
$regCode = '';
$activeAuthTab = 'login'; // default tab for account-based

// Stale session guard: verify the session's team_account_id still exists in the DB.
// This prevents bypassing the auth gate with a leftover session cookie from testing,
// a deleted account, or if the team_accounts table hasn't been created yet.
if (isTeamLoggedIn()) {
    try {
        $acctCheck = $db->prepare("SELECT id FROM team_accounts WHERE id = ?");
        $acctCheck->execute([$_SESSION['team_account_id']]);
        if (!$acctCheck->fetch()) {
            logoutTeamAccount(); // Account no longer exists
        }
    } catch (PDOException $e) {
        logoutTeamAccount(); // team_accounts table doesn't exist (migration not applied)
    }
}

// ============================================================
// Handle account-based actions (login / register)
// ============================================================
if ($isAccountBased && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Captain Login
    if ($action === 'captain_login') {
        $loginEmail = trim($_POST['login_email'] ?? '');
        $loginPassword = $_POST['login_password'] ?? '';

        if (empty($loginEmail) || empty($loginPassword)) {
            $authErrors[] = 'Email and password are required.';
        } else {
            try {
                if (!loginTeamAccount($loginEmail, $loginPassword)) {
                    $authErrors[] = 'Invalid email or password.';
                }
            } catch (PDOException $e) {
                $authErrors[] = 'Account system not available. Please run the database migration (Features 7-8 in migration-features.sql).';
            }
        }
        // On success, loginTeamAccount sets session — page will reload showing team form
        if (empty($authErrors) && isTeamLoggedIn()) {
            header("Location: /signup.php?tournament_id={$tournamentId}");
            exit;
        }
        $activeAuthTab = 'login';
    }

    // Captain Register
    if ($action === 'captain_register') {
        $regName = trim($_POST['reg_name'] ?? '');
        $regEmail = trim($_POST['reg_email'] ?? '');
        $regPhone = trim($_POST['reg_phone'] ?? '');
        $regPassword = $_POST['reg_password'] ?? '';
        $regConfirm = $_POST['reg_confirm'] ?? '';

        if (empty($regName)) $authErrors[] = 'Captain name is required.';
        if (empty($regEmail)) $authErrors[] = 'Email is required.';
        if (!empty($regEmail) && !filter_var($regEmail, FILTER_VALIDATE_EMAIL)) {
            $authErrors[] = 'Please enter a valid email address.';
        }
        if (empty($regPassword)) $authErrors[] = 'Password is required.';
        if (strlen($regPassword) < 6) $authErrors[] = 'Password must be at least 6 characters.';
        if ($regPassword !== $regConfirm) $authErrors[] = 'Passwords do not match.';

        if (empty($authErrors)) {
            try {
                $result = registerTeamAccount($regEmail, $regPassword, $regName, $regPhone);
                if ($result === true) {
                    header("Location: /signup.php?tournament_id={$tournamentId}");
                    exit;
                } else {
                    $authErrors[] = $result;
                }
            } catch (PDOException $e) {
                $authErrors[] = 'Account system not available. Please run the database migration (Features 7-8 in migration-features.sql).';
            }
        }
        $activeAuthTab = 'register';
    }
}

// ============================================================
// Handle team registration (both simple + account-based)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register_team') {
    // Block unauthenticated submissions for account-based tournaments
    if ($isAccountBased && !isTeamLoggedIn()) {
        $errors[] = 'You must sign in or create an account before registering a team.';
    }

    $team_name = trim($_POST['team_name'] ?? '');
    $time_slot_id = intval($_POST['time_slot_id'] ?? 0) ?: null;

    // For account-based tournaments, captain info comes from the team_accounts table
    // (pre-populated during account registration). For simple form, it comes from POST.
    if ($isAccountBased && isTeamLoggedIn()) {
        $acctStmt = $db->prepare("SELECT captain_name, email, phone FROM team_accounts WHERE id = ?");
        $acctStmt->execute([$_SESSION['team_account_id']]);
        $acctData = $acctStmt->fetch();
        if (!$acctData) {
            // Account was deleted after login — force re-authentication
            logoutTeamAccount();
            $errors[] = 'Your account could not be found. Please sign in again.';
        }
        $captain_name = $acctData['captain_name'] ?? '';
        $captain_email = $acctData['email'] ?? '';
        $captain_phone = $acctData['phone'] ?? '';
        $team_account_id = $_SESSION['team_account_id'];
    } else {
        $captain_name = trim($_POST['captain_name'] ?? '');
        $captain_email = trim($_POST['captain_email'] ?? '');
        $captain_phone = trim($_POST['captain_phone'] ?? '');
        $team_account_id = null;
    }
    $sms_opt_in = isset($_POST['sms_opt_in']) ? 1 : 0;

    // Validation
    if (empty($team_name)) $errors[] = 'Team name is required.';
    if (!$isAccountBased || !isTeamLoggedIn()) {
        if (empty($captain_name)) $errors[] = 'Captain name is required.';
        if (empty($captain_email)) $errors[] = 'Email address is required.';
        if (!empty($captain_email) && !filter_var($captain_email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
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

        // Migration safety: check which optional columns exist in teams table.
        $hasAccountCol = false;
        try {
            $db->query("SELECT team_account_id FROM teams LIMIT 0");
            $hasAccountCol = true;
        } catch (PDOException $e) { /* Feature 8 migration not yet applied */ }

        $hasSmsOptIn = false;
        try {
            $db->query("SELECT sms_opt_in FROM teams LIMIT 0");
            $hasSmsOptIn = true;
        } catch (PDOException $e) { /* SMS migration not yet applied */ }

        // Build INSERT dynamically based on available columns
        $cols = 'tournament_id, team_name, captain_name, captain_email, captain_phone';
        $placeholders = '?, ?, ?, ?, ?';
        $params = [$tournamentId, $team_name, $captain_name, $captain_email, $captain_phone];

        if ($hasSmsOptIn) {
            $cols .= ', sms_opt_in';
            $placeholders .= ', ?';
            $params[] = $sms_opt_in;
        }

        $cols .= ', time_slot_id, registration_code';
        $placeholders .= ', ?, ?';
        $params[] = $time_slot_id;
        $params[] = $regCode;

        if ($hasAccountCol) {
            $cols .= ', team_account_id';
            $placeholders .= ', ?';
            $params[] = $team_account_id;
        }

        $cols .= ', status';
        $placeholders .= ", 'registered'";

        $stmt = $db->prepare("INSERT INTO teams ({$cols}) VALUES ({$placeholders})");
        $stmt->execute($params);

        $teamId = $db->lastInsertId();

        // Handle logo upload
        if (function_exists('handleLogoUpload')) {
            $logoFilename = handleLogoUpload($teamId);
            if ($logoFilename) {
                $db->prepare("UPDATE teams SET logo_path = ? WHERE id = ?")->execute([$logoFilename, $teamId]);
            }
        }

        $success = true;
    }
}

// Re-count teams for display (in case we just registered)
$teamCount->execute([$tournamentId]);
$currentTeams = $teamCount->fetchColumn();

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
                <?php if ($isAccountBased && isTeamLoggedIn()): ?>
                    <a href="/captain/" class="btn btn-secondary" style="margin-left: 10px;">My Teams</a>
                <?php else: ?>
                    <a href="/" class="btn btn-secondary" style="margin-left: 10px;">All Tournaments</a>
                <?php endif; ?>
            </div>
        </div>

    <?php elseif ($isAccountBased && !isTeamLoggedIn()): ?>
        <!-- Account-Based: Login / Register Tabs -->
        <div class="signup-card fade-in">
            <?php if (!empty($authErrors)): ?>
                <div class="flash-message flash-error" style="border-radius: var(--border-radius); padding: 14px; margin-bottom: 20px;">
                    <ul style="list-style: none;">
                        <?php foreach ($authErrors as $err): ?>
                            <li>&#9888; <?php echo h($err); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div style="margin-bottom: 25px; padding-bottom: 20px; border-bottom: 2px solid var(--color-brown-border);">
                <h2 style="margin-bottom: 8px;">Captain Account Required</h2>
                <p style="font-size: 14px; opacity: 0.7; margin-bottom: 0;">
                    <?php echo h($tournament['name']); ?>
                    <span class="badge badge-type" style="margin-left: 6px;">
                        <?php echo ucwords(str_replace('_', ' ', $tournament['tournament_type'])); ?>
                    </span>
                </p>
                <p style="font-size: 13px; opacity: 0.5; margin-bottom: 0;">
                    Sign in or create an account to register your team.
                </p>
            </div>

            <!-- Auth Tabs -->
            <div class="auth-tabs">
                <button type="button" class="auth-tab <?php echo $activeAuthTab === 'login' ? 'active' : ''; ?>" onclick="switchAuthTab('login')">Sign In</button>
                <button type="button" class="auth-tab <?php echo $activeAuthTab === 'register' ? 'active' : ''; ?>" onclick="switchAuthTab('register')">Create Account</button>
            </div>

            <!-- Login Panel -->
            <div class="auth-panel <?php echo $activeAuthTab === 'login' ? 'active' : ''; ?>" id="auth-login"
                 style="<?php echo $activeAuthTab !== 'login' ? 'display:none;' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="captain_login">
                    <div class="form-group">
                        <label for="login_email">Email Address</label>
                        <input type="email" id="login_email" name="login_email" class="form-control"
                               value="<?php echo h($_POST['login_email'] ?? ''); ?>" required
                               placeholder="captain@email.com">
                    </div>
                    <div class="form-group">
                        <label for="login_password">Password</label>
                        <input type="password" id="login_password" name="login_password" class="form-control"
                               required placeholder="Your password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">Sign In</button>
                </form>
            </div>

            <!-- Register Panel -->
            <div class="auth-panel <?php echo $activeAuthTab === 'register' ? 'active' : ''; ?>" id="auth-register"
                 style="<?php echo $activeAuthTab !== 'register' ? 'display:none;' : ''; ?>">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="captain_register">
                    <div class="form-group">
                        <label for="reg_name">Captain Name *</label>
                        <input type="text" id="reg_name" name="reg_name" class="form-control"
                               value="<?php echo h($_POST['reg_name'] ?? ''); ?>" required
                               placeholder="Your full name" maxlength="255">
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reg_email">Email *</label>
                            <input type="email" id="reg_email" name="reg_email" class="form-control"
                                   value="<?php echo h($_POST['reg_email'] ?? ''); ?>" required
                                   placeholder="captain@email.com">
                        </div>
                        <div class="form-group">
                            <label for="reg_phone">Phone</label>
                            <input type="tel" id="reg_phone" name="reg_phone" class="form-control"
                                   value="<?php echo h($_POST['reg_phone'] ?? ''); ?>"
                                   placeholder="(555) 123-4567">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="reg_password">Password *</label>
                            <input type="password" id="reg_password" name="reg_password" class="form-control"
                                   required placeholder="Min 6 characters" minlength="6">
                        </div>
                        <div class="form-group">
                            <label for="reg_confirm">Confirm Password *</label>
                            <input type="password" id="reg_confirm" name="reg_confirm" class="form-control"
                                   required placeholder="Repeat password">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">Create Account &amp; Continue</button>
                    <p class="sms-policy-notice">Text Message Policy: Sherwood Adventure LLC uses text messaging for tournament updates and customer service only. We do not send promotional messages. Message and data rates may apply. Reply STOP to opt out. <a href="http://sherwoodadventure.com/terms-and-conditions.html" target="_blank">View Full Policy</a></p>
                </form>
            </div>
        </div>

    <?php else: ?>
        <!-- Team Registration Form (simple form mode OR logged-in account mode) -->
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

            <?php if ($isAccountBased && isTeamLoggedIn()): ?>
                <!-- Logged-in captain banner -->
                <div class="captain-banner">
                    <div>
                        <strong>Signed in as:</strong> <?php echo h($_SESSION['team_account_name']); ?>
                        <span style="opacity: 0.6; margin-left: 8px;">(<?php echo h($_SESSION['team_account_email']); ?>)</span>
                    </div>
                    <a href="/captain/logout.php?return=<?php echo urlencode("/signup.php?tournament_id={$tournamentId}"); ?>" style="font-size: 13px;">Sign out</a>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="signup-form" enctype="multipart/form-data">
                <input type="hidden" name="action" value="register_team">

                <div class="form-group">
                    <label for="team_name">Team Name *</label>
                    <input type="text" id="team_name" name="team_name" class="form-control"
                           value="<?php echo h($_POST['team_name'] ?? ''); ?>" required
                           placeholder="Enter your team name" maxlength="255">
                </div>

                <?php if (!$isAccountBased || !isTeamLoggedIn()): ?>
                <!-- Captain info fields (simple form mode) -->
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
                <?php endif; ?>

                <?php if (!empty($tournament['sms_enabled'])): ?>
                <div class="form-group">
                    <label style="cursor: pointer; font-size: 14px;">
                        <input type="checkbox" name="sms_opt_in" value="1"
                               <?php echo (!isset($_POST['sms_opt_in']) || $_POST['sms_opt_in']) ? 'checked' : ''; ?>>
                        Receive tournament updates via text
                    </label>
                </div>
                <?php endif; ?>

                <div class="form-group">
                    <label for="team_logo">Team Logo <small style="opacity: 0.6;">(optional, max 2MB)</small></label>
                    <input type="file" id="team_logo" name="team_logo" class="form-control"
                           accept="image/jpeg,image/png,image/gif,image/webp">
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
                <p class="sms-policy-notice">Text Message Policy: Sherwood Adventure LLC uses text messaging for tournament updates and customer service only. We do not send promotional messages. Message and data rates may apply. Reply STOP to opt out. <a href="http://sherwoodadventure.com/terms-and-conditions.html" target="_blank">View Full Policy</a></p>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
function selectTimeSlot(el, slotId) {
    document.querySelectorAll('.time-slot-card').forEach(function(card) {
        card.classList.remove('slot-selected');
    });
    el.classList.add('slot-selected');
    document.getElementById('time_slot_id').value = slotId;
}

function switchAuthTab(tab) {
    document.querySelectorAll('.auth-tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.auth-panel').forEach(function(p) {
        p.classList.remove('active');
        p.style.display = 'none';
    });
    document.querySelector('.auth-tab:nth-child(' + (tab === 'login' ? '1' : '2') + ')').classList.add('active');
    var activePanel = document.getElementById('auth-' + tab);
    activePanel.classList.add('active');
    activePanel.style.display = 'block';
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
