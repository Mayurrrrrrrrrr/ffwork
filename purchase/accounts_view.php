<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['accounts', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$po_list = [];
$is_platform_admin = check_role('platform_admin'); // Re-check for platform admin context

// --- DATA FETCHING (COMPANY-AWARE) ---
// Fetch all POs that are in the final accounting stages for this company
// (or all companies if platform admin)
$sql = "SELECT 
            po.id, po.po_number, po.status, po.created_at, 
            v.vendor_name,
            c.company_name,
            pi.vendor_invoice_number, pi.total_amount, pi.accounts_status
        FROM purchase_orders po
        LEFT JOIN vendors v ON po.vendor_id = v.id
        LEFT JOIN purchase_invoices pi ON po.id = pi.po_id
        LEFT JOIN companies c ON po.company_id = c.id
        WHERE 
            po.status IN ('Purchase File Generated', 'Accounts Verified', 'Invoice Received', 'Purchase Complete', 'Customer Delivered')
            " . ($is_platform_admin ? "" : "AND po.company_id = ?") . "
        ORDER BY po.created_at DESC";

if ($stmt = $conn->prepare($sql)) {
    if (!$is_platform_admin) {
        $stmt->bind_param("i", $company_id_context);
    }
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $po_list[] = $row;
        }
    } else { $error_message = "Error fetching reports: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

// get_po_status_badge() is now in init.php
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Accounts View - Purchase Orders</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h4>Accounting-Relevant Purchase Orders</h4>
    </div>
    <div class="card-body">
        <p>This report shows all POs that have reached the accounting verification stage.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>PO Status</th>
                        <?php if($is_platform_admin): ?><th>Company</th><?php endif; ?>
                        <th>PO Number</th>
                        <th>Vendor</th>
                        <th>Vendor Invoice #</th>
                        <th>Invoice Amount</th>
                        <th>Invoice Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($po_list)): ?>
                        <tr><td colspan="<?php echo $is_platform_admin ? '8' : '7'; ?>" class="text-center text-muted">No purchase orders found in the final accounting stages.</td></tr>
                    <?php else: ?>
                        <?php foreach ($po_list as $po): ?>
                        <tr>
                            <td><span class="badge <?php echo get_po_status_badge($po['status']); ?>"><?php echo htmlspecialchars($po['status']); ?></span></td>
                            <?php if($is_platform_admin): ?><td><?php echo htmlspecialchars($po['company_name'] ?? 'N/A'); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($po['po_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['vendor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['vendor_invoice_number'] ?? 'N/A'); ?></td>
                            <td><?php echo $po['total_amount'] ? 'Rs. ' . number_format($po['total_amount'], 2) : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($po['accounts_status'] ?? 'N/A'); ?></td>
                            <td>
                                <a href="purchase/manage_po.php?po_id=<?php echo $po['id']; ?>" class="btn btn-primary btn-sm">View Details</a>
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



