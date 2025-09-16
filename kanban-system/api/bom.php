<?php
// api/bom.php
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

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'calculate':
            $product_id = (int)$_GET['product_id'];
            $quantity = (int)$_GET['quantity'];
            
            if ($product_id <= 0 || $quantity <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // ดึงข้อมูล BOM
            $bom_query = "
                SELECT bh.bom_id, bd.*, m.part_code, m.material_name, m.unit, m.current_stock, m.min_stock
                FROM bom_header bh
                JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bh.product_id = ? AND bh.status = 'active' AND m.status = 'active'
                ORDER BY m.part_code
            ";
            
            $stmt = $db->prepare($bom_query);
            $stmt->execute([$product_id]);
            $bom_items = $stmt->fetchAll();
            
            if (empty($bom_items)) {
                throw new Exception('ไม่พบ BOM สำหรับสินค้านี้');
            }
            
            $materials = [];
            foreach ($bom_items as $item) {
                $required_quantity = $item['quantity_per_unit'] * $quantity;
                
                $materials[] = [
                    'material_id' => $item['material_id'],
                    'part_code' => $item['part_code'],
                    'material_name' => $item['material_name'],
                    'unit' => $item['unit'],
                    'quantity_per_unit' => (float)$item['quantity_per_unit'],
                    'required_quantity' => $required_quantity,
                    'current_stock' => (int)$item['current_stock'],
                    'min_stock' => (int)$item['min_stock'],
                    'sufficient' => $item['current_stock'] >= $required_quantity
                ];
            }
            
            echo json_encode([
                'success' => true,
                'materials' => $materials,
                'total_items' => count($materials),
                'calculation_date' => date('Y-m-d H:i:s')
            ]);
            break;
            
        case 'get_bom':
            $product_id = (int)$_GET['product_id'];
            
            $bom_query = "
                SELECT bh.*, p.product_name, p.product_code
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                WHERE bh.product_id = ? AND bh.status = 'active'
                ORDER BY bh.version DESC
                LIMIT 1
            ";
            
            $stmt = $db->prepare($bom_query);
            $stmt->execute([$product_id]);
            $bom = $stmt->fetch();
            
            if (!$bom) {
                throw new Exception('ไม่พบ BOM สำหรับสินค้านี้');
            }
            
            // ดึงรายละเอียด BOM
            $detail_query = "
                SELECT bd.*, m.part_code, m.material_name, m.unit, m.current_stock
                FROM bom_detail bd
                JOIN materials m ON bd.material_id = m.material_id
                WHERE bd.bom_id = ?
                ORDER BY m.part_code
            ";
            
            $stmt = $db->prepare($detail_query);
            $stmt->execute([$bom['bom_id']]);
            $bom['details'] = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'bom' => $bom]);
            break;
            
        case 'create':
            checkRole(['admin', 'planning']);
            
            $product_id = (int)$_POST['product_id'];
            $version = sanitize($_POST['version'] ?? '1.0');
            $materials = json_decode($_POST['materials'], true);
            
            if (empty($materials)) {
                throw new Exception('กรุณาเพิ่มวัสดุในรายการ BOM');
            }
            
            $db->beginTransaction();
            
            // สร้าง BOM Header
            $header_query = "INSERT INTO bom_header (product_id, version, created_by) VALUES (?, ?, ?)";
            $stmt = $db->prepare($header_query);
            $stmt->execute([$product_id, $version, $_SESSION['user_id']]);
            $bom_id = $db->lastInsertId();
            
            // สร้าง BOM Details
            foreach ($materials as $material) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $bom_id,
                    $material['material_id'],
                    $material['quantity_per_unit'],
                    $material['unit']
                ]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'สร้าง BOM เรียบร้อยแล้ว', 'bom_id' => $bom_id]);
            break;
            
        case 'update':
            checkRole(['admin', 'planning']);
            
            $bom_id = (int)$_POST['bom_id'];
            $materials = json_decode($_POST['materials'], true);
            
            $db->beginTransaction();
            
            // ลบรายการเดิม
            $delete_query = "DELETE FROM bom_detail WHERE bom_id = ?";
            $stmt = $db->prepare($delete_query);
            $stmt->execute([$bom_id]);
            
            // เพิ่มรายการใหม่
            foreach ($materials as $material) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $bom_id,
                    $material['material_id'],
                    $material['quantity_per_unit'],
                    $material['unit']
                ]);
            }
            
            // อัพเดทวันที่แก้ไข
            $update_query = "UPDATE bom_header SET updated_at = CURRENT_TIMESTAMP WHERE bom_id = ?";
            $stmt = $db->prepare($update_query);
            $stmt->execute([$bom_id]);
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'อัพเดท BOM เรียบร้อยแล้ว']);
            break;
            
        case 'get_all':
            $page = (int)($_GET['page'] ?? 1);
            $limit = (int)($_GET['limit'] ?? 20);
            $search = $_GET['search'] ?? '';
            
            $offset = ($page - 1) * $limit;
            
            $where = ["bh.status = 'active'"];
            $params = [];
            
            if (!empty($search)) {
                $where[] = "(p.product_name LIKE ? OR p.product_code LIKE ?)";
                $params[] = "%$search%";
                $params[] = "%$search%";
            }
            
            $whereClause = implode(' AND ', $where);
            
            // Count total
            $count_query = "
                SELECT COUNT(DISTINCT bh.bom_id) as total
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                WHERE $whereClause
            ";
            $stmt = $db->prepare($count_query);
            $stmt->execute($params);
            $total = $stmt->fetch()['total'];
            
            // Get BOMs
            $query = "
                SELECT bh.*, p.product_name, p.product_code, u.full_name as created_by_name,
                       COUNT(bd.bom_detail_id) as material_count
                FROM bom_header bh
                JOIN products p ON bh.product_id = p.product_id
                LEFT JOIN users u ON bh.created_by = u.user_id
                LEFT JOIN bom_detail bd ON bh.bom_id = bd.bom_id
                WHERE $whereClause
                GROUP BY bh.bom_id
                ORDER BY bh.created_at DESC
                LIMIT $limit OFFSET $offset
            ";
            
            $stmt = $db->prepare($query);
            $stmt->execute($params);
            $boms = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'boms' => $boms,
                'pagination' => [
                    'total' => (int)$total,
                    'page' => $page,
                    'limit' => $limit,
                    'pages' => ceil($total / $limit)
                ]
            ]);
            break;
            
        case 'copy':
            checkRole(['admin', 'planning']);
            
            $source_bom_id = (int)$_POST['source_bom_id'];
            $target_product_id = (int)$_POST['target_product_id'];
            $new_version = sanitize($_POST['new_version'] ?? '1.0');
            
            $db->beginTransaction();
            
            // ดึงข้อมูล BOM ต้นฉบับ
            $source_query = "SELECT * FROM bom_detail WHERE bom_id = ?";
            $stmt = $db->prepare($source_query);
            $stmt->execute([$source_bom_id]);
            $source_details = $stmt->fetchAll();
            
            if (empty($source_details)) {
                throw new Exception('ไม่พบข้อมูล BOM ต้นฉบับ');
            }
            
            // สร้าง BOM ใหม่
            $header_query = "INSERT INTO bom_header (product_id, version, created_by) VALUES (?, ?, ?)";
            $stmt = $db->prepare($header_query);
            $stmt->execute([$target_product_id, $new_version, $_SESSION['user_id']]);
            $new_bom_id = $db->lastInsertId();
            
            // คัดลอกรายการวัสดุ
            foreach ($source_details as $detail) {
                $detail_query = "INSERT INTO bom_detail (bom_id, material_id, quantity_per_unit, unit) VALUES (?, ?, ?, ?)";
                $stmt = $db->prepare($detail_query);
                $stmt->execute([
                    $new_bom_id,
                    $detail['material_id'],
                    $detail['quantity_per_unit'],
                    $detail['unit']
                ]);
            }
            
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'คัดลอก BOM เรียบร้อยแล้ว', 'new_bom_id' => $new_bom_id]);
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