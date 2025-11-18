<?php
// We only use the core init here, as we are not logged in
require_once 'includes/init.php'; 

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['request_reset'])) {
    $email = trim($_POST['email']);
    
    if (empty($email)) {
        $error_message = "Please enter your email address.";
    } else {
        // 1. Verify email exists in the database
        $sql_check = "SELECT id FROM users WHERE email = ?";
        if ($stmt_check = $conn->prepare($sql_check)) {
            $stmt_check->bind_param("s", $email);
            $stmt_check->execute();
            $result = $stmt_check->get_result();
            $user_data = $result->fetch_assoc();
            $stmt_check->close();
            
            if ($user_data) {
                $user_id = $user_data['id'];
                
                // 2. Generate a unique token
                $token = bin2hex(random_bytes(32)); // Secure random token
                $expires = date("Y-m-d H:i:s", strtotime('+1 hour')); // Token expires in 1 hour
                
                // 3. Save the token to the database
                // Note: We use INSERT IGNORE to prevent conflicts if a token already exists, or ON DUPLICATE KEY UPDATE
                $sql_save_token = "INSERT INTO password_resets (email, token, expires_at) 
                                   VALUES (?, ?, ?)
                                   ON DUPLICATE KEY UPDATE token=VALUES(token), expires_at=VALUES(expires_at)";
                                   
                if ($stmt_save = $conn->prepare($sql_save_token)) {
                    $stmt_save->bind_param("sss", $email, $token, $expires);
                    $stmt_save->execute();
                    $stmt_save->close();

                    // --- SIMULATED EMAIL SEND ---
                    // In a real system, you would send an email here.
                    // $reset_link = "http://yourdomain.com/reset_password.php?token=" . $token;
                    // mail($email, "Password Reset Request", "Click this link to reset your password: " . $reset_link);
                    
                    $success_message = "A password reset link has been sent to your email address. Please check your inbox (or spam folder).";
                    // For testing, provide the link directly:
                    $reset_link = "http://expenses.gt.tc/reset_password.php?token=" . $token;
                    log_audit_action($conn, 'password_reset_requested', "Reset token generated for {$email}. Token: {$token}", $user_id, null, 'user', $user_id);

                } else {
                    $error_message = "Error saving reset token.";
                }
                
            } else {
                // To avoid leaking information about which email addresses exist, 
                // we show the success message even if the email doesn't exist.
                $success_message = "A password reset link has been sent to your email address. Please check your inbox.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <h2 class="mt-3">Reset Password</h2>
            <p>Enter your account email address to receive a password reset link.</p>

            <?php if(!empty($error_message)){ echo '<div class="alert alert-danger">' . htmlspecialchars($error_message) . '</div>'; } ?>
            <?php if(!empty($success_message)){ echo '<div class="alert alert-success">' . htmlspecialchars($success_message) . '</div>'; } ?>
            
            <?php if(isset($reset_link)): ?>
                <div class="alert alert-warning mt-3">
                    **DEBUG LINK (DELETE THIS):** <a href="<?php echo $reset_link; ?>" target="_blank">Click Here to Reset</a>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="form-group mb-3 text-start">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-control" required>
                </div>
                <div class="form-group mt-4">
                    <button type="submit" name="request_reset" class="btn btn-primary w-100">Send Reset Link</button>
                </div>
            </form>
            <p class="mt-3"><a href="login.php">Back to Login</a></p>
        </div>
    </div>
</body>
</html>

