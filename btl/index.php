<?php
// Use the new BTL header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$proposals_awaiting_action = [];
$my_proposals = [];

// --- DATA FETCHING (ROLE-AWARE & COMPANY-AWARE) ---

// 1. Fetch proposals awaiting the user's action
// If user is L1 Approver
if (has_any_role(['approver', 'admin', 'platform_admin'])) {
    $sql_awaiting = "SELECT p.*, u.full_name as user_name, s.store_name as store_name_from_id
                     FROM btl_proposals p
                     JOIN users u ON p.user_id = u.id
                     LEFT JOIN stores s ON p.store_id = s.id
                     WHERE p.status = 'Pending L1 Approval' 
                     AND u.approver_id = ? 
                     AND p.company_id = ?";
    
    if($stmt_await = $conn->prepare($sql_awaiting)) {
        $stmt_await->bind_param("ii", $user_id, $company_id_context);
        if($stmt_await->execute()) {
            $result = $stmt_await->get_result();
            while($row = $result->fetch_assoc()) { 
                // Use proposal ID as key to prevent duplicates if user is also L2
                $proposals_awaiting_action[$row['id']] = $row; 
            }
        } else { $error_message = "Error fetching reports: ".$stmt_await->error; }
        $stmt_await->close();
    } else { $error_message = "DB Error: ".$conn->error; }
}

// If user is L2 Manager
if (has_any_role(['marketing_manager', 'admin', 'platform_admin'])) {
     $sql_awaiting_l2 = "SELECT p.*, u.full_name as user_name, s.store_name as store_name_from_id 
                         FROM btl_proposals p
                         JOIN users u ON p.user_id = u.id
                         LEFT JOIN stores s ON p.store_id = s.id
                         WHERE p.status = 'Pending L2 Approval' 
                         AND p.company_id = ?";
    
    if($stmt_await_l2 = $conn->prepare($sql_awaiting_l2)) {
        $stmt_await_l2->bind_param("i", $company_id_context);
        if($stmt_await_l2->execute()) {
            $result_l2 = $stmt_await_l2->get_result();
            while($row_l2 = $result_l2->fetch_assoc()) { 
                $proposals_awaiting_action[$row_l2['id']] = $row_l2; // Add to the same list
            }
        } else { $error_message = "Error fetching reports: ".$stmt_await_l2->error; }
        $stmt_await_l2->close();
    } else { $error_message = "DB Error: ".$conn->error; }
}


// 2. Fetch "My Proposals" (for employees)
if (check_role('employee')) {
    $sql_mine = "SELECT p.*, s.store_name as store_name_from_id 
                 FROM btl_proposals p
                 LEFT JOIN stores s ON p.store_id = s.id
                 WHERE p.user_id = ? AND p.company_id = ? ORDER BY p.proposal_date DESC LIMIT 10";
    if($stmt_mine = $conn->prepare($sql_mine)) {
        $stmt_mine->bind_param("ii", $user_id, $company_id_context);
         if($stmt_mine->execute()) {
            $result = $stmt_mine->get_result();
            while($row = $result->fetch_assoc()) { $my_proposals[] = $row; }
        } else { $error_message = "Error fetching my proposals: ".$stmt_mine->error; }
        $stmt_mine->close();
    } else { $error_message = "DB Error: ".$conn->error; }
}

// Helper for status badges (get_btl_status_badge is in init.php)

?>

<h2>BTL Marketing Dashboard</h2>
<p>Propose and track Below-The-Line marketing activities.</p>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- 1. Proposals Awaiting Your Action -->
<?php if (has_any_role(['approver', 'marketing_manager', 'admin', 'platform_admin'])): ?>
<div class="card mb-4">
    <div class="card-header"><h4>Proposals Awaiting Your Action</h4></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead>
                    <tr>
                        <th>Status</th>
                        <th>Proposed By</th>
                        <th>Store</th>
                        <th>Activity Type</th>
                        <th>Budget (Rs.)</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($proposals_awaiting_action)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No proposals are awaiting your action.</td></tr>
                    <?php else: ?>
                        <?php foreach($proposals_awaiting_action as $proposal): ?>
                        <tr>
                            <td><span class="badge <?php echo get_btl_status_badge($proposal['status']); ?>"><?php echo htmlspecialchars($proposal['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($proposal['user_name']); ?></td>
                            <td><?php echo htmlspecialchars($proposal['store_name_from_id']); ?></td>
                            <td><?php echo htmlspecialchars($proposal['activity_type']); ?></td>
                            <td><?php echo number_format($proposal['proposed_budget'], 2); ?></td>
                            <td><a href="btl/review.php?id=<?php echo $proposal['id']; ?>" class="btn btn-sm btn-primary">Review</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<!-- 2. My Proposals (for Employees) -->
<?php if (check_role('employee')): ?>
<div class="card mb-4">
    <div class="card-header"><h4>My Recent Proposals</h4></div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                 <thead>
                    <tr>
                        <th>Proposal Date</th>
                        <th>Store</th>
                        <th>Activity Type</th>
                        <th>Budget (Rs.)</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                     <?php if (empty($my_proposals)): ?>
                        <tr><td colspan="6" class="text-center text-muted">You have not submitted any proposals yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($my_proposals as $proposal): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($proposal['proposal_date']); ?></td>
                            <td><?php echo htmlspecialchars($proposal['store_name_from_id']); ?></td>
                            <td><?php echo htmlspecialchars($proposal['activity_type']); ?></td>
                            <td><?php echo number_format($proposal['proposed_budget'], 2); ?></td>
                            <td><span class="badge <?php echo get_btl_status_badge($proposal['status']); ?>"><?php echo htmlspecialchars($proposal['status']); ?></span></td>
                            <td>
                                <?php if($proposal['status'] == 'Draft'): ?>
                                    <a href="btl/propose.php?id=<?php echo $proposal['id']; ?>" class="btn btn-sm btn-warning">Edit Draft</a>
                                <?php else: ?>
                                    <a href="btl/review.php?id=<?php echo $proposal['id']; ?>" class="btn btn-sm btn-info">View</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


