<?php
// Use the new Learning Portal header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    header("location: " . BASE_URL . "learning/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$my_total_points = 0;
$rewards_list = [];

// --- ACTION HANDLING: REDEEM REWARD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['redeem_reward'])) {
    $reward_id = $_POST['reward_id'];

    if(!empty($reward_id)){
        // Use a transaction to make this an atomic operation
        $conn->begin_transaction();
        try {
            // 1. Get the reward's point cost and user's current points
            $sql_get_data = "
                SELECT 
                    r.points_cost,
                    (SELECT SUM(tr.score) FROM test_results tr JOIN course_assignments ca ON tr.assignment_id = ca.id WHERE ca.user_id = ?) as total_points,
                    (SELECT SUM(rr.points_cost) FROM reward_redemptions rr JOIN rewards r_inner ON rr.reward_id = r_inner.id WHERE rr.user_id = ?) as points_spent
                FROM rewards r
                WHERE r.id = ? AND r.company_id = ?
            ";
            $stmt_get = $conn->prepare($sql_get_data);
            $stmt_get->bind_param("iiii", $user_id, $user_id, $reward_id, $company_id_context);
            $stmt_get->execute();
            $result_data = $stmt_get->get_result();
            $data = $result_data->fetch_assoc();
            $stmt_get->close();

            if(!$data){
                throw new Exception("Reward not found.");
            }

            $points_cost = (int)$data['points_cost'];
            $available_points = (int)$data['total_points'] - (int)$data['points_spent'];
            
            // 2. Check if user has enough points
            if($available_points < $points_cost) {
                throw new Exception("You do not have enough points to redeem this reward.");
            }
            
            // 3. Insert into reward_redemptions
            // We store the points_cost at the time of redemption
            $sql_redeem = "INSERT INTO reward_redemptions (user_id, reward_id, points_cost, redeemed_at)
                           VALUES (?, ?, ?, NOW())";
            $stmt_redeem = $conn->prepare($sql_redeem);
            $stmt_redeem->bind_param("iii", $user_id, $reward_id, $points_cost);
            if(!$stmt_redeem->execute()){
                throw new Exception("Failed to redeem reward: " . $stmt_redeem->error);
            }
            $stmt_redeem->close();

            // 4. Commit transaction
            $conn->commit();
            $success_message = "Reward redeemed successfully! Your " . number_format($points_cost) . " points have been spent.";
            log_audit_action($conn, 'reward_redeemed', "User redeemed reward ID {$reward_id} for {$points_cost} points", $user_id, $company_id_context, 'reward', $reward_id);

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    } else {
        $error_message = "No reward selected.";
    }
}


// --- DATA FETCHING ---
// 1. Fetch all available rewards for this company
$sql_fetch = "SELECT * FROM rewards WHERE company_id = ? ORDER BY points_cost";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $company_id_context);
    if ($stmt_fetch->execute()) {
        $result = $stmt_fetch->get_result();
        while ($row = $result->fetch_assoc()) {
            $rewards_list[] = $row;
        }
    } else { $error_message = "Error fetching rewards."; }
    $stmt_fetch->close();
}

// 2. Fetch user's total earned points
$sql_points_total = "SELECT SUM(tr.score) as total_points 
                     FROM test_results tr
                     JOIN course_assignments ca ON tr.assignment_id = ca.id
                     WHERE ca.user_id = ? AND ca.company_id = ?";
if($stmt_points = $conn->prepare($sql_points_total)){
    $stmt_points->bind_param("ii", $user_id, $company_id_context);
    $stmt_points->execute();
    $my_total_points = $stmt_points->get_result()->fetch_assoc()['total_points'] ?? 0;
    $stmt_points->close();
}

// 3. Fetch user's total spent points
$sql_spent = "SELECT SUM(points_cost) as total_spent FROM reward_redemptions WHERE user_id = ?";
if($stmt_spent = $conn->prepare($sql_spent)){
    $stmt_spent->bind_param("i", $user_id);
    $stmt_spent->execute();
    $my_points_spent = $stmt_spent->get_result()->fetch_assoc()['total_spent'] ?? 0;
    $stmt_spent->close();
}

$my_available_points = $my_total_points - $my_points_spent;

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Rewards Center</h2>
    <a href="learning/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<!-- My Points -->
<div class="card bg-light mb-4">
    <div class="card-body text-center">
        <h5 class="text-muted">Your Available Points</h5>
        <p class="fs-1 fw-bold text-primary mb-0"><?php echo number_format($my_available_points); ?></p>
        <small class="text-muted">(Total Earned: <?php echo number_format($my_total_points); ?> | Total Spent: <?php echo number_format($my_points_spent); ?>)</small>
    </div>
</div>

<!-- Rewards List -->
<div class="row g-4">
    <?php if (empty($rewards_list)): ?>
        <div class="col-12">
            <p class="text-center text-muted">No rewards are available at this time. Please check back later.</p>
        </div>
    <?php else: ?>
        <?php foreach ($rewards_list as $reward): ?>
            <?php $can_redeem = $my_available_points >= $reward['points_cost']; ?>
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 <?php echo !$can_redeem ? 'bg-light' : 'border-primary shadow-sm'; ?>">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title"><?php echo htmlspecialchars($reward['title']); ?></h5>
                        <h6 class="card-subtitle mb-2 <?php echo $can_redeem ? 'text-primary' : 'text-muted'; ?>"><?php echo number_format($reward['points_cost']); ?> Points</h6>
                        <p class="card-text flex-grow-1"><?php echo htmlspecialchars($reward['description']); ?></p>
                        <form action="learning/rewards.php" method="post">
                            <input type="hidden" name="reward_id" value="<?php echo $reward['id']; ?>">
                            <button type="submit" name="redeem_reward" class="btn <?php echo $can_redeem ? 'btn-primary' : 'btn-secondary'; ?> w-100" 
                                    <?php if (!$can_redeem) echo 'disabled'; ?>
                                    onclick="return confirm('Redeem <?php echo htmlspecialchars($reward['title']); ?> for <?php echo number_format($reward['points_cost']); ?> points?');">
                                <?php echo $can_redeem ? 'Redeem' : 'Not Enough Points'; ?>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


