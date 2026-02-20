<?php
/**
 * Captain Action API
 * Sherwood Adventure Tournament System
 *
 * Handles: update_team (name/logo), withdraw_team
 * All actions require team account login and ownership verification.
 */
require_once __DIR__ . '/../includes/auth.php';

if (!isTeamLoggedIn()) {
    setFlash('error', 'Please log in to your team account.');
    header('Location: /');
    exit;
}

$db = getDB();
$action = $_POST['action'] ?? '';
$teamId = intval($_POST['team_id'] ?? 0);
$accountId = $_SESSION['team_account_id'];

// Verify ownership
$stmt = $db->prepare("SELECT t.*, tn.status as tournament_status FROM teams t JOIN tournaments tn ON t.tournament_id = tn.id WHERE t.id = ? AND t.team_account_id = ?");
$stmt->execute([$teamId, $accountId]);
$team = $stmt->fetch();

if (!$team) {
    setFlash('error', 'Team not found or you do not have access.');
    header('Location: /captain/');
    exit;
}

$redirect = "/captain/team.php?id={$teamId}";
$canEdit = in_array($team['tournament_status'], ['registration_open', 'registration_closed']);

switch ($action) {
    case 'update_team':
        if (!$canEdit) {
            setFlash('error', 'Team cannot be edited while the tournament is in progress.');
            break;
        }

        $teamName = trim($_POST['team_name'] ?? '');
        if (empty($teamName)) {
            setFlash('error', 'Team name is required.');
            break;
        }

        // Check duplicate name (excluding self)
        $check = $db->prepare("SELECT COUNT(*) FROM teams WHERE tournament_id = ? AND team_name = ? AND id != ? AND status != 'withdrawn'");
        $check->execute([$team['tournament_id'], $teamName, $teamId]);
        if ($check->fetchColumn() > 0) {
            setFlash('error', 'A team with this name already exists in this tournament.');
            break;
        }

        $db->prepare("UPDATE teams SET team_name = ? WHERE id = ?")->execute([$teamName, $teamId]);

        // Handle logo upload
        $logoFilename = handleLogoUpload($teamId);
        if ($logoFilename) {
            // Delete old logo
            if ($team['logo_path']) {
                $oldPath = __DIR__ . '/../uploads/logos/' . $team['logo_path'];
                if (file_exists($oldPath)) unlink($oldPath);
            }
            $db->prepare("UPDATE teams SET logo_path = ? WHERE id = ?")->execute([$logoFilename, $teamId]);
        }

        setFlash('success', 'Team updated successfully.');
        break;

    case 'withdraw_team':
        if (!$canEdit) {
            setFlash('error', 'Team cannot be withdrawn while the tournament is in progress.');
            break;
        }

        $db->prepare("UPDATE teams SET status = 'withdrawn' WHERE id = ?")->execute([$teamId]);
        setFlash('success', 'Team has been withdrawn from the tournament.');
        $redirect = '/captain/';
        break;

    default:
        setFlash('error', 'Invalid action.');
}

header("Location: {$redirect}");
exit;
