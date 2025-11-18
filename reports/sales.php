<?php
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$success_message = '';

// --- DATA ---
$sources = [
    'btl' => 'BTL',
    'mall_walkin' => 'Mall Walkin',
    'social_media' => 'Social Media',
    'repeat_customer' => 'Repeat Customer',
    'reference' => 'Reference Customer',
    'calling' => 'Calling'
];

$metrics = [
    'walkins' => 'No. of Walkins',
    'bills' => 'No. of Bills',
    'value' => 'Total Sale Value',
    'units' => 'Total Units',
    'advance' => 'Total Advance Value',
    'fsp_value' => 'Total FSP Value',
    'fsp_count' => 'Total FSP Count'
];

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

// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_sales_report'])) {
    $report_date = $_POST['report_date'];
    $store_id = $_POST['store_id'];
    
    // Validate store ID
    $is_valid_store = false;
    foreach($my_stores as $store) { if($store['id'] == $store_id) $is_valid_store = true; }
    if(!$is_valid_store) {
        $error_message = "You do not have permission to submit a report for this store.";
    }

    if(empty($error_message)) {
        // Build the big SQL query
        $sql_insert = "INSERT INTO report_sales_daily (company_id, store_id, user_id, report_date, 
            source_btl, source_mall_walkin, source_social_media, source_repeat_customer, source_reference, source_calling,
            bills_btl, bills_mall_walkin, bills_social_media, bills_repeat_customer, bills_reference, bills_calling,
            value_btl, value_mall_walkin, value_social_media, value_repeat_customer, value_reference, value_calling,
            units_btl, units_mall_walkin, units_social_media, units_repeat_customer, units_reference, units_calling,
            advance_btl, advance_mall_walkin, advance_social_media, advance_repeat_customer, advance_reference, advance_calling,
            fsp_value_btl, fsp_value_mall_walkin, fsp_value_social_media, fsp_value_repeat_customer, fsp_value_reference, fsp_value_calling,
            fsp_count_btl, fsp_count_mall_walkin, fsp_count_social_media, fsp_count_repeat_customer, fsp_count_reference, fsp_count_calling
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $sql_update = " ON DUPLICATE KEY UPDATE 
            user_id = VALUES(user_id),
            source_btl = VALUES(source_btl), source_mall_walkin = VALUES(source_mall_walkin), source_social_media = VALUES(source_social_media), source_repeat_customer = VALUES(source_repeat_customer), source_reference = VALUES(source_reference), source_calling = VALUES(source_calling),
            bills_btl = VALUES(bills_btl), bills_mall_walkin = VALUES(bills_mall_walkin), bills_social_media = VALUES(bills_social_media), bills_repeat_customer = VALUES(bills_repeat_customer), bills_reference = VALUES(bills_reference), bills_calling = VALUES(bills_calling),
            value_btl = VALUES(value_btl), value_mall_walkin = VALUES(value_mall_walkin), value_social_media = VALUES(value_social_media), value_repeat_customer = VALUES(value_repeat_customer), value_reference = VALUES(value_reference), value_calling = VALUES(value_calling),
            units_btl = VALUES(units_btl), units_mall_walkin = VALUES(units_mall_walkin), units_social_media = VALUES(units_social_media), units_repeat_customer = VALUES(units_repeat_customer), units_reference = VALUES(units_reference), units_calling = VALUES(units_calling),
            advance_btl = VALUES(advance_btl), advance_mall_walkin = VALUES(advance_mall_walkin), advance_social_media = VALUES(advance_social_media), advance_repeat_customer = VALUES(advance_repeat_customer), advance_reference = VALUES(advance_reference), advance_calling = VALUES(advance_calling),
            fsp_value_btl = VALUES(fsp_value_btl), fsp_value_mall_walkin = VALUES(fsp_value_mall_walkin), fsp_value_social_media = VALUES(fsp_value_social_media), fsp_value_repeat_customer = VALUES(fsp_value_repeat_customer), fsp_value_reference = VALUES(fsp_value_reference), fsp_value_calling = VALUES(fsp_value_calling),
            fsp_count_btl = VALUES(fsp_count_btl), fsp_count_mall_walkin = VALUES(fsp_count_mall_walkin), fsp_count_social_media = VALUES(fsp_count_social_media), fsp_count_repeat_customer = VALUES(fsp_count_repeat_customer), fsp_count_reference = VALUES(fsp_count_reference), fsp_count_calling = VALUES(fsp_count_calling)";
        
        $stmt = $conn->prepare($sql_insert . $sql_update);
        
        $params = [
            $company_id_context, $store_id, $user_id, $report_date,
            (int)($_POST['walkins']['btl'] ?? 0), (int)($_POST['walkins']['mall_walkin'] ?? 0), (int)($_POST['walkins']['social_media'] ?? 0), (int)($_POST['walkins']['repeat_customer'] ?? 0), (int)($_POST['walkins']['reference'] ?? 0), (int)($_POST['walkins']['calling'] ?? 0),
            (int)($_POST['bills']['btl'] ?? 0), (int)($_POST['bills']['mall_walkin'] ?? 0), (int)($_POST['bills']['social_media'] ?? 0), (int)($_POST['bills']['repeat_customer'] ?? 0), (int)($_POST['bills']['reference'] ?? 0), (int)($_POST['bills']['calling'] ?? 0),
            (float)($_POST['value']['btl'] ?? 0), (float)($_POST['value']['mall_walkin'] ?? 0), (float)($_POST['value']['social_media'] ?? 0), (float)($_POST['value']['repeat_customer'] ?? 0), (float)($_POST['value']['reference'] ?? 0), (float)($_POST['value']['calling'] ?? 0),
            (int)($_POST['units']['btl'] ?? 0), (int)($_POST['units']['mall_walkin'] ?? 0), (int)($_POST['units']['social_media'] ?? 0), (int)($_POST['units']['repeat_customer'] ?? 0), (int)($_POST['units']['reference'] ?? 0), (int)($_POST['units']['calling'] ?? 0),
            (float)($_POST['advance']['btl'] ?? 0), (float)($_POST['advance']['mall_walkin'] ?? 0), (float)($_POST['advance']['social_media'] ?? 0), (float)($_POST['advance']['repeat_customer'] ?? 0), (float)($_POST['advance']['reference'] ?? 0), (float)($_POST['advance']['calling'] ?? 0),
            (float)($_POST['fsp_value']['btl'] ?? 0), (float)($_POST['fsp_value']['mall_walkin'] ?? 0), (float)($_POST['fsp_value']['social_media'] ?? 0), (float)($_POST['fsp_value']['repeat_customer'] ?? 0), (float)($_POST['fsp_value']['reference'] ?? 0), (float)($_POST['fsp_value']['calling'] ?? 0),
            (int)($_POST['fsp_count']['btl'] ?? 0), (int)($_POST['fsp_count']['mall_walkin'] ?? 0), (int)($_POST['fsp_count']['social_media'] ?? 0), (int)($_POST['fsp_count']['repeat_customer'] ?? 0), (int)($_POST['fsp_count']['reference'] ?? 0), (int)($_POST['fsp_count']['calling'] ?? 0)
        ];
        $types = "iiisi" . str_repeat("i", 5) . "i" . str_repeat("i", 5) . "d" . str_repeat("d", 5) . "i" . str_repeat("i", 5) . "d" . str_repeat("d", 5) . "d" . str_repeat("d", 5) . "i" . str_repeat("i", 5);

        if($stmt) {
            $stmt->bind_param($types, ...$params);
            if($stmt->execute()){
                $success_message = "Daily Sales Report for {$report_date} saved successfully.";
                log_audit_action($conn, 'sales_report_saved', "Saved daily sales report for {$report_date}", $user_id, $company_id_context, 'sales_report', $store_id);
            } else { $error_message = "Error saving report: " . $stmt->error; }
            $stmt->close();
        } else { $error_message = "DB Prepare Error: " . $conn->error; }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Daily Sales Report</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Reports</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<form action="reports/sales.php" method="post">
    <div class="card">
        <div class="card-header">
            <h4>Submit Daily Sales Data</h4>
        </div>
        <div class="card-body">
             <div class="row g-3 mb-3">
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
            </div>
            
            <div class="table-responsive">
                <table class="table table-bordered text-center">
                    <thead class="table-light">
                        <tr>
                            <th class="text-start">Metric</th>
                            <?php foreach($sources as $key => $label): ?>
                                <th><?php echo $label; ?></th>
                            <?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($metrics as $metric_key => $metric_label): ?>
                        <tr>
                            <td class="text-start fw-bold"><?php echo $metric_label; ?></td>
                            <?php foreach($sources as $source_key => $source_label): ?>
                                <td>
                                    <input type="number" 
                                           class="form-control form-control-sm text-center" 
                                           name="<?php echo $metric_key; ?>[<?php echo $source_key; ?>]" 
                                           value="0" 
                                           min="0"
                                           step="<?php echo (strpos($metric_key, 'value') !== false) ? '0.01' : '1'; ?>">
                                </td>
                            <?php endforeach; ?>
                            <td class="align-middle">
                                <!-- Totals can be calculated via JS later if needed -->
                                <input type="number" class="form-control form-control-sm text-center" readonly disabled>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_sales_report" class="btn btn-primary btn-lg">Save/Update Sales Report</button>
        </div>
    </div>
</form>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


