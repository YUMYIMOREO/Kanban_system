<?php
// pages/planning/dashboard.php
$page_title = 'แดชบอร์ดแผนกวางแผน';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'แดชบอร์ด']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['planning', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสถิติการวางแผน
$stats = $db->query("
    SELECT 
        (SELECT COUNT(*) FROM production_jobs WHERE created_by = {$_SESSION['user_id']}) as total_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'pending' AND created_by = {$_SESSION['user_id']}) as pending_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'in_progress' AND created_by = {$_SESSION['user_id']}) as active_jobs,
        (SELECT COUNT(*) FROM production_jobs WHERE status = 'completed' AND created_by = {$_SESSION['user_id']}) as completed_jobs,
        (SELECT COUNT(*) FROM purchase_requests WHERE status = 'pending') as pending_prs,
        (SELECT COUNT(*) FROM materials WHERE current_stock <= min_stock AND status = 'active') as low_stock_materials
")->fetch();

// งานที่กำลังดำเนินการ
$active_jobs = $db->query("
    SELECT pj.*, p.product_name, u.full_name as assigned_to_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2) as progress_percent
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u ON pj.assigned_to = u.user_id
    WHERE pj.status IN ('pending', 'in_progress') AND pj.created_by = {$_SESSION['user_id']}
    ORDER BY pj.start_date ASC
    LIMIT 8
")->fetchAll();

// วัสดุที่ต้องสั่งซื้อ
$materials_to_order = $db->query("
    SELECT m.*, 
           (m.min_stock - m.current_stock) as need_to_order,
           pr.pr_id,
           pr.status as pr_status
    FROM materials m
    LEFT JOIN purchase_requests pr ON m.material_id = pr.material_id AND pr.status IN ('pending', 'approved', 'ordered')
    WHERE m.current_stock <= m.min_stock AND m.status = 'active'
    ORDER BY (m.current_stock / m.min_stock) ASC
    LIMIT 10
")->fetchAll();

// ประสิทธิภาพการผลิตรายสัปดาห์
$weekly_performance = $db->query("
    SELECT 
        YEARWEEK(created_at, 1) as week_year,
        WEEK(created_at, 1) as week_num,
        YEAR(created_at) as year,
        COUNT(*) as jobs_created,
        SUM(quantity_planned) as total_planned,
        SUM(quantity_produced) as total_produced,
        AVG(CASE WHEN status = 'completed' THEN DATEDIFF(updated_at, created_at) END) as avg_completion_days
    FROM production_jobs 
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK)
        AND created_by = {$_SESSION['user_id']}
    GROUP BY YEARWEEK(created_at, 1)
    ORDER BY week_year ASC
")->fetchAll();

// คำขอเบิกวัสดุรอการพิจารณา
$pending_material_requests = $db->query("
    SELECT mr.*, pj.job_number, u.full_name as requested_by_name,
           COUNT(mrd.request_detail_id) as item_count
    FROM material_requests mr
    LEFT JOIN production_jobs pj ON mr.job_id = pj.job_id
    LEFT JOIN users u ON mr.requested_by = u.user_id
    LEFT JOIN material_request_details mrd ON mr.request_id = mrd.request_id
    WHERE mr.status = 'pending' AND pj.created_by = {$_SESSION['user_id']}
    GROUP BY mr.request_id
    ORDER BY mr.request_date DESC
    LIMIT 5
")->fetchAll();
?>

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
                        <div class="label">งานรอเริ่ม</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #17a2b8, #6f42c1);">
                        <i class="fas fa-cogs icon"></i>
                        <div class="number"><?= number_format($stats['active_jobs']) ?></div>
                        <div class="label">กำลังผลิต</div>
                    </div>
                </div>

                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="stats-card text-center" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <i class="fas fa-check-circle icon"></i>
                        <div class="number"><?= number_format($stats['completed_jobs']) ?></div>
                        <div class="label">เสร็จแล้ว</div>
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
                                    <a href="create-job.php" class="btn btn-primary btn-lg w-100">
                                        <i class="fas fa-plus fa-2x d-block mb-2"></i>
                                        สร้างงานใหม่
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="material-planning.php" class="btn btn-success btn-lg w-100">
                                        <i class="fas fa-calculator fa-2x d-block mb-2"></i>
                                        วางแผนวัสดุ
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="purchase-requests.php" class="btn btn-warning btn-lg w-100">
                                        <i class="fas fa-shopping-cart fa-2x d-block mb-2"></i>
                                        สั่งซื้อวัสดุ
                                    </a>
                                </div>
                                <div class="col-lg-3 col-md-6 mb-3">
                                    <a href="production-jobs.php" class="btn btn-info btn-lg w-100">
                                        <i class="fas fa-list-alt fa-2x d-block mb-2"></i>
                                        ติดตามงาน
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- งานที่กำลังดำเนินการ -->
                <div class="col-xl-8 col-lg-7">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-tasks me-2"></i>งานที่กำลังดำเนินการ</h5>
                            <span class="badge bg-primary"><?= count($active_jobs) ?> งาน</span>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($active_jobs)): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>เลขที่งาน</th>
                                                <th>สินค้า</th>
                                                <th>ผู้รับผิดชอบ</th>
                                                <th>ความคืบหน้า</th>
                                                <th>สถานะ</th>
                                                <th>วันที่เหลือ</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($active_jobs as $job): ?>
                                                <?php
                                                $status_class = [
                                                    'pending' => 'status-pending',
                                                    'in_progress' => 'status-in-progress',
                                                    'completed' => 'status-completed',
                                                    'cancelled' => 'status-cancelled'
                                                ];
                                                
                                                $status_text = [
                                                    'pending' => 'รอเริ่ม',
                                                    'in_progress' => 'กำลังผลิต',
                                                    'completed' => 'เสร็จแล้ว',
                                                    'cancelled' => 'ยกเลิก'
                                                ];
                                                
                                                $days_class = $job['days_remaining'] < 0 ? 'text-danger' : ($job['days_remaining'] <= 3 ? 'text-warning' : 'text-success');
                                                ?>
                                                <tr>
                                                    <td>
                                                        <strong><?= htmlspecialchars($job['job_number']) ?></strong>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($job['product_name']) ?><br>
                                                        <small class="text-muted">
                                                            <?= number_format($job['quantity_produced']) ?>/<?= number_format($job['quantity_planned']) ?> ชิ้น
                                                        </small>
                                                    </td>
                                                    <td><?= htmlspecialchars($job['assigned_to_name']) ?></td>
                                                    <td>
                                                        <div class="progress mb-1" style="height: 8px;">
                                                            <div class="progress-bar bg-success" style="width: <?= min($job['progress_percent'], 100) ?>%"></div>
                                                        </div>
                                                        <small><?= $job['progress_percent'] ?>%</small>
                                                    </td>
                                                    <td>
                                                        <span class="status-badge <?= $status_class[$job['status']] ?>">
                                                            <?= $status_text[$job['status']] ?>
                                                        </span>
                                                    </td>
                                                    <td class="<?= $days_class ?>">
                                                        <?php if ($job['days_remaining'] < 0): ?>
                                                            เกินกำหนด <?= abs($job['days_remaining']) ?> วัน
                                                        <?php elseif ($job['days_remaining'] == 0): ?>
                                                            วันนี้
                                                        <?php else: ?>
                                                            อีก <?= $job['days_remaining'] ?> วัน
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="text-center mt-3">
                                    <a href="production-jobs.php" class="btn btn-primary">
                                        <i class="fas fa-eye me-1"></i>ดูทั้งหมด
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-4">
                                    <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                                    <p class="text-muted mb-3">ไม่มีงานที่กำลังดำเนินการ</p>
                                    <a href="create-job.php" class="btn btn-primary">
                                        <i class="fas fa-plus me-1"></i>สร้างงานใหม่
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- การแจ้งเตือน -->
                <div class="col-xl-4 col-lg-5">
                    <!-- วัสดุที่ต้องสั่งซื้อ -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-exclamation-triangle me-2 text-warning"></i>ต้องสั่งซื้อ</h5>
                            <span class="badge bg-warning"><?= count($materials_to_order) ?> รายการ</span>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($materials_to_order)): ?>
                                    <?php foreach ($materials_to_order as $material): ?>
                                        <div class="alert alert-warning py-2 mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?= htmlspecialchars($material['part_code']) ?></strong><br>
                                                    <small><?= htmlspecialchars($material['material_name']) ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-danger">
                                                        <?= number_format($material['current_stock']) ?>
                                                    </span><br>
                                                    <small class="text-muted">
                                                        ต้องการ: <?= number_format($material['need_to_order']) ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <div class="mt-2">
                                                <?php if ($material['pr_id']): ?>
                                                    <span class="badge bg-info">PR อยู่ระหว่างดำเนินการ</span>
                                                <?php else: ?>
                                                    <button class="btn btn-warning btn-sm" onclick="createPR(<?= $material['material_id'] ?>, '<?= htmlspecialchars($material['part_code']) ?>', <?= $material['need_to_order'] ?>)">
                                                        <i class="fas fa-plus"></i> สั่งซื้อ
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="text-center py-3">
                                        <i class="fas fa-check-circle fa-2x text-success mb-2"></i>
                                        <p class="text-success mb-0">วัสดุทุกรายการเพียงพอ</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- คำขอเบิกวัสดุรอพิจารณา -->
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-hand-paper me-2"></i>คำขอเบิกวัสดุ</h5>
                        </div>
                        <div class="card-body">
                            <div style="max-height: 300px; overflow-y: auto;">
                                <?php if (!empty($pending_material_requests)): ?>
                                    <?php foreach ($pending_material_requests as $request): ?>
                                        <div class="d-flex mb-3 pb-2 border-bottom">
                                            <div class="flex-shrink-0 me-3">
                                                <div class="avatar-sm bg-warning text-white rounded-circle d-flex align-items-center justify-content-center">
                                                    <i class="fas fa-hand-paper"></i>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-1"><?= htmlspecialchars($request['request_number']) ?></h6>
                                                <p class="text-muted mb-1 small">
                                                    Job: <?= htmlspecialchars($request['job_number']) ?><br>
                                                    ขอโดย: <?= htmlspecialchars($request['requested_by_name']) ?><br>
                                                    <?= $request['item_count'] ?> รายการ
                                                </p>
                                                <small class="text-muted">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?= date('d/m/Y H:i', strtotime($request['request_date'])) ?>
                                                </small>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <p class="text-muted text-center py-3">ไม่มีคำขอเบิกวัสดุรอพิจารณา</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- กราฟประสิทธิภาพ -->
            <div class="row mt-4">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-line me-2"></i>ประสิทธิภาพการผลิตรายสัปดาห์</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="performanceChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Create PR Modal -->
    <div class="modal fade" id="createPRModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">สร้างคำขอซื้อ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="createPRForm">
                    <div class="modal-body">
                        <input type="hidden" id="pr_material_id" name="material_id">
                        
                        <div class="mb-3">
                            <label class="form-label">รหัสวัสดุ</label>
                            <input type="text" class="form-control" id="pr_part_code" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">จำนวนที่ต้องการ</label>
                            <input type="number" class="form-control" id="pr_quantity" name="quantity_requested" required min="1">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ความเร่งด่วน</label>
                            <select class="form-control" name="urgency" required>
                                <option value="medium">ปกติ</option>
                                <option value="high">เร่งด่วน</option>
                                <option value="urgent">เร่งด่วนมาก</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">วันที่ต้องการ</label>
                            <input type="date" class="form-control" name="expected_date" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="ระบุรายละเอียดเพิ่มเติม"></textarea>
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

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // กราฟประสิทธิภาพ
        const performanceData = <?= json_encode($weekly_performance) ?>;
        const ctx = document.getElementById('performanceChart').getContext('2d');
        
        const weekLabels = performanceData.map(item => {
            return `สัปดาห์ ${item.week_num}/${item.year}`;
        });
        
        new Chart(ctx, {
            type: 'line',
            data: {
                labels: weekLabels,
                datasets: [{
                    label: 'งานที่สร้าง',
                    data: performanceData.map(item => item.jobs_created),
                    borderColor: 'rgb(102, 126, 234)',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y'
                }, {
                    label: 'จำนวนที่วางแผน',
                    data: performanceData.map(item => item.total_planned),
                    borderColor: 'rgb(118, 75, 162)',
                    backgroundColor: 'rgba(118, 75, 162, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }, {
                    label: 'จำนวนที่ผลิตจริง',
                    data: performanceData.map(item => item.total_produced),
                    borderColor: 'rgb(40, 167, 69)',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    yAxisID: 'y1'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        type: 'linear',
                        display: true,
                        position: 'left',
                        title: {
                            display: true,
                            text: 'จำนวนงาน'
                        }
                    },
                    y1: {
                        type: 'linear',
                        display: true,
                        position: 'right',
                        title: {
                            display: true,
                            text: 'จำนวนชิ้น'
                        },
                        grid: {
                            drawOnChartArea: false,
                        },
                    }
                }
            }
        });

        // สร้างคำขอซื้อ
        function createPR(materialId, partCode, quantity) {
            document.getElementById('pr_material_id').value = materialId;
            document.getElementById('pr_part_code').value = partCode;
            document.getElementById('pr_quantity').value = quantity;
            
            // ตั้งวันที่ต้องการเป็น 7 วันข้างหน้า
            const expectedDate = new Date();
            expectedDate.setDate(expectedDate.getDate() + 7);
            document.querySelector('input[name="expected_date"]').value = expectedDate.toISOString().split('T')[0];
            
            new bootstrap.Modal(document.getElementById('createPRModal')).show();
        }

        // ส่งฟอร์มสร้าง PR
        document.getElementById('createPRForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'create');
            
            fetch('../../api/purchase-requests.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('createPRModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างคำขอได้', 'error');
            });
        });

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