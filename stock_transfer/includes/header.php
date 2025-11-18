<?php
// Start output buffering as a safety measure against accidental whitespace or premature output
ob_start();

// -- INITIALIZE SESSION AND DB CONNECTION --
require_once dirname(__DIR__, 2) . '/includes/init.php'; // Goes up two levels to /htdocs/includes/init.php

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE (Stock transfer access) --
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
$is_data_admin = has_any_role(['data_admin', 'admin', 'platform_admin']); 

// --- Get user's location name and GATI name (for display) ---
$user_friendly_name = 'N/A';
$user_gati_location_name = 'N/A';
$gati_column_exists = false;

// 1. Check if the critical column exists
$sql_check_col = "SHOW COLUMNS FROM `stores` LIKE 'gati_location_name'";
if ($result_check_col = $conn->query($sql_check_col)) {
    if ($result_check_col->num_rows > 0) {
        $gati_column_exists = true;
    }
    $result_check_col->free();
}

// 2. Fetch the user's linked store details
$sql_user_store = "SELECT u.store_id, s.store_name, " . 
                  ($gati_column_exists ? "s.gati_location_name" : "NULL as gati_location_name") . 
                  " FROM users u 
                  LEFT JOIN stores s ON u.store_id = s.id 
                  WHERE u.id = ?";

if ($stmt_user = $conn->prepare($sql_user_store)) {
    $stmt_user->bind_param("i", $_SESSION['user_id']);
    if ($stmt_user->execute()) {
        $user_store_details = $stmt_user->get_result()->fetch_assoc();
        
        if ($user_store_details) {
            $user_friendly_name = $user_store_details['store_name'] ?? 'N/A';
            if ($gati_column_exists) {
                // This is the CRITICAL value used for matching in index.php/handler.php
                $user_gati_location_name = $user_store_details['gati_location_name'] ?? 'N/A';
            }
        }
    }
    $stmt_user->close();
}

// 3. Save CRITICAL GATI info to the session (for validation by index.php/handler.php)
$_SESSION['gati_location_name'] = $user_gati_location_name; 
$_SESSION['gati_column_exists'] = $gati_column_exists; 

// End PHP block, start HTML/Output buffer content
ob_end_clean(); // Clear previous buffer and disable further output buffering
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <base href="<?php echo BASE_URL; ?>">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Internal Stock Transfer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .navbar-nav .nav-link { display: flex; align-items: center; }
        .navbar-nav .nav-link i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .navbar-text i { width: 16px; height: 16px; margin-right: 6px; stroke-width: 2.5px; vertical-align: middle; }
        .dropdown-item i { width: 16px; height: 16px; margin-right: 8px; stroke-width: 2.5px; }
        .user-dropdown-toggle::after { display: none; }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="portal_home.php">
            <img src="assets/logo.png" alt="Portal Logo" onerror="this.onerror=null; this.src='https://placehold.co/100x30/FFFFFF/004E54?text=EP';">
            <span class="portal-title">Stock Transfer (ISO)</span>
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
                    
                    <li class="nav-item"><a class="nav-link" href="stock_transfer/index.php"><i data-lucide="send"></i>Stock Transfer</a></li>
                    <li class="nav-item"><a class="nav-link" href="tools/stocklookup.php"><i data-lucide="search"></i>Stock Lookup</a></li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="toolsDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="wrench"></i> More Tools
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark" aria-labelledby="toolsDropdown">
                            <li><a class="dropdown-item" href="tools/index.php"><i data-lucide="layout-dashboard"></i>Tools Dashboard</a></li>
                            <li><a class="dropdown-item" href="tools/productfinder.php"><i data-lucide="filter"></i>Product Finder</a></li>
                            <li><a class="dropdown-item" href="tools/costlookup.php"><i data-lucide="dollar-sign"></i>Cost Lookup</a></li>
                            <?php if ($is_data_admin): ?>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="tools/manage_data.php"><i data-lucide="upload-cloud"></i>Manage Data</a></li>
                            <?php endif; ?>
                        </ul>
                    </li>
                     
                    <?php if (check_role('platform_admin')): ?>
                        <li class="nav-item"><a class="nav-link" href="platform_admin/companies.php"><i data-lucide="building"></i>Manage Companies</a></li> 
                    <?php endif; ?>

                    <!-- User Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle user-dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="user-cog"></i> <span class="d-lg-none ms-2">My Account</span>
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



