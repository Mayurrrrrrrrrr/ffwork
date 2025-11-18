<?php
// --- INITIALIZE ---
require_once 'init.php';

// If a referrer is already logged in, redirect them to the dashboard
if (isset($_SESSION["referrer_loggedin"]) && $_SESSION["referrer_loggedin"] === true) {
    header("location: " . BASE_URL . "referral/dashboard.php");
    exit;
}

// --- DEFINE VARIABLES ---
$mobile_number = $password = "";
$mobile_number_err = $password_err = $login_err = "";

// --- PROCESS FORM DATA ---
if ($_SERVER["REQUEST_METHOD"] == "POST") {

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
        $password_err = "Please enter your password.";
    } else {
        $password = trim($_POST["password"]);
    }

    // --- VALIDATE CREDENTIALS ---
    if (empty($mobile_number_err) && empty($password_err)) {
        $sql = "SELECT id, full_name, mobile_number, password_hash FROM referrers WHERE mobile_number = ?";
        
        if ($stmt = $conn->prepare($sql)) {
            $stmt->bind_param("s", $mobile_number);
            
            if ($stmt->execute()) {
                $stmt->store_result();
                
                if ($stmt->num_rows == 1) {
                    $stmt->bind_result($id, $full_name, $db_mobile_number, $hashed_password);
                    if ($stmt->fetch()) {
                        if (password_verify($password, $hashed_password)) {
                            // --- SUCCESS! START SESSION ---
                            $_SESSION["referrer_loggedin"] = true;
                            $_SESSION["referrer_id"] = $id;
                            $_SESSION["referrer_name"] = $full_name;
                            $_SESSION["referrer_mobile"] = $db_mobile_number;
                            
                            header("location: " . BASE_URL . "referral/dashboard.php");
                            exit();
                        } else {
                            $login_err = "Invalid mobile number or password.";
                        }
                    }
                } else {
                    $login_err = "No account found with that mobile number.";
                }
            } else {
                $login_err = "Oops! Something went wrong. Please try again later.";
            }
            $stmt->close();
        }
    }
    
    $conn->close();
}

// --- RENDER PAGE ---
$page_title = "Referrer Login";
require_once 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
        <div class="card shadow-lg border-0 rounded-lg mt-5">
            <div class="card-header text-center"> <img src="<?php echo BASE_URL; ?>assets/logo.png" alt="Logo" style="height: 40px; margin: 1.5rem 0 1rem 0;">
                <h3 class="fw-light my-2"><?php echo $page_title; ?></h3>
                <p class="text-muted">Log in to your Firefly Referral account.</p>
            </div>
            <div class="card-body">
                
                <?php if (!empty($login_err)) { echo '<div class="alert alert-danger">' . $login_err . '</div>'; } ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    <div class="form-floating mb-3">
                        <input class="form-control <?php echo (!empty($mobile_number_err)) ? 'is-invalid' : ''; ?>" id="mobile_number" name="mobile_number" type="tel" placeholder="1234567890" value="<?php echo htmlspecialchars($mobile_number); ?>">
                        <label for="mobile_number">10-Digit Mobile Number</label>
                        <span class="invalid-feedback"><?php echo $mobile_number_err; ?></span>
                    </div>

                    <div class="form-floating mb-3">
                        <input class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" id="password" name="password" type="password" placeholder="Password">
                        <label for="password">Password</label>
                        <span class="invalid-feedback"><?php echo $password_err; ?></span>
                    </div>
                    
                    <div class="d-grid mt-4 mb-0">
                        <button class="btn btn-primary btn-lg" type="submit">Login</button>
                    </div>
                </form>
            </div>
            <div class="card-footer text-center py-3">
                <div class="small">
                    <a href="<?php echo BASE_URL; ?>referral/register.php">Need an account? Sign up!</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once 'includes/footer.php';
?>