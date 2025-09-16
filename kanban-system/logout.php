<?php
// logout.php
require_once 'config/config.php';
require_once 'config/database.php';

if (isLoggedIn()) {
    // Log audit
    $database = new Database();
    $db = $database->getConnection();
    
    $audit_query = "INSERT INTO audit_logs (user_id, action, table_name, record_id, ip_address, user_agent) 
                   VALUES (?, 'logout', 'users', ?, ?, ?)";
    $audit_stmt = $db->prepare($audit_query);
    $audit_stmt->execute([
        $_SESSION['user_id'], 
        $_SESSION['user_id'], 
        $_SERVER['REMOTE_ADDR'], 
        $_SERVER['HTTP_USER_AGENT']
    ]);
}

session_destroy();
redirect('login.php');
?>