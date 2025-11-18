<?php
// 1. Force all errors to be displayed
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>Portal Debug Test</h1>";
echo "<p>This will test the core files and show the *exact* error.</p>";
echo "<hr>";

// 2. Test 'init.php'
echo "<h2>Testing 'includes/init.php'</h2>";
echo "<p>Attempting to include 'includes/init.php'...</p>";

// This will catch the 'die()' from config.php if it's a DB issue,
// or a 'Failed to open' error if the path is wrong.
try {
    require_once 'includes/init.php';
    echo "<p style='color:green; font-weight:bold;'>SUCCESS: 'includes/init.php' was included.</p>";
} catch (Throwable $t) {
    echo "<p style='color:red; font-weight:bold;'>CRITICAL ERROR during include:</p>";
    echo "<pre style='background:#fee; border:1px solid red; padding:10px;'>" . $t->getMessage() . "</pre>";
    echo "<p><strong>Stack Trace:</strong><br>" . nl2br($t->getTraceAsString()) . "</p>";
    echo "<hr><h3>Test Halted.</h3><p>The error above is your problem. Please send me this full message.</p>";
    exit;
}

// 3. Test if 'config.php' loaded (by checking for BASE_URL)
echo "<h2>Testing 'config.php' (via init.php)</h2>";
if (defined('BASE_URL')) {
    echo "<p style='color:green; font-weight:bold;'>SUCCESS: Constant 'BASE_URL' is defined.</p>";
    echo "<p>Value: " . BASE_URL . "</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>FAILURE: Constant 'BASE_URL' was NOT defined. 'config.php' did not load correctly.</p>";
}

// 4. Test if 'init.php' defined the function
echo "<h2>Testing 'init.php' functions</h2>";
if (function_exists('check_role')) {
    echo "<p style='color:green; font-weight:bold;'>SUCCESS: Function 'check_role()' is defined.</p>";
} else {
    echo "<p style='color:red; font-weight:bold;'>FAILURE: Function 'check_role()' is NOT defined. 'init.php' did not complete.</p>";
}

echo "<hr><h3>Debug Test Finished.</h3>";
?>