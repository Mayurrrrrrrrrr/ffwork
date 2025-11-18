<?php
require_once 'includes/init.php'; // Includes $conn, session start, role checks

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

// Get user details from session
$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? 'User';
$company_id_context = get_current_company_id();
if (!$company_id_context) {
    session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}

// --- NEW FIX: Get and set store_id in session if it's missing ---
if (!isset($_SESSION['store_id'])) {
    $sql_store = "SELECT store_id FROM users WHERE id = ?";
    if ($stmt_store = $conn->prepare($sql_store)) {
        $stmt_store->bind_param("i", $user_id);
        if ($stmt_store->execute()) {
            $stmt_store->bind_result($user_store_id);
            if ($stmt_store->fetch()) {
                $_SESSION['store_id'] = $user_store_id;
            } else {
                $_SESSION['store_id'] = 0; // User might not have a store
            }
        }
        $stmt_store->close();
    }
}
// --- END NEW FIX ---


// Check roles for module visibility
$is_admin = check_role('admin');
$is_approver = check_role('approver');
$is_btl_approver = has_any_role(['marketing_manager', 'admin']); // L1 BTL approver
$is_order_team = has_any_role(['order_team', 'admin']); // PO team
$is_purchase_team = has_any_role(['purchase_team', 'admin']);
$is_trainer = check_role('trainer'); // Assuming 'trainer' is the specific role checked here
$can_manage_referrals = has_any_role(['admin', 'accounts', 'approver', 'sales_team']);

// Initialize counts (FIX: Initialize all counts to 0)
$pending_reports_count = 0;
$reports_to_verify_count = 0;
$pending_btl_count = 0;
$pending_po_count = 0;
$pending_stock_incoming_count = 0; // Requests for me to send
$pending_stock_shipped_count = 0;  // Requests sent to me to receive

// *** Stock Transfer Variables ***
$gati_column_exists = false;
$user_gati_location = null;

// Check if the new GATI column exists in the 'stores' table
$sql_check_col = "SHOW COLUMNS FROM `stores` LIKE 'gati_location_name'";
if ($result_check_col = $conn->query($sql_check_col)) {
    if ($result_check_col->num_rows > 0) {
        $gati_column_exists = true;
    }
    $result_check_col->free();
}

// If the column exists, try to get the user's location
if ($gati_column_exists) {
    // 1. Get the user's store_id
    $sql_user_store = "SELECT s.gati_location_name FROM users u 
                       JOIN stores s ON u.store_id = s.id 
                       WHERE u.id = ? AND s.gati_location_name IS NOT NULL";
    if ($stmt_gati = $conn->prepare($sql_user_store)) {
        $stmt_gati->bind_param("i", $user_id);
        if ($stmt_gati->execute()) {
            $stmt_gati->bind_result($user_gati_location);
            $stmt_gati->fetch();
        }
        $stmt_gati->close();
    }
}
// *** END Stock Transfer ***

// *** Fetch Birthdays & Anniversaries ***
$birthdays_today = [];
$birthdays_this_month = [];
$anniversaries_today = [];
$anniversaries_this_month = [];
$current_month = date('m');
$current_day = date('d');

// Fetch Birthdays
$sql_birthdays = "SELECT full_name, dob, DAY(dob) as b_day FROM users 
                  WHERE company_id = ? AND MONTH(dob) = ?
                  ORDER BY DAY(dob)";
if ($stmt_bday = $conn->prepare($sql_birthdays)) {
    $stmt_bday->bind_param("is", $company_id_context, $current_month);
    $stmt_bday->execute();
    $result_bday = $stmt_bday->get_result();
    while ($row = $result_bday->fetch_assoc()) {
        if ($row['b_day'] == $current_day) {
            $birthdays_today[] = $row;
        } else {
            $birthdays_this_month[] = $row;
        }
    }
    $stmt_bday->close();
}

// Fetch Anniversaries
$sql_anniv = "SELECT full_name, doj, DAY(doj) as a_day, 
              YEAR(CURRENT_DATE()) - YEAR(doj) as years_of_service 
              FROM users 
              WHERE company_id = ? AND MONTH(doj) = ? AND doj IS NOT NULL
              ORDER BY DAY(doj)";
if ($stmt_anniv = $conn->prepare($sql_anniv)) {
    $stmt_anniv->bind_param("is", $company_id_context, $current_month);
    $stmt_anniv->execute();
    $result_anniv = $stmt_anniv->get_result();
    while ($row = $result_anniv->fetch_assoc()) {
        // Only add if years_of_service > 0
        if ($row['years_of_service'] > 0) {
            if ($row['a_day'] == $current_day) {
                $anniversaries_today[] = $row;
            } else {
                $anniversaries_this_month[] = $row;
            }
        }
    }
    $stmt_anniv->close();
}
// *** END Celebrations ***


// --- Fetch Pending Actions Counts ---

// Fetch Pending Expense Reports (for approvers)
if ($is_approver) {
    $sql_reports = "SELECT COUNT(DISTINCT r.id) 
                    FROM expense_reports r
                    JOIN users u ON r.user_id = u.id
                    WHERE u.approver_id = ? AND r.status = 'pending_approval' AND r.company_id = ?";
    if($stmt_reports = $conn->prepare($sql_reports)){
        $stmt_reports->bind_param("ii", $user_id, $company_id_context);
        $stmt_reports->execute();
        $stmt_reports->bind_result($pending_reports_count);
        $stmt_reports->fetch();
        $stmt_reports->close();
    }
}

// Fetch Reports to Verify (for admin/accounts)
if ($is_admin) { // Assuming admin can also verify, or use check_role('accounts')
    // FIXED: Changed r.company_id to company_id
    $sql_verify = "SELECT COUNT(id) FROM expense_reports WHERE status = 'pending_verification' AND company_id = ?";
    if($stmt_verify = $conn->prepare($sql_verify)){
        $stmt_verify->bind_param("i", $company_id_context);
        $stmt_verify->execute();
        $stmt_verify->bind_result($reports_to_verify_count);
        $stmt_verify->fetch();
        $stmt_verify->close();
    }
}

// Fetch Pending BTL Proposals (for BTL approvers)
if ($is_btl_approver) {
    $sql_btl = "SELECT COUNT(id) FROM btl_proposals WHERE (status = 'Pending L1 Approval' OR status = 'Pending L2 Approval') AND company_id = ?";
    if($stmt_btl = $conn->prepare($sql_btl)){
        $stmt_btl->bind_param("i", $company_id_context);
        $stmt_btl->execute();
        $stmt_btl->bind_result($pending_btl_count);
        $stmt_btl->fetch();
        $stmt_btl->close();
    }
}

// Fetch Pending Purchase Order Requests (for order_team)
if ($is_order_team) {
    $sql_po = "SELECT COUNT(id) FROM purchase_orders WHERE status = 'New Request' AND company_id = ?";
    if($stmt_po = $conn->prepare($sql_po)){
        $stmt_po->bind_param("i", $company_id_context);
        $stmt_po->execute();
        $stmt_po->bind_result($pending_po_count);
        $stmt_po->fetch();
        $stmt_po->close();
    }
}

// Fetch Pending Stock Transfer Counts 
// Only run these queries if the user is linked to a GATI location
if ($user_gati_location) {
    // 1. Incoming requests for my store (Pending)
    $sql_stock_in = "SELECT COUNT(id) FROM internal_stock_orders 
                     WHERE sender_location_name = ? AND status = 'Pending' AND company_id = ?";
    if($stmt_stock_in = $conn->prepare($sql_stock_in)) {
        $stmt_stock_in->bind_param("si", $user_gati_location, $company_id_context);
        $stmt_stock_in->execute();
        $stmt_stock_in->bind_result($pending_stock_incoming_count);
        $stmt_stock_in->fetch();
        $stmt_stock_in->close();
    }

    // 2. Shipped items for me to receive (Shipped)
    $sql_stock_out = "SELECT COUNT(id) FROM internal_stock_orders 
                      WHERE requester_user_id = ? AND status = 'Shipped' AND company_id = ?";
    if($stmt_stock_out = $conn->prepare($sql_stock_out)) {
        $stmt_stock_out->bind_param("ii", $user_id, $company_id_context);
        $stmt_stock_out->execute();
        $stmt_stock_out->bind_result($pending_stock_shipped_count);
        $stmt_stock_out->fetch();
        $stmt_stock_out->close();
    }
}
// *** END Stock Count Fetch ***


// Fetch Announcements
$announcements = [];
$sql_announcements = "SELECT title, content, post_date FROM announcements WHERE company_id = ? AND is_active = 1 ORDER BY post_date DESC LIMIT 3";
if($stmt_ann = $conn->prepare($sql_announcements)){
    $stmt_ann->bind_param("i", $company_id_context);
    $stmt_ann->execute();
    $result_ann = $stmt_ann->get_result();
    while($row = $result_ann->fetch_assoc()){
        $announcements[] = $row;
    }
    $stmt_ann->close();
}


// Calculate total pending actions
$total_pending_actions = $pending_reports_count + $reports_to_verify_count + $pending_btl_count + $pending_po_count + $pending_stock_incoming_count + $pending_stock_shipped_count;

require_once 'includes/header.php';
?>

<style>
    :root {
        --bg-light: #f8f9fa; /* Bootstrap's light gray */
        --bg-white: #ffffff;
        --text-primary: #212529; /* Bootstrap's dark */
        --text-secondary: #6c757d; /* Bootstrap's secondary */
        --border-color: #e9ecef; /* Bootstrap's border color */
        --accent-primary: #0d6efd;
        --accent-success: #198754;
        --accent-info: #0dcaf0;
        --accent-warning: #ffc107;
        --accent-danger: #dc3545;
        --accent-secondary: #6c757d;
        
        /* Soft light colors for backgrounds */
        --accent-primary-light: rgba(13, 110, 253, 0.1);
        --accent-success-light: rgba(25, 135, 84, 0.1);
        --accent-info-light: rgba(13, 202, 240, 0.1);
        --accent-warning-light: rgba(255, 193, 7, 0.1);
        --accent-danger-light: rgba(220, 53, 69, 0.1);
        --accent-secondary-light: rgba(108, 117, 125, 0.1);

        /* Soft dark colors for text on light backgrounds */
        --accent-danger-dark: #58151c;
        --accent-warning-dark: #664d03;
        --accent-info-dark: #055160;
        --accent-success-dark: #0a3622;
    }

    body {
        background-color: var(--bg-light) !important;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol"; /* Modern System Font Stack */
    }

    /* Override default card styles for a more modern look */
    .card {
        border: none; /* Remove default border */
        border-radius: 0.75rem; /* Softer corners */
        box-shadow: 0 4px 12px rgba(0,0,0,0.05); /* Softer, larger shadow */
        transition: all 0.2s ease-in-out;
    }

    .card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.07);
    }
    
    .card-header {
        background-color: var(--bg-white);
        border-bottom: 1px solid var(--border-color);
        font-weight: 600; /* Bolder headers */
    }

    /* Style the Portal Cards */
    .portal-card .portal-icon {
        width: 48px;
        height: 48px;
        padding: 10px;
        border-radius: 0.75rem; /* Match card radius */
        margin-bottom: 0.75rem;
    }
    
    /* Icon Color Mapping */
    .portal-card .text-primary { color: var(--accent-primary) !important; }
    .portal-card .text-success { color: var(--accent-success) !important; }
    .portal-card .text-info { color: var(--accent-info) !important; }
    .portal-card .text-warning { color: var(--accent-warning) !important; }
    .portal-card .text-danger { color: var(--accent-danger) !important; }
    .portal-card .text-muted { color: var(--accent-secondary) !important; }
    
    /* Icon Background Mapping */
    .portal-card .bg-primary-light { background-color: var(--accent-primary-light); }
    .portal-card .bg-success-light { background-color: var(--accent-success-light); }
    .portal-card .bg-info-light { background-color: var(--accent-info-light); }
    .portal-card .bg-warning-light { background-color: var(--accent-warning-light); }
    .portal-card .bg-danger-light { background-color: var(--accent-danger-light); }
    .portal-card .bg-secondary-light { background-color: var(--accent-secondary-light); }

    /* Pending Actions List */
    .list-group-item-action {
        transition: all 0.2s ease-in-out;
        font-size: 0.9rem;
    }
    .list-group-item-action:hover,
    .list-group-item-action:focus {
        background-color: var(--bg-light);
        transform: translateX(4px);
    }
    
    /* Soft Badges for pending actions */
    .badge.bg-danger { background-color: var(--accent-danger-light) !important; color: var(--accent-danger-dark) !important; }
    .badge.bg-warning { background-color: var(--accent-warning-light) !important; color: var(--accent-warning-dark) !important; }
    .badge.bg-info { background-color: var(--accent-info-light) !important; color: var(--accent-info-dark) !important; }
    .badge.bg-success { background-color: var(--accent-success-light) !important; color: var(--accent-success-dark) !important; }

    /* Welcome Header Badge */
    .welcome-badge {
        background-color: var(--accent-danger-light) !important;
        color: var(--accent-danger-dark) !important;
        font-size: 0.9rem;
        font-weight: 600;
    }

    /* Birthday/Anniversary Cards */
    .celebration-card-body {
        max-height: 250px;
        overflow-y: auto;
    }
    .badge.bg-success-light {
        background-color: var(--accent-success-light) !important;
        color: var(--accent-success-dark) !important;
    }
    .text-success-dark { color: var(--accent-success-dark); }

    /* Announcements */
    .announcement-item:not(:first-child) {
        border-top: 1px solid var(--border-color);
        padding-top: 1rem;
    }
    .announcement-item {
        padding-bottom: 1rem;
    }
    .announcement-item:last-child {
        padding-bottom: 0;
    }
    
    /* STYLES FOR VIBE-LIKE LAYOUT (Icon Grid) */
    .portal-icon-grid {
        margin-top: 2rem;
    }
    .portal-icon-grid .portal-card-small {
        text-align: center;
        padding: 1rem;
        height: auto;
        box-shadow: none;
        border: none;
        background: transparent;
        transition: transform 0.2s;
    }
    .portal-icon-grid .portal-card-small:hover {
        transform: translateY(-5px);
        box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        background: var(--bg-white);
    }
    .portal-icon-grid .portal-icon-small {
        width: 60px;
        height: 60px;
        padding: 12px;
        border-radius: 10px;
        margin: 0 auto 0.5rem;
        stroke-width: 1.5px;
        display: block;
    }
</style>
<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h2">Welcome, <?php echo htmlspecialchars($username); ?>!</h1>
        <?php if ($total_pending_actions > 0): ?>
            <span class="badge welcome-badge p-2 rounded-pill">
                <i data-lucide="bell" style="width:16px; height:16px; vertical-align: -2px;" class="me-1"></i> <?php echo $total_pending_actions; ?> Pending Action<?php echo ($total_pending_actions > 1 ? 's' : ''); ?>
            </span>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            
             <h4 class="mb-3">Pending Actions</h4>
            <div class="card mb-4">
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        
                        <?php if (!$gati_column_exists && $is_admin): ?>
                             <li class="list-group-item list-group-item-action list-group-item-danger d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-bold">Stock Module Error</div>
                                    <small class="text-danger">`gati_location_name` column missing. Please run `fix_database.sql`.</small>
                                </div>
                                <i data-lucide="alert-triangle" class="text-danger"></i>
                            </li>
                        <?php endif; ?>

                        <?php if ($pending_stock_incoming_count > 0): ?>
                        <a href="stock_transfer/index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">Incoming Stock Requests</div>
                                <small class="text-muted">You have new requests for items from your store.</small>
                            </div>
                            <span class="badge bg-warning rounded-pill"><?php echo $pending_stock_incoming_count; ?></span>
                        </a>
                        <?php endif; ?>

                        <?php if ($pending_stock_shipped_count > 0): ?>
                        <a href="stock_transfer/index.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">Shipped Items In-Transit</div>
                                <small class="text-muted">Stock you requested has been shipped. Please confirm receipt.</small>
                            </div>
                            <span class="badge bg-info rounded-pill"><?php echo $pending_stock_shipped_count; ?></span>
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($is_approver && $pending_reports_count > 0): ?>
                        <a href="admin/reports.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">Expense Reports to Approve</div>
                                <small class="text-muted">You have reports awaiting your review.</small>
                            </div>
                            <span class="badge bg-danger rounded-pill"><?php echo $pending_reports_count; ?></span>
                        </a>
                        <?php endif; ?>

                        <?php if ($is_admin && $reports_to_verify_count > 0): ?>
                        <a href="admin/reports.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">Expenses to Verify</div>
                                <small class="text-muted">You have approved reports to verify for payment.</small>
                            </div>
                            <span class="badge bg-info rounded-pill"><?php echo $reports_to_verify_count; ?></span>
                        </a>
                        <?php endif; ?>

                        <?php if ($is_btl_approver && $pending_btl_count > 0): ?>
                        <a href="btl/review.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">BTL Proposals to Review</div>
                                <small class="text-muted">New marketing proposals are awaiting approval.</small>
                            </div>
                            <span class="badge bg-success rounded-pill"><?php echo $pending_btl_count; ?></span>
                        </a>
                        <?php endif; ?>
                        
                        <?php if ($is_order_team && $pending_po_count > 0): ?>
                        <a href="purchase/pipeline.php" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-bold">New Purchase Requests</div>
                                <small class="text-muted">New POs need to be placed with vendors.</small>
                            </div>
                            <span class="badge bg-warning rounded-pill"><?php echo $pending_po_count; ?></span>
                        </a>
                        <?php endif; ?>

                        <?php if ($total_pending_actions == 0): ?>
                            <li class="list-group-item">
                                <div class="d-flex align-items-center text-muted">
                                    <i data-lucide="check-circle" class="me-2 text-success"></i>
                                    <span>No pending actions. You're all caught up!</span>
                                </div>
                            </li>
                        <?php endif; ?>
                    </div>
                </div>
            </div> 
            
            <h4 class="mb-3 mt-4">Main Portals</h4>
            
            <div class="row g-3 portal-icon-grid">
                
                <div class="col-md-4 col-6">
                    <a href="employee/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="credit-card" class="portal-icon-small text-primary bg-primary-light"></i>
                        <div class="fw-bold text-dark">Expenses</div>
                    </a>
                </div>
                
                <div class="col-md-4 col-6">
                    <a href="btl/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="megaphone" class="portal-icon-small text-success bg-success-light"></i>
                        <div class="fw-bold text-dark">BTL Marketing</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="learning/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="graduation-cap" class="portal-icon-small text-info bg-info-light"></i>
                        <div class="fw-bold text-dark">Learning</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="purchase/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="shopping-cart" class="portal-icon-small text-warning bg-warning-light"></i>
                        <div class="fw-bold text-dark">Purchasing</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="tasks/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="list-checks" class="portal-icon-small text-muted bg-secondary-light"></i>
                        <div class="fw-bold text-dark">Task Management</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="reports/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="bar-chart-2" class="portal-icon-small text-danger bg-danger-light"></i>
                        <div class="fw-bold text-dark">Store Reports</div>
                    </a>
                </div>
                
                <div class="col-md-4 col-6">
                    <a href="tools/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="wrench" class="portal-icon-small text-primary bg-primary-light"></i>
                        <div class="fw-bold text-dark">Tools & Stock</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="stock_transfer/index.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="send" class="portal-icon-small text-info bg-info-light"></i>
                        <div class="fw-bold text-dark">Stock Transfer (ISO)</div>
                    </a>
                </div>

                <div class="col-md-4 col-6">
                    <a href="tools/stocklookup.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="search" class="portal-icon-small text-warning bg-warning-light"></i>
                        <div class="fw-bold text-dark">Stock Lookup</div>
                    </a>
                </div>
                
                <?php if ($can_manage_referrals): ?>
                <div class="col-md-4 col-6">
                    <a href="admin/manage_referrals.php" class="card portal-card-small text-decoration-none h-100">
                        <i data-lucide="gift" class="portal-icon-small text-success bg-success-light"></i>
                        <div class="fw-bold text-dark">Referral Program</div>
                    </a>
                </div>
                <?php endif; ?>
                
            </div> </div> 

        <div class="col-lg-4">
            
            <div class="row g-4 mb-4">
                <div class="col-md-12 col-lg-12">
                    <div class="card h-100">
                        <div class="card-header bg-white d-flex align-items-center">
                            <i data-lucide="cake" class="me-2 text-primary"></i>
                            <h5 class="mb-0">Birthdays (<?php echo date('F'); ?>)</h5>
                        </div>
                        <div class="card-body celebration-card-body">
                            <?php if (empty($birthdays_today) && empty($birthdays_this_month)): ?>
                                <p class="text-muted text-center pt-3">No birthdays this month.</p>
                            <?php else: ?>
                                <?php if (!empty($birthdays_today)): ?>
                                    <h6 class="text-primary fw-bold">Today's Birthdays!</h6>
                                    <ul class="list-unstyled mb-3">
                                        <?php foreach ($birthdays_today as $user): ?>
                                            <li class="mb-1 d-flex align-items-center">
                                                <i data-lucide="party-popper" class="me-2 text-warning flex-shrink-0" style="width:16px;"></i> 
                                                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong> 
                                                <span class="text-muted ms-auto small"><?php echo date('M jS', strtotime($user['dob'])); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                
                                <?php if (!empty($birthdays_this_month)): ?>
                                    <h6 class="text-muted <?php echo !empty($birthdays_today) ? 'border-top pt-3' : ''; ?>">Upcoming Birthdays</h6>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($birthdays_this_month as $user): ?>
                                            <li class="mb-1 d-flex justify-content-between">
                                                <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                                                <span class="text-muted small"><?php echo date('M jS', strtotime($user['dob'])); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-12 col-lg-12">
                    <div class="card h-100">
                        <div class="card-header bg-white d-flex align-items-center">
                            <i data-lucide="award" class="me-2 text-success"></i>
                            <h5 class="mb-0">Work Anniversaries (<?php echo date('F'); ?>)</h5>
                        </div>
                        <div class="card-body celebration-card-body">
                            <?php if (empty($anniversaries_today) && empty($anniversaries_this_month)): ?>
                                <p class="text-muted text-center pt-3">No anniversaries this month.</p>
                            <?php else: ?>
                                <?php if (!empty($anniversaries_today)): ?>
                                    <h6 class="text-success fw-bold">Today's Anniversaries!</h6>
                                    <ul class="list-unstyled mb-3">
                                        <?php foreach ($anniversaries_today as $user): ?>
                                            <li class="mb-1 d-flex align-items-center">
                                                <i data-lucide="sparkles" class="me-2 text-warning flex-shrink-0" style="width:16px;"></i> 
                                                <strong><?php echo htmlspecialchars($user['full_name']); ?></strong>
                                                <span class="badge bg-success-light rounded-pill ms-auto"><?php echo htmlspecialchars($user['years_of_service']); ?> Year<?php echo ($user['years_of_service'] > 1 ? 's' : ''); ?>!</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                
                                <?php if (!empty($anniversaries_this_month)): ?>
                                    <h6 class="text-muted <?php echo !empty($anniversaries_today) ? 'border-top pt-3' : ''; ?>">Upcoming Anniversaries</h6>
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($anniversaries_this_month as $user): ?>
                                            <li class="mb-1 d-flex justify-content-between">
                                                <span><?php echo htmlspecialchars($user['full_name']); ?></span>
                                                <span class="text-muted small"><?php echo date('M jS', strtotime($user['doj'])); ?> (<?php echo htmlspecialchars($user['years_of_service']); ?> Year<?php echo ($user['years_of_service'] > 1 ? 's' : ''); ?>)</span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header">
                    Announcements
                </div>
                <div class="card-body">
                    <?php if (empty($announcements)): ?>
                        <p class="text-muted">No recent announcements.</p>
                    <?php else: ?>
                        <?php foreach ($announcements as $index => $ann): ?>
                            <div class="announcement-item">
                                <h6 class="fw-bold"><?php echo htmlspecialchars($ann['title']); ?></h6>
                                <p class="small text-muted mb-1">Posted on <?php echo date("F j, Y", strtotime($ann['post_date'])); ?></p>
                                <p class="card-text small"><?php echo nl2br(htmlspecialchars($ann['content'])); ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div> 
    </div> 
</div> 

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>