<?php
// pages/admin/dashboard.php
$page_title = 'แดshบอร์ดผู้ดูแลระบบ';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดshบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสถิติ
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM users WHERE status = 'active') as total_users,
        (SELECT COUNT(*) FROM materials WHERE status = 'active') as total_materials,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_materials,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'in_progress') as active_jobs,
        (SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending') as pending_prs
";

$stats = $db->query($stats_query)->fetch();

// ดึงข้อมูลวัสดุที่สต็อกต่ำ
$low_stock_query = "
    SELECT material_name, part_code, current_stock, min_stock, 
           ROUND(((current_stock / min_stock) * 100), 2) as stock_percentage
    FROM materials 
    WHERE current_stock <= min_stock AND status = 'active'
    ORDER BY stock_percentage ASC
    LIMIT 10
";
$low_stock_materials = $db->query($low_stock_query)->fetchAll();

// ดึงข้อมูลการใช้งานล่าสุด
$recent_activities = $db->query("
    SELECT al.*, u.full_name, u.role 
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    ORDER BY al.created_at DESC
    LIMIT 10
")->fetchAll();

// ดึงข้อมูลสำหรับกราฟ
$monthly_production = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as job_count,
        SUM(quantity_planned) as total_planned,
        SUM(quantity_produced) as total_produced
    FROM production_jobs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();
?>

            <div class="row">
                <!-- Stats Cards -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-users icon"></i>
                        <div class="number"><?= number_format($stats['total_users']) ?></div>
                        <div class="label">ผู้ใช้งานทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-boxes icon"></i>
                        <div class="number"><?= number_format($stats['total_materials']) ?></div>
                        <div class="label">วัสดุทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #dc3545, #fd7e14);">
                        <i class="fas fa-exclamation-triangle icon"></i>
                        <div class="number"><?= number_format($stats['low_stock_materials']) ?></div>
                        <div class="label">วัสดุสต็อกต่ำ</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-tasks icon"></i>
                        <div class="number"><?= number_format($stats['active_jobs']) ?></div>
                        <div class="label">งานกำลังผลิต</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- กราฎสถิติการผลิต -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line me-2"></i>สถิติการผลิต 6 เดือนย้อนหลัง</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="productionChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- คำขอซื้อรอพิจารณา -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-shopping-cart me-2"></i>คำขอซื้อรอพิจารณา</h5>
                            <span class="badge bg-warning"><?= $stats['pending_prs'] ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <?php if ($stats['pending_prs'] > 0): ?>
                                <div class="alert alert-warning">
                                    <i class="fas fa-clock me-2"></i>
                                    มี <strong><?= $stats['pending_prs'] ?></strong> คำขอซื้อรอการอนุมัติ
                                </div>
                                <a href="purchase-requests.php" class="btn btn-warning btn-sm">
                                    <i class="fas fa-eye me-1"></i>ดูรายละเอียด
                                </a>
                            <?php else: ?>
                                <p class="text-muted mb-0">ไม่มีคำขอซื้อรอพิจารณา</p>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="card mt-3">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i>การกระทำด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="users.php" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-user-plus me-1"></i>เพิ่มผู้ใช้ใหม่
                                </a>
                                <a href="materials.php" class="btn btn-outline-success btn-sm">
                                    <i class="fas fa-plus me-1"></i>เพิ่มวัสดุใหม่
                                </a>
                                <a href="bom.php" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-list-alt me-1"></i>จัดการ BOM
                                </a>
                                <a href="audit-logs.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-history me-1"></i>ดูประวัติระบบ
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- วัสดุที่สต็อกต่ำ -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-exclamation-triangle me-2 text-danger"></i>วัสดุที่สต็อกต่ำ</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($low_stock_materials)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>รหัสวัสดุ</th>
                                                <th>ชื่อวัสดุ</th>
                                                <th>คงเหลือ</th>
                                                <th>สถานะ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($low_stock_materials as $material): ?>
                                                <tr>
                                                    <td><strong><?= htmlspecialchars($material['part_code']) ?></strong></td>
                                                    <td><?= htmlspecialchars($material['material_name']) ?></td>
                                                    <td>
                                                        <span class="badge bg-danger">
                                                            <?= number_format($material['current_stock']) ?>
                                                        </span>
                                                        / <?= number_format($material['min_stock']) ?>
                                                    </td>
                                                    <td>
                                                        <div class="progress" style="height: 6px;">
                                                            <div class="progress-bar bg-danger" style="width: <?= min($material['stock_percentage'], 100) ?>%"></div>
                                                        </div>
                                                        <small class="text-danger"><?= $material['stock_percentage'] ?>%</small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="materials.php?filter=low_stock" class="btn btn-danger btn-sm">
                                        <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <p class="text-success mb-0">วัสดุทุกรายการมีสต็อกเพียงพอ</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- กิจกรรมล่าสุด -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-history me-2"></i>กิจกรรมล่าสุด</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 400px; overflow-y: auto;">
                                <?php if (!empty($recent_activities)): ?>
                                    <?php foreach ($recent_activities as $activity): ?>
                                        <div class="d-flex mb-3 pb-3 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm bg-primary text-white rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($activity['full_name'] ?? 'ระบบ') ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    <?= htmlspecialchars($activity['action']) ?>
                                                    <?php if ($activity['table_name']): ?>
                                                        ใน <?= htmlspecialchars($activity['table_name']) ?>
                                                    <?php endif; ?>
                                                </p>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?= date('d/m/Y H:i', strtotime($activity['created_at'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-center">
                                        <a href="audit-logs.php" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-4">ไม่มีกิจกรรมล่าสุด</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // กราฟสถิติการผลิต
        const ctx = document.getElementById('productionChart').getContext('2d');
        const productionData = <?= json_encode($monthly_production) ?>;
        
        const monthLabels = productionData.map(item => {
            const [year, month] = item.month.split('-');
            const monthNames = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
            return monthNames[parseInt(month) - 1] + ' ' + year;
        });
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'งานที่สร้าง',
                    data: productionData.map(item => item.job_count),
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4
                }, {
                    label: 'จำนวนที่วางแผน',
                    data: productionData.map(item => item.total_planned),
                    borderColor: 'rgb(118, 75, 162)',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    tension: 0.4
                }, {
                    label: 'จำนวนที่ผลิตจริง',
                    data: productionData.map(item => item.total_produced),
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    title: {
                        display: true,
                        text: 'สถิติการผลิตรายเดือน'
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh notifications every 30 seconds
        setInterval(function() {
            fetch('../../api/notifications.php')
                .then(response => response.json())
                .then(data => {
                    document.querySelector('.notification-badge').textContent = data.count || 0;
                })
                .catch(error => console.log('Notification update failed'));
        }, 30000);

        // Show loading on form submit
        document.addEventListener('DOMContentLoaded', function() {
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    document.getElementById('loading').style.display = 'block';
                });
            });
        });
    </script>

</body>
</html>