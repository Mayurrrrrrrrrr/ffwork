<?php
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$success_message = '';

// Fetch stores this user is assigned to (same logic as sales report)
$my_stores = [];
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 
               AND (EXISTS (SELECT 1 FROM users WHERE id = ? AND store_id = stores.id) OR ? = 1)"; // Allow admin
if($stmt_stores = $conn->prepare($sql_stores)){
    $is_admin_check = (int)has_any_role(['admin', 'platform_admin']);
    $stmt_stores->bind_param("iii", $company_id_context, $user_id, $is_admin_check);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while($row_store = $result_stores->fetch_assoc()){ $my_stores[] = $row_store; }
    $stmt_stores->close();
}

// --- FILE UPLOAD FUNCTION ---
// (This is a simplified helper function. A robust version would check file types and sizes.)
function handle_file_upload($file_input_name, $store_id, $type) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file_info = $_FILES[$file_input_name];
        $target_dir = dirname(__DIR__) . '/uploads/checklists/'; //  /htdocs/uploads/checklists/
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        
        $file_extension = strtolower(pathinfo($file_info["name"], PATHINFO_EXTENSION));
        $safe_filename = "store{$store_id}_{$type}_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
        // Basic security check for image
        $allowed_types = ['jpg', 'jpeg', 'png', 'heic'];
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception("Invalid file type for '{$file_input_name}'. Only JPG, PNG, or HEIC allowed.");
        }
        if ($file_info['size'] > 10 * 1024 * 1024) { // 10MB limit
            throw new Exception("File '{$file_input_name}' is too large (Max 10MB).");
        }
        if (!move_uploaded_file($file_info["tmp_name"], $target_file)) {
            throw new Exception("Failed to save uploaded file '{$file_input_name}'.");
        }
        return "uploads/checklists/" . $safe_filename; // Return the path to save in DB
    }
    return null; // No file uploaded or error
}

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_checklist'])) {
    $report_date = $_POST['report_date'];
    $store_id = $_POST['store_id'];
    $checklist_type = $_POST['checklist_type']; // 'Opening' or 'Closing'
    $remarks = trim($_POST['remarks']);
    
    // Validate store ID
    $is_valid_store = false;
    foreach($my_stores as $store) { if($store['id'] == $store_id) $is_valid_store = true; }
    if(!$is_valid_store) {
        $error_message = "You do not have permission to submit a report for this store.";
    }

    if(empty($error_message)) {
        try {
            // Handle file uploads
            $photo_front_url = handle_file_upload('photo_front', $store_id, $checklist_type);
            $photo_display_url = handle_file_upload('photo_display', $store_id, $checklist_type);
            $photo_safe_url = handle_file_upload('photo_safe', $store_id, $checklist_type);

            // Check if a report for this store/date/type already exists
            $sql_check = "SELECT id, photo_front_url, photo_display_url, photo_safe_url 
                          FROM report_store_checklist 
                          WHERE company_id = ? AND store_id = ? AND report_date = ? AND checklist_type = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("iiss", $company_id_context, $store_id, $report_date, $checklist_type);
            $stmt_check->execute();
            $existing_report = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($existing_report) {
                // UPDATE existing report
                $sql = "UPDATE report_store_checklist SET 
                            user_id = ?, 
                            photo_front_url = ?, 
                            photo_display_url = ?, 
                            photo_safe_url = ?, 
                            remarks = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                // Use new file path if uploaded, otherwise keep the old one
                $photo_front_url = $photo_front_url ?? $existing_report['photo_front_url'];
                $photo_display_url = $photo_display_url ?? $existing_report['photo_display_url'];
                $photo_safe_url = $photo_safe_url ?? $existing_report['photo_safe_url'];
                
                $stmt->bind_param("issssi", $user_id, $photo_front_url, $photo_display_url, $photo_safe_url, $remarks, $existing_report['id']);
                $log_action = 'store_checklist_updated';
                $log_message = "Updated {$checklist_type} checklist for {$report_date}";
            } else {
                // INSERT new report
                $sql = "INSERT INTO report_store_checklist 
                        (company_id, store_id, user_id, report_date, checklist_type, photo_front_url, photo_display_url, photo_safe_url, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiissssss", $company_id_context, $store_id, $user_id, $report_date, $checklist_type, $photo_front_url, $photo_display_url, $photo_safe_url, $remarks);
                $log_action = 'store_checklist_created';
                $log_message = "Created {$checklist_type} checklist for {$report_date}";
            }

            if($stmt->execute()){
                $success_message = "Store {$checklist_type} Checklist for {$report_date} saved successfully.";
                $report_record_id = $existing_report['id'] ?? $conn->insert_id;
                log_audit_action($conn, $log_action, $log_message, $user_id, $company_id_context, 'store_checklist', $report_record_id);
            } else {
                $error_message = "Error saving checklist: " . $stmt->error;
            }
            $stmt->close();

        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Daily Store Checklist</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Reports</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<form action="reports/checklist.php" method="post" enctype="multipart/form-data">
    <div class="card">
        <div class="card-header">
            <h4>Submit Opening / Closing Checklist</h4>
        </div>
        <div class="card-body">
             <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label for="report_date" class="form-label">Report Date</label>
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label for="store_id" class="form-label">Your Store / Cost Center</label>
                    <select id="store_id" name="store_id" class="form-select" required>
                        <option value="">-- Select Your Store --</option>
                        <?php foreach ($my_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if(empty($my_stores)): ?><option value="" disabled>You are not assigned to any stores. Contact Admin.</option><?php endif; ?>
                    </select>
                </div>
                 <div class="col-md-4">
                    <label for="checklist_type" class="form-label">Checklist Type</label>
                    <select id="checklist_type" name="checklist_type" class="form-select" required>
                        <option value="Opening">Opening Checklist</option>
                        <option value="Closing">Closing Checklist</option>
                    </select>
                </div>
            </div>
            
            <p class="text-muted">Upload photos for verification. The system will update any existing report for the selected store, date, and type.</p>
            
            <div class="row g-3">
                <div class="col-md-4">
                    <label for="photo_front" class="form-label">1. Store Front / Shutter</label>
                    <input type="file" class="form-control" name="photo_front" id="photo_front" accept="image/*" capture="environment">
                </div>
                <div class="col-md-4">
                    <label for="photo_display" class="form-label">2. Main Display Area</label>
                    <input type="file" class="form-control" name="photo_display" id="photo_display" accept="image/*" capture="environment">
                </div>
                 <div class="col-md-4">
                    <label for="photo_safe" class="form-label">3. Safe / Vault (Closed)</label>
                    <input type="file" class="form-control" name="photo_safe" id="photo_safe" accept="image/*" capture="environment">
                </div>
                <div class="col-12">
                     <label for="remarks" class="form-label">Remarks (Optional)</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Note any issues, e.g., 'Display light #3 not working.'"></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_checklist" class="btn btn-primary btn-lg">Submit Checklist</button>
        </div>
    </div>
</form>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>

