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
function handle_competition_upload($file_input_name, $store_id) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file_info = $_FILES[$file_input_name];
        $target_dir = dirname(__DIR__) . '/uploads/competition/'; //  /htdocs/uploads/competition/
        if (!is_dir($target_dir)) { @mkdir($target_dir, 0755, true); }
        
        $file_extension = strtolower(pathinfo($file_info["name"], PATHINFO_EXTENSION));
        $safe_filename = "comp_store{$store_id}_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
        $allowed_types = ['jpg', 'jpeg', 'png', 'heic', 'pdf'];
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception("Invalid file type. Only JPG, PNG, HEIC, or PDF allowed.");
        }
        if ($file_info['size'] > 10 * 1024 * 1024) { // 10MB limit
            throw new Exception("File is too large (Max 10MB).");
        }
        if (!move_uploaded_file($file_info["tmp_name"], $target_file)) {
            throw new Exception("Failed to save uploaded file.");
        }
        return "uploads/competition/" . $safe_filename; // Return the path to save in DB
    }
    return null; // No file uploaded or error
}

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_competition_data'])) {
    $report_date = $_POST['report_date'];
    $store_id = $_POST['store_id'];
    $competitor_name = trim($_POST['competitor_name']);
    $activity_type = trim($_POST['activity_type']);
    $offer_details = trim($_POST['offer_details']);
    $remarks = trim($_POST['remarks']);
    
    // Validate store ID
    $is_valid_store = false;
    foreach($my_stores as $store) { if($store['id'] == $store_id) $is_valid_store = true; }
    if(!$is_valid_store) {
        $error_message = "You do not have permission to submit a report for this store.";
    } elseif (empty($competitor_name) || empty($activity_type)) {
        $error_message = "Competitor Name and Activity Type are required.";
    }

    if(empty($error_message)) {
        try {
            // Handle file upload
            $photo_url = handle_competition_upload('photo_url', $store_id);

            // INSERT new report (this form is for single entries, not updates)
            $sql = "INSERT INTO report_competition_data 
                    (company_id, store_id, user_id, report_date, competitor_name, activity_type, offer_details, remarks, photo_url)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("iiissssss", 
                $company_id_context, $store_id, $user_id, $report_date, 
                $competitor_name, $activity_type, $offer_details, $remarks, $photo_url
            );

            if($stmt->execute()){
                $new_id = $conn->insert_id;
                $success_message = "Competition data for '{$competitor_name}' saved successfully.";
                log_audit_action($conn, 'competition_data_logged', "Logged competition data for {$competitor_name}", $user_id, $company_id_context, 'competition_report', $new_id);
                // We can clear the form or redirect
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
    <h2>Competition Data Collection</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Reports</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<form action="reports/competition.php" method="post" enctype="multipart/form-data">
    <div class="card">
        <div class="card-header">
            <h4>Log New Competitor Activity</h4>
        </div>
        <div class="card-body">
             <div class="row g-3">
                <div class="col-md-4">
                    <label for="report_date" class="form-label">Date of Observation</label>
                    <input type="date" class="form-control" id="report_date" name="report_date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="col-md-8">
                    <label for="store_id" class="form-label">Your Store (for reference)</label>
                    <select id="store_id" name="store_id" class="form-select" required>
                        <option value="">-- Select Your Store --</option>
                        <?php foreach ($my_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>"><?php echo htmlspecialchars($store['store_name']); ?></option>
                        <?php endforeach; ?>
                        <?php if(empty($my_stores)): ?><option value="" disabled>You are not assigned to any stores. Contact Admin.</option><?php endif; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="competitor_name" class="form-label">Competitor Name</label>
                    <input type="text" class="form-control" id="competitor_name" name="competitor_name" required>
                </div>
                <div class="col-md-6">
                    <label for="activity_type" class="form-label">Activity Type</label>
                     <select id="activity_type" name="activity_type" class="form-select" required>
                        <option value="">-- Select Type --</option>
                        <option value="New Collection Launch">New Collection Launch</option>
                        <option value="Discount / Offer">Discount / Offer</option>
                        <option value="In-Store Event">In-Store Event</option>
                        <option value="Outdoor Advertisement">Outdoor Advertisement</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-12">
                    <label for="offer_details" class="form-label">Offer / Activity Details</label>
                    <textarea class="form-control" id="offer_details" name="offer_details" rows="2" placeholder="e.g., 'Flat 20% off on making charges', 'New Diwali Collection promoted'"></textarea>
                </div>
                <div class="col-12">
                    <label for="remarks" class="form-label">Your Remarks (Optional)</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="e.g., 'Heavy footfall observed', 'Ad was placed on main road'"></textarea>
                </div>
                <div class="col-12">
                    <label for="photo_url" class="form-label">Upload Photo (Optional)</label>
                    <input type="file" class="form-control" name="photo_url" id="photo_url" accept="image/*,application/pdf">
                    <div class="form-text">Upload a photo of the offer, advertisement, or event. (Max 10MB)</div>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_competition_data" class="btn btn-primary btn-lg">Submit Data</button>
        </div>
    </div>
</form>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>

