<?php
// เช็คว่า session เริ่มแล้วหรือยัง
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once '../config/database.php';
require_once '../includes/functions.php';

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user_id'])) {
    echo '<div class="alert alert-danger">กรุณาเข้าสู่ระบบ</div>';
    exit();
}

// ตรวจสอบสิทธิ์การเข้าถึง
if (!in_array($_SESSION['role'], ['production', 'store', 'admin', 'management'])) {
    echo '<div class="alert alert-danger">ไม่มีสิทธิ์ในการเข้าถึง</div>';
    exit();
}

if (!isset($_GET['request_id']) || empty($_GET['request_id'])) {
    echo '<div class="alert alert-danger">ไม่พบรหัสคำขอ</div>';
    exit();
}

$request_id = intval($_GET['request_id']);

try {
    // ดึงข้อมูลคำขอหลัก
    $sql = "
        SELECT 
            mr.request_id,
            mr.request_number,
            mr.job_id,
            mr.status,
            mr.request_date,
            mr.approved_date,
            mr.notes,
            pj.job_number,
            pj.product_id,
            p.product_name,
            u1.full_name as requested_by_name,
            u1.role as requested_by_role,
            u2.full_name as approved_by_name,
            u2.role as approved_by_role
        FROM material_requests mr
        LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
        LEFT JOIN products p ON pj.product_id = p.product_id
        LEFT JOIN users u1 ON mr.requested_by = u1.user_id
        LEFT JOIN users u2 ON mr.approved_by = u2.user_id
        WHERE mr.request_id = ?
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $request_result = $stmt->get_result();
    
    if ($request_result->num_rows === 0) {
        echo '<div class="alert alert-danger">ไม่พบข้อมูลคำขอ</div>';
        exit();
    }
    
    $request = $request_result->fetch_assoc();
    
    // ดึงรายละเอียดวัสดุ
    $detail_sql = "
        SELECT 
            mrd.material_id,
            mrd.quantity_requested,
            mrd.quantity_fulfilled,
            m.part_code,
            m.material_name,
            m.unit,
            m.current_stock,
            m.min_stock,
            m.location
        FROM material_request_details mrd
        JOIN materials m ON mrd.material_id = m.material_id
        WHERE mrd.request_id = ?
        ORDER BY m.material_name
    ";
    
    $detail_stmt = $conn->prepare($detail_sql);
    $detail_stmt->bind_param("i", $request_id);
    $detail_stmt->execute();
    $details_result = $detail_stmt->get_result();
    
    // กำหนดสี status
    $status_class = '';
    $status_text = '';
    $status_icon = '';
    switch ($request['status']) {
        case 'pending':
            $status_class = 'warning';
            $status_text = 'รอดำเนินการ';
            $status_icon = 'fas fa-clock';
            break;
        case 'approved':
            $status_class = 'success';
            $status_text = 'อนุมัติแล้ว';
            $status_icon = 'fas fa-check-circle';
            break;
        case 'rejected':
            $status_class = 'danger';
            $status_text = 'ปฏิเสธ';
            $status_icon = 'fas fa-times-circle';
            break;
        case 'fulfilled':
            $status_class = 'info';
            $status_text = 'จ่ายแล้ว';
            $status_icon = 'fas fa-box';
            break;
    }
    
    ?>
    
    <div class="container-fluid">
        <!-- Header ข้อมูลคำขอ -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h5><i class="fas fa-clipboard-list"></i> คำขอเบิกวัสดุ #<?= str_pad($request['request_id'], 6, '0', STR_PAD_LEFT) ?></h5>
                <div class="mb-3">
                    <span class="badge bg-<?= $status_class ?> fs-6">
                        <i class="<?= $status_icon ?>"></i> <?= $status_text ?>
                    </span>
                </div>
            </div>
            <div class="col-md-6 text-end">
                <small class="text-muted">
                    วันที่สร้าง: <?= date('d/m/Y H:i', strtotime($request['request_date'])) ?><br>
                    <?php if ($request['approved_date']): ?>
                        วันที่อนุมัติ: <?= date('d/m/Y H:i', strtotime($request['approved_date'])) ?>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        
        <!-- ข้อมูลงานผลิต -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h6 class="mb-0"><i class="fas fa-cogs"></i> ข้อมูลงานผลิต</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <strong>รหัสงาน:</strong><br>
                        <span class="text-primary"><?= htmlspecialchars($request['job_number'] ?? 'N/A') ?></span>
                    </div>
                    <div class="col-md-4">
                        <strong>สินค้า:</strong><br>
                        <?= htmlspecialchars($request['product_name'] ?? 'N/A') ?>
                    </div>
                    <div class="col-md-4">
                        <strong>ผู้ขอ:</strong><br>
                        <?= htmlspecialchars($request['requested_by_name']) ?>
                        <span class="badge bg-secondary"><?= ucfirst($request['requested_by_role']) ?></span>
                    </div>
                </div>
                
                <?php if ($request['approved_by_name']): ?>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <strong>ผู้อนุมัติ:</strong><br>
                        <?= htmlspecialchars($request['approved_by_name']) ?>
                        <span class="badge bg-info"><?= ucfirst($request['approved_by_role']) ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($request['notes']): ?>
                <hr>
                <div class="row">
                    <div class="col-12">
                        <strong>หมายเหตุ:</strong><br>
                        <div class="alert alert-info"><?= nl2br(htmlspecialchars($request['notes'])) ?></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- รายการวัสดุที่ขอเบิก -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h6 class="mb-0"><i class="fas fa-boxes"></i> รายการวัสดุที่ขอเบิก</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>รหัสชิ้นส่วน</th>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวนที่ขอ</th>
                                <th>สต็อกคงเหลือ</th>
                                <th>สถานที่เก็บ</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_items = 0;
                            while ($detail = $details_result->fetch_assoc()): 
                                $total_items++;
                                
                                // ตรวจสอบสถานะสต็อก
                                $stock_status = '';
                                $stock_class = '';
                                if ($detail['current_stock'] >= $detail['quantity_requested']) {
                                    $stock_status = 'พอเพียง';
                                    $stock_class = 'success';
                                } elseif ($detail['current_stock'] > 0) {
                                    $stock_status = 'ไม่เพียงพอ';
                                    $stock_class = 'warning';
                                } else {
                                    $stock_status = 'หมด';
                                    $stock_class = 'danger';
                                }
                                
                                // ตรวจสอบระดับสต็อกต่ำ
                                $low_stock_warning = '';
                                if ($detail['current_stock'] <= $detail['min_stock']) {
                                    $low_stock_warning = '<i class="fas fa-exclamation-triangle text-warning" title="สต็อกต่ำกว่าเกณฑ์"></i>';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($detail['part_code']) ?></strong>
                                </td>
                                <td>
                                    <?= htmlspecialchars($detail['material_name']) ?>
                                    <br><small class="text-muted">หน่วย: <?= htmlspecialchars($detail['unit']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-primary"><?= number_format($detail['quantity_requested'], 2) ?></span>
                                </td>
                                <td>
                                    <?= number_format($detail['current_stock']) ?> 
                                    <?= $low_stock_warning ?>
                                    <br><small class="text-muted">ขั้นต่ำ: <?= number_format($detail['min_stock']) ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-secondary"><?= htmlspecialchars($detail['location'] ?? 'N/A') ?></span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $stock_class ?>"><?= $stock_status ?></span>
                                    <?php if ($detail['quantity_fulfilled'] > 0): ?>
                                        <br><small class="text-success">จ่ายแล้ว: <?= number_format($detail['quantity_fulfilled'], 2) ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <th colspan="6">
                                    <strong>รวมทั้งหมด: <?= $total_items ?> รายการ</strong>
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ข้อมูลเพิ่มเติมสำหรับ Store Department -->
        <?php if ($_SESSION['role'] === 'store' && $request['status'] === 'pending'): ?>
        <div class="card mt-4">
            <div class="card-header bg-warning">
                <h6 class="mb-0"><i class="fas fa-exclamation-circle"></i> การตรวจสอบก่อนอนุมัติ</h6>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <strong>คำแนะนำ:</strong>
                    <ul class="mb-0">
                        <li>ตรวจสอบสต็อกคงเหลือของแต่ละรายการ</li>
                        <li>ยืนยันว่าสินค้าอยู่ในสถานที่ที่ระบุ</li>
                        <li>หากสต็อกไม่เพียงพอ ควรติดต่อแผนกวางแผนเพื่อสั่งซื้อเพิ่ม</li>
                        <li>ตรวจสอบคุณภาพของวัสดุก่อนจ่าย</li>
                    </ul>
                </div>
                
                <?php
                // ตรวจสอบรายการที่สต็อกไม่พอ
                $details_result->data_seek(0); // รีเซ็ต cursor
                $insufficient_items = [];
                while ($detail = $details_result->fetch_assoc()) {
                    if ($detail['current_stock'] < $detail['quantity_requested']) {
                        $insufficient_items[] = $detail['material_name'] . " (ต้องการ: {$detail['quantity_requested']}, คงเหลือ: {$detail['current_stock']})";
                    }
                }
                
                if (!empty($insufficient_items)): ?>
                <div class="alert alert-danger">
                    <strong><i class="fas fa-exclamation-triangle"></i> รายการที่สต็อกไม่เพียงพอ:</strong>
                    <ul class="mb-0 mt-2">
                        <?php foreach ($insufficient_items as $item): ?>
                            <li><?= htmlspecialchars($item) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> สต็อกทุกรายการเพียงพอสำหรับการอนุมัติ
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Timeline การดำเนินการ -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="fas fa-history"></i> ประวัติการดำเนินการ</h6>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-marker bg-primary"></div>
                        <div class="timeline-content">
                            <h6 class="timeline-title">สร้างคำขอเบิกวัสดุ</h6>
                            <p class="timeline-text">
                                โดย: <?= htmlspecialchars($request['requested_by_name']) ?><br>
                                วันที่: <?= date('d/m/Y H:i', strtotime($request['request_date'])) ?>
                            </p>
                        </div>
                    </div>
                    
                    <?php if ($request['approved_date']): ?>
                    <div class="timeline-item">
                        <div class="timeline-marker bg-<?= $status_class ?>"></div>
                        <div class="timeline-content">
                            <h6 class="timeline-title"><?= $status_text ?></h6>
                            <p class="timeline-text">
                                โดย: <?= htmlspecialchars($request['approved_by_name']) ?><br>
                                วันที่: <?= date('d/m/Y H:i', strtotime($request['approved_date'])) ?>
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <style>
    .timeline {
        position: relative;
        padding-left: 30px;
    }
    
    .timeline::before {
        content: '';
        position: absolute;
        left: 10px;
        top: 0;
        bottom: 0;
        width: 2px;
        background: #e9ecef;
    }
    
    .timeline-item {
        position: relative;
        margin-bottom: 20px;
    }
    
    .timeline-marker {
        position: absolute;
        left: -25px;
        top: 5px;
        width: 12px;
        height: 12px;
        border-radius: 50%;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px #e9ecef;
    }
    
    .timeline-content {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        border-left: 3px solid #007bff;
    }
    
    .timeline-title {
        margin-bottom: 5px;
        color: #495057;
    }
    
    .timeline-text {
        margin: 0;
        color: #6c757d;
        font-size: 0.9rem;
    }
    </style>
    
    <?php
    
} catch (Exception $e) {
    echo '<div class="alert alert-danger">เกิดข้อผิดพลาด: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
?>