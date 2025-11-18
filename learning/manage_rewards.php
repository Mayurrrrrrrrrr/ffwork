<?php
// Use the new Learning Portal header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS A TRAINER/ADMIN --
if (!has_any_role(['trainer', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "learning/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$edit_reward = null; // Variable to hold reward data for editing

// --- ACTION HANDLING ---

// Handle Deleting a Reward
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_reward'])) {
    $reward_id_to_delete = $_POST['reward_id'];
    
    // We can add a check here to see if a reward has been redeemed, preventing deletion
    // For now, we will allow deletion.
    
    $sql_delete = "DELETE FROM rewards WHERE id = ? AND company_id = ?";
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("ii", $reward_id_to_delete, $company_id_context);
        if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
            $success_message = "Reward deleted successfully.";
            log_audit_action($conn, 'reward_deleted', "Admin deleted reward ID: {$reward_id_to_delete}", $user_id, $company_id_context, 'reward', $reward_id_to_delete);
        } else { $error_message = "Error deleting reward or reward not found."; }
        $stmt_delete->close();
    }
}

// Handle Adding or Editing a Reward
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_reward']) || isset($_POST['update_reward']))) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $points_cost = (int)$_POST['points_cost'];
    $reward_id_to_edit = $_POST['edit_reward_id'] ?? null;

    if (empty($title) || $points_cost <= 0) {
        $error_message = "Reward Title and a valid Points Cost (greater than 0) are required.";
    } else {
        if ($reward_id_to_edit) {
            // Update Existing Reward
            $sql = "UPDATE rewards SET title = ?, description = ?, points_cost = ? 
                    WHERE id = ? AND company_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssiii", $title, $description, $points_cost, $reward_id_to_edit, $company_id_context);
                if ($stmt->execute()) {
                    $success_message = "Reward updated successfully.";
                    log_audit_action($conn, 'reward_updated', "Admin updated reward ID: {$reward_id_to_edit}", $user_id, $company_id_context, 'reward', $reward_id_to_edit);
                } else { $error_message = "Error updating reward: " . $stmt->error; }
                $stmt->close();
            }
        } else {
            // Add New Reward
            $sql_insert = "INSERT INTO rewards (company_id, title, description, points_cost) VALUES (?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("issi", $company_id_context, $title, $description, $points_cost);
                if ($stmt_insert->execute()) {
                    $new_reward_id = $conn->insert_id;
                    $success_message = "Reward added successfully.";
                    log_audit_action($conn, 'reward_created', "Admin created reward: {$title}", $user_id, $company_id_context, 'reward', $new_reward_id);
                } else { $error_message = "Error adding reward: " . $stmt_insert->error; }
                $stmt_insert->close();
            }
        }
    }
    // If update/add failed, and we were editing, repopulate $edit_reward
    if(!empty($error_message) && $reward_id_to_edit) {
        $edit_reward = ['id' => $reward_id_to_edit, 'title' => $title, 'description' => $description, 'points_cost' => $points_cost];
    }
}

// Handle request to edit (load data into form)
if (isset($_GET['edit_id']) && empty($_POST)) { // Check empty POST
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT * FROM rewards WHERE id = ? AND company_id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("ii", $edit_id, $company_id_context);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_reward = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_reward) { $error_message = "Reward not found for editing."; }
    }
}

// --- DATA FETCHING ---
// Fetch all rewards for the current company
$rewards_list = [];
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

require_once 'includes/header.php';
?>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Manage Rewards</h2>
        <a href="learning/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Dashboard</a>
    </div>
    
    <p>Create and manage rewards that employees can redeem with their learning points.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Reward Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h4><?php echo $edit_reward ? 'Edit Reward' : 'Add New Reward'; ?></h4>
        </div>
        <div class="card-body">
            <form action="learning/manage_rewards.php" method="post">
                <?php if ($edit_reward): ?>
                    <input type="hidden" name="edit_reward_id" value="<?php echo $edit_reward['id']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="title" class="form-label">Reward Title</label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?php echo htmlspecialchars($edit_reward['title'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label for="points_cost" class="form-label">Points Cost</label>
                        <input type="number" class="form-control" id="points_cost" name="points_cost" min="1"
                               value="<?php echo htmlspecialchars($edit_reward['points_cost'] ?? ''); ?>" required>
                    </div>
                    <div class="col-12">
                        <label for="description" class="form-label">Description (Optional)</label>
                        <textarea class="form-control" id="description" name="description" rows="2"><?php echo htmlspecialchars($edit_reward['description'] ?? ''); ?></textarea>
                    </div>
                </div>
                <div class="mt-3">
                    <?php if ($edit_reward): ?>
                        <button type="submit" name="update_reward" class="btn btn-warning">Update Reward</button>
                        <a href="learning/manage_rewards.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php else: ?>
                        <button type="submit" name="add_reward" class="btn btn-primary">Add Reward</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Rewards List -->
    <div class="card">
        <div class="card-header">
            <h4>Existing Rewards</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Reward Title</th>
                            <th>Description</th>
                            <th>Points Cost</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rewards_list)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No rewards defined yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($rewards_list as $reward): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($reward['title']); ?></td>
                                    <td><?php echo htmlspecialchars($reward['description']); ?></td>
                                    <td><span class="badge bg-primary"><?php echo number_format($reward['points_cost']); ?> Pts</span></td>
                                    <td>
                                        <a href="learning/manage_rewards.php?edit_id=<?php echo $reward['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="learning/manage_rewards.php" method="post" class="d-inline" onsubmit="return confirm('Delete this reward?');">
                                            <input type="hidden" name="reward_id" value="<?php echo $reward['id']; ?>">
                                            <button type="submit" name="delete_reward" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


