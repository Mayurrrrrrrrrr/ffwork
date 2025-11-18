<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS HEAD OF PURCHASE, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['purchase_head', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$pipeline_pos = [];

// --- DATA FETCHING (COMPANY-AWARE) ---
// Fetch all active (non-completed) POs for the company
// Or all companies if platform admin
$sql = "
    SELECT 
        po.id,
        po.po_number,
        po.status,
        po.created_at,
        po.target_date,
        po.received_date,
        DATEDIFF(NOW(), po.created_at) as days_open,
        v.vendor_name,
        u.full_name as initiated_by
        " . ($is_platform_admin ? ", c.company_name" : "") . "
    FROM purchase_orders po
    LEFT JOIN vendors v ON po.vendor_id = v.id
    JOIN users u ON po.initiated_by_user_id = u.id
    " . ($is_platform_admin ? "LEFT JOIN companies c ON po.company_id = c.id" : "") . "
    WHERE 
        po.status NOT IN ('Purchase Complete', 'Customer Delivered')
        " . ($is_platform_admin ? "" : "AND po.company_id = ?") . "
    ORDER BY
        days_open DESC, po.created_at ASC;
";

if ($stmt = $conn->prepare($sql)) {
    if (!$is_platform_admin) {
        $stmt->bind_param("i", $company_id_context);
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $pipeline_pos[] = $row;
        }
    } else { $error_message = "Error fetching pipeline data: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

// get_po_status_badge() is in init.php
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Purchase Order Pipeline Tracker</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h4>All Active Purchase Orders</h4>
    </div>
    <div class="card-body">
        <p>This report shows all purchase orders that are not yet complete, sorted by the longest open time.</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Days Open</th>
                        <th>Status</th>
                        <?php if($is_platform_admin): ?><th>Company</th><?php endif; ?>
                        <th>PO Number</th>
                        <th>Vendor</th>
                        <th>Customer</th>
                        <th>Target Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pipeline_pos)): ?>
                        <tr><td colspan="<?php echo $is_platform_admin ? '8' : '7'; ?>" class="text-center text-muted">No active purchase orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($pipeline_pos as $po): ?>
                        <tr>
                            <td>
                                <span class="badge <?php echo ($po['days_open'] > 30) ? 'bg-danger' : (($po['days_open'] > 15) ? 'bg-warning text-dark' : 'bg-secondary'); ?>">
                                    <?php echo $po['days_open']; ?> Days
                                </span>
                            </td>
                            <td><span class="badge <?php echo get_po_status_badge($po['status']); ?>"><?php echo htmlspecialchars($po['status']); ?></span></td>
                            <?php if($is_platform_admin): ?><td><?php echo htmlspecialchars($po['company_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($po['po_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['vendor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($po['target_date'] ?? 'N/A'); ?></td>
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



