<?php
// api/notifications.php
require_once '../config/config.php';
require_once '../config/database.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'ไม่ได้รับอนุญาต']);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_role = getUserRole();
$user_id = $_SESSION['user_id'];

try {
    $notifications = [];
    $total_count = 0;
    
    switch ($user_role) {
        case 'admin':
            // การแจ้งเตือนสำหรับ Admin
            $queries = [
                'low_stock' => "SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active'",
                'pending_users' => "SELECT COUNT(*) FROM users WHERE status = 'pending'",
                'system_errors' => "SELECT COUNT(*) FROM system_logs WHERE level = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
            ];
            break;
            
        case 'planning':
            // การแจ้งเตือนสำหรับ Planning
            $queries = [
                'overdue_jobs' => "SELECT COUNT(*) FROM production_jobs WHERE end_date < CURDATE() AND status IN ('pending', 'in_progress') AND created_by = $user_id",
                'low_stock' => "SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active'",
                'pending_prs' => "SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending'"
            ];
            break;
            
        case 'production':
            // การแจ้งเตือนสำหรับ Production
            $queries = [
                'new_jobs' => "SELECT COUNT(*) FROM production_jobs WHERE status = 'pending' AND assigned_to = $user_id",
                'urgent_jobs' => "SELECT COUNT(*) FROM production_jobs WHERE end_date <= DATE_ADD(CURDATE(), INTERVAL 2 DAY) AND status = 'in_progress' AND assigned_to = $user_id",
                'material_approved' => "SELECT COUNT(*) FROM material_requests WHERE status = 'approved' AND requested_by = $user_id"
            ];
            break;
            
        case 'store':
            // การแจ้งเตือนสำหรับ Store
            $queries = [
                'pending_requests' => "SELECT COUNT(*) FROM material_requests WHERE status = 'pending'",
                'low_stock' => "SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active'",
                'overstock' => "SELECT COUNT(*) FROM materials WHERE current_stock > max_stock AND status = 'active'"
            ];
            break;
            
        case 'management':
            // การแจ้งเตือนสำหรับ Management
            $queries = [
                'pending_prs' => "SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending'",
                'overdue_jobs' => "SELECT COUNT(*) FROM production_jobs WHERE end_date < CURDATE() AND status IN ('pending', 'in_progress')",
                'low_efficiency' => "SELECT COUNT(*) FROM production_jobs WHERE status = 'completed' AND (quantity_produced / quantity_planned) < 0.8 AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            ];
            break;
    }
    
    // Execute queries and collect notifications
    foreach ($queries as $type => $query) {
        $stmt = $db->prepare($query);
        $stmt->execute();
        $count = $stmt->fetchColumn();
        
        if ($count > 0) {
            $total_count += $count;
            $notifications[] = [
                'type' => $type,
                'count' => $count,
                'message' => getNotificationMessage($type, $count)
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'count' => $total_count,
        'notifications' => $notifications,
        'timestamp' => time()
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function getNotificationMessage($type, $count) {
    $messages = [
        'low_stock' => "มีวัสดุสต็อกต่ำ $count รายการ",
        'overdue_jobs' => "มีงานที่เกินกำหนด $count งาน",
        'pending_requests' => "มีคำขอเบิกวัสดุรอพิจารณา $count รายการ",
        'pending_prs' => "มีคำขอซื้อรออนุมัติ $count รายการ",
        'new_jobs' => "มีงานใหม่ $count งาน",
        'urgent_jobs' => "มีงานเร่งด่วน $count งาน",
        'material_approved' => "มีคำขอวัสดุได้รับอนุมัติ $count รายการ",
        'overstock' => "มีวัสดุสต็อกเกิน $count รายการ",
        'pending_users' => "มีผู้ใช้รอการอนุมัติ $count คน",
        'system_errors' => "มีข้อผิดพลาดระบบ $count รายการ",
        'low_efficiency' => "มีงานที่ประสิทธิภาพต่ำ $count งาน"
    ];
    
    return $messages[$type] ?? "การแจ้งเตือน: $count รายการ";
}