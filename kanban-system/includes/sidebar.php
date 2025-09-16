<?php
// includes/sidebar.php
$role = getUserRole();
$user_name = $_SESSION['full_name'] ?? 'ผู้ใช้งาน';

// Menu items based on role
$menus = [
    'admin' => [
        'หลัก' => [
            ['icon' => 'fas fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'url' => 'dashboard.php'],
        ],
        'จัดการระบบ' => [
            ['icon' => 'fas fa-users', 'text' => 'จัดการผู้ใช้', 'url' => 'users.php'],
            ['icon' => 'fas fa-boxes', 'text' => 'จัดการวัสดุ', 'url' => 'materials.php'],
            ['icon' => 'fas fa-list-alt', 'text' => 'จัดการ BOM', 'url' => 'bom.php'],
        ],
        'รายงาน' => [
            ['icon' => 'fas fa-history', 'text' => 'ประวัติการใช้งาน', 'url' => 'audit-logs.php'],
        ]
    ],
    'planning' => [
        'หลัก' => [
            ['icon' => 'fas fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'url' => 'dashboard.php'],
        ],
        'การผลิต' => [
            ['icon' => 'fas fa-tasks', 'text' => 'งานการผลิต', 'url' => 'production-jobs.php'],
            ['icon' => 'fas fa-plus-circle', 'text' => 'สร้างงานใหม่', 'url' => 'create-job.php'],
            ['icon' => 'fas fa-calculator', 'text' => 'วางแผนวัสดุ', 'url' => 'material-planning.php'],
        ],
        'การสั่งซื้อ' => [
            ['icon' => 'fas fa-shopping-cart', 'text' => 'คำขอซื้อ', 'url' => 'purchase-requests.php'],
        ]
    ],
    'production' => [
        'หลัก' => [
            ['icon' => 'fas fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'url' => 'dashboard.php'],
        ],
        'การผลิต' => [
            ['icon' => 'fas fa-clipboard-list', 'text' => 'งานของฉัน', 'url' => 'my-jobs.php'],
            ['icon' => 'fas fa-hand-paper', 'text' => 'คำขอเบิกวัสดุ', 'url' => 'material-requests.php'],
            ['icon' => 'fas fa-chart-line', 'text' => 'สถานะการผลิต', 'url' => 'production-status.php'],
        ]
    ],
    'store' => [
        'หลัก' => [
            ['icon' => 'fas fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'url' => 'dashboard.php'],
        ],
        'คลังสินค้า' => [
            ['icon' => 'fas fa-warehouse', 'text' => 'สินค้าคงเหลือ', 'url' => 'inventory.php'],
            ['icon' => 'fas fa-qrcode', 'text' => 'สแกน QR Code', 'url' => 'qr-scanner.php'],
            ['icon' => 'fas fa-arrow-down', 'text' => 'รับวัสดุเข้า', 'url' => 'material-in.php'],
            ['icon' => 'fas fa-arrow-up', 'text' => 'จ่ายวัสดุออก', 'url' => 'material-out.php'],
            ['icon' => 'fas fa-exclamation-triangle', 'text' => 'แจ้งเตือนสต็อก', 'url' => 'stock-alerts.php'],
        ]
    ],
    'management' => [
        'หลัก' => [
            ['icon' => 'fas fa-tachometer-alt', 'text' => 'แดชบอร์ด', 'url' => 'dashboard.php'],
        ],
        'รายงาน' => [
            ['icon' => 'fas fa-chart-bar', 'text' => 'รายงานทั่วไป', 'url' => 'reports.php'],
            ['icon' => 'fas fa-analytics', 'text' => 'วิเคราะห์ข้อมูล', 'url' => 'analytics.php'],
            ['icon' => 'fas fa-trophy', 'text' => 'KPI Dashboard', 'url' => 'kpi-dashboard.php'],
        ]
    ]
];
?>
    <div class="sidebar">
        <div class="sidebar-header">
            <i class="fas fa-boxes fa-2x"></i>
            <h3>Kanban System</h3>
            <small>Packaging Stock Management</small>
        </div>
        
        <div class="user-info d-flex align-items-center">
            <div class="avatar">
                <?= strtoupper(substr($user_name, 0, 1)) ?>
            </div>
            <div class="info">
                <div class="name"><?= $user_name ?></div>
                <div class="role"><?= ucfirst($role) ?></div>
            </div>
        </div>
        
        <div class="sidebar-menu">
            <?php if (isset($menus[$role])): ?>
                <?php foreach ($menus[$role] as $section => $items): ?>
                    <div class="menu-section">
                        <div class="menu-section-title"><?= $section ?></div>
                        <?php foreach ($items as $item): ?>
                            <a href="<?= $item['url'] ?>" class="menu-item <?= ($current_page == pathinfo($item['url'], PATHINFO_FILENAME)) ? 'active' : '' ?>">
                                <i class="<?= $item['icon'] ?>"></i>
                                <?= $item['text'] ?>
                                <?php if (isset($item['badge'])): ?>
                                    <span class="badge bg-danger"><?= $item['badge'] ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <div class="menu-section">
                <div class="menu-section-title">อื่นๆ</div>
                <a href="<?= BASE_URL ?>logout.php" class="menu-item">
                    <i class="fas fa-sign-out-alt"></i>
                    ออกจากระบบ
                </a>
            </div>
        </div>
    </div>
    
    <div class="main-content">
        <div class="top-navbar">
            <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                <i class="fas fa-bars"></i>
            </button>
            
            <div class="page-header">
                <h1><?= isset($page_title) ? $page_title : 'ระบบ Kanban' ?></h1>
                <?php if (isset($breadcrumbs)): ?>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <?php foreach ($breadcrumbs as $crumb): ?>
                            <?php if (isset($crumb['url'])): ?>
                                <li class="breadcrumb-item"><a href="<?= $crumb['url'] ?>"><?= $crumb['text'] ?></a></li>
                            <?php else: ?>
                                <li class="breadcrumb-item active"><?= $crumb['text'] ?></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ol>
                </nav>
                <?php endif; ?>
            </div>
            
            <div class="navbar-actions">
                <div class="notification-icon">
                    <i class="fas fa-bell"></i>
                    <span class="notification-badge">3</span>
                </div>
                
                <div class="dropdown">
                    <button class="btn btn-link dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-user-circle fa-lg"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="#"><i class="fas fa-user me-2"></i>โปรไฟล์</a></li>
                        <li><a class="dropdown-item" href="#"><i class="fas fa-cog me-2"></i>ตั้งค่า</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>logout.php"><i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ</a></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <div class="content-area">