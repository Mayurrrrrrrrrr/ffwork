<?php
// Path: D:\xampp\htdocs\Companyportal\admin\update_referral.php

// 1. Include the MAIN portal's init file
require_once __DIR__ . '/../includes/init.php';

// 2. Security Check: Ensure user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

// 3. Permission Check: Only staff should see this
$allowed_roles = ['admin', 'accounts', 'approver', 'sales_team'];
if (!has_any_role($allowed_roles)) {
    header("location: " . BASE_URL . "portal_home.php");
    exit;
}

// 4. Get Referral ID from URL
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("location: " . BASE_URL . "admin/manage_referrals.php?error=missing_id");
    exit;
}
$referral_id = (int)$_GET['id'];
$admin_user_id = $_SESSION['user_id'];
$admin_full_name = $_SESSION['full_name'];

// 5. Get current referral data
$sql_lead = "SELECT 
                r.*, 
                ref.full_name AS referrer_name, 
                ref.mobile_number AS referrer_mobile,
                s.store_name 
            FROM referrals r 
            JOIN referrers ref ON r.referrer_id = ref.id 
            LEFT JOIN stores s ON r.store_id = s.id
            WHERE r.id = ?";
            
$lead = null;
if ($stmt_lead = $conn->prepare($sql_lead)) {
    $stmt_lead->bind_param("i", $referral_id);
    $stmt_lead->execute();
    $result_lead = $stmt_lead->get_result();
    if ($result_lead->num_rows == 1) {
        $lead = $result_lead->fetch_assoc();
    }
    $stmt_lead->close();
}

if ($lead === null) {
    header("location: " . BASE_URL . "admin/manage_referrals.php?error=not_found");
    exit;
}

// 6. Security Check: Restrict view by store
$is_admin_or_accounts = has_any_role(['admin', 'accounts']);
$user_store_id = $_SESSION['store_id'] ?? 0;

if (!$is_admin_or_accounts && $lead['store_id'] != $user_store_id) {
    header("location: " . BASE_URL . "admin/manage_referrals.php?error=permission_denied");
    exit;
}

// 7. Handle Form Submission (POST Request)
$errors = [];
$success_message = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // Sanitize new input
    $new_status = trim($_POST['status']);
    $new_purchase_amount = !empty($_POST['purchase_amount']) ? (float)$_POST['purchase_amount'] : 0.00;
    $new_points_earned = !empty($_POST['points_earned']) ? (int)$_POST['points_earned'] : 0;
    $new_last_contact_date = !empty($_POST['last_contact_date']) ? trim($_POST['last_contact_date']) : null;
    $new_last_visit_date = !empty($_POST['last_visit_date']) ? trim($_POST['last_visit_date']) : null;
    $new_staff_notes = trim($_POST['staff_notes']);
    
    // Get old values for comparison
    $old_status = $lead['status'];
    $old_purchase_amount = (float)$lead['purchase_amount'];
    $old_points_earned = (int)$lead['points_earned'];
    $old_last_contact_date = $lead['last_contact_date'];
    $old_last_visit_date = $lead['last_visit_date'];
    $old_staff_notes = $lead['staff_notes'];
    
    $changes = []; // To store audit logs
    
    // --- Build Audit Log ---
    if ($new_status !== $old_status) {
        $changes[] = ['action' => 'Status Change', 'old_value' => $old_status, 'new_value' => $new_status];
    }
    if ($new_purchase_amount !== $old_purchase_amount) {
        $changes[] = ['action' => 'Purchase Amount Update', 'old_value' => $old_purchase_amount, 'new_value' => $new_purchase_amount];
    }
    if ($new_points_earned !== $old_points_earned) {
        $changes[] = ['action' => 'Points Update', 'old_value' => $old_points_earned, 'new_value' => $new_points_earned];
    }
    if ($new_last_contact_date !== $old_last_contact_date) {
        $changes[] = ['action' => 'Last Contact Date', 'old_value' => $old_last_contact_date, 'new_value' => $new_last_contact_date];
    }
     if ($new_last_visit_date !== $old_last_visit_date) {
        $changes[] = ['action' => 'Last Visit Date', 'old_value' => $old_last_visit_date, 'new_value' => $new_last_visit_date];
    }
    if ($new_staff_notes !== $old_staff_notes) {
        $changes[] = ['action' => 'Staff Notes Update', 'old_value' => '...', 'new_value' => '...'];
    }
    // --- End Build Audit Log ---
    
    $new_points_awarded_at = $lead['points_awarded_at'];
    $is_awarding_points = false;
    
    if ($new_status == 'Points Awarded' && $old_status != 'Points Awarded') {
        $new_points_awarded_at = date('Y-m-d H:i:s');
        $is_awarding_points = true;
    }

    if (empty($changes)) {
        $success_message = "No changes were detected.";
    } else {
        
        $conn->begin_transaction();
        try {
            // Step A: Update the referral record
            $sql_update = "UPDATE referrals SET 
                            status = ?, 
                            purchase_amount = ?, 
                            points_earned = ?, 
                            points_awarded_at = ?,
                            last_contact_date = ?,
                            last_visit_date = ?,
                            staff_notes = ?,
                            updated_by_user_id = ?
                           WHERE id = ?";
            
            $stmt_update = $conn->prepare($sql_update);
            $stmt_update->bind_param(
                "sdissssii",
                $new_status,
                $new_purchase_amount,
                $new_points_earned,
                $new_points_awarded_at,
                $new_last_contact_date,
                $new_last_visit_date,
                $new_staff_notes,
                $admin_user_id,
                $referral_id
            );
            $stmt_update->execute();
            $stmt_update->close(); // Close statement
            
            // --- NEW: Step B: If awarding points, update the referrer's main balance ---
            if ($is_awarding_points && $new_points_earned > 0) {
                $sql_update_referrer = "UPDATE referrers SET total_points_balance = total_points_balance + ? WHERE id = ?";
                $stmt_update_referrer = $conn->prepare($sql_update_referrer);
                // $lead['referrer_id'] comes from the initial query
                $stmt_update_referrer->bind_param("ii", $new_points_earned, $lead['referrer_id']);
                $stmt_update_referrer->execute();
                $stmt_update_referrer->close(); // Close statement

                // Add this action to the audit log
                $changes[] = [
                    'action' => 'Points Transferred', 
                    'old_value' => 'N/A', 
                    'new_value' => "{$new_points_earned} points added to referrer ID {$lead['referrer_id']}"
                ];
            }
            // --- END NEW ---

            // Step C: Insert all changes into the audit log
            $sql_log = "INSERT INTO referral_audit_log (referral_id, user_id, action, old_value, new_value) 
                        VALUES (?, ?, ?, ?, ?)";
            $stmt_log = $conn->prepare($sql_log);
            
            foreach ($changes as $change) {
                $stmt_log->bind_param(
                    "iisss",
                    $referral_id,
                    $admin_user_id,
                    $change['action'],
                    $change['old_value'],
                    $change['new_value']
                );
                $stmt_log->execute();
            }
            $stmt_log->close(); // Close statement
            
            $conn->commit();
            
            $_SESSION['flash_message'] = "Referral #{$referral_id} has been updated successfully.";
            header("location: " . BASE_URL . "admin/manage_referrals.php");
            exit;
            
        } catch (mysqli_sql_exception $exception) {
            $conn->rollback();
            $errors['general'] = "A database error occurred. " . $exception->getMessage();
        }
    }
}

// 8. Fetch Audit Log History
$audit_logs = [];
$sql_logs = "SELECT a.*, u.full_name AS admin_name 
             FROM referral_audit_log a 
             LEFT JOIN users u ON a.user_id = u.id 
             WHERE a.referral_id = ? 
             ORDER BY a.timestamp DESC";
if ($stmt_logs = $conn->prepare($sql_logs)) {
    $stmt_logs->bind_param("i", $referral_id);
    $stmt_logs->execute();
    $result_logs = $stmt_logs->get_result();
    $audit_logs = $result_logs->fetch_all(MYSQLI_ASSOC);
    $stmt_logs->close();
}

// 9. Include the MAIN portal's header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-10">
            
            <a href="<?php echo BASE_URL; ?>admin/manage_referrals.php" class="text-decoration-none mb-3 d-inline-block">
                <i data-lucide="arrow-left" class="me-1"></i> Back to All Referrals
            </a>

            <h1 class="h3 mb-3">Update Referral #<?php echo $referral_id; ?></h1>

            <?php if (!empty($errors['general'])): ?>
                <div class="alert alert-danger"><?php echo $errors['general']; ?></div>
            <?php endif; ?>
            
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]) . "?id=" . $referral_id; ?>" method="post">
                <div class="row">
                    <div class="col-md-7">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Invitee Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6 mb-3">
                                        <strong>Name:</strong><br>
                                        <?php echo htmlspecialchars($lead['invitee_name']); ?>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <strong>Contact:</strong><br>
                                        <?php echo htmlspecialchars($lead['invitee_contact']); ?>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <strong>Gender:</strong><br>
                                        <?php echo htmlspecialchars($lead['invitee_gender'] ?: 'N/A'); ?>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <strong>Age:</strong><br>
                                        <?php echo htmlspecialchars($lead['invitee_age'] ?: 'N/A'); ?>
                                    </div>
                                    <div class="col-sm-12 mb-3">
                                        <strong>Address:</strong><br>
                                        <?php echo htmlspecialchars($lead['invitee_address'] ?: 'N/A'); ?>
                                    </div>
                                    <div class="col-sm-12">
                                        <strong>Interested In:</strong><br>
                                        <?php echo htmlspecialchars($lead['interested_items'] ?: 'N/A'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow-sm mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Referrer Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-sm-6 mb-3">
                                        <strong>Name:</strong><br>
                                        <?php echo htmlspecialchars($lead['referrer_name']); ?>
                                    </div>
                                    <div class="col-sm-6 mb-3">
                                        <strong>Mobile:</strong><br>
                                        <?php echo htmlspecialchars($lead['referrer_mobile']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card shadow-sm">
                            <div class="card-header">
                                <h5 class="mb-0">Referral History (Audit Log)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                    <table class="table mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Date</th>
                                                <th>Updated By</th>
                                                <th>Action</th>
                                                <th>Old Value</th>
                                                <th>New Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (empty($audit_logs)): ?>
                                                <tr>
                                                    <td colspan="5" class="text-center text-muted p-3">No history for this referral yet.</td>
                                                </tr>
                                            <?php else: ?>
                                                <?php foreach ($audit_logs as $log): ?>
                                                <tr>
                                                    <td><?php echo date('d M Y, g:i A', strtotime($log['timestamp'])); ?></td>
                                                    <td><?php echo htmlspecialchars($log['admin_name'] ?: 'N/A'); ?></td>
                                                    <td><span class="badge bg-info text-dark"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                                    <td><?php echo htmlspecialchars($log['old_value']); ?></td>
                                                    <td><?php echo htmlspecialchars($log['new_value']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-5">
                        <div class="card shadow-sm mb-4">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0">Staff Actions</h5>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <strong>Referred to Store:</strong><br>
                                    <?php echo htmlspecialchars($lead['store_name'] ?: 'N/A'); ?>
                                </div>
                                <div class="mb-3">
                                    <strong>Referral Code:</strong><br>
                                    <span class="badge bg-dark fs-6"><?php echo htmlspecialchars($lead['referral_code']); ?></span>
                                </div>
                                <hr>
                                
                                <div class="mb-3">
                                    <label for="status" class="form-label fw-bold">Referral Status</label>
                                    <select class="form-select" id="status" name="status">
                                        <?php 
                                        $statuses = ['Pending', 'Contacted', 'Purchased', 'Points Awarded', 'Expired', 'Rejected'];
                                        foreach ($statuses as $status):
                                        ?>
                                        <option value="<?php echo $status; ?>" <?php echo ($lead['status'] == $status) ? 'selected' : ''; ?>>
                                            <?php echo $status; ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label for="last_contact_date" class="form-label fw-bold">Last Contact Date</label>
                                    <input type="date" class="form-control" id="last_contact_date" name="last_contact_date" value="<?php echo htmlspecialchars($lead['last_contact_date']); ?>">
                                </div>
                                <div class="mb-3">
                                    <label for="last_visit_date" class="form-label fw-bold">Last Visit Date</label>
                                    <input type="date" class="form-control" id="last_visit_date" name="last_visit_date" value="<?php echo htmlspecialchars($lead['last_visit_date']); ?>">
                                </div>
                                
                                <div class="mb-3">
                                    <label for="purchase_amount" class="form-label fw-bold">Purchase Amount (Rs.)</label>
                                    <input type="number" step="0.01" class="form-control" id="purchase_amount" name="purchase_amount" value="<?php echo htmlspecialchars($lead['purchase_amount']); ?>" placeholder="0.00">
                                </div>

                                <div class="mb-3">
                                    <label for="points_earned" class="form-label fw-bold">Points to Award</label>
                                    <input type="number" class="form-control" id="points_earned" name="points_earned" value="<?php echo htmlspecialchars($lead['points_earned']); ?>" placeholder="Auto-calculates at 5%">
                                    <div class="form-text">Auto-calculates based on 5% of purchase amount.</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="staff_notes" class="form-label fw-bold">Staff Notes</label>
                                    <textarea class="form-control" id="staff_notes" name="staff_notes" rows="3" placeholder="Add a note about the last call or visit..."><?php echo htmlspecialchars($lead['staff_notes']); ?></textarea>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i data-lucide="save" class="me-1"></i> Save Changes
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const purchaseAmountInput = document.getElementById("purchase_amount");
    const pointsEarnedInput = document.getElementById("points_earned");

    if (purchaseAmountInput && pointsEarnedInput) {
        purchaseAmountInput.addEventListener("input", function() {
            // Auto-calculate 5% points as per the guide
            const purchaseAmount = parseFloat(purchaseAmountInput.value);
            if (!isNaN(purchaseAmount) && purchaseAmount > 0) {
                // 5% of purchase value
                const points = Math.round(purchaseAmount * 0.05);
                pointsEarnedInput.value = points;
            } else {
                pointsEarnedInput.value = 0;
            }
        });
    }
});
</script>

<?php
// 10. Include the MAIN portal's footer
require_once __DIR__ . '/../includes/footer.php'; 
?>