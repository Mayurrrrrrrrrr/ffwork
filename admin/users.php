<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn and role functions

// -- SECURITY CHECK: ENSURE USER IS A SUPER ADMIN OR PLATFORM ADMIN --
$is_platform_admin = check_role('platform_admin');
$is_company_admin = check_role('admin');

if (!$is_platform_admin && !$is_company_admin) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

// -- DETERMINE THE COMPANY CONTEXT --
$company_id_context = null;
$company_name_context = "Your Company"; 

if ($is_platform_admin) {
    if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
        $company_id_context = (int)$_GET['company_id'];
        $sql_get_cname = "SELECT company_name FROM companies WHERE id = ?";
        if($stmt_cname = $conn->prepare($sql_get_cname)){
            $stmt_cname->bind_param("i", $company_id_context); $stmt_cname->execute();
            $stmt_cname->bind_result($fetched_company_name); $stmt_cname->fetch(); $stmt_cname->close();
            if(empty($fetched_company_name)) { header("location: " . BASE_URL . "platform_admin/companies.php?error=invalid_company"); exit; }
             $company_name_context = htmlspecialchars($fetched_company_name);
        } else { die("Error retrieving company details."); }
    } else {
        header("location: " . BASE_URL . "platform_admin/companies.php?error=select_company"); 
        exit;
    }
} else {
    // Company Admin uses their own company ID from the session
    $company_id_context = get_current_company_id();
     if (!$company_id_context) {
        session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
    }
    // Fetch company name for display
     $sql_get_cname = "SELECT company_name FROM companies WHERE id = ?";
     if($stmt_cname = $conn->prepare($sql_get_cname)){
        $stmt_cname->bind_param("i", $company_id_context); $stmt_cname->execute();
        $stmt_cname->bind_result($fetched_company_name); $stmt_cname->fetch(); $stmt_cname->close();
        if(!empty($fetched_company_name)) { $company_name_context = htmlspecialchars($fetched_company_name); }
     }
}

$current_user_id = $_SESSION['user_id'] ?? null;
$error_message = '';
$success_message = '';
$bulk_upload_results = null;

// --- ACTION HANDLING ---

// Handle Deleting a User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_user'])) {
    $user_to_delete_id = $_POST['user_id'];
    if ($user_to_delete_id == $current_user_id) {
        $error_message = "Error: You cannot delete your own account.";
    } else {
        // ... (rest of delete logic) ...
        $sql_delete = "DELETE FROM users WHERE id = ? AND company_id = ?"; 
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("ii", $user_to_delete_id, $company_id_context); 
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                 log_audit_action($conn, 'user_deleted', "Admin deleted user ID: {$user_to_delete_id}", $current_user_id, $company_id_context, 'user', $user_to_delete_id);
                 $success_message = "User deleted successfully.";
            } else { $error_message = "Error deleting user or user not found in this company."; }
            $stmt_delete->close();
        } else { $error_message = "Database error during delete."; }
    }
}

// Handle Editing a User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['edit_user'])) {
    $user_id_to_edit = $_POST['edit_user_id'];
    $full_name = trim($_POST['edit_full_name']);
    $email = trim($_POST['edit_email']);
    $department = trim($_POST['edit_department']);
    $roles_to_assign = $_POST['edit_roles'] ?? [];
    $approver_id = isset($_POST['edit_approver_id']) && !empty($_POST['edit_approver_id']) ? $_POST['edit_approver_id'] : null;
    
    // NEW FIELDS
    $store_id = isset($_POST['edit_store_id']) && !empty($_POST['edit_store_id']) ? $_POST['edit_store_id'] : null;
    $employee_code = trim($_POST['edit_employee_code']) ?: null;
    $dob = empty($_POST['edit_dob']) ? null : $_POST['edit_dob'];
    $doj = empty($_POST['edit_doj']) ? null : $_POST['edit_doj'];
    $photo_url = trim($_POST['edit_photo_url']) ?: null;

    if (in_array('1', $roles_to_assign) && $approver_id === null) { $error_message = "An approver must be assigned if the user has the 'Employee' role."; } 
    else {
        if (!in_array('1', $roles_to_assign)) { $approver_id = null; } 
        
        // Check for duplicate employee code
        $sql_check_code = "SELECT id FROM users WHERE employee_code = ? AND company_id = ? AND id != ?";
        $stmt_check_code = $conn->prepare($sql_check_code);
        $stmt_check_code->bind_param("sii", $employee_code, $company_id_context, $user_id_to_edit);
        $stmt_check_code->execute();
        $stmt_check_code->store_result();
        
        if ($employee_code && $stmt_check_code->num_rows > 0) {
            $error_message = "This Employee Code is already in use by another user.";
        } else {
            $sql_check_company = "SELECT id FROM users WHERE id = ? AND company_id = ?";
            if ($stmt_check = $conn->prepare($sql_check_company)) {
                 $stmt_check->bind_param("ii", $user_id_to_edit, $company_id_context); 
                 $stmt_check->execute(); $stmt_check->store_result();
                 if($stmt_check->num_rows == 1) {
                     $conn->begin_transaction(); 
                     try {
                        // Update user details with new fields
                        $sql_update_user = "UPDATE users SET 
                                            full_name = ?, email = ?, department = ?, approver_id = ?, 
                                            store_id = ?, employee_code = ?, dob = ?, doj = ?, photo_url = ?
                                            WHERE id = ? AND company_id = ?";
                        $stmt_update = $conn->prepare($sql_update_user);
                        $stmt_update->bind_param("sssiissssii", 
                            $full_name, $email, $department, $approver_id, 
                            $store_id, $employee_code, $dob, $doj, $photo_url,
                            $user_id_to_edit, $company_id_context
                        );
                        if(!$stmt_update->execute()) throw new Exception("User detail update failed: ".$stmt_update->error);
                        $stmt_update->close();

                        // Update password if provided
                        if (!empty($_POST['edit_password'])) {
                            $password_hash = password_hash($_POST['edit_password'], PASSWORD_DEFAULT);
                            $sql_update_pass = "UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?";
                            $stmt_pass = $conn->prepare($sql_update_pass);
                            $stmt_pass->bind_param("sii", $password_hash, $user_id_to_edit, $company_id_context);
                            if(!$stmt_pass->execute()) throw new Exception("Password update failed: ".$stmt_pass->error);
                            $stmt_pass->close();
                        }

                        // Update roles
                        $sql_delete_roles = "DELETE FROM user_roles WHERE user_id = ?";
                        $stmt_del_roles = $conn->prepare($sql_delete_roles);
                        $stmt_del_roles->bind_param("i", $user_id_to_edit);
                        if(!$stmt_del_roles->execute()) throw new Exception("Role deletion failed: ".$stmt_del_roles->error);
                        $stmt_del_roles->close();
                        
                        if (!empty($roles_to_assign)) {
                            $sql_insert_roles = "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)";
                            $stmt_ins_roles = $conn->prepare($sql_insert_roles);
                            $admin_role_id = 4; // Assuming '4' is 'admin'
                            $admin_role_assigned = false;
                            foreach ($roles_to_assign as $role_id) {
                                $stmt_ins_roles->bind_param("ii", $user_id_to_edit, $role_id);
                                if(!$stmt_ins_roles->execute()) throw new Exception("Role assignment failed: ".$stmt_ins_roles->error);
                                if($role_id == $admin_role_id) $admin_role_assigned = true;
                            }
                             // Ensure current company admin (if editing self) retains admin role
                             if ($user_id_to_edit == $current_user_id && !$admin_role_assigned && $is_company_admin) {
                                 $stmt_ins_roles->bind_param("ii", $user_id_to_edit, $admin_role_id);
                                 if(!$stmt_ins_roles->execute()) throw new Exception("Admin role retention failed: ".$stmt_ins_roles->error);
                                 $stmt_ins_roles->close(); // Close after final use inside loop
                             } elseif (isset($stmt_ins_roles) && $stmt_ins_roles instanceof mysqli_stmt) {
                                $stmt_ins_roles->close(); // Close if loop was executed and not closed above
                             }

                        } elseif ($user_id_to_edit == $current_user_id && $is_company_admin) {
                             $sql_insert_roles = "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)";
                             $stmt_ins_roles = $conn->prepare($sql_insert_roles);
                             $stmt_ins_roles->bind_param("ii", $user_id_to_edit, 4); // ID 4 = admin
                             if(!$stmt_ins_roles->execute()) throw new Exception("Admin role retention failed: ".$stmt_ins_roles->error);
                             $stmt_ins_roles->close();
                        }

                        $conn->commit(); 
                        log_audit_action($conn, 'user_edited', "Admin edited user ID: {$user_id_to_edit}", $current_user_id, $company_id_context, 'user', $user_id_to_edit);
                        $success_message = "User updated successfully.";
                    
                 } catch (Exception $e) { $conn->rollback(); $error_message = "Error updating user: " . $e->getMessage(); }
             } else { $error_message = "User not found or invalid for this company."; }
             $stmt_check->close();
            } else { // This else is for: if ($stmt_check = $conn->prepare($sql_check_company))
                $error_message = "Database prepare error: " . $conn->error;
            }
        } // <-- This is the MISSING brace that closes: if ($stmt_check = ...)
        $stmt_check_code->close();
    }
}


// Handle Creating a Single User
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_user'])) {
     $full_name = trim($_POST['full_name']); $email = trim($_POST['email']); $password = $_POST['password']; $department = trim($_POST['department']);
     $roles_to_assign = $_POST['roles'] ?? []; 
     $approver_id = isset($_POST['approver_id']) && !empty($_POST['approver_id']) ? $_POST['approver_id'] : null;
     
    $store_id = isset($_POST['store_id']) && !empty($_POST['store_id']) ? $_POST['store_id'] : null;
    $employee_code = trim($_POST['employee_code']) ?: null;
    $dob = empty($_POST['dob']) ? null : $_POST['dob'];
    $doj = empty($_POST['doj']) ? null : $_POST['doj'];
    $photo_url = trim($_POST['photo_url']) ?: null;

    if (empty($full_name) || empty($email) || empty($password) || empty($department) || empty($roles_to_assign)) { $error_message = "All fields marked * are required."; } 
    elseif (in_array('1', $roles_to_assign) && $approver_id === null) { $error_message = "Approver required if 'Employee' role selected."; } 
    else {
        // Check email and employee code uniqueness
        $sql_check = "SELECT id FROM users WHERE (email = ? OR (employee_code IS NOT NULL AND employee_code = ?)) AND company_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ssi", $email, $employee_code, $company_id_context);
        $stmt_check->execute(); $stmt_check->store_result();
        if ($stmt_check->num_rows > 0) { $error_message = "Email or Employee Code already exists in this company."; }
        $stmt_check->close();
        
        if (empty($error_message)) {
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            if (!in_array('1', $roles_to_assign)) { $approver_id = null; }
            
            $conn->begin_transaction(); 
            try {
                // Insert user with new fields
                $sql_insert_user = "INSERT INTO users (company_id, full_name, email, password_hash, department, approver_id, store_id, employee_code, dob, doj, photo_url) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt_insert = $conn->prepare($sql_insert_user);
                $stmt_insert->bind_param("issssiissss", 
                    $company_id_context, $full_name, $email, $password_hash, $department, $approver_id,
                    $store_id, $employee_code, $dob, $doj, $photo_url
                );
                
                 if ($stmt_insert->execute()) {
                     $new_user_id = $conn->insert_id;
                     $sql_insert_roles = "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)";
                     $stmt_roles = $conn->prepare($sql_insert_roles);
                     foreach ($roles_to_assign as $role_id) { $stmt_roles->bind_param("ii", $new_user_id, $role_id); if(!$stmt_roles->execute()) throw new Exception("Role assignment failed: ".$stmt_roles->error); }
                     $stmt_roles->close(); $stmt_insert->close();
                     $conn->commit(); 
                     log_audit_action($conn, 'user_created', "Admin created user: {$full_name}", $current_user_id, $company_id_context, 'user', $new_user_id);
                     $success_message = "User created successfully.";
                 } else { throw new Exception($stmt_insert->error); }
            } catch (Exception $e) { $conn->rollback(); $error_message = "Error creating user: " . $e->getMessage(); /* Log error */ }
        }
    }
}

// Handle Bulk CSV Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['user_csv'])) {
    // TODO: Update this logic to include new fields: store_id, employee_code, dob, doj
    $error_message = "Bulk Upload is not yet updated for new profile fields. Please add users manually for now.";
}

// --- DATA FETCHING (Uses $company_id_context) ---
$all_users = []; $approvers = []; $all_roles = []; $all_stores = [];

// Fetch all users for the context company with new fields
// FIX: Added s.gati_location_name to the SELECT clause
$sql_all_users = "SELECT u.*, a.full_name AS approver_name, s.store_name, s.gati_location_name,
                  GROUP_CONCAT(r.id ORDER BY r.id) as role_ids,
                  GROUP_CONCAT(r.name ORDER BY r.id) as roles
                  FROM users u 
                  LEFT JOIN users a ON u.approver_id = a.id 
                  LEFT JOIN stores s ON u.store_id = s.id
                  LEFT JOIN user_roles ur ON u.id = ur.user_id
                  LEFT JOIN roles r ON ur.role_id = r.id
                  WHERE u.company_id = ? 
                  GROUP BY u.id
                  ORDER BY u.full_name";
if($stmt_users = $conn->prepare($sql_all_users)){
    $stmt_users->bind_param("i", $company_id_context); 
    if($stmt_users->execute()){ $all_users = $stmt_users->get_result()->fetch_all(MYSQLI_ASSOC); } 
    $stmt_users->close();
} 

// Fetch approvers for the context company
$sql_approvers = "SELECT u.id, u.full_name FROM users u JOIN user_roles ur ON u.id = ur.user_id WHERE ur.role_id = (SELECT id FROM roles WHERE name = 'approver') AND u.company_id = ?";
if($stmt_approvers = $conn->prepare($sql_approvers)){
     $stmt_approvers->bind_param("i", $company_id_context); 
     if($stmt_approvers->execute()){ $approvers = $stmt_approvers->get_result()->fetch_all(MYSQLI_ASSOC); }
     $stmt_approvers->close();
} 

// Fetch all available roles (excluding platform admin)
$all_roles = $conn->query("SELECT * FROM roles WHERE name != 'platform_admin' ORDER BY id")->fetch_all(MYSQLI_ASSOC);

// Fetch all active stores for this company
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
if($stmt_stores = $conn->prepare($sql_stores)){
    $stmt_stores->bind_param("i", $company_id_context);
    $stmt_stores->execute();
    $all_stores = $stmt_stores->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_stores->close();
}


if(isset($_GET['success'])) { 
    if($_GET['success'] == 'created') $success_message = "User created successfully.";
    if($_GET['success'] == 'edited') $success_message = "User updated successfully.";
    if($_GET['success'] == 'deleted') $success_message = "User deleted successfully.";
 }

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>User Management</h2>
    <!-- Display Company Context -->
    <?php if ($is_platform_admin): ?>
        <div class="alert alert-secondary" role="alert">
            Managing Users for: <strong><?php echo $company_name_context; ?></strong> 
            (<a href="platform_admin/companies.php">Change Company</a>)
        </div>
    <?php else: ?>
         <div class="alert alert-secondary" role="alert">
            Managing Users for: <strong><?php echo $company_name_context; ?></strong> 
        </div>
    <?php endif; ?>
    <p>Create, edit, and manage user accounts and their roles.</p>
    
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>
    <?php if ($bulk_upload_results): /* Bulk Results */ endif; ?>

    <!-- Create User Form -->
    <div class="card mb-4">
        <div class="card-header"><h4>Create New User</h4></div>
        <div class="card-body"> 
            <form action="admin/users.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post" id="create-user-form"> 
                <div class="row g-3">
                    <!-- Core Fields -->
                    <div class="col-md-4"><label for="full_name" class="form-label">Full Name*</label><input type="text" class="form-control" name="full_name" required></div>
                    <div class="col-md-4"><label for="email" class="form-label">Email Address*</label><input type="email" class="form-control" name="email" required></div>
                    <div class="col-md-4"><label for="password" class="form-label">Password*</label><input type="password" class="form-control" name="password" minlength="8" required></div>
                    <div class="col-md-4"><label for="department" class="form-label">Department*</label><input type="text" class="form-control" name="department" required></div>
                    <div class="col-md-4"><label for="employee_code" class="form-label">Employee Code (Unique)</label><input type="text" class="form-control" name="employee_code"></div>
                    <div class="col-md-4"><label for="store_id" class="form-label">Store / Cost Center</label>
                        <select class="form-select" name="store_id"><option value="">-- None --</option><?php foreach ($all_stores as $store): ?><option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-4"><label for="doj" class="form-label">Date of Joining</label><input type="date" class="form-control" name="doj"></div>
                    <div class="col-md-4"><label for="dob" class="form-label">Date of Birth</label><input type="date" class="form-control" name="dob"></div>
                    <div class="col-md-4" id="approver-select-wrapper" style="display:none;">
                        <label for="approver_id" class="form-label">Assign to Approver*</label>
                        <select class="form-select" name="approver_id"><option value="">-- Select --</option><?php foreach ($approvers as $approver): ?><option value="<?php echo $approver['id']; ?>"><?php echo htmlspecialchars($approver['full_name']); ?></option><?php endforeach; ?></select>
                    </div>
                    <div class="col-md-12"><label for="photo_url" class="form-label">Photo URL</label><input type="text" class="form-control" name="photo_url" placeholder="http://example.com/image.png"></div>
                    
                    <div class="col-md-12">
                        <label class="form-label">Roles (Select at least one)*</label>
                        <div>
                        <?php foreach($all_roles as $role): ?>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input create-role-checkbox" type="checkbox" name="roles[]" value="<?php echo $role['id']; ?>" id="create_role_<?php echo $role['id']; ?>">
                                <label class="form-check-label" for="create_role_<?php echo $role['id']; ?>"><?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?></label>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button type="submit" name="create_user" class="btn btn-primary mt-3">Create User</button>
            </form> 
        </div>
    </div>

    <!-- Bulk User Upload Form -->
    <div class="card mb-4">
        <div class="card-header"><h4>Bulk Create Users via CSV</h4></div>
        <div class="card-body"> 
            <form action="admin/users.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post" enctype="multipart/form-data"> 
                 <div class="mb-3">
                    <label for="user_csv" class="form-label">Upload CSV File</label>
                    <input class="form-control" type="file" id="user_csv" name="user_csv" accept=".csv" required>
                    <div class="form-text">
                        CSV format: <code>full_name, email, department, roles, approver_email, employee_code, store_name, dob(Y-m-d), doj(Y-m-d)</code><br>
                        - Roles: comma-separated (e.g., "employee,approver").<br>
                        - `store_name` must *exactly* match a store in the "Manage Stores" page.
                    </div>
                </div>
                <button type="submit" class="btn btn-secondary">Upload and Create Users</button>
            </form> 
        </div>
    </div>


    <!-- Existing Users List -->
    <div class="card">
        <div class="card-header"><h4>Users in <?php echo $is_platform_admin ? $company_name_context : 'Your Company'; ?></h4></div>
        <div class="card-body"> <div class="table-responsive"> <table class="table table-striped table-hover table-sm"> 
            <thead><tr><th>Name</th><th>Employee Code</th><th>Email</th><th>Store / Cost Center</th><th>GATI Location</th><th>Roles</th><th>Approver</th><th>Action</th></tr></thead>
            <tbody>
                <?php foreach ($all_users as $user): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo htmlspecialchars($user['employee_code'] ?? 'N/A'); ?></td>
                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                        <td><?php echo htmlspecialchars($user['store_name'] ?? 'N/A'); ?></td>
                        <!-- FIX: Display GATI location name here -->
                        <td>
                            <?php if ($user['gati_location_name']): ?>
                                <span class="badge bg-success"><?php echo htmlspecialchars($user['gati_location_name']); ?></span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">Not Linked</span>
                            <?php endif; ?>
                        </td>
                        <td><small><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $user['roles'] ?? ''))); ?></small></td>
                        <td><?php echo htmlspecialchars($user['approver_name'] ?? 'N/A'); ?></td>
                        <td>
                            <button class="btn btn-warning btn-sm" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8'); ?>)">Edit</button>
                            <?php if ($user['id'] != $current_user_id): ?>
                                <form action="admin/users.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post" class="d-inline" onsubmit="return confirm('Delete user?');">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="delete_user" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                 <?php if (empty($all_users)): ?> <tr><td colspan="8">No users found in this company.</td></tr> <?php endif; ?>
            </tbody>
        </table> </div> </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1"> <div class="modal-dialog modal-xl"> <div class="modal-content">
    <form action="admin/users.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post">
        <div class="modal-header"><h5 class="modal-title">Edit User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <input type="hidden" name="edit_user_id" id="edit_user_id">
            <div class="row g-3">
                <!-- Core Fields -->
                <div class="col-md-4"><label for="edit_full_name" class="form-label">Full Name*</label><input type="text" class="form-control" id="edit_full_name" name="edit_full_name" required></div>
                <div class="col-md-4"><label for="edit_email" class="form-label">Email*</label><input type="email" class="form-control" id="edit_email" name="edit_email" required></div>
                <div class="col-md-4"><label for="edit_department" class="form-label">Department*</label><input type="text" class="form-control" id="edit_department" name="edit_department" required></div>
                
                <!-- New Profile Fields -->
                <div class="col-md-4"><label for="edit_employee_code" class="form-label">Employee Code</label><input type="text" class="form-control" id="edit_employee_code" name="edit_employee_code"></div>
                <div class="col-md-4"><label for="edit_store_id" class="form-label">Store / Cost Center</label>
                    <select class="form-select" id="edit_store_id" name="edit_store_id"><option value="">-- None --</option><?php foreach ($all_stores as $store): ?><option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4" id="edit-approver-wrapper" style="display:none;">
                    <label for="edit_approver_id" class="form-label">Assign to Approver*</label>
                    <select class="form-select" id="edit_approver_id" name="edit_approver_id"><option value="">-- Select --</option><?php foreach ($approvers as $approver): ?><option value="<?php echo $approver['id']; ?>"><?php echo htmlspecialchars($approver['full_name']); ?></option><?php endforeach; ?></select>
                </div>
                <div class="col-md-4"><label for="edit_doj" class="form-label">Date of Joining</label><input type="date" class="form-control" id="edit_doj" name="edit_doj"></div>
                <div class="col-md-4"><label for="edit_dob" class="form-label">Date of Birth</label><input type="date" class="form-control" id="edit_dob" name="edit_dob"></div>
                <div class="col-md-4"><label for="edit_password" class="form-label">New Password (optional)</label><input type="password" class="form-control" id="edit_password" name="edit_password" placeholder="Leave blank to keep current"></div>
                <div class="col-md-12"><label for="edit_photo_url" class="form-label">Photo URL</label><input type="text" class="form-control" id="edit_photo_url" name="edit_photo_url" placeholder="http://example.com/image.png"></div>
                
                <div class="col-md-12">
                    <label class="form-label">Roles*</label>
                    <div id="edit_roles_container">
                    <?php foreach($all_roles as $role): ?>
                        <div class="form-check form-check-inline">
                            <input class="form-check-input edit-role-checkbox" type="checkbox" name="edit_roles[]" value="<?php echo $role['id']; ?>" id="edit_role_<?php echo $role['id']; ?>">
                            <label class="form-check-label" for="edit_role_<?php echo $role['id']; ?>"><?php echo ucfirst(str_replace('_', ' ', $role['name'])); ?></label>
                        </div>
                    <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" name="edit_user" class="btn btn-primary">Save Changes</button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const employeeRoleId = '1'; 
    var editModalElement = document.getElementById('editUserModal');
    var editModal = new bootstrap.Modal(editModalElement);
    
    function toggleApproverSelect(wrapperSelector, selectedRoles) {
        const wrapper = document.querySelector(wrapperSelector);
        if (!wrapper) return; // Guard clause
        const select = wrapper.querySelector('select');
        if (!select) return; // Guard clause

        if (selectedRoles.includes(employeeRoleId)) {
            wrapper.style.display = 'block';
            select.required = true;
        } else {
            wrapper.style.display = 'none';
            select.required = false;
        }
    }

    function setupRoleCheckboxListeners(formSelector, wrapperSelector) {
        document.querySelectorAll(formSelector + ' .create-role-checkbox, ' + formSelector + ' .edit-role-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const selectedRoles = Array.from(
                    document.querySelectorAll(formSelector + ' input[name="roles[]"]:checked, ' + formSelector + ' input[name="edit_roles[]"]:checked')
                ).map(cb => cb.value);
                toggleApproverSelect(wrapperSelector, selectedRoles);
            });
        });
    }

    function openEditModal(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_full_name').value = user.full_name;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_department').value = user.department;
        document.getElementById('edit_approver_id').value = user.approver_id || '';
        document.getElementById('edit_password').value = ''; 
        
        document.getElementById('edit_employee_code').value = user.employee_code || '';
        document.getElementById('edit_store_id').value = user.store_id || '';
        document.getElementById('edit_dob').value = user.dob || '';
        document.getElementById('edit_doj').value = user.doj || '';
        document.getElementById('edit_photo_url').value = user.photo_url || '';

        // Populate Roles
        const isCurrentUser = user.id == <?php echo $current_user_id; ?>;
        const userRoleIds = user.role_ids ? user.role_ids.split(',') : [];
        const adminRoleId = '4'; // Assuming '4' is 'admin'

        document.querySelectorAll('.edit-role-checkbox').forEach(checkbox => {
            checkbox.checked = userRoleIds.includes(checkbox.value);
            checkbox.disabled = (isCurrentUser && checkbox.value == adminRoleId && <?php echo $is_company_admin ? 'true' : 'false'; ?>);
            if (checkbox.disabled) checkbox.checked = true;
        });
        
        // Ensure Approver select state is correct when opening modal
        toggleApproverSelect('#edit-approver-wrapper', userRoleIds);
        
        editModal.show();
    }
     
    if(editModalElement) {
        editModalElement.addEventListener('shown.bs.modal', function () {
             setupRoleCheckboxListeners('#editUserModal form', '#edit-approver-wrapper');
             const selectedRoles = Array.from(document.querySelectorAll('#edit_roles_container input:checked')).map(c => c.value);
             toggleApproverSelect('#edit-approver-wrapper', selectedRoles);
         });
    }

    document.addEventListener('DOMContentLoaded', () => {
        setupRoleCheckboxListeners('#create-user-form', '#approver-select-wrapper');
     });
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>


