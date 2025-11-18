<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- PAGE-SPECIFIC LOGIC (DASHBOARD) --
$user_roles = $_SESSION['roles'] ?? [];
$company_id_context = get_current_company_id();
$user_id = $_SESSION['user_id'];
$is_platform_admin = check_role('platform_admin');
$dashboard_reports = [];
$stats = ['new_request' => 0, 'pending_qc' => 0, 'pending_invoice' => 0];
$db_error_message = "[No DB error]"; 
$main_sql_query = "No query was run."; 

// --- 1. FETCH STATS FOR THE TOP BOXES (COMPANY-AWARE) ---
$sql_stats_base = "FROM purchase_orders WHERE " . ($is_platform_admin ? "1=1" : "company_id = ?");
$params_stats = $is_platform_admin ? [] : [$company_id_context];
$types_stats = $is_platform_admin ? "" : "i";

// Add role-specific filters for stats, if user is *only* a sales team member
if (check_role('sales_team') && !has_any_role(['admin', 'accounts', 'approver', 'order_team', 'inventory_team', 'purchase_team', 'purchase_head'])) {
    $sql_stats_base .= " AND initiated_by_user_id = ?";
    $params_stats[] = $user_id;
    $types_stats .= "i";
}
// (Platform admin and other roles see all company stats)

// Execute stat queries
try {
    // Stat 1: New Requests
    $sql_new_params = $params_stats; $sql_new_types = $types_stats;
    $sql_new = "SELECT COUNT(*) " . $sql_stats_base . " AND status = 'New Request'";
    $stmt_new = $conn->prepare($sql_new);
    if($stmt_new && !empty($sql_new_types)) $stmt_new->bind_param($sql_new_types, ...$sql_new_params);
    if($stmt_new) { $stmt_new->execute(); $stats['new_request'] = $stmt_new->get_result()->fetch_row()[0] ?? 0; $stmt_new->close(); }

    // Stat 2: Pending QC
    $sql_qc_params = $params_stats; $sql_qc_types = $types_stats;
    $sql_qc = "SELECT COUNT(*) " . $sql_stats_base . " AND status = 'Inward Complete'";
    $stmt_qc = $conn->prepare($sql_qc);
    if($stmt_qc && !empty($sql_qc_types)) $stmt_qc->bind_param($sql_qc_types, ...$sql_qc_params);
    if($stmt_qc) { $stmt_qc->execute(); $stats['pending_qc'] = $stmt_qc->get_result()->fetch_row()[0] ?? 0; $stmt_qc->close(); }
    
    // Stat 3: Pending Invoicing
    $sql_inv_params = $params_stats; $sql_inv_types = $types_stats;
    $sql_inv = "SELECT COUNT(*) " . $sql_stats_base . " AND status = 'Accounts Verified'";
    $stmt_inv = $conn->prepare($sql_inv);
    if($stmt_inv && !empty($sql_inv_types)) $stmt_inv->bind_param($sql_inv_types, ...$sql_inv_params);
    if($stmt_inv) { $stmt_inv->execute(); $stats['pending_invoice'] = $stmt_inv->get_result()->fetch_row()[0] ?? 0; $stmt_inv->close(); }

} catch (Exception $e) { error_log("Error fetching stats: " . $e->getMessage()); }


// --- 2. FETCH PO LIST FOR THE TABLE (ROLE-AWARE & COMPANY-AWARE) ---
$sql = ""; $params = []; $types = "";
$completed_statuses = "'Purchase Complete', 'Customer Delivered'"; 

if ($is_platform_admin) {
    $sql = "SELECT po.*, v.vendor_name, c.company_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id LEFT JOIN companies c ON po.company_id = c.id WHERE po.status NOT IN ({$completed_statuses}) ORDER BY po.created_at DESC LIMIT 10";
} elseif (check_role('admin') || check_role('purchase_head')) { // Purchase Head sees the same as Admin
    $sql = "SELECT po.*, v.vendor_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id WHERE po.company_id = ? AND po.status NOT IN ({$completed_statuses}) ORDER BY po.created_at DESC LIMIT 10";
    $params[] = $company_id_context; $types = "i";
} elseif (check_role('accounts')) {
     $sql = "SELECT po.*, v.vendor_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id WHERE po.company_id = ? AND po.status IN ('Purchase File Generated', 'Accounts Verified', 'Invoice Received') ORDER BY po.created_at DESC LIMIT 10";
     $params[] = $company_id_context; $types = "i";
} elseif (check_role('purchase_team')) {
     $sql = "SELECT po.*, v.vendor_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id WHERE po.company_id = ? AND po.status IN ('Imaging Complete', 'QC Failed', 'Accounts Verified', 'Invoice Received') ORDER BY po.created_at DESC LIMIT 10";
     $params[] = $company_id_context; $types = "i";
} elseif (check_role('order_team') || check_role('inventory_team')) {
     $sql = "SELECT po.*, v.vendor_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id WHERE po.company_id = ? AND po.status IN ('New Request', 'Order Placed', 'Goods Received', 'Inward Complete', 'QC Passed', 'QC Failed', 'Imaging Complete') ORDER BY po.created_at DESC LIMIT 10";
     $params[] = $company_id_context; $types = "i";
} elseif (check_role('sales_team')) {
    $sql = "SELECT po.*, v.vendor_name FROM purchase_orders po LEFT JOIN vendors v ON po.vendor_id = v.id WHERE po.initiated_by_user_id = ? AND po.company_id = ? AND po.status NOT IN ({$completed_statuses}) ORDER BY po.created_at DESC LIMIT 10";
    $params[] = $user_id; $params[] = $company_id_context; $types = "ii";
}

$main_sql_query = $sql; 

if (!empty($sql) && $stmt = $conn->prepare($sql)) {
    if(!empty($params)) $stmt->bind_param($types, ...$params);
    if($stmt->execute()){
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()){ $dashboard_reports[] = $row; }
    } else { $db_error_message = $stmt->error; error_log("Purchase dash error: ".$stmt->error); }
    $stmt->close();
} else { if(!isset($db_error_message) && !empty($sql)) { $db_error_message = $conn->error; error_log("Purchase dash prepare error: ".$conn->error); } }

?>

<h2>Purchase System Dashboard</h2>
<p>Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>. Here is an overview of your tasks.</p>

<!-- Notification Alert -->
<?php 
$notification_count = 0;
if($is_platform_admin) { $notification_count = count($dashboard_reports); } 
elseif (check_role('sales_team')) { $notification_count = $stats['new_request']; }
else { $notification_count = count($dashboard_reports); }
?>
<?php if ($notification_count > 0 && !$is_platform_admin): ?>
<div class="alert alert-info" role="alert">
    You have <strong><?php echo $notification_count; ?></strong> purchase order(s) awaiting your action. Please review them in the "Your Active POs" table below.
</div>
<?php endif; ?>


<!-- Quick Stats (Now dynamic) -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card text-white bg-info">
            <div class="card-body text-center">
                <h5 class="card-title">New Requests</h5>
                <p class="card-text fs-3 fw-bold"><?php echo $stats['new_request']; ?></p>
            </div>
        </div>
    </div>
     <div class="col-md-4">
        <div class="card text-white bg-warning">
            <div class="card-body text-center">
                <h5 class="card-title">Pending QC</h5>
                <p class="card-text fs-3 fw-bold"><?php echo $stats['pending_qc']; ?></p>
            </div>
        </div>
    </div>
     <div class="col-md-4">
        <div class="card text-white bg-primary">
            <div class="card-body text-center">
                <h5 class="card-title">Pending Invoicing</h5>
                <p class="card-text fs-3 fw-bold"><?php echo $stats['pending_invoice']; ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="card">
    <div class="card-header"><h4><?php echo $is_platform_admin ? "Recent Active POs (All Companies)" : "Your Active POs"; ?></h4></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Status</th>
                        <?php if($is_platform_admin): ?><th>Company</th><?php endif; ?>
                        <th>PO #</th>
                        <th>Vendor</th>
                        <th>Customer</th>
                        <th>Created On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(empty($dashboard_reports)): ?>
                        <tr><td colspan="<?php echo $is_platform_admin ? '7' : '6'; ?>" class="text-center text-muted">No active purchase orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach($dashboard_reports as $po): ?>
                        <tr>
                            <td><span class="badge <?php echo get_po_status_badge($po['status']); ?>"><?php echo htmlspecialchars($po['status']); ?></span></td>
                            <?php if($is_platform_admin): ?><td><?php echo htmlspecialchars($po['company_name']); ?></td><?php endif; ?>
                            <td><?php echo htmlspecialchars($po['po_number'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['vendor_name'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($po['customer_name'] ?? 'N/A'); ?></td>
                            <td><?php echo date("Y-m-d", strtotime($po['created_at'])); ?></td>
                            <td><a href="purchase/manage_po.php?po_id=<?php echo $po['id']; ?>" class="btn btn-sm btn-primary">View Details</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
// get_po_status_badge() is now defined in init.php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


