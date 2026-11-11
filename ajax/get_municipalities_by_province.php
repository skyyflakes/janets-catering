<?php
require_once '../includes/auth_check.php';

header('Content-Type: application/json');

$province_id = $_GET['province_id'] ?? null;

if (!$province_id) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, municipality_name FROM psgc_municipalities 
        WHERE province_code = (SELECT province_code FROM psgc_provinces WHERE id = ?)
        ORDER BY municipality_name
    ");
    $stmt->execute([$province_id]);
    $municipalities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($municipalities);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
