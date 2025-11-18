<?php
// Path: D:\xampp\htdocs\Companyportal\referral\index.php

// This file now acts as a router.
// It will show the dashboard if logged in, or the login page if not.

// We need the main init file to check the session
require_once __DIR__ . '/../includes/init.php';

// Check if user is logged in AND is a referrer
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['is_referrer'])) {
    // User is logged in as a referrer, show them the dashboard
    header("Location: " . BASE_URL . "/referral/dashboard.php");
    exit;
} else {
    // User is not logged in or is not a referrer, show them the referrer login page
    header("Location: " . BASE_URL . "/referral/login.php");
    exit;
}
?>
