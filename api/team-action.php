<?php
/**
 * Team Actions API
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
requireAdmin();

$db = getDB();
$teamId = intval($_POST['team_id'] ?? 0);
$action = $_POST['action'] ?? '';
$redirect = $_POST['redirect'] ?? '/admin/dashboard.php';

$stmt = $db->prepare("SELECT * FROM teams WHERE id = ?");
$stmt->execute([$teamId]);
$team = $stmt->fetch();

if (!$team) {
    setFlash('error', 'Team not found.');
    header("Location: {$redirect}");
    exit;
}

switch ($action) {
    case 'withdraw':
        $db->prepare("UPDATE teams SET status = 'withdrawn' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" has been withdrawn.");
        break;

    case 'confirm':
        $db->prepare("UPDATE teams SET status = 'confirmed' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" confirmed.");
        break;

    case 'checkin':
        $db->prepare("UPDATE teams SET status = 'checked_in' WHERE id = ?")->execute([$teamId]);
        setFlash('success', "Team \"{$team['team_name']}\" checked in.");
        break;

    case 'update_seed':
        $seed = intval($_POST['seed'] ?? 0) ?: null;
        $db->prepare("UPDATE teams SET seed = ? WHERE id = ?")->execute([$seed, $teamId]);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true]);
            exit;
        }
        setFlash('success', 'Seed updated.');
        break;

    default:
        setFlash('error', 'Invalid action.');
}

header("Location: {$redirect}");
exit;
