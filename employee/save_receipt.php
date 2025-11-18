<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, log_audit_action()

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    if (has_any_role(['admin', 'accounts', 'approver'])) { header("location: " . BASE_URL . "admin/index.php"); } 
    else { header("location: " . BASE_URL . "login.php"); }
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null;

if (!$company_id || !$user_id) {
    session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}

$error_message = '';
$receipt_path = null; // Path relative to web root
$target_file = null; // Full server path for cleanup
$notes = trim($_POST['notes'] ?? '');
$original_filename = ''; // For logging

// --- FILE UPLOAD AND VALIDATION ---
if (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == UPLOAD_ERR_OK) {
    $target_dir = dirname(__DIR__) . '/uploads/'; 
    if (!is_dir($target_dir)) { if (!mkdir($target_dir, 0755, true)) { $error_message = "Failed to create upload directory."; } } 
    elseif (!is_writable($target_dir)) { $error_message = "Upload directory is not writable."; }

    if(empty($error_message)){
        $file_info = $_FILES['receipt'];
        $original_filename = basename($file_info["name"]); // Get original name for logging
        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        $safe_filename = "quick_receipt_c" . $company_id . "_u" . $user_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename; 
        
        $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
        $max_file_size = 5 * 1024 * 1024; // 5MB

        if (!in_array($file_extension, $allowed_types)) { $error_message = "Invalid file type."; }
        elseif ($file_info['size'] > $max_file_size) { $error_message = "File too large (max 5MB)."; }
        elseif (!move_uploaded_file($file_info["tmp_name"], $target_file)) {
             $upload_errors = [ /* Error codes */ ];
             $error_code = $_FILES['receipt']['error'];
             $error_message = $upload_errors[$error_code] ?? "Failed to save uploaded file.";
        } else {
            $receipt_path = "uploads/" . $safe_filename; 
        }
    }
} else { /* Handle initial upload errors */ }

// --- SAVE TO DATABASE ---
if (empty($error_message) && $receipt_path) {
    $sql_insert = "INSERT INTO unassigned_receipts (company_id, user_id, receipt_url, notes) VALUES (?, ?, ?, ?)";
    if ($stmt_insert = $conn->prepare($sql_insert)) {
        $stmt_insert->bind_param("iiss", $company_id, $user_id, $receipt_path, $notes);
        if ($stmt_insert->execute()) {
            $new_receipt_id = $conn->insert_id;
            // Log success
            $log_msg = "Saved new receipt to wallet. File: {$original_filename}, Notes: {$notes}";
            log_audit_action($conn, 'receipt_saved_to_wallet', $log_msg, $user_id, $company_id, 'receipt', $new_receipt_id);
            
            header("location: " . BASE_URL . "employee/index.php?quick_save_status=success"); 
            exit;
        } else {
            $error_message = "Database error saving receipt: " . $stmt_insert->error;
            log_audit_action($conn, 'receipt_save_failed', "Failed saving receipt to DB. Error: ".$stmt_insert->error, $user_id, $company_id);
            if ($target_file && file_exists($target_file)) { unlink($target_file); }
        }
        $stmt_insert->close();
    } else {
        $error_message = "Database prepare error: " . $conn->error;
        log_audit_action($conn, 'receipt_save_failed', "Failed saving receipt to DB. Prepare Error.", $user_id, $company_id);
        if ($target_file && file_exists($target_file)) { unlink($target_file); }
    }
}

// --- REDIRECT ON ERROR ---
if (!empty($error_message)) {
     // Log the failure before redirecting
     log_audit_action($conn, 'receipt_save_failed', "Failed saving receipt. Error: ".$error_message, $user_id, $company_id);
     header("location: " . BASE_URL . "employee/index.php?quick_save_error=" . urlencode($error_message)); 
     exit;
} else if (!$receipt_path && $_SERVER['REQUEST_METHOD'] === 'POST') { 
    // Handle case where no file was uploaded but form submitted
    log_audit_action($conn, 'receipt_save_failed', "Failed saving receipt. No file uploaded.", $user_id, $company_id);
    header("location: " . BASE_URL . "employee/index.php?quick_save_error=" . urlencode("No file was uploaded."));
    exit;
} else {
     // Fallback redirect
     log_audit_action($conn, 'receipt_save_failed', "Failed saving receipt. Unknown error state.", $user_id, $company_id);
     header("location: " . BASE_URL . "employee/index.php?quick_save_error=" . urlencode("An unexpected error occurred."));
     exit;
}

if(isset($conn)) $conn->close();

?>


