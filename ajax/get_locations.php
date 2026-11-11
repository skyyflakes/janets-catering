<?php
require_once __DIR__ . '/../config/session.php';
require_once '../includes/auth_check.php';

header('Content-Type: application/json');

try {
    $action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
    $db = getDBConnection();
    
    if ($action === 'get_regions') {
        // Get all regions
        $query = "SELECT DISTINCT region_code, region_name FROM psgc_regions ORDER BY region_name ASC";
        $result = $db->query($query);
        $regions = [];
        
        while ($row = $result->fetch_assoc()) {
            $regions[] = [
                'code' => $row['region_code'],
                'name' => $row['region_name']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $regions]);
        
    } elseif ($action === 'get_provinces') {
        // Get provinces by region code
        $region_code = isset($_GET['region_code']) ? sanitize($_GET['region_code']) : '';
        
        if (!$region_code) {
            echo json_encode(['success' => false, 'message' => 'Region code required']);
            exit;
        }
        
        $query = "SELECT DISTINCT province_code, province_name FROM psgc_provinces 
                  WHERE region_code = ? ORDER BY province_name ASC";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $region_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $provinces = [];
        
        while ($row = $result->fetch_assoc()) {
            $provinces[] = [
                'code' => $row['province_code'],
                'name' => $row['province_name']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $provinces]);
        
    } elseif ($action === 'get_municipalities') {
        // Get municipalities by province code
        $province_code = isset($_GET['province_code']) ? sanitize($_GET['province_code']) : '';
        
        if (!$province_code) {
            echo json_encode(['success' => false, 'message' => 'Province code required']);
            exit;
        }
        
        $query = "SELECT DISTINCT municipality_code, municipality_name FROM psgc_municipalities 
                  WHERE province_code = ? ORDER BY municipality_name ASC";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $province_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $municipalities = [];
        
        while ($row = $result->fetch_assoc()) {
            $municipalities[] = [
                'code' => $row['municipality_code'],
                'name' => $row['municipality_name']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $municipalities]);
        
    } elseif ($action === 'get_barangays') {
        // Get barangays by municipality code
        $municipality_code = isset($_GET['municipality_code']) ? sanitize($_GET['municipality_code']) : '';
        
        if (!$municipality_code) {
            echo json_encode(['success' => false, 'message' => 'Municipality code required']);
            exit;
        }
        
        $query = "SELECT DISTINCT barangay_code, barangay_name FROM psgc_barangays 
                  WHERE municipality_code = ? ORDER BY barangay_name ASC";
        $stmt = $db->prepare($query);
        $stmt->bind_param('s', $municipality_code);
        $stmt->execute();
        $result = $stmt->get_result();
        $barangays = [];
        
        while ($row = $result->fetch_assoc()) {
            $barangays[] = [
                'code' => $row['barangay_code'],
                'name' => $row['barangay_name']
            ];
        }
        
        echo json_encode(['success' => true, 'data' => $barangays]);
        
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
