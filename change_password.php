<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// We use the main init file
require_once 'includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS LOGGED IN --
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || empty($_SESSION['user_id'])) {
    session_destroy();
    header("location: " . BASE_URL . "login.php");
    exit;
}

// -- ENSURE USER ID IS AVAILABLE --
$user_id = $_SESSION['user_id'];
// company_id is not strictly needed for this, as user_id is the primary key
$company_id = get_current_company_id(); 

$error_message = '';
$success_message = '';

// --- FORM HANDLING: CHANGE PASSWORD ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    // --- Validation ---
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error_message = "Please fill in all fields.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "New password and confirmation do not match.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "New password must be at least 8 characters long.";
    } else {
        // --- Process Database ---
        try {
            // 1. Fetch the user's current hashed password
            $sql_get_hash = "SELECT password_hash FROM users WHERE id = ?";
            $stmt_get = $conn->prepare($sql_get_hash);
            $stmt_get->bind_param("i", $user_id);
            $stmt_get->execute();
            $result = $stmt_get->get_result();
            $user_data = $result->fetch_assoc();
            $stmt_get->close();

            if (!$user_data) {
                throw new Exception("User not found.");
            }

            // 2. Verify the current password is correct
            if (password_verify($current_password, $user_data['password_hash'])) {
                // 3. Hash the new password
                $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                
                // 4. Update the database
                $sql_update = "UPDATE users SET password_hash = ? WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update);
                $stmt_update->bind_param("si", $new_password_hash, $user_id);
                
                if ($stmt_update->execute()) {
                    $success_message = "Your password has been updated successfully.";
                    log_audit_action($conn, 'password_changed', "User changed their own password.", $user_id, $company_id, 'user', $user_id);
                } else {
                    throw new Exception("Failed to update password: " . $stmt_update->error);
                }
                $stmt_update->close();
                
            } else {
                $error_message = "Your current password was incorrect.";
            }

        } catch (Exception $e) {
            $error_message = "An error occurred: " . $e->getMessage();
            log_audit_action($conn, 'password_change_failed', "User failed to change password. Error: ".$e->getMessage(), $user_id, $company_id, 'user', $user_id);
        }
    }
}


// -- INCLUDE THE COMMON HTML HEADER --
// We use the main expense portal header as a default.
require_once 'includes/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h4>Change Your Password</h4>
                </div>
                <div class="card-body">
                    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
                    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
                    
                    <?php if (empty($success_message)): // Hide form on success ?>
                    <form action="change_password.php" method="post">
                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                            <div class="form-text">Must be at least 8 characters long.</div>
                        </div>
                        <div class="mb-3">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                        </div>
                        <hr>
                        <button type="submit" name="change_password" class="btn btn-primary w-100">Update Password</button>
                    </form>
                    <?php else: ?>
                        <a href="portal_home.php" class="btn btn-primary">Return to Portal Home</a>
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


