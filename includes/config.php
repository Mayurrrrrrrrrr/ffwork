<?php
// -- DATABASE SETTINGS --
// CRITICAL: These are your live database credentials.
define('DB_HOST', 'sql206.infinityfree.com');      // Your database host
define('DB_USER', 'if0_40043837');            // Your database username
define('DB_PASS', '9dxw7HGm504wn');                // Your database password
define('DB_NAME', 'if0_40043837_combinddata');    // Your database name

// --- DEFINE APPLICATION BASE URL ---
// This is now set to your live domain.
if (!defined('BASE_URL')) {
    define('BASE_URL', 'https://ffteam.is-best.net/'); // Your live domain
}

// --- Establish Database Connection ---
// Use mysqli with error reporting enabled for connection issues.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); // Enable exceptions

try {
    // Create the connection object
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Set charset for proper character handling (important!)
    $conn->set_charset("utf8mb4"); 
    
    // Make connection available globally
    $GLOBALS['conn'] = $conn; 

} catch (mysqli_sql_exception $e) {
    // This 'catch' block is required for the 'try' block above.
    // If you see this, it means your DB_HOST, DB_USER, DB_PASS, or DB_NAME is wrong.
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database connection failed. Please check credentials in config.php. Error: " . $e->getMessage()); 
}

// NOTE: Ensure there are NO blank lines or characters after this closing tag.
?>