<?php
require_once '../includes/auth_check.php';

header('Content-Type: application/json');

$municipality_id = $_GET['municipality_id'] ?? null;

if (!$municipality_id) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("
        SELECT id, barangay_name FROM psgc_barangays 
        WHERE municipality_code = (SELECT municipality_code FROM psgc_municipalities WHERE id = ?)
        ORDER BY barangay_name
    ");
    $stmt->execute([$municipality_id]);
    $barangays = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($barangays);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
