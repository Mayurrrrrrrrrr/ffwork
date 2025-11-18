<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS HEAD OF PURCHASE, ADMIN, OR PLATFORM_ADMIN --
if (!has_any_role(['purchase_head', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$cycle_stats = [];

// --- DATA FETCHING (COMPANY-AWARE) ---
// THIS QUERY IS NOW FIXED
// It calculates the average time difference in days by joining with the audit_logs table.
// It only considers 'Customer Delivered' orders for an accurate average of the full cycle.
$sql = "
    SELECT
        AVG(DATEDIFF(
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_placed' AND al.target_id = po.id), 
            po.created_at
        )) AS avg_request_to_placed,
        
        AVG(DATEDIFF(
            po.received_date, 
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_placed' AND al.target_id = po.id)
        )) AS avg_placed_to_received,

        AVG(DATEDIFF(
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_inward_complete' AND al.target_id = po.id), 
            po.received_date
        )) AS avg_received_to_inward_complete,

        AVG(DATEDIFF(
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_qc_complete' AND al.target_id = po.id), 
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_inward_complete' AND al.target_id = po.id)
        )) AS avg_inward_to_qc_complete,

        AVG(DATEDIFF(
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_complete' AND al.target_id = po.id), 
            (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_qc_complete' AND al.target_id = po.id)
        )) AS avg_qc_to_purchase_complete,

        AVG(DATEDIFF(
             (SELECT MIN(al.created_at) FROM audit_logs al WHERE al.action_type = 'po_customer_delivered' AND al.target_id = po.id),
             po.created_at
        )) AS avg_full_cycle_time

    FROM purchase_orders po
    WHERE 
        po.status = 'Customer Delivered'
        " . ($is_platform_admin ? "" : "AND po.company_id = ?");

if ($stmt = $conn->prepare($sql)) {
    if (!$is_platform_admin) {
        $stmt->bind_param("i", $company_id_context);
    }
    
    if ($stmt->execute()) {
        $cycle_stats = $stmt->get_result()->fetch_assoc();
    } else { $error_message = "Error fetching cycle stats: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Purchase Cycle Time Report</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header">
        <h4>Average Process Efficiency</h4>
    </div>
    <div class="card-body">
        <p>This report analyzes the average time (in days) taken for each step in the purchase workflow, based on all completed orders.</p>
        
        <?php if (empty($cycle_stats) || $cycle_stats['avg_full_cycle_time'] === null): ?>
            <div class="alert alert-info">No fully completed ('Customer Delivered') orders found to analyze. As you complete orders, data will appear here.</div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="card bg-light shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted">Request to Order Placed</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_request_to_placed'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Sales & Order Team)</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted">Order Placed to Goods Received</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_placed_to_received'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Vendor Performance)</small>
                        </div>
                    </div>
                </div>
                 <div class="col-md-4">
                    <div class="card bg-light shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted">Goods Received to Inward Complete</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_received_to_inward_complete'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Order Team)</small>
                        </div>
                    </div>
                </div>
                 <div class="col-md-4">
                    <div class="card bg-light shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted">Inward Complete to QC Complete</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_inward_to_qc_complete'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Order Team - QC)</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card bg-light shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-muted">QC Complete to Purchase Complete</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_qc_to_purchase_complete'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Imaging, Purchase & Accounts)</small>
                        </div>
                    </div>
                </div>
                 <div class="col-md-4">
                    <div class="card bg-success text-white shadow-sm">
                        <div class="card-body text-center">
                            <h6 class="text-white-50">Avg. Full Cycle (Request to Delivery)</h6>
                            <p class="fs-3 fw-bold mb-0"><?php echo number_format($cycle_stats['avg_full_cycle_time'] ?? 0, 1); ?> <span class="fs-6 fw-normal">Days</span></p>
                            <small>(Total Time)</small>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



