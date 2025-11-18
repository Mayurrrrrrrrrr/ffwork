<?php
// --- INITIALIZE ---
require_once 'init.php'; // We still need init.php to get BASE_URL

// --- DESTROY REFERRER SESSION ---
// Unset all of the referrer-specific session variables
unset($_SESSION["referrer_loggedin"]);
unset($_SESSION["referrer_id"]);
unset($_SESSION["referrer_name"]);
unset($_SESSION["referrer_mobile"]);

// --- REDIRECT TO LOGIN PAGE ---
// Redirect them to the referral login page, not the main portal login
header("location: ". BASE_URL. "referral/login.php");
exit;
?>