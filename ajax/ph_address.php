<?php
/**
 * PSGC Address AJAX Handler
 * Serves Philippine address data from MySQL database (PSGC tables)
 * Tables: psgc_regions, psgc_provinces, psgc_municipalities, psgc_barangays
 */

header('Content-Type: application/json');
require_once '../config/database.php';

$action = $_GET['action'] ?? '';

$pdo = getDBConnection();
if (!$pdo) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

switch ($action) {
    case 'regions':
        $stmt = $pdo->query("SELECT region_code, region_name FROM psgc_regions ORDER BY region_name");
        echo json_encode($stmt->fetchAll());
        break;

    case 'provinces':
        $region_code = $_GET['region_code'] ?? '';
        $stmt = $pdo->prepare("SELECT province_code, province_name FROM psgc_provinces WHERE region_code = ? ORDER BY province_name");
        $stmt->execute([$region_code]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'cities':
        $province_code = $_GET['province_code'] ?? '';
        $stmt = $pdo->prepare("SELECT municipality_code, municipality_name FROM psgc_municipalities WHERE province_code = ? ORDER BY municipality_name");
        $stmt->execute([$province_code]);
        echo json_encode($stmt->fetchAll());
        break;

    case 'barangays':
        $municipality_code = $_GET['municipality_code'] ?? '';
        $stmt = $pdo->prepare("SELECT barangay_code, barangay_name FROM psgc_barangays WHERE municipality_code = ? ORDER BY barangay_name");
        $stmt->execute([$municipality_code]);
        echo json_encode($stmt->fetchAll());
        break;

    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}
