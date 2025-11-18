<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, log_audit_action()

// -- SECURITY CHECK: ENSURE USER IS A PRIVILEGED USER --
if (!has_any_role(['admin', 'accounts', 'approver', 'platform_admin'])) {
    header("location: " . BASE_URL . "login.php"); // Root-relative path
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null; // The user performing the action
$report_id = $_GET['report_id'] ?? null;
$user_roles = $_SESSION['roles'] ?? []; 
$is_platform_admin = check_role('platform_admin');

if (!$company_id && !$is_platform_admin) { // Allow platform admin to proceed
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error"); // Root-relative path
    exit;
}
if (!$report_id) {
    header("location: " . BASE_URL . "admin/index.php"); // No report ID specified
    exit;
}

// --- FORM SUBMISSION LOGIC ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admin_comments = trim($_POST['admin_comments'] ?? '');
    $current_status = $report_details['status'] ?? ''; // Fetch status locally after main query

    // Re-fetch report details to ensure we have the context for POST actions
    $sql_report_check = "SELECT r.*, u.full_name, u.approver_id FROM expense_reports r JOIN users u ON r.user_id = u.id WHERE r.id = ?";
    if (!$is_platform_admin) { $sql_report_check .= " AND r.company_id = ?"; }
    
    if ($stmt_check = $conn->prepare($sql_report_check)) {
        if ($is_platform_admin) $stmt_check->bind_param("i", $report_id);
        else $stmt_check->bind_param("ii", $report_id, $company_id);
        if($stmt_check->execute()){ $report_details = $stmt_check->get_result()->fetch_assoc(); }
        $stmt_check->close();
    }
    
    // Check if report details were successfully fetched for action
    if (!$report_details) {
         $_SESSION['flash_error'] = "Action failed: Report not found or access denied.";
         header("location: " . BASE_URL . "admin/review.php?report_id={$report_id}&error=1"); exit;
    }
    $current_status = $report_details['status'];
    $report_user_id = $report_details['user_id'];
    $report_company_id = $report_details['company_id']; // Use the report's company ID for logging

    $update_success = false;
    $new_status = '';
    $approved_amount = 0.00; // Default to 0.00
    $log_message = '';
    $log_action = '';
    
    // Prepare for database update parameters
    $update_user_id = $user_id; // User performing the action
    $update_approved_amount = 0.00; // Final amount
    $update_admin_comments = $admin_comments;
    $update_approved_at = 'NULL'; // Default to NULL, will be set to NOW() on update
    $is_accounts_action = false;

    try {
        // --- Approver Actions (pending_approval -> pending_verification / rejected) ---
        if (has_any_role(['approver', 'admin', 'platform_admin']) && $current_status == 'pending_approval') {
            if (isset($_POST['approver_approve'])) {
                if ($report_user_id != $user_id && $report_details['approver_id'] != $user_id && !has_any_role(['admin', 'platform_admin'])) { throw new Exception("Permission denied. You are not the assigned approver."); }
                $new_status = 'pending_verification';
                $update_approved_amount = $report_details['total_amount']; // Set initially to total claimed for PENDING_VERIFICATION state
                $log_message = "Report approved by Approver/Admin. Sent to Accounts.";
                $log_action = 'report_approved_l1';
                $update_success = true;
            } elseif (isset($_POST['approver_reject'])) {
                 if(empty($admin_comments)) { throw new Exception("Rejection comments are required."); }
                if ($report_user_id != $user_id && $report_details['approver_id'] != $user_id && !has_any_role(['admin', 'platform_admin'])) { throw new Exception("Permission denied. You are not the assigned approver."); }
                $new_status = 'rejected';
                $update_approved_amount = 0.00;
                $log_message = "Report rejected by Approver/Admin. Reason: {$admin_comments}";
                $log_action = 'report_rejected_l1';
                $update_success = true;
            }
        }
        
        // --- Accounts Actions (pending_verification -> approved / rejected) ---
        elseif (has_any_role(['accounts', 'admin', 'platform_admin']) && $current_status == 'pending_verification') {
            $is_accounts_action = true;
            if (isset($_POST['accounts_approve'])) {
                $approved_amount = floatval($_POST['approved_amount'] ?? 0);
                if($approved_amount <= 0) { throw new Exception("Approved amount must be positive."); }
                $new_status = 'approved';
                $update_approved_amount = $approved_amount;
                $update_approved_at = 'NOW()';
                $log_message = "Report verified and approved by Accounts. Final amount: {$approved_amount}";
                $log_action = 'report_approved_l2';
                $update_success = true;
                
                // --- PETTY CASH DEDUCTION LOGIC ---
                $petty_cash_spent = 0;
                $sql_pc_spent = "SELECT SUM(amount) AS total FROM expense_items WHERE report_id = ? AND payment_method = 'Petty Cash'";
                $stmt_pc_spent = $conn->prepare($sql_pc_spent);
                $stmt_pc_spent->bind_param("i", $report_id); $stmt_pc_spent->execute();
                $petty_cash_spent = $stmt_pc_spent->get_result()->fetch_assoc()['total'] ?? 0;
                $stmt_pc_spent->close();
                
                if ($petty_cash_spent > 0) {
                    $conn->begin_transaction();
                    try {
                        // 1. Get wallet ID
                        $sql_wallet = "SELECT id FROM petty_cash_wallets WHERE user_id = ? AND company_id = ?";
                        $stmt_wallet = $conn->prepare($sql_wallet);
                        $stmt_wallet->bind_param("ii", $report_user_id, $report_company_id);
                        $stmt_wallet->execute();
                        $wallet_id = $stmt_wallet->get_result()->fetch_assoc()['id'] ?? null;
                        $stmt_wallet->close();

                        if (!$wallet_id) { throw new Exception("Petty Cash was used, but the user does not have an active wallet."); }

                        // 2. Deduct from wallet balance
                        $sql_deduct = "UPDATE petty_cash_wallets SET current_balance = current_balance - ? WHERE id = ?";
                        $stmt_deduct = $conn->prepare($sql_deduct);
                        $stmt_deduct->bind_param("di", $petty_cash_spent, $wallet_id);
                        $stmt_deduct->execute();
                        if($stmt_deduct->affected_rows == 0) { throw new Exception("Failed to deduct from wallet balance."); }
                        $stmt_deduct->close();

                        // 3. Log the expense transaction in petty_cash_transactions
                        $sql_log_tx = "INSERT INTO petty_cash_transactions (wallet_id, transaction_type, amount, description, related_expense_item_id, processed_by_user_id)
                                       SELECT ?, 'expense', amount, CONCAT('Expense Report Item: ', description), id, ? FROM expense_items WHERE report_id = ? AND payment_method = 'Petty Cash'";
                        $stmt_log_tx = $conn->prepare($sql_log_tx);
                        $stmt_log_tx->bind_param("iii", $wallet_id, $user_id, $report_id);
                        $stmt_log_tx->execute();
                        $stmt_log_tx->close();
                        
                        // 4. Update the expense report status and final amount
                        $sql_update_report_pc = "UPDATE expense_reports SET status = ?, approved_amount = ?, approved_at = NOW(), admin_comments = ?, accountant_id = ?, is_read = 0 WHERE id = ? AND status = 'pending_verification'";
                        $stmt_update_report_pc = $conn->prepare($sql_update_report_pc);
                        $stmt_update_report_pc->bind_param("sdsii", $new_status, $update_approved_amount, $update_admin_comments, $update_user_id, $report_id);
                        if(!$stmt_update_report_pc->execute() || $stmt_update_report_pc->affected_rows == 0) { throw new Exception("Failed to update final report status."); }
                        $stmt_update_report_pc->close();

                        $conn->commit(); // Only commit if ALL steps succeed
                        $log_message .= " (Petty Cash deduction successful: Rs. {$petty_cash_spent})";
                        $update_success = true; // Set success here, bypass general update block
                        
                        // Log and redirect immediately after successful PC transaction
                        log_audit_action($conn, $log_action, $log_message, $update_user_id, $report_company_id, 'report', $report_id);
                        header("location: " . BASE_URL . "admin/review.php?report_id={$report_id}&success=1");
                        exit;

                    } catch (Exception $e) {
                         $conn->rollback();
                         $log_message = "Petty Cash Deduction Failed. Error: " . $e->getMessage();
                         log_audit_action($conn, 'pc_deduction_failed', $log_message, $user_id, $report_company_id, 'report', $report_id);
                         throw new Exception("Approval failed: Petty Cash deduction error: " . $e->getMessage()); // Re-throw to main handler
                    }
                }
            } elseif (isset($_POST['accounts_reject'])) {
                 if(empty($admin_comments)) { throw new Exception("Rejection comments are required."); }
                $new_status = 'rejected';
                $update_approved_amount = 0.00;
                $update_approved_at = 'NOW()';
                $log_message = "Report rejected by Accounts. Reason: {$admin_comments}";
                $log_action = 'report_rejected_l2';
                $update_success = true;
            }
        }

        // --- Execute general report status update (if not handled by PC transaction logic) ---
        if ($update_success) {
            // Use dynamic query construction to set approved_amount and approved_at correctly
            $sql_update = "UPDATE expense_reports SET status = ?, admin_comments = ?, accountant_id = ?, is_read = 0";
            $update_params = [$new_status, $update_admin_comments, $update_user_id];
            $update_types = "ssi";

            if ($is_accounts_action && $new_status == 'approved') {
                 // Only set approved_amount and approved_at for successful L2 approval (non-PC path)
                $sql_update .= ", approved_amount = ?, approved_at = NOW()";
                $update_params[] = $update_approved_amount;
                $update_types .= "d";
            } elseif (!$is_accounts_action) {
                 // L1 approval or rejection. Set amount to claimed amount if we move forward or set to 0 if rejected
                 $sql_update .= ", approved_amount = ?, approved_at = NOW()";
                 // L1 approval sets the amount to the initial claimed amount. L1/L2 rejection sets it to 0.
                 $final_set_amount = ($new_status == 'rejected') ? 0.00 : $report_details['total_amount'];
                 $update_params[] = $final_set_amount;
                 $update_types .= "d";
            }
            
            $sql_update .= " WHERE id = ? AND status = ?";
            $update_params[] = $report_id;
            $update_params[] = $current_status;
            $update_types .= "is";

            $stmt_update = $conn->prepare($sql_update);
            if (!$stmt_update) { throw new Exception("DB Prepare Error: " . $conn->error); }
            
            // Need to use call_user_func_array for dynamic parameter binding
            $bind_params = array();
            $bind_params[] = &$update_types;
            for ($i = 0; $i < count($update_params); $i++) {
                $bind_params[] = &$update_params[$i]; 
            }
            if(!call_user_func_array(array($stmt_update, 'bind_param'), $bind_params)){
                 throw new Exception("DB Bind Error: " . $stmt_update->error);
            }
            
            if (!$stmt_update->execute() || $stmt_update->affected_rows == 0) {
                 throw new Exception("Failed to update report status in database.");
            }
            $stmt_update->close();
        }

        // --- Finalize and Redirect (if not already handled by PC transaction) ---
        if ($update_success) {
            log_audit_action($conn, $log_action, $log_message, $update_user_id, $report_company_id, 'report', $report_id);
            header("location: " . BASE_URL . "admin/review.php?report_id={$report_id}&success=1");
            exit;
        }

    } catch (Exception $e) {
         $_SESSION['flash_error'] = $e->getMessage();
         header("location: " . BASE_URL . "admin/review.php?report_id={$report_id}&error=1");
         exit;
    }
}


// --- REGULAR GET REQUEST LOGIC (COMPANY-AWARE) ---
$report_details = null;
$expense_items = [];
$error_message = '';
$success_message = '';

if(isset($_GET['success'])) { $success_message = "Action completed successfully."; }
if(isset($_GET['error'])) { 
    $error_message = $_SESSION['flash_error'] ?? "There was an error processing your request.";
    unset($_SESSION['flash_error']); // Clear the message after displaying it once
}

// Fetch report details (company-aware)
$sql_report = "SELECT r.*, u.full_name, u.email, u.department 
               FROM expense_reports r 
               JOIN users u ON r.user_id = u.id 
               WHERE r.id = ?";
if (!$is_platform_admin) {
    $sql_report .= " AND r.company_id = ?"; // Add company check
}

if ($stmt = $conn->prepare($sql_report)) {
    if($is_platform_admin) $stmt->bind_param("i", $report_id);
    else $stmt->bind_param("ii", $report_id, $company_id);
    
    if($stmt->execute()){ $report_details = $stmt->get_result()->fetch_assoc(); }
    $stmt->close();
} 

if (!$report_details) {
    header("location: " . BASE_URL . "admin/index.php?error=notfound"); exit;
}

// Fetch items AND their linked BTL proposal info
// FIX: Added LEFT JOIN to 'stores' table to correctly get 'btl_store' name.
$sql_items = "SELECT i.*, b.activity_type as btl_activity, s.store_name as btl_store
              FROM expense_items i
              LEFT JOIN btl_proposals b ON i.btl_proposal_id = b.id
              LEFT JOIN stores s ON b.store_id = s.id 
              WHERE i.report_id = ? 
              ORDER BY i.item_date ASC";
if ($stmt_items = $conn->prepare($sql_items)) { 
    $stmt_items->bind_param("i", $report_id);
    if($stmt_items->execute()){
        $result_items = $stmt_items->get_result();
        while ($row = $result_items->fetch_assoc()) { $expense_items[] = $row; }
    } else { $error_message = "Error fetching items: " . $stmt_items->error; }
     $stmt_items->close();
} else { $error_message = "DB Error preparing items: " . $conn->error; }


require_once '../includes/header.php';
?>

<div class="container mt-4">
    <a href="admin/index.php" class="btn btn-secondary btn-sm mb-3">Back to Dashboard</a>
     <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
     <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header"><h3>Review Expense Report</h3></div>
        <div class="card-body">
            <div class="row mb-4">
                <div class="col-md-6"><p><strong>Employee:</strong> <?php echo htmlspecialchars($report_details['full_name']); ?></p><p><strong>Department:</strong> <?php echo htmlspecialchars($report_details['department']); ?></p></div>
                <div class="col-md-6"><p><strong>Report Title:</strong> <?php echo htmlspecialchars($report_details['report_title']); ?></p><p><strong>Submitted On:</strong> <?php echo date("F j, Y", strtotime($report_details['submitted_at'])); ?></p></div>
            </div>

            <h5>Expense Items</h5>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>BTL Link</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                         <?php if (empty($expense_items)): ?>
                            <tr><td colspan="7" class="text-center text-muted">No expense items found for this report.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expense_items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['item_date']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td>Rs. <?php echo number_format($item['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($item['payment_method']); ?></td>
                                    <td>
                                        <?php if ($item['btl_proposal_id']): ?>
                                            <a href="btl/review.php?id=<?php echo $item['btl_proposal_id']; ?>" target="_blank" title="<?php echo htmlspecialchars($item['btl_store'] . ' - ' . $item['btl_activity']); ?>">
                                                <i data-lucide="link"></i> BTL-<?php echo $item['btl_proposal_id']; ?>
                                            </a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php if ($item['receipt_url']): ?><a href="<?php echo htmlspecialchars($item['receipt_url']); ?>" target="_blank" class="btn btn-outline-primary btn-sm">View</a><?php else: ?>N/A<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <tfoot class="table-light"><tr><td colspan="4" class="text-end"><strong>Total Claimed:</strong></td><td colspan="3"><strong>Rs. <?php echo number_format($report_details['total_amount'], 2); ?></strong></td></tr></tfoot>
                </table>
            </div>

            <hr class="mt-4">
            
            <?php // --- APPROVER'S ACTION BOX ---
            if (has_any_role(['approver', 'admin', 'platform_admin']) && $report_details['status'] == 'pending_approval'): ?>
            <div class="action-box my-4 p-3 border rounded bg-light">
                <h4>Approver Actions</h4>
                <div class="row">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <form action="admin/review.php?report_id=<?php echo $report_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to approve this report and send it to Accounts?');">
                            <button type="submit" name="approver_approve" class="btn btn-success w-100">Approve & Send to Accounts</button>
                        </form>
                    </div>
                    <div class="col-md-6">
                         <form action="admin/review.php?report_id=<?php echo $report_id; ?>" method="post">
                             <div class="mb-2"><label for="approver_comments" class="form-label small">Reason for Rejection (Required)</label><textarea class="form-control form-control-sm" id="approver_comments" name="admin_comments" rows="2" required></textarea></div>
                            <button type="submit" name="approver_reject" class="btn btn-danger w-100">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // --- ACCOUNTS' ACTION BOX ---
            if (has_any_role(['accounts', 'admin', 'platform_admin']) && $report_details['status'] == 'pending_verification'): ?>
            <div class="action-box my-4 p-3 border rounded bg-light">
                <h4>Accounts Verification</h4>
                <div class="row">
                    <div class="col-md-6 mb-2 mb-md-0">
                        <form action="admin/review.php?report_id=<?php echo $report_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to approve this for payout?');">
                            <div class="mb-2"><label for="approved_amount" class="form-label small">Final Approved Amount (Rs.)</label><input type="number" step="0.01" class="form-control" id="approved_amount" name="approved_amount" value="<?php echo $report_details['total_amount']; ?>" required></div>
                            <button type="submit" name="accounts_approve" class="btn btn-success w-100">Verify & Approve for Payout</button>
                        </form>
                    </div>
                    <div class="col-md-6">
                         <form action="admin/review.php?report_id=<?php echo $report_id; ?>" method="post">
                             <div class="mb-2"><label for="accounts_comments" class="form-label small">Reason for Rejection (Required)</label><textarea class="form-control form-control-sm" id="accounts_comments" name="admin_comments" rows="2" required></textarea></div>
                            <button type="submit" name="accounts_reject" class="btn btn-danger w-100">Reject</button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php // --- PROCESSED REPORT INFO ---
            if (!in_array($report_details['status'], ['pending_approval', 'pending_verification', 'draft'])): ?>
                <div class="mt-4">
                     <h4>Report Processed</h4>
                     <p>This report status is <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report_details['status']))); ?></strong>.</p>
                     <?php if(in_array($report_details['status'], ['approved', 'paid'])): ?><p><strong>Final Approved Amount:</strong> Rs. <?php echo number_format($report_details['approved_amount'], 2); ?></p><?php endif; ?>
                     <?php if($report_details['status'] == 'rejected' && !empty($report_details['admin_comments'])): ?><p><strong>Comments:</strong> <?php echo htmlspecialchars($report_details['admin_comments']); ?></p><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>


