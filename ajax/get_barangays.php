<?php
/**
 * Get barangays by city/municipality
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/session.php';

require_once '../includes/auth_check.php';

$pdo = getDBConnection();
$city = $_GET['city'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT barangay_name FROM psgc_barangays
        WHERE municipality_code IN (
            SELECT municipality_code FROM psgc_municipalities WHERE municipality_name = ?
        )
        ORDER BY barangay_name
    ");
    $stmt->execute([$city]);
    $barangays = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(['success' => true, 'barangays' => $barangays]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
