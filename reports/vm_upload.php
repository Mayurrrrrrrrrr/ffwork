<?php
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$success_message = '';

// Fetch stores this user is assigned to
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
function handle_vm_upload($file_input_name, $store_id) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file_info = $_FILES[$file_input_name];
        $target_dir = dirname(__DIR__) . '/uploads/vm_pictures/'; //  /htdocs/uploads/vm_pictures/
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        
        $file_extension = strtolower(pathinfo($file_info["name"], PATHINFO_EXTENSION));
        $safe_filename = "vm_store{$store_id}_" . date('Y-m-d') . "_{$file_input_name}_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
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
        return "uploads/vm_pictures/" . $safe_filename; // Return the path to save in DB
    }
    return null; // No file uploaded or error
}

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_vm_report'])) {
    $report_date = $_POST['report_date'];
    $store_id = $_POST['store_id'];
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
            $photo_url_1 = handle_vm_upload('photo_url_1', $store_id);
            $photo_url_2 = handle_vm_upload('photo_url_2', $store_id);
            $photo_url_3 = handle_vm_upload('photo_url_3', $store_id);
            $photo_url_4 = handle_vm_upload('photo_url_4', $store_id);

            // Check if a report for this store/date already exists
            $sql_check = "SELECT id, photo_url_1, photo_url_2, photo_url_3, photo_url_4
                          FROM report_vm_pictures 
                          WHERE company_id = ? AND store_id = ? AND report_date = ?";
            $stmt_check = $conn->prepare($sql_check);
            $stmt_check->bind_param("iis", $company_id_context, $store_id, $report_date);
            $stmt_check->execute();
            $existing_report = $stmt_check->get_result()->fetch_assoc();
            $stmt_check->close();

            if ($existing_report) {
                // UPDATE existing report
                $sql = "UPDATE report_vm_pictures SET 
                            user_id = ?, 
                            photo_url_1 = IF(? IS NOT NULL, ?, photo_url_1), 
                            photo_url_2 = IF(? IS NOT NULL, ?, photo_url_2), 
                            photo_url_3 = IF(? IS NOT NULL, ?, photo_url_3), 
                            photo_url_4 = IF(? IS NOT NULL, ?, photo_url_4), 
                            remarks = ?
                        WHERE id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("issssssssssi", 
                    $user_id, 
                    $photo_url_1, $photo_url_1,
                    $photo_url_2, $photo_url_2,
                    $photo_url_3, $photo_url_3,
                    $photo_url_4, $photo_url_4,
                    $remarks, 
                    $existing_report['id']
                );
                $log_action = 'vm_report_updated';
                $log_message = "Updated VM picture report for {$report_date}";
            } else {
                // INSERT new report
                $sql = "INSERT INTO report_vm_pictures 
                        (company_id, store_id, user_id, report_date, photo_url_1, photo_url_2, photo_url_3, photo_url_4, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("iiissssss", 
                    $company_id_context, $store_id, $user_id, $report_date, 
                    $photo_url_1, $photo_url_2, $photo_url_3, $photo_url_4, $remarks
                );
                $log_action = 'vm_report_created';
                $log_message = "Created VM picture report for {$report_date}";
            }

            if($stmt->execute()){
                $success_message = "VM Picture Report for {$report_date} saved successfully.";
                $report_record_id = $existing_report['id'] ?? $conn->insert_id;
                log_audit_action($conn, $log_action, $log_message, $user_id, $company_id_context, 'vm_report', $report_record_id);
            } else {
                $error_message = "Error saving data: " . $stmt->error;
            }
            $stmt->close();

        } catch (Exception $e) {
            $error_message = $e->getMessage();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Visual Merchandising (VM) Picture Upload</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Reports</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<form action="reports/vm_upload.php" method="post" enctype="multipart/form-data">
    <div class="card">
        <div class="card-header">
            <h4>Submit VM Pictures</h4>
        </div>
        <div class="card-body">
             <p>Upload your store's VM pictures for the specified date. This will overwrite any existing entry for the same store and date.</p>
             <div class="row g-3">
                <div class="col-md-6">
                    <label for="report_date" class="form-label">Report Date</label>
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-6">
                    <label for="store_id" class="form-label">Your Store / Cost Center</label>
                    <select id="store_id" name="store_id" class="form-select" required>
                        <option value="">-- Select Your Store --</option>
                        <?php foreach ($my_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if(empty($my_stores)): ?><option value="" disabled>You are not assigned to any stores. Contact Admin.</option><?php endif; ?>
                    </select>
                </div>
                
                <div class="col-12">
                    <hr>
                    <h5 class="mb-3">Upload Pictures</h5>
                </div>

                <div class="col-md-6 col-lg-3">
                    <label for="photo_url_1" class="form-label">Photo 1 (e.g., Main Display)</label>
                    <input type="file" class="form-control" name="photo_url_1" id="photo_url_1" accept="image/*" capture="environment">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="photo_url_2" class="form-label">Photo 2 (e.g., Window Display)</label>
                    <input type="file" class="form-control" name="photo_url_2" id="photo_url_2" accept="image/*" capture="environment">
                </div>
                <div class="col-md-6 col-lg-3">
                    <label for="photo_url_3" class="form-label">Photo 3 (e.g., Side Display)</label>
                    <input type="file" class="form-control" name="photo_url_3" id="photo_url_3" accept="image/*" capture="environment">
                </div>
                 <div class="col-md-6 col-lg-3">
                    <label for="photo_url_4" class="form-label">Photo 4 (e.g., Other)</label>
                    <input type="file" class="form-control" name="photo_url_4" id="photo_url_4" accept="image/*" capture="environment">
                </div>
               
                <div class="col-12">
                    <label for="remarks" class="form-label">Remarks (Optional)</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Describe the VM theme or any issues..."></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_vm_report" class="btn btn-primary btn-lg">Submit VM Report</button>
        </div>
    </div>
</form>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>

