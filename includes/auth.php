<?php
/**
 * Authentication Helper
 * Sherwood Adventure Tournament System
 */

session_start();

require_once __DIR__ . '/../config/database.php';

/**
 * Check if current user is logged in as admin
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Require admin login - redirect to login page if not authenticated
 */
function requireAdmin() {
    if (!isAdmin()) {
        header('Location: /admin/login.php');
        exit;
    }
    // Check session expiry
    if (isset($_SESSION['admin_login_time']) &&
        (time() - $_SESSION['admin_login_time']) > SESSION_LIFETIME) {
        session_destroy();
        session_start();
        setFlash('warning', 'Your session has expired. Please log in again.');
        header('Location: /admin/login.php');
        exit;
    }
}

/**
 * Attempt admin login
 */
function loginAdmin($username, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash, display_name FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_display_name'] = $admin['display_name'];
        $_SESSION['admin_login_time'] = time();

        // Update last login
        $stmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$admin['id']]);

        return true;
    }
    return false;
}

/**
 * Logout admin
 */
function logoutAdmin() {
    session_destroy();
}

/**
 * Check if team account is logged in
 */
function isTeamLoggedIn() {
    return isset($_SESSION['team_account_id']) && !empty($_SESSION['team_account_id']);
}

/**
 * Login team account
 */
function loginTeamAccount($email, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, password_hash, captain_name, phone FROM team_accounts WHERE email = ?");
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if ($account && password_verify($password, $account['password_hash'])) {
        $_SESSION['team_account_id'] = $account['id'];
        $_SESSION['team_account_email'] = $account['email'];
        $_SESSION['team_account_name'] = $account['captain_name'];
        $_SESSION['team_account_phone'] = $account['phone'] ?? '';

        $stmt = $db->prepare("UPDATE team_accounts SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$account['id']]);

        return true;
    }
    return false;
}

/**
 * Register a new team account, then auto-login
 * Returns true on success, or an error message string on failure.
 */
function registerTeamAccount($email, $password, $captainName, $phone = '') {
    $db = getDB();

    // Check if email already exists
    $check = $db->prepare("SELECT COUNT(*) FROM team_accounts WHERE email = ?");
    $check->execute([$email]);
    if ($check->fetchColumn() > 0) {
        return 'An account with this email already exists.';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("INSERT INTO team_accounts (email, password_hash, captain_name, phone) VALUES (?, ?, ?, ?)");
    $stmt->execute([$email, $hash, $captainName, $phone]);

    // Auto-login after registration
    return loginTeamAccount($email, $password) ? true : 'Registration succeeded but auto-login failed.';
}

/**
 * Logout team account (preserves admin session if present)
 */
function logoutTeamAccount() {
    unset(
        $_SESSION['team_account_id'],
        $_SESSION['team_account_email'],
        $_SESSION['team_account_name'],
        $_SESSION['team_account_phone']
    );
}

/**
 * Require team account login — redirect if not logged in
 */
function requireTeamLogin($redirectUrl = '/') {
    if (!isTeamLoggedIn()) {
        setFlash('warning', 'Please log in to your team account.');
        header("Location: {$redirectUrl}");
        exit;
    }
}
