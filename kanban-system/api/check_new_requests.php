<?php
// เช็คว่า session เริ่มแล้วหรือยัง
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!in_array($_SESSION['role'], ['store', 'admin'])) {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

try {
    // เฉพาะ Store Department เท่านั้นที่ต้องเช็คคำขอใหม่
    if ($user_role !== 'store') {
        echo json_encode(['success' => true, 'hasNewRequests' => false]);
        exit();
    }
    
    // เช็คว่ามีคำขอใหม่ที่ยังไม่ได้ดูหรือไม่ (ใน 5 นาทีที่ผ่านมา)
    $sql = "
        SELECT COUNT(*) as new_count
        FROM material_requests mr
        WHERE mr.status = 'pending'
        AND mr.request_date >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        AND mr.requested_by != ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    $hasNewRequests = $row['new_count'] > 0;
    
    // ถ้ามีคำขอใหม่ ดึงข้อมูลรายละเอียด
    $newRequests = [];
    if ($hasNewRequests) {
        $detail_sql = "
            SELECT 
                mr.request_id,
                mr.request_date,
                u.full_name as requested_by,
                pj.job_number,
                COUNT(mrd.request_detail_id) as item_count
            FROM material_requests mr
            JOIN users u ON mr.requested_by = u.user_id
            LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
            LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
            WHERE mr.status = 'pending'
            AND mr.request_date >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
            AND mr.requested_by != ?
            GROUP BY mr.request_id
            ORDER BY mr.request_date DESC
            LIMIT 5
        ";
        
        $detail_stmt = $conn->prepare($detail_sql);
        $detail_stmt->bind_param("i", $user_id);
        $detail_stmt->execute();
        $detail_result = $detail_stmt->get_result();
        
        while ($request = $detail_result->fetch_assoc()) {
            $newRequests[] = [
                'request_id' => $request['request_id'],
                'request_date' => $request['request_date'],
                'requested_by' => $request['requested_by'],
                'job_number' => $request['job_number'],
                'item_count' => $request['item_count']
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'hasNewRequests' => $hasNewRequests,
        'newRequestsCount' => $row['new_count'],
        'requests' => $newRequests
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการตรวจสอบคำขอใหม่: ' . $e->getMessage()
    ]);
}
?>