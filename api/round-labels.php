<?php
/**
 * Round Labels API
 * Sherwood Adventure Tournament System
 *
 * Saves custom round labels and dates for league/round-robin tournaments.
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$tournamentId = intval($_POST['tournament_id'] ?? 0);

$stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
$stmt->execute([$tournamentId]);
$tournament = $stmt->fetch();

if (!$tournament) {
    setFlash('error', 'Tournament not found.');
    header('Location: /admin/dashboard.php');
    exit;
}

$redirect = "/admin/tournament-edit.php?id={$tournamentId}#round-labels";

$rounds = $_POST['rounds'] ?? [];

if (!empty($rounds) && is_array($rounds)) {
    $upsertStmt = $db->prepare("
        INSERT INTO round_labels (tournament_id, round_number, label, round_date)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE label = VALUES(label), round_date = VALUES(round_date)
    ");

    foreach ($rounds as $roundNumber => $data) {
        $roundNum = intval($roundNumber);
        $label = trim($data['label'] ?? '') ?: null;
        $roundDate = !empty($data['round_date']) ? $data['round_date'] : null;
        $upsertStmt->execute([$tournamentId, $roundNum, $label, $roundDate]);
    }

    setFlash('success', 'Round labels saved successfully.');
} else {
    setFlash('info', 'No round label data to save.');
}

header("Location: {$redirect}");
exit;
