<?php
// Path: D:\xampp\htdocs\Companyportal\referral\init.php

// 1. Load the main configuration file
// This will define $conn, BASE_URL, and start the session ONCE.
require_once __DIR__ . '/../includes/init.php';

// 2. Add any referral-specific functions or settings here
// (We will add functions.php later if needed)


// Make the database connection global
// We check for $conn (which comes from the main init.php)
global $conn;
if (!isset($conn) || !$conn instanceof mysqli) {
    // This check ensures our database connection from the main init.php was successful
    die("Database connection not established in referral/init.php. Check main configuration.");
}
?>