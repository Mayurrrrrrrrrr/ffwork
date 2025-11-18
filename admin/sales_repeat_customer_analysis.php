<?php
// admin/sales_repeat_customer_analysis.php

// --- DEBUGGING: Force PHP to show errors. ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- END DEBUGGING ---

require_once '../includes/header.php'; // Use '../' to go up one directory

// --- SECURITY CHECK: Only Admins can access this ---
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: ". BASE_URL . "portal_home.php");
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
    'stores' => [], 'categories' => [], 'stocktypes' => [], 'basemetals' => []
];
$error_message = '';

// --- Chart Data ---
$trend_data = ['labels' => [], 'new_sales' => [], 'repeat_sales' => []];

// --- Report Data ---
$kpi_data = [
    'new_sales' => 0, 'new_units' => 0, 'new_txns' => 0,
    'repeat_sales' => 0, 'repeat_units' => 0, 'repeat_txns' => 0,
    'new_atv' => 0, 'new_asp' => 0, 'new_upt' => 0,
    'repeat_atv' => 0, 'repeat_asp' => 0, 'repeat_upt' => 0,
    'total_customers' => 0, 'repeat_customers' => 0, 'one_time_customers' => 0,
    'repeat_customer_rate' => 0, 'avg_sales_per_customer' => 0,
    'avg_days_to_repeat' => 0, 'purchase_frequency' => 0,
    'total_sales' => 0, 'total_txns' => 0,
    'repeat_revenue_pct' => 0, 'repeat_txn_pct' => 0
];
$store_wise_data = [];
$product_wise_data = [];


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
$filter_options['basemetals'] = get_distinct_values($conn, 'BaseMetal');

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
$f_basemetals = $_GET['basemetals'] ?? [];

// --- DYNAMIC SQL BUILDER (if form is submitted) ---
if (isset($_GET['generate_report'])) {
    
    // --- Helper function to build "IN (...)" clauses safely ---
    function build_in_clause($field, $values, $total_options, &$where_conditions, &$params, &$types, $alias = 's') {
        if (!empty($values) && count($values) < $total_options) {
            $placeholders = implode(',', array_fill(0, count($values), '?'));
            $where_conditions[] = "$alias.$field IN ($placeholders)";
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

    // --- Base Filters ---
    $base_where_conditions = []; 
    $base_where_conditions[] = "s.TransactionDate BETWEEN ? AND ?";
    $base_where_conditions[] = "s.EntryType = 'FF'";
    $base_where_conditions[] = "s.ClientMobile IS NOT NULL AND s.ClientMobile != ''";
    $base_params = [$start_date, $end_date];
    $base_types = "ss";

    // --- Call build_in_clause with total counts ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $base_where_conditions, $base_params, $base_types);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $base_where_conditions, $base_params, $base_types);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $base_where_conditions, $base_params, $base_types);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $base_where_conditions, $base_params, $base_types);
    
    $where_clause = " WHERE " . implode(' AND ', $base_where_conditions);
    
    // --- ***** SQL CTE (MAIN DEFINITION) ***** ---
    // This definition will be prepended to all our queries
    // THIS IS THE NEW LOGIC
    $sql_cte = "
        WITH customer_first_purchase AS (
            -- Get the first *ever* purchase date for all customers
            SELECT
                ClientMobile,
                MIN(TransactionDate) as first_ever_date,
                -- Get the second purchase date to calculate 'Days to Repeat'
                MIN(CASE WHEN TransactionDate > (SELECT MIN(TransactionDate) FROM sales_reports s2 WHERE s2.ClientMobile = s1.ClientMobile) THEN TransactionDate ELSE NULL END) as second_purchase_date
            FROM sales_reports s1
            WHERE ClientMobile IS NOT NULL AND ClientMobile != '' AND EntryType = 'FF'
            GROUP BY ClientMobile
        ),
        filtered_sales AS (
            -- Get all sales rows within the user's filtered date range and join to stores
            SELECT 
                s.*,
                st.gati_location_name
            FROM sales_reports s
            LEFT JOIN stores st ON s.StoreCode = st.store_code -- Join to get GATI name
            $where_clause -- This $where_clause is built by PHP and has all filters
        ),
        analysis_data AS (
            -- Join filtered sales with the first-purchase-date lookup
            SELECT
                fs.*,
                cfp.first_ever_date,
                cfp.second_purchase_date,
                -- Classify the purchase
                (fs.TransactionDate = cfp.first_ever_date) as is_new_purchase,
                (fs.TransactionDate > cfp.first_ever_date) as is_repeat_purchase,
                -- Calculate days to repeat if this is the second purchase
                CASE 
                    WHEN fs.TransactionDate = cfp.second_purchase_date THEN DATEDIFF(cfp.second_purchase_date, cfp.first_ever_date)
                    ELSE NULL
                END as days_to_repeat
            FROM filtered_sales fs
            JOIN customer_first_purchase cfp ON fs.ClientMobile = cfp.ClientMobile
        )
    ";
    
    // --- ***** QUERY 1: KPI DATA ***** ---
    $sql_kpi = $sql_cte . "
        SELECT
            -- Sales, Units, Txns (New vs Repeat)
            COALESCE(SUM(CASE WHEN is_new_purchase = 1 THEN NetSales ELSE 0 END), 0) as new_sales,
            COUNT(CASE WHEN is_new_purchase = 1 THEN 1 ELSE NULL END) as new_units,
            COUNT(DISTINCT CASE WHEN is_new_purchase = 1 THEN TransactionNo ELSE NULL END) as new_txns,
            
            COALESCE(SUM(CASE WHEN is_repeat_purchase = 1 THEN NetSales ELSE 0 END), 0) as repeat_sales,
            COUNT(CASE WHEN is_repeat_purchase = 1 THEN 1 ELSE NULL END) as repeat_units,
            COUNT(DISTINCT CASE WHEN is_repeat_purchase = 1 THEN TransactionNo ELSE NULL END) as repeat_txns,

            -- Customer Counts
            COUNT(DISTINCT ClientMobile) as total_customers,
            COUNT(DISTINCT CASE WHEN is_repeat_purchase = 1 THEN ClientMobile ELSE NULL END) as repeat_customers,
            
            -- New KPI: Avg Days to Repeat
            COALESCE(AVG(days_to_repeat), 0) as avg_days_to_repeat
            
        FROM analysis_data
    ";
    
    if($stmt_kpi = $conn->prepare($sql_kpi)){
        $stmt_kpi->bind_param($base_types, ...$base_params);
        if($stmt_kpi->execute()){
            $kpi_data = $stmt_kpi->get_result()->fetch_assoc();
            
            // Calculate derived KPIs
            $kpi_data['new_atv'] = ($kpi_data['new_txns'] > 0) ? ($kpi_data['new_sales'] / $kpi_data['new_txns']) : 0;
            $kpi_data['new_asp'] = ($kpi_data['new_units'] > 0) ? ($kpi_data['new_sales'] / $kpi_data['new_units']) : 0;
            $kpi_data['new_upt'] = ($kpi_data['new_txns'] > 0) ? ($kpi_data['new_units'] / $kpi_data['new_txns']) : 0;
            
            $kpi_data['repeat_atv'] = ($kpi_data['repeat_txns'] > 0) ? ($kpi_data['repeat_sales'] / $kpi_data['repeat_txns']) : 0;
            $kpi_data['repeat_asp'] = ($kpi_data['repeat_units'] > 0) ? ($kpi_data['repeat_sales'] / $kpi_data['repeat_units']) : 0;
            $kpi_data['repeat_upt'] = ($kpi_data['repeat_txns'] > 0) ? ($kpi_data['repeat_units'] / $kpi_data['repeat_txns']) : 0;
            
            // NEW KPIs
            $kpi_data['one_time_customers'] = $kpi_data['total_customers'] - $kpi_data['repeat_customers'];
            $kpi_data['repeat_customer_rate'] = ($kpi_data['total_customers'] > 0) ? ($kpi_data['repeat_customers'] / $kpi_data['total_customers']) * 100 : 0;
            $kpi_data['total_sales'] = $kpi_data['new_sales'] + $kpi_data['repeat_sales'];
            $kpi_data['total_txns'] = $kpi_data['new_txns'] + $kpi_data['repeat_txns'];
            $kpi_data['avg_sales_per_customer'] = ($kpi_data['total_customers'] > 0) ? ($kpi_data['total_sales'] / $kpi_data['total_customers']) : 0;
            $kpi_data['purchase_frequency'] = ($kpi_data['total_customers'] > 0) ? ($kpi_data['total_txns'] / $kpi_data['total_customers']) : 0;
            
            // NEW: Overall Repeat %
            $kpi_data['repeat_revenue_pct'] = ($kpi_data['total_sales'] > 0) ? ($kpi_data['repeat_sales'] / $kpi_data['total_sales']) * 100 : 0;
            $kpi_data['repeat_txn_pct'] = ($kpi_data['total_txns'] > 0) ? ($kpi_data['repeat_txns'] / $kpi_data['total_txns']) * 100 : 0;

            
        } else { $error_message .= "KPI Analysis Error: ". $stmt_kpi->error . "<br>"; }
        $stmt_kpi->close();
    } else { $error_message .= "DB Prepare Error (KPI Analysis): ". $conn->error . "<br>"; }


    // --- ***** QUERY 2: Store-wise Ratio (Using GATI Name) ***** ---
    $sql_store = $sql_cte . "
        SELECT
            -- Use GATI name, but fall back to StoreCode if GATI name is NULL or empty
            COALESCE(NULLIF(gati_location_name, ''), StoreCode) as location_name,
            COALESCE(SUM(NetSales), 0) as total_sales,
            COALESCE(SUM(CASE WHEN is_repeat_purchase = 1 THEN NetSales ELSE 0 END), 0) as repeat_sales,
            COUNT(DISTINCT TransactionNo) as total_txns,
            COUNT(DISTINCT CASE WHEN is_repeat_purchase = 1 THEN TransactionNo ELSE NULL END) as repeat_txns
        FROM analysis_data
        GROUP BY location_name
        ORDER BY repeat_sales DESC
    ";
    if($stmt_store = $conn->prepare($sql_store)){
        $stmt_store->bind_param($base_types, ...$base_params);
        if($stmt_store->execute()){
            $result_store = $stmt_store->get_result();
            while($row = $result_store->fetch_assoc()) { $store_wise_data[] = $row; }
        } else { $error_message .= "Store Analysis Error: ". $stmt_store->error . "<br>"; }
        $stmt_store->close();
    } else { $error_message .= "DB Prepare Error (Store Analysis): ". $conn->error . "<br>"; }
    

    // --- ***** QUERY 3: Product-wise (Repeat) ***** ---
    $sql_product = $sql_cte . "
        SELECT
            ProductCategory as group_key,
            COALESCE(SUM(NetSales), 0) as total_sales,
            COALESCE(SUM(CASE WHEN is_repeat_purchase = 1 THEN NetSales ELSE 0 END), 0) as repeat_sales,
            COUNT(DISTINCT TransactionNo) as total_txns,
            COUNT(DISTINCT CASE WHEN is_repeat_purchase = 1 THEN TransactionNo ELSE NULL END) as repeat_txns
        FROM analysis_data
        WHERE ProductCategory IS NOT NULL AND ProductCategory != ''
        GROUP BY ProductCategory
        ORDER BY repeat_sales DESC
    ";
    if($stmt_product = $conn->prepare($sql_product)){
        $stmt_product->bind_param($base_types, ...$base_params);
        if($stmt_product->execute()){
            $result_product = $stmt_product->get_result();
            while($row = $result_product->fetch_assoc()) { $product_wise_data[] = $row; }
        } else { $error_message .= "Product Analysis Error: ". $stmt_product->error . "<br>"; }
        $stmt_product->close();
    } else { $error_message .= "DB Prepare Error (Product Analysis): ". $conn->error . "<br>"; }


    // --- ***** QUERY 4: Dynamic Trend Chart ***** ---
    $date_diff = (strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24);
    $chart_group_by = '';
    $chart_select_label = '';
    $chart_date_format = '';

    if ($date_diff <= 92) { // ~3 months or less -> Daily
        $chart_group_by = "TransactionDate";
        $chart_select_label = "TransactionDate as period_label";
        $chart_date_format = 'd-M-Y';
    } elseif ($date_diff <= 730) { // Up to 2 years -> Weekly
        $chart_group_by = "YEAR(TransactionDate), WEEK(TransactionDate, 1)"; // Mode 1: Sunday start, 0-53
        $chart_select_label = "CONCAT(YEAR(TransactionDate), '-W', LPAD(WEEK(TransactionDate, 1), 2, '0')) as period_label";
        $chart_date_format = 'Y-W'; // Custom format
    } else { // Over 2 years -> Monthly
        $chart_group_by = "YEAR(TransactionDate), MONTH(TransactionDate)";
        $chart_select_label = "CONCAT(YEAR(TransactionDate), '-', LPAD(MONTH(TransactionDate), 2, '0')) as period_label";
        $chart_date_format = 'M Y';
    }
    
    $sql_chart = $sql_cte . "
        SELECT
            $chart_select_label,
            COALESCE(SUM(CASE WHEN is_new_purchase = 1 THEN NetSales ELSE 0 END), 0) as new_sales,
            COALESCE(SUM(CASE WHEN is_repeat_purchase = 1 THEN NetSales ELSE 0 END), 0) as repeat_sales
        FROM analysis_data
        GROUP BY $chart_group_by
        ORDER BY $chart_group_by ASC
    ";
    
    if($stmt_chart = $conn->prepare($sql_chart)){
        $stmt_chart->bind_param($base_types, ...$base_params);
        if($stmt_chart->execute()){
            $result_chart = $stmt_chart->get_result();
            while($row = $result_chart->fetch_assoc()) {
                // Format the label
                $label = $row['period_label'];
                if ($chart_date_format === 'd-M-Y') {
                    $label = date('d-M-Y', strtotime($row['period_label']));
                } elseif ($chart_date_format === 'M Y') {
                    $label = date('M Y', strtotime($row['period_label'] . '-01'));
                }
                // For 'Y-W', the label is already good (e.g., 2024-W35)
                
                $trend_data['labels'][] = $label;
                $trend_data['new_sales'][] = (float)$row['new_sales'];
                $trend_data['repeat_sales'][] = (float)$row['repeat_sales'];
            }
        } else { $error_message .= "Chart Analysis Error: ". $stmt_chart->error . "<br>"; }
        $stmt_chart->close();
    } else { $error_message .= "DB Prepare Error (Chart Analysis): ". $conn->error . "<br>"; }

}

?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .kpi-card {
        border: 1px solid #e0e0e0;
        border-radius: 0.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        background-color: #fff;
    }
    .kpi-card .card-header {
        background-color: #f8f9fa;
        font-weight: 600;
        border-bottom: 1px solid #e0e0e0;
    }
    .kpi-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
    }
    .kpi-value {
        font-size: 1.5rem;
        font-weight: 700;
        color: #212529;
    }
    .kpi-value-small {
        font-size: 1.1rem;
        font-weight: 600;
        color: #495057;
    }
    
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
    
    /* --- NEW: Print Styles --- */
    .print-only {
        display: none;
    }
    
    @media print {
        body {
            -webkit-print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        .no-print, .no-print * {
            display: none !important;
        }
        .card {
            border: 1px solid #ccc !important;
            box-shadow: none !important;
            page-break-inside: avoid;
        }
        .card-header {
            background-color: #f8f9fa !important;
        }
        .table, .table-sm {
            font-size: 10pt !important;
        }
        .report-card-body {
            max-height: none !important; 
            overflow-y: visible !important;
        }
        
        /* --- NEW: Fix for graph and add print filter summary --- */
        .print-only {
            display: block;
            margin-bottom: 20px;
            border: 1px solid #ccc;
            padding: 15px;
            border-radius: 5px;
        }
        .print-only h4 {
            font-size: 14pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .print-only ul {
            padding-left: 20px;
            font-size: 11pt;
            margin-bottom: 0;
            list-style-type: none;
        }
        .print-only li {
            margin-bottom: 5px;
        }
        
        .chart-container {
            width: 100% !important;
            max-width: 100% !important;
            height: 300px !important; /* Fixed height for print */
            page-break-inside: avoid;
        }
        #dynamicTrendChart {
            max-width: 100% !important;
            max-height: 300px !important;
        }
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Repeat Customer Analysis</h2>
    <div>
        <button class="btn btn-outline-secondary no-print" onclick="window.print();"><i data-lucide="printer" class="me-1" style="width:16px;"></i> Print Report</button>
        <a href="admin/sales_dashboard.php" class="btn btn-secondary no-print">Back to Main Dashboard</a>
    </div>
</div>

<?php if (isset($_GET['generate_report'])): ?>
<div class="print-only">
    <h4>Report Filters</h4>
    <ul>
        <li><strong>Date Range:</strong> <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></li>
        <li><strong>Stores:</strong> <?php echo empty($f_stores) ? 'All Stores' : htmlspecialchars(implode(', ', $f_stores)); ?></li>
        <li><strong>Categories:</strong> <?php echo empty($f_categories) ? 'All Categories' : htmlspecialchars(implode(', ', $f_categories)); ?></li>
        <li><strong>Stock Types:</strong> <?php echo empty($f_stocktypes) ? 'All Stock Types' : htmlspecialchars(implode(', ', $f_stocktypes)); ?></li>
        <li><strong>Base Metals:</strong> <?php echo empty($f_basemetals) ? 'All Base Metals' : htmlspecialchars(implode(', ', $f_basemetals)); ?></li>
    </ul>
</div>
<?php endif; ?>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

<div class="card mb-4 no-print shadow-sm">
    <div class="card-body">
        <form action="admin/sales_repeat_customer_analysis.php" method="get" class="row g-3 align-items-end">
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
            
            <div class="col-md-3">
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
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button type="submit" name="generate_report" class="btn btn-primary w-100">Generate</button>
            </div>
        </form>
    </div>
</div>

<?php if (isset($_GET['generate_report']) && empty($error_message) && !empty($kpi_data)): ?>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card h-100">
                <div class="card-header"><i data-lucide="goal" class="me-2 text-success"></i>Overall Repeat Contribution</div>
                <div class="card-body d-flex flex-column justify-content-center">
                     <div class="row text-center">
                        <div class="col-6">
                            <span class="kpi-title">% Repeat Revenue</span>
                            <h3 class="kpi-value text-success"><?php echo number_format($kpi_data['repeat_revenue_pct'], 1); ?>%</h3>
                            <small class="text-muted">(<?php echo formatInLakhs($kpi_data['repeat_sales']); ?> L of <?php echo formatInLakhs($kpi_data['total_sales']); ?> L)</small>
                        </div>
                        <div class="col-6">
                            <span class="kpi-title">% Repeat Transactions</span>
                            <h3 class="kpi-value text-success"><?php echo number_format($kpi_data['repeat_txn_pct'], 1); ?>%</h3>
                            <small class="text-muted">(<?php echo number_format($kpi_data['repeat_txns']); ?> of <?php echo number_format($kpi_data['total_txns']); ?>)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card h-100">
                <div class="card-header"><i data-lucide="pie-chart" class="me-2 text-primary"></i>Overall Customer Metrics (Period)</div>
                <div class="card-body">
                     <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">Total Customers</span>
                            <h3 class="kpi-value"><?php echo number_format($kpi_data['total_customers']); ?></h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat Customers</span>
                            <h3 class="kpi-value"><?php echo number_format($kpi_data['repeat_customers']); ?></h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat Cust. Rate</span>
                            <h3 class="kpi-value text-primary"><?php echo number_format($kpi_data['repeat_customer_rate'], 1); ?>%</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-12">
            <div class="card kpi-card mb-4">
                <div class="card-header"><i data-lucide="trending-up" class="me-2 text-info"></i>Customer Journey Metrics</div>
                <div class="card-body">
                     <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">Avg. Sales / Customer</span>
                            <h3 class="kpi-value"><?php echo number_format($kpi_data['avg_sales_per_customer'], 0); ?></h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Purchase Frequency</span>
                            <h3 class="kpi-value"><?php echo number_format($kpi_data['purchase_frequency'], 2); ?></h3>
                            <small class="text-muted">Avg. txns per customer</small>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Avg. Days to Repeat</span>
                            <h3 class="kpi-value text-info"><?php echo number_format($kpi_data['avg_days_to_repeat'], 1); ?></h3>
                            <small class="text-muted">From 1st to 2nd purchase</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card h-100">
                <div class="card-header"><i data-lucide="user-check" class="me-2 text-success"></i>Repeat Purchases (in Period)</div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">Repeat Sales</span>
                            <h3 class="kpi-value text-success"><?php echo formatInLakhs($kpi_data['repeat_sales']); ?> L</h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat Units</span>
                            <h3 class="kpi-value text-success"><?php echo number_format($kpi_data['repeat_units']); ?></h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat Txns</span>
                            <h3 class="kpi-value text-success"><?php echo number_format($kpi_data['repeat_txns']); ?></h3>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">Repeat ATV</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['repeat_atv'], 0); ?></h4>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat ASP</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['repeat_asp'], 0); ?></h4>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">Repeat UPT</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['repeat_upt'], 2); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card h-100">
                <div class="card-header"><i data-lucide="user-plus" class="me-2 text-primary"></i>New Purchases (in Period)</div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">New Sales</span>
                            <h3 class="kpi-value text-primary"><?php echo formatInLakhs($kpi_data['new_sales']); ?> L</h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">New Units</span>
                            <h3 class="kpi-value text-primary"><?php echo number_format($kpi_data['new_units']); ?></h3>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">New Txns</span>
                            <h3 class="kpi-value text-primary"><?php echo number_format($kpi_data['new_txns']); ?></h3>
                        </div>
                    </div>
                    <hr>
                    <div class="row text-center">
                        <div class="col-4">
                            <span class="kpi-title">New ATV</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['new_atv'], 0); ?></h4>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">New ASP</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['new_asp'], 0); ?></h4>
                        </div>
                        <div class="col-4">
                            <span class="kpi-title">New UPT</span>
                            <h4 class="kpi-value-small"><?php echo number_format($kpi_data['new_upt'], 2); ?></h4>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12 mb-4">
            <div class="card kpi-card">
                <div class="card-header"><i data-lucide="line-chart" class="me-2 text-primary"></i>Sales Trend (New vs. Repeat)</div>
                <div class="card-body chart-container" style="height: 350px;">
                    <canvas id="dynamicTrendChart"></canvas>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card" id="report-store-wise-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i data-lucide="store" class="me-2 text-muted"></i>Store-wise Repeat Contribution</h5>
                    <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-store-wise', 'store_repeat_contribution.csv')">
                        <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
                    </button>
                </div>
                <div class="card-body report-card-body">
                    <table class="table table-striped table-hover table-sm" id="table-store-wise">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Location Name</th>
                                <th class="text-end">Repeat Rev %</th>
                                <th class="text-end">Repeat Txn %</th>
                                <th class="text-end">Repeat Sales (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($store_wise_data)): ?>
                                <tr><td colspan="4" class="text-center">No store data found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($store_wise_data as $row): ?>
                                    <?php 
                                    $total_sales = (float)($row['total_sales'] ?? 0);
                                    $repeat_sales = (float)($row['repeat_sales'] ?? 0);
                                    $total_txns = (int)($row['total_txns'] ?? 0);
                                    $repeat_txns = (int)($row['repeat_txns'] ?? 0);
                                    $repeat_rev_pct = ($total_sales > 0) ? ($repeat_sales / $total_sales) * 100 : 0; 
                                    $repeat_txn_pct = ($total_txns > 0) ? ($repeat_txns / $total_txns) * 100 : 0; 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['location_name']); ?></td>
                                        <td class="text-end fw-bold"><?php echo number_format($repeat_rev_pct, 1); ?>%</td>
                                        <td class="text-end fw-bold"><?php echo number_format($repeat_txn_pct, 1); ?>%</td>
                                        <td class="text-end"><?php echo formatInLakhs($repeat_sales); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="card kpi-card" id="report-product-wise-card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i data-lucide="gem" class="me-2 text-muted"></i>Product-wise Repeat Contribution</h5>
                    <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-product-wise', 'product_repeat_contribution.csv')">
                        <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
                    </button>
                </div>
                <div class="card-body report-card-body">
                    <table class="table table-striped table-hover table-sm" id="table-product-wise">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Product Category</th>
                                <th class="text-end">Repeat Rev %</th>
                                <th class="text-end">Repeat Txn %</th>
                                <th class="text-end">Repeat Sales (L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($product_wise_data)): ?>
                                <tr><td colspan="4" class="text-center">No product data found for repeat customers.</td></tr>
                            <?php else: ?>
                                <?php foreach ($product_wise_data as $row): ?>
                                    <?php
                                    $total_sales = (float)($row['total_sales'] ?? 0);
                                    $repeat_sales = (float)($row['repeat_sales'] ?? 0);
                                    $total_txns = (int)($row['total_txns'] ?? 0);
                                    $repeat_txns = (int)($row['repeat_txns'] ?? 0);
                                    $repeat_rev_pct = ($total_sales > 0) ? ($repeat_sales / $total_sales) * 100 : 0; 
                                    $repeat_txn_pct = ($total_txns > 0) ? ($repeat_txns / $total_txns) * 100 : 0; 
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['group_key']); ?></td>
                                        <td class="text-end fw-bold"><?php echo number_format($repeat_rev_pct, 1); ?>%</td>
                                        <td class="text-end fw-bold"><?php echo number_format($repeat_txn_pct, 1); ?>%</td>
                                        <td class="text-end"><?php echo formatInLakhs($repeat_sales); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

<?php endif; ?>

<script>
// --- NEW: Export to CSV Function ---
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var rows = document.querySelectorAll("#" + tableId + " tr");
    
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        
        for (var j = 0; j < cols.length; j++) {
            var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ")
                                        .replace(/\s\s+/g, ' ')
                                        .replace(/,/g, '') // Remove commas from numbers
                                        .replace(/ L/g, '') // Remove ' L'
                                        .replace(/%/g, '') // Remove '%'
                                        .trim();
            data = '"' + data.replace(/"/g, '""') + '"';
            row.push(data);
        }
        csv.push(row.join(","));
    }

    var csvFile = new Blob([csv.join("\n")], {type: "text/csv"});
    var downloadLink = document.createElement("a");
    downloadLink.download = filename;
    downloadLink.href = window.URL.createObjectURL(csvFile);
    downloadLink.style.display = "none";
    document.body.appendChild(downloadLink);
    downloadLink.click();
    document.body.removeChild(downloadLink);
}

// --- Chart.js ---
<?php if (isset($_GET['generate_report']) && empty($error_message) && !empty($trend_data['labels'])): ?>
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('dynamicTrendChart').getContext('2d');
    const chartData = <?php echo json_encode($trend_data); ?>;
    
    // Function to format to Lakhs for tooltips
    const toLakhs = (val) => (val / 100000).toFixed(2) + ' L';

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [
                {
                    label: 'New Sales (L)',
                    data: chartData.new_sales, // Pass raw numbers
                    borderColor: '#0d6efd',
                    backgroundColor: 'rgba(13, 110, 253, 0.1)',
                    fill: true,
                    tension: 0.1,
                    yAxisID: 'ySales'
                },
                {
                    label: 'Repeat Sales (L)',
                    data: chartData.repeat_sales, // Pass raw numbers
                    borderColor: '#198754',
                    backgroundColor: 'rgba(25, 135, 84, 0.1)',
                    fill: true,
                    tension: 0.1,
                    yAxisID: 'ySales'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                ySales: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Sales in Lakhs (L)'
                    },
                    // Format Y-axis labels to Lakhs
                    ticks: {
                        callback: function(value, index, ticks) {
                            return (value / 100000).toFixed(1);
                        }
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Period (<?php echo $chart_date_format == 'd-M-Y' ? 'Daily' : ($chart_date_format == 'Y-W' ? 'Weekly' : 'Monthly'); ?>)'
                    }
                }
            },
            plugins: {
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    // NEW: Add % to tooltip
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            if (context.parsed.y !== null) {
                                label += toLakhs(context.parsed.y);
                            }
                            return label;
                        },
                        footer: function(tooltipItems) {
                            let newSales = 0;
                            let repeatSales = 0;
                            
                            tooltipItems.forEach(function(tooltipItem) {
                                if (tooltipItem.dataset.label.includes('New Sales')) {
                                    newSales = tooltipItem.parsed.y;
                                } else if (tooltipItem.dataset.label.includes('Repeat Sales')) {
                                    repeatSales = tooltipItem.parsed.y;
                                }
                            });

                            const totalSales = newSales + repeatSales;
                            const repeatPercent = (totalSales > 0) ? (repeatSales / totalSales) * 100 : 0;
                            
                            return 'Repeat %: ' + repeatPercent.toFixed(1) + '%';
                        }
                    }
                }
            }
        }
    });
});
<?php endif; ?>


// --- Filter Checkbox & Select2 Logic (runs on every page load) ---
$(document).ready(function() {
    function initSelect2(selector) {
        $(selector).select2({
            theme: "bootstrap-5",
            placeholder: "All (Default)",
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
            let allChecked = items.length > 0;
            for (const item of items) {
                if (!item.checked) { 
                    allChecked = false;
                    break; 
                }
            }
            selectAll.checked = allChecked;
        }

        selectAll.addEventListener('click', function() {
            for (const item of items) { item.checked = selectAll.checked; }
        });

        for (const item of items) {
            item.addEventListener('click', function() {
                updateSelectAllState();
            });
        }
        
        updateSelectAllState(); // Run on page load
    }

    setupSelectAll('category-select-all', 'category-check-item');
    setupSelectAll('stocktype-select-all', 'stocktype-check-item');
    setupSelectAll('basemetal-select-all', 'basemetal-check-item');
    
    // --- Lucide Icons ---
    lucide.createIcons();
});
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>