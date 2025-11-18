<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['accounts', 'admin', 'platform_admin', 'purchase_head'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$invoices = [];
$summary = ['pending' => 0, 'verified' => 0, 'paid' => 0, 'total' => 0];

// --- DATA FETCHING (COMPANY-AWARE) ---
// Fetches all invoices that have been logged
$sql = "
    SELECT 
        pi.id as invoice_id,
        pi.po_id,
        pi.vendor_invoice_number,
        pi.invoice_date,
        pi.total_amount,
        pi.accounts_status,
        pi.accounts_remarks,
        po.po_number,
        v.vendor_name
        " . ($is_platform_admin ? ", c.company_name" : "") . "
    FROM purchase_invoices pi
    JOIN purchase_orders po ON pi.po_id = po.id
    LEFT JOIN vendors v ON po.vendor_id = v.id
    " . ($is_platform_admin ? "LEFT JOIN companies c ON po.company_id = c.id" : "") . "
    WHERE 
        1=1 
        " . ($is_platform_admin ? "" : "AND pi.company_id = ?") . "
    ORDER BY
        pi.accounts_status ASC, pi.invoice_date ASC;
";

if ($stmt = $conn->prepare($sql)) {
    if (!$is_platform_admin) {
        $stmt->bind_param("i", $company_id_context);
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $invoices[] = $row;
            // Calculate summary
            $status = strtolower($row['accounts_status']);
            if (isset($summary[$status])) {
                $summary[$status] += $row['total_amount'];
            }
            $summary['total'] += $row['total_amount'];
        }
    } else { $error_message = "Error fetching invoices: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Accounts Payable Report</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- Summary Buckets -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Pending (Awaiting Verification)</h6>
                <p class="fs-4 fw-bold mb-0 text-danger">Rs. <?php echo number_format($summary['pending'], 2); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Verified (Ready to Pay)</h6>
                <p class="fs-4 fw-bold mb-0 text-success">Rs. <?php echo number_format($summary['verified'], 2); ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Paid</h6>
                <p class="fs-4 fw-bold mb-0 text-secondary">Rs. <?php echo number_format($summary['paid'], 2); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Report -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Detailed Invoice Ledger</h4>
        <strong>Total AP: Rs. <?php echo number_format($summary['total'], 2); ?></strong>
    </div>
    <div class="card-body">
        <p>This report shows all invoices logged in the system.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>Invoice Status</th>
                        <?php if($is_platform_admin): ?><th>Company</th><?php endif; ?>
                        <th>Invoice #</th>
                        <th>Vendor</th>
                        <th>PO #</th>
                        <th>Invoice Date</th>
                        <th>Amount (Rs.)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($invoices)): ?>
                        <tr><td colspan="<?php echo $is_platform_admin ? '8' : '7'; ?>" class="text-center text-muted">No invoices found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($invoices as $invoice): ?>
                        <tr>
                            <td>
                                <span class="badge <?php 
                                    if($invoice['accounts_status'] == 'Pending') echo 'bg-warning text-dark';
                                    elseif($invoice['accounts_status'] == 'Verified') echo 'bg-success';
                                    elseif($invoice['accounts_status'] == 'Paid') echo 'bg-secondary';
                                ?>">
                                    <?php echo htmlspecialchars($invoice['accounts_status']); ?>
                                </span>
                            </td>
                            <?php if($is_platform_admin): ?><td><?php echo htmlspecialchars($invoice['company_name']); ?></td><?php endif; ?>
                            <td><strong><?php echo htmlspecialchars($invoice['vendor_invoice_number']); ?></strong></td>
                            <td><?php echo htmlspecialchars($invoice['vendor_name']); ?></td>
                            <td><?php echo htmlspecialchars($invoice['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($invoice['invoice_date']); ?></td>
                            <td><?php echo number_format($invoice['total_amount'], 2); ?></td>
                            <td>
                                <a href="purchase/manage_po.php?po_id=<?php echo $invoice['po_id']; ?>" class="btn btn-primary btn-sm">View PO</a>
                                <?php // We can add a "Mark as Paid" button here later ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


