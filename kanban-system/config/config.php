<?php
// config/config.php
define('BASE_URL', 'http://localhost/kanban-system/');

define('SITE_NAME', 'Kanban System');

// Timezone
date_default_timezone_set('Asia/Bangkok');

// Session settings
session_start();

// Helper functions
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
?>