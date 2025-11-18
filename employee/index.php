<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    // If user is logged in but not an employee, redirect to admin dash
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
         header("location: " . BASE_URL . "admin/index.php");
    } else {
    // Otherwise, redirect to login
        header("location: " . BASE_URL . "login.php");
    }
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null;

if (!$company_id || !$user_id) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error");
    exit;
}

// --- HANDLE QUICK RECEIPT SAVE RESPONSE ---
$quick_save_success = $_GET['quick_save_status'] ?? null;
$quick_save_error = $_GET['quick_save_error'] ?? null;

// --- DATA FETCHING ---
// 1. Fetch Submitted Reports (COMPANY-AWARE)
$my_reports = [];
$sql_reports = "SELECT id, report_title, submitted_at, total_amount, approved_amount, status, admin_comments, is_read
                FROM expense_reports
                WHERE user_id = ? AND company_id = ?
                ORDER BY CASE WHEN status = 'draft' THEN 1 ELSE 0 END, 
                         CASE WHEN status = 'rejected' AND is_read = 0 THEN 2 ELSE 0 END DESC,
                         submitted_at DESC, id DESC";
if ($stmt_reports = $conn->prepare($sql_reports)) {
    $stmt_reports->bind_param("ii", $user_id, $company_id);
    if ($stmt_reports->execute()) {
        $result_reports = $stmt_reports->get_result();
        while ($row = $result_reports->fetch_assoc()) {
            $my_reports[] = $row;
        }
    } else { error_log("DB Error fetching employee reports: " . $stmt_reports->error); }
    $stmt_reports->close();
} else { error_log("DB Error preparing employee reports statement: " . $conn->error); }


// 2. Fetch Unassigned Receipts (New)
$unassigned_receipts = [];
$sql_receipts = "SELECT id, receipt_url, notes, uploaded_at
                 FROM unassigned_receipts
                 WHERE user_id = ? AND company_id = ? AND assigned_item_id IS NULL
                 ORDER BY uploaded_at DESC";
if ($stmt_receipts = $conn->prepare($sql_receipts)) {
    $stmt_receipts->bind_param("ii", $user_id, $company_id);
    if ($stmt_receipts->execute()) {
        $result_receipts = $stmt_receipts->get_result();
        while ($row_receipt = $result_receipts->fetch_assoc()) {
            $unassigned_receipts[] = $row_receipt;
        }
    } else { error_log("DB Error fetching unassigned receipts: " . $stmt_receipts->error); }
    $stmt_receipts->close();
} else { error_log("DB Error preparing unassigned receipts: " . $conn->error); }

// 3. Fetch Petty Cash Balance, Pending Total, and Approver Name
$petty_cash_balance = null;
$approver_name = "Not Assigned";
$pending_petty_cash_total = 0.00;

// Fetch wallet balance and approver name in one query
$sql_info = "SELECT 
               pcw.current_balance,
               a.full_name AS approver_name
             FROM users u
             LEFT JOIN petty_cash_wallets pcw ON u.id = pcw.user_id AND u.company_id = pcw.company_id
             LEFT JOIN users a ON u.approver_id = a.id AND u.company_id = a.company_id
             WHERE u.id = ? AND u.company_id = ?";
if ($stmt_info = $conn->prepare($sql_info)) {
    $stmt_info->bind_param("ii", $user_id, $company_id);
    if ($stmt_info->execute()) {
        $result_info = $stmt_info->get_result();
        if ($row_info = $result_info->fetch_assoc()) {
            $petty_cash_balance = $row_info['current_balance']; // Will be null if no wallet
            if ($row_info['approver_name']) {
                $approver_name = $row_info['approver_name'];
            }
        }
    } else { error_log("DB Error fetching user info: " . $stmt_info->error); }
    $stmt_info->close();
} else { error_log("DB Error preparing user info: " . $conn->error); }

// Fetch pending petty cash total (submitted but not approved/paid)
$sql_pc_pending = "SELECT SUM(i.amount) as pending_total 
                   FROM expense_items i
                   JOIN expense_reports r ON i.report_id = r.id
                   WHERE r.user_id = ? AND r.company_id = ? 
                   AND i.payment_method = 'Petty Cash' 
                   AND r.status IN ('pending_approval', 'pending_verification')";
if ($stmt_pc_pending = $conn->prepare($sql_pc_pending)) {
     $stmt_pc_pending->bind_param("ii", $user_id, $company_id);
     if($stmt_pc_pending->execute()){
         $pending_petty_cash_total = $stmt_pc_pending->get_result()->fetch_assoc()['pending_total'] ?? 0;
     } else { error_log("DB Error fetching pending PC: " . $stmt_pc_pending->error); }
     $stmt_pc_pending->close();
} else { error_log("DB Error preparing pending PC: " . $conn->error); }

// 4. NEW: Get count of unread rejected reports
$rejected_count = 0;
foreach($my_reports as $report){
    if($report['status'] == 'rejected' && $report['is_read'] == 0){
        $rejected_count++;
    }
}


require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Employee Dashboard</h2>
    <p>Your expenses will be reviewed by: <strong><?php echo htmlspecialchars($approver_name); ?></strong></p>

    <!-- NEW: Notification Alert -->
    <?php if ($rejected_count > 0): ?>
    <div class="alert alert-danger" role="alert">
        You have <strong><?php echo $rejected_count; ?></strong> <a href="#reports" class="alert-link">rejected report(s)</a> that require your attention. Please review the comments and resubmit.
    </div>
    <?php endif; ?>

    <?php if ($quick_save_success === 'success'): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
          Receipt saved successfully! You can attach it when creating an expense item.
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php elseif ($quick_save_error): ?>
         <div class="alert alert-danger alert-dismissible fade show" role="alert">
          Error saving receipt: <?php echo htmlspecialchars(urldecode($quick_save_error)); ?>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <!-- Column 1: Actions -->
        <div class="col-lg-4">
            
            <!-- Petty Cash Balance Display -->
            <?php if ($petty_cash_balance !== null): ?>
            <div class="card text-white bg-primary mb-4">
                <div class="card-body">
                    <h5 class="card-title text-center">Petty Cash Status</h5>
                    <div class="d-flex justify-content-between">
                        <span class="card-text">Total Balance:</span>
                        <span class="fw-bold">Rs. <?php echo number_format($petty_cash_balance, 2); ?></span>
                    </div>
                    <div class="d-flex justify-content-between text-warning">
                        <span class="card-text">Submitted (Pending):</span>
                        <span class="fw-bold">- Rs. <?php echo number_format($pending_petty_cash_total, 2); ?></span>
                    </div>
                    <hr class="bg-white my-2">
                    <div class="d-flex justify-content-between fs-5">
                        <span class="card-text">Actual Balance:</span>
                        <span class="fw-bold">Rs. <?php echo number_format($petty_cash_balance - $pending_petty_cash_total, 2); ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header"><h4>Actions</h4></div>
                <div class="card-body">
                    <a href="employee/submit.php" class="btn btn-primary w-100 mb-3">Submit New Expense Report</a>

                    <h5>Quick Save a Bill</h5>
                    <form action="employee/save_receipt.php" method="post" enctype="multipart/form-data">
                        <div class="mb-2">
                            <label for="quick_receipt" class="form-label">Capture Receipt (Max 5MB)</label>
                            <input class="form-control" type="file" id="quick_receipt" name="receipt" accept="image/*,application/pdf" capture="environment" required>
                        </div>
                        <div class="mb-2">
                            <label for="quick_notes" class="form-label">Notes (Optional)</label>
                            <textarea class="form-control" id="quick_notes" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Save Receipt</button>
                    </form>
                </div>
            </div>

             <!-- Unassigned Receipts -->
            <div class="card">
                <div class="card-header"><h4>Receipt Wallet (Unassigned)</h4></div>
                <div class="card-body" style="max-height: 400px; overflow-y: auto;">
                    <?php if (empty($unassigned_receipts)): ?>
                        <p class="text-muted">You have no unassigned receipts.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach($unassigned_receipts as $receipt): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <a href="<?php echo htmlspecialchars($receipt['receipt_url']); ?>" target="_blank">View Receipt</a>
                                        <small class="d-block text-muted">Saved: <?php echo date("M j, Y H:i", strtotime($receipt['uploaded_at'])); ?></small>
                                        <?php if(!empty($receipt['notes'])): ?>
                                            <small class="d-block">Notes: <?php echo htmlspecialchars($receipt['notes']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Column 2: Submitted Reports -->
        <div class="col-lg-8" id="reports">
            <div class="card">
                <div class="card-header"><h4>Your Submitted Reports</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                           <thead><tr><th>Report Title</th><th>Submitted On</th><th>Claimed</th><th>Approved</th><th>Status</th><th>Action</th></tr></thead>
                           <tbody>
                                <?php if (empty($my_reports)): ?>
                                    <tr><td colspan="6">You have not submitted any expense reports yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($my_reports as $report): ?>
                                        <tr class="<?php echo ($report['status'] == 'rejected' && $report['is_read'] == 0) ? 'table-warning' : ''; ?>">
                                            <td><?php echo htmlspecialchars($report['report_title']); ?></td>
                                            <td><?php echo $report['submitted_at'] ? date("M j, Y", strtotime($report['submitted_at'])) : 'Draft'; ?></td>
                                            <td>Rs. <?php echo number_format($report['total_amount'], 2); ?></td>
                                            <td><?php echo in_array($report['status'], ['approved', 'paid']) ? 'Rs. ' . number_format($report['approved_amount'], 2) : 'N/A'; ?></td>
                                            <td>
                                                <span class="badge <?php echo get_status_badge($report['status']); ?>">
                                                    <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report['status']))); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($report['status'] == 'draft'): ?>
                                                    <a href="employee/submit.php?report_id=<?php echo $report['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                                <?php else: ?>
                                                    <a href="employee/view_report.php?report_id=<?php echo $report['id']; ?>" class="btn btn-info btn-sm">View</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                         <?php if ($report['status'] == 'rejected' && !empty($report['admin_comments'])): ?>
                                            <tr class="table-danger"><td colspan="6"><small><strong>Comments:</strong> <?php echo htmlspecialchars($report['admin_comments']); ?></small></td></tr>
                                         <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div> <!-- end row -->
</div>

<?php
// get_status_badge() is now in init.php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>



