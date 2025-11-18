<?php
// Initialize the session.
// This is necessary to access the session data before destroying it.
session_start();
 
// Unset all of the session variables to clear them.
$_SESSION = array();
 
// Destroy the session completely.
session_destroy();
 
// Redirect the user to the login page after they have been logged out.
header("location: login.php");
exit;
?>

