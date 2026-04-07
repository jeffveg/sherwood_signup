<?php
/**
 * Authentication Helper
 * Sherwood Adventure Tournament System
 *
 * Handles admin and team account sessions, CSRF protection,
 * brute force throttling, and session cookie security.
 */

// Set secure session cookie parameters BEFORE session_start().
// This ensures cookies are HttpOnly, Secure, and SameSite=Strict
// regardless of whether mod_php is available (works with FastCGI/FPM too).
session_set_cookie_params([
    'lifetime' => 0,             // Session cookie (expires when browser closes)
    'path'     => '/',
    'secure'   => true,          // HTTPS only
    'httponly'  => true,          // No JavaScript access
    'samesite'  => 'Strict',     // CSRF protection at the cookie level
]);
session_start();

require_once __DIR__ . '/../config/database.php';

// ============================================================
// CSRF Protection
// ============================================================

/**
 * Generate or retrieve the current CSRF token for this session.
 * Token is stored in $_SESSION and lasts for the session lifetime.
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Return a hidden input field containing the CSRF token.
 * Drop this inside any <form> that uses method="POST".
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

/**
 * Verify that the submitted CSRF token matches the session token.
 * Call at the top of any POST handler. Halts execution on failure.
 */
function verifyCsrf() {
    $submitted = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $submitted)) {
        http_response_code(403);
        die('Invalid or missing security token. Please go back and try again.');
    }
}

// ============================================================
// Brute Force Protection
// ============================================================

/**
 * Check if login attempts should be throttled for this IP.
 * Uses a session-based counter — after 5 failed attempts, enforces
 * an increasing delay (2^failures seconds, capped at 60s).
 *
 * Returns true if the request should be blocked (too soon after last failure).
 */
function isLoginThrottled() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);

    if (!isset($_SESSION[$key])) {
        return false;
    }

    $data = $_SESSION[$key];
    $failures = $data['count'] ?? 0;
    $lastAttempt = $data['last'] ?? 0;

    if ($failures < 5) {
        return false;
    }

    // Exponential backoff: 2^(failures-4) seconds, capped at 60s
    $delay = min(60, pow(2, $failures - 4));
    return (time() - $lastAttempt) < $delay;
}

/**
 * Record a failed login attempt for the current IP.
 */
function recordFailedLogin() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);

    $data = $_SESSION[$key] ?? ['count' => 0, 'last' => 0];
    $data['count']++;
    $data['last'] = time();
    $_SESSION[$key] = $data;
}

/**
 * Clear login attempt counter (call after successful login).
 */
function clearLoginAttempts() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'login_attempts_' . md5($ip);
    unset($_SESSION[$key]);
}

// ============================================================
// Admin Authentication
// ============================================================

/**
 * Check if current user is logged in as admin
 */
function isAdmin() {
    return isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);
}

/**
 * Require admin login - redirect to login page if not authenticated.
 * Also enforces session timeout (SESSION_LIFETIME).
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
 * Attempt admin login.
 * Regenerates session ID on success to prevent session fixation.
 */
function loginAdmin($username, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, username, password_hash, display_name FROM admins WHERE username = ?");
    $stmt->execute([$username]);
    $admin = $stmt->fetch();

    if ($admin && password_verify($password, $admin['password_hash'])) {
        // Regenerate session ID to prevent session fixation attacks.
        // An attacker who pre-sets a session ID before login cannot
        // hijack the authenticated session because the ID changes here.
        session_regenerate_id(true);

        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_username'] = $admin['username'];
        $_SESSION['admin_display_name'] = $admin['display_name'];
        $_SESSION['admin_login_time'] = time();

        clearLoginAttempts();

        // Update last login
        $stmt = $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$admin['id']]);

        return true;
    }

    recordFailedLogin();
    return false;
}

/**
 * Logout admin — fully destroys session to prevent reuse.
 */
function logoutAdmin() {
    // Clear session data
    $_SESSION = [];

    // Delete the session cookie
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }

    session_destroy();
}

// ============================================================
// Team Account Authentication
// ============================================================

/**
 * Check if team account is logged in
 */
function isTeamLoggedIn() {
    return isset($_SESSION['team_account_id']) && !empty($_SESSION['team_account_id']);
}

/**
 * Login team account.
 * Regenerates session ID on success to prevent session fixation.
 */
function loginTeamAccount($email, $password) {
    $db = getDB();
    $stmt = $db->prepare("SELECT id, email, password_hash, captain_name, phone FROM team_accounts WHERE email = ?");
    $stmt->execute([$email]);
    $account = $stmt->fetch();

    if ($account && password_verify($password, $account['password_hash'])) {
        session_regenerate_id(true);

        $_SESSION['team_account_id'] = $account['id'];
        $_SESSION['team_account_email'] = $account['email'];
        $_SESSION['team_account_name'] = $account['captain_name'];
        $_SESSION['team_account_phone'] = $account['phone'] ?? '';
        $_SESSION['team_login_time'] = time();

        clearLoginAttempts();

        $stmt = $db->prepare("UPDATE team_accounts SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$account['id']]);

        return true;
    }

    recordFailedLogin();
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
        $_SESSION['team_account_phone'],
        $_SESSION['team_login_time']
    );
}

/**
 * Require team account login — redirect if not logged in.
 * Also enforces session timeout (SESSION_LIFETIME).
 */
function requireTeamLogin($redirectUrl = '/') {
    if (!isTeamLoggedIn()) {
        setFlash('warning', 'Please log in to your team account.');
        header("Location: {$redirectUrl}");
        exit;
    }
    // Check team session expiry
    if (isset($_SESSION['team_login_time']) &&
        (time() - $_SESSION['team_login_time']) > SESSION_LIFETIME) {
        logoutTeamAccount();
        setFlash('warning', 'Your session has expired. Please log in again.');
        header("Location: {$redirectUrl}");
        exit;
    }
}
