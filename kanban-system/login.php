<?php
// login.php
require_once 'config/config.php';
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = sanitize($_POST['username']);
    $password = $_POST['password'];
    
    if (!empty($username) && !empty($password)) {
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT user_id, username, password, role, full_name, status FROM users WHERE username = ? AND status = 'active'";
        $stmt = $db->prepare($query);
        $stmt->execute([$username]);
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch();
            
            if (verifyPassword($password, $user['password'])) {
                $_SESSION['user_id'] = $user['user_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // Log audit
                $audit_query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address, user_agent) 
                               VALUES (?, 'login', 'users', ?, ?, ?)";
                $audit_stmt = $db->prepare($audit_query);
                $audit_stmt->execute([
                    $user['user_id'], 
                    $user['user_id'], 
                    $_SERVER['REMOTE_ADDR'], 
                    $_SERVER['HTTP_USER_AGENT']
                ]);
                
                // Redirect based on role
                switch ($user['role']) {
                    case 'admin':
                        redirect('pages/admin/dashboard.php');
                        break;
                    case 'planning':
                        redirect('pages/planning/dashboard.php');
                        break;
                    case 'production':
                        redirect('pages/production/dashboard.php');
                        break;
                    case 'store':
                        redirect('pages/store/dashboard.php');
                        break;
                    case 'management':
                        redirect('pages/management/dashboard.php');
                        break;
                    default:
                        redirect('dashboard.php');
                }
            } else {
                $error = 'รหัสผ่านไม่ถูกต้อง';
            }
        } else {
            $error = 'ไม่พบผู้ใช้หรือบัญชีถูกระงับ';
        }
    } else {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วน';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - <?= SITE_NAME ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Sarabun', sans-serif;
        }
        .login-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
        }
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
            border-radius: 20px 20px 0 0;
        }
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 12px;
            border-radius: 10px;
            transition: all 0.3s ease;
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            color: white;
        }
        .form-control {
            border-radius: 10px;
            border: 2px solid #e1e5e9;
            padding: 12px 15px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
    </style>
</head>
<body>
    <div class="container d-flex justify-content-center align-items-center min-vh-100">
        <div class="row w-100">
            <div class="col-md-6 col-lg-4 mx-auto">
                <div class="login-card">
                    <div class="login-header">
                        <i class="fas fa-boxes fa-3x mb-3"></i>
                        <h3>ระบบ Kanban</h3>
                        <p class="mb-0">Web Application for Packaging Stock Management</p>
                    </div>
                    
                    <div class="card-body p-4">
                        <?php if (!empty($error)): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($success)): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i><?= $success ?>
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="username" class="form-label">
                                    <i class="fas fa-user me-2"></i>ชื่อผู้ใช้
                                </label>
                                <input type="text" class="form-control" id="username" name="username" 
                                       placeholder="กรอกชื่อผู้ใช้" required>
                            </div>
                            
                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-2"></i>รหัสผ่าน
                                </label>
                                <input type="password" class="form-control" id="password" name="password" 
                                       placeholder="กรอกรหัสผ่าน" required>
                            </div>
                            
                            <button type="submit" class="btn btn-login w-100 mb-3">
                                <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
                            </button>
                        </form>

                        <div class="text-center">
                            <small class="text-muted">
                                Demo Accounts:<br>
                                <strong>admin/password</strong> - ผู้ดูแลระบบ<br>
                                <strong>planner1/password</strong> - แผนกวางแผน<br>
                                <strong>production1/password</strong> - แผนกผลิต<br>
                                <strong>store1/password</strong> - แผนกคลัง<br>
                                <strong>manager1/password</strong> - ผู้จัดการ
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>