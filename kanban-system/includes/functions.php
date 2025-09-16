<?php
function redirect($url) {
    header("Location: " . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getUserRole() {
    return isset($_SESSION['role']) ? $_SESSION['role'] : '';
}

function checkRole($allowed_roles) {
    if (!isLoggedIn()) {
        redirect('login.php');
    }
    if (!in_array(getUserRole(), $allowed_roles)) {
        redirect('unauthorized.php');
    }
}

function sanitize($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}



// ฟังก์ชันช่วยต่างๆ สำหรับระบบ Kanban

/**
 * ตรวจสอบสิทธิ์การเข้าถึงตาม role
 */
function checkAccess($allowedRoles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $allowedRoles)) {
        header("Location: ../../unauthorized.php");
        exit();
    }
}

/**
 * ล็อก Audit การทำงานของผู้ใช้
 */
function logAudit($conn, $user_id, $action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $sql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("issiisss", 
        $user_id, 
        $action, 
        $table_name, 
        $record_id, 
        $old_values ? json_encode($old_values) : null,
        $new_values ? json_encode($new_values) : null,
        $ip_address, 
        $user_agent
    );
    
    return $stmt->execute();
}

/**
 * สร้างรหัส Request Number อัตโนมัติ
 */
function generateRequestNumber($conn) {
    $year = date('Y');
    $sql = "SELECT MAX(CAST(SUBSTRING(request_number, -4) AS UNSIGNED)) as max_num 
            FROM material_requests 
            WHERE request_number LIKE 'REQ-$year-%'";
    
    $result = $conn->query($sql);
    $row = $result->fetch_assoc();
    $next_num = ($row['max_num'] ?? 0) + 1;
    
    return "REQ-$year-" . str_pad($next_num, 4, '0', STR_PAD_LEFT);
}

/**
 * ตรวจสอบสต็อกต่ำกว่าเกณฑ์
 */
function checkLowStock($conn) {
    $sql = "SELECT material_id, material_name, current_stock, min_stock, part_code
            FROM materials 
            WHERE current_stock <= min_stock AND status = 'active'
            ORDER BY (current_stock / NULLIF(min_stock, 0)) ASC";
    
    $result = $conn->query($sql);
    return $result->fetch_all(MYSQLI_ASSOC);
}

/**
 * คำนวณวัสดุที่ต้องใช้จาก BOM
 */
function calculateMaterialsFromBOM($conn, $product_id, $quantity) {
    $sql = "SELECT 
                bd.material_id,
                m.material_name,
                m.part_code,
                m.unit,
                bd.quantity_per_unit,
                (bd.quantity_per_unit * ?) as total_needed,
                m.current_stock
            FROM bom_header bh
            JOIN bom_detail bd ON bh.bom_id = bd.bom_id
            JOIN materials m ON bd.material_id = m.material_id
            WHERE bh.product_id = ? AND bh.status = 'active'
            ORDER BY m.material_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("di", $quantity, $product_id);
    $stmt->execute();
    
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

/**
 * แปลงสถานะเป็นภาษาไทย
 */
function getStatusText($status) {
    $statusMap = [
        'pending' => 'รอดำเนินการ',
        'approved' => 'อนุมัติแล้ว',
        'rejected' => 'ปฏิเสธ',
        'fulfilled' => 'จ่ายแล้ว',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
        'in_progress' => 'กำลังดำเนินการ',
        'active' => 'ใช้งาน',
        'inactive' => 'ไม่ใช้งาน'
    ];
    
    return $statusMap[$status] ?? $status;
}

/**
 * แปลง role เป็นภาษาไทย
 */
function getRoleText($role) {
    $roleMap = [
        'admin' => 'ผู้ดูแลระบบ',
        'planning' => 'แผนกวางแผน',
        'production' => 'แผนกผลิต',
        'store' => 'แผนกคลัง',
        'management' => 'ผู้บริหาร'
    ];
    
    return $roleMap[$role] ?? $role;
}

/**
 * สร้าง QR Code สำหรับวัสดุ
 */
function generateMaterialQR($material_id, $part_code) {
    $qr_data = json_encode([
        'type' => 'material',
        'material_id' => $material_id,
        'part_code' => $part_code,
        'timestamp' => time()
    ]);
    
    return base64_encode($qr_data);
}

/**
 * ตรวจสอบว่าเป็น AJAX Request หรือไม่
 */
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * ส่ง JSON Response
 */
function sendJsonResponse($data) {
    header('Content-Type: application/json');
    echo json_encode($data);
    exit();
}

/**
 * ตรวจสอบสิทธิ์ CSRF Token (สำหรับความปลอดภัย)
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && 
           hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * สร้าง CSRF Token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * แสดงข้อความแจ้งเตือน
 */
function showAlert($type, $message) {
    $alertClass = [
        'success' => 'alert-success',
        'error' => 'alert-danger',
        'warning' => 'alert-warning',
        'info' => 'alert-info'
    ];
    
    $class = $alertClass[$type] ?? 'alert-info';
    
    return "<div class='alert $class alert-dismissible fade show' role='alert'>
                $message
                <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
            </div>";
}

/**
 * จัดรูปแบบวันที่เป็นภาษาไทย
 */
function formatDateThai($date) {
    if (!$date) return '-';
    
    $thaiMonths = [
        1 => 'ม.ค.', 2 => 'ก.พ.', 3 => 'มี.ค.', 4 => 'เม.ย.',
        5 => 'พ.ค.', 6 => 'มิ.ย.', 7 => 'ก.ค.', 8 => 'ส.ค.',
        9 => 'ก.ย.', 10 => 'ต.ค.', 11 => 'พ.ย.', 12 => 'ธ.ค.'
    ];
    
    $timestamp = strtotime($date);
    $day = date('j', $timestamp);
    $month = $thaiMonths[date('n', $timestamp)];
    $year = date('Y', $timestamp) + 543;
    $time = date('H:i', $timestamp);
    
    return "$day $month $year $time";
}

/**
 * คำนวณเปーร์เซ็นต์
 */
function calculatePercentage($part, $total) {
    if ($total == 0) return 0;
    return round(($part / $total) * 100, 2);
}

/**
 * ตรวจสอบการอัพโหลดไฟล์
 */
function validateFileUpload($file, $allowedTypes = ['jpg', 'jpeg', 'png', 'pdf']) {
    $errors = [];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'เกิดข้อผิดพลาดในการอัพโหลดไฟล์';
        return $errors;
    }
    
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!in_array($extension, $allowedTypes)) {
        $errors[] = 'ประเภทไฟล์ไม่ถูกต้อง อนุญาตเฉพาะ: ' . implode(', ', $allowedTypes);
    }
    
    $maxSize = 5 * 1024 * 1024; // 5MB
    if ($file['size'] > $maxSize) {
        $errors[] = 'ขนาดไฟล์ใหญ่เกินไป (สูงสุด 5MB)';
    }
    
    return $errors;
}

/**
 * สร้างชื่อไฟล์ที่ไม่ซ้ำ
 */
function generateUniqueFilename($originalName) {
    $fileInfo = pathinfo($originalName);
    $extension = $fileInfo['extension'];
    $basename = preg_replace('/[^a-zA-Z0-9]/', '_', $fileInfo['filename']);
    
    return $basename . '_' . uniqid() . '.' . $extension;
}
?>