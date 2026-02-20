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

/**
 * Get custom round labels for a tournament.
 * Returns associative array: round_number => ['label' => ..., 'round_date' => ...]
 */
function getRoundLabels($db, $tournamentId) {
    $stmt = $db->prepare("SELECT round_number, label, round_date FROM round_labels WHERE tournament_id = ? ORDER BY round_number");
    $stmt->execute([$tournamentId]);
    $labels = [];
    foreach ($stmt->fetchAll() as $r) {
        $labels[$r['round_number']] = $r;
    }
    return $labels;
}

/**
 * Render team name HTML with optional logo and forfeit styling
 */
function teamNameHtml($name, $isForfeit = 0, $logoPath = null, $size = 'sm') {
    $sizes = ['xs' => 16, 'sm' => 24, 'md' => 32, 'lg' => 48];
    $px = $sizes[$size] ?? 24;
    $html = '';

    if ($logoPath) {
        $html .= '<img src="/uploads/logos/' . htmlspecialchars($logoPath) . '" class="team-logo" width="' . $px . '" height="' . $px . '" alt="">';
    }

    if ($isForfeit) {
        $html .= '<span class="team-forfeit"><s>' . htmlspecialchars($name) . '</s> <small>(Forfeit)</small></span>';
    } else {
        $html .= htmlspecialchars($name);
    }
    return $html;
}

/**
 * Handle team logo upload from $_FILES['team_logo']
 * Returns the filename on success, null on failure/no upload.
 */
function handleLogoUpload($teamId) {
    if (empty($_FILES['team_logo']['name']) || $_FILES['team_logo']['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024; // 2MB
    $file = $_FILES['team_logo'];

    if (!in_array($file['type'], $allowed)) return null;
    if ($file['size'] > $maxSize) return null;

    $uploadDir = __DIR__ . '/../uploads/logos/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'team_' . $teamId . '_' . time() . '.' . $ext;
    $dest = $uploadDir . $filename;

    if (move_uploaded_file($file['tmp_name'], $dest)) {
        return $filename;
    }
    return null;
}
