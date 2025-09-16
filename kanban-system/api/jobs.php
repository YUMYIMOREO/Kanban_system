<?php
// api/jobs.php
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
        case 'create':
            checkRole(['planning', 'admin']);
            
            $data = [
                'product_id' => (int)$_POST['product_id'],
                'quantity_planned' => (int)$_POST['quantity_planned'],
                'start_date' => $_POST['start_date'],
                'end_date' => $_POST['end_date'],
                'assigned_to' => (int)$_POST['assigned_to'],
                'notes' => sanitize($_POST['notes'])
            ];
            
            if ($data['quantity_planned'] <= 0) {
                throw new Exception('จำนวนที่วางแผนต้องมากกว่า 0');
            }
            
            $db->beginTransaction();
            
            // Generate job number
            $job_number = 'JOB' . date('Ymd') . sprintf('%04d', rand(1, 9999));
            
            // Create job
            $jobQuery = "INSERT INTO production_jobs (job_number, product_id, quantity_planned, start_date, end_date, assigned_to, notes, created_by) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $jobStmt = $db->prepare($jobQuery);
            $jobStmt->execute([
                $job_number, $data['product_id'], $data['quantity_planned'], 
                $data['start_date'], $data['end_date'], $data['assigned_to'], 
                $data['notes'], $_SESSION['user_id']
            ]);
            
            $job_id = $db->lastInsertId();
            
            // Calculate required materials from BOM
            $bomQuery = "SELECT bd.material_id, bd.quantity_per_unit, m.material_name, m.part_code
                        FROM bom_header bh
                        JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                        JOIN materials m ON bd.material_id = m.material_id
                        WHERE bh.product_id = ? AND bh.status = 'active'";
            $bomStmt = $db->prepare($bomQuery);
            $bomStmt->execute([$data['product_id']]);
            $bomItems = $bomStmt->fetchAll();
            
            $required_materials = [];
            foreach ($bomItems as $item) {
                $required_quantity = $item['quantity_per_unit'] * $data['quantity_planned'];
                $required_materials[] = [
                    'material_id' => $item['material_id'],
                    'part_code' => $item['part_code'],
                    'material_name' => $item['material_name'],
                    'quantity_per_unit' => $item['quantity_per_unit'],
                    'required_quantity' => $required_quantity
                ];
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'สร้างงานการผลิตสำเร็จ',
                'job_id' => $job_id,
                'job_number' => $job_number,
                'required_materials' => $required_materials
            ]);
            break;
            
        case 'get_all':
            $role = getUserRole();
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $status = $_GET['status'] ?? '';
            $assigned_to = $_GET['assigned_to'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = ["1=1"];
            $params = [];
            
            // Role-based filtering
            if ($role === 'production') {
                $where[] = "pj.assigned_to = ?";
                $params[] = $_SESSION['user_id'];
            }
            
            if (!empty($status)) {
                $where[] = "pj.status = ?";
                $params[] = $status;
            }
            
            if (!empty($assigned_to)) {
                $where[] = "pj.assigned_to = ?";
                $params[] = $assigned_to;
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Get total count
            $countQuery = "SELECT COUNT(*) as total FROM production_jobs pj WHERE $whereClause";
            $countStmt = $db->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch()['total'];
            
            // Get jobs
            $query = "SELECT pj.*, p.product_name, p.product_code, 
                             u1.full_name as created_by_name, u2.full_name as assigned_to_name
                      FROM production_jobs pj
                      LEFT JOIN products p ON pj.product_id = p.product_id
                      LEFT JOIN users u1 ON pj.created_by = u1.user_id
                      LEFT JOIN users u2 ON pj.assigned_to = u2.user_id
                      WHERE $whereClause
                      ORDER BY pj.created_at DESC
                      LIMIT $limit OFFSET $offset";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $jobs = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'jobs' => $jobs,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'update_status':
            checkRole(['production', 'admin']);
            
            $job_id = (int)$_POST['job_id'];
            $status = $_POST['status'];
            $quantity_produced = isset($_POST['quantity_produced']) ? (int)$_POST['quantity_produced'] : null;
            $notes = sanitize($_POST['notes'] ?? '');
            
            // Validate status
            $valid_statuses = ['pending', 'in_progress', 'completed', 'cancelled'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception('สถานะไม่ถูกต้อง');
            }
            
            $updateFields = ['status = ?'];
            $params = [$status];
            
            if ($quantity_produced !== null) {
                $updateFields[] = 'quantity_produced = ?';
                $params[] = $quantity_produced;
            }
            
            if (!empty($notes)) {
                $updateFields[] = 'notes = CONCAT(COALESCE(notes, ""), "\n", ?)';
                $params[] = date('Y-m-d H:i:s') . ' - ' . $_SESSION['full_name'] . ': ' . $notes;
            }
            
            $params[] = $job_id;
            
            $query = "UPDATE production_jobs SET " . implode(', ', $updateFields) . " WHERE job_id = ?";
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('ไม่พบงานที่ระบุ');
            }
            
            echo json_encode(['success' => true, 'message' => 'อัพเดทสถานะงานสำเร็จ']);
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