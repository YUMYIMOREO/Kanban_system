<?php
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
        case 'transaction':
            checkRole(['store', 'admin']);
            
            $material_id = (int)$_POST['material_id'];
            $transaction_type = $_POST['transaction_type']; // in, out, adjustment
            $quantity = (int)$_POST['quantity'];
            $reference_type = $_POST['reference_type'];
            $reference_id = $_POST['reference_id'] ?? null;
            $notes = sanitize($_POST['notes']);
            
            if ($quantity <= 0) {
                throw new Exception('จำนวนต้องมากกว่า 0');
            }
            
            $db->beginTransaction();
            
            // Get current material info
            $materialQuery = "SELECT * FROM materials WHERE material_id = ? AND status = 'active'";
            $materialStmt = $db->prepare($materialQuery);
            $materialStmt->execute([$material_id]);
            $material = $materialStmt->fetch();
            
            if (!$material) {
                throw new Exception('ไม่พบวัสดุที่ระบุ');
            }
            
            $previous_stock = (int)$material['current_stock'];
            $current_stock = $previous_stock;
            
            // Calculate new stock based on transaction type
            switch ($transaction_type) {
                case 'in':
                    $current_stock += $quantity;
                    break;
                case 'out':
                    if ($previous_stock < $quantity) {
                        throw new Exception('สต็อกไม่เพียงพอ (คงเหลือ: ' . number_format($previous_stock) . ')');
                    }
                    $current_stock -= $quantity;
                    break;
                case 'adjustment':
                    $current_stock = $quantity; // For adjustment, quantity is the new total
                    $quantity = $current_stock - $previous_stock; // Calculate the difference
                    break;
                default:
                    throw new Exception('ประเภทรายการไม่ถูกต้อง');
            }
            
            // Update material stock
            $updateQuery = "UPDATE materials SET current_stock = ? WHERE material_id = ?";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->execute([$current_stock, $material_id]);
            
            // Record transaction
            $transactionQuery = "INSERT INTO inventory_transactions 
                                (material_id, transaction_type, quantity, reference_type, reference_id, 
                                 previous_stock, current_stock, transaction_by, notes) 
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $transactionStmt = $db->prepare($transactionQuery);
            $transactionStmt->execute([
                $material_id, $transaction_type, abs($quantity), $reference_type, $reference_id,
                $previous_stock, $current_stock, $_SESSION['user_id'], $notes
            ]);
            
            $db->commit();
            
            // Check for stock alerts
            $alerts = [];
            if ($current_stock <= $material['min_stock']) {
                $alerts[] = 'สต็อกต่ำกว่าเกณฑ์ขั้นต่ำ';
            } elseif ($current_stock > $material['max_stock']) {
                $alerts[] = 'สต็อกเกินเกณฑ์สูงสุด';
            }
            
            $response = [
                'success' => true,
                'message' => 'บันทึกรายการสำเร็จ',
                'previous_stock' => $previous_stock,
                'current_stock' => $current_stock,
                'alerts' => $alerts
            ];
            
            echo json_encode($response);
            break;
            
        case 'get_transactions':
            $material_id = $_GET['material_id'] ?? null;
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $date_from = $_GET['date_from'] ?? null;
            $date_to = $_GET['date_to'] ?? null;
            
            $offset = ($page - 1) * $limit;
            
            $where = ["1=1"];
            $params = [];
            
            if ($material_id) {
                $where[] = "it.material_id = ?";
                $params[] = $material_id;
            }
            
            if ($date_from) {
                $where[] = "DATE(it.transaction_date) >= ?";
                $params[] = $date_from;
            }
            
            if ($date_to) {
                $where[] = "DATE(it.transaction_date) <= ?";
                $params[] = $date_to;
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total 
                          FROM inventory_transactions it 
                          LEFT JOIN materials m ON it.material_id = m.material_id 
                          WHERE $whereClause";
            $countStmt = $db->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Get transactions
            $query = "SELECT it.*, m.part_code, m.material_name, m.unit, u.full_name as transaction_by_name
                      FROM inventory_transactions it
                      LEFT JOIN materials m ON it.material_id = m.material_id
                      LEFT JOIN users u ON it.transaction_by = u.user_id
                      WHERE $whereClause
                      ORDER BY it.transaction_date DESC
                      LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $transactions = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'transactions' => $transactions,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
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