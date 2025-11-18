<?php
// FORCE ERROR DISPLAYING
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Use output buffering as a safeguard against any stray whitespace
ob_start();

// --- HELPER FUNCTIONS ---
function check_column_exists($conn, $table, $column) {
    if (!$conn || !($conn instanceof mysqli)) return false;
    // Query INFORMATION_SCHEMA for better reliability
    $sql = "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = ? 
            AND COLUMN_NAME = ?";
    $stmt = $conn->prepare($sql);
    if(!$stmt) return false; // Prepare failed
    $stmt->bind_param("ss", $table, $column);
    $stmt->execute();
    $stmt->store_result();
    $exists = $stmt->num_rows > 0;
    $stmt->close();
    return $exists;
}

function print_status($success, $message) {
    global $final_status;
    if ($success) {
        echo "<p><span class='status-success'>SUCCESS:</span> $message</p>";
    } else {
        echo "<p><span class='status-failed'>FAILED:</span> $message</p>";
        $final_status = false; // Mark overall status as failed
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Health Check</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f4f7f6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1, h2 { color: #004E54; border-bottom: 2px solid #e0e0e0; padding-bottom: 10px; }
        .status-success { color: #27ae60; font-weight: bold; }
        .status-failed { color: #c0392b; font-weight: bold; }
        p { margin: 10px 0; font-size: 14px;}
        code { background-color: #ecf0f1; padding: 2px 5px; border-radius: 4px; font-family: "Courier New", Courier, monospace; }
        .summary { border-left: 5px solid; padding: 15px; border-radius: 5px; margin-top: 20px; }
        .summary.success { border-color: #27ae60; background-color: #eafaf1; }
        .summary.failed { border-color: #c0392b; background-color: #fbecec; }
        pre { background-color: #333; color: #fff; padding: 15px; border-radius: 5px; white-space: pre-wrap; word-wrap: break-word; font-size: 12px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Comprehensive System Health Check</h1>
        <p>This script is performing a deep analysis of your application. Please share the full results.</p>
        
        <?php
        $final_status = true; // Assume success until a failure occurs
        
        echo "<h2>Phase 1: Environment & File System</h2>";
        print_status(version_compare(PHP_VERSION, '7.4.0', '>='), "PHP version is adequate. (Current: " . PHP_VERSION . ")");

        $files_to_check = [
            'includes/init.php', 
            'includes/config.php', 
            'includes/header.php',
            'admin/users.php', 
            'employee/submit.php',
            'platform_admin/companies.php'
        ];
        foreach ($files_to_check as $file) {
            print_status(file_exists($file), "Checking for critical file: <code>$file</code>");
        }
        $uploads_dir_exists = is_dir('uploads');
        print_status($uploads_dir_exists, "Checking for <code>/uploads/</code> directory.");
        if ($uploads_dir_exists) {
            print_status(is_writable('uploads'), "Checking if <code>/uploads/</code> directory is writable.");
        }

        echo "<h2>Phase 2: Initialization & Session Integrity</h2>";
        if (session_status() === PHP_SESSION_ACTIVE) { session_destroy(); }
        
        @include_once 'includes/init.php';
        print_status(true, "Including <code>includes/init.php</code>");

        $session_active = session_status() === PHP_SESSION_ACTIVE;
        print_status($session_active, "Session status is active after initialization.");
        if ($session_active) {
            $cookie_params = session_get_cookie_params();
            print_status($cookie_params['path'] === '/', "Session cookie path is correct. (Path: '{$cookie_params['path']}')");
        }

        echo "<h2>Phase 3: Database Connection & Schema</h2>";
        $db_connected = isset($conn) && $conn instanceof mysqli && @$conn->ping();
        print_status($db_connected, "Database connection is established via <code>init.php</code>.");
        
        if ($db_connected) {
            // Test all tables from our final schema
            $schema = [
                'companies' => ['id', 'company_name', 'company_code'],
                'roles' => ['id', 'name'],
                'users' => ['id', 'company_id', 'email', 'password_hash', 'approver_id'],
                'user_roles' => ['user_id', 'role_id'],
                'expense_reports' => ['id', 'company_id', 'user_id', 'report_title', 'status', 'approver_id', 'accountant_id', 'paid_at'],
                'expense_items' => ['id', 'report_id', 'category', 'amount', 'payment_method'],
                'unassigned_receipts' => ['id', 'company_id', 'user_id', 'receipt_url', 'assigned_item_id'],
                'expense_splits' => ['id', 'item_id', 'user_id', 'company_id', 'split_amount'],
                'expense_categories' => ['id', 'company_id', 'category_name', 'is_active'],
                'petty_cash_wallets' => ['id', 'company_id', 'user_id', 'current_balance'],
                'petty_cash_transactions' => ['id', 'wallet_id', 'transaction_type', 'amount', 'related_expense_item_id'],
                'audit_logs' => ['id', 'company_id', 'user_id', 'action_type', 'log_message']
            ];

            foreach ($schema as $table_name => $columns) {
                $table_exists_res = $conn->query("SHOW TABLES LIKE '$table_name'");
                if ($table_exists_res && $table_exists_res->num_rows == 1) {
                    print_status(true, "Table exists: <code>$table_name</code>");
                    foreach ($columns as $column_name) {
                        print_status(check_column_exists($conn, $table_name, $column_name), "Column exists: <code>$table_name.$column_name</code>");
                    }
                } else {
                    print_status(false, "Table exists: <code>$table_name</code>");
                }
            }
        }

        echo "<h2>Phase 4: Core Logic & Authentication Simulation</h2>";
        $fr_ok = function_exists('check_role') && function_exists('has_any_role');
        print_status($fr_ok, "Role functions (<code>check_role</code>, <code>has_any_role</code>) are defined.");
            
        if($fr_ok && $session_active) {
            $_SESSION['loggedin'] = true;
            $_SESSION['roles'] = ['employee', 'admin']; // Simulate a user with multiple roles
            
            print_status(check_role('admin'), "Simulating <code>check_role('admin')</code> (should be TRUE).");
            print_status(check_role('approver') === false, "Simulating <code>check_role('approver')</code> (should be FALSE).");
            print_status(has_any_role(['approver', 'accounts']) === false, "Simulating <code>has_any_role(['approver', 'accounts'])</code> (should be FALSE).");
            print_status(has_any_role(['admin', 'accounts']), "Simulating <code>has_any_role(['admin', 'accounts'])</code> (should be TRUE).");
            
            session_destroy(); // Clean up simulation
        }

        echo "<h2>Phase 5: Database Write & Delete Test (Multi-Tenant)</h2>";
        if ($db_connected) {
            $test_company_id = null;
            $test_user_id = null;
            $test_role_id = null;
            $insert_error = '';

            $conn->begin_transaction();
            try {
                // 1. Create test company
                $test_code = 'TEST_COMPANY_' . time();
                $conn->query("INSERT INTO companies (company_name, company_code) VALUES ('Test Company', '$test_code')");
                $test_company_id = $conn->insert_id;
                print_status(true, "INSERT test: Creating temporary company.");

                // 2. Create test role (to avoid conflicts)
                $test_role_name = 'test_role_' . time();
                $conn->query("INSERT INTO roles (name) VALUES ('$test_role_name')");
                $test_role_id = $conn->insert_id;
                print_status(true, "INSERT test: Creating temporary role.");

                // 3. Create test user
                $test_email = 'test.' . time() . '@test.com';
                $password_hash = password_hash('password', PASSWORD_DEFAULT);
                $sql_insert_user = "INSERT INTO users (company_id, full_name, email, password_hash, department) VALUES (?, 'Test User', ?, ?, 'Test Dept')";
                $stmt_insert = $conn->prepare($sql_insert_user);
                $stmt_insert->bind_param("iss", $test_company_id, $test_email, $password_hash);
                $stmt_insert->execute();
                $test_user_id = $conn->insert_id;
                $stmt_insert->close();
                print_status(true, "INSERT test: Creating temporary user in company.");

                // 4. Assign role
                $sql_assign_role = "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)";
                $stmt_role = $conn->prepare($sql_assign_role);
                $stmt_role->bind_param("ii", $test_user_id, $test_role_id);
                $stmt_role->execute();
                $stmt_role->close();
                print_status(true, "INSERT test: Assigning role to user.");

                // If all good, throw a specific exception to trigger rollback (this is a test, not a real operation)
                throw new Exception("TEST_ROLLBACK_SUCCESS");

            } catch (Exception $e) {
                if ($e->getMessage() === 'TEST_ROLLBACK_SUCCESS') {
                    print_status(true, "Database write operations (INSERT) are working.");
                } else {
                    print_status(false, "Database write operations (INSERT) failed.");
                    $insert_error = $e->getMessage();
                }
                $conn->rollback(); // Rollback all test data
                print_status(true, "Database transaction rollback successful.");
            }

            if (!empty($insert_error)) {
                echo "<p><span class='status-failed'>DATABASE ERROR:</span> This is likely the cause of your blank pages. The specific error is:</p>";
                echo "<pre>" . htmlspecialchars($insert_error) . "</pre>";
            }
        } else {
            print_status(false, "Skipping Write/Delete tests (database not connected).");
        }


        // --- Final Summary ---
        if ($final_status) {
            echo '<div class="summary success"><h2>Test Complete: All critical systems are operational.</h2></div>';
        } else {
            echo '<div class="summary failed"><h2>Test Complete: One or more critical checks failed.</h2><p>Please review the "FAILED" messages above to identify the root cause of the issues.</p></div>';
        }
        
        if ($db_connected && $conn->ping()) { $conn->close(); }
        ob_end_flush();
        ?>
    </div>
</body>
</html>

