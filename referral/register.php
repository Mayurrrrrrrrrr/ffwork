<?php
// --- INITIALIZE ---
require_once 'init.php';

// If a referrer is already logged in, redirect them to the dashboard
if (isset($_SESSION["referrer_loggedin"]) && $_SESSION["referrer_loggedin"] === true) {
    header("location: " . BASE_URL . "referral/dashboard.php");
    exit;
}

// --- DEFINE VARIABLES ---
$full_name = $mobile_number = $password = $confirm_password = "";
$full_name_err = $mobile_number_err = $password_err = $confirm_password_err = $register_err = "";

// --- PROCESS FORM DATA ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate full name
    if (empty(trim($_POST["full_name"]))) {
        $full_name_err = "Please enter your full name.";
    } else {
        $full_name = trim($_POST["full_name"]);
    }

    // --- (FIXED) Validate mobile number (10 digits only) ---
    if (empty(trim($_POST["mobile_number"]))) {
        $mobile_number_err = "Please enter your mobile number.";
    } else {
        // Remove any non-numeric characters
        $mobile = preg_replace('/[^0-9]/', '', trim($_POST["mobile_number"]));
        
        if (strlen($mobile) != 10) {
            $mobile_number_err = "Mobile number must be exactly 10 digits.";
        } else {
            $mobile_number = $mobile;
        }
    }
    // --- End of fix ---

    // Validate password
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 6) {
        $password_err = "Password must have at least 6 characters.";
    } else {
        $password = trim($_POST["password"]);
    }

    // Validate confirm password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Passwords did not match.";
        }
    }

    // Check for existing mobile number
    if (empty($mobile_number_err)) {
        $sql = "SELECT id FROM referrers WHERE mobile_number = ?";
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $mobile_number);
            if ($stmt->execute()) {
                $stmt->store_result();
                if ($stmt->num_rows == 1) {
                    $mobile_number_err = "This mobile number is already registered.";
                }
            } else {
                $register_err = "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }

    // --- INSERT NEW REFERRER ---
    if (empty($full_name_err) && empty($mobile_number_err) && empty($password_err) && empty($confirm_password_err) && empty($register_err)) {
        
        $sql_insert = "INSERT INTO referrers (full_name, mobile_number, password_hash) VALUES (?, ?, ?)";
        
        if ($stmt = $conn->prepare($sql_insert)) {
            $param_password = password_hash($password, PASSWORD_DEFAULT); // Hash the password
            
            $stmt->bind_param("sss", $full_name, $mobile_number, $param_password);
            
            if ($stmt->execute()) {
                // Get the new referrer ID
                $new_referrer_id = $stmt->insert_id;
                
                // --- START SESSION AND REDIRECT ---
                $_SESSION["referrer_loggedin"] = true;
                $_SESSION["referrer_id"] = $new_referrer_id;
                $_SESSION["referrer_name"] = $full_name;
                $_SESSION["referrer_mobile"] = $mobile_number;
                
                header("location: " . BASE_URL . "referral/dashboard.php");
                exit();
            } else {
                $register_err = "Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }
    
    $conn->close();
}

// --- RENDER PAGE ---
$page_title = "Referrer Registration";
require_once 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
        <div class="card shadow-lg border-0 rounded-lg mt-5">
            <div class="card-header text-center">
                <img src="<?php echo BASE_URL; ?>assets/logo.png" alt="Logo" style="height: 40px; margin-bottom: 1rem;">
                <h3 class="fw-light my-2"><?php echo $page_title; ?></h3>
                <p class="text-muted">Create an account to join the Firefly Referral Program.</p>
            </div>
            <div class="card-body">
                
                <?php if (!empty($register_err)) { echo '<div class="alert alert-danger">' . $register_err . '</div>'; } ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-floating mb-3">
                        <input class="form-control <?php echo (!empty($full_name_err)) ? 'is-invalid' : ''; ?>" id="full_name" name="full_name" type="text" placeholder="John Doe" value="<?php echo htmlspecialchars($full_name); ?>">
                        <label for="full_name">Full Name</label>
                        <span class="invalid-feedback"><?php echo $full_name_err; ?></span>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control <?php echo (!empty($mobile_number_err)) ? 'is-invalid' : ''; ?>" id="mobile_number" name="mobile_number" type="tel" placeholder="1234567890" value="<?php echo htmlspecialchars($mobile_number); ?>">
                        <label for="mobile_number">10-Digit Mobile Number</label>
                        <span class="invalid-feedback"><?php echo $mobile_number_err; ?></span>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" id="password" name="password" type="password" placeholder="Password">
                                <label for="password">Password</label>
                                <span class="invalid-feedback"><?php echo $password_err; ?></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-floating mb-3">
                                <input class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" id="confirm_password" name="confirm_password" type="password" placeholder="Confirm Password">
                                <label for="confirm_password">Confirm Password</label>
                                <span class="invalid-feedback"><?php echo $confirm_password_err; ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="d-grid mt-4 mb-0">
                        <button class="btn btn-primary btn-lg" type="submit">Create Account</button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center py-3">
                <div class="small">
                    <a href="<?php echo BASE_URL; ?>referral/login.php">Have an account? Go to login</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>