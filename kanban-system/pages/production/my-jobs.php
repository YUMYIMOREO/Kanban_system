<?php
// pages/production/my-jobs.php - งานของฉัน
$page_title = 'งานของฉัน';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'งานของฉัน']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['production', 'admin']);

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

// ดึงข้อมูลงานทั้งหมด
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

$where_conditions = ["pj.assigned_to = ?"];
$params = [$user_id];

if ($filter !== 'all') {
    $where_conditions[] = "pj.status = ?";
    $params[] = $filter;
}

if (!empty($search)) {
    $where_conditions[] = "(pj.job_number LIKE ? OR p.product_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

$jobs_query = "
    SELECT pj.*, p.product_name, p.product_code, u.full_name as created_by_name,
           DATEDIFF(pj.end_date, CURDATE()) as days_remaining,
           ROUND((pj.quantity_produced / pj.quantity_planned) * 100, 2) as progress_percent,
           (SELECT COUNT(*) FROM material_requests mr WHERE mr.job_id = pj.job_id AND mr.requested_by = ?) as material_request_count
    FROM production_jobs pj
    LEFT JOIN products p ON pj.product_id = p.product_id
    LEFT JOIN users u ON pj.created_by = u.user_id
    WHERE $where_clause
    ORDER BY 
        CASE pj.status 
            WHEN 'in_progress' THEN 1 
            WHEN 'pending' THEN 2 
            WHEN 'completed' THEN 3 
            WHEN 'cancelled' THEN 4 
        END,
        pj.end_date ASC
";

$params[] = $user_id; // เพิ่มสำหรับ material_request_count
$stmt = $db->prepare($jobs_query);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

// นับจำนวนตามสถานะ
$status_counts = [
    'all' => count($jobs),
    'pending' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0
];

foreach ($jobs as $job) {
    $status_counts[$job['status']]++;
}
?>

<style>
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
    .job-card.status-completed { border-left-color: #28a745; }
    .job-card.status-cancelled { border-left-color: #dc3545; }
    
    .job-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .job-header {
        display: flex;
        justify-content: between;
        align-items: flex-start;
        margin-bottom: 15px;
    }
    
    .job-number {
        font-size: 1.2rem;
        font-weight: 700;
        color: var(--primary-color);
    }
    
    .urgency-high {
        border: 2px solid #ff6b35;
        box-shadow: 0 0 15px rgba(255, 107, 53, 0.3);
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
    
    .progress-container {
        position: relative;
        margin: 10px 0;
    }
    
    .progress-text {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 0.8rem;
        font-weight: 600;
        color: white;
        text-shadow: 1px 1px 2px rgba(0,0,0,0.5);
    }
    
    .filter-tabs {
        background: #f8f9fa;
        border-radius: 15px;
        padding: 5px;
        margin-bottom: 25px;
    }
    
    .filter-tab {
        display: inline-block;
        padding: 10px 20px;
        border-radius: 10px;
        text-decoration: none;
        color: #6c757d;
        transition: all 0.3s ease;
        margin: 0 2px;
    }
    
    .filter-tab.active {
        background: var(--primary-color);
        color: white;
    }
    
    .filter-tab:hover {
        color: var(--primary-color);
        text-decoration: none;
    }
    
    .search-container {
        position: relative;
        margin-bottom: 25px;
    }
    
    .search-icon {
        position: absolute;
        left: 15px;
        top: 50%;
        transform: translateY(-50%);
        color: #6c757d;
    }
    
    .search-input {
        padding-left: 45px;
        border-radius: 25px;
        border: 2px solid #e9ecef;
        transition: all 0.3s ease;
    }
    
    .search-input:focus {
        border-color: var(--primary-color);
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }
</style>

            <!-- Filter Tabs -->
            <div class="filter-tabs">
                <a href="?filter=all" class="filter-tab <?= $filter === 'all' ? 'active' : '' ?>">
                    ทั้งหมด (<?= $status_counts['all'] ?>)
                </a>
                <a href="?filter=pending" class="filter-tab <?= $filter === 'pending' ? 'active' : '' ?>">
                    รอเริ่ม (<?= $status_counts['pending'] ?>)
                </a>
                <a href="?filter=in_progress" class="filter-tab <?= $filter === 'in_progress' ? 'active' : '' ?>">
                    กำลังทำ (<?= $status_counts['in_progress'] ?>)
                </a>
                <a href="?filter=completed" class="filter-tab <?= $filter === 'completed' ? 'active' : '' ?>">
                    เสร็จแล้ว (<?= $status_counts['completed'] ?>)
                </a>
                <a href="?filter=cancelled" class="filter-tab <?= $filter === 'cancelled' ? 'active' : '' ?>">
                    ยกเลิก (<?= $status_counts['cancelled'] ?>)
                </a>
            </div>

            <!-- Search -->
            <div class="search-container">
                <i class="fas fa-search search-icon"></i>
                <input type="text" class="form-control search-input" placeholder="ค้นหาเลขที่งาน หรือ ชื่อสินค้า..." 
                       value="<?= htmlspecialchars($search) ?>" id="searchInput">
            </div>

            <!-- Jobs List -->
            <div class="row">
                <?php if (!empty($jobs)): ?>
                    <?php foreach ($jobs as $job): ?>
                        <?php
                        $urgency_class = '';
                        if ($job['days_remaining'] <= 0 && $job['status'] !== 'completed') {
                            $urgency_class = 'urgency-urgent';
                        } elseif ($job['days_remaining'] <= 2 && $job['status'] !== 'completed') {
                            $urgency_class = 'urgency-high';
                        }
                        
                        $status_colors = [
                            'pending' => 'warning',
                            'in_progress' => 'info', 
                            'completed' => 'success',
                            'cancelled' => 'danger'
                        ];
                        
                        $status_texts = [
                            'pending' => 'รอเริ่มงาน',
                            'in_progress' => 'กำลังทำ',
                            'completed' => 'เสร็จแล้ว',
                            'cancelled' => 'ยกเลิก'
                        ];
                        ?>
                        
                        <div class="col-lg-6 col-xl-4">
                            <div class="job-card status-<?= $job['status'] ?> <?= $urgency_class ?>">
                                <div class="card-body">
                                    <div class="job-header">
                                        <div class="flex-grow-1">
                                            <div class="job-number"><?= htmlspecialchars($job['job_number']) ?></div>
                                            <h6 class="mb-2"><?= htmlspecialchars($job['product_name']) ?></h6>
                                            <small class="text-muted"><?= htmlspecialchars($job['product_code']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-<?= $status_colors[$job['status']] ?>">
                                                <?= $status_texts[$job['status']] ?>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Progress -->
                                    <div class="progress-container">
                                        <div class="progress" style="height: 25px; border-radius: 12px;">
                                            <div class="progress-bar bg-success" 
                                                 style="width: <?= min($job['progress_percent'], 100) ?>%">
                                            </div>
                                        </div>
                                        <div class="progress-text">
                                            <?= $job['progress_percent'] ?>% 
                                            (<?= number_format($job['quantity_produced']) ?>/<?= number_format($job['quantity_planned']) ?>)
                                        </div>
                                    </div>

                                    <!-- Job Details -->
                                    <div class="row mb-3">
                                        <div class="col-6">
                                            <small class="text-muted">วันที่เริ่ม:</small><br>
                                            <strong><?= date('d/m/Y', strtotime($job['start_date'])) ?></strong>
                                        </div>
                                        <div class="col-6">
                                            <small class="text-muted">กำหนดเสร็จ:</small><br>
                                            <strong class="<?= $job['days_remaining'] <= 0 ? 'text-danger' : ($job['days_remaining'] <= 2 ? 'text-warning' : 'text-success') ?>">
                                                <?= date('d/m/Y', strtotime($job['end_date'])) ?>
                                                <?php if ($job['status'] !== 'completed' && $job['status'] !== 'cancelled'): ?>
                                                    <br>
                                                    <small>
                                                        <?php if ($job['days_remaining'] < 0): ?>
                                                            <i class="fas fa-exclamation-triangle"></i> เกิน <?= abs($job['days_remaining']) ?> วัน
                                                        <?php elseif ($job['days_remaining'] == 0): ?>
                                                            <i class="fas fa-clock"></i> วันนี้
                                                        <?php else: ?>
                                                            <i class="fas fa-calendar"></i> อีก <?= $job['days_remaining'] ?> วัน
                                                        <?php endif; ?>
                                                    </small>
                                                <?php endif; ?>
                                            </strong>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="d-flex gap-2 flex-wrap">
                                        <?php if ($job['status'] === 'pending'): ?>
                                            <button class="btn btn-success btn-sm flex-fill" 
                                                    onclick="startJob(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                                <i class="fas fa-play"></i> เริ่มงาน
                                            </button>
                                        <?php elseif ($job['status'] === 'in_progress'): ?>
                                            <button class="btn btn-primary btn-sm flex-fill" 
                                                    onclick="updateProgress(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>', <?= $job['quantity_planned'] ?>, <?= $job['quantity_produced'] ?>)">
                                                <i class="fas fa-edit"></i> อัพเดท
                                            </button>
                                        <?php endif; ?>
                                        
                                        <?php if ($job['status'] !== 'completed' && $job['status'] !== 'cancelled'): ?>
                                            <button class="btn btn-warning btn-sm" 
                                                    onclick="requestMaterials(<?= $job['job_id'] ?>, '<?= htmlspecialchars($job['job_number']) ?>')">
                                                <i class="fas fa-hand-paper"></i> เบิกวัสดุ
                                                <?php if ($job['material_request_count'] > 0): ?>
                                                    <span class="badge bg-light text-dark"><?= $job['material_request_count'] ?></span>
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                        
                                        <button class="btn btn-outline-info btn-sm" 
                                                onclick="viewJobDetails(<?= $job['job_id'] ?>)">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>

                                    <!-- Additional Info -->
                                    <?php if (!empty($job['notes'])): ?>
                                        <div class="mt-2 pt-2 border-top">
                                            <small class="text-muted">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <?= htmlspecialchars(mb_substr($job['notes'], 0, 100)) ?>
                                                <?= mb_strlen($job['notes']) > 100 ? '...' : '' ?>
                                            </small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="col-12">
                        <div class="text-center py-5">
                            <i class="fas fa-clipboard-list fa-4x text-muted mb-4"></i>
                            <h4 class="text-muted">ไม่พบงาน</h4>
                            <?php if ($filter === 'all'): ?>
                                <p class="text-muted">ยังไม่มีงานที่ได้รับมอบหมาย</p>
                            <?php else: ?>
                                <p class="text-muted">ไม่มีงานในสถานะ "<?= $status_texts[$filter] ?? $filter ?>"</p>
                                <a href="?" class="btn btn-primary">ดูงานทั้งหมด</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Start Job Modal -->
    <div class="modal fade" id="startJobModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">เริ่มงานการผลิต</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="startJobForm">
                    <div class="modal-body">
                        <input type="hidden" id="start_job_id" name="job_id">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            คุณกำลังจะเริ่มงาน: <strong id="start_job_number"></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุการเริ่มงาน</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="บันทึกข้อมูลเพิ่มเติม เช่น การเตรียมพร้อม, ปัญหาที่พบ (ไม่บังคับ)"></textarea>
                        </div>
                        <div class="alert alert-warning">
                            <small><i class="fas fa-lightbulb me-1"></i> 
                            ก่อนเริ่มงาน ควรตรวจสอบว่าได้เบิกวัสดุครบถ้วนแล้ว</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-play me-1"></i>เริ่มงาน
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Progress Modal -->
    <div class="modal fade" id="updateProgressModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">อัพเดทความคืบหน้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="updateProgressForm">
                    <div class="modal-body">
                        <input type="hidden" id="update_job_id" name="job_id">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            งาน: <strong id="update_job_number"></strong>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">จำนวนที่ผลิตได้ <span class="text-danger">*</span></label>
                            <div class="row">
                                <div class="col-8">
                                    <input type="number" class="form-control" id="quantity_produced" name="quantity_produced" required min="0">
                                </div>
                                <div class="col-4">
                                    <div class="text-muted">
                                        <small>จาก <span id="total_planned"></span></small>
                                    </div>
                                </div>
                            </div>
                            <div class="form-text">ระบุจำนวนที่ผลิตได้จริงตั้งแต่เริ่มงาน</div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">สถานะงาน</label>
                            <select class="form-control" name="status" id="job_status">
                                <option value="in_progress">กำลังผลิต</option>
                                <option value="completed">เสร็จแล้ว</option>
                                <option value="cancelled">ยกเลิก</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">หมายเหตุ</label>
                            <textarea class="form-control" name="notes" rows="3" placeholder="บันทึกความคืบหน้า ปัญหาที่พบ หรือข้อมูลเพิ่มเติม"></textarea>
                        </div>
                        
                        <div id="completion-warning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-check-circle me-2"></i>
                            การเปลี่ยนเป็น "เสร็จแล้ว" จะไม่สามารถแก้ไขได้อีก
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Job Details Modal -->
    <div class="modal fade" id="jobDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดงาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="jobDetailsContent">
                        <!-- Job details will be loaded here -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Search functionality
        let searchTimeout;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const searchTerm = this.value;
            
            searchTimeout = setTimeout(() => {
                const currentUrl = new URL(window.location);
                if (searchTerm) {
                    currentUrl.searchParams.set('search', searchTerm);
                } else {
                    currentUrl.searchParams.delete('search');
                }
                window.location.href = currentUrl.toString();
            }, 500);
        });

        // Start Job
        function startJob(jobId, jobNumber) {
            document.getElementById('start_job_id').value = jobId;
            document.getElementById('start_job_number').textContent = jobNumber;
            new bootstrap.Modal(document.getElementById('startJobModal')).show();
        }

        // Update Progress
        function updateProgress(jobId, jobNumber, plannedQty, currentQty) {
            document.getElementById('update_job_id').value = jobId;
            document.getElementById('update_job_number').textContent = jobNumber;
            document.getElementById('quantity_produced').value = currentQty;
            document.getElementById('total_planned').textContent = plannedQty.toLocaleString();
            
            new bootstrap.Modal(document.getElementById('updateProgressModal')).show();
        }

        // Request Materials
        function requestMaterials(jobId, jobNumber) {
            window.location.href = `material-requests.php?action=create&job_id=${jobId}`;
        }

        // View Job Details
        function viewJobDetails(jobId) {
            fetch(`../../api/jobs.php?action=get_detail&job_id=${jobId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayJobDetails(data.job);
                        new bootstrap.Modal(document.getElementById('jobDetailsModal')).show();
                    } else {
                        Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                    }
                })
                .catch(error => {
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถโหลดรายละเอียดงานได้', 'error');
                });
        }

        function displayJobDetails(job) {
            const statusColors = {
                'pending': 'warning',
                'in_progress': 'info', 
                'completed': 'success',
                'cancelled': 'danger'
            };
            
            const statusTexts = {
                'pending': 'รอเริ่มงาน',
                'in_progress': 'กำลังทำ',
                'completed': 'เสร็จแล้ว',
                'cancelled': 'ยกเลิก'
            };

            const progressPercent = job.quantity_planned > 0 ? 
                Math.round((job.quantity_produced / job.quantity_planned) * 100) : 0;

            let html = `
                <div class="row mb-4">
                    <div class="col-md-8">
                        <h4>${job.job_number}</h4>
                        <h6 class="text-muted">${job.product_name} (${job.product_code})</h6>
                    </div>
                    <div class="col-md-4 text-end">
                        <span class="badge bg-${statusColors[job.status]} fs-6">${statusTexts[job.status]}</span>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <strong>ความคืบหน้า:</strong>
                        <div class="progress mt-2" style="height: 25px;">
                            <div class="progress-bar bg-success" style="width: ${progressPercent}%">
                                ${progressPercent}%
                            </div>
                        </div>
                        <small class="text-muted">${job.quantity_produced.toLocaleString()} / ${job.quantity_planned.toLocaleString()} ชิ้น</small>
                    </div>
                    <div class="col-md-6">
                        <strong>วันที่:</strong><br>
                        <small>เริ่ม: ${new Date(job.start_date).toLocaleDateString('th-TH')}</small><br>
                        <small>เสร็จ: ${new Date(job.end_date).toLocaleDateString('th-TH')}</small>
                    </div>
                </div>
                
                <div class="row mb-4">
                    <div class="col-md-6">
                        <strong>สร้างโดย:</strong> ${job.created_by_name}<br>
                        <strong>วันที่สร้าง:</strong> ${new Date(job.created_at).toLocaleDateString('th-TH')}
                    </div>
                    <div class="col-md-6">
                        <strong>ผู้รับผิดชอบ:</strong> ${job.assigned_to_name}
                    </div>
                </div>
            `;

            if (job.notes) {
                html += `
                    <div class="mb-4">
                        <strong>หมายเหตุ:</strong>
                        <div class="p-3 bg-light rounded">${job.notes}</div>
                    </div>
                `;
            }

            if (job.materials && job.materials.length > 0) {
                html += `
                    <div class="mb-4">
                        <strong>วัสดุที่ต้องใช้:</strong>
                        <div class="table-responsive mt-2">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>รหัสวัสดุ</th>
                                        <th>ชื่อวัสดุ</th>
                                        <th class="text-end">จำนวน</th>
                                        <th>หน่วย</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                job.materials.forEach(material => {
                    html += `
                        <tr>
                            <td>${material.part_code}</td>
                            <td>${material.material_name}</td>
                            <td class="text-end"
                                           <td class="text-end">${material.required_quantity.toLocaleString()}</td>
                            <td>${material.unit}</td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
            }

            document.getElementById('jobDetailsContent').innerHTML = html;
        }

        // Form submissions
        document.getElementById('startJobForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            formData.append('status', 'in_progress');
            
            fetch('../../api/jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'เริ่มงานเรียบร้อยแล้ว', 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('startJobModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเริ่มงานได้', 'error');
            });
        });

        document.getElementById('updateProgressForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'update_status');
            
            fetch('../../api/jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', 'อัพเดทความคืบหน้าเรียบร้อยแล้ว', 'success').then(() => {
                        bootstrap.Modal.getInstance(document.getElementById('updateProgressModal')).hide();
                        location.reload();
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถอัพเดทได้', 'error');
            });
        });

        // Show warning when status is completed
        document.getElementById('job_status').addEventListener('change', function() {
            const warning = document.getElementById('completion-warning');
            if (this.value === 'completed') {
                warning.style.display = 'block';
            } else {
                warning.style.display = 'none';
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }

        // Auto refresh every 2 minutes for active jobs
        setInterval(() => {
            if (document.querySelectorAll('.status-in-progress').length > 0) {
                location.reload();
            }
        }, 120000);
    </script>

</body>
</html>