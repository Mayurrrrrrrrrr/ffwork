<?php
// admin/kpi_comparison_dashboard.php

// --- DEBUGGING: Force PHP to show errors. ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- END DEBUGGING ---

require_once '../includes/init.php'; 
require_once '../includes/header.php'; // Use '../' to go up one directory

// --- SECURITY CHECK: Only Admins can access this ---
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: ". BASE_URL . "portal_home.php");
    exit;
}

// --- NEW: Helper function to format in Lakhs ---
function formatInLakhs($number) {
    if (!is_numeric($number) || $number == 0) {
        return '0.00';
    }
    $lakhs = $number / 100000;
    return number_format($lakhs, 2);
}

// --- Set default date filters ---
$today = date('Y-m-d');
$start_date_1 = $_GET['start_date_1'] ?? date('Y-m-d', strtotime('-30 days')); 
$end_date_1 = $_GET['end_date_1'] ?? $today;
$start_date_2 = $_GET['start_date_2'] ?? date('Y-m-d', strtotime('-60 days')); 
$end_date_2 = $_GET['end_date_2'] ?? date('Y-m-d', strtotime('-31 days'));

$selected_kpis = $_GET['kpis'] ?? ['TotalNetSales', 'ATV']; 
$store_filter = $_GET['store_id'] ?? 'all'; 
$f_stocktypes = $_GET['stocktypes'] ?? []; 
$f_categories = $_GET['categories'] ?? []; 
$f_basemetals = $_GET['basemetals'] ?? []; 

// --- DATA FETCHING ---
$all_stores = [];
$all_stocktypes = [];
$all_categories = [];
$all_basemetals = []; 
$error_message = '';

// Helper function to get distinct values for filters
function get_distinct_values($conn, $column) {
    $options = [];
    // Using simple query for speed and including blanks/nulls for proper filter rendering
    // FIX: Using COALESCE for sorting to handle NULL/Blanks consistently at the top
    $sql = "SELECT DISTINCT $column FROM sales_reports ORDER BY COALESCE($column, '') = '' DESC, $column ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            // Use the column name to fetch the value, defaulting to '' for nulls
            $options[] = $row[$column] ?? '';
        }
    }
    return array_unique($options); 
}

// Fetch stores for the filter dropdown
$all_stores = get_distinct_values($conn, 'StoreCode');
$all_stocktypes = get_distinct_values($conn, 'Stocktype');
$all_categories = get_distinct_values($conn, 'ProductCategory');
$all_basemetals = get_distinct_values($conn, 'BaseMetal'); 

// --- Build Filter SQL ---
/**
 * @param string $field The DB column name.
 * @param array $values The array of selected values from the form.
 * @param int $total_options The total count of available options for this filter.
 * @param array $where_conditions The array of WHERE clauses.
 * @param array $params The array of parameters for bind_param.
 * @param string $types The string of types for bind_param.
 */
function build_in_clause($field, $values, $total_options, &$where_conditions, &$params, &$types) {
    
    // Check if the filter array is effectively 'all' (selected values cover all options, including null/blank)
    // To properly determine if "all" is selected, we need to compare selected options against ALL options.
    // However, given the nature of the form (not having an 'all' option), if $values is empty, nothing is selected.
    // If $values is not empty, we apply the filter.
    
    if (!empty($values)) {
        
        $include_null_or_blank = in_array('', $values);
        // Filter out the blank/null indicator to only bind non-empty strings
        $filtered_values = array_filter($values, fn($v) => $v !== ''); 
        
        $placeholders = [];
        $conditions_parts = [];
        
        if (!empty($filtered_values)) {
            $placeholders = implode(',', array_fill(0, count($filtered_values), '?'));
            $conditions_parts[] = "$field IN ($placeholders)";
            foreach ($filtered_values as $val) {
                $params[] = $val;
                $types .= "s";
            }
        }

        if ($include_null_or_blank) {
            $conditions_parts[] = "($field IS NULL OR $field = '')";
        }
        
        if (!empty($conditions_parts)) {
            $where_conditions[] = "(" . implode(' OR ', $conditions_parts) . ")";
        }
    }
}

// --- KPI Comparison Setup ---
$kpi_options = [
    'TotalNetSales' => ['label' => 'Net Sales', 'format' => 'currency_lakhs'],
    'TotalNetUnits' => ['label' => 'Total Units', 'format' => 'number'],
    'TotalTransactions' => ['label' => 'Transactions', 'format' => 'number'],
    'ATV' => ['label' => 'ATV', 'format' => 'currency'],
    'ASP' => ['label' => 'ASP', 'format' => 'currency'],
    'UPT' => ['label' => 'UPT', 'format' => 'number_decimal'],
];

// --- Build Filter SQL ---
$total_stocktypes = count($all_stocktypes);
$total_categories = count($all_categories);
$total_basemetals = count($all_basemetals);

$params = [$start_date_1, $end_date_1, $start_date_2, $end_date_2]; // Dates first
$types = "ssss"; // 4 strings for the dates

$where_conditions = [];
$where_conditions[] = "(TransactionDate BETWEEN ? AND ? OR TransactionDate BETWEEN ? AND ?)";
$where_conditions[] = "EntryType = 'FF'";

// Add main store filter
if($store_filter !== 'all'){
    $where_conditions[] = "StoreCode = ? ";
    $params[] = $store_filter;
    $types .= "s";
}

// --- APPLY FILTER LOGIC ---
// Pass the count of *all* distinct options (including the blank one) to build_in_clause
build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions, $params, $types);
build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions, $params, $types);
build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions, $params, $types);

$where_clause = " WHERE " . implode(' AND ', $where_conditions);

// --- 1. COMPARISON QUERY (Conditional Aggregation) ---
$comparison_data = [];

$sql_comparison = "SELECT
    StoreCode,
    
    -- PERIOD 1 CALCULATIONS
    SUM(CASE WHEN TransactionDate BETWEEN ? AND ? THEN NetSales ELSE 0 END) AS P1_NetSales,
    COUNT(CASE WHEN TransactionDate BETWEEN ? AND ? THEN 1 ELSE NULL END) AS P1_TotalNetUnits,
    COUNT(DISTINCT CASE WHEN TransactionDate BETWEEN ? AND ? THEN TransactionNo ELSE NULL END) AS P1_TotalTransactions,
    
    -- PERIOD 2 CALCULATIONS
    SUM(CASE WHEN TransactionDate BETWEEN ? AND ? THEN NetSales ELSE 0 END) AS P2_NetSales,
    COUNT(CASE WHEN TransactionDate BETWEEN ? AND ? THEN 1 ELSE NULL END) AS P2_TotalNetUnits,
    COUNT(DISTINCT CASE WHEN TransactionDate BETWEEN ? AND ? THEN TransactionNo ELSE NULL END) AS P2_TotalTransactions

FROM sales_reports
$where_clause
AND StoreCode IS NOT NULL AND StoreCode != ''
GROUP BY StoreCode
ORDER BY P1_NetSales DESC";

// --- Adjust parameters for the query ---
$sql_date_params = [
    $start_date_1, $end_date_1, // P1 NetSales
    $start_date_1, $end_date_1, // P1 Units
    $start_date_1, $end_date_1, // P1 Transactions
    $start_date_2, $end_date_2, // P2 NetSales
    $start_date_2, $end_date_2, // P2 Units
    $start_date_2, $end_date_2, // P2 Transactions
];

// The parameters for the initial WHERE clause follow the date ranges
$final_params = array_merge($sql_date_params, $params); 
$final_types = str_repeat('s', count($sql_date_params)) . $types;


if($stmt_comparison = $conn->prepare($sql_comparison)){
    // FIX: Using the splice operator for variadic function calls is the safest way
    $stmt_comparison->bind_param($final_types, ...$final_params);
    if($stmt_comparison->execute()){
        $result_comparison = $stmt_comparison->get_result();
        while($row = $result_comparison->fetch_assoc()){ 
            
            // Initialize raw values safely (uses NetSales from query, NOT TotalNetSales)
            $row['P1_NetSales'] = $row['P1_NetSales'] ?? 0;
            $row['P1_TotalNetUnits'] = $row['P1_TotalNetUnits'] ?? 0;
            $row['P1_TotalTransactions'] = $row['P1_TotalTransactions'] ?? 0;
            
            $row['P2_NetSales'] = $row['P2_NetSales'] ?? 0;
            $row['P2_TotalNetUnits'] = $row['P2_TotalNetUnits'] ?? 0;
            $row['P2_TotalTransactions'] = $row['P2_TotalTransactions'] ?? 0;
            
            // Calculate derived KPIs safely
            // Map the internal KPI name (TotalNetSales) to the DB column alias (NetSales)
            $row['P1_TotalNetSales'] = $row['P1_NetSales'];
            $row['P2_TotalNetSales'] = $row['P2_NetSales'];
            
            $row['P1_ATV'] = ($row['P1_TotalTransactions'] > 0) ? ($row['P1_NetSales'] / $row['P1_TotalTransactions']) : 0;
            $row['P1_ASP'] = ($row['P1_TotalNetUnits'] > 0) ? ($row['P1_NetSales'] / $row['P1_TotalNetUnits']) : 0;
            $row['P1_UPT'] = ($row['P1_TotalTransactions'] > 0) ? ($row['P1_TotalNetUnits'] / $row['P1_TotalTransactions']) : 0;

            $row['P2_ATV'] = ($row['P2_TotalTransactions'] > 0) ? ($row['P2_NetSales'] / $row['P2_TotalTransactions']) : 0;
            $row['P2_ASP'] = ($row['P2_TotalNetUnits'] > 0) ? ($row['P2_NetSales'] / $row['P2_TotalNetUnits']) : 0;
            $row['P2_UPT'] = ($row['P2_TotalTransactions'] > 0) ? ($row['P2_TotalNetUnits'] / $row['P2_TotalTransactions']) : 0;

            $comparison_data[] = $row; 
        }
    } else { $error_message .= "Comparison Report Error: ". $stmt_comparison->error . "<br>"; }
    $stmt_comparison->close();
} else { $error_message .= "DB Prepare Error (Comparison): ". $conn->error . "<br>"; }


// --- Helper functions for formatting ---
function format_kpi_value($kpi_key, $value) {
    global $kpi_options;
    // Safely retrieve format, default to number_decimal if key not in array (shouldn't happen)
    $format = $kpi_options[$kpi_key]['format'] ?? 'number_decimal';

    switch ($format) {
        case 'currency_lakhs':
            return formatInLakhs($value) . ' L';
        case 'currency':
            return number_format($value, 0);
        case 'number':
            return number_format($value, 0);
        case 'number_decimal':
            return number_format($value, 2);
        default:
            return number_format($value, 2);
    }
}
function calculate_variance($p1_val, $p2_val) {
    if ($p1_val == 0 && $p2_val == 0) return ['value' => '0%', 'class' => 'text-muted'];
    if ($p1_val == 0) return ['value' => '∞', 'class' => 'text-success'];
    
    $variance = (($p2_val - $p1_val) / $p1_val) * 100;
    $class = ($variance >= 0) ? 'text-success' : 'text-danger';
    $sign = ($variance > 0) ? '+' : '';

    return ['value' => $sign . number_format($variance, 1) . '%', 'class' => $class];
}


// --- HTML/PHP VIEW ---
?>

<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<style>
    /* New Styles for a Clean, Modern Look */
    .dashboard-container {
        background-color: #f8f9fa;
    }
    .kpi-checkbox-list, .filter-checkbox-list {
        max-height: 150px;
        overflow-y: auto;
        border: 1px solid #ced4da;
        padding: 10px;
        border-radius: 0.25rem;
        background-color: #fff;
    }
    .kpi-checkbox-list label, .filter-checkbox-list label {
        font-size: 0.9rem;
    }
    .comparison-table th, .comparison-table td {
        vertical-align: middle;
        text-align: center;
        padding: 8px 5px;
        font-size: 0.9rem;
    }
    .comparison-table thead th {
        border-bottom: 2px solid #343a40 !important;
    }
    .comparison-table th[rowspan="2"] {
        text-align: left;
        background-color: #e9ecef;
    }
    .text-primary, .text-success {
        font-weight: 600;
    }
    .text-success {
        color: #198754 !important;
    }
    .text-danger {
        color: #dc3545 !important;
    }
    .bg-dark-header {
        background-color: #343a40 !important;
    }
</style>


<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>KPI Performance Comparison</h2>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

<div class="card mb-4 no-print shadow-sm">
    <div class="card-body">
        <form action="" method="get" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="store_id" class="form-label">Store / Cost Center</label>
                <select id="store_id" name="store_id" class="form-select">
                    <option value="all">All Stores (Overall)</option>
                    <?php foreach ($all_stores as $store): ?>
                        <option value="<?php echo $store; ?>" <?php if($store_filter == $store) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($store); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-4">
                <label for="start_date_1" class="form-label text-primary fw-bold">Period 1: Start Date</label>
                <input type="date" id="start_date_1" name="start_date_1" class="form-control" value="<?php echo htmlspecialchars($start_date_1); ?>">
            </div>
            <div class="col-md-2">
                <label for="end_date_1" class="form-label text-primary fw-bold">End Date</label>
                <input type="date" id="end_date_1" name="end_date_1" class="form-control" value="<?php echo htmlspecialchars($end_date_1); ?>">
            </div>

            <div class="col-md-4">
                <label for="start_date_2" class="form-label text-success fw-bold">Period 2: Start Date</label>
                <input type="date" id="start_date_2" name="start_date_2" class="form-control" value="<?php echo htmlspecialchars($start_date_2); ?>">
            </div>
            <div class="col-md-2">
                <label for="end_date_2" class="form-label text-success fw-bold">End Date</label>
                <input type="date" id="end_date_2" name="end_date_2" class="form-control" value="<?php echo htmlspecialchars($end_date_2); ?>">
            </div>

            <div class="col-md-3">
                <label class="form-label fw-bold">Select KPIs to Compare</label>
                <div class="kpi-checkbox-list">
                    <?php foreach ($kpi_options as $key => $kpi): ?>
                        <div class="form-check">
                            <input class="form-check-input kpi-check-item" type="checkbox" name="kpis[]"
                                        value="<?php echo htmlspecialchars($key); ?>" id="kpi_<?php echo $key; ?>"
                                        <?php echo in_array($key, $selected_kpis) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="kpi_<?php echo $key; ?>"><?php echo htmlspecialchars($kpi['label']); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-3">
                <label class="form-label">Product Category</label>
                <div class="filter-checkbox-list">
                    <?php foreach ($all_categories as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input category-check-item" type="checkbox" name="categories[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="cat_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $f_categories) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="cat_<?php echo md5(htmlspecialchars($opt)); ?>">
                                <?php echo htmlspecialchars($opt === '' ? 'Blank/N.A.' : $opt); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Stock Type</label>
                <div class="filter-checkbox-list">
                    <?php foreach ($all_stocktypes as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input stocktype-check-item" type="checkbox" name="stocktypes[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="st_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $f_stocktypes) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="st_<?php echo md5(htmlspecialchars($opt)); ?>">
                                <?php echo htmlspecialchars($opt === '' ? 'Blank/N.A.' : $opt); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Base Metal</label>
                <div class="filter-checkbox-list">
                    <?php foreach ($all_basemetals as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input basemetal-check-item" type="checkbox" name="basemetals[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="bm_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $f_basemetals) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bm_<?php echo md5(htmlspecialchars($opt)); ?>">
                                <?php echo htmlspecialchars($opt === '' ? 'Blank/N.A.' : $opt); ?>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-12">
                <button type="submit" class="btn btn-primary w-100 mt-2">Run Comparison</button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4 dashboard-container">
    <div class="card-header bg-dark-header text-white">
        <h5 class="mb-0">Store-wise KPI Comparison</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table comparison-table table-sm table-hover">
                <thead class="table-light sticky-top">
                    <tr>
                        <th rowspan="2">Store Code</th>
                        <?php foreach ($selected_kpis as $kpi_key): ?>
                            <th colspan="3" class="text-center text-uppercase fw-bold"><?php echo htmlspecialchars($kpi_options[$kpi_key]['label']); ?></th>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <?php foreach ($selected_kpis as $kpi_key): ?>
                            <th class="text-primary">P1</th>
                            <th class="text-success">P2</th>
                            <th class="bg-light">Var.</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($comparison_data)): ?>
                        <tr><td colspan="<?php echo (count($selected_kpis) * 3) + 1; ?>" class="text-center">No data found for the selected dates and filters.</td></tr>
                    <?php else: ?>
                        <?php foreach ($comparison_data as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['StoreCode']); ?></td>
                                <?php foreach ($selected_kpis as $kpi_key): 
                                    // The data preparation phase now correctly maps P1_TotalNetSales, P1_ATV, P1_UPT etc.
                                    $p1_key = 'P1_' . $kpi_key;
                                    $p2_key = 'P2_' . $kpi_key;

                                    // Access the values using the full KPI key name created in the data fetching loop
                                    $p1_val = $row[$p1_key] ?? 0; 
                                    $p2_val = $row[$p2_key] ?? 0; 
                                    
                                    $variance = calculate_variance($p1_val, $p2_val);
                                ?>
                                    <td class="text-primary"><?php echo format_kpi_value($kpi_key, $p1_val); ?></td>
                                    <td class="text-success"><?php echo format_kpi_value($kpi_key, $p2_val); ?></td>
                                    <td class="fw-bold <?php echo $variance['class']; ?> bg-light"><?php echo $variance['value']; ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <p class="mt-3 small text-muted">Period 1: <?php echo htmlspecialchars($start_date_1); ?> to <?php echo htmlspecialchars($end_date_1); ?></p>
        <p class="small text-muted">Period 2: <?php echo htmlspecialchars($start_date_2); ?> to <?php echo htmlspecialchars($end_date_2); ?></p>
    </div>
</div>

<?php 
// Ensure Lucide icons are created on page load
echo "<script>document.addEventListener('DOMContentLoaded', () => { if (typeof lucide !== 'undefined') lucide.createIcons(); });</script>";
require_once '../includes/footer.php'; 
?>