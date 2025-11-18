<?php
// Use the new BTL header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER CAN VIEW/MANAGE --
// We allow employees to view their own submitted reports
// We allow approvers/managers/admins to view all
if (!check_role('employee')) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$proposal_id = $_GET['id'] ?? null;

if (!$proposal_id || !is_numeric($proposal_id)) {
    header("location: " . BASE_URL . "btl/index.php"); 
    exit;
}

// Check for success message from redirect
if(isset($_GET['success'])){
    $success_message = "Action completed successfully.";
}

// --- FORM HANDLING: APPROVE/REJECT PROPOSAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_taken = false;
    $error_occurred = false;
    $new_status = '';
    $log_action_type = '';
    $log_message = '';
    
    // L1 Approver Action
    if (isset($_POST['l1_action']) && has_any_role(['approver', 'admin', 'platform_admin'])) {
        $l1_remarks = trim($_POST['approval_remarks']);
        $l1_approver_id = $user_id;

        if ($_POST['l1_action'] == 'approve') {
            $new_status = 'Pending L2 Approval';
            $log_action_type = 'btl_l1_approved';
            $log_message = "L1 Approved. Remarks: {$l1_remarks}";
            $action_taken = true;
        } elseif ($_POST['l1_action'] == 'reject') {
            if(empty($l1_remarks)) { $error_message = "Remarks are required to reject a proposal."; }
            else {
                $new_status = 'Rejected';
                $log_action_type = 'btl_l1_rejected';
                $log_message = "L1 Rejected. Remarks: {$l1_remarks}";
                $action_taken = true;
            }
        }
        
        if($action_taken && empty($error_message)){
            $sql = "UPDATE btl_proposals SET status = ?, approval_remarks = ?, l1_approver_id = ? 
                    WHERE id = ? AND company_id = ? AND status = 'Pending L1 Approval'";
            if($stmt = $conn->prepare($sql)){
                $stmt->bind_param("ssiii", $new_status, $l1_remarks, $l1_approver_id, $proposal_id, $company_id_context);
                if(!$stmt->execute() || $stmt->affected_rows == 0) { $error_occurred = true; $error_message = "Failed to update status. Report may be in an invalid state."; }
                $stmt->close();
            } else { $error_occurred = true; $error_message = "DB Prepare Error: ".$conn->error; }
        }
    }
    // L2 Marketing Manager Action
    elseif (isset($_POST['l2_action']) && has_any_role(['marketing_manager', 'admin', 'platform_admin'])) {
        $l2_remarks = trim($_POST['approval_remarks']);
        $l2_manager_id = $user_id;

        if ($_POST['l2_action'] == 'approve') {
            $new_status = 'Approved';
            $log_action_type = 'btl_l2_approved';
            $log_message = "L2 Approved. Remarks: {$l2_remarks}";
            $action_taken = true;
        } elseif ($_POST['l2_action'] == 'reject') {
            if(empty($l2_remarks)) { $error_message = "Remarks are required to reject a proposal."; }
            else {
                $new_status = 'Rejected';
                $log_action_type = 'btl_l2_rejected';
                $log_message = "L2 Rejected. Remarks: {$l2_remarks}";
                $action_taken = true;
            }
        }

        if($action_taken && empty($error_message)){
            $sql = "UPDATE btl_proposals SET status = ?, approval_remarks = CONCAT(IFNULL(approval_remarks, ''), '\n--- L2 ---:\n', ?), l2_manager_id = ? 
                    WHERE id = ? AND company_id = ? AND status = 'Pending L2 Approval'";
            if($stmt = $conn->prepare($sql)){
                $stmt->bind_param("ssiii", $new_status, $l2_remarks, $l2_manager_id, $proposal_id, $company_id_context);
                if(!$stmt->execute() || $stmt->affected_rows == 0) { $error_occurred = true; $error_message = "Failed to update status. Report may be in an invalid state."; }
                $stmt->close();
            } else { $error_occurred = true; $error_message = "DB Prepare Error: ".$conn->error; }
        }
    }
    
    // Redirect or show error
    if($action_taken && !$error_occurred){
        log_audit_action($conn, $log_action_type, $log_message, $user_id, $company_id_context, 'btl_proposal', $proposal_id);
        header("location: " . BASE_URL . "btl/review.php?id={$proposal_id}&success=1");
        exit;
    }
}


// --- DATA FETCHING (for viewing) ---
$proposal_data = null;
$sql_fetch = "SELECT p.*, u.full_name as user_name, s.store_name as store_name_from_id,
              a.full_name as l1_approver_name, m.full_name as l2_manager_name
              FROM btl_proposals p
              JOIN users u ON p.user_id = u.id
              LEFT JOIN stores s ON p.store_id = s.id
              LEFT JOIN users a ON p.l1_approver_id = a.id
              LEFT JOIN users m ON p.l2_manager_id = m.id
              WHERE p.id = ? AND p.company_id = ?";
              
// Security: If user is just an employee, they must be the one who submitted it.
if (check_role('employee') && !has_any_role(['admin', 'approver', 'marketing_manager', 'platform_admin'])) {
    $sql_fetch .= " AND p.user_id = " . (int)$user_id;
}

if($stmt_fetch = $conn->prepare($sql_fetch)){
    $stmt_fetch->bind_param("ii", $proposal_id, $company_id_context);
    if($stmt_fetch->execute()){
        $result = $stmt_fetch->get_result();
        $proposal_data = $result->fetch_assoc();
        if(!$proposal_data) {
             $error_message = "Proposal not found or you do not have permission to view it.";
        }
    } else { $error_message = "Error fetching proposal: ".$stmt_fetch->error; }
    $stmt_fetch->close();
} else { $error_message = "DB Error: ".$conn->error; }

// Helper for status badges
if (!function_exists('get_btl_status_badge')) {
    function get_btl_status_badge($status) {
        switch($status) {
            case 'Approved': return 'bg-success';
            case 'Rejected': return 'bg-danger';
            case 'Pending L1 Approval': return 'bg-warning text-dark';
            case 'Pending L2 Approval': return 'bg-info text-dark';
            default: return 'bg-secondary'; // draft
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>BTL Proposal Review</h2>
    <a href="btl/index.php" class="btn btn-secondary btn-sm">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<?php if($proposal_data): // Only show if data was fetched ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Proposal Details (ID: <?php echo $proposal_data['id']; ?>)</h4>
        <span class="badge <?php echo get_btl_status_badge($proposal_data['status']); ?> fs-6">
            <?php echo htmlspecialchars($proposal_data['status']); ?>
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Store / Cost Center</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($proposal_data['store_name_from_id']); ?>" readonly>
            </div>
             <div class="col-md-6">
                <label class="form-label">Proposed By</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($proposal_data['user_name']); ?>" readonly>
            </div>
             <div class="col-md-6">
                <label class="form-label">Location of Activity</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($proposal_data['activity_location']); ?>" readonly>
            </div>
            <div class="col-md-3">
                <label class="form-label">Date of Proposal</label>
                <input type="date" class="form-control" value="<?php echo htmlspecialchars($proposal_data['proposal_date']); ?>" readonly>
            </div>
             <div class="col-md-3">
                <label class="form-label">Proposed Date of Activity</label>
                <input type="date" class="form-control" value="<?php echo htmlspecialchars($proposal_data['activity_date']); ?>" readonly>
            </div>
             <div class="col-md-12">
                <label class="form-label">Type of Activity</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($proposal_data['activity_type']); ?>" readonly>
            </div>
            <div class="col-12">
                <label class="form-label">Activity Summary & Expected Outcome</label>
                <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($proposal_data['activity_summary']); ?></textarea>
            </div>
             <div class="col-12">
                <label class="form-label">Requirements / Support Needed</label>
                <textarea class="form-control" rows="2" readonly><?php echo htmlspecialchars($proposal_data['requirements']); ?></textarea>
            </div>
             <div class="col-md-6">
                <label class="form-label">Proposed Budget (INR)</label>
                 <div class="input-group">
                    <span class="input-group-text">Rs.</span>
                    <input type="text" class="form-control" value="<?php echo number_format($proposal_data['proposed_budget'], 2); ?>" readonly>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- --- ACTION BOXES --- -->

<?php // L1 Approver Action Box
if ($proposal_data['status'] == 'Pending L1 Approval' && has_any_role(['approver', 'admin', 'platform_admin'])): ?>
<div class="card mt-4 border-warning">
    <div class="card-header bg-warning text-dark"><h4>Action: L1 Approval</h4></div>
    <div class="card-body">
        <form action="btl/review.php?id=<?php echo $proposal_id; ?>" method="post">
            <div class="mb-3">
                <label for="approval_remarks" class="form-label">Remarks (Required if rejecting)</label>
                <textarea class="form-control" id="approval_remarks" name="approval_remarks" rows="3"></textarea>
            </div>
            <button type="submit" name="l1_action" value="approve" class="btn btn-success btn-lg">Approve (Send to L2)</button>
            <button type="submit" name="l1_action" value="reject" class="btn btn-danger btn-lg">Reject</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php // L2 Manager Action Box
if ($proposal_data['status'] == 'Pending L2 Approval' && has_any_role(['marketing_manager', 'admin', 'platform_admin'])): ?>
<div class="card mt-4 border-info">
    <div class="card-header bg-info text-dark"><h4>Action: L2 (Manager) Approval</h4></div>
    <div class="card-body">
        <form action="btl/review.php?id=<?php echo $proposal_id; ?>" method="post">
            <div class="mb-3">
                <label for="approval_remarks" class="form-label">Remarks (Required if rejecting)</label>
                <textarea class="form-control" id="approval_remarks" name="approval_remarks" rows="3"></textarea>
            </div>
            <button type="submit" name="l2_action" value="approve" class="btn btn-success btn-lg">Final Approve</button>
            <button type="submit" name="l2_action" value="reject" class="btn btn-danger btn-lg">Reject</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- --- DISPLAY FINAL APPROVAL/REJECTION INFO --- -->
<?php if ($proposal_data['status'] == 'Approved' || $proposal_data['status'] == 'Rejected'): ?>
<div class="card mt-4">
    <div class="card-header"><h4>Approval Details</h4></div>
    <div class="card-body">
        <p><strong>L1 Approver:</strong> <?php echo htmlspecialchars($proposal_data['l1_approver_name'] ?? 'N/A'); ?></p>
        <p><strong>L2 Manager:</strong> <?php echo htmlspecialchars($proposal_data['l2_manager_name'] ?? 'N/A'); ?></p>
        <p><strong>Final Status:</strong> <?php echo htmlspecialchars($proposal_data['status']); ?></p>
        <label class="form-label">Remarks:</label>
        <textarea class="form-control" rows="4" readonly><?php echo htmlspecialchars($proposal_data['approval_remarks']); ?></textarea>
    </div>
</div>
<?php endif; ?>

<?php endif; // End if($proposal_data) ?>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



