<?php
// /index.php
require_once 'includes/init.php';

// Check if the user is already logged in
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // If logged in, go to the main dashboard
    header("location: " . BASE_URL . "portal_home.php");
} else {
    // If not logged in, go to the login page
    header("location: " . BASE_URL . "login.php");
}
exit;
?>