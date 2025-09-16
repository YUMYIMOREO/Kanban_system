<?php
// pages/planning/create-job.php
$page_title = 'สร้างงานการผลิตใหม่';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'การผลิต', 'url' => 'production-jobs.php'],
    ['text' => 'สร้างงานใหม่']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['planning', 'admin']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสินค้า
$products = $db->query("
    SELECT p.*, 
           (SELECT COUNT(*) FROM bom_header bh WHERE bh.product_id = p.product_id AND bh.status = 'active') as has_bom
    FROM products p 
    WHERE p.status = 'active' 
    ORDER BY p.product_name
")->fetchAll();

// ดึงข้อมูลพนักงานแผนกผลิต
$production_users = $db->query("
    SELECT user_id, full_name,
           (SELECT COUNT(*) FROM production_jobs WHERE assigned_to = u.user_id AND status IN ('pending', 'in_progress')) as active_jobs
    FROM users u
    WHERE role = 'production' AND status = 'active' 
    ORDER BY active_jobs ASC, full_name
")->fetchAll();

// ดึงข้อมูลลูกค้า (ถ้ามี)
$customers = [];
try {
    $stmt = $db->query("
        SELECT * 
        FROM customers 
        WHERE status = 'active' 
        ORDER BY customer_name
    ");
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = []; // กัน error ถ้ายังไม่มีตาราง customers
}
?>

<style>
    .wizard-steps {
        display: flex;
        justify-content: center;
        margin-bottom: 40px;
        padding: 20px 0;
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-radius: 15px;
    }

    .wizard-step {
        display: flex;
        align-items: center;
        color: #6c757d;
        position: relative;
        padding: 0 20px;
    }

    .wizard-step:not(:last-child)::after {
        content: '';
        position: absolute;
        right: -20px;
        top: 50%;
        transform: translateY(-50%);
        width: 40px;
        height: 2px;
        background: #dee2e6;
        z-index: 1;
    }

    .wizard-step.active::after,
    .wizard-step.completed::after {
        background: var(--primary-color);
    }

    .step-number {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: #e9ecef;
        color: #6c757d;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        margin-right: 15px;
        font-size: 1.2rem;
        position: relative;
        z-index: 2;
    }

    .wizard-step.active .step-number {
        background: var(--primary-color);
        color: white;
        box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.2);
    }

    .wizard-step.completed .step-number {
        background: var(--success-color);
        color: white;
    }

    .wizard-step.completed .step-number i {
        font-size: 1.5rem;
    }

    .step-info h6 {
        margin: 0;
        font-weight: 600;
    }

    .step-info small {
        color: #6c757d;
    }

    .wizard-step.active .step-info h6,
    .wizard-step.active .step-info small {
        color: var(--primary-color);
    }

    .form-step {
        display: none;
        animation: fadeIn 0.3s ease-in;
    }

    .form-step.active {
        display: block;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .product-card {
        border: 2px solid #e9ecef;
        border-radius: 15px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
        position: relative;
    }

    .product-card:hover {
        border-color: var(--primary-color);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.2);
        transform: translateY(-2px);
    }

    .product-card.selected {
        border-color: var(--primary-color);
        background: rgba(102, 126, 234, 0.05);
    }

    .product-card .check-icon {
        position: absolute;
        top: 10px;
        right: 10px;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--success-color);
        color: white;
        display: none;
        align-items: center;
        justify-content: center;
    }

    .product-card.selected .check-icon {
        display: flex;
    }

    .bom-status {
        position: absolute;
        top: 10px;
        left: 10px;
        padding: 4px 8px;
        border-radius: 20px;
        font-size: 0.7rem;
        font-weight: 600;
    }

    .bom-available {
        background: #d4edda;
        color: #155724;
    }

    .bom-missing {
        background: #f8d7da;
        color: #721c24;
    }

    .material-requirement {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 10px;
        border-left: 4px solid var(--primary-color);
        transition: all 0.3s ease;
    }

    .material-requirement:hover {
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }

    .stock-indicator {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: inline-block;
        margin-right: 8px;
    }

    .stock-sufficient {
        background: #28a745;
    }

    .stock-low {
        background: #ffc107;
    }

    .stock-insufficient {
        background: #dc3545;
    }

    .summary-section {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px;
        padding: 25px;
        margin-bottom: 20px;
    }

    .calculation-loading {
        text-align: center;
        padding: 60px 20px;
    }

    .spinner-custom {
        width: 60px;
        height: 60px;
        border: 6px solid rgba(102, 126, 234, 0.3);
        border-top: 6px solid var(--primary-color);
        border-radius: 50%;
        animation: spin 1s linear infinite;
        margin: 0 auto 20px;
    }

    @keyframes spin {
        0% {
            transform: rotate(0deg);
        }

        100% {
            transform: rotate(360deg);
        }
    }

    .priority-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: 600;
    }

    .priority-normal {
        background: #e9ecef;
        color: #495057;
    }

    .priority-high {
        background: #fff3cd;
        color: #856404;
    }

    .priority-urgent {
        background: #f8d7da;
        color: #721c24;
    }

    .navigation-buttons {
        display: flex;
        justify-content: space-between;
        margin-top: 30px;
        padding: 20px 0;
        border-top: 1px solid #dee2e6;
    }

    .btn-wizard {
        min-width: 120px;
        padding: 12px 30px;
        border-radius: 25px;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .btn-wizard:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
    }
</style>

<!-- Wizard Steps -->
<div class="wizard-steps">
    <div class="wizard-step active" id="wizard-step-1">
        <div class="step-number">1</div>
        <div class="step-info">
            <h6>เลือกสินค้า</h6>
            <small>เลือกสินค้าที่ต้องการผลิต</small>
        </div>
    </div>

    <div class="wizard-step" id="wizard-step-2">
        <div class="step-number">2</div>
        <div class="step-info">
            <h6>ข้อมูลการผลิต</h6>
            <small>กรอกรายละเอียดงาน</small>
        </div>
    </div>

    <div class="wizard-step" id="wizard-step-3">
        <div class="step-number">3</div>
        <div class="step-info">
            <h6>คำนวณวัสดุ</h6>
            <small>ตรวจสอบความต้องการวัสดุ</small>
        </div>
    </div>

    <div class="wizard-step" id="wizard-step-4">
        <div class="step-number">4</div>
        <div class="step-info">
            <h6>ยืนยันและสร้าง</h6>
            <small>ตรวจสอบและสร้างงาน</small>
        </div>
    </div>
</div>

<form id="createJobForm" enctype="multipart/form-data">
    <!-- Step 1: Product Selection -->
    <div class="form-step active" id="form-step-1">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-boxes me-2"></i>เลือกสินค้าที่ต้องการผลิต</h5>
                <p class="mb-0 text-muted">เลือกสินค้า 1 รายการสำหรับงานการผลิตนี้</p>
            </div>
            <div class="card-body">
                <?php if (!empty($products)): ?>
                    <div class="row">
                        <?php foreach ($products as $product): ?>
                            <div class="col-lg-4 col-md-6 mb-4">
                                <div class="product-card" onclick="selectProduct(<?= $product['product_id'] ?>, '<?= htmlspecialchars($product['product_name']) ?>', '<?= htmlspecialchars($product['product_code']) ?>', <?= $product['has_bom'] ?>)">
                                    <div class="bom-status <?= $product['has_bom'] ? 'bom-available' : 'bom-missing' ?>">
                                        <?= $product['has_bom'] ? '✓ มี BOM' : '✗ ไม่มี BOM' ?>
                                    </div>
                                    <div class="check-icon">
                                        <i class="fas fa-check"></i>
                                    </div>

                                    <div class="text-center mb-3">
                                        <div class="product-icon mb-3">
                                            <i class="fas fa-cube fa-3x text-primary"></i>
                                        </div>
                                        <h6 class="mb-1"><?= htmlspecialchars($product['product_name']) ?></h6>
                                        <small class="text-muted"><?= htmlspecialchars($product['product_code']) ?></small>
                                    </div>

                                    <?php if (!empty($product['description'])): ?>
                                        <p class="text-muted small mb-0">
                                            <?= htmlspecialchars(mb_substr($product['description'], 0, 80)) ?>
                                            <?= mb_strlen($product['description']) > 80 ? '...' : '' ?>
                                        </p>
                                    <?php endif; ?>

                                    <?php if (!$product['has_bom']): ?>
                                        <div class="alert alert-warning mt-3 py-2 mb-0">
                                            <small><i class="fas fa-exclamation-triangle me-1"></i>ยังไม่มี BOM ไม่สามารถคำนวณวัสดุได้</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <input type="hidden" id="selected_product_id" name="product_id">
                    <input type="hidden" id="selected_product_name">
                    <input type="hidden" id="selected_product_code">
                    <input type="hidden" id="selected_has_bom">

                    <div id="product-selection-info" class="alert alert-info" style="display: none;">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-info-circle fa-2x me-3"></i>
                            <div>
                                <h6 class="mb-1">สินค้าที่เลือก</h6>
                                <span id="selected-product-display"></span>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        ไม่พบสินค้าในระบบ กรุณาเพิ่มสินค้าก่อนสร้างงานการผลิต
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Step 2: Job Details -->
    <div class="form-step" id="form-step-2">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-edit me-2"></i>ข้อมูลงานการผลิต</h5>
                <p class="mb-0 text-muted">กรอกรายละเอียดการผลิต</p>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="quantity_planned" class="form-label">
                                <i class="fas fa-sort-numeric-up me-1"></i>
                                จำนวนที่ต้องผลิต <span class="text-danger">*</span>
                            </label>
                            <input type="number" class="form-control form-control-lg"
                                id="quantity_planned" name="quantity_planned"
                                required min="1" placeholder="ระบุจำนวนที่ต้องผลิต"
                                oninput="updateMaterialCalculation()">
                            <div class="form-text">
                                <i class="fas fa-lightbulb me-1"></i>
                                ระบุจำนวนชิ้นที่ต้องการผลิต
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="priority" class="form-label">
                                <i class="fas fa-flag me-1"></i>
                                ความสำคัญ
                            </label>
                            <select class="form-control form-control-lg" id="priority" name="priority">
                                <option value="normal">ปกติ</option>
                                <option value="high">สำคัญ</option>
                                <option value="urgent">เร่งด่วน</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="start_date" class="form-label">
                                <i class="fas fa-calendar-plus me-1"></i>
                                วันที่เริ่มผลิต <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control form-control-lg"
                                id="start_date" name="start_date" required>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="end_date" class="form-label">
                                <i class="fas fa-calendar-check me-1"></i>
                                วันที่ต้องเสร็จ <span class="text-danger">*</span>
                            </label>
                            <input type="date" class="form-control form-control-lg"
                                id="end_date" name="end_date" required>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="assigned_to" class="form-label">
                                <i class="fas fa-user-cog me-1"></i>
                                ผู้รับผิดชอบ <span class="text-danger">*</span>
                            </label>
                            <select class="form-control form-control-lg" id="assigned_to" name="assigned_to" required>
                                <option value="">เลือกผู้รับผิดชอบ</option>
                                <?php foreach ($production_users as $user): ?>
                                    <option value="<?= $user['user_id'] ?>">
                                        <?= htmlspecialchars($user['full_name']) ?>
                                        <?php if ($user['active_jobs'] > 0): ?>
                                            <small>(กำลังทำ <?= $user['active_jobs'] ?> งาน)</small>
                                        <?php else: ?>
                                            <small>(ว่าง)</small>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="col-lg-6">
                        <div class="mb-4">
                            <label for="customer_id" class="form-label">
                                <i class="fas fa-building me-1"></i>
                                ลูกค้า (ไม่บังคับ)
                            </label>
                            <select class="form-control form-control-lg" id="customer_id" name="customer_id">
                                <option value="">ไม่ระบุลูกค้า</option>
                                <?php if (!empty($customers)): ?>
                                    <?php foreach ($customers as $customer): ?>
                                        <option value="<?= $customer['customer_id'] ?>">
                                            <?= htmlspecialchars($customer['customer_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="notes" class="form-label">
                        <i class="fas fa-sticky-note me-1"></i>
                        หมายเหตุ / รายละเอียดเพิ่มเติม
                    </label>
                    <textarea class="form-control" id="notes" name="notes" rows="4"
                        placeholder="ระบุรายละเอียดเพิ่มเติม เช่น ข้อกำหนดพิเศษ, การบรรจุ, คุณภาพ (ไม่บังคับ)"></textarea>
                </div>

                <!-- Quick Calculation Preview -->
                <div id="quick-calculation" class="alert alert-light border" style="display: none;">
                    <h6><i class="fas fa-calculator me-2"></i>ตัวอย่างการคำนวณ</h6>
                    <div id="quick-calc-content">
                        <!-- จะแสดงการคำนวณเบื้องต้นที่นี่ -->
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 3: Material Calculation -->
    <div class="form-step" id="form-step-3">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-calculator me-2"></i>คำนวณความต้องการวัสดุ</h5>
                <p class="mb-0 text-muted">ระบบจะคำนวณวัสดุที่ต้องใช้จาก BOM อัตโนมัติ</p>
            </div>
            <div class="card-body">
                <div id="material-calculation">
                    <div class="calculation-loading">
                        <div class="spinner-custom"></div>
                        <h5>กำลังคำนวณความต้องการวัสดุ...</h5>
                        <p class="text-muted">กรุณารอสักครู่</p>
                    </div>
                </div>

                <div id="material-results" style="display: none;">
                    <!-- ผลการคำนวณจะแสดงที่นี่ -->
                </div>
            </div>
        </div>
    </div>

    <!-- Step 4: Summary & Confirmation -->
    <div class="form-step" id="form-step-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="fas fa-clipboard-check me-2"></i>สรุปและยืนยันการสร้างงาน</h5>
                <p class="mb-0 text-muted">ตรวจสอบรายละเอียดก่อนสร้างงาน</p>
            </div>
            <div class="card-body">
                <div class="summary-section">
                    <div class="row">
                        <div class="col-lg-6">
                            <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลงาน</h6>
                            <table class="table table-borderless text-white mb-0">
                                <tr>
                                    <td><strong>สินค้า:</strong></td>
                                    <td id="summary-product">-</td>
                                </tr>
                                <tr>
                                    <td><strong>จำนวน:</strong></td>
                                    <td id="summary-quantity">-</td>
                                </tr>
                                <tr>
                                    <td><strong>ผู้รับผิดชอบ:</strong></td>
                                    <td id="summary-assigned">-</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-lg-6">
                            <h6><i class="fas fa-calendar me-2"></i>กำหนดเวลา</h6>
                            <table class="table table-borderless text-white mb-0">
                                <tr>
                                    <td><strong>วันที่เริ่ม:</strong></td>
                                    <td id="summary-start-date">-</td>
                                </tr>
                                <tr>
                                    <td><strong>วันที่เสร็จ:</strong></td>
                                    <td id="summary-end-date">-</td>
                                </tr>
                                <tr>
                                    <td><strong>ความสำคัญ:</strong></td>
                                    <td id="summary-priority">-</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>

                <div id="final-material-summary">
                    <!-- สรุปวัสดุจะแสดงที่นี่ -->
                </div>

                <div class="row mt-4">
                    <div class="col-lg-6">
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>หมายเหตุสำคัญ</h6>
                            <ul class="mb-0">
                                <li>งานจะถูกสร้างในสถานะ "รอเริ่มงาน"</li>
                                <li>วัสดุจะถูกตัดสต็อกเมื่อแผนกผลิตเบิกจริง</li>
                                <li>สามารถแก้ไขงานได้ก่อนเริ่มการผลิต</li>
                            </ul>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div id="readiness-status">
                            <!-- สถานะความพร้อม -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Buttons -->
    <div class="navigation-buttons">
        <button type="button" class="btn btn-secondary btn-wizard" id="btn-prev" onclick="previousStep()" style="display: none;">
            <i class="fas fa-arrow-left me-2"></i>ก่อนหน้า
        </button>

        <div>
            <button type="button" class="btn btn-outline-secondary btn-wizard me-2" onclick="saveDraft()">
                <i class="fas fa-save me-2"></i>บันทึกแบบร่าง
            </button>
            <button type="button" class="btn btn-primary btn-wizard" id="btn-next" onclick="nextStep()">
                ถัดไป<i class="fas fa-arrow-right ms-2"></i>
            </button>
            <button type="submit" class="btn btn-success btn-wizard" id="btn-submit" style="display: none;">
                <i class="fas fa-check me-2"></i>สร้างงานการผลิต
            </button>
        </div>
    </div>
</form>

</div>
</div>

<!-- JavaScript Libraries -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    let currentStep = 1;
    let totalSteps = 4;
    let materialRequirements = [];
    let stockAvailability = [];
    let selectedProductId = null;
    let selectedProductName = '';
    let selectedProductCode = '';
    let selectedHasBom = false;

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        setDefaultDates();
        updateNavigationButtons();
    });

    function setDefaultDates() {
        const today = new Date();
        const tomorrow = new Date(today);
        tomorrow.setDate(tomorrow.getDate() + 1);
        const weekLater = new Date(today);
        weekLater.setDate(weekLater.getDate() + 7);

        document.getElementById('start_date').value = tomorrow.toISOString().split('T')[0];
        document.getElementById('end_date').value = weekLater.toISOString().split('T')[0];
    }

    function selectProduct(productId, productName, productCode, hasBom) {
        // Remove previous selections
        document.querySelectorAll('.product-card').forEach(card => {
            card.classList.remove('selected');
        });

        // Select current product
        event.currentTarget.classList.add('selected');

        // Store selection
        selectedProductId = productId;
        selectedProductName = productName;
        selectedProductCode = productCode;
        selectedHasBom = hasBom > 0;

        // Update form fields
        document.getElementById('selected_product_id').value = productId;
        document.getElementById('selected_product_name').value = productName;
        document.getElementById('selected_product_code').value = productCode;
        document.getElementById('selected_has_bom').value = hasBom;

        // Show selection info
        document.getElementById('selected-product-display').innerHTML =
            `<strong>${productName}</strong> (${productCode})`;
        document.getElementById('product-selection-info').style.display = 'block';

        // Enable next button
        document.getElementById('btn-next').disabled = false;
    }

    function nextStep() {
        if (!validateCurrentStep()) {
            return;
        }

        // Update step
        currentStep++;

        // Handle specific step actions
        if (currentStep === 3) {
            calculateMaterials();
        } else if (currentStep === 4) {
            prepareSummary();
        }

        updateWizardSteps();
        updateNavigationButtons();
        showCurrentStep();
    }

    function previousStep() {
        currentStep--;
        updateWizardSteps();
        updateNavigationButtons();
        showCurrentStep();
    }

    function validateCurrentStep() {
        switch (currentStep) {
            case 1:
                if (!selectedProductId) {
                    Swal.fire('กรุณาเลือกสินค้า', 'เลือกสินค้าที่ต้องการผลิต', 'warning');
                    return false;
                }
                break;

            case 2:
                const requiredFields = ['quantity_planned', 'start_date', 'end_date', 'assigned_to'];
                for (const field of requiredFields) {
                    const element = document.getElementById(field);
                    if (!element.value.trim()) {
                        element.focus();
                        Swal.fire('กรุณากรอกข้อมูล', `กรุณากรอก${element.previousElementSibling.textContent}`, 'warning');
                        return false;
                    }
                }

                // Validate dates
                const startDate = new Date(document.getElementById('start_date').value);
                const endDate = new Date(document.getElementById('end_date').value);
                const today = new Date();
                today.setHours(0, 0, 0, 0);

                if (startDate < today) {
                    Swal.fire('วันที่ไม่ถูกต้อง', 'วันที่เริ่มต้องไม่เป็นอดีต', 'warning');
                    return false;
                }

                if (endDate <= startDate) {
                    Swal.fire('วันที่ไม่ถูกต้อง', 'วันที่สิ้นสุดต้องมากกว่าวันที่เริ่ม', 'warning');
                    return false;
                }

                const quantity = parseInt(document.getElementById('quantity_planned').value);
                if (quantity <= 0) {
                    Swal.fire('จำนวนไม่ถูกต้อง', 'จำนวนที่ต้องผลิตต้องมากกว่า 0', 'warning');
                    return false;
                }
                break;
        }
        return true;
    }

    function updateWizardSteps() {
        for (let i = 1; i <= totalSteps; i++) {
            const stepEl = document.getElementById(`wizard-step-${i}`);
            const numberEl = stepEl.querySelector('.step-number');

            stepEl.classList.remove('active', 'completed');

            if (i < currentStep) {
                stepEl.classList.add('completed');
                numberEl.innerHTML = '<i class="fas fa-check"></i>';
            } else if (i === currentStep) {
                stepEl.classList.add('active');
                numberEl.textContent = i;
            } else {
                numberEl.textContent = i;
            }
        }
    }

    function updateNavigationButtons() {
        const btnPrev = document.getElementById('btn-prev');
        const btnNext = document.getElementById('btn-next');
        const btnSubmit = document.getElementById('btn-submit');

        // Previous button
        btnPrev.style.display = currentStep > 1 ? 'block' : 'none';

        // Next/Submit buttons
        if (currentStep < totalSteps) {
            btnNext.style.display = 'block';
            btnSubmit.style.display = 'none';
            btnNext.disabled = currentStep === 1 && !selectedProductId;
        } else {
            btnNext.style.display = 'none';
            btnSubmit.style.display = 'block';
        }
    }

    function showCurrentStep() {
        document.querySelectorAll('.form-step').forEach(step => {
            step.classList.remove('active');
        });
        document.getElementById(`form-step-${currentStep}`).classList.add('active');
    }

    function calculateMaterials() {
        if (!selectedHasBom) {
            document.getElementById('material-calculation').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <h5>ไม่พบ BOM สำหรับสินค้านี้</h5>
                        <p class="mb-3">สินค้า ${selectedProductName} ยังไม่มี Bill of Materials (BOM) 
                           ระบบไม่สามารถคำนวณวัสดุที่ต้องใช้ได้</p>
                        <div class="d-flex gap-2">
                            <a href="../admin/bom.php?action=create&product_id=${selectedProductId}" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i>สร้าง BOM
                            </a>
                            <button class="btn btn-outline-primary btn-sm" onclick="skipMaterialCalculation()">
                                <i class="fas fa-forward me-1"></i>ข้ามขั้นตอนนี้
                            </button>
                        </div>
                    </div>
                `;
            return;
        }

        const quantity = document.getElementById('quantity_planned').value;

        fetch(`../../api/bom.php?action=calculate&product_id=${selectedProductId}&quantity=${quantity}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    materialRequirements = data.materials;
                    displayMaterialRequirements();
                    checkStockAvailability();
                } else {
                    document.getElementById('material-calculation').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <h5>เกิดข้อผิดพลาด</h5>
                                <p>${data.message}</p>
                            </div>
                        `;
                }
            })
            .catch(error => {
                document.getElementById('material-calculation').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <h5>เกิดข้อผิดพลาด</h5>
                            <p>ไม่สามารถคำนวณความต้องการวัสดุได้</p>
                        </div>
                    `;
            });
    }

    function displayMaterialRequirements() {
        let html = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    ระบบคำนวณความต้องการวัสดุจาก BOM แล้ว กำลังตรวจสอบสต็อกคงเหลือ...
                </div>
                <h6 class="mb-3"><i class="fas fa-list me-2"></i>รายการวัสดุที่ต้องใช้</h6>
            `;

        materialRequirements.forEach(material => {
            html += `
                    <div class="material-requirement">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="mb-1">
                                    <span class="stock-indicator" id="stock-${material.material_id}"></span>
                                    ${material.part_code} - ${material.material_name}
                                </h6>
                                <small class="text-muted">
                                    ${material.quantity_per_unit} ${material.unit}/ชิ้น × ${document.getElementById('quantity_planned').value} ชิ้น
                                </small>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-primary fs-6">${material.required_quantity.toLocaleString()}</span>
                                <div class="small text-muted">${material.unit}</div>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">คงเหลือ: </small>
                            <span id="current-stock-${material.material_id}" class="badge bg-secondary">กำลังตรวจสอบ...</span>
                        </div>
                    </div>
                `;
        });

        document.getElementById('material-calculation').innerHTML = html;
        document.getElementById('material-results').style.display = 'block';
    }

    function checkStockAvailability() {
        const materialData = materialRequirements.map(m => ({
            material_id: m.material_id,
            required_quantity: m.required_quantity
        }));

        fetch('../../api/inventory.php?action=check_availability', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    materials: materialData
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    stockAvailability = data.availability;
                    updateStockDisplay();
                }
            })
            .catch(error => {
                console.error('Error checking stock:', error);
            });
    }

    function updateStockDisplay() {
        stockAvailability.forEach(item => {
            const indicator = document.getElementById(`stock-${item.material_id}`);
            const stockSpan = document.getElementById(`current-stock-${item.material_id}`);

            if (indicator) {
                if (item.sufficient) {
                    indicator.className = 'stock-indicator stock-sufficient';
                    stockSpan.className = 'badge bg-success';
                    stockSpan.textContent = `${item.current_stock.toLocaleString()} (เพียงพอ)`;
                } else {
                    indicator.className = 'stock-indicator stock-insufficient';
                    stockSpan.className = 'badge bg-danger';
                    stockSpan.textContent = `${item.current_stock.toLocaleString()} (ไม่พียงพอ)`;
                }
            }
        });
    }

    function skipMaterialCalculation() {
        materialRequirements = [];
        stockAvailability = [];
        document.getElementById('material-results').innerHTML = `
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    ข้ามการคำนวณวัสดุแล้ว จะต้องเบิกวัสดุด้วยตนเองหลังจากสร้างงาน
                </div>
            `;
    }

    function prepareSummary() {
        // Update job summary
        const product = `${selectedProductName} (${selectedProductCode})`;
        const quantity = parseInt(document.getElementById('quantity_planned').value).toLocaleString();
        const assignedSelect = document.getElementById('assigned_to');
        const assigned = assignedSelect.options[assignedSelect.selectedIndex].text;
        const startDate = new Date(document.getElementById('start_date').value).toLocaleDateString('th-TH');
        const endDate = new Date(document.getElementById('end_date').value).toLocaleDateString('th-TH');
        const prioritySelect = document.getElementById('priority');
        const priority = prioritySelect.options[prioritySelect.selectedIndex].text;

        document.getElementById('summary-product').textContent = product;
        document.getElementById('summary-quantity').textContent = quantity + ' ชิ้น';
        document.getElementById('summary-assigned').textContent = assigned;
        document.getElementById('summary-start-date').textContent = startDate;
        document.getElementById('summary-end-date').textContent = endDate;
        document.getElementById('summary-priority').innerHTML =
            `<span class="priority-badge priority-${document.getElementById('priority').value}">${priority}</span>`;

        // Material summary
        if (materialRequirements.length > 0) {
            let materialHtml = `
                    <h6 class="mb-3"><i class="fas fa-boxes me-2"></i>สรุปความต้องการวัสดุ</h6>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>รหัสวัสดุ</th>
                                    <th>ชื่อวัสดุ</th>
                                    <th class="text-end">ต้องการ</th>
                                    <th class="text-end">คงเหลือ</th>
                                    <th class="text-center">สถานะ</th>
                                </tr>
                            </thead>
                            <tbody>
                `;

            let allSufficient = true;
            stockAvailability.forEach(item => {
                const statusClass = item.sufficient ? 'text-success' : 'text-danger';
                const statusText = item.sufficient ? '✓ เพียงพอ' : '✗ ไม่เพียงพอ';

                if (!item.sufficient) allSufficient = false;

                materialHtml += `
                        <tr>
                            <td><strong>${item.part_code}</strong></td>
                            <td>${item.material_name}</td>
                            <td class="text-end">${item.required_quantity.toLocaleString()} ${item.unit}</td>
                            <td class="text-end">${item.current_stock.toLocaleString()} ${item.unit}</td>
                            <td class="text-center ${statusClass}"><strong>${statusText}</strong></td>
                        </tr>
                    `;
            });

            materialHtml += `
                            </tbody>
                        </table>
                    </div>
                `;

            document.getElementById('final-material-summary').innerHTML = materialHtml;

            // Readiness status
            const readinessHtml = allSufficient ? `
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>พร้อมสำหรับการผลิต</h6>
                        <p class="mb-0">วัสดุทุกรายการเพียงพอสำหรับการผลิต สามารถเริ่มงานได้ทันที</p>
                    </div>
                ` : `
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>ต้องการสั่งซื้อวัสดุเพิ่ม</h6>
                        <p class="mb-0">มีวัสดุบางรายการไม่เพียงพอ ควรสั่งซื้อก่อนเริ่มการผลิต</p>
                    </div>
                `;

            document.getElementById('readiness-status').innerHTML = readinessHtml;
        } else {
            document.getElementById('final-material-summary').innerHTML = `
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>ไม่มีการคำนวณวัสดุ</h6>
                        <p class="mb-0">จะต้องจัดการวัสดุด้วยตนเองหลังจากสร้างงาน</p>
                    </div>
                `;

            document.getElementById('readiness-status').innerHTML = `
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-tools me-2"></i>ต้องจัดการวัสดุเอง</h6>
                        <p class="mb-0">ต้องตรวจสอบและเบิกวัสดุด้วยตนเองหลังจากสร้างงาน</p>
                    </div>
                `;
        }
    }

    function saveDraft() {
        Swal.fire({
            title: 'บันทึกแบบร่าง',
            text: 'ฟีเจอร์นี้จะเปิดใช้งานในอนาคต',
            icon: 'info'
        });
    }

    function updateMaterialCalculation() {
        const quantity = document.getElementById('quantity_planned').value;
        if (quantity && selectedHasBom && materialRequirements.length > 0) {
            // Update quick calculation if available
            let quickCalcHtml = '<div class="row">';
            materialRequirements.slice(0, 3).forEach(material => {
                const required = material.quantity_per_unit * quantity;
                quickCalcHtml += `
                        <div class="col-md-4">
                            <strong>${material.part_code}:</strong><br>
                            <span class="text-primary">${required.toLocaleString()} ${material.unit}</span>
                        </div>
                    `;
            });
            quickCalcHtml += '</div>';

            document.getElementById('quick-calc-content').innerHTML = quickCalcHtml;
            document.getElementById('quick-calculation').style.display = 'block';
        }
    }

    // Form submission
    document.getElementById('createJobForm').addEventListener('submit', function(e) {
        e.preventDefault();

        Swal.fire({
            title: 'ยืนยันการสร้างงาน?',
            text: `สร้างงานการผลิต ${selectedProductName} จำนวน ${document.getElementById('quantity_planned').value} ชิ้น`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'สร้างงาน',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                submitJob();
            }
        });
    });

    function submitJob() {
        const formData = new FormData(document.getElementById('createJobForm'));
        formData.append('action', 'create');
        formData.append('material_requirements', JSON.stringify(materialRequirements));
        formData.append('stock_availability', JSON.stringify(stockAvailability));

        // Show loading
        Swal.fire({
            title: 'กำลังสร้างงาน...',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('../../api/jobs.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire({
                        title: 'สำเร็จ!',
                        html: `
                                <div class="text-center">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h5>สร้างงานการผลิตเรียบร้อย</h5>
                                    <p><strong>เลขที่งาน:</strong> ${data.job_number}</p>
                                    <p><strong>สินค้า:</strong> ${selectedProductName}</p>
                                    <p><strong>จำนวน:</strong> ${document.getElementById('quantity_planned').value} ชิ้น</p>
                                </div>
                            `,
                        icon: 'success',
                        confirmButtonText: 'ดูรายการงาน',
                        showCancelButton: true,
                        cancelButtonText: 'สร้างงานใหม่'
                    }).then((result) => {
                        if (result.isConfirmed) {
                            window.location.href = 'production-jobs.php';
                        } else {
                            location.reload();
                        }
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถสร้างงานการผลิตได้', 'error');
            });
    }

    // Toggle Sidebar for Mobile
    function toggleSidebar() {
        const sidebar = document.querySelector('.sidebar');
        sidebar.classList.toggle('show');
    }

    // Initialize first step
    showCurrentStep();
    updateNavigationButtons();
    updateWizardSteps();
</script>

</body>

</html>