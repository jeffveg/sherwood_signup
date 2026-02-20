<?php
/**
 * Captain Logout
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';

logoutTeamAccount();

$return = $_GET['return'] ?? '/';
setFlash('success', 'You have been logged out.');
header("Location: {$return}");
exit;
