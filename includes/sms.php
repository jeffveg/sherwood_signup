<?php
/**
 * SMS Helper Functions (QUO API)
 * Sherwood Adventure Tournament System
 *
 * Handles sending SMS messages via QUO (formerly OpenPhone) REST API.
 * All phone number normalization, message building, sending, and logging
 * goes through this file. Called by api/sms-notify.php.
 *
 * QUO API docs: https://www.quo.com/api
 * Cost: $0.01 per 160-character segment
 */

/**
 * Normalize a US phone number to E.164 format (+1XXXXXXXXXX).
 * Accepts: (555) 123-4567, 555-123-4567, 5551234567, +15551234567, etc.
 *
 * @param  string      $phone  Raw phone number input
 * @return string|null E.164 formatted number, or null if invalid
 */
function normalizePhoneNumber($phone) {
    if (empty($phone)) {
        return null;
    }

    // Strip everything except digits
    $digits = preg_replace('/[^0-9]/', '', $phone);

    // Remove leading 1 if it's an 11-digit US number
    if (strlen($digits) === 11 && $digits[0] === '1') {
        $digits = substr($digits, 1);
    }

    // Must be exactly 10 digits for a US number
    if (strlen($digits) !== 10) {
        return null;
    }

    return '+1' . $digits;
}

/**
 * Send an SMS message via QUO API.
 *
 * @param  string $to   Recipient phone number (will be normalized)
 * @param  string $body Message body (keep under 160 chars for single segment)
 * @return array  ['success' => bool, 'message_id' => string|null, 'error' => string|null]
 */
function sendSms($to, $body) {
    $normalizedTo = normalizePhoneNumber($to);
    if (!$normalizedTo) {
        return ['success' => false, 'message_id' => null, 'error' => 'Invalid phone number: ' . $to];
    }

    if (!defined('QUO_API_KEY') || QUO_API_KEY === 'your-quo-api-key-here') {
        return ['success' => false, 'message_id' => null, 'error' => 'QUO API key not configured'];
    }
    if (!defined('QUO_PHONE_FROM') || QUO_PHONE_FROM === '+1XXXXXXXXXX') {
        return ['success' => false, 'message_id' => null, 'error' => 'QUO phone number not configured'];
    }
    if (!defined('QUO_API_URL')) {
        return ['success' => false, 'message_id' => null, 'error' => 'QUO API URL not configured'];
    }

    $payload = json_encode([
        'from'    => QUO_PHONE_FROM,
        'to'      => $normalizedTo,
        'content' => $body,
    ]);

    $ch = curl_init(QUO_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: ' . QUO_API_KEY,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['success' => false, 'message_id' => null, 'error' => 'cURL error: ' . $curlError];
    }

    $data = json_decode($response, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return [
            'success'    => true,
            'message_id' => $data['data']['id'] ?? null,
            'error'      => null,
        ];
    }

    return [
        'success'    => false,
        'message_id' => null,
        'error'      => 'QUO API error (' . $httpCode . '): ' . ($data['message'] ?? $response),
    ];
}

/**
 * Check if a notification has already been sent for this match/team/type combo.
 * Pre-check before sending to avoid wasting a QUO API call.
 *
 * @param  PDO    $db
 * @param  int    $matchId
 * @param  int    $teamId
 * @param  string $type  'upcoming', 'on_deck', or 'score'
 * @return bool   true if already sent
 */
function smsAlreadySent($db, $matchId, $teamId, $type) {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM sms_log
        WHERE match_id = ? AND team_id = ? AND notification_type = ? AND status = 'sent'
    ");
    $stmt->execute([$matchId, $teamId, $type]);
    return $stmt->fetchColumn() > 0;
}

/**
 * Log an SMS notification attempt. Also serves as the dedup safety net:
 * the UNIQUE KEY on (match_id, team_id, notification_type) will reject
 * duplicate inserts even if the pre-check was bypassed by a race condition.
 *
 * @param  PDO         $db
 * @param  int         $tournamentId
 * @param  int         $matchId
 * @param  int         $teamId
 * @param  string      $type         'upcoming', 'on_deck', or 'score'
 * @param  string      $phone        Normalized phone number
 * @param  string      $messageBody
 * @param  string|null $quoMessageId QUO API message ID (null on failure)
 * @param  string      $status       'sent', 'failed', or 'skipped'
 * @param  string|null $errorMsg     Error message (null on success)
 * @return bool        true if duplicate (already logged), false if new entry created
 */
function logSmsNotification($db, $tournamentId, $matchId, $teamId, $type, $phone, $messageBody, $quoMessageId = null, $status = 'sent', $errorMsg = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO sms_log
            (tournament_id, match_id, team_id, notification_type, phone_to,
             message_body, quo_message_id, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $tournamentId, $matchId, $teamId, $type, $phone,
            $messageBody, $quoMessageId, $status, $errorMsg
        ]);
        return false; // New entry — not a duplicate
    } catch (PDOException $e) {
        // Error code 23000 = integrity constraint violation (duplicate key)
        if ($e->getCode() == 23000) {
            return true; // Duplicate — already logged
        }
        error_log("SMS log error: " . $e->getMessage());
        return false;
    }
}

/**
 * Build the message body for a game-approaching notification.
 * Kept under 160 characters for a single SMS segment ($0.01).
 *
 * @param  string $teamName       The team being notified
 * @param  string $opponentName   Their opponent
 * @param  int    $gamesAway      Number of games until their match
 * @return string Message body
 */
function buildUpcomingMessage($teamName, $opponentName, $gamesAway) {
    if ($gamesAway <= 1) {
        // On deck — next up
        return "Sherwood: {$teamName}, you're ON DECK! Game vs {$opponentName} is next. Head to the field! Reply STOP to opt out.";
    }
    // N games away — estimate time at ~8 min per game
    $estMinutes = $gamesAway * 8;
    return "Sherwood: {$teamName}, you play {$opponentName} in ~{$gamesAway} games (~{$estMinutes} min). Start getting ready! Reply STOP to opt out.";
}

/**
 * Build the message body for a score result notification.
 *
 * @param  string      $team1Name
 * @param  int         $team1Score
 * @param  string      $team2Name
 * @param  int         $team2Score
 * @param  string|null $winnerName  null if tie
 * @return string Message body
 */
function buildScoreMessage($team1Name, $team1Score, $team2Name, $team2Score, $winnerName) {
    $result = "{$team1Name} {$team1Score} - {$team2Score} {$team2Name}";
    if ($winnerName) {
        $result .= ". {$winnerName} wins!";
    }
    return "Sherwood Score: {$result} Reply STOP to opt out.";
}
