<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, log_audit_action()

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS OR ADMIN --
if (!has_any_role(['accounts', 'admin'])) {
    header("location: " . BASE_URL . "login.php"); // Root-relative path
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null; // User performing the action

if (!$company_id || !$user_id) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error"); // Root-relative path
    exit;
}

$error_message = '';
$success_message = '';

// -- FORM HANDLING (POST/REDIRECT/GET PATTERN, COMPANY-AWARE, AUDIT LOGGING) --
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['mark_paid'])) {
    $report_to_pay_id = $_POST['report_id'];
    $action_success = false;
    $error_occurred = false;
    $error_details = '';
    
    // Include company_id in the WHERE clause for security
    $sql_update = "UPDATE expense_reports SET status = 'paid', paid_at = NOW() 
                   WHERE id = ? AND status = 'approved' AND company_id = ?";
                   
    if ($stmt_update = $conn->prepare($sql_update)) {
        $stmt_update->bind_param("ii", $report_to_pay_id, $company_id);
        if ($stmt_update->execute() && $stmt_update->affected_rows > 0) {
            $action_success = true;
            // Log successful action
            log_audit_action($conn, 'report_marked_paid', "Marked report as paid.", $user_id, $company_id, 'report', $report_to_pay_id);
        } else {
            $error_occurred = true;
            $error_details = $stmt_update->error ?: "Report not found, not approved, or already paid.";
            log_audit_action($conn, 'report_mark_paid_failed', "Failed to mark report as paid. Error: ".$error_details, $user_id, $company_id, 'report', $report_to_pay_id);
        }
        $stmt_update->close();
    } else {
         $error_occurred = true;
         $error_details = $conn->error;
         log_audit_action($conn, 'report_mark_paid_failed', "Failed to mark report as paid. DB Prepare Error.", $user_id, $company_id, 'report', $report_to_pay_id);
    }
    
    // Redirect after processing
    if ($action_success) {
        header("location: " . BASE_URL . "admin/payouts.php?success=paid"); // Root-relative path
    } else {
        $_SESSION['flash_error'] = $error_details; // Store error for display after redirect
        header("location: " . BASE_URL . "admin/payouts.php?error=paid"); // Root-relative path
    }
    exit;
}

// Check for status messages from the redirect
if (isset($_GET['success'])) {
    $success_message = "Report has been successfully marked as paid.";
}
if (isset($_GET['error'])) {
    $error_message = $_SESSION['flash_error'] ?? "Could not mark report as paid. It may have already been processed or an error occurred.";
    unset($_SESSION['flash_error']); // Clear the message
}


// -- DATA FETCHING: GET ALL APPROVED REPORTS READY FOR PAYOUT (COMPANY-AWARE) --
$payout_reports = [];
// ... (SQL query remains the same, already includes company_id) ...
$sql = "SELECT r.id, r.report_title, r.approved_at, r.approved_amount, u.full_name,
            SUM(CASE WHEN i.payment_method IN ('Cash', 'Personal Card') THEN i.amount ELSE 0 END) AS reimbursement_total,
            SUM(CASE WHEN i.payment_method = 'Corp Card' THEN i.amount ELSE 0 END) AS corporate_card_total
        FROM expense_reports r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN expense_items i ON i.report_id = r.id
        WHERE r.status = 'approved' AND r.company_id = ?
        GROUP BY r.id 
        ORDER BY r.approved_at ASC";


if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $company_id);
     if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $payout_reports[] = $row;
        }
    } else { error_log("Error fetching payout reports: ".$stmt->error); }
    $stmt->close();
} else { error_log("Error preparing payout reports statement: ".$conn->error); }

// -- INCLUDE THE COMMON HTML HEADER --
require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Payout Reports</h2>
    <p>The following reports have been fully approved and are ready for payment processing for your company.</p>
    
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4>Reports to be Paid</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Employee</th>
                            <th>Report Title</th>
                            <th>Date Approved</th>
                            <th>Total Approved</th>
                            <th>To Employee (Cash/Card)</th>
                            <th>Corp. Card Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($payout_reports)): ?>
                            <tr><td colspan="7">There are no reports awaiting payout.</td></tr>
                        <?php else: ?>
                            <?php foreach ($payout_reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['report_title']); ?></td>
                                    <td><?php echo date("Y-m-d", strtotime($report['approved_at'])); ?></td>
                                    <td><strong>Rs. <?php echo number_format($report['approved_amount'], 2); ?></strong></td>
                                    <td><span class="text-success fw-bold">Rs. <?php echo number_format($report['reimbursement_total'], 2); ?></span></td>
                                    <td>Rs. <?php echo number_format($report['corporate_card_total'], 2); ?></td>
                                    <td>
                                        <form action="admin/payouts.php" method="post" onsubmit="return confirm('Mark report #<?php echo $report['id']; ?> as paid? This cannot be undone.');">
                                            <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                            <button type="submit" name="mark_paid" class="btn btn-success btn-sm">Mark as Paid</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>



