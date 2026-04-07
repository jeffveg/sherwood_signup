<?php
/**
 * Admin Setup Page
 * Sherwood Adventure Tournament System
 * - Change password
 * - Create admin accounts
 * - Generate initial password hash
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$errors = [];
$success = '';

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    if ($_POST['action'] === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (empty($current) || empty($new)) {
            $errors[] = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } elseif (strlen($new) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            // Verify current password
            $stmt = $db->prepare("SELECT password_hash FROM admins WHERE id = ?");
            $stmt->execute([$_SESSION['admin_id']]);
            $admin = $stmt->fetch();

            if (password_verify($current, $admin['password_hash'])) {
                $hash = password_hash($new, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
                $stmt->execute([$hash, $_SESSION['admin_id']]);
                $success = 'Password changed successfully!';
            } else {
                $errors[] = 'Current password is incorrect.';
            }
        }
    }

    if ($_POST['action'] === 'create_admin') {
        $username = trim($_POST['new_username'] ?? '');
        $display = trim($_POST['new_display_name'] ?? '');
        $email = trim($_POST['new_email'] ?? '');
        $pass = $_POST['new_admin_password'] ?? '';

        if (empty($username) || empty($pass) || empty($display)) {
            $errors[] = 'Username, display name, and password are required.';
        } elseif (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        } else {
            $check = $db->prepare("SELECT COUNT(*) FROM admins WHERE username = ?");
            $check->execute([$username]);
            if ($check->fetchColumn() > 0) {
                $errors[] = 'Username already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO admins (username, password_hash, display_name, email) VALUES (?, ?, ?, ?)");
                $stmt->execute([$username, $hash, $display, $email]);
                $success = "Admin account \"{$username}\" created successfully!";
            }
        }
    }

    if ($_POST['action'] === 'generate_hash') {
        $hashPassword = $_POST['hash_password'] ?? '';
        if (!empty($hashPassword)) {
            $generatedHash = password_hash($hashPassword, PASSWORD_DEFAULT);
        }
    }
}

// Get admin list
$admins = $db->query("SELECT id, username, display_name, email, last_login, created_at FROM admins ORDER BY id")->fetchAll();

$pageTitle = 'Setup';
include __DIR__ . '/../includes/header.php';
?>

<div class="admin-container">
    <div class="admin-page-header">
        <h1>Setup & Admin Accounts</h1>
        <a href="/admin/dashboard.php" class="btn btn-secondary btn-small">Dashboard</a>
    </div>

    <?php if ($success): ?>
        <div class="flash-message flash-success" style="border-radius: var(--border-radius); padding: 12px; margin-bottom: 20px;">
            <?php echo h($success); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="flash-message flash-error" style="border-radius: var(--border-radius); padding: 16px; margin-bottom: 20px;">
            <ul style="list-style: none;">
                <?php foreach ($errors as $err): ?>
                    <li>&#9888; <?php echo h($err); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <!-- Change Password -->
    <div class="form-section">
        <h3 class="form-section-title">Change Your Password</h3>
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-group">
                <label for="current_password">Current Password</label>
                <input type="password" id="current_password" name="current_password" class="form-control" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" class="form-control" required minlength="8">
                </div>
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" class="form-control" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Update Password</button>
        </form>
    </div>

    <!-- Create Admin Account -->
    <div class="form-section">
        <h3 class="form-section-title">Create Admin Account</h3>
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="create_admin">
            <div class="form-row">
                <div class="form-group">
                    <label for="new_username">Username</label>
                    <input type="text" id="new_username" name="new_username" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="new_display_name">Display Name</label>
                    <input type="text" id="new_display_name" name="new_display_name" class="form-control" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="new_email">Email</label>
                    <input type="email" id="new_email" name="new_email" class="form-control">
                </div>
                <div class="form-group">
                    <label for="new_admin_password">Password</label>
                    <input type="password" id="new_admin_password" name="new_admin_password" class="form-control" required minlength="8">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">Create Admin</button>
        </form>
    </div>

    <!-- Admin List -->
    <div class="form-section">
        <h3 class="form-section-title">Admin Accounts</h3>
        <div class="admin-table-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Display Name</th>
                        <th>Email</th>
                        <th>Last Login</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($admins as $a): ?>
                    <tr>
                        <td><strong><?php echo h($a['username']); ?></strong></td>
                        <td><?php echo h($a['display_name']); ?></td>
                        <td><?php echo h($a['email']); ?></td>
                        <td><?php echo $a['last_login'] ? date('M j, Y g:i A', strtotime($a['last_login'])) : 'Never'; ?></td>
                        <td><?php echo date('M j, Y', strtotime($a['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Password Hash Generator (for initial setup) -->
    <div class="form-section">
        <h3 class="form-section-title">Password Hash Generator</h3>
        <p style="font-size: 13px; opacity: 0.7; margin-bottom: 15px;">
            Use this to generate a password hash for the initial admin account in the SQL schema.
        </p>
        <form method="POST" action="">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="generate_hash">
            <div class="form-group">
                <label for="hash_password">Password to Hash</label>
                <input type="text" id="hash_password" name="hash_password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-secondary">Generate Hash</button>
        </form>
        <?php if (isset($generatedHash)): ?>
            <div class="card mt-2" style="background: var(--color-primary-green);">
                <label style="font-size: 12px; color: var(--color-gold);">Generated Hash:</label>
                <code style="word-break: break-all; font-size: 13px; display: block; margin-top: 6px;"><?php echo h($generatedHash); ?></code>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
