<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once dirname(__DIR__, 2) . '/includes/init.php'; // Goes up two levels to /htdocs/includes/init.php

// -- SECURITY CHECK: ENSURE USER HAS LEARNING-RELATED ROLES --
$learning_roles = ['employee', 'trainer', 'admin', 'platform_admin'];
if (!has_any_role($learning_roles)) {
    if (check_role('employee')) { header("location: " . BASE_URL . "employee/index.php"); } 
    else { header("location: " . BASE_URL . "login.php"); }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo BASE_URL; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Learning Portal</title>
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
            <span class="portal-title">Learning Portal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['roles'])): ?>
                    
                    <?php 
                        // Check access to other portals
                        $expense_roles = ['employee', 'approver', 'accounts', 'admin', 'platform_admin'];
                        $purchase_roles = ['sales_team', 'order_team', 'inventory_team', 'purchase_team', 'accounts', 'admin', 'platform_admin', 'purchase_head'];
                        $btl_roles = ['employee', 'approver', 'marketing_manager', 'accounts', 'admin', 'platform_admin'];
                        
                        $can_access_expenses = has_any_role($expense_roles);
                        $can_access_purchase = has_any_role($purchase_roles);
                        $can_access_btl = has_any_role($btl_roles);
                    ?>

                    <?php if ($can_access_expenses || $can_access_purchase || $can_access_btl): // Show Portal Home if they have access to more than just Learning ?>
                        <li class="nav-item"><a class="nav-link" href="portal_home.php"><i data-lucide="home"></i>Portal Home</a></li>
                    <?php endif; ?>
                    
                    <li class="nav-item"><a class="nav-link" href="learning/index.php"><i data-lucide="layout-dashboard"></i>Learning Dashboard</a></li>

                    <?php // --- LEARNING SYSTEM LINKS (REVISED) --- ?>
                    <?php if (check_role('employee') && !has_any_role(['trainer', 'admin'])): // Just an employee/learner ?>
                        <li class="nav-item"><a class="nav-link" href="learning/index.php"><i data-lucide="book-open"></i>My Learning</a></li>
                    <?php endif; ?>
                    
                    <?php if (has_any_role(['trainer', 'admin', 'platform_admin'])): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="trainerDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i data-lucide="graduation-cap"></i> Trainer Tools
                            </a>
                            <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="trainerDropdown">
                                <li><a class="dropdown-item" href="learning/manage_courses.php"><i data-lucide="book-copy"></i>Manage Courses</a></li>
                                <li><a class="dropdown-item" href="learning/assign_courses.php"><i data-lucide="user-check"></i>Assign Courses</a></li>
                                <li><a class="dropdown-item" href="learning/view_progress.php"><i data-lucide="bar-chart-3"></i>View Progress</a></li>
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




