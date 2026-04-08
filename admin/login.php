<?php
/**
 * Admin Login Page
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';

// Redirect if already logged in
if (isAdmin()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (isLoginThrottled()) {
        $error = 'Too many login attempts. Please wait a moment and try again.';
    } elseif (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        if (loginAdmin($username, $password)) {
            header('Location: /admin/dashboard.php');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

$pageTitle = 'Admin Login';
include __DIR__ . '/../includes/header.php';
?>

<div class="login-wrapper">
    <div class="login-card fade-in">
        <img src="https://sherwoodadventure.com/images/logo.png"
             alt="Sherwood Adventure" class="site-logo">
        <h2>Tournament Admin</h2>

        <?php if ($error): ?>
            <div class="flash-message flash-error" style="margin-bottom: 20px; border-radius: var(--border-radius); padding: 12px;">
                <?php echo h($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" class="form-control"
                       value="<?php echo h($username ?? ''); ?>" autocomplete="username" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" class="form-control"
                       autocomplete="current-password" required>
            </div>

            <button type="submit" class="btn btn-primary btn-large">Enter the Realm</button>
        </form>

        <p class="mt-2" style="font-size: 13px; opacity: 0.5;">
            <a href="/">Back to Tournaments</a>
        </p>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
