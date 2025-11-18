<?php
// Path: D:\xampp\htdocs\Companyportal\referral\includes\header.php

// 1. Load the module-specific init file
// This starts the session, loads $conn, BASE_URL, and checks DB connection.
require_once __DIR__ . '/../init.php'; 

// 2. Define page title if not set
$page_title = $page_title ?? "Firefly Referral Program";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <base href="<?php echo BASE_URL; ?>">
    
    <title><?php echo htmlspecialchars($page_title); ?> - Company Portal</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="css/style.css"> 
    
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style>
        /* Referral-portal-specific styles */
        body {
            background-color: #f8f9fa; /* A light grey background */
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }
        .navbar-brand img {
            height: 30px;
        }
        .card-header {
            background-color: #fff;
            border-bottom: 1px solid #dee2e6;
        }
        .main-container {
            flex: 1;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark shadow-sm">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center" href="<?php echo BASE_URL; ?>referral/">
            <img src="<?php echo BASE_URL; ?>assets/logo.png" alt="Portal Logo" class="me-2" 
                 onerror="this.onerror=null; this.src='https://placehold.co/100x30/FFFFFF/004E54?text=Referral';">
            <span class="portal-title">Referral Program</span>
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#referralNav" aria-controls="referralNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="referralNav">
            <ul class="navbar-nav ms-auto">
                
                <?php // Check if a REFERRER is logged in
                if (isset($_SESSION["referrer_loggedin"]) && $_SESSION["referrer_loggedin"] === true): ?>
                    
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo BASE_URL; ?>referral/dashboard.php">
                            <i data-lucide="layout-dashboard" class="me-1" style="width:16px;"></i> Dashboard
                        </a>
                    </li>
                    
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i data-lucide="user" class="me-1" style="width:16px;"></i> 
                            <?php echo htmlspecialchars($_SESSION['referrer_name'] ?? 'Account'); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><span class="dropdown-item-text">Logged in as:<br><strong><?php echo htmlspecialchars($_SESSION['referrer_mobile'] ?? ''); ?></strong></span></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?php echo BASE_URL; ?>referral/logout.php">
                                <i data-lucide="log-out" class="me-1" style="width:16px;"></i> Logout
                            </a></li>
                        </ul>
                    </li>

                <?php else: 
                    // User is not logged in as a referrer
                    // Show login/register links
                    $current_page = basename($_SERVER['PHP_SELF']);
                ?>
                    <?php if ($current_page != 'login.php'): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo BASE_URL; ?>referral/login.php">Login</a>
                        </li>
                    <?php endif; ?>
                    <?php if ($current_page != 'register.php'): ?>
                         <li class="nav-item">
                            <a class="nav-link btn btn-outline-light btn-sm px-3" href="<?php echo BASE_URL; ?>referral/register.php">Sign Up</a>
                        </li>
                    <?php endif; ?>
                    
                <?php endif; ?>
                
            </ul>
        </div>
    </div>
</nav>

<div class="container my-4 main-container">