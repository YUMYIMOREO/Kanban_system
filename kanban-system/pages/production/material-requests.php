<?php
// เช็คว่า session เริ่มแล้วหรือยัง
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../../config/database.php';
require_once '../../includes/functions.php';

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!in_array($_SESSION['role'], ['production', 'store', 'admin'])) {
    header("Location: ../../unauthorized.php");
    exit();
}

// แปลง role ให้ตรงกับฐานข้อมูล
$role_mapping = [
    'admin' => 'Admin',
    'planning' => 'Planning Department', 
    'production' => 'Production Department',
    'store' => 'Store Department',
    'management' => 'Management'
];

$user_role = $role_mapping[$_SESSION['role']] ?? $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// การจัดการ AJAX Requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        switch ($_POST['action']) {
            case 'create_request':
                if ($user_role !== 'Production Department') {
                    throw new Exception('ไม่มีสิทธิ์ในการสร้างคำขอเบิกวัสดุ');
                }
                
                $job_id = $_POST['job_id'];
                $materials = json_decode($_POST['materials'], true);
                
                // เริ่ม Transaction
                $conn->autocommit(FALSE);
                
                // สร้างคำขอเบิกวัสดุหลัก
                $stmt = $conn->prepare("INSERT INTO material_requests (job_id, requested_by, status, request_date) VALUES (?, ?, 'Pending', NOW())");
                $stmt->bind_param("ii", $job_id, $user_id);
                $stmt->execute();
                
                $request_id = $conn->insert_id;
                
                // เพิ่มรายการวัสดุ
                $stmt = $conn->prepare("INSERT INTO material_request_details (request_id, material_id, quantity_requested) VALUES (?, ?, ?)");
                
                foreach ($materials as $material) {
                    $stmt->bind_param("iid", $request_id, $material['material_id'], $material['quantity']);
                    $stmt->execute();
                }
                
                $conn->commit();
                
                // บันทึก Audit Log
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'CREATE_MATERIAL_REQUEST', 'material_requests', ?, NOW())");
                $log_stmt->bind_param("ii", $user_id, $request_id);
                $log_stmt->execute();
                
                echo json_encode(['success' => true, 'request_id' => $request_id]);
                break;
                
            case 'approve_request':
                if ($user_role !== 'Store Department') {
                    throw new Exception('ไม่มีสิทธิ์ในการอนุมัติคำขอ');
                }
                
                $request_id = $_POST['request_id'];
                
                // ตรวจสอบสต็อกก่อนอนุมัติ
                $check_stock = $conn->prepare("
                    SELECT mrd.material_id, mrd.quantity_requested, m.current_stock, m.material_name
                    FROM material_request_details mrd
                    JOIN materials m ON mrd.material_id = m.material_id
                    WHERE mrd.request_id = ?
                ");
                $check_stock->bind_param("i", $request_id);
                $check_stock->execute();
                $stock_result = $check_stock->get_result();
                
                $insufficient_items = [];
                while ($row = $stock_result->fetch_assoc()) {
                    if ($row['current_stock'] < $row['quantity_requested']) {
                        $insufficient_items[] = $row['material_name'] . " (ต้องการ: {$row['quantity_requested']}, คงเหลือ: {$row['current_stock']})";
                    }
                }
                
                if (!empty($insufficient_items)) {
                    throw new Exception('สต็อกไม่เพียงพอ: ' . implode(', ', $insufficient_items));
                }
                
                $conn->autocommit(FALSE);
                
                // อัพเดทสถานะเป็น Approved และหักสต็อก
                $stmt = $conn->prepare("UPDATE material_requests SET status = 'Approved', approved_by = ?, approved_date = NOW() WHERE request_id = ?");
                $stmt->bind_param("ii", $user_id, $request_id);
                $stmt->execute();
                
                // หักสต็อกและบันทึกการเคลื่อนไหว
                $items_stmt = $conn->prepare("
                    SELECT mrd.material_id, mrd.quantity_requested
                    FROM material_request_details mrd
                    WHERE mrd.request_id = ?
                ");
                $items_stmt->bind_param("i", $request_id);
                $items_stmt->execute();
                $items_result = $items_stmt->get_result();
                
                $update_stock = $conn->prepare("UPDATE materials SET current_stock = current_stock - ? WHERE material_id = ?");
                $transaction_stmt = $conn->prepare("INSERT INTO inventory_transactions (material_id, transaction_type, quantity, reference_type, reference_id, previous_stock, current_stock, transaction_by, notes, transaction_date) VALUES (?, 'out', ?, 'production', ?, ?, ?, ?, 'Material request approval', NOW())");
                
                while ($item = $items_result->fetch_assoc()) {
                    // ดึงสต็อกปัจจุบันก่อนหัก
                    $current_stock_stmt = $conn->prepare("SELECT current_stock FROM materials WHERE material_id = ?");
                    $current_stock_stmt->bind_param("i", $item['material_id']);
                    $current_stock_stmt->execute();
                    $current_stock_result = $current_stock_stmt->get_result();
                    $current_stock_row = $current_stock_result->fetch_assoc();
                    $previous_stock = $current_stock_row['current_stock'];
                    $new_stock = $previous_stock - $item['quantity_requested'];
                    
                    // หักสต็อก
                    $update_stock->bind_param("di", $item['quantity_requested'], $item['material_id']);
                    $update_stock->execute();
                    
                    // บันทึกการเคลื่อนไหว
                    $transaction_stmt->bind_param("ididiii", $item['material_id'], $item['quantity_requested'], $request_id, $previous_stock, $new_stock, $user_id);
                    $transaction_stmt->execute();
                }
                
                $conn->commit();
                
                // บันทึก Audit Log
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'APPROVE_MATERIAL_REQUEST', 'material_requests', ?, NOW())");
                $log_stmt->bind_param("ii", $user_id, $request_id);
                $log_stmt->execute();
                
                echo json_encode(['success' => true]);
                break;
                
            case 'reject_request':
                if ($user_role !== 'Store Department') {
                    throw new Exception('ไม่มีสิทธิ์ในการปฏิเสธคำขอ');
                }
                
                $request_id = $_POST['request_id'];
                $reject_reason = $_POST['reject_reason'];
                
                $stmt = $conn->prepare("UPDATE material_requests SET status = 'rejected', approved_by = ?, approved_date = NOW(), notes = ? WHERE request_id = ?");
                $stmt->bind_param("isi", $user_id, $reject_reason, $request_id);
                $stmt->execute();
                
                // บันทึก Audit Log
                $log_stmt = $conn->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at) VALUES (?, 'REJECT_MATERIAL_REQUEST', 'material_requests', ?, NOW())");
                $log_stmt->bind_param("ii", $user_id, $request_id);
                $log_stmt->execute();
                
                echo json_encode(['success' => true]);
                break;
        }
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ดึงข้อมูลคำขอเบิกวัสดุ
$where_clause = "";
$params = [];
$types = "";

// กรองตาม Role
if ($user_role === 'Production Department') {
    $where_clause = "WHERE mr.requested_by = ?";
    $params[] = $user_id;
    $types .= "i";
} elseif ($user_role === 'Store Department') {
    $where_clause = "WHERE mr.status IN ('pending', 'approved', 'rejected')";
}

// กรองตามสถานะ
if (isset($_GET['status']) && !empty($_GET['status'])) {
    $status_filter = $_GET['status'];
    if (!empty($where_clause)) {
        $where_clause .= " AND mr.status = ?";
    } else {
        $where_clause = "WHERE mr.status = ?";
    }
    $params[] = $status_filter;
    $types .= "s";
}

$sql = "
    SELECT 
        mr.request_id,
        mr.job_id,
        pj.job_number as job_name,
        mr.status,
        mr.request_date,
        mr.approved_date,
        mr.notes,
        u1.full_name as requested_by_name,
        u2.full_name as approved_by_name,
        COUNT(mrd.request_detail_id) as total_items
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN users u1 ON mr.requested_by = u1.user_id
    LEFT JOIN users u2 ON mr.approved_by = u2.user_id
    LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
    $where_clause
    GROUP BY mr.request_id
    ORDER BY mr.request_date DESC
";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$requests = $stmt->get_result();

// ดึงข้อมูล Production Jobs สำหรับ Production Department
$production_jobs = null;
if ($user_role === 'Production Department') {
    $jobs_sql = "
        SELECT pj.job_id, pj.job_name, pj.quantity_required, pj.status
        FROM production_jobs pj
        WHERE pj.status IN ('In Progress', 'Pending')
        ORDER BY pj.created_at DESC
    ";
    $jobs_result = $conn->query($jobs_sql);
    $production_jobs = $jobs_result->fetch_all(MYSQLI_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>จัดการคำขอเบิกวัสดุ - Kanban System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-badge {
            font-size: 0.85em;
            padding: 0.4em 0.8em;
        }
        .status-pending { background-color: #ffeaa7; color: #2d3436; }
        .status-approved { background-color: #00b894; color: white; }
        .status-rejected { background-color: #e17055; color: white; }
        
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border: none;
            margin-bottom: 1rem;
        }
        
        .material-item {
            background-color: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
            margin: 5px 0;
        }
        
        .action-buttons .btn {
            margin: 0 2px;
            padding: 0.25rem 0.5rem;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    <?php include '../../includes/sidebar.php'; ?>
    
    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-md-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h2><i class="fas fa-clipboard-list"></i> จัดการคำขอเบิกวัสดุ</h2>
                    
                    <div class="d-flex gap-2">
                        <!-- ตัวกรองสถานะ -->
                        <select class="form-select" id="statusFilter" style="width: 200px;">
                            <option value="">ทุกสถานะ</option>
                            <option value="pending" <?= isset($_GET['status']) && $_GET['status'] === 'pending' ? 'selected' : '' ?>>รอดำเนินการ</option>
                            <option value="approved" <?= isset($_GET['status']) && $_GET['status'] === 'approved' ? 'selected' : '' ?>>อนุมัติแล้ว</option>
                            <option value="rejected" <?= isset($_GET['status']) && $_GET['status'] === 'rejected' ? 'selected' : '' ?>>ปฏิเสธ</option>
                        </select>
                        
                        <?php if ($user_role === 'Production Department'): ?>
                        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newRequestModal">
                            <i class="fas fa-plus"></i> สร้างคำขอใหม่
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- สถิติคำขอ -->
                <div class="row mb-4">
                    <?php
                    $stats_sql = "
                        SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                            SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                            SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
                        FROM material_requests mr
                        " . ($user_role === 'Production Department' ? "WHERE requested_by = $user_id" : "");
                    
                    $stats_result = $conn->query($stats_sql);
                    $stats = $stats_result->fetch_assoc();
                    ?>
                    
                    <div class="col-md-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $stats['total'] ?></h4>
                                        <p class="mb-0">คำขอทั้งหมด</p>
                                    </div>
                                    <i class="fas fa-clipboard-list fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $stats['pending'] ?></h4>
                                        <p class="mb-0">รอดำเนินการ</p>
                                    </div>
                                    <i class="fas fa-clock fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $stats['approved'] ?></h4>
                                        <p class="mb-0">อนุมัติแล้ว</p>
                                    </div>
                                    <i class="fas fa-check-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-3">
                        <div class="card bg-danger text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4><?= $stats['rejected'] ?></h4>
                                        <p class="mb-0">ปฏิเสธ</p>
                                    </div>
                                    <i class="fas fa-times-circle fa-2x"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- รายการคำขอ -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>รหัสคำขอ</th>
                                        <th>งานผลิต</th>
                                        <th>ผู้ขอ</th>
                                        <th>จำนวนรายการ</th>
                                        <th>สถานะ</th>
                                        <th>วันที่สร้าง</th>
                                        <th>การดำเนินการ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($request = $requests->fetch_assoc()): ?>
                                    <tr>
                                        <td><strong>#<?= str_pad($request['request_id'], 6, '0', STR_PAD_LEFT) ?></strong></td>
                                        <td>
                                            <strong><?= htmlspecialchars($request['job_name'] ?? 'N/A') ?></strong><br>
                                            <small class="text-muted">Job ID: <?= $request['job_id'] ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($request['requested_by_name']) ?></td>
                                        <td><span class="badge bg-info"><?= $request['total_items'] ?> รายการ</span></td>
                                        <td>
                                            <?php
                                            $status_class = '';
                                            $status_text = '';
                                            switch ($request['status']) {
                                                case 'pending':
                                                    $status_class = 'status-pending';
                                                    $status_text = 'รอดำเนินการ';
                                                    break;
                                                case 'approved':
                                                    $status_class = 'status-approved';
                                                    $status_text = 'อนุมัติแล้ว';
                                                    break;
                                                case 'rejected':
                                                    $status_class = 'status-rejected';
                                                    $status_text = 'ปฏิเสธ';
                                                    break;
                                            }
                                            ?>
                                            <span class="badge status-badge <?= $status_class ?>"><?= $status_text ?></span>
                                        </td>
                                        <td><?= date('d/m/Y H:i', strtotime($request['request_date'])) ?></td>
                                        <td class="action-buttons">
                                            <button class="btn btn-info btn-sm" onclick="viewRequestDetail(<?= $request['request_id'] ?>)">
                                                <i class="fas fa-eye"></i> ดู
                                            </button>
                                            
                                            <?php if ($user_role === 'Store Department' && $request['status'] === 'pending'): ?>
                                                <button class="btn btn-success btn-sm" onclick="approveRequest(<?= $request['request_id'] ?>)">
                                                    <i class="fas fa-check"></i> อนุมัติ
                                                </button>
                                                <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?= $request['request_id'] ?>)">
                                                    <i class="fas fa-times"></i> ปฏิเสธ
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal สำหรับสร้างคำขอใหม่ (Production Department) -->
    <?php if ($user_role === 'Production Department'): ?>
    <div class="modal fade" id="newRequestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">สร้างคำขอเบิกวัสดุใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="newRequestForm">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">เลือกงานผลิต</label>
                            <select class="form-select" name="job_id" required>
                                <option value="">เลือกงานผลิต...</option>
                                <?php foreach ($production_jobs as $job): ?>
                                    <option value="<?= $job['job_id'] ?>"><?= htmlspecialchars($job['job_name']) ?> (จำนวน: <?= $job['quantity_required'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">รายการวัสดุที่ต้องการ</label>
                            <div id="materialsContainer">
                                <div class="material-item">
                                    <div class="row">
                                        <div class="col-md-8">
                                            <select class="form-select material-select" name="materials[0][material_id]" required>
                                                <option value="">เลือกวัสดุ...</option>
                                                <?php
                                                $materials_sql = "SELECT material_id, material_name, unit FROM materials WHERE status = 'Active' ORDER BY material_name";
                                                $materials_result = $conn->query($materials_sql);
                                                while ($material = $materials_result->fetch_assoc()):
                                                ?>
                                                    <option value="<?= $material['material_id'] ?>"><?= htmlspecialchars($material['material_name']) ?> (<?= $material['unit'] ?>)</option>
                                                <?php endwhile; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <input type="number" class="form-control" name="materials[0][quantity]" placeholder="จำนวน" min="1" step="0.01" required>
                                        </div>
                                        <div class="col-md-1">
                                            <button type="button" class="btn btn-danger btn-sm" onclick="removeMaterialItem(this)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="addMaterialItem()">
                                <i class="fas fa-plus"></i> เพิ่มวัสดุ
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">สร้างคำขอ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Modal รายละเอียดคำขอ -->
    <div class="modal fade" id="requestDetailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดคำขอเบิกวัสดุ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="requestDetailContent">
                    <!-- เนื้อหาจะถูกโหลดด้วย AJAX -->
                </div>
            </div>
        </div>
    </div>

    <!-- Modal ปฏิเสธคำขอ -->
    <div class="modal fade" id="rejectModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ปฏิเสธคำขอเบิกวัสดุ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="rejectForm">
                    <input type="hidden" id="rejectRequestId" name="request_id">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">เหตุผลในการปฏิเสธ</label>
                            <textarea class="form-control" name="reject_reason" rows="3" required placeholder="กรุณาระบุเหตุผล..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-danger">ปฏิเสธคำขอ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/main.js"></script>
    <script>
        let materialCounter = 1;

        // กรองตามสถานะ
        document.getElementById('statusFilter').addEventListener('change', function() {
            const status = this.value;
            const url = new URL(window.location);
            if (status) {
                url.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
            }
            window.location = url;
        });

        // เพิ่มรายการวัสดุ
        function addMaterialItem() {
            const container = document.getElementById('materialsContainer');
            const newItem = document.createElement('div');
            newItem.className = 'material-item';
            newItem.innerHTML = `
                <div class="row">
                    <div class="col-md-8">
                        <select class="form-select material-select" name="materials[${materialCounter}][material_id]" required>
                            <option value="">เลือกวัสดุ...</option>
                            <?php
                            $materials_result->data_seek(0);
                            while ($material = $materials_result->fetch_assoc()):
                            ?>
                                <option value="<?= $material['material_id'] ?>"><?= htmlspecialchars($material['material_name']) ?> (<?= $material['unit'] ?>)</option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <input type="number" class="form-control" name="materials[${materialCounter}][quantity]" placeholder="จำนวน" min="1" step="0.01" required>
                    </div>
                    <div class="col-md-1">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeMaterialItem(this)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newItem);
            materialCounter++;
        }

        // ลบรายการวัสดุ
        function removeMaterialItem(button) {
            const container = document.getElementById('materialsContainer');
            if (container.children.length > 1) {
                button.closest('.material-item').remove();
            }
        }

        // สร้างคำขอใหม่
        document.getElementById('newRequestForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            // รวบรวมข้อมูลวัสดุ
            const materials = [];
            const materialSelects = document.querySelectorAll('.material-select');
            const quantityInputs = document.querySelectorAll('input[name*="quantity"]');
            
            for (let i = 0; i < materialSelects.length; i++) {
                if (materialSelects[i].value && quantityInputs[i].value) {
                    materials.push({
                        material_id: materialSelects[i].value,
                        quantity: quantityInputs[i].value
                    });
                }
            }
            
            if (materials.length === 0) {
                alert('กรุณาเลือกวัสดุอย่างน้อย 1 รายการ');
                return;
            }
            
            const data = new FormData();
            data.append('action', 'create_request');
            data.append('job_id', formData.get('job_id'));
            data.append('materials', JSON.stringify(materials));
            
            fetch(window.location.href, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('สร้างคำขอเบิกวัสดุเรียบร้อยแล้ว');
                    location.reload();
                } else {
                    alert('เกิดข้อผิดพลาด: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('เกิดข้อผิดพลาดในการส่งข้อมูล');
            });
        });

        // ดูรายละเอียดคำขอ
        function viewRequestDetail(requestId) {
            fetch(`../../api/get_request_detail.php?request_id=${requestId}`)
            .then(response => response.text())
            .then(data => {
                document.getElementById('requestDetailContent').innerHTML = data;
                new bootstrap.Modal(document.getElementById('requestDetailModal')).show();
            })
            .catch(error => {
                console.error('Error:', error);
                alert('เกิดข้อผิดพลาดในการโหลดข้อมูล');
            });
        }

        // อนุมัติคำขอ
        function approveRequest(requestId) {
            if (!confirm('คุณต้องการอนุมัติคำขอเบิกวัสดุนี้ใช่หรือไม่?')) {
                return;
            }
            
            const data = new FormData();
            data.append('action', 'approve_request');
            data.append('request_id', requestId);
            
            fetch(window.location.href, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('อนุมัติคำขอเรียบร้อยแล้ว');
                    location.reload();
                } else {
                    alert('เกิดข้อผิดพลาด: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('เกิดข้อผิดพลาดในการส่งข้อมูล');
            });
        }

        // ปฏิเสธคำขอ
        function rejectRequest(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            new bootstrap.Modal(document.getElementById('rejectModal')).show();
        }

        // ส่งการปฏิเสธ
        document.getElementById('rejectForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'reject_request');
            
            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('ปฏิเสธคำขอเรียบร้อยแล้ว');
                    location.reload();
                } else {
                    alert('เกิดข้อผิดพลาด: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('เกิดข้อผิดพลาดในการส่งข้อมูล');
            });
        });

        // อัพเดทเวลาแบบ Real-time
        function updateTimeStamps() {
            const timestamps = document.querySelectorAll('[data-timestamp]');
            timestamps.forEach(element => {
                const timestamp = element.getAttribute('data-timestamp');
                const date = new Date(timestamp);
                const now = new Date();
                const diff = now - date;
                
                if (diff < 60000) { // น้อยกว่า 1 นาที
                    element.textContent = 'เมื่อสักครู่';
                } else if (diff < 3600000) { // น้อยกว่า 1 ชั่วโมง
                    element.textContent = Math.floor(diff / 60000) + ' นาทีที่แล้ว';
                } else if (diff < 86400000) { // น้อยกว่า 1 วัน
                    element.textContent = Math.floor(diff / 3600000) + ' ชั่วโมงที่แล้ว';
                }
            });
        }

        // เรียกใช้ทุก 30 วินาที
        setInterval(updateTimeStamps, 30000);

        // โหลดข้อมูลใหม่ทุก 2 นาที (สำหรับ Store Department)
        <?php if ($user_role === 'Store Department'): ?>
        setInterval(function() {
            const pendingRequests = document.querySelectorAll('.status-pending').length;
            if (pendingRequests > 0) {
                // เช็คคำขอใหม่
                fetch('../../api/check_new_requests.php')
                .then(response => response.json())
                .then(data => {
                    if (data.hasNewRequests) {
                        // แสดง notification หรือรีเฟรชหน้า
                        const notification = document.createElement('div');
                        notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
                        notification.style.top = '20px';
                        notification.style.right = '20px';
                        notification.style.zIndex = '9999';
                        notification.innerHTML = `
                            <i class="fas fa-bell"></i> มีคำขอเบิกวัสดุใหม่เข้ามา
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        `;
                        document.body.appendChild(notification);
                        
                        // Auto dismiss after 5 seconds
                        setTimeout(() => {
                            if (notification.parentNode) {
                                notification.remove();
                            }
                        }, 5000);
                    }
                })
                .catch(error => console.error('Error checking new requests:', error));
            }
        }, 120000); // 2 นาที
        <?php endif; ?>

        // เริ่มต้นการทำงาน
        document.addEventListener('DOMContentLoaded', function() {
            // ตั้งค่า tooltip
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
            
            updateTimeStamps();
        });
    </script>
    
    <?php include '../../includes/footer.php'; ?>
</body>
</html>