<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS HEAD OF PURCHASE, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['purchase_head', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$aging_items = [];
$aging_summary = [
    '0-30' => 0,
    '31-60' => 0,
    '61-90' => 0,
    '90+' => 0,
    'total_value' => 0
];

// --- DATA FETCHING (COMPANY-AWARE) ---
// Find all jewel inventory items that have passed QC but are not yet on a 'Customer Delivered' PO
// We also need to get the *cost* of these items, which we'll estimate from the purchase_invoices table.
$sql = "
    SELECT 
        ji.jewel_code,
        ji.inward_at,
        DATEDIFF(NOW(), ji.inward_at) as days_in_stock,
        pi.design_code,
        po.po_number,
        v.vendor_name,
        -- Estimate cost by dividing total invoice amount by total items received on that PO
        -- This is an estimate; a real system might store cost per item.
        (COALESCE(p_inv.total_amount, 0) / NULLIF( (SELECT SUM(quantity_received) FROM po_items WHERE po_id = po.id), 0)) AS estimated_cost
    FROM jewel_inventory ji
    JOIN purchase_orders po ON ji.po_id = po.id
    JOIN po_items pi ON ji.po_item_id = pi.id
    LEFT JOIN vendors v ON po.vendor_id = v.id
    LEFT JOIN purchase_invoices p_inv ON po.id = p_inv.po_id
    WHERE 
        ji.qc_status = 'Pass'
        AND po.company_id = ?
        AND po.status != 'Customer Delivered'
    ORDER BY
        days_in_stock DESC;
";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("i", $company_id_context);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $aging_items[] = $row;
            $cost = $row['estimated_cost'] ?? 0;
            $days = $row['days_in_stock'];
            
            // Group into buckets
            if ($days <= 30) $aging_summary['0-30'] += $cost;
            elseif ($days <= 60) $aging_summary['31-60'] += $cost;
            elseif ($days <= 90) $aging_summary['61-90'] += $cost;
            else $aging_summary['90+'] += $cost;
            
            $aging_summary['total_value'] += $cost;
        }
    } else { $error_message = "Error fetching aging report: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Inventory Aging Report</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- Summary Buckets -->
<div class="row g-4 mb-4">
    <div class="col-lg-3 col-6">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">0-30 Days</h6>
                <p class="fs-4 fw-bold mb-0">Rs. <?php echo number_format($aging_summary['0-30'], 0); ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">31-60 Days</h6>
                <p class="fs-4 fw-bold mb-0">Rs. <?php echo number_format($aging_summary['31-60'], 0); ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card bg-light">
            <div class="card-body text-center">
                <h6 class="text-muted">61-90 Days</h6>
                <p class="fs-4 fw-bold mb-0">Rs. <?php echo number_format($aging_summary['61-90'], 0); ?></p>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-6">
        <div class="card bg-warning">
            <div class="card-body text-center">
                <h6 class="text-warning-dark">90+ Days</h6>
                <p class="fs-4 fw-bold mb-0 text-danger">Rs. <?php echo number_format($aging_summary['90+'], 0); ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Report -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Detailed Inventory on Hand (QC Passed)</h4>
        <strong>Total Est. Value: Rs. <?php echo number_format($aging_summary['total_value'], 2); ?></strong>
    </div>
    <div class="card-body">
        <p>This report shows all individual items that have passed QC but have not yet been delivered to a customer.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>Days in Stock</th>
                        <th>Jewel Code</th>
                        <th>Design Code</th>
                        <th>PO Number</th>
                        <th>Vendor</th>
                        <th>Est. Cost (Rs.)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($aging_items)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No QC Passed inventory found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($aging_items as $item): ?>
                        <tr class="<?php echo ($item['days_in_stock'] > 90) ? 'table-danger' : (($item['days_in_stock'] > 60) ? 'table-warning' : ''); ?>">
                            <td><strong><?php echo $item['days_in_stock']; ?> Days</strong></td>
                            <td><?php echo htmlspecialchars($item['jewel_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['design_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($item['vendor_name']); ?></td>
                            <td><?php echo number_format($item['estimated_cost'] ?? 0, 2); ?></td>
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


