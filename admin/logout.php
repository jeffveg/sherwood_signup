<?php
/**
 * Admin Logout
 * Sherwood Adventure Tournament System
 */
require_once __DIR__ . '/../includes/auth.php';
logoutAdmin();
session_start();
setFlash('success', 'You have been logged out.');
header('Location: /admin/login.php');
exit;
