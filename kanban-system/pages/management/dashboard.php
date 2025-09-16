<?php
// pages/management/dashboard.php
$page_title = 'แดชบอร์ดผู้บริหาร';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['management', 'admin']);

$database = new Database();
$db = $database->getConnection();

// KPI สำคัญ
$kpi = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'completed' AND MONTH(updated_at) = MONTH(CURDATE())) as completed_jobs_month,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'in_progress') as active_jobs,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_materials,
        (SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending') as pending_prs,
        (SELECT SUM(quantity_produced) FROM production_jobs WHERE status = 'completed' AND MONTH(updated_at) = MONTH(CURDATE())) as total_production_month,
        (SELECT SUM(quantity_planned) FROM production_jobs WHERE MONTH(created_at) = MONTH(CURDATE())) as planned_production_month,
        (SELECT COUNT(DISTINCT assigned_to) FROM production_jobs WHERE status IN ('pending', 'in_progress')) as active_workers,
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND role != 'admin') as total_workers
")->fetch();

// คำนวณ KPI
$production_efficiency = $kpi['planned_production_month'] > 0 ? 
    round(($kpi['total_production_month'] / $kpi['planned_production_month']) * 100, 2) : 0;

$worker_utilization = $kpi['total_workers'] > 0 ? 
    round(($kpi['active_workers'] / $kpi['total_workers']) * 100, 2) : 0;

// ข้อมูลการผลิตรายเดือน (12 เดือน)
$monthly_production = $db->query("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as month,
        MONTHNAME(created_at) as month_name,
        YEAR(created_at) as year,
        COUNT(*) as jobs_created,
        SUM(quantity_planned) as planned,
        SUM(CASE WHEN status = 'completed' THEN quantity_produced ELSE 0 END) as produced,
        ROUND(AVG(CASE WHEN status = 'completed' THEN DATEDIFF(updated_at, created_at) END), 1) as avg_completion_days
    FROM production_jobs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC
")->fetchAll();

// Top ผลิตภัณฑ์
$top_products = $db->query("
    SELECT p.product_name, p.product_code,
           COUNT(pj.job_id) as job_count,
           SUM(pj.quantity_planned) as total_planned,
           SUM(pj.quantity_produced) as total_produced
    FROM products p
    LEFT JOIN production_jobs pj ON p.product_id = pj.product_id
    WHERE pj.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY p.product_id
    ORDER BY total_produced DESC
    LIMIT 5
")->fetchAll();

// การใช้วัสดุ
$material_usage = $db->query("
    SELECT m.part_code, m.material_name,
           SUM(CASE WHEN it.transaction_type = 'out' THEN it.quantity ELSE 0 END) as total_used,
           SUM(CASE WHEN it.transaction_type = 'in' THEN it.quantity ELSE 0 END) as total_received,
           m.current_stock
    FROM materials m
    LEFT JOIN inventory_transactions it ON m.material_id = it.material_id
    WHERE it.transaction_date >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
        AND m.status = 'active'
    GROUP BY m.material_id
    ORDER BY total_used DESC
    LIMIT 8
")->fetchAll();

// ประสิทธิภาพทีม
$team_performance = $db->query("
    SELECT u.full_name, u.role,
           COUNT(pj.job_id) as assigned_jobs,
           SUM(CASE WHEN pj.status = 'completed' THEN 1 ELSE 0 END) as completed_jobs,
           SUM(CASE WHEN pj.status = 'completed' THEN pj.quantity_produced ELSE 0 END) as total_produced,
           ROUND(AVG(CASE WHEN pj.status = 'completed' THEN DATEDIFF(pj.updated_at, pj.created_at) END), 1) as avg_completion_days
    FROM users u
    LEFT JOIN production_jobs pj ON u.user_id = pj.assigned_to
        AND pj.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    WHERE u.role IN ('production', 'planning') AND u.status = 'active'
    GROUP BY u.user_id
    ORDER BY completed_jobs DESC
    LIMIT 8
")->fetchAll();

// การแจ้งเตือนสำคัญ
$alerts = [];

// สต็อกต่ำ
if ($kpi['low_stock_materials'] > 0) {
    $alerts[] = [
        'type' => 'danger',
        'icon' => 'fas fa-exclamation-triangle',
        'title' => 'วัสดุสต็อกต่ำ',
        'message' => "มีวัสดุ {$kpi['low_stock_materials']} รายการที่สต็อกต่ำกว่าเกณฑ์",
        'action' => 'ดูรายละเอียด',
        'link' => '../admin/materials.php?filter=low_stock'
    ];
}

// คำขอซื้อรอพิจารณา
if ($kpi['pending_prs'] > 0) {
    $alerts[] = [
        'type' => 'warning',
        'icon' => 'fas fa-shopping-cart',
        'title' => 'คำขอซื้อรอพิจารณา',
        'message' => "มีคำขอซื้อ {$kpi['pending_prs']} รายการรอการอนุมัติ",
        'action' => 'อนุมัติ',
        'link' => 'purchase-requests.php'
    ];
}

// ประสิทธิภาพการผลิตต่ำ
if ($production_efficiency < 80) {
    $alerts[] = [
        'type' => 'info',
        'icon' => 'fas fa-chart-line',
        'title' => 'ประสิทธิภาพการผลิต',
        'message' => "ประสิทธิภาพการผลิตเดือนนี้: {$production_efficiency}%",
        'action' => 'วิเคราะห์',
        'link' => 'analytics.php'
    ];
}
?>

            <!-- KPI Cards -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-chart-line icon"></i>
                        <div class="number"><?= $production_efficiency ?>%</div>
                        <div class="label">ประสิทธิภาพการผลิต</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-tasks icon"></i>
                        <div class="number"><?= number_format($kpi['completed_jobs_month']) ?></div>
                        <div class="label">งานเสร็จเดือนนี้</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-users icon"></i>
                        <div class="number"><?= $worker_utilization ?>%</div>
                        <div class="label">การใช้งานทีม</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
                        <i class="fas fa-boxes icon"></i>
                        <div class="number"><?= number_format($kpi['total_production_month'] ?? 0) ?></div>
                        <div class="label">ชิ้นผลิตเดือนนี้</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions & Alerts -->
            <div class="row mb-4">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i>การดำเนินการด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="reports.php" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-chart-bar fa-2x d-block mb-2"></i>
                                        รายงาน
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="analytics.php" class="btn btn-success btn-lg w-100">
                                        <i class="fas fa-analytics fa-2x d-block mb-2"></i>
                                        วิเคราะห์
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="kpi-dashboard.php" class="btn btn-info btn-lg w-100">
                                        <i class="fas fa-trophy fa-2x d-block mb-2"></i>
                                        KPI
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="../admin/audit-logs.php" class="btn btn-secondary btn-lg w-100">
                                        <i class="fas fa-history fa-2x d-block mb-2"></i>
                                        ประวัติ
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bell me-2 text-warning"></i>การแจ้งเตือนสำคัญ</h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($alerts)): ?>
                                <?php foreach ($alerts as $alert): ?>
                                    <div class="alert alert-<?= $alert['type'] ?> py-2 mb-2">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <i class="<?= $alert['icon'] ?> me-2"></i>
                                                <strong><?= $alert['title'] ?></strong><br>
                                                <small><?= $alert['message'] ?></small>
                                            </div>
                                            <a href="<?= $alert['link'] ?>" class="btn btn-<?= $alert['type'] ?> btn-sm">
                                                <?= $alert['action'] ?>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-3">
                                    <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                    <p class="text-success mb-0">ไม่มีการแจ้งเตือน</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- กราฟการผลิต -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line me-2"></i>ภาพรวมการผลิต 12 เดือน</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="productionChart" height="100"></canvas>
                        </div>
                    </div>
                </div>

                <!-- สถิติการใช้วัสดุ -->
                <div class="col-xl-4 col-lg-5">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-boxes me-2"></i>การใช้วัสดุ</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="materialChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-4">
                <!-- Top Products -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-star me-2"></i>สินค้ายอดนิยม</h5>
                            <small class="text-muted">6 เดือนที่ผ่านมา</small>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($top_products)): ?>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>สินค้า</th>
                                                <th>งาน</th>
                                                <th>วางแผน</th>
                                                <th>ผลิตจริง</th>
                                                <th>%</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($top_products as $product): ?>
                                                <?php 
                                                $efficiency = $product['total_planned'] > 0 ? 
                                                    round(($product['total_produced'] / $product['total_planned']) * 100, 1) : 0;
                                                $efficiency_class = $efficiency >= 90 ? 'text-success' : ($efficiency >= 70 ? 'text-warning' : 'text-danger');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($product['product_code']) ?></strong><br>
                                                        <small class="text-muted"><?= htmlspecialchars($product['product_name']) ?></small>
                                                    </td>
                                                    <td><?= number_format($product['job_count']) ?></td>
                                                    <td><?= number_format($product['total_planned']) ?></td>
                                                    <td><?= number_format($product['total_produced']) ?></td>
                                                    <td class="<?= $efficiency_class ?>">
                                                        <strong><?= $efficiency ?>%</strong>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-4">ไม่มีข้อมูลการผลิต</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Team Performance -->
                <div class="col-xl-6 col-lg-6">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-users me-2"></i>ประสิทธิภาพทีม</h5>
                            <small class="text-muted">3 เดือนที่ผ่านมา</small>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($team_performance)): ?>
                                <div style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($team_performance as $member): ?>
                                        <?php 
                                        $completion_rate = $member['assigned_jobs'] > 0 ? 
                                            round(($member['completed_jobs'] / $member['assigned_jobs']) * 100, 1) : 0;
                                        $rate_class = $completion_rate >= 90 ? 'bg-success' : ($completion_rate >= 70 ? 'bg-warning' : 'bg-danger');
                                        ?>
                                        <div class="d-flex mb-3 pb-3 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm <?= $rate_class ?> text-white rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-user"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($member['full_name']) ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    <?= ucfirst($member['role']) ?> | 
                                                    งานที่ได้รับ: <?= number_format($member['assigned_jobs']) ?> | 
                                                    เสร็จ: <?= number_format($member['completed_jobs']) ?>
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">
                                                        ผลิต: <?= number_format($member['total_produced']) ?> ชิ้น
                                                    </small>
                                                    <span class="badge <?= $rate_class ?>">
                                                        <?= $completion_rate ?>%
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted text-center py-4">ไม่มีข้อมูลประสิทธิภาพทีม</p>
                            <?php endif; ?>
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
        // กราฟการผลิต
        const productionData = <?= json_encode($monthly_production) ?>;
        const productionCtx = document.getElementById('productionChart').getContext('2d');
        
        const monthLabels = productionData.map(item => {
            const date = new Date(item.month + '-01');
            return date.toLocaleDateString('th-TH', { month: 'short', year: 'numeric' });
        });
        
        new Chart(productionCtx, {
            type: 'line',
            data: {
                labels: monthLabels,
                datasets: [{
                    label: 'วางแผน',
                    data: productionData.map(item => item.planned || 0),
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }, {
                    label: 'ผลิตจริง',
                    data: productionData.map(item => item.produced || 0),
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    },
                    tooltip: {
                        callbacks: {
                            afterBody: function(context) {
                                const index = context[0].dataIndex;
                                const data = productionData[index];
                                if (data.avg_completion_days) {
                                    return `เฉลี่ยระยะเวลา: ${data.avg_completion_days} วัน`;
                                }
                                return '';
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'จำนวน (ชิ้น)'
                        }
                    }
                },
                interaction: {
                    intersect: false,
                    mode: 'index'
                }
            }
        });

        // กราฟการใช้วัสดุ
        const materialData = <?= json_encode($material_usage) ?>;
        const materialCtx = document.getElementById('materialChart').getContext('2d');
        
        new Chart(materialCtx, {
            type: 'doughnut',
            data: {
                labels: materialData.map(item => item.part_code),
                datasets: [{
                    data: materialData.map(item => item.total_used),
                    backgroundColor: [
                        '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0',
                        '#9966FF', '#FF9F40', '#FF6384', '#C9CBCF'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            padding: 15,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const item = materialData[context.dataIndex];
                                return `${item.material_name}: ${context.parsed.toLocaleString()} หน่วย`;
                            }
                        }
                    }
                }
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 5 minutes
        setInterval(() => {
            location.reload();
        }, 300000);

        // Export functions
        function exportReport(type) {
            window.location.href = `../../api/reports.php?action=export&type=${type}&format=excel`;
        }
    </script>

</body>
</html>