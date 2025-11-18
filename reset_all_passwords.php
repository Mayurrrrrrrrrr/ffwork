<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This script provides access to the $conn database object.
require_once 'includes/init.php';

// --- SCRIPT CONFIGURATION ---
$new_password = 'password123'; // The new password for all users.
$password_hash = password_hash($new_password, PASSWORD_DEFAULT); // Create a secure hash of the new password.

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Utility</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f4f7f6; color: #333; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #004E54; }
        .success { color: #27ae60; font-weight: bold; }
        .error { color: #c0392b; font-weight: bold; }
        .warning { color: #f39c12; font-weight: bold; border: 1px solid #f39c12; padding: 10px; border-radius: 5px; background-color: #fff8e1;}
        code { background-color: #ecf0f1; padding: 2px 5px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Bulk Password Reset Utility</h1>
        <?php
        if (!$conn) {
            echo "<p class='error'>FATAL ERROR: Could not connect to the database. Please check your <code>config.php</code> file.</p>";
        } else {
            // Prepare the UPDATE statement to change the password hash for all users.
            $sql = "UPDATE users SET password_hash = ?";

            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("s", $password_hash);

                if ($stmt->execute()) {
                    $affected_rows = $stmt->affected_rows;
                    echo "<p class='success'>SUCCESS: Passwords for all {$affected_rows} users have been reset to '{$new_password}'.</p>";
                } else {
                    echo "<p class='error'>ERROR: The query failed to execute.</p>";
                    echo "<p>Details: " . htmlspecialchars($stmt->error) . "</p>";
                }
                $stmt->close();
            } else {
                echo "<p class='error'>ERROR: Could not prepare the SQL statement.</p>";
                echo "<p>Details: " . htmlspecialchars($conn->error) . "</p>";
            }
            $conn->close();
        }
        ?>
        <div class="warning" style="margin-top: 20px;">
            <p><strong>IMPORTANT SECURITY NOTICE:</strong></p>
            <p>You must now delete this file (<code>reset_all_passwords.php</code>) from your server immediately.</p>
        </div>
    </div>
</body>
</html>

