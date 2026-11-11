<?php
/**
 * Get backdrops by event type
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/session.php';

require_once '../includes/auth_check.php';

$pdo = getDBConnection();
$event_type = $_GET['event_type'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT id, backdrop_name, image_path FROM backdrops
        WHERE event_type = ? AND active = 1
        ORDER BY backdrop_name
    ");
    $stmt->execute([$event_type]);
    $backdrops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'backdrops' => $backdrops]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
