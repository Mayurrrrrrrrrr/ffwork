<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, role checks, $company_id_context

// -- SECURITY CHECK: ENSURE USER IS A COMPANY ADMIN OR PLATFORM ADMIN --
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "admin/index.php"); 
    exit;
}

// -- DEFINE CORE VARIABLES --
$company_id_context = null;
$company_name_context = "Your Company";
$is_platform_admin = check_role('platform_admin');
$user_id = $_SESSION['user_id'] ?? null;

if ($is_platform_admin) {
    if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
        $company_id_context = (int)$_GET['company_id'];
        // Fetch company name for display
        $sql_get_cname = "SELECT company_name FROM companies WHERE id = ?";
        if($stmt_cname = $conn->prepare($sql_get_cname)){
            $stmt_cname->bind_param("i", $company_id_context);
            $stmt_cname->execute();
            $stmt_cname->bind_result($fetched_company_name);
            $stmt_cname->fetch();
            $stmt_cname->close();
            if(empty($fetched_company_name)) {
                die("Invalid Company ID specified."); // Or redirect
            }
             $company_name_context = htmlspecialchars($fetched_company_name);
        }
    } else {
        // Platform admin must select a company
        header("location: " . BASE_URL . "platform_admin/companies.php?error=select_company_to_manage_stores"); 
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


$error_message = '';
$success_message = '';
$edit_store = null; // Variable to hold store data for editing
$gati_column_exists = false; // Flag to check if new column exists

// --- Check if gati_location_name column exists ---
// This is critical for the page to function without crashing
$sql_check_col = "SHOW COLUMNS FROM `stores` LIKE 'gati_location_name'";
if ($result_check_col = $conn->query($sql_check_col)) {
    if ($result_check_col->num_rows > 0) {
        $gati_column_exists = true;
    }
    $result_check_col->free();
}

if (!$gati_column_exists) {
    // We add html_entity_decode to safely display the error message which contains HTML tags
    $error_message = htmlspecialchars("<strong>CRITICAL ERROR:</strong> The column `gati_location_name` is missing from the `stores` table. Please run the `ALTER TABLE` command from `fix_database.sql` to fix this.");
}


// --- ACTION HANDLING ---

// Handle Deleting a Store
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_store'])) {
    $store_id_to_delete = $_POST['store_id'];
    
    // Check if store is in use by users or reports (recommended)
    $sql_check_users = "SELECT id FROM users WHERE store_id = ? AND company_id = ?";
    $stmt_check_u = $conn->prepare($sql_check_users);
    $stmt_check_u->bind_param("ii", $store_id_to_delete, $company_id_context);
    $stmt_check_u->execute();
    $stmt_check_u->store_result();
    
    $sql_check_reports = "SELECT id FROM expense_reports WHERE store_id = ? AND company_id = ?";
    $stmt_check_r = $conn->prepare($sql_check_reports);
    $stmt_check_r->bind_param("ii", $store_id_to_delete, $company_id_context);
    $stmt_check_r->execute();
    $stmt_check_r->store_result();
    
    $sql_check_btl = "SELECT id FROM btl_proposals WHERE store_id = ? AND company_id = ?";
    $stmt_check_b = $conn->prepare($sql_check_btl);
    $stmt_check_b->bind_param("ii", $store_id_to_delete, $company_id_context);
    $stmt_check_b->execute();
    $stmt_check_b->store_result();


    if($stmt_check_u->num_rows > 0 || $stmt_check_r->num_rows > 0 || $stmt_check_b->num_rows > 0) {
        $error_message = "Cannot delete store: It is linked to users, expense reports, or BTL proposals. Please re-assign them first.";
    } else {
        // Proceed with delete
        $sql_delete = "DELETE FROM stores WHERE id = ? AND company_id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("ii", $store_id_to_delete, $company_id_context);
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $success_message = "Store deleted successfully.";
                log_audit_action($conn, 'store_deleted', "Admin deleted store ID: {$store_id_to_delete}", $user_id, $company_id_context, 'store', $store_id_to_delete);
            } else { $error_message = "Error deleting store or store not found."; }
            $stmt_delete->close();
        }
    }
    $stmt_check_u->close();
    $stmt_check_r->close();
    $stmt_check_b->close();
}

// Handle Adding or Editing a Store
// Only proceed if the column exists
if ($gati_column_exists && $_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_store']) || isset($_POST['update_store']))) {
    $store_name = trim($_POST['store_name']);
    $store_code = trim($_POST['store_code']);
    
    // NEW: Get GATI location name
    $gati_location_name = trim($_POST['gati_location_name']);
    if (empty($gati_location_name)) {
        $gati_location_name = null; // Store as NULL if empty
    }

    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $store_id_to_edit = $_POST['edit_store_id'] ?? null;

    if (empty($store_name)) {
        $error_message = "Store Name is required.";
    } else {
        // Check for duplicate store code within the company
        $sql_check_code = "SELECT id FROM stores WHERE store_code = ? AND company_id = ? AND id != ?";
        $check_id = $store_id_to_edit ?? 0;
        $stmt_check = $conn->prepare($sql_check_code);
        $stmt_check->bind_param("sii", $store_code, $company_id_context, $check_id);
        $stmt_check->execute();
        $stmt_check->store_result();
        
        if ($stmt_check->num_rows > 0 && !empty($store_code)) {
            $error_message = "This Store Code is already in use by another store in your company.";
        } else {
            if ($store_id_to_edit) {
                // Update Existing Store
                // MODIFIED: Added gati_location_name = ?
                $sql = "UPDATE stores SET store_name = ?, store_code = ?, is_active = ?, gati_location_name = ? 
                        WHERE id = ? AND company_id = ?";
                if ($stmt = $conn->prepare($sql)) {
                    // MODIFIED: Added 's' for $gati_location_name, changed from "ssiii" to "ssisii"
                    $stmt->bind_param("ssisii", $store_name, $store_code, $is_active, $gati_location_name, $store_id_to_edit, $company_id_context);
                    if ($stmt->execute()) {
                        $success_message = "Store updated successfully.";
                        log_audit_action($conn, 'store_updated', "Admin updated store ID: {$store_id_to_edit}", $user_id, $company_id_context, 'store', $store_id_to_edit);
                    } else { $error_message = "Error updating store: " . $stmt->error; }
                    $stmt->close();
                }
            } else {
                // Add New Store
                // MODIFIED: Added gati_location_name
                $sql_insert = "INSERT INTO stores (company_id, store_name, store_code, is_active, gati_location_name) VALUES (?, ?, ?, ?, ?)";
                if ($stmt_insert = $conn->prepare($sql_insert)) {
                    // MODIFIED: Added 's' for $gati_location_name, changed from "issi" to "issis"
                    $stmt_insert->bind_param("issis", $company_id_context, $store_name, $store_code, $is_active, $gati_location_name);
                    if ($stmt_insert->execute()) {
                        $new_store_id = $conn->insert_id;
                        $success_message = "Store added successfully.";
                        log_audit_action($conn, 'store_created', "Admin created store: {$store_name}", $user_id, $company_id_context, 'store', $new_store_id);
                    } else { $error_message = "Error adding store: " . $stmt_insert->error; }
                    $stmt_insert->close();
                }
            }
        }
        $stmt_check->close();
    }
    
    if(!empty($error_message) && $store_id_to_edit) {
        // MODIFIED: Repopulate form on error, including new field
        $edit_store = ['id' => $store_id_to_edit, 'store_name' => $store_name, 'store_code' => $store_code, 'is_active' => $is_active, 'gati_location_name' => $gati_location_name];
    }
}

// Handle request to edit (load data into form)
if (isset($_GET['edit_id']) && empty($_POST)) { // Check empty POST
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT * FROM stores WHERE id = ? AND company_id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("ii", $edit_id, $company_id_context);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_store = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_store) { $error_message = "Store not found for editing."; }
    }
}

// --- DATA FETCHING ---
// Fetch all stores for the current company
$stores_list = [];
// Updated query to fetch all columns including the new one
$sql_fetch = "SELECT * FROM stores WHERE company_id = ? ORDER BY store_name";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $company_id_context);
    if ($stmt_fetch->execute()) {
        $result = $stmt_fetch->get_result();
        while ($row = $result->fetch_assoc()) {
            $stores_list[] = $row;
        }
    } else { $error_message = "Error fetching stores."; }
    $stmt_fetch->close();
}

// NEW: Fetch all available GATI locations from stock_data for the datalist helper
$gati_locations = [];
$sql_gati_locs = "SELECT DISTINCT LocationName FROM stock_data WHERE company_id = ? AND LocationName IS NOT NULL AND LocationName != '' ORDER BY LocationName";
if($stmt_gati = $conn->prepare($sql_gati_locs)){
    $stmt_gati->bind_param("i", $company_id_context);
    $stmt_gati->execute();
    $result_gati = $stmt_gati->get_result();
    // *** FIX: Removed duplicate ->get_result() call ***
    while($row_gati = $result_gati->fetch_assoc()) { 
        $gati_locations[] = $row_gati['LocationName'];
    }
    $stmt_gati->close();
}


require_once '../includes/header.php'; 
?>

<div class="container mt-4">
    <h2>Manage Stores / Cost Centers</h2>
    <p>Add, edit, or disable stores and cost centers for your company. (e.g., "Head Office", "Main Store").</p>
    <?php if ($is_platform_admin): ?>
    <div class="alert alert-info">
        You are managing stores for: <strong><?php echo $company_name_context; ?></strong>. <a href="platform_admin/companies.php">Return to Company List</a>.
    </div>
    <?php endif; ?>

    <!-- Use html_entity_decode to render the <strong> tag from the error message -->
    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo html_entity_decode($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Store Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h4><?php echo $edit_store ? 'Edit Store' : 'Add New Store'; ?></h4>
        </div>
        <div class="card-body">
            <!-- Check if column exists before showing the form -->
            <?php if ($gati_column_exists): ?>
            <form action="admin/manage_stores.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post">
                <?php if ($edit_store): ?>
                    <input type="hidden" name="edit_store_id" value="<?php echo $edit_store['id']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label for="store_name" class="form-label">Store Name*</label>
                        <input type="text" class="form-control" id="store_name" name="store_name" 
                               value="<?php echo htmlspecialchars($edit_store['store_name'] ?? ''); ?>" required>
                        <div class="form-text">The friendly, human-readable name (e.g., "R City, Mumbai").</div>
                    </div>
                    <!-- NEW FIELD -->
                    <div class="col-md-4">
                        <label for="gati_location_name" class="form-label">GATI Location Name (from stock_data)</label>
                        <input type="text" class="form-control" id="gati_location_name" name="gati_location_name" 
                               value="<?php echo htmlspecialchars($edit_store['gati_location_name'] ?? ''); ?>" 
                               list="gati-locations" placeholder="e.g., R-CITY MALL - LO">
                        <!-- Datalist to help admins find the right name -->
                        <datalist id="gati-locations">
                            <?php foreach ($gati_locations as $loc): ?>
                                <option value="<?php echo htmlspecialchars($loc); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Must **EXACTLY** match a <code>LocationName</code> from the stock data.</div>
                    </div>
                     <div class="col-md-4">
                        <label for="store_code" class="form-label">Store Code (Optional, Unique)</label>
                        <input type="text" class="form-control" id="store_code" name="store_code" 
                               value="<?php echo htmlspecialchars($edit_store['store_code'] ?? ''); ?>">
                    </div>
                </div>
                <!-- New row for buttons/toggle -->
                <div class="row g-3 mt-2">
                    <div class="col-md-3">
                        <div class="form-check form-switch pt-2">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   value="1" <?php echo ($edit_store === null || $edit_store['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                     <div class="col-md-9 text-end">
                        <?php if ($edit_store): ?>
                            <a href="admin/manage_stores.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="update_store" class="btn btn-warning">Update Store</button>
                        <?php else: ?>
                            <button type="submit" name="add_store" class="btn btn-primary">Add Store</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
            <?php else: ?>
                <!-- Show only the error if the column is missing -->
                <p class="text-danger">Cannot add or edit stores until the database error is resolved. Please run the SQL command to add the `gati_location_name` column.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Existing Stores List -->
    <div class="card">
        <div class="card-header">
            <h4>Existing Stores</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Store Name</th>
                            <!-- NEW: Added GATI Location Name column -->
                            <th>GATI Location Name</th>
                            <th>Store Code</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stores_list)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No stores or cost centers defined yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($stores_list as $store): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($store['store_name']); ?></td>
                                    <!-- NEW: Display GATI Location Name -->
                                    <td>
                                        <?php if ($gati_column_exists && isset($store['gati_location_name']) && !empty($store['gati_location_name'])): ?>
                                            <code><?php echo htmlspecialchars($store['gati_location_name']); ?></code>
                                        <?php elseif ($gati_column_exists): ?>
                                            <span class="badge bg-warning text-dark">Not Set</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger">DB Column Missing</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><code><?php echo htmlspecialchars($store['store_code']); ?></code></td>
                                    <td>
                                        <span class="badge <?php echo $store['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $store['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="admin/manage_stores.php?<?php echo $is_platform_admin ? 'company_id='.$company_id_context.'&' : ''; ?>edit_id=<?php echo $store['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="admin/manage_stores.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post" class="d-inline" onsubmit="return confirm('Delete this store? This will fail if users, expense reports, or BTL proposals are linked to it.');">
                                            <input type="hidden" name="store_id" value="<?php echo $store['id']; ?>">
                                            <button type="submit" name="delete_store" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
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
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; 
?>



