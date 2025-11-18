<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS HEAD OF PURCHASE, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['purchase_head', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$vendor_stats = [];

// --- DATA FETCHING (COMPANY-AWARE) ---
// This complex query joins vendors, POs, PO items, and jewel inventory
// to calculate performance metrics for each vendor in the company.
$sql = "
    SELECT
        v.id,
        v.vendor_name,
        COUNT(DISTINCT po.id) AS total_pos,
        SUM(pi.quantity) AS total_items_ordered,
        SUM(pi.quantity_received) AS total_items_received,
        AVG(DATEDIFF(po.received_date, po.target_date)) AS avg_days_late,
        (SELECT COUNT(*) FROM jewel_inventory ji WHERE ji.po_id IN (SELECT id FROM purchase_orders WHERE vendor_id = v.id) AND ji.qc_status = 'Fail') AS total_fail_qc,
        (SELECT SUM(p_inv.total_amount) FROM purchase_invoices p_inv WHERE p_inv.po_id IN (SELECT id FROM purchase_orders WHERE vendor_id = v.id)) AS total_invoiced_amount
    FROM vendors v
    LEFT JOIN purchase_orders po ON v.id = po.vendor_id
    LEFT JOIN po_items pi ON po.id = pi.po_id
    WHERE
        v.company_id = ?
    GROUP BY
        v.id, v.vendor_name
    ORDER BY
        v.vendor_name;
";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $company_id_context);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $vendor_stats[] = $row;
        }
    } else { $error_message = "Error fetching vendor stats: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Vendor Performance Report</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h4>Vendor Performance Summary</h4>
    </div>
    <div class="card-body">
        <p>This report analyzes vendor reliability based on completed orders.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Vendor Name</th>
                        <th>Total POs</th>
                        <th>Items Ordered</th>
                        <th>Items Received</th>
                        <th>Total QC Failed</th>
                        <th>Avg. Days Late</th>
                        <th>Total Invoiced (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendor_stats)): ?>
                        <tr><td colspan="7" class="text-center text-muted">No vendor data found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vendor_stats as $vendor): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($vendor['vendor_name']); ?></strong></td>
                            <td><?php echo $vendor['total_pos']; ?></td>
                            <td><?php echo $vendor['total_items_ordered'] ?? 0; ?></td>
                            <td><?php echo $vendor['total_items_received'] ?? 0; ?></td>
                            <td>
                                <span class="<?php echo ($vendor['total_fail_qc'] > 0) ? 'text-danger fw-bold' : ''; ?>">
                                    <?php echo $vendor['total_fail_qc'] ?? 0; ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    $avg_days = $vendor['avg_days_late'];
                                    if ($avg_days === null) echo 'N/A';
                                    elseif ($avg_days <= 0) echo '<span class="text-success">On Time</span>';
                                    else echo '<span class="text-danger">' . number_format($avg_days, 1) . ' days</span>';
                                ?>
                            </td>
                            <td>Rs. <?php echo number_format($vendor['total_invoiced_amount'] ?? 0, 2); ?></td>
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



