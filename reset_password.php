<?php
// We only use the core init here, as we are not logged in
require_once 'includes/init.php'; 

$error_message = '';
$success_message = '';
$token = $_GET['token'] ?? null;
$email = ''; // Will be populated by the database

// --- Check Token Validity ---
if (!$token) {
    die("Invalid request: No token provided.");
}

$sql_check_token = "SELECT email, expires_at FROM password_resets WHERE token = ?";
if ($stmt_check = $conn->prepare($sql_check_token)) {
    $stmt_check->bind_param("s", $token);
    $stmt_check->execute();
    $result = $stmt_check->get_result();
    $token_data = $result->fetch_assoc();
    $stmt_check->close();
    
    if (!$token_data) {
        $error_message = "Invalid or expired token. Please request a new link.";
    } elseif (strtotime($token_data['expires_at']) < time()) {
        $error_message = "Token has expired. Please request a new link.";
    } else {
        $email = $token_data['email'];
    }
} else {
    die("Database error during token check.");
}

// --- FORM HANDLING: NEW PASSWORD SUBMISSION ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && empty($error_message)) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if ($new_password !== $confirm_password) {
        $error_message = "New password and confirmation do not match.";
    } elseif (strlen($new_password) < 8) {
        $error_message = "New password must be at least 8 characters long.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Hash the new password
            $new_password_hash = password_hash($new_password, PASSWORD_DEFAULT);
            
            // 2. Update the user's password
            $sql_update_pass = "UPDATE users SET password_hash = ? WHERE email = ?";
            $stmt_update = $conn->prepare($sql_update_pass);
            $stmt_update->bind_param("ss", $new_password_hash, $email);
            if (!$stmt_update->execute() || $stmt_update->affected_rows == 0) {
                throw new Exception("Failed to update password or user not found.");
            }
            $stmt_update->close();

            // 3. Delete the used token to prevent reuse
            $sql_delete_token = "DELETE FROM password_resets WHERE token = ?";
            $stmt_delete = $conn->prepare($sql_delete_token);
            $stmt_delete->bind_param("s", $token);
            $stmt_delete->execute();
            $stmt_delete->close();
            
            $conn->commit();
            $success_message = "Your password has been reset successfully! You can now log in.";
            // Prevent form display after success
            $email = ''; 
            $token = ''; 

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "An error occurred during password update: " . $e->getMessage();
        }
    }
}

// --- FINAL RENDER ---
$form_visible = empty($error_message) && !empty($token) && !empty($email);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <h2 class="mt-3">Password Reset</h2>

            <?php if(!empty($error_message)): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <p class="mt-3"><a href="login.php">Back to Login</a></p>
            <?php endif; ?>
            
            <?php if(!empty($success_message)): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                <p class="mt-3"><a href="login.php">Back to Login</a></p>
            <?php endif; ?>
            
            <?php if ($form_visible): ?>
                <p>Enter your new password for **<?php echo htmlspecialchars($email); ?>**.</p>
                <form action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>" method="post">
                    <div class="form-group mb-3 text-start">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="8">
                        <div class="form-text">Must be at least 8 characters long.</div>
                    </div>
                    <div class="form-group mb-3 text-start">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required>
                    </div>
                    <div class="form-group mt-4">
                        <button type="submit" class="btn btn-primary w-100">Set New Password</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

