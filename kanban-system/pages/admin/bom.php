<?php
// pages/admin/bom.php
$page_title = 'จัดการ BOM (Bill of Materials)';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'จัดการระบบ'],
    ['text' => 'จัดการ BOM']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['admin', 'planning']);

$database = new Database();
$db = $database->getConnection();

// ดึงข้อมูลสินค้า
$products = $db->query("SELECT * FROM products WHERE status = 'active' ORDER BY product_name")->fetchAll();

// ดึงข้อมูลวัสดุ
$materials = $db->query("SELECT * FROM materials WHERE status = 'active' ORDER BY part_code")->fetchAll();
?>

<style>
    .bom-card {
        border: none;
        border-radius: 15px;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        transition: all 0.3s ease;
        margin-bottom: 20px;
    }
    
    .bom-card:hover {
        box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }
    
    .product-header {
        background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
        color: white;
        border-radius: 15px 15px 0 0;
        padding: 20px;
    }
    
    .bom-item {
        background: #f8f9fa;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 10px;
        border-left: 4px solid var(--primary-color);
        transition: all 0.3s ease;
    }
    
    .bom-item:hover {
        background: #e9ecef;
        transform: translateX(5px);
    }
    
    .quantity-input {
        width: 100px;
        text-align: center;
    }

        .material-selector {
        border: 2px dashed #dee2e6;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .material-selector:hover {
        border-color: var(--primary-color);
        background: rgba(102, 126, 234, 0.05);
    }
    
    .material-search {
        position: relative;
    }
    
    .material-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }
    
    .material-dropdown-item {
        padding: 10px 15px;
        cursor: pointer;
        border-bottom: 1px solid #f1f3f4;
    }
    
    .material-dropdown-item:hover {
        background: #f8f9fa;
    }
    
    .material-dropdown-item:last-child {
        border-bottom: none;
    }
    
    .bom-summary {
        background: linear-gradient(135deg, #e3f2fd, #bbdefb);
        border-radius: 10px;
        padding: 20px;
        margin-top: 20px;
    }
    
    .calculation-preview {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 10px;
        padding: 15px;
        margin-top: 15px;
    }
</style>

            <div class="row">
                <!-- รายการ BOM -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5><i class="fas fa-list-alt me-2"></i>รายการ BOM ทั้งหมด</h5>
                            <div>
                                <button class="btn btn-primary" onclick="showCreateBOMModal()">
                                    <i class="fas fa-plus me-1"></i>สร้าง BOM ใหม่
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="bomList">
                                <!-- รายการ BOM จะแสดงที่นี่ -->
                            </div>
                        </div>
                    </div>
                </div>

                <!-- เครื่องมือ -->
                <div class="col-lg-4">
                    <!-- เครื่องคิดเลข BOM -->
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5><i class="fas fa-calculator me-2"></i>เครื่องคิดเลข BOM</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">เลือกสินค้า</label>
                                <select class="form-control" id="calc_product_id" onchange="loadBOMForCalculation()">
                                    <option value="">เลือกสินค้า</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['product_id'] ?>">
                                            <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['product_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">จำนวนที่ต้องผลิต</label>
                                <input type="number" class="form-control" id="calc_quantity" min="1" placeholder="ระบุจำนวน" oninput="calculateMaterials()">
                            </div>
                            
                            <div id="calculation-result">
                                <div class="text-center text-muted py-4">
                                    <i class="fas fa-calculator fa-3x mb-3"></i>
                                    <p>เลือกสินค้าและระบุจำนวนเพื่อคำนวณ</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- สถิติ BOM -->
                    <div class="card">
                        <div class="card-header">
                            <h5><i class="fas fa-chart-pie me-2"></i>สถิติ BOM</h5>
                        </div>
                        <div class="card-body">
                            <div id="bomStats">
                                <!-- สถิติจะแสดงที่นี่ -->
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Create/Edit BOM Modal -->
    <div class="modal fade" id="bomModal" tabindex="-1" data-bs-backdrop="static">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="bomModalTitle">สร้าง BOM ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="bomForm">
                    <div class="modal-body">
                        <input type="hidden" id="bom_id" name="bom_id">
                        <input type="hidden" id="form_action" name="action" value="create">
                        
                        <div class="row mb-4">
                            <div class="col-md-8">
                                <label for="product_id" class="form-label">สินค้า <span class="text-danger">*</span></label>
                                <select class="form-control form-control-lg" id="product_id" name="product_id" required onchange="checkExistingBOM()">
                                    <option value="">เลือกสินค้า</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= $product['product_id'] ?>">
                                            <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['product_code']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="version" class="form-label">เวอร์ชัน</label>
                                <input type="text" class="form-control form-control-lg" id="version" name="version" value="1.0" placeholder="เช่น 1.0, 1.1">
                            </div>
                        </div>
                        
                        <div id="existing-bom-warning" class="alert alert-warning" style="display: none;">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            สินค้านี้มี BOM อยู่แล้ว การสร้างใหม่จะเป็นเวอร์ชันใหม่
                        </div>
                        
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6><i class="fas fa-boxes me-2"></i>รายการวัสดุ</h6>
                            <button type="button" class="btn btn-success btn-sm" onclick="addMaterialRow()">
                                <i class="fas fa-plus me-1"></i>เพิ่มวัสดุ
                            </button>
                        </div>
                        
                        <div id="materials-container">
                            <div class="material-selector" onclick="addMaterialRow()">
                                <i class="fas fa-plus fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">คลิกเพื่อเพิ่มวัสดุแรก</p>
                            </div>
                        </div>
                        
                        <div class="bom-summary" id="bom-summary" style="display: none;">
                            <h6><i class="fas fa-chart-bar me-2"></i>สรุป BOM</h6>
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>จำนวนวัสดุ:</strong> <span id="total-materials">0</span> รายการ
                                </div>
                                <div class="col-md-4">
                                    <strong>ต้นทุนรวม:</strong> <span id="total-cost">-</span> บาท
                                </div>
                                <div class="col-md-4">
                                    <strong>สถานะ:</strong> <span id="bom-status" class="badge bg-success">พร้อมใช้งาน</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="button" class="btn btn-info" onclick="previewBOM()">
                            <i class="fas fa-eye me-1"></i>ดูตัวอย่าง
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>บันทึก BOM
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- BOM Preview Modal -->
    <div class="modal fade" id="bomPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">ตัวอย่าง BOM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="bom-preview-content">
                        <!-- Preview content -->
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
                    <button type="button" class="btn btn-primary" onclick="printBOM()">
                        <i class="fas fa-print me-1"></i>พิมพ์
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Copy BOM Modal -->
    <div class="modal fade" id="copyBOMModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">คัดลอก BOM</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form id="copyBOMForm">
                    <div class="modal-body">
                        <input type="hidden" id="source_bom_id" name="source_bom_id">
                        
                        <div class="mb-3">
                            <label class="form-label">สินค้าต้นฉบับ</label>
                            <input type="text" class="form-control" id="source_product" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label for="target_product_id" class="form-label">สินค้าปลายทาง <span class="text-danger">*</span></label>
                            <select class="form-control" id="target_product_id" name="target_product_id" required>
                                <option value="">เลือกสินค้าที่ต้องการคัดลอกไป</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= $product['product_id'] ?>">
                                        <?= htmlspecialchars($product['product_name']) ?> (<?= htmlspecialchars($product['product_code']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="new_version" class="form-label">เวอร์ชันใหม่</label>
                            <input type="text" class="form-control" id="new_version" name="new_version" value="1.0">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-copy me-1"></i>คัดลอก
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.11.5/js/dataTables.bootstrap5.min.js"></script>

    <script>
        let materialRowCount = 0;
        let materialsData = <?= json_encode($materials) ?>;
        let currentBOMData = [];

        document.addEventListener('DOMContentLoaded', function() {
            loadBOMList();
            loadBOMStats();
        });

        function loadBOMList() {
            fetch('../../api/bom.php?action=get_all')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayBOMList(data.boms);
                    }
                })
                .catch(error => {
                    console.error('Error loading BOM list:', error);
                });
        }

        function displayBOMList(boms) {
            const container = document.getElementById('bomList');
            
            if (boms.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-5">
                        <i class="fas fa-list-alt fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">ยังไม่มี BOM</h5>
                        <p class="text-muted">เริ่มสร้าง BOM แรกของคุณ</p>
                        <button class="btn btn-primary" onclick="showCreateBOMModal()">
                            <i class="fas fa-plus me-1"></i>สร้าง BOM ใหม่
                        </button>
                    </div>
                `;
                return;
            }

            let html = '';
            boms.forEach(bom => {
                html += `
                    <div class="bom-card">
                        <div class="product-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1">${bom.product_name}</h5>
                                    <small>รหัส: ${bom.product_code} | เวอร์ชัน: ${bom.version}</small>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark">${bom.material_count} รายการ</span>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-8">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>สร้างโดย: ${bom.created_by_name || 'ไม่ระบุ'}<br>
                                        <i class="fas fa-calendar me-1"></i>วันที่สร้าง: ${new Date(bom.created_at).toLocaleDateString('th-TH')}
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="btn-group" role="group">
                                        <button class="btn btn-info btn-sm" onclick="viewBOM(${bom.bom_id})" title="ดูรายละเอียด">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                        <button class="btn btn-warning btn-sm" onclick="editBOM(${bom.bom_id})" title="แก้ไข">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <button class="btn btn-secondary btn-sm" onclick="copyBOM(${bom.bom_id}, '${bom.product_name}')" title="คัดลอก">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                        <button class="btn btn-danger btn-sm" onclick="deleteBOM(${bom.bom_id})" title="ลบ">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.innerHTML = html;
        }

        function loadBOMStats() {
            // สถิติ BOM - ใส่โค้ดสำหรับโหลดสถิติที่นี่
            const statsHtml = `
                <div class="row text-center">
                    <div class="col-6">
                        <h4 class="text-primary">${document.querySelectorAll('.bom-card').length}</h4>
                        <small class="text-muted">BOM ทั้งหมด</small>
                    </div>
                    <div class="col-6">
                        <h4 class="text-success">${materialsData.length}</h4>
                        <small class="text-muted">วัสดุในระบบ</small>
                    </div>
                </div>
            `;
            document.getElementById('bomStats').innerHTML = statsHtml;
        }

        function showCreateBOMModal() {
            document.getElementById('bomModalTitle').textContent = 'สร้าง BOM ใหม่';
            document.getElementById('form_action').value = 'create';
            document.getElementById('bomForm').reset();
            document.getElementById('bom_id').value = '';
            resetMaterialsContainer();
            new bootstrap.Modal(document.getElementById('bomModal')).show();
        }

        function resetMaterialsContainer() {
            materialRowCount = 0;
            document.getElementById('materials-container').innerHTML = `
                <div class="material-selector" onclick="addMaterialRow()">
                    <i class="fas fa-plus fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-0">คลิกเพื่อเพิ่มวัสดุแรก</p>
                </div>
            `;
            document.getElementById('bom-summary').style.display = 'none';
        }

        function addMaterialRow() {
            materialRowCount++;
            const container = document.getElementById('materials-container');
            
            // ลบ material selector ถ้ายังมี
            const selector = container.querySelector('.material-selector');
            if (selector) {
                selector.remove();
            }
            
            const row = document.createElement('div');
            row.className = 'bom-item';
            row.id = `material-row-${materialRowCount}`;
            
            row.innerHTML = `
                <div class="row align-items-center">
                    <div class="col-md-5">
                        <div class="material-search">
                            <input type="text" class="form-control material-input" 
                                   placeholder="ค้นหาวัสดุ..." 
                                   oninput="searchMaterials(this, ${materialRowCount})"
                                   data-row="${materialRowCount}">
                            <div class="material-dropdown" id="dropdown-${materialRowCount}"></div>
                            <input type="hidden" name="materials[${materialRowCount}][material_id]" id="material-id-${materialRowCount}">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control quantity-input" 
                               name="materials[${materialRowCount}][quantity_per_unit]" 
                               placeholder="จำนวน" step="0.0001" min="0" required
                               oninput="updateBOMSummary()">
                    </div>
                    <div class="col-md-2">
                        <input type="text" class="form-control" 
                               name="materials[${materialRowCount}][unit]" 
                               placeholder="หน่วย" required>
                    </div>
                    <div class="col-md-2">
                        <span class="text-muted small">คงเหลือ: </span>
                        <span id="current-stock-${materialRowCount}" class="badge bg-secondary">-</span>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeMaterialRow(${materialRowCount})">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
            `;
            
            container.appendChild(row);
            document.getElementById('bom-summary').style.display = 'block';
            updateBOMSummary();
        }

        function searchMaterials(input, rowId) {
            const searchTerm = input.value.toLowerCase();
            const dropdown = document.getElementById(`dropdown-${rowId}`);
            
            if (searchTerm.length < 2) {
                dropdown.style.display = 'none';
                return;
            }
            
            const filteredMaterials = materialsData.filter(material => 
                material.part_code.toLowerCase().includes(searchTerm) ||
                material.material_name.toLowerCase().includes(searchTerm)
            );
            
            if (filteredMaterials.length === 0) {
                dropdown.style.display = 'none';
                return;
            }
            
            let html = '';
            filteredMaterials.slice(0, 10).forEach(material => {
                html += `
                    <div class="material-dropdown-item" onclick="selectMaterial(${material.material_id}, '${material.part_code}', '${material.material_name}', '${material.unit}', ${material.current_stock}, ${rowId})">
                        <strong>${material.part_code}</strong> - ${material.material_name}
                        <br><small class="text-muted">คงเหลือ: ${material.current_stock} ${material.unit}</small>
                    </div>
                `;
            });
            
            dropdown.innerHTML = html;
            dropdown.style.display = 'block';
        }

        function selectMaterial(materialId, partCode, materialName, unit, currentStock, rowId) {
            const input = document.querySelector(`[data-row="${rowId}"]`);
            const hiddenInput = document.getElementById(`material-id-${rowId}`);
            const unitInput = document.querySelector(`input[name="materials[${rowId}][unit]"]`);
            const stockSpan = document.getElementById(`current-stock-${rowId}`);
            const dropdown = document.getElementById(`dropdown-${rowId}`);
            
            input.value = `${partCode} - ${materialName}`;
            hiddenInput.value = materialId;
            unitInput.value = unit;
            stockSpan.textContent = `${currentStock} ${unit}`;
            stockSpan.className = currentStock > 0 ? 'badge bg-success' : 'badge bg-danger';
            dropdown.style.display = 'none';
            
            updateBOMSummary();
        }

        function removeMaterialRow(rowId) {
            document.getElementById(`material-row-${rowId}`).remove();
            updateBOMSummary();
            
            // ถ้าไม่มี row เหลือ ให้แสดง material selector
            const container = document.getElementById('materials-container');
            if (container.children.length === 0) {
                resetMaterialsContainer();
            }
        }

        function updateBOMSummary() {
            const materialRows = document.querySelectorAll('.bom-item');
            const totalMaterials = materialRows.length;
            
            document.getElementById('total-materials').textContent = totalMaterials;
            
            if (totalMaterials > 0) {
                document.getElementById('bom-status').className = 'badge bg-success';
                document.getElementById('bom-status').textContent = 'พร้อมใช้งาน';
            } else {
                document.getElementById('bom-status').className = 'badge bg-warning';
                document.getElementById('bom-status').textContent = 'ยังไม่สมบูรณ์';
            }
        }

        function checkExistingBOM() {
            const productId = document.getElementById('product_id').value;
            if (!productId) {
                document.getElementById('existing-bom-warning').style.display = 'none';
                return;
            }
            
            fetch(`../../api/bom.php?action=get_bom&product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('existing-bom-warning').style.display = 'block';
                        // อัพเดทเวอร์ชัน
                        const currentVersion = parseFloat(data.bom.version);
                        const newVersion = (currentVersion + 0.1).toFixed(1);
                        document.getElementById('version').value = newVersion;
                    } else {
                        document.getElementById('existing-bom-warning').style.display = 'none';
                        document.getElementById('version').value = '1.0';
                    }
                })
                .catch(error => {
                    document.getElementById('existing-bom-warning').style.display = 'none';
                });
        }

        function previewBOM() {
            const formData = new FormData(document.getElementById('bomForm'));
            const productSelect = document.getElementById('product_id');
            const productName = productSelect.options[productSelect.selectedIndex].text;
            
            let previewHtml = `
                <div class="text-center mb-4">
                    <h4>${productName}</h4>
                    <p class="text-muted">เวอร์ชัน: ${formData.get('version')}</p>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th class="text-end">จำนวน/หน่วย</th>
                                <th>หน่วย</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            document.querySelectorAll('.bom-item').forEach((row, index) => {
                const materialInput = row.querySelector('.material-input');
                const quantityInput = row.querySelector('input[name*="quantity_per_unit"]');
                const unitInput = row.querySelector('input[name*="unit"]');
                
                if (materialInput.value && quantityInput.value) {
                    const materialText = materialInput.value.split(' - ');
                    previewHtml += `
                        <tr>
                            <td><strong>${materialText[0]}</strong></td>
                            <td>${materialText[1] || ''}</td>
                            <td class="text-end">${parseFloat(quantityInput.value).toLocaleString()}</td>
                            <td>${unitInput.value}</td>
                        </tr>
                    `;
                }
            });
            
            previewHtml += `
                        </tbody>
                    </table>
                </div>
                <div class="text-center mt-4">
                    <small class="text-muted">สร้างเมื่อ: ${new Date().toLocaleDateString('th-TH')}</small>
                </div>
            `;
            
            document.getElementById('bom-preview-content').innerHTML = previewHtml;
            new bootstrap.Modal(document.getElementById('bomPreviewModal')).show();
        }

        function viewBOM(bomId) {
            fetch(`../../api/bom.php?action=get_bom&bom_id=${bomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showBOMDetails(data.bom);
                    }
                });
        }

        function editBOM(bomId) {
            fetch(`../../api/bom.php?action=get_bom&bom_id=${bomId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadBOMForEdit(data.bom);
                    }
                });
        }

        function copyBOM(bomId, productName) {
            document.getElementById('source_bom_id').value = bomId;
            document.getElementById('source_product').value = productName;
            new bootstrap.Modal(document.getElementById('copyBOMModal')).show();
        }

        function deleteBOM(bomId) {
            Swal.fire({
                title: 'ยืนยันการลบ?',
                text: 'คุณต้องการลบ BOM นี้หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ลบ',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    fetch('../../api/bom.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `action=delete&bom_id=${bomId}`
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire('ลบแล้ว!', data.message, 'success');
                            loadBOMList();
                            loadBOMStats();
                        } else {
                            Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                        }
                    });
                }
            });
        }

        // Calculator functions
        function loadBOMForCalculation() {
            const productId = document.getElementById('calc_product_id').value;
            if (!productId) {
                document.getElementById('calculation-result').innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-calculator fa-3x mb-3"></i>
                        <p>เลือกสินค้าและระบุจำนวนเพื่อคำนวณ</p>
                    </div>
                `;
                return;
            }
            
            fetch(`../../api/bom.php?action=get_bom&product_id=${productId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        currentBOMData = data.bom;
                        calculateMaterials();
                    } else {
                        document.getElementById('calculation-result').innerHTML = `
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ยังไม่มี BOM สำหรับสินค้านี้
                            </div>
                        `;
                    }
                });
        }

        function calculateMaterials() {
            const quantity = document.getElementById('calc_quantity').value;
            
            if (!currentBOMData || !quantity || quantity <= 0) {
                return;
            }
            
            let html = `
                <h6 class="mb-3"><i class="fas fa-list me-2"></i>ความต้องการวัสดุ</h6>
                <div class="calculation-preview">
                    <strong>สำหรับการผลิต: ${parseInt(quantity).toLocaleString()} ชิ้น</strong>
                </div>
            `;
            
            currentBOMData.details.forEach(detail => {
                const required = detail.quantity_per_unit * quantity;
                const sufficient = detail.current_stock >= required;
                
                html += `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <strong>${detail.part_code}</strong><br>
                            <small class="text-muted">${detail.material_name}</small>
                        </div>
                        <div class="text-end">
                            <span class="badge ${sufficient ? 'bg-success' : 'bg-danger'}">
                                ${required.toLocaleString()} ${detail.unit}
                            </span><br>
                            <small class="text-muted">คงเหลือ: ${detail.current_stock.toLocaleString()}</small>
                        </div>
                    </div>
                `;
            });
            
            document.getElementById('calculation-result').innerHTML = html;
        }

        // Form submission
        document.getElementById('bomForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Validate materials
            const materialRows = document.querySelectorAll('.bom-item');
            if (materialRows.length === 0) {
                Swal.fire('กรุณาเพิ่มวัสดุ', 'ต้องมีวัสดุอย่างน้อย 1 รายการ', 'warning');
                return;
            }
            
            // Prepare materials data
            const materials = [];
            let isValid = true;
            
            materialRows.forEach((row, index) => {
                const materialId = row.querySelector('input[type="hidden"]').value;
                const quantityInput = row.querySelector('input[name*="quantity_per_unit"]');
                const unitInput = row.querySelector('input[name*="unit"]');
                
                if (!materialId || !quantityInput.value || !unitInput.value) {
                    isValid = false;
                    return;
                }
                
                materials.push({
                    material_id: parseInt(materialId),
                    quantity_per_unit: parseFloat(quantityInput.value),
                    unit: unitInput.value
                });
            });
            
            if (!isValid) {
                Swal.fire('ข้อมูลไม่สมบูรณ์', 'กรุณาตรวจสอบข้อมูลวัสดุทั้งหมด', 'warning');
                return;
            }
            
            const formData = new FormData(this);
            formData.append('materials', JSON.stringify(materials));
            
            fetch('../../api/bom.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ!', data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('bomModal')).hide();
                    loadBOMList();
                    loadBOMStats();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้', 'error');
            });
        });

        // Copy BOM form submission
        document.getElementById('copyBOMForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'copy');
            
            fetch('../../api/bom.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('คัดลอกสำเร็จ!', data.message, 'success');
                    bootstrap.Modal.getInstance(document.getElementById('copyBOMModal')).hide();
                    loadBOMList();
                    loadBOMStats();
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            });
        });

        function loadBOMForEdit(bom) {
            document.getElementById('bomModalTitle').textContent = 'แก้ไข BOM';
            document.getElementById('form_action').value = 'update';
            document.getElementById('bom_id').value = bom.bom_id;
            document.getElementById('product_id').value = bom.product_id;
            document.getElementById('version').value = bom.version;
            
            // Reset materials container
            resetMaterialsContainer();
            
            // Load existing materials
            bom.details.forEach(detail => {
                addMaterialRow();
                const currentRow = materialRowCount;
                
                // Set material data
                const input = document.querySelector(`[data-row="${currentRow}"]`);
                const hiddenInput = document.getElementById(`material-id-${currentRow}`);
                const quantityInput = document.querySelector(`input[name="materials[${currentRow}][quantity_per_unit]"]`);
                const unitInput = document.querySelector(`input[name="materials[${currentRow}][unit]"]`);
                const stockSpan = document.getElementById(`current-stock-${currentRow}`);
                
                input.value = `${detail.part_code} - ${detail.material_name}`;
                hiddenInput.value = detail.material_id;
                quantityInput.value = detail.quantity_per_unit;
                unitInput.value = detail.unit;
                stockSpan.textContent = `${detail.current_stock} ${detail.unit}`;
                stockSpan.className = detail.current_stock > 0 ? 'badge bg-success' : 'badge bg-danger';
            });
            
            updateBOMSummary();
            new bootstrap.Modal(document.getElementById('bomModal')).show();
        }

        function showBOMDetails(bom) {
            let detailsHtml = `
                <div class="product-header mb-4">
                    <h4>${bom.product_name}</h4>
                    <p class="mb-0">รหัส: ${bom.product_code} | เวอร์ชัน: ${bom.version}</p>
                </div>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>รหัสวัสดุ</th>
                                <th>ชื่อวัสดุ</th>
                                <th class="text-end">จำนวน/หน่วย</th>
                                <th>หน่วย</th>
                                <th class="text-end">คงเหลือ</th>
                            </tr>
                        </thead>
                        <tbody>
            `;
            
            bom.details.forEach(detail => {
                detailsHtml += `
                    <tr>
                        <td><strong>${detail.part_code}</strong></td>
                        <td>${detail.material_name}</td>
                        <td class="text-end">${parseFloat(detail.quantity_per_unit).toLocaleString()}</td>
                        <td>${detail.unit}</td>
                        <td class="text-end">
                            <span class="badge ${detail.current_stock > 0 ? 'bg-success' : 'bg-danger'}">
                                ${detail.current_stock.toLocaleString()}
                            </span>
                        </td>
                    </tr>
                `;
            });
            
            detailsHtml += `
                        </tbody>
                    </table>
                </div>
            `;
            
            document.getElementById('bom-preview-content').innerHTML = detailsHtml;
            new bootstrap.Modal(document.getElementById('bomPreviewModal')).show();
        }

        function printBOM() {
            const content = document.getElementById('bom-preview-content').innerHTML;
            const printWindow = window.open('', '', 'width=800,height=600');
            
            printWindow.document.write(`
                <html>
                    <head>
                        <title>BOM Report</title>
                        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                        <style>
                            body { font-family: 'Sarabun', sans-serif; }
                            .product-header { background: #667eea; color: white; padding: 20px; border-radius: 10px; }
                            @media print {
                                .btn { display: none; }
                            }
                        </style>
                    </head>
                    <body class="p-4">
                        ${content}
                    </body>
                </html>
            `);
            
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
        }

        // Hide dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.material-search')) {
                document.querySelectorAll('.material-dropdown').forEach(dropdown => {
                    dropdown.style.display = 'none';
                });
            }
        });

        // Toggle Sidebar for Mobile
        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            sidebar.classList.toggle('show');
        }
    </script>

</body>
</html>