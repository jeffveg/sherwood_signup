<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/database.php';
$flash = getFlash();
$isAdminPage = strpos($_SERVER['REQUEST_URI'], '/admin') !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? h($pageTitle) . ' | ' : ''; ?>Sherwood Adventure Tournaments</title>

    <!-- Google Fonts matching sherwoodadventure.com -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Dancing+Script:wght@400;700&family=EB+Garamond&family=Lato:wght@300;400;700&family=Lustria&family=PT+Sans&display=swap" rel="stylesheet">

    <!-- Main Stylesheet -->
    <link rel="stylesheet" href="/assets/css/style.css">

    <?php if ($isAdminPage): ?>
    <link rel="stylesheet" href="/assets/css/admin.css">
    <?php endif; ?>
</head>
<body class="<?php echo $isAdminPage ? 'admin-body' : 'public-body'; ?>">

    <!-- Header -->
    <header class="site-header">
        <div class="header-inner">
            <a href="/" class="logo-link">
                <img src="https://sherwoodadventure.com/images/c/logo_466608_print-1--500.png"
                     alt="Sherwood Adventure" class="site-logo">
            </a>
            <nav class="main-nav">
                <button class="nav-toggle" aria-label="Toggle navigation">
                    <span></span><span></span><span></span>
                </button>
                <ul class="nav-links">
                    <li><a href="/">Tournaments</a></li>
                    <li><a href="https://sherwoodadventure.com" target="_blank">Main Site</a></li>
                    <?php if (function_exists('isTeamLoggedIn') && isTeamLoggedIn()): ?>
                        <li><a href="/captain/">My Teams</a></li>
                        <li><a href="/captain/logout.php">Logout</a></li>
                    <?php endif; ?>
                    <?php if (isAdmin()): ?>
                        <li><a href="/admin/dashboard.php">Admin Dashboard</a></li>
                        <li><a href="/admin/logout.php">Logout</a></li>
                    <?php elseif ($isAdminPage): ?>
                        <li><a href="/admin/login.php">Admin Login</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </header>

    <!-- Flash Messages -->
    <?php if ($flash): ?>
    <div class="flash-message flash-<?php echo h($flash['type']); ?>">
        <div class="container">
            <?php echo h($flash['message']); ?>
            <button class="flash-close" onclick="this.parentElement.parentElement.remove()">&times;</button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Main Content -->
    <main class="main-content">
