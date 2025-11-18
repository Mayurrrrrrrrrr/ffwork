<?php
// --- INITIALIZE ---
require_once 'init.php';

// Set header to return JSON
header('Content-Type: application/json');

// --- SECURITY CHECK ---
if (!isset($_SESSION["referrer_loggedin"]) || $_SESSION["referrer_loggedin"] !== true) {
    echo json_encode(['success' => false, 'message' => 'Authentication required. Please log in again.']);
    exit;
}

// --- MAIN ACTION HANDLER ---
$action = $_POST['action'] ?? '';
$referrer_id = $_SESSION["referrer_id"];
$response = ['success' => false, 'message' => 'Invalid action.'];

if ($_SERVER["REQUEST_METHOD"] == "POST" && $action == 'add_referral') {
    
    // --- 1. GET & SANITIZE DATA ---
    $invitee_name = trim($_POST['invitee_name'] ?? '');
    $invitee_contact = trim($_POST['invitee_contact'] ?? '');
    $invitee_address = trim($_POST['invitee_address'] ?? '');
    $invitee_age = !empty($_POST['invitee_age']) ? (int)$_POST['invitee_age'] : null;
    $invitee_gender = trim($_POST['invitee_gender'] ?? '');
    $interested_items = trim($_POST['interested_items'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    // --- 2. VALIDATE REQUIRED FIELDS ---
    if (empty($invitee_name) || empty($invitee_contact)) {
        $response['message'] = 'Friend\'s Name and Contact are required.';
        echo json_encode($response);
        exit;
    }

    // --- 3. GENERATE UNIQUE CODE ---
    // This function will generate a code and check the DB to ensure it's unique
    function generateUniqueReferralCode($db_conn) {
        $prefix = "FFD-R-";
        $is_unique = false;
        $code = "";
        
        while (!$is_unique) {
            $random_part = strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)); // 5 char random string
            $code = $prefix . $random_part;

            $stmt_check = $db_conn->prepare("SELECT id FROM referrals WHERE referral_code = ?");
            $stmt_check->bind_param("s", $code);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows == 0) {
                $is_unique = true;
            }
            $stmt_check->close();
        }
        return $code;
    }
    
    $referral_code = generateUniqueReferralCode($conn);
    
    // --- 4. GET COMPANY ID (CRITICAL FOR MULTI-TENANCY) ---
    // !! NOTE: We are assuming a default company_id of 1.
    // In a full multi-tenant app, the referrer should be linked to a company.
    // For this module, we will hardcode 1.
    $company_id = 1; 

    // We can try to get a store_id from the internal user session if it exists,
    // but the referrer is submitting this, not an employee.
    // We will leave store_id NULL for now. Staff can assign one later.
    $store_id = null;

    // --- 5. EXECUTE DATABASE TRANSACTION ---
    $conn->begin_transaction();
    try {
        // --- Insert into referrals table ---
        $sql_insert = "INSERT INTO referrals 
                        (referrer_id, company_id, store_id, invitee_name, invitee_contact, 
                         invitee_address, invitee_age, invitee_gender, interested_items, 
                         remarks, referral_code, status)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')";
                       
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("iiisssissss", 
            $referrer_id, $company_id, $store_id, $invitee_name, $invitee_contact,
            $invitee_address, $invitee_age, $invitee_gender, $interested_items,
            $remarks, $referral_code
        );
        
        if (!$stmt_insert->execute()) {
            throw new Exception("Error saving referral: " . $stmt_insert->error);
        }
        
        $new_referral_id = $stmt_insert->insert_id;
        $stmt_insert->close();

        // --- Create Audit Log Entry ---
        $audit_action = "REFERRAL_CREATED";
        $audit_new_value = "Referrer ID $referrer_id created referral for $invitee_name ($invitee_contact) with code $referral_code.";
        
        $sql_audit = "INSERT INTO referral_audit_log 
                        (referral_id, referrer_id, action, new_value) 
                      VALUES (?, ?, ?, ?)";
                      
        $stmt_audit = $conn->prepare($sql_audit);
        $stmt_audit->bind_param("iiss", $new_referral_id, $referrer_id, $audit_action, $audit_new_value);
        $stmt_audit->execute();
        $stmt_audit->close();
        
        // --- Commit and set success response ---
        $conn->commit();
        
        $response = [
            'success' => true,
            'message' => 'Referral link generated successfully!',
            'referral_code' => $referral_code,
            'invitee_name' => $invitee_name,
            'invitee_contact' => $invitee_contact
        ];
        
    } catch (Exception $e) {
        // --- Rollback on error ---
        $conn->rollback();
        $response['message'] = 'Database error: ' . $e->getMessage();
    }

} else {
    // Handle other actions if needed
    $response['message'] = 'Invalid request type or action.';
}

$conn->close();
echo json_encode($response);
exit;
?><?php require_once 'init.php'; // Handle AJAX requests here ?>
