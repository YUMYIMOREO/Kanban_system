<?php
// api/qr-code.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'generate':
            checkRole(['admin', 'store']);
            
            $data = [
                'part_code' => sanitize($_POST['part_code']),
                'type' => sanitize($_POST['type'] ?? 'material'),
                'additional_data' => $_POST['additional_data'] ?? []
            ];
            
            $qr_data = json_encode($data);
            
            // You can integrate with QR code generation libraries here
            // For now, we'll return the data that should be encoded
            
            echo json_encode([
                'success' => true,
                'qr_data' => $qr_data,
                'qr_url' => BASE_URL . 'api/qr-display.php?data=' . urlencode($qr_data)
            ]);
            break;
            
        case 'scan_log':
            // Log QR code scans
            $scan_data = [
                'part_code' => sanitize($_POST['part_code']),
                'scanned_by' => $_SESSION['user_id'],
                'scan_location' => sanitize($_POST['location'] ?? ''),
                'device_info' => $_SERVER['HTTP_USER_AGENT'] ?? ''
            ];
            
            $database = new Database();
            $db = $database->getConnection();
            
            $query = "INSERT INTO qr_scan_logs (part_code, scanned_by, scan_location, device_info) VALUES (?, ?, ?, ?)";
            $stmt = $db->prepare($query);
            $stmt->execute([$scan_data['part_code'], $scan_data['scanned_by'], $scan_data['scan_location'], $scan_data['device_info']]);
            
            echo json_encode(['success' => true, 'message' => 'บันทึกการสแกนสำเร็จ']);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>