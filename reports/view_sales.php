<?php
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: Only managers/admins can view compiled reports --
if (!$is_manager) {
    header("location: " . BASE_URL . "reports/index.php"); 
    exit;
}

// --- Set default date filters ---
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d');     // Default to today
$store_filter = $_GET['store_id'] ?? 'all';

// --- Data Arrays (from your design) ---
$sources = [
    'btl' => 'BTL', 'mall_walkin' => 'Mall Walkin', 'social_media' => 'Social Media',
    'repeat_customer' => 'Repeat', 'reference' => 'Reference', 'calling' => 'Calling'
];
$metrics = [
    'walkins' => 'No. of Walkins', 'bills' => 'No. of Bills', 'value' => 'Total Sale Value',
    'units' => 'Total Units', 'advance' => 'Total Advance Value', 'fsp_value' => 'Total FSP Value',
    'fsp_count' => 'Total FSP Count'
];

// --- DATA FETCHING ---
$all_stores = [];
$report_data = [];
$error_message = '';

// Fetch stores for the filter
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
if($stmt_stores = $conn->prepare($sql_stores)){
    $stmt_stores->bind_param("i", $company_id_context);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while($row_store = $result_stores->fetch_assoc()){ $all_stores[] = $row_store; }
    $stmt_stores->close();
}

// --- Build the main SQL query ---
// We use SUM() to compile all data within the date range
$sql_fields = [
    "SUM(source_btl) as walkins_btl", "SUM(source_mall_walkin) as walkins_mall_walkin", "SUM(source_social_media) as walkins_social_media",
    "SUM(source_repeat_customer) as walkins_repeat_customer", "SUM(source_reference) as walkins_reference", "SUM(source_calling) as walkins_calling",
    "SUM(bills_btl) as bills_btl", "SUM(bills_mall_walkin) as bills_mall_walkin", "SUM(bills_social_media) as bills_social_media",
    "SUM(bills_repeat_customer) as bills_repeat_customer", "SUM(bills_reference) as bills_reference", "SUM(bills_calling) as bills_calling",
    "SUM(value_btl) as value_btl", "SUM(value_mall_walkin) as value_mall_walkin", "SUM(value_social_media) as value_social_media",
    "SUM(value_repeat_customer) as value_repeat_customer", "SUM(value_reference) as value_reference", "SUM(value_calling) as value_calling",
    "SUM(units_btl) as units_btl", "SUM(units_mall_walkin) as units_mall_walkin", "SUM(units_social_media) as units_social_media",
    "SUM(units_repeat_customer) as units_repeat_customer", "SUM(units_reference) as units_reference", "SUM(units_calling) as units_calling",
    "SUM(advance_btl) as advance_btl", "SUM(advance_mall_walkin) as advance_mall_walkin", "SUM(advance_social_media) as advance_social_media",
    "SUM(advance_repeat_customer) as advance_repeat_customer", "SUM(advance_reference) as advance_reference", "SUM(advance_calling) as advance_calling",
    "SUM(fsp_value_btl) as fsp_value_btl", "SUM(fsp_value_mall_walkin) as fsp_value_mall_walkin", "SUM(fsp_value_social_media) as fsp_value_social_media",
    "SUM(fsp_value_repeat_customer) as fsp_value_repeat_customer", "SUM(fsp_value_reference) as fsp_value_reference", "SUM(fsp_value_calling) as fsp_value_calling",
    "SUM(fsp_count_btl) as fsp_count_btl", "SUM(fsp_count_mall_walkin) as fsp_count_mall_walkin", "SUM(fsp_count_social_media) as fsp_count_social_media",
    "SUM(fsp_count_repeat_customer) as fsp_count_repeat_customer", "SUM(fsp_count_reference) as fsp_count_reference", "SUM(fsp_count_calling) as fsp_count_calling"
];

$sql_query = "SELECT " . implode(", ", $sql_fields) . " FROM report_sales_daily WHERE company_id = ? AND report_date BETWEEN ? AND ?";
$params = [$company_id_context, $start_date, $end_date];
$types = "iss";

if($store_filter !== 'all' && is_numeric($store_filter)){
    $sql_query .= " AND store_id = ?";
    $params[] = $store_filter;
    $types .= "i";
}

if($stmt = $conn->prepare($sql_query)){
    $stmt->bind_param($types, ...$params);
    if($stmt->execute()){
        $report_data = $stmt->get_result()->fetch_assoc();
    } else { $error_message = "Error generating report: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Daily Sales Report</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Forms</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- Filter Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form action="reports/view_sales.php" method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="store_id" class="form-label">Store / Cost Center</label>
                <select id="store_id" name="store_id" class="form-select">
                    <option value="all">All Stores</option>
                    <?php foreach ($all_stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php if($store_filter == $store['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($store['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Results -->
<div class="card printable-area">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <h4>Compiled Sales Data</h4>
            <p class="mb-0 text-muted">Showing results for: <strong><?php echo htmlspecialchars($start_date); ?></strong> to <strong><?php echo htmlspecialchars($end_date); ?></strong></p>
        </div>
        <button onclick="window.print()" class="btn btn-outline-secondary no-print"><i data-lucide="printer" class="me-2"></i>Print</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-bordered text-center">
                <thead class="table-light">
                    <tr>
                        <th class="text-start">Metric</th>
                        <?php foreach($sources as $label): ?>
                            <th><?php echo $label; ?></th>
                        <?php endforeach; ?>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($report_data)): ?>
                        <tr><td colspan="<?php echo count($sources) + 2; ?>" class="text-center text-muted">No data found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach($metrics as $metric_key => $metric_label): ?>
                        <tr>
                            <td class="text-start fw-bold"><?php echo $metric_label; ?></td>
                            <?php 
                            $total_row = 0;
                            foreach($sources as $source_key => $source_label): 
                                $cell_value = (float)($report_data[$metric_key . '_' . $source_key] ?? 0);
                                $total_row += $cell_value;
                                $is_value = (strpos($metric_key, 'value') !== false);
                            ?>
                                <td><?php echo $is_value ? 'Rs. ' . number_format($cell_value, 2) : number_format($cell_value); ?></td>
                            <?php endforeach; ?>
                            <td class="fw-bold table-active">
                                <?php echo $is_value ? 'Rs. ' . number_format($total_row, 2) : number_format($total_row); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


