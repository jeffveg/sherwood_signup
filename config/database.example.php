<?php
/**
 * Database Configuration
 * Sherwood Adventure Tournament System
 *
 * SETUP: Copy this file to database.php and update the values below.
 *   cp config/database.example.php config/database.php
 */

define('DB_HOST', 'localhost');       // IONOS: use hostname like db12345678.hosting-data.io
define('DB_NAME', 'your_database');   // CHANGE: Your MySQL database name
define('DB_USER', 'your_db_user');    // CHANGE: Your MySQL username
define('DB_PASS', 'your_db_pass');    // CHANGE: Your MySQL password
define('DB_CHARSET', 'utf8mb4');

// Site configuration
define('SITE_NAME', 'Sherwood Adventure Tournaments');
define('SITE_URL', 'https://app.sherwoodadventure.com');  // CHANGE: Your domain
define('ADMIN_EMAIL', 'admin@sherwoodadventure.com');      // CHANGE: Your admin email

// Session configuration
define('SESSION_LIFETIME', 3600); // 1 hour

// Timezone
date_default_timezone_set('America/Phoenix');

/**
 * Get PDO database connection
 */
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("Database connection error. Please try again later.");
        }
    }
    return $pdo;
}

/**
 * Generate a unique registration code for teams
 */
function generateRegistrationCode() {
    return strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
}

/**
 * Generate a unique tournament number
 */
function generateTournamentNumber() {
    return 'SA-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

/**
 * Sanitize output for HTML display
 */
function h($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Flash message helpers
 */
function setFlash($type, $message) {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash() {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}
