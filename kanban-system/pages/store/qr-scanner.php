<?php
// pages/store/qr-scanner.php
$page_title = 'สแกน QR Code';
$breadcrumbs = [
    ['text' => 'หน้าแรก', 'url' => 'dashboard.php'],
    ['text' => 'สแกน QR Code']
];

require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
require_once '../../config/database.php';

checkRole(['store', 'admin']);
?>

<style>
    .scanner-container {
        max-width: 600px;
        margin: 0 auto;
    }
    
    #qr-video {
        width: 100%;
        height: 400px;
        border-radius: 15px;
        object-fit: cover;
        background: #000;
    }
    
    .scanner-overlay {
        position: relative;
        display: inline-block;
    }
    
    .scanner-frame {
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        width: 250px;
        height: 250px;
        border: 3px solid #fff;
        border-radius: 15px;
        box-shadow: 0 0 0 1000px rgba(0,0,0,0.5);
    }
    
    .scanner-frame::before,
    .scanner-frame::after {
        content: '';
        position: absolute;
        width: 30px;
        height: 30px;
        border: 3px solid #667eea;
    }
    
    .scanner-frame::before {
        top: -3px;
        left: -3px;
        border-right: none;
        border-bottom: none;
    }
    
    .scanner-frame::after {
        bottom: -3px;
        right: -3px;
        border-left: none;
        border-top: none;
    }
    
    .scan-result {
        display: none;
    }
    
    .material-info-card {
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border-radius: 15px;
        padding: 25px;
    }
    
    .action-buttons .btn {
        margin: 5px;
        border-radius: 25px;
        padding: 10px 25px;
    }
    
    .transaction-form {
        background: #f8f9fa;
        border-radius: 15px;
        padding: 20px;
        margin-top: 20px;
    }
</style>

            <div class="row justify-content-center">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header text-center">
                            <h5><i class="fas fa-qrcode me-2"></i>สแกน QR Code วัสดุ</h5>
                            <p class="mb-0 text-muted">นำกล้องไปส่องที่ QR Code บนวัสดุ</p>
                        </div>
                        <div class="card-body text-center">
                            <div class="scanner-container">
                                <!-- Camera Scanner -->
                                <div id="scanner-section">
                                    <div class="scanner-overlay">
                                        <video id="qr-video" autoplay muted playsinline></video>
                                        <div class="scanner-frame"></div>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <button id="start-scanner" class="btn btn-success me-2">
                                            <i class="fas fa-play"></i> เริ่มสแกน
                                        </button>
                                        <button id="stop-scanner" class="btn btn-danger me-2" style="display:none;">
                                            <i class="fas fa-stop"></i> หยุดสแกน
                                        </button>
                                        <button id="switch-camera" class="btn btn-info" style="display:none;">
                                            <i class="fas fa-sync-alt"></i> สลับกล้อง
                                        </button>
                                    </div>
                                    
                                    <div class="alert alert-info mt-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        วางกล้องให้ QR Code อยู่ในกรอบสี่เหลี่ยม
                                    </div>
                                </div>

                                <!-- Manual Input -->
                                <div class="mt-4">
                                    <h6>หรือป้อนรหัสด้วยตนเอง</h6>
                                    <div class="input-group">
                                        <input type="text" id="manual-code" class="form-control" placeholder="ป้อนรหัสวัสดุ (Part Code)">
                                        <button class="btn btn-primary" id="manual-search">
                                            <i class="fas fa-search"></i> ค้นหา
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Scan Result -->
                    <div id="scan-result" class="scan-result">
                        <div class="card mt-4">
                            <div class="card-body">
                                <div class="material-info-card" id="material-info">
                                    <!-- Material info will be displayed here -->
                                </div>
                                
                                <div class="action-buttons text-center mt-4">
                                    <button class="btn btn-success btn-lg" onclick="showTransactionForm('in')">
                                        <i class="fas fa-arrow-down me-2"></i>รับเข้า
                                    </button>
                                    <button class="btn btn-warning btn-lg" onclick="showTransactionForm('out')">
                                        <i class="fas fa-arrow-up me-2"></i>จ่ายออก
                                    </button>
                                    <button class="btn btn-info btn-lg" onclick="showTransactionForm('adjust')">
                                        <i class="fas fa-edit me-2"></i>ปรับปรุง
                                    </button>
                                    <button class="btn btn-secondary btn-lg" onclick="resetScanner()">
                                        <i class="fas fa-redo me-2"></i>สแกนใหม่
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Transaction Form -->
                        <div id="transaction-form" class="transaction-form" style="display:none;">
                            <form id="inventory-form">
                                <input type="hidden" id="material_id" name="material_id">
                                <input type="hidden" id="transaction_type" name="transaction_type">
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <label for="quantity" class="form-label">จำนวน</label>
                                        <input type="number" class="form-control" id="quantity" name="quantity" required min="1">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="reference_type" class="form-label">ประเภทอ้างอิง</label>
                                        <select class="form-control" id="reference_type" name="reference_type" required>
                                            <option value="">เลือกประเภท</option>
                                            <option value="purchase">การซื้อ</option>
                                            <option value="production">การผลิต</option>
                                            <option value="return">การคืน</option>
                                            <option value="adjustment">การปรับปรุง</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <label for="notes" class="form-label">หมายเหตุ</label>
                                        <textarea class="form-control" id="notes" name="notes" rows="3" placeholder="ระบุหมายเหตุเพิ่มเติม (ไม่บังคับ)"></textarea>
                                    </div>
                                </div>
                                
                                <div class="text-center mt-4">
                                    <button type="submit" class="btn btn-primary btn-lg me-2">
                                        <i class="fas fa-save me-2"></i>บันทึก
                                    </button>
                                    <button type="button" class="btn btn-secondary btn-lg" onclick="hideTransactionForm()">
                                        <i class="fas fa-times me-2"></i>ยกเลิก
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Recent Scans -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5><i class="fas fa-history me-2"></i>การสแกนล่าสุด</h5>
                        </div>
                        <div class="card-body">
                            <div id="recent-scans">
                                <p class="text-muted text-center">ยังไม่มีการสแกน</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- JavaScript Libraries -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/jsqr/dist/jsQR.js"></script>

    <script>
        let video, canvas, context, animationId;
        let currentStream = null;
        let cameras = [];
        let currentCameraIndex = 0;
        let recentScans = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            initializeScanner();
            loadRecentScans();
        });

        async function initializeScanner() {
            video = document.getElementById('qr-video');
            canvas = document.createElement('canvas');
            context = canvas.getContext('2d');
            
            // Get available cameras
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                cameras = devices.filter(device => device.kind === 'videoinput');
                
                if (cameras.length > 1) {
                    document.getElementById('switch-camera').style.display = 'inline-block';
                }
            } catch (error) {
                console.error('Error getting cameras:', error);
            }
            
            document.getElementById('start-scanner').addEventListener('click', startScanner);
            document.getElementById('stop-scanner').addEventListener('click', stopScanner);
            document.getElementById('switch-camera').addEventListener('click', switchCamera);
            document.getElementById('manual-search').addEventListener('click', manualSearch);
            document.getElementById('inventory-form').addEventListener('submit', submitTransaction);
            
            // Start scanner automatically
            startScanner();
        }

        async function startScanner() {
            try {
                if (currentStream) {
                    currentStream.getTracks().forEach(track => track.stop());
                }
                
                const constraints = {
                    video: {
                        facingMode: cameras.length > 0 ? undefined : 'environment',
                        deviceId: cameras.length > 0 ? cameras[currentCameraIndex].deviceId : undefined
                    }
                };
                
                currentStream = await navigator.mediaDevices.getUserMedia(constraints);
                video.srcObject = currentStream;
                
                video.onloadedmetadata = () => {
                    canvas.width = video.videoWidth;
                    canvas.height = video.videoHeight;
                    scanFrame();
                };
                
                document.getElementById('start-scanner').style.display = 'none';
                document.getElementById('stop-scanner').style.display = 'inline-block';
                
            } catch (error) {
                console.error('Error starting scanner:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถเปิดกล้องได้', 'error');
            }
        }

        function stopScanner() {
            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }
            if (animationId) {
                cancelAnimationFrame(animationId);
            }
            
            document.getElementById('start-scanner').style.display = 'inline-block';
            document.getElementById('stop-scanner').style.display = 'none';
        }

        function switchCamera() {
            currentCameraIndex = (currentCameraIndex + 1) % cameras.length;
            startScanner();
        }

        function scanFrame() {
            if (video.readyState === video.HAVE_ENOUGH_DATA) {
                context.drawImage(video, 0, 0, canvas.width, canvas.height);
                const imageData = context.getImageData(0, 0, canvas.width, canvas.height);
                const code = jsQR(imageData.data, imageData.width, imageData.height);
                
                if (code) {
                    processQRCode(code.data);
                    return;
                }
            }
            animationId = requestAnimationFrame(scanFrame);
        }

        function processQRCode(qrData) {
            stopScanner();
            
            // Extract part code from QR data
            let partCode = qrData;
            
            // If QR contains JSON or structured data
            try {
                const parsed = JSON.parse(qrData);
                partCode = parsed.part_code || parsed.code || qrData;
            } catch (e) {
                // Use as plain text
            }
            
            searchMaterial(partCode);
        }

        function manualSearch() {
            const partCode = document.getElementById('manual-code').value.trim();
            if (!partCode) {
                Swal.fire('กรุณาป้อนรหัสวัสดุ', '', 'warning');
                return;
            }
            searchMaterial(partCode);
        }

        function searchMaterial(partCode) {
            fetch(`../../api/materials.php?action=get_by_code&code=${encodeURIComponent(partCode)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayMaterialInfo(data.material);
                        addToRecentScans(data.material);
                    } else {
                        Swal.fire('ไม่พบวัสดุ', `ไม่พบวัสดุที่มีรหัส: ${partCode}`, 'error');
                        resetScanner();
                    }
                })
                .catch(error => {
                    console.error('Error searching material:', error);
                    Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถค้นหาวัสดุได้', 'error');
                    resetScanner();
                });
        }

        function displayMaterialInfo(material) {
            const stockStatus = getStockStatus(material);
            const materialInfoHtml = `
                <div class="row">
                    <div class="col-md-8">
                        <h4><i class="fas fa-box me-2"></i>${material.material_name}</h4>
                        <p class="mb-1"><strong>รหัสวัสดุ:</strong> ${material.part_code}</p>
                        <p class="mb-1"><strong>หน่วย:</strong> ${material.unit}</p>
                        <p class="mb-1"><strong>ที่เก็บ:</strong> ${material.location || 'ไม่ระบุ'}</p>
                        ${material.description ? `<p class="mb-1"><strong>รายละเอียด:</strong> ${material.description}</p>` : ''}
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="mb-2">
                            <h2 class="mb-0">${material.current_stock.toLocaleString()}</h2>
                            <small>คงเหลือ</small>
                        </div>
                        <div class="mb-2">
                            <span class="badge ${stockStatus.class} fs-6">${stockStatus.text}</span>
                        </div>
                        <div class="small">
                            ต่ำสุด: ${material.min_stock.toLocaleString()} | 
                            สูงสุด: ${material.max_stock.toLocaleString()}
                        </div>
                    </div>
                </div>
            `;
            
            document.getElementById('material-info').innerHTML = materialInfoHtml;
            document.getElementById('material_id').value = material.material_id;
            document.getElementById('scan-result').style.display = 'block';
            document.getElementById('scanner-section').style.display = 'none';
        }

        function getStockStatus(material) {
            if (material.current_stock <= material.min_stock) {
                return { class: 'bg-danger', text: 'สต็อกต่ำ' };
            } else if (material.current_stock > material.max_stock) {
                return { class: 'bg-warning', text: 'สต็อกเกิน' };
            } else {
                return { class: 'bg-success', text: 'ปกติ' };
            }
        }

        function showTransactionForm(type) {
            const titles = {
                'in': 'รับวัสดุเข้า',
                'out': 'จ่ายวัสดุออก',
                'adjust': 'ปรับปรุงสต็อก'
            };
            
            document.getElementById('transaction_type').value = type;
            document.getElementById('transaction-form').style.display = 'block';
            
            // Scroll to form
            document.getElementById('transaction-form').scrollIntoView({ behavior: 'smooth' });
            
            // Focus on quantity input
            setTimeout(() => {
                document.getElementById('quantity').focus();
            }, 300);
        }

        function hideTransactionForm() {
            document.getElementById('transaction-form').style.display = 'none';
            document.getElementById('inventory-form').reset();
        }

        function submitTransaction(e) {
            e.preventDefault();
            
            const formData = new FormData(document.getElementById('inventory-form'));
            const transactionType = formData.get('transaction_type');
            
            // Confirm transaction
            const actionText = {
                'in': 'รับเข้า',
                'out': 'จ่ายออก', 
                'adjust': 'ปรับปรุง'
            }[transactionType];
            
            Swal.fire({
                title: `ยืนยัน${actionText}วัสดุ?`,
                text: `จำนวน: ${formData.get('quantity')} ${document.querySelector('#material-info').textContent.includes('หน่วย') ? '' : 'หน่วย'}`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    processTransaction(formData);
                }
            });
        }

        function processTransaction(formData) {
            fetch('../../api/inventory.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire('สำเร็จ', data.message, 'success').then(() => {
                        hideTransactionForm();
                        // Refresh material info to show updated stock
                        const partCode = document.querySelector('#material-info').textContent.match(/รหัสวัสดุ:\s*([^\n]+)/)[1].trim();
                        searchMaterial(partCode);
                    });
                } else {
                    Swal.fire('เกิดข้อผิดพลาด', data.message, 'error');
                }
            })
            .catch(error => {
                console.error('Error processing transaction:', error);
                Swal.fire('เกิดข้อผิดพลาด', 'ไม่สามารถบันทึกข้อมูลได้', 'error');
            });
        }

        function resetScanner() {
            document.getElementById('scan-result').style.display = 'none';
            document.getElementById('scanner-section').style.display = 'block';
            document.getElementById('transaction-form').style.display = 'none';
            document.getElementById('manual-code').value = '';
            hideTransactionForm();
            startScanner();
        }

        function addToRecentScans(material) {
            const now = new Date();
            const scanRecord = {
                ...material,
                scan_time: now.toISOString()
            };
            
            // Add to beginning of array and limit to 10 items
            recentScans.unshift(scanRecord);
            recentScans = recentScans.slice(0, 10);
            
            // Save to localStorage
            localStorage.setItem('recent_scans', JSON.stringify(recentScans));
            
            displayRecentScans();
        }

        function displayRecentScans() {
            const container = document.getElementById('recent-scans');
            
            if (recentScans.length === 0) {
                container.innerHTML = '<p class="text-muted text-center">ยังไม่มีการสแกน</p>';
                return;
            }
            
            const scansHtml = recentScans.map(scan => {
                const scanTime = new Date(scan.scan_time);
                const timeAgo = getTimeAgo(scanTime);
                const stockStatus = getStockStatus(scan);
                
                return `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <strong>${scan.part_code}</strong> - ${scan.material_name}<br>
                            <small class="text-muted">
                                <i class="fas fa-clock me-1"></i>${timeAgo}
                                | คงเหลือ: ${scan.current_stock.toLocaleString()}
                                <span class="badge ${stockStatus.class} ms-2">${stockStatus.text}</span>
                            </small>
                        </div>
                        <button class="btn btn-outline-primary btn-sm" onclick="searchMaterial('${scan.part_code}')">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                `;
            }).join('');
            
            container.innerHTML = scansHtml;
        }

        function loadRecentScans() {
            const saved = localStorage.getItem('recent_scans');
            if (saved) {
                recentScans = JSON.parse(saved);
                displayRecentScans();
            }
        }

        function getTimeAgo(date) {
            const now = new Date();
            const diffMs = now - date;
            const diffMins = Math.floor(diffMs / 60000);
            
            if (diffMins < 1) return 'เพิ่งสแกน';
            if (diffMins < 60) return `${diffMins} นาทีที่แล้ว`;
            
            const diffHours = Math.floor(diffMins / 60);
            if (diffHours < 24) return `${diffHours} ชั่วโมงที่แล้ว`;
            
            const diffDays = Math.floor(diffHours / 24);
            return `${diffDays} วันที่แล้ว`;
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // ESC to reset scanner
            if (e.key === 'Escape') {
                resetScanner();
            }
            
            // Enter in manual input
            if (e.key === 'Enter' && e.target.id === 'manual-code') {
                e.preventDefault();
                manualSearch();
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