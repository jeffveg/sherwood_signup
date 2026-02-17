<?php
/**
 * Installation Script
 * Sherwood Adventure Tournament System
 *
 * Run this once to set up the database and create the initial admin account.
 * DELETE THIS FILE after installation for security!
 */

// Check if already installed
$configFile = __DIR__ . '/config/database.php';

$step = intval($_GET['step'] ?? 1);
$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === 1) {
        // Test database connection
        $dbHost = trim($_POST['db_host'] ?? 'localhost');
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';

        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Create database if it doesn't exist
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Run schema
            $schema = file_get_contents(__DIR__ . '/sql/schema.sql');
            // Remove the CREATE DATABASE and USE lines since we handled them
            $schema = preg_replace('/CREATE DATABASE.*?;\s*/s', '', $schema);
            $schema = preg_replace('/USE.*?;\s*/s', '', $schema);

            // Split by semicolons and execute each statement
            $statements = array_filter(array_map('trim', explode(';', $schema)));
            foreach ($statements as $sql) {
                if (!empty($sql)) {
                    try {
                        $pdo->exec($sql);
                    } catch (PDOException $e) {
                        // Ignore duplicate key/table errors during re-install
                        if (strpos($e->getMessage(), 'already exists') === false &&
                            strpos($e->getMessage(), 'Duplicate') === false) {
                            $errors[] = "SQL Error: " . $e->getMessage();
                        }
                    }
                }
            }

            if (empty($errors)) {
                // Update config file
                $configContent = file_get_contents($configFile);
                $configContent = str_replace("'localhost'", "'" . addslashes($dbHost) . "'", $configContent);
                $configContent = str_replace("'sherwood_tournaments'", "'" . addslashes($dbName) . "'", $configContent);
                $configContent = str_replace("'your_db_user'", "'" . addslashes($dbUser) . "'", $configContent);
                $configContent = str_replace("'your_db_pass'", "'" . addslashes($dbPass) . "'", $configContent);
                file_put_contents($configFile, $configContent);

                header('Location: /install.php?step=2');
                exit;
            }
        } catch (PDOException $e) {
            $errors[] = "Connection failed: " . $e->getMessage();
        }
    }

    if ($step === 2) {
        // Create admin account
        require_once $configFile;
        $db = getDB();

        $username = trim($_POST['admin_username'] ?? '');
        $password = $_POST['admin_password'] ?? '';
        $displayName = trim($_POST['admin_display_name'] ?? '');
        $email = trim($_POST['admin_email'] ?? '');

        if (empty($username)) $errors[] = 'Username is required.';
        if (empty($password) || strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (empty($displayName)) $errors[] = 'Display name is required.';

        if (empty($errors)) {
            $hash = password_hash($password, PASSWORD_DEFAULT);

            // Delete the placeholder admin and insert real one
            $db->exec("DELETE FROM admins WHERE username = 'admin' AND password_hash = '\$2y\$10\$placeholder'");

            $stmt = $db->prepare("INSERT INTO admins (username, password_hash, display_name, email) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = ?, display_name = ?, email = ?");
            $stmt->execute([$username, $hash, $displayName, $email, $hash, $displayName, $email]);

            $success = 'Installation complete! You can now log in.';
            $step = 3;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Install - Sherwood Adventure Tournaments</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@700&family=Lato:wght@400;700&family=PT+Sans&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .install-container { max-width: 600px; margin: 40px auto; padding: 0 20px; }
        .install-step { font-family: var(--font-heading); font-size: 13px; color: var(--color-orange); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
    </style>
</head>
<body class="public-body">
    <div style="text-align: center; padding: 30px; border-bottom: 5px solid var(--color-brown-border);">
        <img src="https://sherwoodadventure.com/images/c/logo_466608_print-1--500.png" alt="Sherwood Adventure" style="height: 80px;">
        <h1 style="font-size: 36px;">Tournament System Setup</h1>
    </div>

    <div class="install-container">
        <?php if (!empty($errors)): ?>
            <div class="flash-message flash-error" style="border-radius: var(--border-radius); padding: 14px; margin-bottom: 20px;">
                <?php foreach ($errors as $e): ?>
                    <p>&#9888; <?php echo htmlspecialchars($e); ?></p>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <div class="form-section">
                <div class="install-step">Step 1 of 2</div>
                <h2 class="form-section-title">Database Configuration</h2>
                <p style="margin-bottom: 20px; opacity: 0.7; font-size: 14px;">
                    Enter your IONOS MySQL database credentials. You can find these in your IONOS hosting control panel.
                </p>
                <form method="POST" action="?step=1">
                    <div class="form-group">
                        <label for="db_host">Database Host</label>
                        <input type="text" id="db_host" name="db_host" class="form-control" value="localhost">
                        <span class="form-hint">Usually 'localhost' or a hostname from IONOS</span>
                    </div>
                    <div class="form-group">
                        <label for="db_name">Database Name</label>
                        <input type="text" id="db_name" name="db_name" class="form-control" value="sherwood_tournaments" required>
                    </div>
                    <div class="form-group">
                        <label for="db_user">Database Username</label>
                        <input type="text" id="db_user" name="db_user" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label for="db_pass">Database Password</label>
                        <input type="password" id="db_pass" name="db_pass" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">
                        Connect & Create Tables
                    </button>
                </form>
            </div>

        <?php elseif ($step === 2): ?>
            <div class="form-section">
                <div class="install-step">Step 2 of 2</div>
                <h2 class="form-section-title">Create Admin Account</h2>
                <p style="margin-bottom: 20px; opacity: 0.7; font-size: 14px;">
                    Database connected! Now create your admin account.
                </p>
                <form method="POST" action="?step=2">
                    <div class="form-group">
                        <label for="admin_username">Username</label>
                        <input type="text" id="admin_username" name="admin_username" class="form-control" value="admin" required>
                    </div>
                    <div class="form-group">
                        <label for="admin_password">Password</label>
                        <input type="password" id="admin_password" name="admin_password" class="form-control" required minlength="8">
                        <span class="form-hint">Minimum 8 characters</span>
                    </div>
                    <div class="form-group">
                        <label for="admin_display_name">Display Name</label>
                        <input type="text" id="admin_display_name" name="admin_display_name" class="form-control" value="Administrator" required>
                    </div>
                    <div class="form-group">
                        <label for="admin_email">Email</label>
                        <input type="email" id="admin_email" name="admin_email" class="form-control">
                    </div>
                    <button type="submit" class="btn btn-primary btn-large" style="width: 100%;">
                        Create Admin & Finish
                    </button>
                </form>
            </div>

        <?php elseif ($step === 3): ?>
            <div class="form-section" style="text-align: center; padding: 50px 30px;">
                <h2 style="color: var(--color-gold);">Installation Complete!</h2>
                <p style="margin: 20px 0;">Your tournament system is ready to use.</p>
                <p style="color: var(--color-danger); font-weight: 700; margin: 20px 0;">
                    &#9888; IMPORTANT: Delete this install.php file for security!
                </p>
                <div style="margin-top: 30px;">
                    <a href="/admin/login.php" class="btn btn-primary btn-large">Go to Admin Login</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
