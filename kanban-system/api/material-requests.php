<?php
// api/material-requests.php  
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

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            $request_id = (int)$_GET['id'];
            
            $query = "SELECT mr.*, pj.job_number, pj.product_id, u.full_name as requested_by_name, 
                             p.product_name
                      FROM material_requests mr
                      LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
                      LEFT JOIN users u ON mr.requested_by = u.user_id
                      LEFT JOIN products p ON pj.product_id = p.product_id
                      WHERE mr.request_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute([$request_id]);
            $request = $stmt->fetch();
            
            if (!$request) {
                throw new Exception('ไม่พบคำขอที่ระบุ');
            }
            
            // Get request details
            $detailQuery = "SELECT mrd.*, m.part_code, m.material_name, m.unit, m.current_stock
                           FROM material_request_details mrd
                           LEFT JOIN materials m ON mrd.material_id = m.material_id
                           WHERE mrd.request_id = ?";
            $detailStmt = $db->prepare($detailQuery);
            $detailStmt->execute([$request_id]);
            $request['details'] = $detailStmt->fetchAll();
            
            echo json_encode(['success' => true, 'data' => $request]);
            break;
            
        case 'approve':
            checkRole(['store', 'admin']);
            
            $request_id = (int)$_POST['request_id'];
            
            $db->beginTransaction();
            
            // Get request details
            $requestQuery = "SELECT * FROM material_requests WHERE request_id = ? AND status = 'pending'";
            $requestStmt = $db->prepare($requestQuery);
            $requestStmt->execute([$request_id]);
            $request = $requestStmt->fetch();
            
            if (!$request) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            // Check stock availability for all items
            $detailQuery = "SELECT mrd.*, m.part_code, m.material_name, m.current_stock
                           FROM material_request_details mrd
                           LEFT JOIN materials m ON mrd.material_id = m.material_id
                           WHERE mrd.request_id = ?";
            $detailStmt = $db->prepare($detailQuery);
            $detailStmt->execute([$request_id]);
            $details = $detailStmt->fetchAll();
            
            $insufficient_items = [];
            foreach ($details as $detail) {
                if ($detail['current_stock'] < $detail['quantity_requested']) {
                    $insufficient_items[] = $detail['part_code'] . ' (คงเหลือ: ' . number_format($detail['current_stock']) . ', ต้องการ: ' . number_format($detail['quantity_requested']) . ')';
                }
            }
            
            if (!empty($insufficient_items)) {
                throw new Exception('วัสดุไม่เพียงพอ: ' . implode(', ', $insufficient_items));
            }
            
            // Process stock deduction and update fulfilled quantities
            foreach ($details as $detail) {
                // Update material stock
                $updateStockQuery = "UPDATE materials SET current_stock = current_stock - ? WHERE material_id = ?";
                $updateStockStmt = $db->prepare($updateStockQuery);
                $updateStockStmt->execute([$detail['quantity_requested'], $detail['material_id']]);
                
                // Record inventory transaction
                $transactionQuery = "INSERT INTO inventory_transactions 
                                    (material_id, transaction_type, quantity, reference_type, reference_id, 
                                     previous_stock, current_stock, transaction_by, notes) 
                                    VALUES (?, 'out', ?, 'production', ?, ?, ?, ?, ?)";
                $transactionStmt = $db->prepare($transactionQuery);
                $transactionStmt->execute([
                    $detail['material_id'], 
                    $detail['quantity_requested'], 
                    $request_id,
                    $detail['current_stock'], 
                    $detail['current_stock'] - $detail['quantity_requested'], 
                    $_SESSION['user_id'],
                    'อนุมัติคำขอเบิกวัสดุ #' . $request['request_number']
                ]);
                
                // Update fulfilled quantity
                $updateDetailQuery = "UPDATE material_request_details SET quantity_fulfilled = ? WHERE request_detail_id = ?";
                $updateDetailStmt = $db->prepare($updateDetailQuery);
                $updateDetailStmt->execute([$detail['quantity_requested'], $detail['request_detail_id']]);
            }
            
            // Update request status
            $updateRequestQuery = "UPDATE material_requests SET status = 'fulfilled', approved_by = ?, approved_date = NOW() WHERE request_id = ?";
            $updateRequestStmt = $db->prepare($updateRequestQuery);
            $updateRequestStmt->execute([$_SESSION['user_id'], $request_id]);
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'อนุมัติคำขอเบิกวัสดุสำเร็จ']);
            break;
            
        case 'reject':
            checkRole(['store', 'admin']);
            
            $request_id = (int)$_POST['request_id'];
            $reason = sanitize($_POST['reason'] ?? '');
            
            $query = "UPDATE material_requests SET status = 'rejected', approved_by = ?, approved_date = NOW(), notes = CONCAT(COALESCE(notes, ''), '\nปฏิเสธโดย: ', ?, '\nเหตุผล: ', ?) WHERE request_id = ? AND status = 'pending'";
            $stmt = $db->prepare($query);
            $stmt->execute([$_SESSION['user_id'], $_SESSION['full_name'], $reason, $request_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบคำขอหรือคำขอถูกดำเนินการแล้ว');
            }
            
            echo json_encode(['success' => true, 'message' => 'ปฏิเสธคำขอเบิกวัสดุสำเร็จ']);
            break;
            
        case 'create':
            checkRole(['production', 'admin']);
            
            $job_id = (int)$_POST['job_id'];
            $materials = json_decode($_POST['materials'], true);
            $notes = sanitize($_POST['notes'] ?? '');
            
            if (empty($materials)) {
                throw new Exception('กรุณาระบุวัสดุที่ต้องการเบิก');
            }
            
            $db->beginTransaction();
            
            // Generate request number
            $request_number = 'MR' . date('Ymd') . sprintf('%04d', rand(1, 9999));
            
            // Create request header
            $requestQuery = "INSERT INTO material_requests (request_number, job_id, requested_by, notes) VALUES (?, ?, ?, ?)";
            $requestStmt = $db->prepare($requestQuery);
            $requestStmt->execute([$request_number, $job_id, $_SESSION['user_id'], $notes]);
            $request_id = $db->lastInsertId();
            
            // Create request details
            foreach ($materials as $material) {
                $detailQuery = "INSERT INTO material_request_details (request_id, material_id, quantity_requested) VALUES (?, ?, ?)";
                $detailStmt = $db->prepare($detailQuery);
                $detailStmt->execute([$request_id, $material['material_id'], $material['quantity']]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'สร้างคำขอเบิกวัสดุสำเร็จ', 'request_number' => $request_number]);
            break;
            
        default:
            throw new Exception('ไม่พบการกระทำที่ระบุ');
    }
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}