<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This MUST be the very first line of the script.
require_once 'includes/init.php';

// If a user is already logged in, redirect them to the portal home
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: " . BASE_URL . "portal_home.php"); // Updated Redirect
    exit;
}

// Define variables
$company_code = $email = $password = "";
$company_code_err = $email_err = $password_err = $login_err = "";
$is_platform_admin_login = false; // Flag for special login

if(isset($_GET['error']) && $_GET['error'] == 'no_roles') {
    $login_err = "Your session was invalid (no roles assigned). Please log in again.";
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate Email first to check for Platform Admin
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter email.";
    } else {
        $email = trim($_POST["email"]);
        // Define the Platform Admin email (must match sample data)
        if ($email === 'platform@admin.com') {
            $is_platform_admin_login = true;
        }
    }

    // Validate Company Code only if it's NOT a Platform Admin login
    if (!$is_platform_admin_login) {
        if (empty(trim($_POST["company_code"]))) {
            $company_code_err = "Please enter company code.";
        } else {
            $company_code = trim(strtoupper($_POST["company_code"]));
        }
    }

    // Validate Password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter password.";
    } else {
        $password = trim($_POST["password"]);
    }

    $required_fields_valid = empty($email_err) && empty($password_err) && ($is_platform_admin_login || empty($company_code_err));

    if ($required_fields_valid) {
        
        $company_id = null; 

        // 1. Handle Platform Admin Login OR Find Company ID
        if ($is_platform_admin_login) {
            $company_id = null; // Explicitly null for platform admin user query
        } else {
            // Find the company based on the code for regular users
            $sql_company = "SELECT id FROM companies WHERE company_code = ?";
            if ($stmt_company = $conn->prepare($sql_company)) {
                $stmt_company->bind_param("s", $company_code);
                if ($stmt_company->execute()) {
                    $stmt_company->store_result();
                    if ($stmt_company->num_rows == 1) {
                        $stmt_company->bind_result($fetched_company_id);
                        $stmt_company->fetch();
                        $company_id = $fetched_company_id;
                    } else { $login_err = "Invalid company code."; }
                } else { $login_err = "Error verifying company code."; }
                $stmt_company->close();
            } else { $login_err = "Database error (company lookup)."; }
        }

        // 2. Authenticate the user (handles both platform admin and regular users)
        if (empty($login_err) && ($is_platform_admin_login || $company_id !== null)) {
            $sql_user = "SELECT u.id, u.full_name, u.password_hash, u.company_id AS user_company_id, GROUP_CONCAT(r.name) AS roles 
                         FROM users u
                         LEFT JOIN user_roles ur ON u.id = ur.user_id
                         LEFT JOIN roles r ON ur.role_id = r.id
                         WHERE u.email = ? AND (u.company_id = ? OR (? IS NULL AND u.company_id IS NULL))
                         GROUP BY u.id";

            if ($stmt_user = $conn->prepare($sql_user)) {
                $stmt_user->bind_param("sii", $email, $company_id, $company_id);

                if ($stmt_user->execute()) {
                    $stmt_user->store_result();
                    if ($stmt_user->num_rows == 1) {
                        $stmt_user->bind_result($id, $full_name, $hashed_password, $user_company_id, $roles_str);
                        if ($stmt_user->fetch()) {
                            if (password_verify($password, $hashed_password)) {
                                $roles_array = $roles_str ? explode(',', $roles_str) : [];
                                if (empty($roles_array)) {
                                    $login_err = "Account has no permissions.";
                                } else {
                                    // SUCCESS! Store session info
                                    $_SESSION["loggedin"] = true;
                                    $_SESSION["user_id"] = $id;
                                    $_SESSION["company_id"] = $user_company_id; 
                                    $_SESSION["full_name"] = $full_name;
                                    $_SESSION["roles"] = $roles_array;
                                    
                                    // Always redirect to the portal home
                                    header("location: " . BASE_URL . "portal_home.php");
                                    exit();
                                }
                            } else { $login_err = "Invalid email or password."; }
                        }
                    } else { $login_err = "Invalid email or password for this company."; }
                } else { $login_err = "Database error (user execution)."; error_log($stmt_user->error);}
                $stmt_user->close();
            } else { $login_err = "Database error (user prepare)."; error_log($conn->error); }
        } 
    } 
    
    if(isset($conn) && $conn instanceof mysqli) $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Login - Company Workportal</title> 
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght=700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-container">
            <img src="assets/logo.png" alt="Portal Logo" class="login-logo" onerror="this.onerror=null; this.src='https://placehold.co/120x40/004E54/FFFFFF?text=Portal';">
            <h2 class="mt-3">Company Workportal</h2> 
            <p>Please provide your company code and credentials.</p>

            <?php if(!empty($login_err)){ echo '<div class="alert alert-danger">' . htmlspecialchars($login_err) . '</div>'; } ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" id="login-form">
                <div class="form-group mb-3 text-start company-code-field" id="company_code_wrapper">
                    <label class="form-label">Company Code</label>
                    <input type="text" name="company_code" id="company_code_input" class="form-control <?php echo (!empty($company_code_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($company_code); ?>">
                    <span class="invalid-feedback"><?php echo $company_code_err; ?></span>
                </div>    
                <div class="form-group mb-3 text-start">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" id="email_input" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" oninput="checkPlatformAdmin(this.value)">
                    <span class="invalid-feedback"><?php echo $email_err; ?></span>
                </div>    
                <div class="form-group mb-3 text-start">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>">
                    <span class="invalid-feedback"><?php echo $password_err; ?></span>
                </div>
                <div class="form-group mt-4">
                    <input type="submit" class="btn btn-primary w-100" value="Login">
                </div>
            </form>
        </div>
    </div>
    <script>
        const platformAdminEmail = 'platform@admin.com';
        const companyCodeWrapper = document.getElementById('company_code_wrapper');
        const companyCodeInput = document.getElementById('company_code_input');
        const emailInput = document.getElementById('email_input');
        function checkPlatformAdmin(emailValue) {
            if (emailValue.toLowerCase() === platformAdminEmail) {
                companyCodeWrapper.style.display = 'none';
                companyCodeInput.required = false; 
            } else {
                companyCodeWrapper.style.display = 'block';
                 companyCodeInput.required = true; 
            }
        }
        checkPlatformAdmin(emailInput.value);
    </script>
</body>
</html>



