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

// Upload configuration
define('UPLOAD_DIR', __DIR__ . '/../uploads/logos/');
define('MAX_LOGO_SIZE', 2 * 1024 * 1024); // 2MB
define('ALLOWED_LOGO_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// Timezone
date_default_timezone_set('America/Phoenix');

// Scoring API Key (used by Sherwood Timer to push/pull match data)
// Change this to a secure random string for production
define('SCORING_API_KEY', 'change-me-to-a-random-string');

// QUO (formerly OpenPhone) SMS API Configuration
// Used to send text notifications to team captains during tournaments.
// Get your API key from QUO dashboard: Settings → API → Generate API Key
// Requires A2P 10DLC registration for US business texting.
define('QUO_API_KEY', 'your-quo-api-key-here');       // CHANGE: Your QUO API key
define('QUO_PHONE_FROM', '+1XXXXXXXXXX');              // CHANGE: Your QUO phone number (E.164 format)
define('QUO_API_URL', 'https://api.quo.com/v1/messages');

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
    return strtoupper(bin2hex(random_bytes(4)));
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
 * Returns the filename on success, null on no file, or false on error (sets flash message)
 */
function handleLogoUpload($teamId) {
    if (!isset($_FILES['team_logo']) || $_FILES['team_logo']['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES['team_logo'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'Logo upload failed. Please try again.');
        return false;
    }

    if ($file['size'] > MAX_LOGO_SIZE) {
        setFlash('error', 'Logo file is too large. Maximum size is 2MB.');
        return false;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_LOGO_TYPES)) {
        setFlash('error', 'Invalid logo file type. Allowed: JPG, PNG, GIF, WebP.');
        return false;
    }

    // Ensure upload directory exists
    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $ext = match($mimeType) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        default => 'png',
    };

    $filename = $teamId . '_' . time() . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
        setFlash('error', 'Failed to save logo file.');
        return false;
    }

    return $filename;
}

/**
 * Calculate remaining game slots for a queue tournament.
 *
 * Uses the event end datetime (end_date + end_time) and game_duration_minutes
 * to determine how many more games can be played, then subtracts teams already
 * signed up (who haven't played yet) to get available signup slots.
 *
 * This is the core function for dynamic queue registration cutoff — it tells
 * the signup page, tournament page, and display pages whether registration
 * is still open and how long the estimated wait is.
 *
 * @param  array $tournament   Tournament row from DB (needs end_date, end_time, game_duration_minutes)
 * @param  int   $pendingTeams Number of teams signed up but not yet eliminated (registered + checked_in)
 * @return array ['slots_remaining' => int|null, 'registration_open' => bool,
 *                'est_wait_minutes' => int|null, 'games_remaining' => int|null]
 *               slots_remaining is null when end_time or game_duration not set (unlimited registration)
 */
function getQueueAvailability($tournament, $pendingTeams) {
    $gameDuration = $tournament['game_duration_minutes'] ?? null;
    $endDate = $tournament['end_date'] ?? null;
    $endTime = $tournament['end_time'] ?? null;

    // If game duration or end datetime not configured, registration is always open (unlimited).
    // This allows queue tournaments to run without a hard time boundary.
    if (!$gameDuration || !$endDate || !$endTime) {
        return [
            'slots_remaining' => null,
            'registration_open' => true,
            'est_wait_minutes' => null,
            'games_remaining' => null,
        ];
    }

    // Build end datetime from end_date + end_time
    $endDatetime = strtotime("{$endDate} {$endTime}");
    $now = time();

    // If the event end has already passed, no more slots
    if ($now >= $endDatetime) {
        return [
            'slots_remaining' => 0,
            'registration_open' => false,
            'est_wait_minutes' => 0,
            'games_remaining' => 0,
        ];
    }

    // Minutes remaining in the event
    $minutesLeft = ($endDatetime - $now) / 60;

    // Total games that can still be played (each takes game_duration_minutes)
    $gamesRemaining = max(0, floor($minutesLeft / $gameDuration));

    // Each game consumes 2 teams. Total team capacity = games * 2.
    $teamCapacity = $gamesRemaining * 2;

    // Subtract teams already waiting (registered + checked_in, not yet eliminated)
    $slotsRemaining = max(0, $teamCapacity - $pendingTeams);

    // Estimated wait: pending teams / 2 = games ahead, times game_duration
    $estWait = ($pendingTeams > 0) ? ceil($pendingTeams / 2) * $gameDuration : 0;

    return [
        'slots_remaining' => $slotsRemaining,
        'registration_open' => ($slotsRemaining > 0),
        'est_wait_minutes' => $estWait,
        'games_remaining' => $gamesRemaining,
    ];
}
