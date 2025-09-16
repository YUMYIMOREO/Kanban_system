<?php
// pages/store/dashboard.php
$page_title = 'แดชบอร์ดแผนกคลัง';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสถิติคลัง
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM materials WHERE status = 'active') as total_materials,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_count,
        (SELECT COUNT(*) FROM materials WHERE current_stock > max_stock AND status = 'active') as overstock_count,
        (SELECT COUNT(*) FROM material_requests WHERE status = 'pending') as pending_requests,
        (SELECT SUM(current_stock) FROM materials WHERE status = 'active') as total_stock_value
")->fetch();

// คำขอเบิกวัสดุรอการอนุมัติ
$pending_requests = $db->query("
    SELECT mr.*, pj.job_number, u.full_name as requested_by_name, p.product_name
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN users u ON mr.requested_by = u.user_id
    LEFT JOIN products p ON pj.product_id = p.product_id
    WHERE mr.status = 'pending'
    ORDER BY mr.request_date ASC
    LIMIT 10
")->fetchAll();

// วัสดุที่ต้องแจ้งเตือน
$alert_materials = $db->query("
    SELECT *, 
        CASE 
            WHEN current_stock <= min_stock THEN 'low'
            WHEN current_stock > max_stock THEN 'overstock'
        END as alert_type
    FROM materials 
    WHERE (current_stock <= min_stock OR current_stock > max_stock) 
        AND status = 'active'
    ORDER BY 
        CASE WHEN current_stock <= min_stock THEN current_stock/min_stock ELSE current_stock/max_stock END ASC
    LIMIT 10
")->fetchAll();

// รายการเคลื่อนไหววัสดุล่าสุด
$recent_transactions = $db->query("
    SELECT it.*, m.material_name, m.part_code, u.full_name as transaction_by_name
    FROM inventory_transactions it
    LEFT JOIN materials m ON it.material_id = m.material_id
    LEFT JOIN users u ON it.transaction_by = u.user_id
    ORDER BY it.transaction_date DESC
    LIMIT 15
")->fetchAll();

// ข้อมูลสำหรับกราฟ
$stock_levels = $db->query("
    SELECT 
        material_name,
        part_code,
        current_stock,
        min_stock,
        max_stock
    FROM materials 
    WHERE status = 'active'
    ORDER BY current_stock DESC
    LIMIT 10
")->fetchAll();
?>

            <div class="row">
                <!-- Stats Cards -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-boxes icon"></i>
                        <div class="number"><?= number_format($stats['total_materials']) ?></div>
                        <div class="label">วัสดุทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-exclamation-triangle icon"></i>
                        <div class="number"><?= number_format($stats['low_stock_count']) ?></div>
                        <div class="label">สต็อกต่ำ</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
                        <i class="fas fa-arrow-up icon"></i>
                        <div class="number"><?= number_format($stats['overstock_count']) ?></div>
                        <div class="label">สต็อกเกิน</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-hand-paper icon"></i>
                        <div class="number"><?= number_format($stats['pending_requests']) ?></div>
                        <div class="label">คำขอรอพิจารณา</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- Quick Actions -->
                <div class="col-12 mb-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i>การดำเนินการด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <a href="qr-scanner.php" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-qrcode fa-2x d-block mb-2"></i>
                                        สแกน QR Code
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="material-in.php" class="btn btn-success btn-lg w-100">
                                        <i class="fas fa-arrow-down fa-2x d-block mb-2"></i>
                                        รับวัสดุเข้า
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="material-out.php" class="btn btn-warning btn-lg w-100">
                                        <i class="fas fa-arrow-up fa-2x d-block mb-2"></i>
                                        จ่ายวัสดุออก
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="inventory.php" class="btn btn-info btn-lg w-100">
                                        <i class="fas fa-warehouse fa-2x d-block mb-2"></i>
                                        ตรวจสอบคลัง
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- คำขอเบิกวัสดุ -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-hand-paper me-2"></i>คำขอเบิกวัสดุรอการอนุมัติ</h5>
                            <span class="badge bg-warning"><?= count($pending_requests) ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($pending_requests)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>เลขที่คำขอ</th>
                                                <th>Job</th>
                                                <th>สินค้า</th>
                                                <th>ผู้ขอ</th>
                                                <th>วันที่</th>
                                                <th>การกระทำ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($pending_requests as $request): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($request['request_number']) ?></strong></td>
                                                    <td>
                                                        <span class="badge bg-info">
                                                            <?= htmlspecialchars($request['job_number']) ?>
                                                        </span>
                                                    </td>
                                                    <td><?= htmlspecialchars($request['product_name']) ?></td>
                                                    <td><?= htmlspecialchars($request['requested_by_name']) ?></td>
                                                    <td><?= date('d/m/Y H:i', strtotime($request['request_date'])) ?></td>
                                                    <td>
                                                        <div class="btn-group" role="group">
                                                            <button class="btn btn-success btn-sm" onclick="approveRequest(<?= $request['request_id'] ?>)">
                                                                <i class="fas fa-check"></i>
                                                            </button>
                                                            <button class="btn btn-info btn-sm" onclick="viewRequest(<?= $request['request_id'] ?>)">
                                                                <i class="fas fa-eye"></i>
                                                            </button>
                                                            <button class="btn btn-danger btn-sm" onclick="rejectRequest(<?= $request['request_id'] ?>)">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="material-requests.php" class="btn btn-primary">
                                        <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-0">ไม่มีคำขอเบิกวัสดุรอการอนุมัติ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- การแจ้งเตือนสต็อก -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bell me-2 text-warning"></i>การแจ้งเตือนสต็อก</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($alert_materials)): ?>
                                    <?php foreach ($alert_materials as $material): ?>
                                        <div class="alert <?= $material['alert_type'] == 'low' ? 'alert-danger' : 'alert-warning' ?> py-2 mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars($material['part_code']) ?></strong><br>
                                                    <small><?= htmlspecialchars($material['material_name']) ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge <?= $material['alert_type'] == 'low' ? 'bg-danger' : 'bg-warning' ?>">
                                                        <?= number_format($material['current_stock']) ?>
                                                    </span><br>
                                                    <small class="text-muted">
                                                        <?= $material['alert_type'] == 'low' ? 'ต่ำกว่า' : 'เกินกว่า' ?>
                                                        <?= number_format($material['alert_type'] == 'low' ? $material['min_stock'] : $material['max_stock']) ?>
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-center">
                                        <a href="stock-alerts.php" class="btn btn-warning btn-sm">
                                            <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                        <p class="text-success mb-0">สต็อกทุกรายการอยู่ในระดับปกติ</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- กราฟระดับสต็อก -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar me-2"></i>ระดับสต็อกวัสดุ Top 10</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="stockChart" height="80"></canvas>
                        </div>
                    </div>
                </div>

                <!-- รายการเคลื่อนไหวล่าสุด -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-exchange-alt me-2"></i>การเคลื่อนไหวล่าสุด</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($recent_transactions)): ?>
                                    <?php foreach ($recent_transactions as $transaction): ?>
                                        <div class="d-flex mb-3 pb-2 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm <?= $transaction['transaction_type'] == 'in' ? 'bg-success' : ($transaction['transaction_type'] == 'out' ? 'bg-danger' : 'bg-warning') ?> text-white rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-<?= $transaction['transaction_type'] == 'in' ? 'arrow-down' : ($transaction['transaction_type'] == 'out' ? 'arrow-up' : 'edit') ?>"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($transaction['part_code']) ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    <?= $transaction['transaction_type'] == 'in' ? 'รับเข้า' : ($transaction['transaction_type'] == 'out' ? 'จ่ายออก' : 'ปรับปรุง') ?>
                                                    <strong><?= number_format($transaction['quantity']) ?></strong>
                                                    <?= $transaction['transaction_type'] == 'in' ? '+' : ($transaction['transaction_type'] == 'out' ? '-' : '±') ?><?= number_format($transaction['quantity']) ?>
                                                </p>
                                                <small class="text-muted">
                                                    โดย <?= htmlspecialchars($transaction['transaction_by_name']) ?><br>
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?= date('d/m/Y H:i', strtotime($transaction['transaction_date'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-center">
                                        <a href="inventory.php?tab=transactions" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-4">ไม่มีการเคลื่อนไหววัสดุ</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Material Request Modal -->
    <div class="modal fade" id="requestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดคำขอเบิกวัสดุ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="requestDetails">
                        <!-- Request details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-danger" id="rejectBtn">ปฏิเสธ</button>
                    <button type="button" class="btn btn-success" id="approveBtn">อนุมัติ</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // กราฟแสดงระดับสต็อก
        const stockData = <?= json_encode($stock_levels) ?>;
        const ctx = document.getElementById('stockChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: stockData.map(item => item.part_code),
                datasets: [{
                    label: 'สต็อกปัจจุบัน',
                    data: stockData.map(item => item.current_stock),
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: 'rgba(102, 126, 234, 1)',
                    borderWidth: 1
                }, {
                    label: 'สต็อกต่ำสุด',
                    data: stockData.map(item => item.min_stock),
                    backgroundColor: 'rgba(220, 53, 69, 0.3)',
                    borderColor: 'rgba(220, 53, 69, 1)',
                    borderWidth: 2,
                    type: 'line'
                }, {
                    label: 'สต็อกสูงสุด',
                    data: stockData.map(item => item.max_stock),
                    backgroundColor: 'rgba(255, 193, 7, 0.3)',
                    borderColor: 'rgba(255, 193, 7, 1)',
                    borderWidth: 2,
                    type: 'line'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Functions for material request actions
        function viewRequest(requestId) {
            fetch(`../../api/material-requests.php?action=get&id=${requestId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('requestDetails').innerHTML = formatRequestDetails(data.data);
                        document.getElementById('approveBtn').onclick = () => approveRequest(requestId);
                        document.getElementById('rejectBtn').onclick = () => rejectRequest(requestId);
                        new bootstrap.Modal(document.getElementById('requestModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดข้อมูลได้', 'error');
                });
        }

        function approveRequest(requestId) {
            Swal.fire({
                title: 'ยืนยันการอนุมัติ?',
                text: 'คุณต้องการอนุมัติคำขอเบิกวัสดุนี้หรือไม่?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'อนุมัติ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    processRequest(requestId, 'approve');
                }
            });
        }

        function rejectRequest(requestId) {
            Swal.fire({
                title: 'ยืนยันการปฏิเสธ?',
                text: 'คุณต้องการปฏิเสธคำขอเบิกวัสดุนี้หรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ปฏิเสธ',
                cancelButtonText: 'ยกเลิก',
                input: 'textarea',
                inputPlaceholder: 'ระบุเหตุผล (ไม่บังคับ)',
            }).then((result) => {
                if (result.isConfirmed) {
                    processRequest(requestId, 'reject', result.value);
                }
            });
        }

        function processRequest(requestId, action, reason = '') {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('request_id', requestId);
            formData.append('reason', reason);

            fetch('../../api/material-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถดำเนินการได้', 'error');
            });
        }

        function formatRequestDetails(request) {
            return `
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>เลขที่คำขอ:</strong> ${request.request_number}<br>
                        <strong>Job:</strong> ${request.job_number}<br>
                        <strong>สินค้า:</strong> ${request.product_name}
                    </div>
                    <div class="col-md-6">
                        <strong>ผู้ขอ:</strong> ${request.requested_by_name}<br>
                        <strong>วันที่:</strong> ${new Date(request.request_date).toLocaleDateString('th-TH')}<br>
                        <strong>สถานะ:</strong> <span class="badge bg-warning">รอการอนุมัติ</span>
                    </div>
                </div>
                <h6>รายการวัสดุที่ขอเบิก:</h6>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th>จำนวนที่ขอ</th>
                                <th>คงเหลือ</th>
                                <th>สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${request.details.map(detail => `
                                <tr>
                                    <td>${detail.part_code}</td>
                                    <td>${detail.material_name}</td>
                                    <td>${detail.quantity_requested}</td>
                                    <td>${detail.current_stock}</td>
                                    <td>
                                        ${detail.current_stock >= detail.quantity_requested 
                                            ? '<span class="badge bg-success">เพียงพอ</span>' 
                                            : '<span class="badge bg-danger">ไม่เพียงพอ</span>'}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
                ${request.notes ? `<div class="mt-3"><strong>หมายเหตุ:</strong> ${request.notes}</div>` : ''}
            `;
        }

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 60 seconds
        setInterval(() => {
            location.reload();
        }, 60000);

        // Initialize tooltips
        document.addEventListener('DOMContentLoaded', function() {
            var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
            var tooltipList = tooltipTriggerList.map(function(tooltipTriggerEl) {
                return new bootstrap.Tooltip(tooltipTriggerEl);
            });
        });
    </script>

</body>
</html>