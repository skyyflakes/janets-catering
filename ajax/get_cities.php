<?php
/**
 * Get cities/municipalities by province
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../config/session.php';

require_once '../includes/auth_check.php';

$pdo = getDBConnection();
$province = $_GET['province'] ?? '';

try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT municipality_name FROM psgc_municipalities
        WHERE province_code IN (
            SELECT province_code FROM psgc_provinces WHERE province_name = ?
        )
        ORDER BY municipality_name
    ");
    $stmt->execute([$province]);
    $cities = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo json_encode(['success' => true, 'cities' => $cities]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
