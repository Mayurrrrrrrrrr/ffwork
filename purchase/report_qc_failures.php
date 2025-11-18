<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS HEAD OF PURCHASE, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['purchase_head', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$failed_items = [];
$vendor_summary = [];

// --- DATA FETCHING (COMPANY-AWARE) ---
$base_where = $is_platform_admin ? "" : " AND po.company_id = " . (int)$company_id_context;

// 1. Fetch Summary by Vendor
$sql_summary = "
    SELECT 
        v.vendor_name,
        COUNT(ji.id) AS total_failed,
        (SELECT COUNT(*) FROM jewel_inventory ji2 JOIN purchase_orders po2 ON ji2.po_id = po2.id WHERE po2.vendor_id = v.id AND ji2.qc_status != 'Pending' " . ($is_platform_admin ? "" : "AND po2.company_id = " . (int)$company_id_context) . ") as total_qcd
    FROM jewel_inventory ji
    JOIN purchase_orders po ON ji.po_id = po.id
    JOIN vendors v ON po.vendor_id = v.id
    WHERE ji.qc_status = 'Fail'
    {$base_where}
    GROUP BY v.id, v.vendor_name
    ORDER BY total_failed DESC;
";

if ($result_summary = $conn->query($sql_summary)) {
    while ($row = $result_summary->fetch_assoc()) {
        $vendor_summary[] = $row;
    }
} else { $error_message = "Error fetching summary: " . $conn->error; }


// 2. Fetch All Individual Failed Items
$sql_items = "
    SELECT 
        ji.jewel_code,
        ji.qc_remarks,
        ji.inward_at,
        pi.design_code,
        po.po_number,
        v.vendor_name
        " . ($is_platform_admin ? ", c.company_name" : "") . "
    FROM jewel_inventory ji
    JOIN purchase_orders po ON ji.po_id = po.id
    JOIN po_items pi ON ji.po_item_id = pi.id
    LEFT JOIN vendors v ON po.vendor_id = v.id
    " . ($is_platform_admin ? "LEFT JOIN companies c ON po.company_id = c.id" : "") . "
    WHERE 
        ji.qc_status = 'Fail'
        " . ($is_platform_admin ? "" : "AND po.company_id = ?") . "
    ORDER BY
        ji.inward_at DESC;
";

if ($stmt_items = $conn->prepare($sql_items)) {
    if (!$is_platform_admin) {
        $stmt_items->bind_param("i", $company_id_context);
    }
    if ($stmt_items->execute()) {
        $result_items = $stmt_items->get_result();
        while ($row = $result_items->fetch_assoc()) {
            $failed_items[] = $row;
        }
    } else { $error_message .= " Error fetching failed items: " . $stmt_items->error; }
    $stmt_items->close();
} else { $error_message .= " Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>QC Failure Analysis Report</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- Summary by Vendor -->
<div class="card mb-4">
    <div class="card-header">
        <h4>QC Failures by Vendor</h4>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Vendor Name</th>
                        <th>Total Items QC'd</th>
                        <th>Total Items Failed</th>
                        <th>Failure Rate (%)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vendor_summary)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No QC failures found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($vendor_summary as $vendor): ?>
                        <?php $failure_rate = ($vendor['total_qcd'] > 0) ? ($vendor['total_failed'] / $vendor['total_qcd']) * 100 : 0; ?>
                        <tr class="<?php echo ($failure_rate > 10) ? 'table-danger' : (($failure_rate > 5) ? 'table-warning' : ''); ?>">
                            <td><strong><?php echo htmlspecialchars($vendor['vendor_name']); ?></strong></td>
                            <td><?php echo $vendor['total_qcd']; ?></td>
                            <td><?php echo $vendor['total_failed']; ?></td>
                            <td><?php echo number_format($failure_rate, 2); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed Log -->
<div class="card">
    <div class="card-header">
        <h4>Detailed Log of Failed Items</h4>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <?php if($is_platform_admin): ?><th>Company</th><?php endif; ?>
                        <th>Jewel Code</th>
                        <th>Design Code</th>
                        <th>PO Number</th>
                        <th>Vendor</th>
                        <th>QC Remarks (Reason for Failure)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($failed_items)): ?>
                        <tr><td colspan="<?php echo $is_platform_admin ? '6' : '5'; ?>" class="text-center text-muted">No QC failed items found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($failed_items as $item): ?>
                        <tr class="table-danger">
                            <?php if($is_platform_admin): ?><td><?php echo htmlspecialchars($item['company_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($item['jewel_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['design_code']); ?></td>
                            <td><?php echo htmlspecialchars($item['po_number']); ?></td>
                            <td><?php echo htmlspecialchars($item['vendor_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['qc_remarks']); ?></td>
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


