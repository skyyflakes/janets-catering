<?php
require_once '../includes/auth_check.php';

header('Content-Type: application/json');

$region_id = $_GET['region_id'] ?? null;

if (!$region_id) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, province_name FROM psgc_provinces 
        WHERE region_code = (SELECT region_code FROM psgc_regions WHERE id = ?)
        ORDER BY province_name
    ");
    $stmt->execute([$region_id]);
    $provinces = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($provinces);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
