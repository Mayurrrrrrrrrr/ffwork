<?php
// Path: D:\xampp\htdocs\Companyportal\referral\add_referral.php

// 1. Include init.php FIRST
// This loads the session, $conn, and BASE_URL
require_once 'init.php'; 

// 2. Session check: Ensure a REFERRER is logged in
if (!isset($_SESSION["referrer_loggedin"]) || $_SESSION["referrer_loggedin"] !== true) {
    // If not logged in, redirect to login page
    header("location: ". BASE_URL . "referral/login.php");
    exit;
}

// 3. Get Referrer details from session
$referrer_id = $_SESSION['referrer_id'];
$referrer_company_id = 1; // Assuming '1' is the main company ID for Firefly

// 4. Define variables for the form
$invitee_name = $invitee_contact = $invitee_address = $invitee_age = $invitee_gender = $interested_items = $store_id = $remarks = "";
$errors = [];
$success_message = "";

// 5. Fetch Stores for the dropdown
$stores = [];
// Fetches stores from the 'stores' table
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
if ($stmt_stores = $conn->prepare($sql_stores)) {
    $stmt_stores->bind_param("i", $referrer_company_id);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while ($row = $result_stores->fetch_assoc()) {
        $stores[] = $row;
    }
    $stmt_stores->close();
}

// 6. Process form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    // --- Validate Form Data ---
    $invitee_name = trim($_POST['invitee_name']);
    if (empty($invitee_name)) {
        $errors['invitee_name'] = "Please enter the invitee's name.";
    }

    // Use the 10-digit mobile number logic we created
    $invitee_contact = preg_replace('/[^0-9]/', '', trim($_POST["invitee_contact"]));
    if (empty($invitee_contact)) {
        $errors['invitee_contact'] = "Please enter the invitee's mobile number.";
    } elseif (strlen($invitee_contact) != 10) {
        $errors['invitee_contact'] = "Mobile number must be exactly 10 digits.";
    }

    $store_id = (int)$_POST['store_id'];
    if (empty($store_id)) {
        $errors['store_id'] = "Please select a store to refer them to.";
    }

    // Optional fields
    $invitee_address = trim($_POST['invitee_address']);
    $invitee_age = !empty($_POST['invitee_age']) ? (int)$_POST['invitee_age'] : null;
    $invitee_gender = trim($_POST['invitee_gender']);
    $interested_items = trim($_POST['interested_items']);
    $remarks = trim($_POST['remarks']);

    // --- If no errors, proceed to insert ---
    if (empty($errors)) {
        
        // Generate a unique referral code
        $referral_code = 'FF-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        // Insert into the 'referrals' table
        $sql_insert = "INSERT INTO referrals (referrer_id, company_id, store_id, invitee_name, invitee_contact, invitee_address, invitee_age, invitee_gender, interested_items, remarks, referral_code, status, created_at)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())";
        
        if ($stmt_insert = $conn->prepare($sql_insert)) {
            $stmt_insert->bind_param(
                "iiisssissss",
                $referrer_id,
                $referrer_company_id,
                $store_id,
                $invitee_name,
                $invitee_contact,
                $invitee_address,
                $invitee_age,
                $invitee_gender,
                $interested_items,
                $remarks,
                $referral_code
            );

            if ($stmt_insert->execute()) {
                $new_referral_id = $stmt_insert->insert_id;
                
                // --- Success! Redirect to the 'share' page (Phase 1, Step 2) ---
                header("location: " . BASE_URL . "referral/share.php?id=" . $new_referral_id . "&new=1");
                exit;
            } else {
                $errors['general'] = "Database error: Could not save the referral. Please try again.";
            }
            $stmt_insert->close();
        } else {
            $errors['general'] = "Database error: Could not prepare the statement.";
        }
    }
}


// 7. Include the referral-specific header
$page_title = "Add a New Referral";
require_once 'includes/header.php'; 
?>

<div class="row justify-content-center">
    <div class="col-lg-8 col-md-10">
        <div class="card shadow-sm border-0 rounded-lg">
            <div class="card-header text-center">
                <h3 class="fw-light my-3"><?php echo $page_title; ?></h3>
                <p class="text-muted">Submit your friend's details to generate a unique referral code for them.</p>
            </div>
            <div class="card-body p-4 p-md-5">

                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo $errors['general']; ?></div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                    
                    <h5 class="text-dark mb-3">Invitee's Details (Required)</h5>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="invitee_name" class="form-label">Full Name*</label>
                            <input class="form-control <?php echo (!empty($errors['invitee_name'])) ? 'is-invalid' : ''; ?>" id="invitee_name" name="invitee_name" type="text" placeholder="John Doe" value="<?php echo htmlspecialchars($invitee_name); ?>" required>
                            <span class="invalid-feedback"><?php echo $errors['invitee_name'] ?? ''; ?></span>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="invitee_contact" class="form-label">10-Digit Mobile Number*</label>
                            <input class="form-control <?php echo (!empty($errors['invitee_contact'])) ? 'is-invalid' : ''; ?>" id="invitee_contact" name="invitee_contact" type="tel" placeholder="10-digit mobile number" value="<?php echo htmlspecialchars($invitee_contact); ?>" required>
                            <span class="invalid-feedback"><?php echo $errors['invitee_contact'] ?? ''; ?></span>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="store_id" class="form-label">Refer to Store*</label>
                        <select class="form-select <?php echo (!empty($errors['store_id'])) ? 'is-invalid' : ''; ?>" id="store_id" name="store_id" required>
                            <option value="">-- Select a Store --</option>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo $store['id']; ?>" <?php echo ($store_id == $store['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store['store_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="invalid-feedback"><?php echo $errors['store_id'] ?? ''; ?></span>
                    </div>

                    <hr class="my-4">
                    
                    <h5 class="text-dark mb-3">Additional Details (Optional)</h5>

                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="invitee_age" class="form-label">Age</label>
                            <input class="form-control" id="invitee_age" name="invitee_age" type="number" placeholder="e.g., 35" value="<?php echo htmlspecialchars($invitee_age); ?>">
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="invitee_gender" class="form-label">Gender</label>
                            <select class="form-select" id="invitee_gender" name="invitee_gender">
                                <option value="">-- Select --</option>
                                <option value="Male" <?php echo ($invitee_gender == 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo ($invitee_gender == 'Female') ? 'selected' : ''; ?>>Female</option>
                                <option value="Other" <?php echo ($invitee_gender == 'Other') ? 'selected' : ''; ?>>Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="invitee_address" class="form-label">Address</label>
                        <textarea class="form-control" id="invitee_address" name="invitee_address" rows="2" placeholder="Invitee's city or area"><?php echo htmlspecialchars($invitee_address); ?></textarea>
                    </div>

                    <div class="mb-3">
                        <label for="interested_items" class="form-label">Interested In</label>
                        <input class="form-control" id="interested_items" name="interested_items" type="text" placeholder="e.g., Diamond Rings, Earrings" value="<?php echo htmlspecialchars($interested_items); ?>">
                    </div>

                    <div class="mb-3">
                        <label for="remarks" class="form-label">Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Any other info (e.g., upcoming anniversary)"><?php echo htmlspecialchars($remarks); ?></textarea>
                    </div>

                    <div class="d-grid mt-4">
                        <button class="btn btn-primary btn-lg" type="submit">
                            <i data-lucide="plus-circle" class="me-1" style="width:18px;"></i>
                            Generate Referral Code
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// 8. Include the referral-specific footer
require_once 'includes/footer.php'; 
?>