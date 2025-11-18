<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once dirname(__DIR__, 2) . '/includes/init.php'; // Goes up two levels to /htdocs/includes/init.php

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
// For now, any employee can view/submit reports
if (!check_role('employee')) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE (unless Platform Admin) --
$company_id_context = get_current_company_id();
$is_platform_admin = check_role('platform_admin');
if (!$company_id_context && !$is_platform_admin) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error");
    exit;
}

$user_id = $_SESSION['user_id'] ?? null; // Set user_id for logging
$is_manager = has_any_role(['approver', 'admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'trainer']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo BASE_URL; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Portal</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Google Fonts for Theme -->
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght=700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="css/style.css">
    <!-- Load Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Tweaks for icon alignment */
        .navbar-nav .nav-link { display: flex; align-items: center; }
        .navbar-nav .nav-link i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .navbar-text i { width: 16px; height: 16px; margin-right: 6px; stroke-width: 2.5px; vertical-align: middle; }
        .dropdown-item i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .user-dropdown-toggle::after { display: none; } /* Hide default caret */
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="portal_home.php">
            <img src="assets/logo.png" alt="Portal Logo" onerror="this.onerror=null; this.src='https://placehold.co/100x30/FFFFFF/004E54?text=EP';">
            <span class="portal-title">Reports Portal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['roles'])): ?>
                    <li class="nav-item">
                        <span class="navbar-text me-3">
                            <i data-lucide="user"></i>
                            Welcome, <strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></strong>
                        </span>
                    </li>
                    
                    <li class="nav-item"><a class="nav-link" href="portal_home.php"><i data-lucide="home"></i>Portal Home</a></li>
                    <li class="nav-item"><a class="nav-link" href="reports/index.php"><i data-lucide="layout-dashboard"></i>Reports Dashboard</a></li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="reportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="file-plus"></i> Submit Form
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="reportsDropdown">
                            <li><a class="dropdown-item" href="reports/sales.php"><i data-lucide="bar-chart-2"></i>Daily Sales Report</a></li>
                            <li><a class="dropdown-item" href="reports/checklist.php"><i data-lucide="check-square"></i>Store Checklist</a></li>
                            <li><a class="dropdown-item" href="reports/competition.php"><i data-lucide="shield"></i>Competition Data</a></li>
                            <li><a class="dropdown-item" href="reports/stock_count.php"><i data-lucide="clipboard-list"></i>Stock Count</a></li>
                            <li><a class="dropdown-item" href="reports/vm_upload.php"><i data-lucide="image"></i>VM Pictures</a></li>
                        </ul>
                    </li>
                    
                    <?php // --- UPDATED: "View Data" Dropdown, visible to all employees ---
                    if (check_role('employee')): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="viewReportsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="file-text"></i> View Data
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="viewReportsDropdown">
                            <li><a class="dropdown-item" href="reports/view_sales.php"><i data-lucide="bar-chart-2"></i>View Sales Report</a></li>
                            <li><a class="dropdown-item" href="reports/view_checklist.php"><i data-lucide="check-square"></i>View Checklists</a></li>
                            <li><a class="dropdown-item" href="reports/view_competition.php"><i data-lucide="shield"></i>View Competition Data</a></li>
                            <li><a class="dropdown-item" href="reports/view_stock_count.php"><i data-lucide="clipboard-list"></i>View Stock Counts</a></li>
                            <li><a class="dropdown-item" href="reports/view_vm_pictures.php"><i data-lucide="image"></i>View VM Pictures</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                     
                     <?php if (check_role('platform_admin')): ?>
                        <li class="nav-item"><a class="nav-link" href="platform_admin/companies.php"><i data-lucide="building"></i>Manage Companies</a></li> 
                    <?php endif; ?>

                    <!-- User Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle user-dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="user-cog"></i>
                            <span class="d-lg-none ms-2">My Account</span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><span class="dropdown-item-text"><strong><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></strong></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="change_password.php"><i data-lucide="key-round"></i>Change Password</a></li>
                            <li><a class="dropdown-item" href="logout.php"><i data-lucide="log-out"></i>Logout</a></li>
                        </ul>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<div class="container mt-4">




