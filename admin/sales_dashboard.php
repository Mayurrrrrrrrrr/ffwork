<?php
// admin/sales_dashboard.php

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/header.php';

if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: ". BASE_URL . "portal_home.php");
    exit;
}

function formatInLakhs($number) {
    if (!is_numeric($number) || $number == 0) {
        return '0.00';
    }
    $lakhs = $number / 100000;
    return number_format($lakhs, 2);
}

// --- Set default date filters ---
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$store_filter = $_GET['store_id'] ?? [];  // Changed to array for multi-select
$f_stocktypes = $_GET['stocktypes'] ?? [];
$f_categories = $_GET['categories'] ?? [];
$f_basemetals = $_GET['basemetals'] ?? [];

$all_stores = [];
$all_stocktypes = [];
$all_categories = [];
$all_basemetals = [];
$error_message = '';

function get_distinct_values($conn, $column) {
    $options = [];
    $sql = "SELECT DISTINCT $column FROM sales_reports ORDER BY $column IS NULL ASC, $column = '' ASC, $column ASC";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $options[] = $row[$column] ?? '';
        }
    }
    return array_unique($options);
}

$all_stores = get_distinct_values($conn, 'StoreCode');
$all_stocktypes = get_distinct_values($conn, 'Stocktype');
$all_categories = get_distinct_values($conn, 'ProductCategory');
$all_basemetals = get_distinct_values($conn, 'BaseMetal');

// Set defaults to "all selected" if not specified
if (empty($f_stocktypes) && !isset($_GET['stocktypes'])) {
    $f_stocktypes = $all_stocktypes;
}
if (empty($f_categories) && !isset($_GET['categories'])) {
    $f_categories = $all_categories;
}
if (empty($f_basemetals) && !isset($_GET['basemetals'])) {
    $f_basemetals = $all_basemetals;
}
if (empty($store_filter) && !isset($_GET['store_id'])) {
    $store_filter = $all_stores;
}

function build_in_clause($field, $values, $total_options, &$where_conditions, &$params, &$types) {
    $is_all_selected = count($values) === $total_options;
    
    if (!empty($values) && (!$is_all_selected || $total_options == 1)) {
        $include_null_or_blank = in_array('', $values);
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
            $conditions_parts[] = "$field IS NULL OR $field = ''";
        }
        
        if (!empty($conditions_parts)) {
            $where_conditions[] = "(" . implode(' OR ', $conditions_parts) . ")";
        }
    }
}

$total_stocktypes = count($all_stocktypes);
$total_categories = count($all_categories);
$total_basemetals = count($all_basemetals);
$total_stores = count($all_stores);

$where_conditions = [];
$where_conditions[] = "TransactionDate BETWEEN ? AND ?";
// Updated logic: FF is positive (sales), exclude RI and RR completely
$where_conditions[] = "(LEFT(TransactionNo, 2) = 'FF' OR LEFT(TransactionNo, 3) IN ('7DE', '7DR') OR LEFT(TransactionNo, 2) IN ('LB', 'LE', 'LR'))";
$where_conditions[] = "LEFT(TransactionNo, 2) NOT IN ('RI', 'RR')";
$params = [$start_date, $end_date];
$types = "ss";

// Store filter - now multi-select
build_in_clause('StoreCode', $store_filter, $total_stores, $where_conditions, $params, $types);
build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions, $params, $types);
build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions, $params, $types);
build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions, $params, $types);

$where_clause = " WHERE " . implode(' AND ', $where_conditions);

// --- KPI QUERY with proper positive/negative logic ---
$kpi_data = [
    'TotalNetSales' => 0, 'TotalNetUnits' => 0, 'TotalTransactions' => 0,
    'TotalGrossMargin' => 0, 'TotalSolitaireWeight' => 0,
    'TotalOriginalPrice' => 0, 'TotalDiscountAmount' => 0,
    'ATV' => 0, 'ASP' => 0, 'UPT' => 0,
    'AvgDiscountPercentage' => 0, 'AvgSolitaireWeightPerUnit' => 0,
    'GrossMarginPercentage' => 0, 'TotalReturns' => 0, 'ReturnRate' => 0
];

$sql_kpi = "SELECT
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN 1 ELSE -1 END) as TotalNetUnits,
    COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN TransactionNo END) as TotalTransactions,
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN GrossMargin ELSE -GrossMargin END) as TotalGrossMargin,
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN SolitaireWeight ELSE 0 END) as TotalSolitaireWeight,
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN OriginalSellingPrice ELSE -OriginalSellingPrice END) as TotalOriginalPrice,
    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN DiscountAmount ELSE -DiscountAmount END) as TotalDiscountAmount,
    COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) != 'FF' THEN TransactionNo END) as TotalReturns
FROM sales_reports
$where_clause";

if($stmt_kpi = $conn->prepare($sql_kpi)){
    $stmt_kpi->bind_param($types, ...$params);
    if($stmt_kpi->execute()){
        $result = $stmt_kpi->get_result()->fetch_assoc();
        if ($result) {
            $kpi_data['TotalNetSales'] = $result['TotalNetSales'] ?? 0;
            $kpi_data['TotalNetUnits'] = $result['TotalNetUnits'] ?? 0;
            $kpi_data['TotalTransactions'] = $result['TotalTransactions'] ?? 0;
            $kpi_data['TotalGrossMargin'] = $result['TotalGrossMargin'] ?? 0;
            $kpi_data['TotalSolitaireWeight'] = $result['TotalSolitaireWeight'] ?? 0;
            $kpi_data['TotalOriginalPrice'] = $result['TotalOriginalPrice'] ?? 0;
            $kpi_data['TotalDiscountAmount'] = $result['TotalDiscountAmount'] ?? 0;
            $kpi_data['TotalReturns'] = $result['TotalReturns'] ?? 0;
            
            $kpi_data['ATV'] = ($kpi_data['TotalTransactions'] > 0) ? ($kpi_data['TotalNetSales'] / $kpi_data['TotalTransactions']) : 0;
            $kpi_data['ASP'] = ($kpi_data['TotalNetUnits'] > 0) ? ($kpi_data['TotalNetSales'] / $kpi_data['TotalNetUnits']) : 0;
            $kpi_data['UPT'] = ($kpi_data['TotalTransactions'] > 0) ? ($kpi_data['TotalNetUnits'] / $kpi_data['TotalTransactions']) : 0;
            $kpi_data['AvgDiscountPercentage'] = ($kpi_data['TotalOriginalPrice'] > 0) ? (($kpi_data['TotalDiscountAmount'] / $kpi_data['TotalOriginalPrice']) * 100) : 0;
            $kpi_data['AvgSolitaireWeightPerUnit'] = ($kpi_data['TotalNetUnits'] > 0) ? ($kpi_data['TotalSolitaireWeight'] / $kpi_data['TotalNetUnits']) : 0;
            $kpi_data['GrossMarginPercentage'] = ($kpi_data['TotalNetSales'] > 0) ? (($kpi_data['TotalGrossMargin'] / $kpi_data['TotalNetSales']) * 100) : 0;
            $kpi_data['ReturnRate'] = ($kpi_data['TotalTransactions'] > 0) ? (($kpi_data['TotalReturns'] / $kpi_data['TotalTransactions']) * 100) : 0;
        }
    } else { $error_message .= "KPI Report Error: ". $stmt_kpi->error . "<br>"; }
    $stmt_kpi->close();
} else { $error_message .= "DB Prepare Error (KPI): ". $conn->error . "<br>"; }

// --- STORE-WISE COMPREHENSIVE KPIs ---
$store_kpi_data = [];
$sql_store_kpi = "SELECT
                      StoreCode,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN 1 ELSE -1 END) as TotalNetUnits,
                      COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN TransactionNo END) as TotalTransactions,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN GrossMargin ELSE -GrossMargin END) as TotalGrossMargin,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN DiscountAmount ELSE -DiscountAmount END) as TotalDiscountAmount,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN OriginalSellingPrice ELSE -OriginalSellingPrice END) as TotalOriginalPrice,
                      COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) != 'FF' THEN TransactionNo END) as TotalReturns
                  FROM sales_reports
                  $where_clause
                  AND StoreCode IS NOT NULL AND StoreCode != ''
                  GROUP BY StoreCode
                  ORDER BY TotalNetSales DESC";
if($stmt_store_kpi = $conn->prepare($sql_store_kpi)){
    $stmt_store_kpi->bind_param($types, ...$params);
    if($stmt_store_kpi->execute()){
        $result_store_kpi = $stmt_store_kpi->get_result();
        while($row = $result_store_kpi->fetch_assoc()){  
            $row['ATV'] = ($row['TotalTransactions'] > 0) ? ($row['TotalNetSales'] / $row['TotalTransactions']) : 0;
            $row['ASP'] = ($row['TotalNetUnits'] > 0) ? ($row['TotalNetSales'] / $row['TotalNetUnits']) : 0;
            $row['UPT'] = ($row['TotalTransactions'] > 0) ? ($row['TotalNetUnits'] / $row['TotalTransactions']) : 0;
            $row['GrossMarginPct'] = ($row['TotalNetSales'] > 0) ? (($row['TotalGrossMargin'] / $row['TotalNetSales']) * 100) : 0;
            $row['DiscountPct'] = ($row['TotalOriginalPrice'] > 0) ? (($row['TotalDiscountAmount'] / $row['TotalOriginalPrice']) * 100) : 0;
            $row['ReturnRate'] = ($row['TotalTransactions'] > 0) ? (($row['TotalReturns'] / $row['TotalTransactions']) * 100) : 0;
            $store_kpi_data[] = $row;  
        }
    } else { $error_message .= "Store KPI Report Error: ". $stmt_store_kpi->error . "<br>"; }
    $stmt_store_kpi->close();
} else { $error_message .= "DB Prepare Error (Store KPI): ". $conn->error . "<br>"; }

// --- STORE-WISE TOP SALESPERSON ---
$store_salesperson_data = [];
$sql_store_sp = "SELECT 
                    StoreCode, 
                    SALESEXU,
                    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
                    COUNT(*) as TotalUnits
                 FROM sales_reports
                 $where_clause
                 AND SALESEXU IS NOT NULL AND SALESEXU != ''
                 GROUP BY StoreCode, SALESEXU
                 ORDER BY StoreCode, TotalNetSales DESC";
if($stmt_store_sp = $conn->prepare($sql_store_sp)){
    $stmt_store_sp->bind_param($types, ...$params);
    if($stmt_store_sp->execute()){
        $result_store_sp = $stmt_store_sp->get_result();
        $temp_data = [];
        while($row = $result_store_sp->fetch_assoc()){
            if (!isset($temp_data[$row['StoreCode']])) {
                $temp_data[$row['StoreCode']] = $row; // Get top salesperson per store
            }
        }
        $store_salesperson_data = array_values($temp_data);
    }
    $stmt_store_sp->close();
}

// --- REPEAT CUSTOMER ANALYSIS (Same customer, same day = 1 transaction, >2 purchases = repeat) ---
$repeat_customer_data = [];
$rc_where_clause = " WHERE TransactionDate BETWEEN ? AND ? AND LEFT(TransactionNo, 2) = 'FF' AND ClientMobile IS NOT NULL AND ClientMobile != '' ";
$rc_params = [$start_date, $end_date];
$rc_types = "ss";

// Add store filter for repeat customers
if (!empty($store_filter) && count($store_filter) < $total_stores) {
    $placeholders = implode(',', array_fill(0, count($store_filter), '?'));
    $rc_where_clause .= " AND StoreCode IN ($placeholders) ";
    foreach ($store_filter as $store) {
        $rc_params[] = $store;
        $rc_types .= "s";
    }
}

$sql_rc = "SELECT
                ClientName,
                ClientMobile,
                COUNT(DISTINCT CONCAT(TransactionDate, ClientMobile)) as TotalVisits,
                SUM(NetSales) as TotalNetSales
              FROM sales_reports
              $rc_where_clause
              GROUP BY ClientMobile, ClientName
              HAVING TotalVisits > 1
              ORDER BY TotalNetSales DESC
              LIMIT 50";
if($stmt_rc = $conn->prepare($sql_rc)){
    $stmt_rc->bind_param($rc_types, ...$rc_params);
    if($stmt_rc->execute()){
        $result_rc = $stmt_rc->get_result();
        while($row = $result_rc->fetch_assoc()){ $repeat_customer_data[] = $row; }
    }
    $stmt_rc->close();
}

// --- TOP 10 JEWEL CODE WISE SALES ---
$top_jewelcode_data = [];
$sql_jewelcode = "SELECT
                    JewelCode,
                    ProductCategory,
                    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
                    SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN 1 ELSE -1 END) as TotalNetUnits
                  FROM sales_reports
                  $where_clause
                  AND JewelCode IS NOT NULL AND JewelCode != ''
                  GROUP BY JewelCode, ProductCategory
                  ORDER BY TotalNetSales DESC
                  LIMIT 10";
if($stmt_jewelcode = $conn->prepare($sql_jewelcode)){
    $stmt_jewelcode->bind_param($types, ...$params);
    if($stmt_jewelcode->execute()){
        $result_jewelcode = $stmt_jewelcode->get_result();
        while($row = $result_jewelcode->fetch_assoc()){ $top_jewelcode_data[] = $row; }
    } else { $error_message .= "Jewel Code Report Error: ". $stmt_jewelcode->error . "<br>"; }
    $stmt_jewelcode->close();
} else { $error_message .= "DB Prepare Error (Jewel Code): ". $conn->error . "<br>"; }

// --- STORE-WISE REPEAT CUSTOMER RATIO ---
$store_repeat_ratio = [];
$sql_store_repeat = "SELECT 
                        StoreCode,
                        COUNT(DISTINCT ClientMobile) as TotalCustomers,
                        COUNT(DISTINCT CASE WHEN visits > 1 THEN ClientMobile END) as RepeatCustomers
                     FROM (
                        SELECT StoreCode, ClientMobile, COUNT(DISTINCT CONCAT(TransactionDate, ClientMobile)) as visits
                        FROM sales_reports
                        $rc_where_clause
                        GROUP BY StoreCode, ClientMobile
                     ) as customer_visits
                     WHERE StoreCode IS NOT NULL AND StoreCode != ''
                     GROUP BY StoreCode";
if($stmt_store_repeat = $conn->prepare($sql_store_repeat)){
    $stmt_store_repeat->bind_param($rc_types, ...$rc_params);
    if($stmt_store_repeat->execute()){
        $result_store_repeat = $stmt_store_repeat->get_result();
        while($row = $result_store_repeat->fetch_assoc()){
            $row['RepeatRatio'] = ($row['TotalCustomers'] > 0) ? (($row['RepeatCustomers'] / $row['TotalCustomers']) * 100) : 0;
            $store_repeat_ratio[] = $row;
        }
    }
    $stmt_store_repeat->close();
}

// --- CATEGORY DATA FOR CHART ---
$category_only_data = [];
$sql_cat_only = "SELECT
                      ProductCategory,
                      SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as CategoryNetSales,
                      COUNT(*) as CategoryNetUnits
                      FROM sales_reports
                      $where_clause
                      AND ProductCategory IS NOT NULL AND ProductCategory != ''
                      GROUP BY ProductCategory
                      ORDER BY CategoryNetSales DESC";
if($stmt_cat_only = $conn->prepare($sql_cat_only)){
    $stmt_cat_only->bind_param($types, ...$params);
    if($stmt_cat_only->execute()){
        $result_cat_only = $stmt_cat_only->get_result();
        while($row = $result_cat_only->fetch_assoc()){ $category_only_data[] = $row; }
    }
    $stmt_cat_only->close();
}

// --- BASEMETAL DATA FOR CHART ---
$basemetal_data = [];
$sql_bm = "SELECT
                COALESCE(BaseMetal, 'Other') as BaseMetal,
                SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
                COUNT(*) as TotalNetUnits
             FROM sales_reports
             $where_clause
             GROUP BY COALESCE(BaseMetal, 'Other')
             ORDER BY TotalNetSales DESC";
if($stmt_bm = $conn->prepare($sql_bm)){
    $stmt_bm->bind_param($types, ...$params);
    if($stmt_bm->execute()){
        $result_bm = $stmt_bm->get_result();
        while($row = $result_bm->fetch_assoc()){ $basemetal_data[] = $row; }
    }
    $stmt_bm->close();
}

// --- MONTHLY TREND DATA ---
$monthly_kpi_data = [];
$sql_monthly_kpi = "SELECT
                        DATE_FORMAT(TransactionDate, '%Y-%m') as MonthYear,
                        SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN NetSales ELSE -NetSales END) as TotalNetSales,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN TransactionNo END) as TotalTransactions,
                        SUM(CASE WHEN LEFT(TransactionNo, 2) = 'FF' THEN 1 ELSE -1 END) as TotalNetUnits
                    FROM sales_reports
                    $where_clause
                    GROUP BY MonthYear
                    ORDER BY MonthYear ASC";

if($stmt_monthly_kpi = $conn->prepare($sql_monthly_kpi)){
    $stmt_monthly_kpi->bind_param($types, ...$params);
    if($stmt_monthly_kpi->execute()){
        $result_monthly_kpi = $stmt_monthly_kpi->get_result();
        while($row = $result_monthly_kpi->fetch_assoc()){ 
            $row['ATV'] = ($row['TotalTransactions'] > 0) ? ($row['TotalNetSales'] / $row['TotalTransactions']) : 0;
            $row['ASP'] = ($row['TotalNetUnits'] > 0) ? ($row['TotalNetSales'] / $row['TotalNetUnits']) : 0;
            $monthly_kpi_data[] = $row; 
        }
    }
    $stmt_monthly_kpi->close();
}

// --- RETURN ANALYSIS BY STORE ---
$store_return_data = [];
$sql_store_returns = "SELECT
                        StoreCode,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 3) = '7DR' THEN TransactionNo END) as SevenDayReturns,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'LR' THEN TransactionNo END) as LifetimeReturns,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 3) = '7DE' THEN TransactionNo END) as SevenDayExchanges,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'LE' THEN TransactionNo END) as LifetimeExchanges,
                        COUNT(DISTINCT CASE WHEN LEFT(TransactionNo, 2) = 'LB' THEN TransactionNo END) as LifetimeBuybacks,
                        SUM(CASE WHEN LEFT(TransactionNo, 2) != 'FF' THEN NetSales ELSE 0 END) as TotalReturnValue
                      FROM sales_reports
                      $where_clause
                      AND StoreCode IS NOT NULL AND StoreCode != ''
                      GROUP BY StoreCode
                      ORDER BY TotalReturnValue DESC";
if($stmt_store_returns = $conn->prepare($sql_store_returns)){
    $stmt_store_returns->bind_param($types, ...$params);
    if($stmt_store_returns->execute()){
        $result_store_returns = $stmt_store_returns->get_result();
        while($row = $result_store_returns->fetch_assoc()){ $store_return_data[] = $row; }
    }
    $stmt_store_returns->close();
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/lucide@latest/dist/umd/lucide.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<style>
    :root {
        --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        --danger-gradient: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        --dark-gradient: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
    }
    
    body {
        background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        font-family: 'Inter', sans-serif;
    }
    
    .dashboard-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 2rem;
        border-radius: 15px;
        margin-bottom: 2rem;
        box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
    }
    
    .stat-card {
        border: none;
        border-radius: 15px;
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(0,0,0,0.08);
        background: white;
        position: relative;
        overflow: hidden;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 15px 35px rgba(0,0,0,0.15);
    }
    
    .stat-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 5px;
        transition: height 0.3s ease;
    }
    
    .stat-card.card-primary::before { background: var(--primary-gradient); }
    .stat-card.card-success::before { background: var(--success-gradient); }
    .stat-card.card-warning::before { background: var(--warning-gradient); }
    .stat-card.card-info::before { background: var(--info-gradient); }
    .stat-card.card-danger::before { background: var(--danger-gradient); }
    .stat-card.card-dark::before { background: var(--dark-gradient); }
    
    .stat-card .card-body {
        padding: 1.5rem;
    }
    
    .stat-card h6 {
        font-weight: 600;
        font-size: 0.85rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: #6c757d;
        margin-bottom: 0.75rem;
    }
    
    .stat-card h3 {
        font-weight: 700;
        color: #2c3e50;
        font-size: 1.75rem;
        margin: 0;
    }
    
    .stat-icon {
        position: absolute;
        right: 1.5rem;
        top: 50%;
        transform: translateY(-50%);
        opacity: 0.1;
        width: 60px;
        height: 60px;
    }
    
    .filter-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        margin-bottom: 2rem;
    }
    
    .filter-card .card-body {
        padding: 2rem;
    }
    
    .filter-checkbox-list {
        max-height: 200px;
        overflow-y: auto;
        border: 2px solid #e9ecef;
        padding: 15px;
        border-radius: 10px;
        background-color: #f8f9fa;
    }
    
    .filter-checkbox-list::-webkit-scrollbar {
        width: 8px;
    }
    
    .filter-checkbox-list::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 10px;
    }
    
    .form-control, .form-select {
        border: 2px solid #e9ecef;
        border-radius: 10px;
        padding: 0.75rem 1rem;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        padding: 0.75rem 2rem;
        border-radius: 10px;
        font-weight: 600;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 25px rgba(102, 126, 234, 0.4);
    }
    
    .report-card {
        background: white;
        border-radius: 15px;
        box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        margin-bottom: 1.5rem;
    }
    
    .report-card .card-header {
        background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
        border-bottom: 3px solid #667eea;
        padding: 1.25rem 1.5rem;
        font-weight: 600;
    }
    
    .report-card-body {
        max-height: 450px;
        overflow-y: auto;
        padding: 1.5rem;
    }
    
    .report-card-body::-webkit-scrollbar {
        width: 10px;
    }
    
    .report-card-body::-webkit-scrollbar-thumb {
        background: #667eea;
        border-radius: 10px;
    }
    
    .table thead th {
        background: #f8f9fa;
        color: #2c3e50;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        position: sticky;
        top: 0;
        z-index: 10;
    }
    
    .table tbody tr:hover {
        background-color: rgba(102, 126, 234, 0.05);
    }
    
    .chart-container {
        position: relative;
        height: 350px;
        padding: 1rem;
    }
    
    .badge-metric {
        padding: 0.5rem 1rem;
        border-radius: 20px;
        font-weight: 600;
    }
    
    .badge-primary { background: var(--primary-gradient); color: white; }
    .badge-success { background: var(--success-gradient); color: white; }
    .badge-warning { background: var(--warning-gradient); color: white; }
    .badge-info { background: var(--info-gradient); color: white; }
</style>

<div class="container-fluid py-4">
    <div class="dashboard-header">
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <h1 class="mb-2" style="font-weight: 700; font-size: 2.5rem;">
                    <i data-lucide="trending-up" class="me-3" style="width: 40px; height: 40px;"></i>
                    Sales Performance Dashboard
                </h1>
                <p class="mb-0 opacity-75" style="font-size: 1.1rem;">Real-time insights into your business performance</p>
            </div>
            <div class="text-end">
                <button onclick="window.print()" class="btn btn-light no-print" style="border-radius: 10px; padding: 0.75rem 1.5rem;">
                    <i data-lucide="printer" class="me-2"></i>Print Report
                </button>
            </div>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo $error_message; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Filter Card -->
    <div class="filter-card no-print">
        <div class="card-body">
            <h5 class="mb-4" style="color: #2c3e50; font-weight: 600;">
                <i data-lucide="filter" class="me-2"></i>Filter Options
            </h5>
            <form action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="get" class="row g-3 align-items-end">
                <div class="col-lg-3 col-md-6">
                    <label for="start_date" class="form-label">
                        <i data-lucide="calendar" style="width: 16px; height: 16px;"></i> Start Date
                    </label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <label for="end_date" class="form-label">
                        <i data-lucide="calendar" style="width: 16px; height: 16px;"></i> End Date
                    </label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-lg-3 col-md-6">
                    <button type="submit" class="btn btn-primary w-100">
                        <i data-lucide="search" class="me-2" style="width: 18px; height: 18px;"></i>Generate Report
                    </button>
                </div>
                <div class="col-lg-3 col-md-6">
                    <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                        <i data-lucide="refresh-cw" class="me-2" style="width: 18px; height: 18px;"></i>Reset Filters
                    </button>
                </div>
                
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">
                        <i data-lucide="store" style="width: 16px; height: 16px;"></i> Store
                    </label>
                    <div class="filter-checkbox-list">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="store-select-all" checked>
                            <label class="form-check-label fw-bold" for="store-select-all">Select All</label>
                        </div>
                        <hr class="my-1">
                        <?php foreach ($all_stores as $opt): ?>
                            <div class="form-check">
                                <input class="form-check-input store-check-item" type="checkbox" name="store_id[]" 
                                       value="<?php echo htmlspecialchars($opt); ?>" id="store_<?php echo md5($opt); ?>" 
                                       <?php echo in_array($opt, $store_filter) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="store_<?php echo md5($opt); ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">
                        <i data-lucide="tag" style="width: 16px; height: 16px;"></i> Product Category
                    </label>
                    <div class="filter-checkbox-list">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="category-select-all" checked>
                            <label class="form-check-label fw-bold" for="category-select-all">Select All</label>
                        </div>
                        <hr class="my-1">
                        <?php foreach ($all_categories as $opt): ?>
                            <div class="form-check">
                                <input class="form-check-input category-check-item" type="checkbox" name="categories[]" 
                                       value="<?php echo htmlspecialchars($opt); ?>" id="cat_<?php echo md5($opt); ?>" 
                                       <?php echo in_array($opt, $f_categories) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="cat_<?php echo md5($opt); ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">
                        <i data-lucide="package" style="width: 16px; height: 16px;"></i> Stock Type
                    </label>
                    <div class="filter-checkbox-list">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="stocktype-select-all" checked>
                            <label class="form-check-label fw-bold" for="stocktype-select-all">Select All</label>
                        </div>
                        <hr class="my-1">
                        <?php foreach ($all_stocktypes as $opt): ?>
                            <div class="form-check">
                                <input class="form-check-input stocktype-check-item" type="checkbox" name="stocktypes[]" 
                                       value="<?php echo htmlspecialchars($opt); ?>" id="st_<?php echo md5($opt); ?>" 
                                       <?php echo in_array($opt, $f_stocktypes) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="st_<?php echo md5($opt); ?>"><?php echo htmlspecialchars($opt); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6">
                    <label class="form-label">
                        <i data-lucide="coins" style="width: 16px; height: 16px;"></i> Base Metal
                    </label>
                    <div class="filter-checkbox-list">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="basemetal-select-all" checked>
                            <label class="form-check-label fw-bold" for="basemetal-select-all">Select All</label>
                        </div>
                        <hr class="my-1">
                        <?php foreach ($all_basemetals as $opt): ?>
                            <div class="form-check">
                                <input class="form-check-input basemetal-check-item" type="checkbox" name="basemetals[]" 
                                       value="<?php echo htmlspecialchars($opt); ?>" id="bm_<?php echo md5($opt); ?>" 
                                       <?php echo in_array($opt, $f_basemetals) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="bm_<?php echo md5($opt); ?>">
                                    <?php echo htmlspecialchars($opt === '' ? 'Other' : $opt); ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Overall KPIs -->
    <h4 class="mb-3 mt-4" style="color: #2c3e50; font-weight: 600;">
        <i data-lucide="bar-chart-2" class="me-2"></i>Overall Performance
    </h4>
    <div class="row mb-4">
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-primary h-100">
                <div class="card-body position-relative">
                    <h6>Total Net Sales</h6>
                    <h3>₹<?php echo formatInLakhs($kpi_data['TotalNetSales']); ?> L</h3>
                    <i data-lucide="trending-up" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-info h-100">
                <div class="card-body position-relative">
                    <h6>ATV</h6>
                    <h3>₹<?php echo number_format($kpi_data['ATV'], 0); ?></h3>
                    <i data-lucide="shopping-cart" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-success h-100">
                <div class="card-body position-relative">
                    <h6>ASP</h6>
                    <h3>₹<?php echo number_format($kpi_data['ASP'], 0); ?></h3>
                    <i data-lucide="tag" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-warning h-100">
                <div class="card-body position-relative">
                    <h6>Units Sold</h6>
                    <h3><?php echo number_format($kpi_data['TotalNetUnits']); ?></h3>
                    <i data-lucide="package" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-danger h-100">
                <div class="card-body position-relative">
                    <h6>Transactions</h6>
                    <h3><?php echo number_format($kpi_data['TotalTransactions']); ?></h3>
                    <i data-lucide="shopping-bag" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-2 col-lg-4 col-md-6 mb-3">
            <div class="stat-card card-dark h-100">
                <div class="card-body position-relative">
                    <h6>UPT</h6>
                    <h3><?php echo number_format($kpi_data['UPT'], 2); ?></h3>
                    <i data-lucide="layers" class="stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Secondary KPIs -->
    <div class="row mb-4">
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card card-success h-100">
                <div class="card-body position-relative">
                    <h6>Gross Margin</h6>
                    <h3>₹<?php echo formatInLakhs($kpi_data['TotalGrossMargin']); ?> L</h3>
                    <span class="badge badge-success mt-2"><?php echo number_format($kpi_data['GrossMarginPercentage'], 2); ?>%</span>
                    <i data-lucide="percent" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card card-warning h-100">
                <div class="card-body position-relative">
                    <h6>Avg Discount</h6>
                    <h3><?php echo number_format($kpi_data['AvgDiscountPercentage'], 2); ?>%</h3>
                    <i data-lucide="percent-circle" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card card-danger h-100">
                <div class="card-body position-relative">
                    <h6>Total Returns</h6>
                    <h3><?php echo number_format($kpi_data['TotalReturns']); ?></h3>
                    <span class="badge badge-warning mt-2"><?php echo number_format($kpi_data['ReturnRate'], 2); ?>%</span>
                    <i data-lucide="rotate-ccw" class="stat-icon"></i>
                </div>
            </div>
        </div>
        <div class="col-lg-3 col-md-6 mb-3">
            <div class="stat-card card-info h-100">
                <div class="card-body position-relative">
                    <h6>Avg Solitaire Weight</h6>
                    <h3><?php echo number_format($kpi_data['AvgSolitaireWeightPerUnit'], 3); ?> ct</h3>
                    <i data-lucide="gem" class="stat-icon"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <h4 class="mb-3 mt-4" style="color: #2c3e50; font-weight: 600;">
        <i data-lucide="line-chart" class="me-2"></i>Sales Trends
    </h4>
    <div class="row mb-4">
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="trending-up" class="me-2"></i>Monthly ATV Trend</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="atvChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="dollar-sign" class="me-2"></i>Monthly ASP Trend</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="aspChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Distribution Charts -->
    <h4 class="mb-3 mt-4" style="color: #2c3e50; font-weight: 600;">
        <i data-lucide="pie-chart" class="me-2"></i>Sales Distribution
    </h4>
    <div class="row mb-4">
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="layers" class="me-2"></i>Category Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="categoryChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="hexagon" class="me-2"></i>Base Metal Distribution</h5>
                </div>
                <div class="card-body">
                    <div class="chart-container">
                        <canvas id="basemetalChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Store-Wise Performance -->
    <h4 class="mb-3 mt-4" style="color: #2c3e50; font-weight: 600;">
        <i data-lucide="store" class="me-2"></i>Store-Wise Performance
    </h4>
    <div class="row">
        <div class="col-12 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="bar-chart-4" class="me-2"></i>Comprehensive Store KPIs</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($store_kpi_data)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th class="text-end">Net Sales (₹L)</th>
                                <th class="text-end">ATV</th>
                                <th class="text-end">ASP</th>
                                <th class="text-end">UPT</th>
                                <th class="text-end">GM%</th>
                                <th class="text-end">Disc%</th>
                                <th class="text-end">Return%</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($store_kpi_data as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['StoreCode']); ?></td>
                                <td class="text-end fw-bold text-primary">₹<?php echo formatInLakhs($row['TotalNetSales']); ?></td>
                                <td class="text-end">₹<?php echo number_format($row['ATV'], 0); ?></td>
                                <td class="text-end">₹<?php echo number_format($row['ASP'], 0); ?></td>
                                <td class="text-end"><?php echo number_format($row['UPT'], 2); ?></td>
                                <td class="text-end"><span class="badge badge-success"><?php echo number_format($row['GrossMarginPct'], 1); ?>%</span></td>
                                <td class="text-end"><span class="badge badge-warning"><?php echo number_format($row['DiscountPct'], 1); ?>%</span></td>
                                <td class="text-end"><span class="badge badge-info"><?php echo number_format($row['ReturnRate'], 1); ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No store data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Store-Wise Top Salesperson -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="award" class="me-2"></i>Top Salesperson by Store</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($store_salesperson_data)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th>Salesperson</th>
                                <th class="text-end">Sales (₹L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($store_salesperson_data as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['StoreCode']); ?></td>
                                <td><?php echo htmlspecialchars($row['SALESEXU']); ?></td>
                                <td class="text-end fw-bold text-success">₹<?php echo formatInLakhs($row['TotalNetSales']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No salesperson data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Store-Wise Repeat Customer Ratio -->
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="repeat" class="me-2"></i>Repeat Customer Ratio by Store</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($store_repeat_ratio)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th class="text-end">Total Customers</th>
                                <th class="text-end">Repeat Customers</th>
                                <th class="text-end">Repeat Ratio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($store_repeat_ratio as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['StoreCode']); ?></td>
                                <td class="text-end"><?php echo number_format($row['TotalCustomers']); ?></td>
                                <td class="text-end"><?php echo number_format($row['RepeatCustomers']); ?></td>
                                <td class="text-end"><span class="badge badge-primary"><?php echo number_format($row['RepeatRatio'], 1); ?>%</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No repeat customer data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Return Analysis -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="rotate-ccw" class="me-2"></i>Store-Wise Return Analysis</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($store_return_data)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Store</th>
                                <th class="text-end">7-Day Returns</th>
                                <th class="text-end">7-Day Exchanges</th>
                                <th class="text-end">Lifetime Returns</th>
                                <th class="text-end">Lifetime Exchanges</th>
                                <th class="text-end">Buybacks</th>
                                <th class="text-end">Total Return Value (₹L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($store_return_data as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['StoreCode']); ?></td>
                                <td class="text-end"><?php echo number_format($row['SevenDayReturns']); ?></td>
                                <td class="text-end"><?php echo number_format($row['SevenDayExchanges']); ?></td>
                                <td class="text-end"><?php echo number_format($row['LifetimeReturns']); ?></td>
                                <td class="text-end"><?php echo number_format($row['LifetimeExchanges']); ?></td>
                                <td class="text-end"><?php echo number_format($row['LifetimeBuybacks']); ?></td>
                                <td class="text-end fw-bold text-danger">₹<?php echo formatInLakhs($row['TotalReturnValue']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No return data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Top Repeat Customers -->
    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="users" class="me-2"></i>Top 50 Repeat Customers (>1 Purchase)</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($repeat_customer_data)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Customer Name</th>
                                <th>Mobile</th>
                                <th class="text-end">Total Visits</th>
                                <th class="text-end">Total Sales (₹L)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repeat_customer_data as $row): ?>
                            <tr>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['ClientName']); ?></td>
                                <td><?php echo htmlspecialchars($row['ClientMobile']); ?></td>
                                <td class="text-end"><span class="badge badge-info"><?php echo number_format($row['TotalVisits']); ?></span></td>
                                <td class="text-end fw-bold text-success">₹<?php echo formatInLakhs($row['TotalNetSales']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No repeat customer data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Top 10 Jewel Codes -->
        <div class="col-lg-6 mb-4">
            <div class="report-card">
                <div class="card-header">
                    <h5 class="mb-0"><i data-lucide="star" class="me-2"></i>Top 10 Jewel Codes by Sales</h5>
                </div>
                <div class="report-card-body">
                    <?php if (!empty($top_jewelcode_data)): ?>
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Rank</th>
                                <th>Jewel Code</th>
                                <th>Category</th>
                                <th class="text-end">Sales (₹L)</th>
                                <th class="text-end">Units</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $rank = 1; foreach ($top_jewelcode_data as $row): ?>
                            <tr>
                                <td><span class="badge badge-primary">#<?php echo $rank++; ?></span></td>
                                <td class="fw-bold"><?php echo htmlspecialchars($row['JewelCode']); ?></td>
                                <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                <td class="text-end fw-bold text-success">₹<?php echo formatInLakhs($row['TotalNetSales']); ?></td>
                                <td class="text-end"><?php echo number_format($row['TotalNetUnits']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                        <div class="alert alert-info m-3">No jewel code data available.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Initialize Lucide icons
lucide.createIcons();

// Reset filters function
function resetFilters() {
    // Check all checkboxes
    document.querySelectorAll('.store-check-item, .category-check-item, .stocktype-check-item, .basemetal-check-item').forEach(cb => {
        cb.checked = true;
    });
    // Check all "Select All" checkboxes
    document.querySelectorAll('#store-select-all, #category-select-all, #stocktype-select-all, #basemetal-select-all').forEach(cb => {
        cb.checked = true;
    });
}

// Checkbox Select All Logic
document.addEventListener('DOMContentLoaded', function() {
    function setupSelectAll(selectAllId, itemClass) {
        const selectAll = document.getElementById(selectAllId);
        const items = document.querySelectorAll('.' + itemClass);

        function updateSelectAllState() {
            const checkedItems = document.querySelectorAll('.' + itemClass + ':checked');
            if (selectAll) {
                selectAll.checked = checkedItems.length === items.length;
                selectAll.indeterminate = checkedItems.length > 0 && checkedItems.length < items.length;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('click', function() {
                items.forEach(item => {
                    item.checked = selectAll.checked;
                });
            });
        }

        items.forEach(item => {
            item.addEventListener('click', updateSelectAllState);
        });

        updateSelectAllState();
    }

    setupSelectAll('store-select-all', 'store-check-item');
    setupSelectAll('category-select-all', 'category-check-item');
    setupSelectAll('stocktype-select-all', 'stocktype-check-item');
    setupSelectAll('basemetal-select-all', 'basemetal-check-item');
});

// Chart.js Charts
<?php if (!empty($monthly_kpi_data)): ?>
// ATV Chart
const atvCtx = document.getElementById('atvChart');
if (atvCtx) {
    new Chart(atvCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('M Y', strtotime($d['MonthYear'].'-01')); }, $monthly_kpi_data)); ?>,
            datasets: [{
                label: 'ATV (₹)',
                data: <?php echo json_encode(array_column($monthly_kpi_data, 'ATV')); ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return '₹' + value.toLocaleString(); }
                    }
                }
            }
        }
    });
}

// ASP Chart
const aspCtx = document.getElementById('aspChart');
if (aspCtx) {
    new Chart(aspCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_map(function($d) { return date('M Y', strtotime($d['MonthYear'].'-01')); }, $monthly_kpi_data)); ?>,
            datasets: [{
                label: 'ASP (₹)',
                data: <?php echo json_encode(array_column($monthly_kpi_data, 'ASP')); ?>,
                borderColor: '#11998e',
                backgroundColor: 'rgba(17, 153, 142, 0.1)',
                borderWidth: 3,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return '₹' + value.toLocaleString(); }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

<?php if (!empty($category_only_data)): ?>
// Category Chart
const categoryCtx = document.getElementById('categoryChart');
if (categoryCtx) {
    new Chart(categoryCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($category_only_data, 'ProductCategory')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($category_only_data, 'CategoryNetSales')); ?>,
                backgroundColor: [
                    '#667eea', '#764ba2', '#f093fb', '#4facfe', 
                    '#11998e', '#fa709a', '#ffd89b', '#19547b'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ₹' + (context.parsed / 100000).toFixed(2) + 'L';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>

<?php if (!empty($basemetal_data)): ?>
// Base Metal Chart
const basemetalCtx = document.getElementById('basemetalChart');
if (basemetalCtx) {
    new Chart(basemetalCtx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($basemetal_data, 'BaseMetal')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($basemetal_data, 'TotalNetSales')); ?>,
                backgroundColor: [
                    '#FFD700', '#C0C0C0', '#E5E4E2', '#CD7F32', 
                    '#667eea', '#11998e', '#fa709a'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: { font: { size: 11 } }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.label + ': ₹' + (context.parsed / 100000).toFixed(2) + 'L';
                        }
                    }
                }
            }
        }
    });
}
<?php endif; ?>
</script>

<?php require_once '../includes/footer.php'; ?>