<?php
ob_start(); // Start output buffering
require_once '../includes/init.php'; // Includes $conn, session checks, functions

// -- SECURITY CHECK: ENSURE USER IS LOGGED IN AND HAS BTL ROLE --
// Allow employees, admins, or approvers to propose BTL activities
if (!check_role('employee') && !check_role('admin') && !check_role('approver')) {
    header("location: " . BASE_URL . "login.php?error=permission_denied");
    exit;
}

$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null;

if (!$company_id || !$user_id) {
    header("location: " . BASE_URL . "login.php?error=session_error");
    exit;
}

$error_message = '';
$success_message = '';

// Fetch stores for dropdown
$stores = [];
// **MODIFIED: Fetch stores this user is assigned to (or all if admin)**
$sql_stores = "SELECT s.id, s.store_name FROM stores s
               JOIN users u ON s.id = u.store_id
               WHERE u.id = ? AND s.company_id = ? AND s.is_active = 1 
               UNION
               SELECT s.id, s.store_name FROM stores s
               WHERE ? = 1 AND s.company_id = ? AND s.is_active = 1
               ORDER BY store_name";
if($stmt_stores = $conn->prepare($sql_stores)){
    $is_admin_check = (int)has_any_role(['admin', 'platform_admin']); // Assumes has_any_role is available via init.php
    $stmt_stores->bind_param("iiii", $user_id, $company_id, $is_admin_check, $company_id);
    if($stmt_stores->execute()){
        $result_stores = $stmt_stores->get_result();
        while($row_store = $result_stores->fetch_assoc()){ 
            $stores[] = $row_store; 
        }
    } else { $error_message = "Error fetching stores list: " . $stmt_stores->error; }
    $stmt_stores->close();
} else { $error_message = "Error preparing stores list: " . $conn->error; }


// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_proposal'])) {
    $store_id = $_POST['store_id'];
    $activity_type = trim($_POST['activity_type']);
    $proposal_date = $_POST['proposal_date'];
    $proposed_budget = $_POST['proposed_budget'];
    $details = trim($_POST['details']);
    
    // Find approver
    $approver_id = null;
    $sql_approver = "SELECT approver_id FROM users WHERE id = ?";
    $stmt_approver = $conn->prepare($sql_approver);
    $stmt_approver->bind_param("i", $user_id);
    $stmt_approver->execute();
    $approver_id = $stmt_approver->get_result()->fetch_assoc()['approver_id'] ?? null;
    $stmt_approver->close();

    // Validation
    if (empty($store_id) || empty($activity_type) || empty($proposal_date) || !is_numeric($proposed_budget) || $proposed_budget <= 0) {
        $error_message = "Please fill in all required fields (Store, Activity Type, Date, Budget).";
    } elseif (!$approver_id) {
         $error_message = "You are not assigned an approver. Please contact your administrator.";
    } else {
        // Verify store_id belongs to this user/company
        $valid_store = false;
        foreach($stores as $store) { if($store['id'] == $store_id) $valid_store = true; }
        
        if(!$valid_store) {
            $error_message = "Invalid Store / Cost Center selected.";
        } else {
            $sql = "INSERT INTO btl_proposals (company_id, user_id, store_id, activity_type, proposal_date, proposed_budget, details, status, approver_id, created_at, is_read) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'pending_approval', ?, NOW(), 0)";
            
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("iiisssdi", $company_id, $user_id, $store_id, $activity_type, $proposal_date, $proposed_budget, $details, $approver_id);
                if ($stmt->execute()) {
                    $new_proposal_id = $conn->insert_id;
                    log_audit_action($conn, 'btl_proposal_submitted', "BTL Proposal '{$activity_type}' for Rs. {$proposed_budget} submitted.", $user_id, $company_id, 'btl_proposal', $new_proposal_id);
                    $success_message = "BTL proposal submitted successfully for approval.";
                    // Redirect to index after success
                    header("location: " . BASE_URL . "btl/index.php?success=proposed");
                    exit;
                } else {
                    $error_message = "Error submitting proposal: " . $stmt->error;
                    log_audit_action($conn, 'btl_proposal_failed', "BTL Proposal submission failed. Error: " . $stmt->error, $user_id, $company_id);
                }
                $stmt->close();
            } else {
                $error_message = "Database prepare error: " . $conn->error;
            }
        }
    }
}

// Include Header
require_once '../includes/header.php'; 
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Propose New BTL Activity</h2>
        <a href="btl/index.php" class="btn btn-secondary">Back to BTL Dashboard</a>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <form action="btl/propose.php" method="POST">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="store_id" class="form-label">Store / Cost Center*</label>
                        <select class="form-select" id="store_id" name="store_id" required>
                            <option value="">-- Select Store --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                            <?php endforeach; ?>
                             <?php if(empty($stores)): ?><option value="" disabled>No stores assigned to you. Contact Admin.</option><?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="activity_type" class="form-label">Activity Type*</label>
                        <input type="text" class="form-control" id="activity_type" name="activity_type" placeholder="e.g., In-store promotion, Local event" required>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="proposal_date" class="form-label">Proposed Date of Activity*</label>
                        <input type="date" class="form-control" id="proposal_date" name="proposal_date" min="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="proposed_budget" class="form-label">Proposed Budget (Rs.)*</label>
                        <input type="number" step="0.01" min="0.01" class="form-control" id="proposed_budget" name="proposed_budget" required>
                    </div>
                </div>
                <div class="mb-3">
                    <label for="details" class="form-label">Activity Details & Justification</label>
                    <textarea class="form-control" id="details" name="details" rows="5" placeholder="Describe the activity, objective, expected outcome, and breakdown of costs."></textarea>
                </div>
                <button type="submit" name="submit_proposal" class="btn btn-primary">Submit for Approval</button>
            </form>
        </div>
    </div>
</div>

<?php
if(isset($conn)) { $conn->close(); }
require_once '../includes/footer.php';
ob_end_flush(); // Send buffered output
?>


