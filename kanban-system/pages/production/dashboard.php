<?php
// pages/production/dashboard.php
$page_title = 'แดชบอร์ดแผนกผลิต';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['production', 'admin']);

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// ดึงข้อมูลสถิติการผลิต
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM production_jobs WHERE assigned_to = $user_id) as total_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE assigned_to = $user_id AND status = 'pending') as pending_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE assigned_to = $user_id AND status = 'in_progress') as active_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE assigned_to = $user_id AND status = 'completed') as completed_jobs,
        (SELECT COUNT(*) FROM material_requests WHERE requested_by = $user_id AND status = 'pending') as pending_requests,
        (SELECT SUM(quantity_produced) FROM production_jobs WHERE assigned_to = $user_id AND status = 'completed' AND MONTH(updated_at) = MONTH(CURDATE())) as this_month_production
")->fetch();

// งานที่กำลังทำอยู่
$current_jobs = $db->query("
    SELECT pj.*, p.product_name, u.full_name as created_by_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2) as progress_percent
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u ON pj.created_by = u.user_id
    WHERE pj.assigned_to = $user_id AND pj.status IN ('pending', 'in_progress')
    ORDER BY 
        CASE pj.status 
            WHEN 'in_progress' THEN 1 
            WHEN 'pending' THEN 2 
        END,
        pj.end_date ASC
    LIMIT 6
")->fetchAll();

// คำขอเบิกวัสดุที่ส่งไป
$material_requests = $db->query("
    SELECT mr.*, pj.job_number, p.product_name,
           COUNT(mrd.request_detail_id) as item_count
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
    WHERE mr.requested_by = $user_id
    GROUP BY mr.request_id
    ORDER BY mr.request_date DESC
    LIMIT 8
")->fetchAll();

// ประสิทธิภาพการผลิตรายวัน (7 วันล่าสุด)
$daily_performance = $db->query("
    SELECT 
        DATE(updated_at) as production_date,
        SUM(quantity_produced) as daily_production,
        COUNT(*) as jobs_completed
    FROM production_jobs 
    WHERE assigned_to = $user_id 
        AND status = 'completed'
        AND updated_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    GROUP BY DATE(updated_at)
    ORDER BY production_date ASC
")->fetchAll();

// งานที่ใกล้ครบกำหนด
$urgent_jobs = $db->query("
    SELECT pj.*, p.product_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    WHERE pj.assigned_to = $user_id 
        AND pj.status IN ('pending', 'in_progress')
        AND pj.end_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
    ORDER BY pj.end_date ASC
    LIMIT 5
")->fetchAll();
?>

<style>
    .stats-card {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
        transition: all 0.3s ease;
    }
    
    .stats-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    }
    
    .stats-card .icon {
        font-size: 2.5rem;
        margin-bottom: 15px;
        opacity: 0.8;
    }
    
    .stats-card .number {
        font-size: 2rem;
        font-weight: 700;
        margin-bottom: 5px;
    }
    
    .stats-card .label {
        font-size: 0.9rem;
        opacity: 0.9;
    }
    
    .job-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        margin-bottom: 20px;
        border-left: 5px solid;
    }
    
    .job-card.status-pending { border-left-color: #ffc107; }
    .job-card.status-in-progress { border-left-color: #17a2b8; }
    
    .job-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .urgency-urgent {
        border: 2px solid #dc3545;
        box-shadow: 0 0 15px rgba(220, 53, 69, 0.3);
        animation: pulse-urgent 2s infinite;
    }
    
    @keyframes pulse-urgent {
        0% { box-shadow: 0 0 15px rgba(220, 53, 69, 0.3); }
        50% { box-shadow: 0 0 25px rgba(220, 53, 69, 0.6); }
        100% { box-shadow: 0 0 15px rgba(220, 53, 69, 0.3); }
    }
</style>

            <div class="row">
                <!-- Stats Cards -->
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center">
                        <i class="fas fa-tasks icon"></i>
                        <div class="number"><?= number_format($stats['total_jobs']) ?></div>
                        <div class="label">งานทั้งหมด</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #ffc107, #ff8c00);">
                        <i class="fas fa-clock icon"></i>
                        <div class="number"><?= number_format($stats['pending_jobs']) ?></div>
                        <div class="label">รอเริ่มงาน</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-cogs icon"></i>
                        <div class="number"><?= number_format($stats['active_jobs']) ?></div>
                        <div class="label">กำลังทำ</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-chart-line icon"></i>
                        <div class="number"><?= number_format($stats['this_month_production'] ?? 0) ?></div>
                        <div class="label">ผลิตเดือนนี้</div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-bolt me-2"></i>การดำเนินการด่วน</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="my-jobs.php" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-list fa-2x d-block mb-2"></i>
                                        งานของฉัน
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <button class="btn btn-success btn-lg w-100" onclick="showStartJobModal()">
                                        <i class="fas fa-play fa-2x d-block mb-2"></i>
                                        เริ่มงาน
                                    </button>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <button class="btn btn-warning btn-lg w-100" onclick="showRequestMaterialModal()">
                                        <i class="fas fa-hand-paper fa-2x d-block mb-2"></i>
                                        เบิกวัสดุ
                                    </button>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <button class="btn btn-info btn-lg w-100" onclick="showUpdateProgressModal()">
                                        <i class="fas fa-edit fa-2x d-block mb-2"></i>
                                        อัพเดทงาน
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- งานปัจจุบัน -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-tasks me-2"></i>งานที่กำลังดำเนินการ</h5>
                            <span class="badge bg-primary"><?= count($current_jobs) ?> งาน</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($current_jobs)): ?>
                                <?php foreach ($current_jobs as $job): ?>
                                    <?php
                                    $urgency_class = '';
                                    if ($job['days_remaining'] <= 0 && $job['status'] !== 'completed') {
                                        $urgency_class = 'urgency-urgent';
                                    }
                                    
                                    $days_class = $job['days_remaining'] < 0 ? 'text-danger' : ($job['days_remaining'] <= 2 ? 'text-warning' : 'text-success');
                                    ?>
                                    <div class="job-card status-<?= $job['status'] ?> <?= $urgency_class ?>">
                                        <div class="card-body">
                                            <div class="row align-items-center">
                                                <div class="col-md-8">
                                                    <h6 class="mb-1">
                                                        <strong><?= htmlspecialchars($job['job_number']) ?></strong>
                                                        <span class="badge bg-<?= $job['status'] === 'pending' ? 'warning' : 'info' ?> ms-2">
                                                            <?= $job['status'] === 'pending' ? 'รอเริ่ม' : 'กำลังทำ' ?>
                                                        </span>
                                                    </h6>
                                                    <p class="text-muted mb-2"><?= htmlspecialchars($job['product_name']) ?></p>
                                                    <div class="row">
                                                        <div class="col-sm-6">
                                                            <small class="text-muted">
                                                                <i class="fas fa-calendar me-1"></i>
                                                                <?= date('d/m/Y', strtotime($job['start_date'])) ?> - <?= date('d/m/Y', strtotime($job['end_date'])) ?>
                                                            </small>
                                                        </div>
                                                        <div class="col-sm-6">
                                                            <small class="text-muted">
                                                                <i class="fas fa-user me-1"></i>
                                                                วางแผนโดย: <?= htmlspecialchars($job['created_by_name']) ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                    <div class="progress mt-2" style="height: 8px;">
                                                        <div class="progress-bar bg-success" style="width: <?= min($job['progress_percent'], 100) ?>%"></div>
                                                    </div>
                                                    <small class="text-muted">
                                                        ความคืบหน้า: <?= $job['progress_percent'] ?>% 
                                                        (<?= number_format($job['quantity_produced']) ?>/<?= number_format($job['quantity_planned']) ?> ชิ้น)
                                                    </small>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="mb-2 <?= $days_class ?>">
                                                        <i class="fas fa-clock"></i>
                                                        <?php if ($job['days_remaining'] < 0): ?>
                                                            เกินกำหนด <?= abs($job['days_remaining']) ?> วัน
                                                        <?php elseif ($job['days_remaining'] == 0): ?>
                                                            วันนี้
                                                        <?php else: ?>
                                                            อีก <?= $job['days_remaining'] ?> วัน
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="btn-group-vertical" role="group">
                                                        <?php if ($job['status'] == 'pending'): ?>
                                                            <button class="btn btn-success btn-sm" onclick="startJob(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                                                <i class="fas fa-play"></i> เริ่ม
                                                            </button>
                                                        <?php else: ?>
                                                            <button class="btn btn-primary btn-sm" onclick="updateProgress(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                                                <i class="fas fa-edit"></i> อัพเดท
                                                            </button>
                                                        <?php endif; ?>
                                                        <button class="btn btn-warning btn-sm" onclick="requestMaterials(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                                            <i class="fas fa-hand-paper"></i> เบิกวัสดุ
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <div class="text-center mt-3">
                                    <a href="my-jobs.php" class="btn btn-primary">
                                        <i class="fas fa-eye me-1"></i>ดูงานทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-3">ไม่มีงานที่กำลังดำเนินการ</p>
                                    <p class="text-muted">รอการมอบหมายงานจากแผนกวางแผน</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Sidebar ขวา -->
                <div class="col-xl-4 col-lg-5">
                    <!-- งานเร่งด่วน -->
                    <?php if (!empty($urgent_jobs)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-exclamation-triangle me-2 text-danger"></i>งานเร่งด่วน</h5>
                            <span class="badge bg-danger"><?= count($urgent_jobs) ?> งาน</span>
                        </div>
                        <div class="card-body">
                            <?php foreach ($urgent_jobs as $job): ?>
                                <div class="alert alert-danger py-2 mb-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?= htmlspecialchars($job['job_number']) ?></strong><br>
                                            <small><?= htmlspecialchars($job['product_name']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <?php if ($job['days_remaining'] <= 0): ?>
                                                <span class="badge bg-danger">เกินกำหนด</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">อีก <?= $job['days_remaining'] ?> วัน</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- คำขอเบิกวัสดุ -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-hand-paper me-2"></i>คำขอเบิกวัสดุ</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($material_requests)): ?>
                                    <?php foreach ($material_requests as $request): ?>
                                        <?php
                                        $status_colors = [
                                            'pending' => 'warning',
                                            'approved' => 'success',
                                            'rejected' => 'danger',
                                            'fulfilled' => 'info'
                                        ];
                                        $status_texts = [
                                            'pending' => 'รอพิจารณา',
                                            'approved' => 'อนุมัติแล้ว',
                                            'rejected' => 'ปฏิเสธ',
                                            'fulfilled' => 'จ่ายแล้ว'
                                        ];
                                        ?>
                                        <div class="d-flex mb-3 pb-2 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm bg-<?= $status_colors[$request['status']] ?> text-white rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-hand-paper"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($request['request_number']) ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    Job: <?= htmlspecialchars($request['job_number']) ?><br>
                                                    <?= $request['item_count'] ?> รายการ
                                                </p>
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        <?= date('d/m/Y H:i', strtotime($request['request_date'])) ?>
                                                    </small>
                                                    <span class="badge bg-<?= $status_colors[$request['status']] ?>">
                                                        <?= $status_texts[$request['status']] ?>
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    <div class="text-center">
                                        <a href="material-requests.php" class="btn btn-outline-primary btn-sm">
                                            <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                        </a>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">ไม่มีคำขอเบิกวัสดุ</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- สถิติการผลิต -->
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-bar me-2"></i>ผลงานเดือนนี้</h5>
                        </div>
                        <div class="card-body text-center">
                            <div class="row">
                                <div class="col-6">
                                    <h3 class="text-primary"><?= number_format($stats['completed_jobs']) ?></h3>
                                    <small class="text-muted">งานที่เสร็จ</small>
                                </div>
                                <div class="col-6">
                                    <h3 class="text-success"><?= number_format($stats['this_month_production'] ?? 0) ?></h3>
                                    <small class="text-muted">ชิ้นที่ผลิต</small>
                                </div>
                            </div>
                            <?php if (!empty($daily_performance)): ?>
                                <div class="mt-3">
                                    <canvas id="dailyChart" height="100"></canvas>
                                </div>
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
        // กราฟผลงานรายวัน
        <?php if (!empty($daily_performance)): ?>
        const dailyData = <?= json_encode($daily_performance) ?>;
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: dailyData.map(item => new Date(item.production_date).toLocaleDateString('th-TH', {day: '2-digit', month: '2-digit'})),
                datasets: [{
                    label: 'ชิ้นที่ผลิต',
                    data: dailyData.map(item => item.daily_production),
                    backgroundColor: 'rgba(40, 167, 69, 0.8)',
                    borderColor: 'rgba(40, 167, 69, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
        <?php endif; ?>

        // Functions
        function startJob(jobId, jobNumber) {
            window.location.href = `my-jobs.php?action=start&job_id=${jobId}`;
        }

        function updateProgress(jobId, jobNumber) {
            window.location.href = `my-jobs.php?action=update&job_id=${jobId}`;
        }

        function requestMaterials(jobId, jobNumber) {
            window.location.href = `material-requests.php?action=create&job_id=${jobId}`;
        }

        function showStartJobModal() {
            window.location.href = 'my-jobs.php?filter=pending';
        }

        function showRequestMaterialModal() {
            window.location.href = 'material-requests.php?action=create';
        }

        function showUpdateProgressModal() {
            window.location.href = 'my-jobs.php?filter=in_progress';
        }

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 2 minutes
        setInterval(() => {
            location.reload();
        }, 120000);
    </script>

</body>
</html>