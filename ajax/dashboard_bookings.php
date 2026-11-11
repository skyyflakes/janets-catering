<?php
/**
 * Current dashboard figures as JSON, polled by dashboard.php.
 */
require_once __DIR__ . '/../config/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not signed in.']);
    exit;
}

require_once __DIR__ . '/../includes/activity_logger.php';
require_once __DIR__ . '/../includes/dashboard_data.php';

$pdo = getDBConnection();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable.']);
    exit;
}
jq_require_roles($pdo, ['ADMIN', 'OWNER'], 'Dashboard');

echo json_encode(['success' => true, 'data' => jq_dashboard_data($pdo)], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
