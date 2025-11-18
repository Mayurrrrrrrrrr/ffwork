<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn and role functions

// -- SECURITY CHECK: ENSURE USER IS THE PLATFORM ADMIN --
if (!check_role('platform_admin')) {
    // Redirect non-platform admins away
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        if (has_any_role(['admin', 'accounts', 'approver'])) { header("location: " . BASE_URL . "admin/index.php"); } 
        elseif (check_role('employee')) { header("location: " . BASE_URL . "employee/index.php"); } 
        else { header("location: " . BASE_URL . "login.php"); }
    } else { header("location: " . BASE_URL . "login.php"); }
    exit;
}

// Ensure $conn is available and valid
$conn = $GLOBALS['conn'] ?? null; 
if (!$conn || !($conn instanceof mysqli) || $conn->connect_error) {
     die("Database connection failed. Check configuration."); 
}

$error_message = '';
$success_message = '';
$current_user_id = $_SESSION['user_id'] ?? null; // Platform Admin ID
$edit_company = null; // Variable to hold company data for editing

// --- ACTION HANDLING ---

// Handle Deleting a Company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_company'])) {
    $company_id_to_delete = $_POST['company_id'];
    $company_name_to_delete = ''; // For logging

    // Get company details before deleting for logging
    $sql_get_company = "SELECT company_name, company_code FROM companies WHERE id = ?";
     if($stmt_get = $conn->prepare($sql_get_company)){
        $stmt_get->bind_param("i", $company_id_to_delete);
        $stmt_get->execute();
        $stmt_get->bind_result($company_name_to_delete, $company_code_to_delete);
        $stmt_get->fetch();
        $stmt_get->close();
    }

    $sql_delete = "DELETE FROM companies WHERE id = ?"; // Cascade delete should handle related data
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("i", $company_id_to_delete);
        if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
            $success_message = "Company '" . htmlspecialchars($company_name_to_delete) . "' and all associated data deleted successfully.";
            log_audit_action($conn, 'company_deleted', "Platform Admin deleted company: {$company_name_to_delete} (Code: {$company_code_to_delete}, ID: {$company_id_to_delete})", $current_user_id, null, 'company', $company_id_to_delete);
            // No redirect needed, list will refresh below
        } else {
            $error_message = "Error deleting company or company not found.";
            log_audit_action($conn, 'company_delete_failed', "Failed attempt to delete company ID: {$company_id_to_delete}. Error: " . ($stmt_delete->error ?: 'Not found/No rows affected'), $current_user_id, null, 'company', $company_id_to_delete);
        }
        $stmt_delete->close();
    } else {
         $error_message = "Database prepare error during delete: " . $conn->error;
         log_audit_action($conn, 'company_delete_failed', "Failed attempt to delete company ID: {$company_id_to_delete}. DB Prepare Error.", $current_user_id, null, 'company', $company_id_to_delete);
    }
}

// Handle Updating a Company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_company'])) {
    $company_id_to_edit = $_POST['edit_company_id'];
    $company_name = trim($_POST['company_name']);
    $company_code = trim(strtoupper($_POST['company_code']));
    $original_code = ''; // To check if code was changed

     // Get original code
     $sql_get_orig = "SELECT company_code FROM companies WHERE id = ?";
     if($stmt_get_orig = $conn->prepare($sql_get_orig)){
         $stmt_get_orig->bind_param("i", $company_id_to_edit); $stmt_get_orig->execute();
         $stmt_get_orig->bind_result($original_code); $stmt_get_orig->fetch(); $stmt_get_orig->close();
     }

    // Validation
    if (empty($company_name) || empty($company_code)) {
        $error_message = "Both Company Name and Company Code are required.";
    } elseif (!preg_match('/^[A-Z0-9_-]+$/', $company_code)) {
         $error_message = "Company Code invalid format (Uppercase letters, numbers, _, - only).";
    } else {
        // Check if new code is unique (only if it was changed)
        $code_unique = true;
        if ($company_code !== $original_code) {
            $sql_check = "SELECT id FROM companies WHERE company_code = ?";
            if ($stmt_check = $conn->prepare($sql_check)) {
                $stmt_check->bind_param("s", $company_code); $stmt_check->execute(); $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $error_message = "This Company Code ('" . htmlspecialchars($company_code) . "') is already in use by another company.";
                    $code_unique = false;
                }
                $stmt_check->close();
            } else { $error_message = "DB error checking code."; $code_unique = false; }
        }

        if ($code_unique) {
            // Update the company
            $sql_update = "UPDATE companies SET company_name = ?, company_code = ? WHERE id = ?";
            if ($stmt_update = $conn->prepare($sql_update)) {
                $stmt_update->bind_param("ssi", $company_name, $company_code, $company_id_to_edit);
                if ($stmt_update->execute()) {
                    $success_message = "Company '" . htmlspecialchars($company_name) . "' updated successfully.";
                    log_audit_action($conn, 'company_updated', "Platform Admin updated company ID: {$company_id_to_edit}. Name: {$company_name}, Code: {$company_code}", $current_user_id, null, 'company', $company_id_to_edit);
                     // Redirect to clear form and prevent resubmit issues
                     header("location: " . BASE_URL . "platform_admin/companies.php?success=updated"); exit; 
                } else {
                    $error_message = "Error updating company: " . $stmt_update->error;
                    log_audit_action($conn, 'company_update_failed', "Failed update company ID {$company_id_to_edit}. Error: ".$stmt_update->error, $current_user_id, null, 'company', $company_id_to_edit);
                }
                $stmt_update->close();
            } else { $error_message = "DB error preparing update: " . $conn->error; }
        }
    }
     // If update failed, load the details back into the edit form
     if(!empty($error_message)){
        $edit_company = ['id' => $company_id_to_edit, 'company_name' => $company_name, 'company_code' => $company_code];
     }
}


// Handle Adding a New Company
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_company'])) {
    $company_name = trim($_POST['company_name']);
    $company_code = trim(strtoupper($_POST['company_code'])); 
    if (empty($company_name) || empty($company_code)) { $error_message = "Fields required."; } 
    elseif (!preg_match('/^[A-Z0-9_-]+$/', $company_code)) { $error_message = "Code invalid format."; } 
    else {
        $sql_check = "SELECT id FROM companies WHERE company_code = ?";
        if ($stmt_check = $conn->prepare($sql_check)) {
            $stmt_check->bind_param("s", $company_code); $stmt_check->execute(); $stmt_check->store_result();
            if ($stmt_check->num_rows > 0) { $error_message = "Code already in use."; } 
            else {
                $sql_insert = "INSERT INTO companies (company_name, company_code) VALUES (?, ?)";
                if ($stmt_insert = $conn->prepare($sql_insert)) {
                    $stmt_insert->bind_param("ss", $company_name, $company_code);
                    if ($stmt_insert->execute()) {
                        $new_company_id = $conn->insert_id;
                        $success_message = "Company '" . htmlspecialchars($company_name) . "' created.";
                        log_audit_action($conn, 'company_created', "Created company: {$company_name} (Code: {$company_code})", $current_user_id, null, 'company', $new_company_id);
                         // Redirect after successful creation
                         header("location: " . BASE_URL . "platform_admin/companies.php?success=created"); exit;
                    } else { $error_message = "Error: " . $stmt_insert->error; log_audit_action($conn, 'company_create_failed', "Failed create '{$company_name}'. Error: ".$stmt_insert->error, $current_user_id, null); }
                    $stmt_insert->close();
                } else { $error_message = "DB prepare error: " . $conn->error; }
            }
            $stmt_check->close();
        } else { $error_message = "DB check error: " . $conn->error; }
    }
}

// Handle request to load data for editing
if (isset($_GET['edit_id']) && empty($_POST)) { // Check empty POST to prevent reloading edit data after failed update
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT id, company_name, company_code FROM companies WHERE id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("i", $edit_id);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_company = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_company) {
            $error_message = "Company not found for editing.";
        }
    }
}

// Set success message based on redirect parameter
if(isset($_GET['success'])) {
    if($_GET['success'] == 'created') $success_message = "Company created successfully.";
    if($_GET['success'] == 'updated') $success_message = "Company updated successfully.";
    if($_GET['success'] == 'deleted') $success_message = "Company deleted successfully.";
}


// --- DATA FETCHING: Get existing companies (Moved after potential actions) ---
$companies = []; 
$sql_fetch = "SELECT id, company_name, company_code, created_at FROM companies ORDER BY company_name";
if ($result = $conn->query($sql_fetch)) {
    while ($row = $result->fetch_assoc()) { $companies[] = $row; }
    $result->free();
} else {
    if(empty($error_message)) $error_message = "Error fetching companies: " . $conn->error; 
}


// -- INCLUDE THE COMMON HTML HEADER --
require_once '../includes/header.php'; 
?>

<div class="container mt-4">
    <h2>Manage Companies</h2>
    <p>As the Platform Administrator, create, edit, and manage companies using this portal.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Company Form -->
    <div class="card mb-4">
        <div class="card-header"><h4><?php echo $edit_company ? 'Edit Company' : 'Add New Company'; ?></h4></div>
        <div class="card-body">
            <form action="platform_admin/companies.php" method="POST">
                <?php if ($edit_company): ?>
                    <input type="hidden" name="edit_company_id" value="<?php echo $edit_company['id']; ?>">
                <?php endif; ?>
                 <div class="row g-3 align-items-end">
                     <div class="col-md-5">
                         <label class="form-label" for="company_name_input">Company Name</label>
                         <input type="text" id="company_name_input" name="company_name" class="form-control" 
                                value="<?php echo htmlspecialchars($edit_company['company_name'] ?? ''); ?>" required>
                     </div>
                     <div class="col-md-4">
                         <label class="form-label" for="company_code_input">Company Code (Unique)</label>
                         <input type="text" id="company_code_input" name="company_code" class="form-control" 
                                value="<?php echo htmlspecialchars($edit_company['company_code'] ?? ''); ?>" 
                                pattern="^[A-Z0-9_-]+$" title="Uppercase letters, numbers, underscore, hyphen only" required>
                         <div class="form-text">Used for login. Uppercase, numbers, _, - only.</div>
                    </div>
                     <div class="col-md-3">
                        <?php if ($edit_company): ?>
                             <button type="submit" name="update_company" class="btn btn-warning w-100 mb-2">Update Company</button>
                             <a href="platform_admin/companies.php" class="btn btn-secondary w-100">Cancel Edit</a>
                        <?php else: ?>
                             <button type="submit" name="add_company" class="btn btn-primary w-100">Add Company</button>
                        <?php endif; ?>
                    </div>
                 </div>
            </form>
        </div>
    </div>

    <!-- Existing Companies List -->
     <div class="card">
        <div class="card-header"><h4>Existing Companies</h4></div>
        <div class="card-body">
             <div class="table-responsive">
                 <table class="table table-striped table-hover">
                     <thead><tr><th>Company Name</th><th>Company Code</th><th>Created On</th><th>Actions</th></tr></thead>
                     <tbody>
                         <?php if (empty($companies)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No companies created yet.</td></tr>
                         <?php else: ?>
                            <?php foreach($companies as $company): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($company['company_name']); ?></td>
                                <td><code><?php echo htmlspecialchars($company['company_code']); ?></code></td>
                                <td><?php echo date("Y-m-d", strtotime($company['created_at'])); ?></td>
                                <td>
                                    <a href="platform_admin/companies.php?edit_id=<?php echo $company['id']; ?>" class="btn btn-sm btn-warning">Edit</a>
                                    <form action="platform_admin/companies.php" method="POST" class="d-inline" onsubmit="return confirm('DELETE company \'<?php echo htmlspecialchars(addslashes($company['company_name'])); // Escape for JS ?>\' and ALL associated data (users, reports etc)? This cannot be undone!');">
                                        <input type="hidden" name="company_id" value="<?php echo $company['id']; ?>">
                                        <button type="submit" name="delete_company" class="btn btn-sm btn-danger">Delete</button>
                                    </form>
                                     <!-- New Manage Users Link -->
                                    <a href="admin/users.php?company_id=<?php echo $company['id']; ?>" class="btn btn-sm btn-info ms-1" title="Manage users for this company">Manage Users</a>
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
// -- CLOSE DATABASE CONNECTION AND INCLUDE FOOTER --
if(isset($conn) && $conn instanceof mysqli) $conn->close();
require_once '../includes/footer.php'; 
?>



