<?php
/**
 * Activity report from the browser - Janet's Quality Catering System
 * static/js/idle-logout.js calls this when the person types, clicks, scrolls or touches the page,
 * at most once a minute, so working on one page for a long time still counts as activity.
 * Other background requests never extend the session (see includes/auth_check.php).
 */
define('JQ_ACTIVITY_PING', true);
require_once __DIR__ . '/../includes/auth_check.php'; // signed-in check, idle check and security token

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}
$_SESSION['jq_last_seen'] = time();
echo json_encode(['ok' => true, 'idle_limit' => JQ_SESSION_IDLE_SECONDS]);
