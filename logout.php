<?php
/**
 * Logout Page - Janet's Quality Catering System
 */
require_once __DIR__ . '/config/session.php';
require_once 'config/database.php';
require_once 'includes/activity_logger.php';

// Record the logout before the session (and the user's identity) is destroyed
$idle = ($_GET['reason'] ?? '') === 'idle';
if (isLoggedIn()) {
    logActivity(getDBConnection(), 'LOGOUT', 'Authentication', 'User ' . ($_SESSION['username'] ?? '') . ($idle ? ' was signed out automatically after 30 minutes of inactivity' : ' signed out'), [
        'record_table' => 'users',
        'record_id'    => $_SESSION['user_id'] ?? null,
        'record_label' => $_SESSION['username'] ?? null,
    ]);
}

// Clear all session data, then remove the session cookie too
session_unset();
session_destroy();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'domain' => $p['domain'], 'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => $p['samesite'] ?: 'Lax']);
}

// Redirect to login page
header('Location: login.php' . ($idle ? '?timeout=1' : ''));
exit();
?>
