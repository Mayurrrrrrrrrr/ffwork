<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
ob_start(); // Start output buffering
require_once '../includes/init.php'; // Provides $conn, log_audit_action()

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    if (has_any_role(['admin', 'accounts', 'approver'])) { header("location: " . BASE_URL . "admin/index.php"); }
    else { header("location: " . BASE_URL . "login.php"); }
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null; // The user submitting the report
if (!$company_id || !$user_id) {
    session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}

// -- INITIALIZE VARIABLES --
$report_id = $_GET['report_id'] ?? null;
$report_details = null;
$error_message = '';
$success_message = '';
$company_categories = []; $unassigned_receipts = []; $company_users = [];
$approved_btl_proposals = [];
$all_stores = []; // For stores list

// --- FETCH COMPANY DATA ---
// Fetch categories
$sql_categories = "SELECT category_name FROM expense_categories WHERE company_id = ? AND is_active = 1 ORDER BY category_name";
if($stmt_cat = $conn->prepare($sql_categories)){
    $stmt_cat->bind_param("i", $company_id);
    if($stmt_cat->execute()){
        $result_cat = $stmt_cat->get_result();
        while($row_cat = $result_cat->fetch_assoc()){ $company_categories[] = $row_cat['category_name']; }
    } else { error_log("Error fetching categories: ".$stmt_cat->error); $error_message .= " Error loading categories.";}
    $stmt_cat->close();
} else { error_log("Error preparing categories: ".$conn->error); $error_message .= " DB Error loading categories."; }
if(empty($company_categories)) { $company_categories = ['Miscellaneous']; }

// Fetch unassigned receipts
$sql_receipts = "SELECT id, receipt_url, notes, uploaded_at FROM unassigned_receipts WHERE user_id = ? AND company_id = ? AND assigned_item_id IS NULL ORDER BY uploaded_at DESC";
if ($stmt_receipts = $conn->prepare($sql_receipts)) {
    $stmt_receipts->bind_param("ii", $user_id, $company_id);
    if ($stmt_receipts->execute()) {
        $result_receipts = $stmt_receipts->get_result();
        while ($row_receipt = $result_receipts->fetch_assoc()) { $unassigned_receipts[] = $row_receipt; }
    } else { error_log("DB Error fetching unassigned receipts: " . $stmt_receipts->error); }
    $stmt_receipts->close();
}

// Fetch company users for splitting
$sql_users = "SELECT id, full_name FROM users WHERE company_id = ? AND id != ? ORDER BY full_name";
if($stmt_users = $conn->prepare($sql_users)){
    $stmt_users->bind_param("ii", $company_id, $user_id);
    if($stmt_users->execute()){
        $result_users = $stmt_users->get_result();
        while($row_user = $result_users->fetch_assoc()){ $company_users[] = $row_user; }
    } else { error_log("Error fetching company users: ".$stmt_users->error); }
    $stmt_users->close();
}

// Fetch user's approved BTL proposals
$sql_btl = "SELECT b.id, b.activity_type, b.proposed_budget, s.store_name 
            FROM btl_proposals b
            LEFT JOIN stores s ON b.store_id = s.id
            WHERE b.company_id = ? AND b.status = 'Approved'
            ORDER BY b.proposal_date DESC";
if($stmt_btl = $conn->prepare($sql_btl)){
    $stmt_btl->bind_param("i", $company_id);
    if($stmt_btl->execute()){
        $result_btl = $stmt_btl->get_result();
        while($row_btl = $result_btl->fetch_assoc()){ $approved_btl_proposals[] = $row_btl; }
    } else { error_log("Error fetching BTL proposals: ".$stmt_btl->error); }
    $stmt_btl->close();
}

// **FIX 1: Fetch stores this user is assigned to (or all if admin)**
$sql_stores = "SELECT s.id, s.store_name FROM stores s
               JOIN users u ON s.id = u.store_id
               WHERE u.id = ? AND s.company_id = ? AND s.is_active = 1 
               UNION
               SELECT s.id, s.store_name FROM stores s
               WHERE ? = 1 AND s.company_id = ? AND s.is_active = 1
               ORDER BY store_name";
if($stmt_stores = $conn->prepare($sql_stores)){
    $is_admin_check = (int)has_any_role(['admin', 'platform_admin']);
    $stmt_stores->bind_param("iiii", $user_id, $company_id, $is_admin_check, $company_id);
    if($stmt_stores->execute()){
        $result_stores = $stmt_stores->get_result();
        while($row_store = $result_stores->fetch_assoc()){ $all_stores[] = $row_store; }
    } else { $error_message = "Error fetching stores list: " . $stmt_stores->error; }
    $stmt_stores->close();
} else { $error_message = "Error preparing stores list: " . $conn->error; }


// --- FORM SUBMISSION LOGIC ---

// 1. Handle New Report Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_report'])) {
    $report_title = trim($_POST['report_title']); 
    $report_type = $_POST['report_type']; 
    $start_date = $_POST['start_date']; 
    $end_date = $_POST['end_date'];
    $store_id = $_POST['store_id']; // NEW Store ID

    if (empty($report_title) || empty($report_type) || empty($start_date) || empty($end_date) || empty($store_id)) { 
        $error_message = "All fields (Title, Type, Dates, and Store/Cost Center) are required."; 
    } else {
        // Verify store_id belongs to this user/company
        $valid_store = false;
        foreach($all_stores as $store) { if($store['id'] == $store_id) $valid_store = true; }
        
        if(!$valid_store) {
            $error_message = "Invalid Store / Cost Center selected.";
        } else {
            $sql = "INSERT INTO expense_reports (company_id, user_id, store_id, report_type, report_title, travel_start_date, travel_end_date, status, submitted_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', NOW())";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iiissss", $company_id, $user_id, $store_id, $report_type, $report_title, $start_date, $end_date);
                if ($stmt->execute()) {
                    $new_report_id = $conn->insert_id;
                    log_audit_action($conn, 'report_created', "Created draft report '{$report_title}' for store ID {$store_id}", $user_id, $company_id, 'report', $new_report_id);
                    header("location: " . BASE_URL . "employee/submit.php?report_id=" . $new_report_id); exit;
                } else { $error_message = "Error creating report: " . $stmt->error; log_audit_action($conn, 'report_create_failed', "Failed create report. Error: ".$stmt->error, $user_id, $company_id); }
                $stmt->close();
            } else { $error_message = "DB prepare error: " . $conn->error; }
        }
    }
}

// 2. Handle Adding a New Expense Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item']) && $report_id) {
    $item_date = $_POST['item_date']; $category = trim($_POST['category']); $description = trim($_POST['description']); $amount = $_POST['amount']; $payment_method = $_POST['payment_method'];
    $btl_proposal_id = empty($_POST['btl_proposal_id']) ? null : (int)$_POST['btl_proposal_id']; 
    $unassigned_receipt_id = empty($_POST['unassigned_receipt_id']) ? null : (int)$_POST['unassigned_receipt_id'];
    $is_split = isset($_POST['is_split']) && $_POST['is_split'] == '1';
    $split_users = $_POST['split_user'] ?? [];
    $split_amounts = $_POST['split_amount'] ?? [];

    if (empty($item_date) || empty($category) || empty($description) || !is_numeric($amount) || $amount <= 0) {
        $error_message = "Please fill in all item fields (Date, Category, Description, Amount).";
    }
    
    if (empty($error_message)) {
        if($stmt_check = $conn->prepare("SELECT id FROM expense_reports WHERE id = ? AND company_id = ? AND user_id = ? AND status = 'draft'")){
             $stmt_check->bind_param("iii", $report_id, $company_id, $user_id); $stmt_check->execute(); $stmt_check->store_result();
             if($stmt_check->num_rows == 1){
                 $conn->begin_transaction();
                 try {
                    // 1. Determine Receipt Path
                    $receipt_path = null;
                    if ($unassigned_receipt_id) {
                        // Use receipt from wallet
                        $sql_get_receipt_path = "SELECT receipt_url FROM unassigned_receipts WHERE id = ? AND user_id = ? AND company_id = ? AND assigned_item_id IS NULL";
                        $stmt_path = $conn->prepare($sql_get_receipt_path);
                        $stmt_path->bind_param("iii", $unassigned_receipt_id, $user_id, $company_id);
                        $stmt_path->execute();
                        $receipt_path = $stmt_path->get_result()->fetch_assoc()['receipt_url'] ?? null;
                        $stmt_path->close();
                        if (!$receipt_path) throw new Exception("Selected receipt not found or already assigned.");
                    } elseif (isset($_FILES['receipt']) && $_FILES['receipt']['error'] == UPLOAD_ERR_OK) {
                        // Handle new file upload
                        $target_dir = dirname(__DIR__) . '/uploads/'; 
                        // ** PARSE ERROR FIX: Added missing parenthesis after true **
                        if (!is_dir($target_dir)) { if (!mkdir($target_dir, 0755, true)) { throw new Exception("Failed to create upload directory."); } } 
                        if (!is_writable($target_dir)) { throw new Exception("Upload directory is not writable."); }

                        $file_info = $_FILES['receipt'];
                        $original_filename = basename($file_info["name"]); 
                        $file_extension = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
                        $safe_filename = "receipt_c" . $company_id . "_r" . $report_id . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
                        $target_file = $target_dir . $safe_filename; 
                        
                        $allowed_types = ['jpg', 'jpeg', 'png', 'pdf'];
                        $max_file_size = 5 * 1024 * 1024; // 5MB

                        if (!in_array($file_extension, $allowed_types)) { throw new Exception("Invalid file type."); }
                        if ($file_info['size'] > $max_file_size) { throw new Exception("File too large (max 5MB)."); }
                        if (!move_uploaded_file($file_info["tmp_name"], $target_file)) { throw new Exception("Failed to save uploaded file."); }
                        
                        $receipt_path = "uploads/" . $safe_filename; 
                    }

                    // 2. Insert the main expense item (with btl_proposal_id)
                    $sql_insert_item = "INSERT INTO expense_items (report_id, item_date, category, description, amount, payment_method, receipt_url, btl_proposal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_item = $conn->prepare($sql_insert_item);
                    $stmt_item->bind_param("isssdssi", $report_id, $item_date, $category, $description, $amount, $payment_method, $receipt_path, $btl_proposal_id);
                    if (!$stmt_item->execute()) { throw new Exception("Error adding item: " . $stmt_item->error); }
                    $new_item_id = $conn->insert_id; $stmt_item->close();
                    log_audit_action($conn, 'expense_item_added', "Added '{$description}' (Rs. {$amount})", $user_id, $company_id, 'item', $new_item_id);

                    // 3. Assign unassigned receipt if one was used
                    if ($unassigned_receipt_id && $receipt_path) {
                        $sql_assign_receipt = "UPDATE unassigned_receipts SET assigned_item_id = ? WHERE id = ?";
                        $stmt_assign = $conn->prepare($sql_assign_receipt);
                        $stmt_assign->bind_param("ii", $new_item_id, $unassigned_receipt_id);
                        if (!$stmt_assign->execute()) throw new Exception("Failed to assign receipt from wallet.");
                        $stmt_assign->close();
                    }

                    // 4. Insert splits (if any)
                    if ($is_split && !empty($split_users)) {
                        $sql_split = "INSERT INTO expense_splits (item_id, user_id, company_id, split_amount) VALUES (?, ?, ?, ?)";
                        $stmt_split = $conn->prepare($sql_split);
                        for ($i = 0; $i < count($split_users); $i++) {
                            if (!empty($split_users[$i]) && is_numeric($split_amounts[$i]) && $split_amounts[$i] > 0) {
                                $stmt_split->bind_param("iiid", $new_item_id, $split_users[$i], $company_id, $split_amounts[$i]);
                                if (!$stmt_split->execute()) { throw new Exception("Failed to save split item: " . $stmt_split->error); }
                            }
                        }
                        $stmt_split->close();
                    }

                    $conn->commit();
                    $success_message = "Expense item added successfully.";
                    
                    // Refetch unassigned receipts
                    $unassigned_receipts = []; 
                    if ($stmt_receipts_refetch = $conn->prepare($sql_receipts)) {
                        $stmt_receipts_refetch->bind_param("ii", $user_id, $company_id);
                        if ($stmt_receipts_refetch->execute()) {
                            $result_receipts = $stmt_receipts_refetch->get_result();
                            while ($row_receipt = $result_receipts->fetch_assoc()) { $unassigned_receipts[] = $row_receipt; }
                        }
                        $stmt_receipts_refetch->close();
                    }

                } catch (Exception $e) { 
                    $conn->rollback(); 
                    $error_message = $e->getMessage(); 
                    if (isset($receipt_path) && isset($target_file) && file_exists($target_file) && !$unassigned_receipt_id) { unlink($target_file); } 
                }
             } else { $error_message = "Invalid report, already submitted, or permission denied."; }
             $stmt_check->close();
        } else { $error_message = "DB error checking report."; }
    } 
} 

// **NEW: Handle Deleting an Expense Item**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item']) && $report_id) {
    $item_id_to_delete = $_POST['delete_item_id'];
    
    // Check if report is in draft and belongs to user
    $sql_check_report = "SELECT id FROM expense_reports WHERE id = ? AND company_id = ? AND user_id = ? AND status = 'draft'";
    if ($stmt_check = $conn->prepare($sql_check_report)) {
        $stmt_check->bind_param("iii", $report_id, $company_id, $user_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows == 1) {
            // Report is valid for editing
            $conn->begin_transaction();
            try {
                // Find any unassigned_receipt linked to this item
                $sql_get_receipt = "SELECT id, receipt_url FROM unassigned_receipts WHERE assigned_item_id = ? AND company_id = ? AND user_id = ?";
                $stmt_get_receipt = $conn->prepare($sql_get_receipt);
                $stmt_get_receipt->bind_param("iii", $item_id_to_delete, $company_id, $user_id);
                $stmt_get_receipt->execute();
                $receipt_data = $stmt_get_receipt->get_result()->fetch_assoc();
                $stmt_get_receipt->close();
                
                // If a receipt was linked, un-assign it
                if ($receipt_data) {
                    $sql_unassign = "UPDATE unassigned_receipts SET assigned_item_id = NULL WHERE id = ?";
                    $stmt_unassign = $conn->prepare($sql_unassign);
                    $stmt_unassign->bind_param("i", $receipt_data['id']);
                    if (!$stmt_unassign->execute()) throw new Exception("Failed to un-assign receipt.");
                    $stmt_unassign->close();
                }
                
                // Delete splits manually
                $sql_delete_splits = "DELETE FROM expense_splits WHERE item_id = ?";
                $stmt_del_splits = $conn->prepare($sql_delete_splits);
                $stmt_del_splits->bind_param("i", $item_id_to_delete);
                if (!$stmt_del_splits->execute()) throw new Exception("Failed to delete splits.");
                $stmt_del_splits->close();
                
                // Now delete the item
                $sql_delete_item = "DELETE FROM expense_items WHERE id = ? AND report_id = ?";
                $stmt_del_item = $conn->prepare($sql_delete_item);
                $stmt_del_item->bind_param("ii", $item_id_to_delete, $report_id);
                if (!$stmt_del_item->execute()) throw new Exception("Failed to delete item.");
                $stmt_del_item->close();
                
                $conn->commit();
                $success_message = "Item deleted successfully.";
                log_audit_action($conn, 'expense_item_deleted', "Deleted item ID: {$item_id_to_delete}", $user_id, $company_id, 'item', $item_id_to_delete);

            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error deleting item: " . $e->getMessage();
            }
        } else {
            $error_message = "Cannot delete item. Report is not a draft or you do not have permission.";
        }
        $stmt_check->close();
    } else {
        $error_message = "Database error checking report status.";
    }
    
    // After deletion, we need to refetch the unassigned receipts list
    $unassigned_receipts = []; // Clear old list
    if ($stmt_receipts_refetch = $conn->prepare($sql_receipts)) {
        $stmt_receipts_refetch->bind_param("ii", $user_id, $company_id);
        if ($stmt_receipts_refetch->execute()) {
            $result_receipts = $stmt_receipts_refetch->get_result();
            while ($row_receipt = $result_receipts->fetch_assoc()) { $unassigned_receipts[] = $row_receipt; }
        }
        $stmt_receipts_refetch->close();
    }
}


// 3. Handle Final Report Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_final']) && $report_id) {
    // First, check if there are any items
    $sql_check_items = "SELECT COUNT(*) as item_count FROM expense_items WHERE report_id = ?";
    $stmt_check_items = $conn->prepare($sql_check_items);
    $stmt_check_items->bind_param("i", $report_id);
    $stmt_check_items->execute();
    $item_count = $stmt_check_items->get_result()->fetch_assoc()['item_count'] ?? 0;
    $stmt_check_items->close();

    if ($item_count == 0) {
        $error_message = "Cannot submit an empty report. Please add at least one expense item or delete the draft report.";
    } else {
        // Calculate total amount
        $sql_total = "SELECT SUM(amount) AS total FROM expense_items WHERE report_id = ?";
        $stmt_total = $conn->prepare($sql_total);
        $stmt_total->bind_param("i", $report_id);
        $stmt_total->execute();
        $total_amount_final = $stmt_total->get_result()->fetch_assoc()['total'] ?? 0;
        $stmt_total->close();
        
        // Find the approver
        $approver_id = null;
        $sql_approver = "SELECT approver_id FROM users WHERE id = ?";
        $stmt_approver = $conn->prepare($sql_approver);
        $stmt_approver->bind_param("i", $user_id);
        $stmt_approver->execute();
        $approver_id = $stmt_approver->get_result()->fetch_assoc()['approver_id'] ?? null;
        $stmt_approver->close();
        
        if (!$approver_id) {
            $error_message = "Error: You are not assigned to an approver. Please contact your administrator.";
        } else {
            // Update report
            $sql_submit = "UPDATE expense_reports SET status = 'pending_approval', total_amount = ?, submitted_at = NOW(), approver_id = ?, is_read = 0 
                           WHERE id = ? AND user_id = ? AND company_id = ? AND status = 'draft'";
            if ($stmt_submit = $conn->prepare($sql_submit)) {
                $stmt_submit->bind_param("diiii", $total_amount_final, $approver_id, $report_id, $user_id, $company_id);
                if ($stmt_submit->execute() && $stmt_submit->affected_rows > 0) {
                    log_audit_action($conn, 'report_submitted', "Submitted report #{$report_id} for approval. Total: {$total_amount_final}", $user_id, $company_id, 'report', $report_id);
                    header("location: " . BASE_URL . "employee/index.php?success=submitted"); exit;
                } else { $error_message = "Error submitting report: " . $stmt_submit->error; }
                $stmt_submit->close();
            } else { $error_message = "DB prepare error during submission: " . $conn->error; }
        }
    }
}


// --- DATA FETCHING for displaying the page ---
$expense_items = []; $total_amount = 0; $can_edit_report = false;
if ($report_id) {
    // Fetch report details
    $sql_report = "SELECT r.id, r.report_title, r.travel_start_date, r.travel_end_date, r.status, s.store_name 
                   FROM expense_reports r 
                   LEFT JOIN stores s ON r.store_id = s.id
                   WHERE r.id = ? AND r.user_id = ? AND r.company_id = ?";
    if ($stmt = $conn->prepare($sql_report)) {
        $stmt->bind_param("iii", $report_id, $user_id, $company_id);
        if($stmt->execute()){ $report_details = $stmt->get_result()->fetch_assoc(); } 
        else { if(empty($error_message)) $error_message = "Error retrieving report data."; }
        $stmt->close();
    } else { if(empty($error_message)) $error_message = "Database error retrieving report."; }

    // Check status if found
    if ($report_details) {
        if ($report_details['status'] == 'draft') {
            $can_edit_report = true;
            // **FIX 2: Fetch items, including split status**
            $sql_items = "SELECT i.*, b.activity_type as btl_activity, s.store_name as btl_store,
                          (SELECT COUNT(*) FROM expense_splits es WHERE es.item_id = i.id) > 0 AS is_split
                          FROM expense_items i
                          LEFT JOIN btl_proposals b ON i.btl_proposal_id = b.id
                          LEFT JOIN stores s ON b.store_id = s.id
                          WHERE i.report_id = ? 
                          ORDER BY i.id DESC";
            if ($stmt_items = $conn->prepare($sql_items)) {
                 $stmt_items->bind_param("i", $report_id);
                 if($stmt_items->execute()){
                     $result_items = $stmt_items->get_result();
                     while ($row = $result_items->fetch_assoc()) { $expense_items[] = $row; $total_amount += $row['amount']; }
                 } else { if(empty($error_message)) $error_message = "Error fetching items."; }
                 $stmt_items->close();
            } else { if(empty($error_message)) $error_message = "DB Error fetching items."; }
        } else {
             if(empty($error_message)) $error_message = "Report (ID: " . htmlspecialchars($report_id) . ") status '" . htmlspecialchars($report_details['status']) . "' cannot be edited.";
            $report_details = null; // Prevent showing edit form
        }
    } else if (empty($error_message)) {
         $error_message = "Report not found, invalid, or does not belong to your company.";
    }
}


// Include header
require_once '../includes/header.php';
?>

<div class="container mt-4">
    <a href="employee/index.php" class="btn btn-secondary btn-sm mb-3">Back to Dashboard</a>
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <?php if ($report_id && $report_details && $can_edit_report): ?>
        
        <h3>Add Items to Report: "<?php echo htmlspecialchars($report_details['report_title']); ?>"</h3>
        <p>For Store/Cost Center: <strong><?php echo htmlspecialchars($report_details['store_name'] ?? 'N/A'); ?></strong> | Period: <?php echo htmlspecialchars($report_details['travel_start_date']); ?> to <?php echo htmlspecialchars($report_details['travel_end_date']); ?></p>
        
        <div class="card mb-4">
            <div class="card-header">Add New Expense Item</div>
            <div class="card-body">
                <form action="employee/submit.php?report_id=<?php echo $report_id; ?>" method="post" enctype="multipart/form-data" id="add-item-form">
                    <input type="hidden" name="is_split" id="is_split_input" value="0">
                    <div class="row g-3">
                         <div class="col-md-6 col-lg-3"><label class="form-label">Date</label><input type="date" class="form-control" name="item_date" required></div>
                         <div class="col-md-6 col-lg-3"><label class="form-label">Category</label><select class="form-select" name="category" required><?php foreach($company_categories as $cat): ?><option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option><?php endforeach; ?></select></div>
                         <div class="col-md-12 col-lg-6"><label class="form-label">Description</label><input type="text" class="form-control" name="description" required></div>
                         <div class="col-md-6 col-lg-3"><label class="form-label">Amount (Total)</label><input type="number" step="0.01" min="0.01" class="form-control" id="item_amount_total" name="amount" required></div>
                         <div class="col-md-6 col-lg-3"><label class="form-label">Payment Method</label><select class="form-select" name="payment_method" required><option value="Personal Card">Personal Card</option><option value="Cash">Cash</option><option value="Corp Card">Corp Card</option><option value="Petty Cash">Petty Cash</option></select></div>
                         <div class="col-md-6">
                            <label class="form-label">Attach Receipt</label>
                            <select class="form-select mb-2" name="unassigned_receipt_id">
                                <option value="">-- OR Select from Wallet --</option>
                                <?php foreach ($unassigned_receipts as $receipt): ?>
                                    <option value="<?php echo $receipt['id']; ?>">
                                        Saved: <?php echo date("M j, H:i", strtotime($receipt['uploaded_at'])); ?> <?php echo $receipt['notes'] ? '('.htmlspecialchars($receipt['notes']).')' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="file" class="form-control" name="receipt" accept="image/*,application/pdf">
                            <div class="form-text">Select saved receipt OR upload new (max 5MB).</div>
                         </div>
                         <!-- NEW BTL DROPDOWN -->
                         <div class="col-md-6">
                            <label for="btl_proposal_id" class="form-label">Link to BTL Budget (Optional)</label>
                            <select class="form-select" id="btl_proposal_id" name="btl_proposal_id">
                                <option value="">-- None --</option>
                                <?php foreach($approved_btl_proposals as $proposal): ?>
                                <option value="<?php echo $proposal['id']; ?>">
                                    <?php echo htmlspecialchars($proposal['store_name'] . ' / ' . $proposal['activity_type'] . ' (Budget: Rs.' . number_format($proposal['proposed_budget'], 2) . ')'); ?>
                                </option>
                                <?php endforeach; ?>
                                <?php if(empty($approved_btl_proposals)): ?><option value="" disabled>No approved BTL proposals found.</option><?php endif; ?>
                            </select>
                            <div class="form-text">Link this expense to a pre-approved BTL activity.</div>
                         </div>
                    </div>
                    <div class="mt-3"><button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleSplitSection()">Split Expense</button></div>
                    
                    <!-- Split Section -->
                    <div id="split-section" style="display: none;" class="mt-3 p-3 border rounded bg-light">
                        <h5>Split Expense With</h5>
                        <p class="text-muted small">Select colleagues to split this item with. You will pay the full amount and they will be assigned the split amount as an expense.</p>
                        <div id="split-rows-container">
                            <div class="row g-2 mb-2 split-row">
                                <div class="col-7">
                                    <select name="split_user[]" class="form-select">
                                        <option value="">-- Select Colleague --</option>
                                        <?php foreach($company_users as $c_user): ?>
                                            <option value="<?php echo $c_user['id']; ?>"><?php echo htmlspecialchars($c_user['full_name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-4">
                                    <input type="number" name="split_amount[]" class="form-control split-amount-input" placeholder="Amount" step="0.01" min="0.01">
                                </div>
                                <div class="col-1">
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeSplitRow(this)">X</button>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addSplitRow()">+ Add Person</button>
                        <div class="mt-2">
                            <strong>Total Split: Rs. <span id="split-total">0.00</span></strong> | 
                            <strong>Your Share: Rs. <span id="split-your-share">0.00</span></strong>
                        </div>
                    </div>
                    
                    <button type="submit" name="add_item" class="btn btn-primary mt-3">Add Item</button>
                </form>
            </div>
        </div>

        <!-- **START OF TRUNCATED SECTION** -->
        <div class="card">
             <div class="card-header d-flex justify-content-between align-items-center">
                <span>Expense Items Added</span>
                <strong>Total: Rs. <span id="total-amount"><?php echo number_format($total_amount, 2); ?></span></strong>
            </div>
             <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Amount</th>
                                <th>BTL Link</th>
                                <th>Receipt</th>
                                <th>Split?</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($expense_items)): ?>
                                <tr><td colspan="8" class="text-center text-muted">No items added yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($expense_items as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['item_date']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                        <td>Rs. <?php echo number_format($item['amount'], 2); ?></td>
                                        <td>
                                            <?php if ($item['btl_proposal_id']): ?>
                                                <a href="btl/review.php?id=<?php echo $item['btl_proposal_id']; ?>" target="_blank" title="<?php echo htmlspecialchars($item['btl_store'] . ' - ' . $item['btl_activity']); ?>">
                                                    <i data-lucide="link" style="width: 16px; height: 16px;"></i> BTL
                                                </a>
                                            <?php else: echo 'N/A'; endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($item['receipt_url']): ?>
                                                <a href="<?php echo htmlspecialchars($item['receipt_url']); ?>" target="_blank">View</a>
                                            <?php else: echo 'N/A'; endif; ?>
                                        </td>
                                        <td><?php echo $item['is_split'] ? 'Yes' : 'No'; ?></td>
                                        <td>
                                            <form action="employee/submit.php?report_id=<?php echo $report_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to delete this item?');">
                                                <input type="hidden" name="delete_item_id" value="<?php echo $item['id']; ?>">
                                                <button type="submit" name="delete_item" class="btn btn-danger btn-sm p-1">
                                                    <i data-lucide="trash-2" style="width: 16px; height: 16px;"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                 <?php if(!empty($expense_items)): ?>
                    <form action="employee/submit.php?report_id=<?php echo $report_id; ?>" method="post" class="mt-3 text-end" onsubmit="return confirm('Are you sure you want to submit this report? You will not be able to edit it after submission.');">
                        <button type="submit" name="submit_final" class="btn btn-success btn-lg">Submit Final Report (Total: Rs. <?php echo number_format($total_amount, 2); ?>)</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <!-- **END OF TRUNCATED SECTION** -->

    <?php elseif (!$report_id && empty($error_message)): ?>
        
        <!-- Create Report Form -->
        <h3>Create New Expense Report</h3>
        <div class="card">
            <div class="card-body">
                <form action="employee/submit.php" method="post">
                    <div class="mb-3">
                        <label for="store_id" class="form-label">Store / Cost Center*</label>
                        <select class="form-select" id="store_id" name="store_id" required>
                            <option value="">-- Select Store --</option>
                            <?php foreach ($all_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                            <?php endforeach; ?>
                            <?php if(empty($all_stores)): ?><option value="" disabled>No stores assigned to you. Contact Admin.</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="mb-3"><label class="form-label">Report Title / Purpose*</label><input type="text" class="form-control" name="report_title" required></div>
                    <div class="mb-3"><label class="form-label">Report Type*</label><select class="form-select" name="report_type" required><option value="Travel">Travel</option><option value="Store Petty Cash">Store Petty Cash</option><option value="Office Supplies">Office Supplies</option><option value="Miscellaneous">Miscellaneous</option></select></div>
                    <div class="row">
                        <div class="col-md-6 mb-3"><label class="form-label">Expense Period Start Date*</label><input type="date" class="form-control" name="start_date" required></div>
                        <div class="col-md-6 mb-3"><label class="form-label">Expense Period End Date*</label><input type="date" class="form-control" name="end_date" required></div>
                    </div>
                    <button type="submit" name="create_report" class="btn btn-primary">Create Report & Add Items</button>
                </form>
            </div>
        </div>

    <?php endif; ?> 

</div>

<script>
    // --- SPLIT FUNCTIONALITY ---
    const companyUsers = <?php echo json_encode($company_users); ?>;
    
    function toggleSplitSection() {
        const section = document.getElementById('split-section');
        const isSplitInput = document.getElementById('is_split_input');
        if (section.style.display === 'none') {
            section.style.display = 'block';
            isSplitInput.value = '1';
        } else {
            section.style.display = 'none';
            isSplitInput.value = '0';
        }
    }

    function addSplitRow() {
        const container = document.getElementById('split-rows-container');
        const newRow = document.createElement('div');
        newRow.className = 'row g-2 mb-2 split-row';
        
        let options = '<option value="">-- Select Colleague --</option>';
        companyUsers.forEach(user => {
            options += `<option value="${user.id}">${escapeHTML(user.full_name)}</option>`;
        });

        newRow.innerHTML = `
            <div class="col-7">
                <select name="split_user[]" class="form-select">${options}</select>
            </div>
            <div class="col-4">
                <input type="number" name="split_amount[]" class="form-control split-amount-input" placeholder="Amount" step="0.01" min="0.01">
            </div>
            <div class="col-1">
                <button type="button" class="btn btn-danger btn-sm" onclick="removeSplitRow(this)">X</button>
            </div>
        `;
        container.appendChild(newRow);
        attachSplitAmountListeners();
    }

    function removeSplitRow(button) {
        button.closest('.split-row').remove();
        calculateSplitTotals();
    }

    function calculateSplitTotals() {
        let totalSplit = 0;
        const totalAmount = parseFloat(document.getElementById('item_amount_total').value) || 0;
        
        document.querySelectorAll('.split-amount-input').forEach(input => {
            totalSplit += parseFloat(input.value) || 0;
        });

        document.getElementById('split-total').textContent = totalSplit.toFixed(2);
        document.getElementById('split-your-share').textContent = (totalAmount - totalSplit).toFixed(2);
    }
    
    function attachSplitAmountListeners() {
         document.querySelectorAll('.split-amount-input').forEach(input => {
            input.removeEventListener('input', calculateSplitTotals); // Remove old listener
            input.addEventListener('input', calculateSplitTotals); // Add new one
        });
    }

    document.getElementById('item_amount_total').addEventListener('input', calculateSplitTotals);
    attachSplitAmountListeners(); // Attach to initial row

    function escapeHTML(str) {
        return str.replace(/[&<>"']/g, function(m) {
            return {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            }[m];
        });
    }
</script>

<?php
if (isset($conn)) { $conn->close(); }
require_once '../includes/footer.php';
ob_end_flush(); // Send buffered output
?>


