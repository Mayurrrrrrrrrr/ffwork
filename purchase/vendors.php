<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS ADMIN/PLATFORM_ADMIN --
// Only admins should manage the vendor list
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$edit_vendor = null; // Variable to hold vendor data for editing

// --- ACTION HANDLING ---

// Handle Deleting a Vendor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_vendor'])) {
    $vendor_id_to_delete = $_POST['vendor_id'];
    
    // Check if vendor is in use (optional but recommended)
    $sql_check_po = "SELECT id FROM purchase_orders WHERE vendor_id = ? AND company_id = ?";
    $stmt_check = $conn->prepare($sql_check_po);
    $stmt_check->bind_param("ii", $vendor_id_to_delete, $company_id_context);
    $stmt_check->execute();
    $stmt_check->store_result();
    
    if($stmt_check->num_rows > 0) {
        $error_message = "Cannot delete vendor: This vendor is already linked to one or more purchase orders.";
    } else {
        $sql_delete = "DELETE FROM vendors WHERE id = ? AND company_id = ?";
        if ($stmt_delete = $conn->prepare($sql_delete)) {
            $stmt_delete->bind_param("ii", $vendor_id_to_delete, $company_id_context);
            if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
                $success_message = "Vendor deleted successfully.";
                log_audit_action($conn, 'vendor_deleted', "Admin deleted vendor ID: {$vendor_id_to_delete}", $user_id, $company_id_context, 'vendor', $vendor_id_to_delete);
            } else { $error_message = "Error deleting vendor or vendor not found."; }
            $stmt_delete->close();
        }
    }
    $stmt_check->close();
}

// Handle Adding or Editing a Vendor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_vendor']) || isset($_POST['update_vendor']))) {
    $vendor_name = trim($_POST['vendor_name']);
    $contact_person = trim($_POST['contact_person']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $vendor_id_to_edit = $_POST['edit_vendor_id'] ?? null;

    if (empty($vendor_name) || empty($email)) {
        $error_message = "Vendor Name and Email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Invalid email format.";
    } else {
        if ($vendor_id_to_edit) {
            // Update Existing Vendor
            $sql = "UPDATE vendors SET vendor_name = ?, contact_person = ?, email = ?, phone = ?, is_active = ? 
                    WHERE id = ? AND company_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("ssssiii", $vendor_name, $contact_person, $email, $phone, $is_active, $vendor_id_to_edit, $company_id_context);
                if ($stmt->execute()) {
                    $success_message = "Vendor updated successfully.";
                    log_audit_action($conn, 'vendor_updated', "Admin updated vendor ID: {$vendor_id_to_edit}", $user_id, $company_id_context, 'vendor', $vendor_id_to_edit);
                } else { $error_message = "Error updating vendor: " . $stmt->error; }
                $stmt->close();
            }
        } else {
            // Add New Vendor - Check for duplicate email in this company
            $sql_check = "SELECT id FROM vendors WHERE email = ? AND company_id = ?";
            if ($stmt_check = $conn->prepare($sql_check)) {
                $stmt_check->bind_param("si", $email, $company_id_context);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $error_message = "A vendor with this email already exists for your company.";
                } else {
                    $sql_insert = "INSERT INTO vendors (company_id, vendor_name, contact_person, email, phone, is_active) VALUES (?, ?, ?, ?, ?, ?)";
                    if ($stmt_insert = $conn->prepare($sql_insert)) {
                        $stmt_insert->bind_param("issssi", $company_id_context, $vendor_name, $contact_person, $email, $phone, $is_active);
                        if ($stmt_insert->execute()) {
                            $new_vendor_id = $conn->insert_id;
                            $success_message = "Vendor added successfully.";
                            log_audit_action($conn, 'vendor_created', "Admin created vendor: {$vendor_name}", $user_id, $company_id_context, 'vendor', $new_vendor_id);
                        } else { $error_message = "Error adding vendor: " . $stmt_insert->error; }
                        $stmt_insert->close();
                    }
                }
                $stmt_check->close();
            }
        }
    }
    // If update/add failed, and we were editing, repopulate $edit_vendor
    if(!empty($error_message) && $vendor_id_to_edit) {
        $edit_vendor = ['id' => $vendor_id_to_edit, 'vendor_name' => $vendor_name, 'contact_person' => $contact_person, 'email' => $email, 'phone' => $phone, 'is_active' => $is_active];
    }
}

// Handle request to edit (load data into form)
if (isset($_GET['edit_id']) && empty($_POST)) { // Check empty POST
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT * FROM vendors WHERE id = ? AND company_id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("ii", $edit_id, $company_id_context);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_vendor = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_vendor) { $error_message = "Vendor not found for editing."; }
    }
}

// --- DATA FETCHING ---
// Fetch all vendors for the current company
$vendors_list = [];
$sql_fetch = "SELECT * FROM vendors WHERE company_id = ? ORDER BY vendor_name";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $company_id_context);
    if ($stmt_fetch->execute()) {
        $result = $stmt_fetch->get_result();
        while ($row = $result->fetch_assoc()) {
            $vendors_list[] = $row;
        }
    } else { $error_message = "Error fetching vendors."; }
    $stmt_fetch->close();
}

require_once 'includes/header.php';
?>

<div class="container mt-4">
    <h2>Manage Vendors</h2>
    <p>Add, edit, or disable vendors for your company.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Vendor Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h4><?php echo $edit_vendor ? 'Edit Vendor' : 'Add New Vendor'; ?></h4>
        </div>
        <div class="card-body">
            <form action="purchase/vendors.php" method="post">
                <?php if ($edit_vendor): ?>
                    <input type="hidden" name="edit_vendor_id" value="<?php echo $edit_vendor['id']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="vendor_name" class="form-label">Vendor Name</label>
                        <input type="text" class="form-control" id="vendor_name" name="vendor_name" 
                               value="<?php echo htmlspecialchars($edit_vendor['vendor_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="contact_person" class="form-label">Contact Person</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person" 
                               value="<?php echo htmlspecialchars($edit_vendor['contact_person'] ?? ''); ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="email" class="form-label">Email</label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($edit_vendor['email'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label for="phone" class="form-label">Phone</label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($edit_vendor['phone'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                         <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?php echo ($edit_vendor === null || $edit_vendor['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Vendor is Active</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <?php if ($edit_vendor): ?>
                        <button type="submit" name="update_vendor" class="btn btn-warning">Update Vendor</button>
                        <a href="purchase/vendors.php" class="btn btn-secondary">Cancel Edit</a>
                    <?php else: ?>
                        <button type="submit" name="add_vendor" class="btn btn-primary">Add Vendor</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Vendors List -->
    <div class="card">
        <div class="card-header">
            <h4>Existing Vendors</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Vendor Name</th>
                            <th>Contact Person</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vendors_list)): ?>
                            <tr><td colspan="6" class="text-center text-muted">No vendors defined yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vendors_list as $vendor): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($vendor['vendor_name']); ?></td>
                                    <td><?php echo htmlspecialchars($vendor['contact_person']); ?></td>
                                    <td><?php echo htmlspecialchars($vendor['email']); ?></td>
                                    <td><?php echo htmlspecialchars($vendor['phone']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $vendor['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $vendor['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="purchase/vendors.php?edit_id=<?php echo $vendor['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="purchase/vendors.php" method="post" class="d-inline" onsubmit="return confirm('Delete this vendor? This might fail if they are linked to existing POs.');">
                                            <input type="hidden" name="vendor_id" value="<?php echo $vendor['id']; ?>">
                                            <button type="submit" name="delete_vendor" class="btn btn-danger btn-sm">Delete</button>
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
require_once 'includes/footer.php';
?>


