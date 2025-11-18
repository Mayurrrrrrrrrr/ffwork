<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This MUST be the very first line.
require_once 'init.php'; // Provides $conn, role checks, all helper functions

// -- SECURITY CHECK: ENSURE USER IS LOGGED IN --
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || empty($_SESSION['roles'])) {
    session_destroy();
    header("location: " . BASE_URL . "login.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo BASE_URL; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Workportal</title> 
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Tweaks for icon alignment */
        .navbar-nav .nav-link { display: flex; align-items: center; }
        .navbar-nav .nav-link i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .navbar-text i { width: 16px; height: 16px; margin-right: 6px; stroke-width: 2.5px; vertical-align: middle; }
        .dropdown-item i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .user-dropdown-toggle::after { display: none; } /* Hide default caret */
        /* Search Bar Styling */
        .search-form { width: 250px; margin-right: 15px; }
        @media (max-width: 991px) { /* Mobile */
            .search-form { width: 100%; margin-right: 0; margin-bottom: 8px; order: 1; }
        }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="portal_home.php">
            <img src="assets/logo.png" alt="Portal Logo" onerror="this.onerror=null; this.src='https://placehold.co/100x30/FFFFFF/004E54?text=EP';">
            <span class="portal-title">Workportal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['roles'])): ?>
                    
                    <?php 
                        // Check access to portals
                        $expense_roles = ['employee', 'approver', 'accounts', 'admin', 'platform_admin'];
                        $purchase_roles = ['sales_team', 'order_team', 'inventory_team', 'purchase_team', 'accounts', 'admin', 'platform_admin', 'purchase_head'];
                        $btl_roles = ['employee', 'approver', 'marketing_manager', 'accounts', 'admin', 'platform_admin'];
                        $learning_roles = ['employee', 'trainer', 'admin', 'platform_admin'];
                        $task_roles = ['employee', 'approver', 'admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'trainer'];
                        $reports_roles = ['employee', 'admin', 'platform_admin']; 
                        $tools_roles = ['employee', 'admin', 'platform_admin', 'data_admin'];
                        $sales_dashboard_roles = ['admin', 'platform_admin']; // --- NEW ---
                        
                        $can_access_expenses = has_any_role($expense_roles);
                        $can_access_purchase = has_any_role($purchase_roles);
                        $can_access_btl = has_any_role($btl_roles);
                        $can_access_learning = has_any_role($learning_roles);
                        $can_access_tasks = has_any_role($task_roles);
                        $can_access_reports = has_any_role($reports_roles);
                        $can_access_tools = has_any_role($tools_roles);
                        $can_access_sales_dashboard = has_any_role($sales_dashboard_roles); // --- NEW ---
                        
                        // Roles allowed to search users
                        $can_search_users = has_any_role(['admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'approver', 'trainer']);
                    ?>

                    <?php // --- NEW: Search Bar (Visible to Managers/Admins) ---
                    if ($can_search_users): ?>
                    <li class="nav-item">
                        <form action="search_users.php" method="get" class="search-form d-flex">
                            <input type="search" name="q" class="form-control form-control-sm" placeholder="Find User (Emp Code/Name)">
                            <button type="submit" class="btn btn-sm btn-outline-light ms-2"><i data-lucide="search"></i></button>
                        </form>
                    </li>
                    <?php endif; ?>

                    <?php // Show Portal Home if they have access to multiple portals
                        // --- UPDATED PORTAL COUNT ---
                        $portal_count = (int)$can_access_expenses + (int)$can_access_purchase + (int)$can_access_btl + (int)$can_access_learning + (int)$can_access_tasks + (int)$can_access_reports + (int)$can_access_tools + (int)$can_access_sales_dashboard;
                        if ($portal_count > 1): 
                    ?>
                        <li class="nav-item"><a class="nav-link" href="portal_home.php"><i data-lucide="home"></i>Portal Home</a></li>
                    <?php endif; ?>

                    <?php // --- Expense Portal Links --- ?>
                    <?php if ($can_access_expenses): ?>
                        <?php if (has_any_role(['admin', 'accounts', 'approver', 'platform_admin'])): ?>
                            <li class="nav-item"><a class="nav-link" href="admin/index.php"><i data-lucide="banknote"></i>Expenses</a></li>
                        <?php elseif (check_role('employee')): ?>
                            <li class="nav-item"><a class="nav-link" href="employee/index.php"><i data-lucide="banknote"></i>Expenses</a></li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php // --- Purchase System Link --- ?>
                    <?php if ($can_access_purchase): ?>
                         <li class="nav-item"><a class="nav-link" href="purchase/index.php"><i data-lucide="shopping-cart"></i>Purchase</a></li>
                    <?php endif; ?>
                    
                    <?php // --- BTL Link --- ?>
                    <?php if ($can_access_btl): ?>
                         <li class="nav-item"><a class="nav-link" href="btl/index.php"><i data-lucide="sparkles"></i>BTL</a></li>
                    <?php endif; ?>
                    
                    <?php // --- Learning Link --- ?>
                    <?php if ($can_access_learning): ?>
                         <li class="nav-item"><a class="nav-link" href="learning/index.php"><i data-lucide="graduation-cap"></i>Learning</a></li>
                    <?php endif; ?>
                    
                    <?php // --- Tasks Link --- ?>
                    <?php if ($can_access_tasks): ?>
                         <li class="nav-item"><a class="nav-link" href="tasks/index.php"><i data-lucide="list-checks"></i>Tasks</a></li>
                    <?php endif; ?>
                    
                    <?php // --- Reports Link --- ?>
                    <?php if ($can_access_reports): ?>
                         <li class="nav-item"><a class="nav-link" href="reports/index.php"><i data-lucide="file-spreadsheet"></i>Reports</a></li>
                    <?php endif; ?>

                    <?php // --- Tools Link --- ?>
                    <?php if ($can_access_tools): ?>
                         <li class="nav-item"><a class="nav-link" href="tools/index.php"><i data-lucide="wrench"></i>Tools</a></li>
                    <?php endif; ?>

                    <?php if ($can_access_sales_dashboard): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i data-lucide="bar-chart-3"></i>Sales Dashboard
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark">
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/sales_dashboard.php"><i data-lucide="layout-dashboard"></i>View Dashboard</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/sales_financial_report.php"><i data-lucide="line-chart"></i>Financial Report</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/sales_customer_report.php"><i data-lucide="users"></i>Customer Analytics</a></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/sales_report_builder.php"><i data-lucide="list-filter"></i>Custom Report Builder</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>admin/sales_importer.php"><i data-lucide="upload-cloud"></i>Upload CSV Report</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                    <?php // --- Finance & Admin Settings Dropdowns (Keep existing logic) --- ?>
                    <?php if (has_any_role(['accounts', 'admin', 'approver'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="financeDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i data-lucide="sliders"></i> More
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="financeDropdown">
                                <?php if (has_any_role(['accounts', 'admin'])): ?>
                                    <li><h6 class="dropdown-header">Finance (Expenses)</h6></li>
                                    <li><a class="dropdown-item" href="admin/payouts.php"><i data-lucide="dollar-sign"></i>Payouts</a></li>
                                    <li><a class="dropdown-item" href="admin/petty_cash.php"><i data-lucide="wallet"></i>Petty Cash Mgmt</a></li>
                                    <li><a class="dropdown-item" href="admin/analytics.php"><i data-lucide="pie-chart"></i>Expense Analytics</a></li>
                                    <li><a class="dropdown-item" href="admin/report_anomalies.php"><i data-lucide="alert-triangle"></i>Expense Anomaly Report</a></li>
                                    <li><a class="dropdown-item" href="admin/report_petty_cash.php"><i data-lucide="book-open"></i>Petty Cash Report</a></li> 
                                    <li><hr class="dropdown-divider"></li>
                                <?php endif; ?>
                                
                                <?php if (check_role('admin')): ?>
                                    <li><h6 class="dropdown-header">Admin Settings</h6></li>
                                    <li><a class="dropdown-item" href="admin/users.php"><i data-lucide="users"></i>Manage Users</a></li>
                                    <li><a class="dropdown-item" href="admin/categories.php"><i data-lucide="tags"></i>Manage Expense Categories</a></li> 
                                    <li><a class="dropdown-item" href="admin/manage_stores.php"><i data-lucide="store"></i>Manage Stores</a></li> 
                                    <li><a class="dropdown-item" href="admin/manage_announcements.php"><i data-lucide="megaphone"></i>Manage Announcements</a></li>
                                    <li><a class="dropdown-item" href="admin/audit_logs.php"><i data-lucide="shield-check"></i>Audit Log</a></li> 
                                <?php endif; ?>
                            </ul>
                        </li>
                    <?php endif; ?>
                     
                     <?php if (check_role('platform_admin')): ?>
                        <li class="nav-item"><a class="nav-link" href="platform_admin/companies.php"><i data-lucide="building"></i>Manage Companies</a></li> 
                    <?php endif; ?>

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