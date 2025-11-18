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
function handle_stock_upload($file_input_name, $store_id) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file_info = $_FILES[$file_input_name];
        $target_dir = dirname(__DIR__) . '/uploads/stock_counts/'; //  /htdocs/uploads/stock_counts/
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        
        $file_extension = strtolower(pathinfo($file_info["name"], PATHINFO_EXTENSION));
        $safe_filename = "stock_store{$store_id}_" . date('Y-m-d') . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
        $allowed_types = ['pdf', 'xls', 'xlsx', 'csv'];
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception("Invalid file type. Only PDF, XLS, XLSX, or CSV allowed.");
        }
        if ($file_info['size'] > 10 * 1024 * 1024) { // 10MB limit
            throw new Exception("File is too large (Max 10MB).");
        }
        if (!move_uploaded_file($file_info["tmp_name"], $target_file)) {
            throw new Exception("Failed to save uploaded file.");
        }
        return "uploads/stock_counts/" . $safe_filename; // Return the path to save in DB
    }
    return null; // No file uploaded or error
}

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_stock_count'])) {
    $report_date = $_POST['report_date'];
    $store_id = $_POST['store_id'];
    $system_count = (int)$_POST['system_count'];
    $physical_count = (int)$_POST['physical_count'];
    $remarks = trim($_POST['remarks']);
    
    // Validate store ID
    $is_valid_store = false;
    foreach($my_stores as $store) { if($store['id'] == $store_id) $is_valid_store = true; }
    if(!$is_valid_store) {
        $error_message = "You do not have permission to submit a report for this store.";
    } elseif ($system_count < 0 || $physical_count < 0) {
        $error_message = "Counts cannot be negative.";
    }

    if(empty($error_message)) {
        try {
            // Handle file upload
            $file_url = handle_stock_upload('stock_file', $store_id);

            // INSERT or UPDATE (UPSERT)
            $sql = "INSERT INTO report_stock_count 
                    (company_id, store_id, user_id, report_date, system_count, physical_count, remarks, file_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    system_count = VALUES(system_count),
                    physical_count = VALUES(physical_count),
                    remarks = VALUES(remarks),
                    file_url = IF(VALUES(file_url) IS NOT NULL, VALUES(file_url), file_url)"; // Only update file if new one is uploaded
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiisiiss", 
                $company_id_context, $store_id, $user_id, $report_date, 
                $system_count, $physical_count, $remarks, $file_url
            );

            if($stmt->execute()){
                $success_message = "Monthly stock count for {$report_date} saved successfully.";
                log_audit_action($conn, 'stock_count_logged', "Logged stock count for store {$store_id} on {$report_date}", $user_id, $company_id_context, 'stock_report', $store_id);
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
    <h2>Monthly Stock Count</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Reports</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<form action="reports/stock_count.php" method="post" enctype="multipart/form-data">
    <div class="card">
        <div class="card-header">
            <h4>Submit Monthly Stock Count</h4>
        </div>
        <div class="card-body">
             <p>Submit your physical stock count vs. the system count for the specified date. This will overwrite any existing entry for the same store and date.</p>
             <div class="row g-3">
                <div class="col-md-4">
                    <label for="report_date" class="form-label">Report Date</label>
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-8">
                    <label for="store_id" class="form-label">Your Store / Cost Center</label>
                    <select id="store_id" name="store_id" class="form-select" required>
                        <option value="">-- Select Your Store --</option>
                        <?php foreach ($my_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if(empty($my_stores)): ?><option value="" disabled>You are not assigned to any stores. Contact Admin.</option><?php endif; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="system_count" class="form-label">System Count (Units)</label>
                    <input type="number" class="form-control" id="system_count" name="system_count" min="0" required>
                </div>
                <div class="col-md-6">
                    <label for="physical_count" class="form-label">Physical Count (Units)</label>
                    <input type="number" class="form-control" id="physical_count" name="physical_count" min="0" required>
                </div>
                <div class="col-12">
                    <label for="remarks" class="form-label">Remarks (Optional)</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Explain any discrepancies..."></textarea>
                </div>
                <div class="col-12">
                    <label for="stock_file" class="form-label">Upload Count Sheet (Optional)</label>
                    <input type="file" class="form-control" name="stock_file" id="stock_file" accept=".pdf,.xls,.xlsx,.csv">
                    <div class="form-text">Upload your scanned physical count sheet or spreadsheet. (Max 10MB)</div>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_stock_count" class="btn btn-primary btn-lg">Submit Stock Count</button>
        </div>
    </div>
</form>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>

