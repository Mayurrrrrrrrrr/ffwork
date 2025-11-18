<?php
// admin/sales_financial_report.php

// --- DEBUGGING: Force PHP to show errors. ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- END DEBUGGING ---

require_once '../includes/header.php'; // Use '../' to go up one directory

// --- SECURITY CHECK: Only Admins can access this ---
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "portal_home.php");
    exit;
}

// --- Helper function to format in Lakhs ---
function formatInLakhs($number) {
    if (!is_numeric($number) || $number == 0) {
        return '0.00';
    }
    $lakhs = $number / 100000;
    return number_format($lakhs, 2);
}

// --- Initialize Arrays ---
$filter_options = [
    'stores' => [], 'categories' => [], 'stocktypes' => [], 'basemetals' => [] // Added basemetals
];
$report_results_monthly = []; // For by-store report
$report_results_daytype = [];
$report_results_weekly = [];
$report_results_monthly_agg = []; // For total report
$error_message = '';

// --- Populate Filter Dropdowns ---
function get_distinct_values($conn, $column) {
    $options = [];
    $sql = "SELECT DISTINCT $column FROM sales_reports WHERE $column IS NOT NULL AND $column != '' ORDER BY $column";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $options[] = $row[$column];
        }
    }
    return $options;
}
$filter_options['stores'] = get_distinct_values($conn, 'StoreCode');
$filter_options['categories'] = get_distinct_values($conn, 'ProductCategory');
$filter_options['stocktypes'] = get_distinct_values($conn, 'Stocktype');
$filter_options['basemetals'] = get_distinct_values($conn, 'BaseMetal'); // Added BaseMetal fetch

// --- Set Filter Defaults ---
$current_month = (int)date('m');
$current_year = (int)date('Y');
$fy_start_year = $current_month >= 4 ? $current_year : $current_year - 1;
$default_start_date = $fy_start_year . '-04-01';

$start_date = $_GET['start_date'] ?? $default_start_date;
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$f_stores = $_GET['stores'] ?? [];
$f_categories = $_GET['categories'] ?? [];
$f_stocktypes = $_GET['stocktypes'] ?? [];
$f_basemetals = $_GET['basemetals'] ?? []; // Added BaseMetal filter variable

// --- DYNAMIC SQL BUILDER (if form is submitted) ---
if (isset($_GET['generate_report'])) {
    
    // --- ***** REVISED: Helper function to build "IN (...)" clauses safely ***** ---
    /**
     * @param string $field The DB column name.
     * @param array $values The array of selected values from the form.
     * @param int $total_options The total count of available options for this filter.
     * @param array $where_conditions The array of WHERE clauses.
     * @param array $params The array of parameters for bind_param.
     * @param string $types The string of types for bind_param.
     */
    function build_in_clause($field, $values, $total_options, &$where_conditions, &$params, &$types) {
        // This function will NOT add a filter if:
        // 1. The $values array is empty (user selected nothing)
        // 2. The user selected ALL available options (count($values) == $total_options)
        // This ensures that "Select All" truly means "no filter" and includes NULLs.
        if (!empty($values) && count($values) < $total_options) {
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where_conditions[] = "$field IN ($placeholders)";
            foreach ($values as $val) {
                $params[] = $val;
                $types .= "s";
            }
        }
    }

    // --- Get total counts for each filter type ---
    $total_stores = count($filter_options['stores']);
    $total_categories = count($filter_options['categories']);
    $total_stocktypes = count($filter_options['stocktypes']);
    $total_basemetals = count($filter_options['basemetals']);


    // --- Base Filters (Current Year) ---
    $where_conditions_cy = []; 
    $where_conditions_cy[] = "TransactionDate BETWEEN ? AND ?";
    $where_conditions_cy[] = "EntryType = 'FF'";
    $params_cy = [$start_date, $end_date];
    $types_cy = "ss";

    // --- ***** REVISED: Call build_in_clause with total counts ***** ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $where_conditions_cy, $params_cy, $types_cy);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions_cy, $params_cy, $types_cy);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions_cy, $params_cy, $types_cy);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions_cy, $params_cy, $types_cy);
    
    $where_clause_cy = " WHERE " . implode(' AND ', $where_conditions_cy);
    
    // --- Base Filters (Last Year) ---
    $start_date_ly = date('Y-m-d', strtotime('-1 year', strtotime($start_date)));
    $end_date_ly = date('Y-m-d', strtotime('-1 year', strtotime($end_date)));
    
    $where_conditions_ly = []; 
    $where_conditions_ly[] = "TransactionDate BETWEEN ? AND ?";
    $where_conditions_ly[] = "EntryType = 'FF'";
    $params_ly = [$start_date_ly, $end_date_ly];
    $types_ly = "ss";
    
    // --- ***** REVISED: Call build_in_clause with total counts ***** ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $where_conditions_ly, $params_ly, $types_ly);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions_ly, $params_ly, $types_ly);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions_ly, $params_ly, $types_ly);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions_ly, $params_ly, $types_ly);

    $where_clause_ly = " WHERE " . implode(' AND ', $where_conditions_ly);

    // --- 1. Report 1: Monthwise LY vs. TY (BY STORE) ---
    $cy_data = [];
    $ly_data = [];

    // Get Current Year Data (By Store)
    $sql_cy = "SELECT StoreCode, MONTH(TransactionDate) as month_num, SUM(NetSales) as net_sales, COUNT(*) as net_units
               FROM sales_reports
               $where_clause_cy
               GROUP BY StoreCode, MONTH(TransactionDate)";
    if($stmt_cy = $conn->prepare($sql_cy)){
        $stmt_cy->bind_param($types_cy, ...$params_cy);
        if($stmt_cy->execute()){
            $result_cy = $stmt_cy->get_result();
            while($row = $result_cy->fetch_assoc()){ $cy_data[$row['StoreCode']][$row['month_num']] = $row; }
        } else { $error_message .= "CY Report Error: ". $stmt_cy->error . "<br>"; }
        $stmt_cy->close();
    } else { $error_message .= "DB Prepare Error (CY): ". $conn->error . "<br>"; }

    // Get Last Year Data (By Store)
    $sql_ly = "SELECT StoreCode, MONTH(TransactionDate) as month_num, SUM(NetSales) as net_sales, COUNT(*) as net_units
               FROM sales_reports
               $where_clause_ly
               GROUP BY StoreCode, MONTH(TransactionDate)";
    if($stmt_ly = $conn->prepare($sql_ly)){
        $stmt_ly->bind_param($types_ly, ...$params_ly);
        if($stmt_ly->execute()){
            $result_ly = $stmt_ly->get_result();
            while($row = $result_ly->fetch_assoc()){ $ly_data[$row['StoreCode']][$row['month_num']] = $row; }
        } else { $error_message .= "LY Report Error: ". $stmt_ly->error . "<br>"; }
        $stmt_ly->close();
    } else { $error_message .= "DB Prepare Error (LY): ". $conn->error . "<br>"; }

    // Combine data for the (By Store) table
    $report_results_monthly = [];
    $all_stores_in_report = array_unique(array_merge(array_keys($cy_data), array_keys($ly_data)));
    sort($all_stores_in_report);

    foreach ($all_stores_in_report as $store) {
        if(empty($store)) continue; 
        for ($m = 1; $m <= 12; $m++) {
            $month_name = date('F', mktime(0, 0, 0, $m, 10));
            $report_results_monthly[$store][$month_name] = [
                'CY_Sales' => $cy_data[$store][$m]['net_sales'] ?? 0,
                'CY_Units' => $cy_data[$store][$m]['net_units'] ?? 0,
                'LY_Sales' => $ly_data[$store][$m]['net_sales'] ?? 0,
                'LY_Units' => $ly_data[$store][$m]['net_units'] ?? 0,
            ];
        }
    }
    
    // --- 2. Report 2: Weekday vs. Weekend ---
    $sql_daytype = "SELECT 
                        CASE 
                            WHEN DAYOFWEEK(TransactionDate) IN (1, 7) THEN 'Weekend (Sat, Sun)' 
                            ELSE 'Weekday (Mon-Fri)' 
                        END as day_type, 
                        SUM(NetSales) as net_sales, 
                        COUNT(*) as net_units, 
                        COUNT(DISTINCT TransactionNo) as transactions
                    FROM sales_reports
                    $where_clause_cy
                    GROUP BY day_type
                    ORDER BY net_sales DESC";
                    
    if($stmt_daytype = $conn->prepare($sql_daytype)){
        $stmt_daytype->bind_param($types_cy, ...$params_cy);
        if($stmt_daytype->execute()){
            $result_daytype = $stmt_daytype->get_result();
            while($row = $result_daytype->fetch_assoc()){ $report_results_daytype[] = $row; }
        } else { $error_message .= "Day Type Report Error: ". $stmt_daytype->error . "<br>"; }
        $stmt_daytype->close();
    } else { $error_message .= "DB Prepare Error (Day Type): ". $conn->error . "<br>"; }

    // --- 3. Report 3: Weekly Sales Report ---
    $sql_weekly = "SELECT 
                       YEAR(TransactionDate) as year, 
                       WEEK(TransactionDate, 3) as week_num, 
                       MIN(TransactionDate) as week_start, 
                       MAX(TransactionDate) as week_end, 
                       SUM(NetSales) as net_sales, 
                       COUNT(*) as net_units
                   FROM sales_reports
                   $where_clause_cy
                   GROUP BY year, week_num
                   ORDER BY year ASC, week_num ASC"; // Sorted ASCENDING

    if($stmt_weekly = $conn->prepare($sql_weekly)){
        $stmt_weekly->bind_param($types_cy, ...$params_cy);
        if($stmt_weekly->execute()){
            $result_weekly = $stmt_weekly->get_result();
            while($row = $result_weekly->fetch_assoc()){ $report_results_weekly[] = $row; }
        } else { $error_message .= "Weekly Report Error: ". $stmt_weekly->error . "<br>"; }
        $stmt_weekly->close();
    } else { $error_message .= "DB Prepare Error (Weekly): ". $conn->error . "<br>"; }

    // --- 4. Report 4: Aggregated Monthwise LY vs. TY (TOTAL) ---
    $cy_data_agg = [];
    $ly_data_agg = [];

    // Get Current Year Data (Aggregated)
    $sql_cy_agg = "SELECT MONTH(TransactionDate) as month_num, SUM(NetSales) as net_sales, COUNT(*) as net_units
                   FROM sales_reports
                   $where_clause_cy
                   GROUP BY MONTH(TransactionDate)";
    if($stmt_cy_agg = $conn->prepare($sql_cy_agg)){
        $stmt_cy_agg->bind_param($types_cy, ...$params_cy);
        if($stmt_cy_agg->execute()){
            $result_cy_agg = $stmt_cy_agg->get_result();
            while($row = $result_cy_agg->fetch_assoc()){ $cy_data_agg[$row['month_num']] = $row; }
        } else { $error_message .= "CY Agg Report Error: ". $stmt_cy_agg->error . "<br>"; }
        $stmt_cy_agg->close();
    } else { $error_message .= "DB Prepare Error (CY Agg): ". $conn->error . "<br>"; }

    // Get Last Year Data (Aggregated)
    $sql_ly_agg = "SELECT MONTH(TransactionDate) as month_num, SUM(NetSales) as net_sales, COUNT(*) as net_units
                   FROM sales_reports
                   $where_clause_ly
                   GROUP BY MONTH(TransactionDate)";
    if($stmt_ly_agg = $conn->prepare($sql_ly_agg)){
        $stmt_ly_agg->bind_param($types_ly, ...$params_ly);
        if($stmt_ly_agg->execute()){
            $result_ly_agg = $stmt_ly_agg->get_result();
            while($row = $result_ly_agg->fetch_assoc()){ $ly_data_agg[$row['month_num']] = $row; }
        } else { $error_message .= "LY Agg Report Error: ". $stmt_ly_agg->error . "<br>"; }
        $stmt_ly_agg->close();
    } else { $error_message .= "DB Prepare Error (LY Agg): ". $conn->error . "<br>"; }

    // Combine data for the aggregated table
    for ($m = 1; $m <= 12; $m++) {
        $month_name = date('F', mktime(0, 0, 0, $m, 10));
        $report_results_monthly_agg[$month_name] = [
            'CY_Sales' => $cy_data_agg[$m]['net_sales'] ?? 0,
            'CY_Units' => $cy_data_agg[$m]['net_units'] ?? 0,
            'LY_Sales' => $ly_data_agg[$m]['net_sales'] ?? 0,
            'LY_Units' => $ly_data_agg[$m]['net_units'] ?? 0,
        ];
    }
}

?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .report-card-body {
        max-height: 400px; 
        overflow-y: auto;
    }
    .report-card-body .sticky-top {
        top: -1px; 
    }
    .filter-checkbox-list {
        max-height: 150px;
        overflow-y: auto;
        border: 1px solid #ced4da;
        padding: 10px;
        border-radius: 0.25rem;
        background-color: #fff;
    }
    .filter-checkbox-list .form-check {
        margin-bottom: 5px;
    }
    .table-v-align th, .table-v-align td {
        vertical-align: middle;
    }
    .table th { background-color: #f8f9fa; }
    .table td.text-end { text-align: right; }
    .table td.fw-bold { font-weight: bold; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Financial Sales Report</h2>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

<div class="card mb-4 no-print shadow-sm">
    <div class="card-body">
        <form action="admin/sales_financial_report.php" method="get" class="row g-3 align-items-end">
            <input type="hidden" name="generate_report" value="1">
            <div class="col-md-4">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="col-md-4">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="col-md-4">
                <label for="store_id" class="form-label">Store / Cost Center</label>
                <select id="stores" name="stores[]" class="form-select" multiple="multiple">
                    <?php foreach ($filter_options['stores'] as $opt): ?>
                        <option value="<?php echo $opt; ?>" <?php if(in_array($opt, $f_stores)) echo 'selected'; ?>><?php echo htmlspecialchars($opt); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="col-md-4">
                <label class="form-label">Product Category</label>
                <div class="filter-checkbox-list" id="category-filter-list">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="category-select-all">
                        <label class="form-check-label fw-bold" for="category-select-all">Select All / None</label>
                    </div>
                    <hr class="my-1">
                    <?php foreach ($filter_options['categories'] as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input category-check-item" type="checkbox" name="categories[]" 
                                   value="<?php echo htmlspecialchars($opt); ?>" id="cat_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                   <?php echo in_array($opt, $f_categories) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="cat_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Stock Type</label>
                <div class="filter-checkbox-list" id="stocktype-filter-list">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="stocktype-select-all">
                        <label class="form-check-label fw-bold" for="stocktype-select-all">Select All / None</label>
                    </div>
                    <hr class="my-1">
                    <?php foreach ($filter_options['stocktypes'] as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input stocktype-check-item" type="checkbox" name="stocktypes[]" 
                                   value="<?php echo htmlspecialchars($opt); ?>" id="st_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                   <?php echo in_array($opt, $f_stocktypes) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="st_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="col-md-3">
                <label class="form-label">Base Metal</label>
                <div class="filter-checkbox-list" id="basemetal-filter-list">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="basemetal-select-all">
                        <label class="form-check-label fw-bold" for="basemetal-select-all">Select All / None</label>
                    </div>
                    <hr class="my-1">
                    <?php foreach ($filter_options['basemetals'] as $opt): ?>
                        <div class="form-check">
                            <input class="form-check-input basemetal-check-item" type="checkbox" name="basemetals[]" 
                                   value="<?php echo htmlspecialchars($opt); ?>" id="bm_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                   <?php echo in_array($opt, $f_basemetals) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="bm_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="col-md-2">
                <label class="form-label">&nbsp;</label>
                <button type="submit" class="btn btn-primary w-100">Generate</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['generate_report'])): ?>

<div class="card mb-4" id="report-monthly">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i data-lucide="calendar-days" class="me-2 text-muted"></i>Month-wise Sales (LY vs. TY) by Store</h5>
        <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-monthly', 'monthly_sales_by_store.csv')">
            <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm table-v-align" id="table-monthly">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="text-start">Store</th>
                        <th rowspan="2" class="text-start">Month</th>
                        <th colspan="2" class="text-center">Last Year (LY)</th>
                        <th colspan="2" class="text-center">Current Year (CY)</th>
                        <th colspan="2" class="text-center">Growth % (Sales)</th>
                    </tr>
                    <tr>
                        <th class="text-end">Net Sales (L)</th>
                        <th class="text-end">Net Units</th>
                        <th class="text-end">Net Sales (L)</th>
                        <th class="text-end">Net Units</th>
                        <th class="text-end">Sales %</th>
                        <th class="text-end">Units %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totals = ['LY_Sales' => 0, 'LY_Units' => 0, 'CY_Sales' => 0, 'CY_Units' => 0];
                    foreach ($report_results_monthly as $store => $months_data):
                        foreach ($months_data as $month => $data):
                            if($data['CY_Sales'] == 0 && $data['CY_Units'] == 0 && $data['LY_Sales'] == 0 && $data['LY_Units'] == 0) continue;
                        
                            $totals['LY_Sales'] += $data['LY_Sales'];
                            $totals['LY_Units'] += $data['LY_Units'];
                            $totals['CY_Sales'] += $data['CY_Sales'];
                            $totals['CY_Units'] += $data['CY_Units'];

                            $sales_growth = ($data['LY_Sales'] > 0) ? (($data['CY_Sales'] - $data['LY_Sales']) / $data['LY_Sales']) * 100 : ($data['CY_Sales'] > 0 ? 100.0 : 0.0);
                            $units_growth = ($data['LY_Units'] > 0) ? (($data['CY_Units'] - $data['LY_Units']) / $data['LY_Units']) * 100 : ($data['CY_Units'] > 0 ? 100.0 : 0.0);
                        ?>
                            <tr>
                                <td class="text-start"><strong><?php echo htmlspecialchars($store); ?></strong></td>
                                <td class="text-start"><strong><?php echo $month; ?></strong></td>
                                <td class="text-end"><?php echo formatInLakhs($data['LY_Sales']); ?></td>
                                <td class="text-end"><?php echo number_format($data['LY_Units']); ?></td>
                                <td class="text-end"><?php echo formatInLakhs($data['CY_Sales']); ?></td>
                                <td class="text-end"><?php echo number_format($data['CY_Units']); ?></td>
                                <td class="text-end fw-bold <?php echo $sales_growth > 0 ? 'text-success' : ($sales_growth < 0 ? 'text-danger' : ''); ?>">
                                    <?php echo number_format($sales_growth, 1); ?>%
                                </td>
                                <td class="text-end fw-bold <?php echo $units_growth > 0 ? 'text-success' : ($units_growth < 0 ? 'text-danger' : ''); ?>">
                                    <?php echo number_format($units_growth, 1); ?>%
                                </td>
                            </tr>
                        <?php 
                        endforeach; // end month
                    endforeach; // end store
                    ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <?php
                        $total_sales_growth = ($totals['LY_Sales'] > 0) ? (($totals['CY_Sales'] - $totals['LY_Sales']) / $totals['LY_Sales']) * 100 : ($totals['CY_Sales'] > 0 ? 100.0 : 0.0);
                        $total_units_growth = ($totals['LY_Units'] > 0) ? (($totals['CY_Units'] - $totals['LY_Units']) / $totals['LY_Units']) * 100 : ($totals['CY_Units'] > 0 ? 100.0 : 0.0);
                    ?>
                    <tr>
                        <td class="text-start" colspan="2">Grand Total</td>
                        <td class="text-end"><?php echo formatInLakhs($totals['LY_Sales']); ?></td>
                        <td class="text-end"><?php echo number_format($totals['LY_Units']); ?></td>
                        <td class="text-end"><?php echo formatInLakhs($totals['CY_Sales']); ?></td>
                        <td class="text-end"><?php echo number_format($totals['CY_Units']); ?></td>
                        <td class="text-end <?php echo $total_sales_growth > 0 ? 'text-success' : ($total_sales_growth < 0 ? 'text-danger' : ''); ?>">
                            <?php echo number_format($total_sales_growth, 1); ?>%
                        </td>
                        <td class="text-end <?php echo $total_units_growth > 0 ? 'text-success' : ($total_units_growth < 0 ? 'text-danger' : ''); ?>">
                            <?php echo number_format($total_units_growth, 1); ?>%
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card mb-4" id="report-daytype">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-lucide="sun" class="me-2 text-muted"></i>Weekday vs. Weekend Sales</h5>
                <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-daytype', 'daytype_sales_report.csv')">
                    <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
                </button>
            </div>
            <div class="card-body">
                <table class="table table-striped table-hover table-sm" id="table-daytype">
                    <thead class="table-light sticky-top">
                        <tr><th class="text-start">Day Type</th><th class="text-end">Net Sales (L)</th><th class="text-end">Net Units</th><th class="text-end">Transactions</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_results_daytype)): ?>
                            <tr><td colspan="4" class="text-center">No data found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($report_results_daytype as $row): ?>
                                <tr>
                                    <td class="text-start"><strong><?php echo htmlspecialchars($row['day_type']); ?></strong></td>
                                    <td class="text-end"><?php echo formatInLakhs($row['net_sales']); ?></td>
                                    <td class="text-end"><?php echo number_format($row['net_units']); ?></td>
                                    <td class="text-end"><?php echo number_format($row['transactions']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-4" id="report-weekly">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i data-lucide="calendar-check" class="me-2 text-muted"></i>Weekly Sales Report</h5>
                <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-weekly', 'weekly_sales_report.csv')">
                    <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
                </button>
            </div>
            <div class="card-body report-card-body">
                <table class="table table-striped table-hover table-sm" id="table-weekly">
                    <thead class="table-light sticky-top">
                        <tr><th>Year</th><th>Week #</th><th class="text-start">Week Start</th><th class="text-start">Week End</th><th class="text-end">Net Sales (L)</th><th class="text-end">Net Units</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($report_results_weekly)): ?>
                            <tr><td colspan="6" class="text-center">No data found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($report_results_weekly as $row): ?>
                                <tr>
                                    <td><?php echo $row['year']; ?></td>
                                    <td><?php echo $row['week_num']; ?></td>
                                    <td class="text-start"><?php echo date('d-M-Y', strtotime($row['week_start'])); ?></td>
                                    <td class="text-start"><?php echo date('d-M-Y', strtotime($row['week_end'])); ?></td>
                                    <td class="text-end"><?php echo formatInLakhs($row['net_sales']); ?></td>
                                    <td class="text-end"><?php echo number_format($row['net_units']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4" id="report-monthly-agg">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i data-lucide="calendar-days" class="me-2 text-muted"></i>Month-wise Sales (LY vs. TY) - Total</h5>
        <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-monthly-agg', 'monthly_sales_total.csv')">
            <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm table-v-align" id="table-monthly-agg">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="text-start">Month</th>
                        <th colspan="2" class="text-center">Last Year (LY)</th>
                        <th colspan="2" class="text-center">Current Year (CY)</th>
                        <th colspan="2" class="text-center">Growth % (Sales)</th>
                    </tr>
                    <tr>
                        <th class="text-end">Net Sales (L)</th>
                        <th class="text-end">Net Units</th>
                        <th class="text-end">Net Sales (L)</th>
                        <th class="text-end">Net Units</th>
                        <th class="text-end">Sales %</th>
                        <th class="text-end">Units %</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $totals_agg = ['LY_Sales' => 0, 'LY_Units' => 0, 'CY_Sales' => 0, 'CY_Units' => 0];
                    foreach ($report_results_monthly_agg as $month => $data): 
                        $totals_agg['LY_Sales'] += $data['LY_Sales'];
                        $totals_agg['LY_Units'] += $data['LY_Units'];
                        $totals_agg['CY_Sales'] += $data['CY_Sales'];
                        $totals_agg['CY_Units'] += $data['CY_Units'];

                        $sales_growth_agg = ($data['LY_Sales'] > 0) ? (($data['CY_Sales'] - $data['LY_Sales']) / $data['LY_Sales']) * 100 : ($data['CY_Sales'] > 0 ? 100.0 : 0.0);
                        $units_growth_agg = ($data['LY_Units'] > 0) ? (($data['CY_Units'] - $data['LY_Units']) / $data['LY_Units']) * 100 : ($data['CY_Units'] > 0 ? 100.0 : 0.0);
                    ?>
                        <tr>
                            <td class="text-start"><strong><?php echo $month; ?></strong></td>
                            <td class="text-end"><?php echo formatInLakhs($data['LY_Sales']); ?></td>
                            <td class="text-end"><?php echo number_format($data['LY_Units']); ?></td>
                            <td class="text-end"><?php echo formatInLakhs($data['CY_Sales']); ?></td>
                            <td class="text-end"><?php echo number_format($data['CY_Units']); ?></td>
                            <td class="text-end fw-bold <?php echo $sales_growth_agg > 0 ? 'text-success' : ($sales_growth_agg < 0 ? 'text-danger' : ''); ?>">
                                <?php echo number_format($sales_growth_agg, 1); ?>%
                            </td>
                            <td class="text-end fw-bold <?php echo $units_growth_agg > 0 ? 'text-success' : ($units_growth_agg < 0 ? 'text-danger' : ''); ?>">
                                <?php echo number_format($units_growth_agg, 1); ?>%
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot class="table-light fw-bold">
                    <?php
                        $total_sales_growth_agg = ($totals_agg['LY_Sales'] > 0) ? (($totals_agg['CY_Sales'] - $totals_agg['LY_Sales']) / $totals_agg['LY_Sales']) * 100 : ($totals_agg['CY_Sales'] > 0 ? 100.0 : 0.0);
                        $total_units_growth_agg = ($totals_agg['LY_Units'] > 0) ? (($totals_agg['CY_Units'] - $totals_agg['LY_Units']) / $totals_agg['LY_Units']) * 100 : ($totals_agg['CY_Units'] > 0 ? 100.0 : 0.0);
                    ?>
                    <tr>
                        <td class="text-start">Grand Total</td>
                        <td class="text-end"><?php echo formatInLakhs($totals_agg['LY_Sales']); ?></td>
                        <td class="text-end"><?php echo number_format($totals_agg['LY_Units']); ?></td>
                        <td class="text-end"><?php echo formatInLakhs($totals_agg['CY_Sales']); ?></td>
                        <td class="text-end"><?php echo number_format($totals_agg['CY_Units']); ?></td>
                        <td class="text-end <?php echo $total_sales_growth_agg > 0 ? 'text-success' : ($total_sales_growth_agg < 0 ? 'text-danger' : ''); ?>">
                            <?php echo number_format($total_sales_growth_agg, 1); ?>%
                        </td>
                        <td class="text-end <?php echo $total_units_growth_agg > 0 ? 'text-success' : ($total_units_growth_agg < 0 ? 'text-danger' : ''); ?>">
                            <?php echo number_format($total_units_growth_agg, 1); ?>%
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<?php endif; ?>


<script>
// --- Export to CSV Function ---
// This function exports the raw, unformatted numbers for better Excel analysis
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var rows = document.querySelectorAll("#" + tableId + " tr");
    
    // This variable will hold the raw, unformatted data for export
    var rawData = {
        'table-monthly': <?php echo json_encode($report_results_monthly); ?>,
        'table-daytype': <?php echo json_encode($report_results_daytype); ?>,
        'table-weekly': <?php echo json_encode($report_results_weekly); ?>,
        'table-monthly-agg': <?php echo json_encode($report_results_monthly_agg); ?>
    };

    // --- Special CSV generation for raw data ---
    
    // 1. Monthly (By Store)
    if (tableId === 'table-monthly') {
        csv.push('"Store","Month","LY Net Sales","LY Net Units","CY Net Sales","CY Net Units"');
        var totals = { LY_Sales: 0, LY_Units: 0, CY_Sales: 0, CY_Units: 0 };
        for (var store in rawData['table-monthly']) {
            for (var month in rawData['table-monthly'][store]) {
                var data = rawData['table-monthly'][store][month];
                if(data.CY_Sales == 0 && data.CY_Units == 0 && data.LY_Sales == 0 && data.LY_Units == 0) continue;
                csv.push([
                    '"' + store + '"', '"' + month + '"',
                    data.LY_Sales, data.LY_Units, data.CY_Sales, data.CY_Units
                ].join(','));
                totals.LY_Sales += data.LY_Sales;
                totals.LY_Units += data.LY_Units;
                totals.CY_Sales += data.CY_Sales;
                totals.CY_Units += data.CY_Units;
            }
        }
        csv.push(['"Grand Total"','""', totals.LY_Sales, totals.LY_Units, totals.CY_Sales, totals.CY_Units].join(','));
    }
    // 2. Day Type
    else if (tableId === 'table-daytype') {
        csv.push('"Day Type","Net Sales","Net Units","Transactions"');
        for (var i in rawData['table-daytype']) {
            var row = rawData['table-daytype'][i];
            csv.push([
                '"' + row.day_type + '"', row.net_sales, row.net_units, row.transactions
            ].join(','));
        }
    }
    // 3. Weekly
    else if (tableId === 'table-weekly') {
        csv.push('"Year","Week #","Week Start","Week End","Net Sales","Net Units"');
        for (var i in rawData['table-weekly']) {
            var row = rawData['table-weekly'][i];
            csv.push([
                row.year, row.week_num, '"' + row.week_start + '"', '"' + row.week_end + '"',
                row.net_sales, row.net_units
            ].join(','));
        }
    }
    // 4. Monthly (Aggregated)
    else if (tableId === 'table-monthly-agg') {
        csv.push('"Month","LY Net Sales","LY Net Units","CY Net Sales","CY Net Units"');
        var totals_agg = { LY_Sales: 0, LY_Units: 0, CY_Sales: 0, CY_Units: 0 };
        for (var month in rawData['table-monthly-agg']) {
            var data = rawData['table-monthly-agg'][month];
            csv.push([
                '"' + month + '"',
                data.LY_Sales, data.LY_Units, data.CY_Sales, data.CY_Units
            ].join(','));
            totals_agg.LY_Sales += data.LY_Sales;
            totals_agg.LY_Units += data.LY_Units;
            totals_agg.CY_Sales += data.CY_Sales;
            totals_agg.CY_Units += data.CY_Units;
        }
        csv.push(['"Grand Total"', totals_agg.LY_Sales, totals_agg.LY_Units, totals_agg.CY_Sales, totals_agg.CY_Units].join(','));
    }
    
    // --- Download Trigger ---
    var csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// --- Filter Logic ---
$(document).ready(function() {
    function initSelect2(selector) {
        $(selector).select2({
            theme: "bootstrap-5",
            placeholder: "All Stores (Default)",
            allowClear: true,
            closeOnSelect: false
        });
    }
    initSelect2('#stores');

    function setupSelectAll(selectAllId, itemClass) {
        const selectAll = document.getElementById(selectAllId);
        const items = document.querySelectorAll('.' + itemClass);
        if (!selectAll) return;
        
        function updateSelectAllState() {
            let allChecked = items.length > 0; // Assume true if there are items
            for (const item of items) {
                if (!item.checked) { 
                    allChecked = false; // If any item is unchecked, set to false
                    break; 
                }
            }
            selectAll.checked = allChecked;
        }

        selectAll.addEventListener('click', function() {
            for (const item of items) {
                item.checked = selectAll.checked;
            }
        });

        for (const item of items) {
            item.addEventListener('click', function() {
                updateSelectAllState();
            });
        }
        
        // This is the key logic that runs on page load.
        // It checks the current state of the boxes (set by PHP)
        // and updates the "Select All" toggle to match.
        updateSelectAllState();
    }

    setupSelectAll('category-select-all', 'category-check-item');
    setupSelectAll('stocktype-select-all', 'stocktype-check-item');
    setupSelectAll('basemetal-select-all', 'basemetal-check-item');
});
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>