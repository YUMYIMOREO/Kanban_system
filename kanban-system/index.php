<?php
require_once 'config/config.php';

if (isLoggedIn()) {
    $role = getUserRole();
    switch ($role) {
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
            redirect('login.php');
    }
} else {
    redirect('login.php');
}
?>