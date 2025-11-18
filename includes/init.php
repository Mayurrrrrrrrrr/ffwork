<?php
// Use output buffering as a safeguard against any stray whitespace
ob_start();

// -- CORE APPLICATION SETUP FOR MULTI-TENANCY --

// 1. START THE SESSION
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400, // 24 hours
        'path' => '/',
        'domain' => $_SERVER['HTTP_HOST'],
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// 2. INCLUDE DATABASE CONFIGURATION
require_once __DIR__ . '/config.php'; // Provides $conn

// 3. DEFINE GLOBAL HELPER FUNCTIONS FOR MULTI-ROLE SYSTEM

if (!function_exists('check_role')) {
    function check_role($required_role) {
        if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
            return in_array(strtolower($required_role), array_map('strtolower', $_SESSION['roles']));
        }
        return false;
    }
}

if (!function_exists('has_any_role')) {
    function has_any_role($required_roles) {
        if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && isset($_SESSION['roles']) && is_array($_SESSION['roles'])) {
            foreach ($required_roles as $role) {
                if (check_role($role)) { return true; }
            }
        }
        return false;
    }
}

if (!function_exists('get_current_company_id')) {
    function get_current_company_id() {
        return $_SESSION['company_id'] ?? null;
    }
}

// 4. GLOBAL AUDIT LOGGING FUNCTION
if (!function_exists('log_audit_action')) {
    function log_audit_action($db, $action_type, $log_message, $user_id = null, $company_id = null, $target_type = null, $target_id = null) {
        if (!$db || !($db instanceof mysqli) || $db->connect_error) {
             error_log("Audit Log Error: Invalid database connection provided.");
             return false;
        }
        $log_user_id = $user_id ?? ($_SESSION['user_id'] ?? null);
        $log_company_id = ($company_id !== null) ? $company_id : get_current_company_id(); 
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $sql = "INSERT INTO audit_logs (company_id, user_id, action_type, target_type, target_id, log_message, ip_address) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        
        // --- THIS IS THE FIXED LINE (Line 66) ---
        if ($stmt === false) { error_log("Audit Log Error: Failed to prepare - " . $db->error); return false; } 
        // ----------------------------------------

        $target_id_str = $target_id !== null ? (string)$target_id : null; 
        $bind_success = $stmt->bind_param("iisssss", $log_company_id, $log_user_id, $action_type, $target_type, $target_id_str, $log_message, $ip_address);
         if ($bind_success === false) { error_log("Audit Log Error: Failed to bind parameters - " . $stmt->error); $stmt->close(); return false; }
        if (!$stmt->execute()) { error_log("Audit Log Error: Failed to execute - " . $stmt->error); $stmt->close(); return false; }
        $stmt->close();
        return true;
    }
}

// 5. GLOBAL HELPER FUNCTION FOR EXPENSE STATUS BADGES
if (!function_exists('get_status_badge')) {
    function get_status_badge($status) {
        switch($status) {
            case 'approved': case 'paid': return 'bg-success';
            case 'rejected': return 'bg-danger';
            case 'pending_approval': return 'bg-warning text-dark';
            case 'pending_verification': return 'bg-info text-dark';
            default: return 'bg-secondary'; // for 'draft'
        }
    }
}

// 6. GLOBAL HELPER FUNCTION FOR PO STATUS BADGES
if (!function_exists('get_po_status_badge')) {
    function get_po_status_badge($status) {
        $statuses = [
            'New Request' => 'bg-info text-dark', 'Order Placed' => 'bg-primary',
            'Goods Received' => 'bg-secondary', 'Inward Complete' => 'bg-secondary',
            'QC Passed' => 'bg-success', 'QC Failed' => 'bg-danger',
            'Imaging Complete' => 'bg-dark', 'Purchase File Generated' => 'bg-warning text-dark',
            'Accounts Verified' => 'bg-success', 'Invoice Received' => 'bg-primary',
            'Purchase Complete' => 'bg-success',
            'Customer Delivered' => 'bg-light text-dark border'
        ];
        return $statuses[$status] ?? 'bg-light text-dark'; 
    }
}

// 7. GLOBAL HELPER FUNCTION FOR BTL STATUS BADGES
if (!function_exists('get_btl_status_badge')) {
    function get_btl_status_badge($status) {
        switch($status) {
            case 'Approved': return 'bg-success';
            case 'Rejected': return 'bg-danger';
            case 'Pending L1 Approval': return 'bg-warning text-dark';
            case 'Pending L2 Approval': return 'bg-info text-dark';
            default: return 'bg-secondary'; // draft
        }
    }
}
?>