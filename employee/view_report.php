<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    if (has_any_role(['admin', 'accounts', 'approver'])) {
        header("location: " . BASE_URL . "admin/index.php"); 
    } else {
        header("location: " . BASE_URL . "login.php");
    }
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null;
$report_id = $_GET['report_id'] ?? null;

if (!$company_id || !$user_id || !$report_id) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error");
    exit;
}

// --- DATA FETCHING LOGIC (COMPANY-AWARE) ---
$report_details = null;
$expense_items = [];

// Fetch main report details, ensuring it belongs to the logged-in user AND their company
$sql_report = "SELECT r.*, u.full_name, u.department 
               FROM expense_reports r
               JOIN users u ON r.user_id = u.id
               WHERE r.id = ? AND r.user_id = ? AND r.company_id = ?";
if ($stmt = $conn->prepare($sql_report)) {
    $stmt->bind_param("iii", $report_id, $user_id, $company_id);
    if ($stmt->execute()) {
        $report_details = $stmt->get_result()->fetch_assoc();
    } else { error_log("DB Error fetching report view details: ".$stmt->error); }
    $stmt->close();
} else { error_log("DB Error preparing report view details: ".$conn->error); }


if (!$report_details) {
    // If no report found or it doesn't belong to the user/company, redirect
    header("location: " . BASE_URL . "employee/index.php?error=notfound"); // Root-relative path
    exit;
}

// Fetch associated expense items (implicitly company-aware via report_id)
$sql_items = "SELECT * FROM expense_items WHERE report_id = ? ORDER BY item_date ASC";
if ($stmt_items = $conn->prepare($sql_items)) {
    $stmt_items->bind_param("i", $report_id);
    $stmt_items->execute();
    $result_items = $stmt_items->get_result();
    while ($row = $result_items->fetch_assoc()) {
        $expense_items[] = $row;
    }
    $stmt_items->close();
}

// -- INCLUDE THE COMMON HTML HEADER --
require_once '../includes/header.php';
?>
<style>
    /* Simple styles for printing */
    @media print {
        body * { visibility: hidden; }
        .printable-area, .printable-area * { visibility: visible; }
        .printable-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none; }
        .card { border: none !important; box-shadow: none !important; }
        .table-responsive { overflow-x: visible !important; } /* Allow table to expand fully when printing */
    }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <!-- Use root-relative path -->
        <a href="employee/index.php" class="btn btn-secondary btn-sm">Back to Dashboard</a>
        <button onclick="window.print()" class="btn btn-primary">Print Report</button>
    </div>

    <div class="card printable-area">
        <div class="card-header">
            <h3>Expense Report Details</h3>
        </div>
        <div class="card-body">
            <!-- Report Summary -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <p><strong>Employee:</strong> <?php echo htmlspecialchars($report_details['full_name']); ?></p>
                    <p><strong>Department:</strong> <?php echo htmlspecialchars($report_details['department']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Report Title:</strong> <?php echo htmlspecialchars($report_details['report_title']); ?></p>
                    <p><strong>Status:</strong> <span class="badge <?php 
                        switch($report_details['status']) {
                            case 'approved': case 'paid': echo 'bg-success'; break;
                            case 'rejected': echo 'bg-danger'; break;
                            case 'pending_approval': echo 'bg-warning text-dark'; break;
                            case 'pending_verification': echo 'bg-info text-dark'; break;
                            default: echo 'bg-secondary';
                        }
                    ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report_details['status']))); ?></span></p>
                </div>
            </div>

            <!-- Expense Items Table -->
            <h5>Expense Items</h5>
            <!-- Added table-responsive wrapper -->
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Category</th>
                            <th>Description</th>
                            <th>Amount</th>
                            <th>Payment Method</th>
                            <th>Receipt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($expense_items)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No items were added to this report.</td></tr>
                        <?php else: ?>
                            <?php foreach ($expense_items as $item): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['item_date']); ?></td>
                                    <td><?php echo htmlspecialchars($item['category']); ?></td>
                                    <td><?php echo htmlspecialchars($item['description']); ?></td>
                                    <td>Rs. <?php echo number_format($item['amount'], 2); ?></td>
                                    <td><?php echo htmlspecialchars($item['payment_method']); ?></td>
                                    <td>
                                        <?php if ($item['receipt_url']): ?>
                                            <!-- Use root-relative path -->
                                            <a href="<?php echo htmlspecialchars($item['receipt_url']); ?>" target="_blank">View</a>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                     <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end"><strong>Total Claimed:</strong></td>
                            <td colspan="3"><strong>Rs. <?php echo number_format($report_details['total_amount'], 2); ?></strong></td>
                        </tr>
                     </tfoot>
                </table>
            </div>

             <!-- Final Details Section -->
             <div class="mt-4">
                 <?php if(in_array($report_details['status'], ['approved', 'paid'])): ?>
                    <p class="fs-5"><strong>Final Approved Amount:</strong> <span class="text-success">Rs. <?php echo number_format($report_details['approved_amount'], 2); ?></span></p>
                 <?php endif; ?>
                 <?php if($report_details['status'] == 'rejected' && !empty($report_details['admin_comments'])): ?>
                    <p><strong>Comments:</strong></p>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($report_details['admin_comments']); ?></div>
                 <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>



