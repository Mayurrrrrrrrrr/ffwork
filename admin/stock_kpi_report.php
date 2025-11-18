<?php
require_once '../includes/init.php'; // Main init
require_once '../includes/header.php'; // Main header

// Security check: Only admins
if (!has_any_role(['admin', 'platform_admin'])) {
    header("Location: " . BASE_URL . "index.php");
    exit;
}

// Global error message collector
$error_message = '';

// --- Fetch data for filters ---
$stores = [];
$categories = [];
$collections = [];

// Get Stores from STORES TABLE (FINAL FIX: Changing column to 'store_name' in the 'stores' table)
$stmt_stores = $conn->prepare("SELECT DISTINCT store_name FROM stores WHERE company_id = ? AND store_name IS NOT NULL AND store_name != '' ORDER BY store_name");
$stmt_stores->bind_param("i", $company_id_context);
$stmt_stores->execute();
$result_stores = $stmt_stores->get_result();
while ($row = $result_stores->fetch_assoc()) {
    $stores[] = $row['store_name'];
}
$stmt_stores->close();

// Get Categories from stock_data (RELAXED FILTER)
$stmt_categories = $conn->prepare("SELECT DISTINCT Category FROM stock_data WHERE company_id = ? AND Category IS NOT NULL ORDER BY Category");
$stmt_categories->bind_param("i", $company_id_context);
$stmt_categories->execute();
$result_categories = $stmt_categories->get_result();
while ($row = $result_categories->fetch_assoc()) {
    $categories[] = $row['Category'];
}
$stmt_categories->close();

// Get Collections from collection_master (RELAXED FILTER)
$stmt_collections = $conn->prepare("SELECT DISTINCT collection FROM collection_master WHERE company_id = ? AND collection IS NOT NULL ORDER BY collection");
$stmt_collections->bind_param("i", $company_id_context);
$stmt_collections->execute();
$result_collections = $stmt_collections->get_result();
while ($row = $result_collections->fetch_assoc()) {
    $collections[] = $row['collection'];
}
$stmt_collections->close();


// --- Helper function to build "IN (...)" clauses safely and correctly populate params/types arrays ---
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


// --- Process Filters ---
// Default date range set to the last 12 months for initial data population
$filter_start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-1 year'));
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');

$filter_store = $_GET['store'] ?? [];
$filter_category = $_GET['category'] ?? [];
$filter_collection = $_GET['collection'] ?? [];
$filter_style = $_GET['style'] ?? '';

// --- Stock Filters (for Inventory/Total Stock KPIs) ---
$params_stock = [$company_id_context]; // Starts with company_id (integer)
$where_stock_conditions = ["s.company_id = ?", "s.Qty > 0"];
$types_stock = "i"; // 'i' for company_id

// --- Sales Filters (for Stock Turnaround KPIs) ---
$params_sales = [];
$where_sales_conditions = ["s.EntryType = 'FF'", "s.InwardDate IS NOT NULL", "s.TransactionDate IS NOT NULL", "DATEDIFF(s.TransactionDate, s.InwardDate) >= 0"];
$types_sales = ""; 


// --- Apply dynamic filters to both datasets ---
$total_stores = count($stores);
$total_categories = count($categories);
$total_collections = count($collections);

// Store filter
if (!empty($filter_store)) {
    // FIX: Using 'LocationName' for stock_data as per previous attempts
    build_in_clause('LocationName', $filter_store, $total_stores, $where_stock_conditions, $params_stock, $types_stock, 's');
    // Using 'StoreCode' for sales_reports as per previous attempts
    build_in_clause('StoreCode', $filter_store, $total_stores, $where_sales_conditions, $params_sales, $types_sales, 's'); 
}

// Category filter
if (!empty($filter_category)) {
    build_in_clause('Category', $filter_category, $total_categories, $where_stock_conditions, $params_stock, $types_stock, 's');
    build_in_clause('ProductCategory', $filter_category, $total_categories, $where_sales_conditions, $params_sales, $types_sales, 's'); 
}

// Collection filter
if (!empty($filter_collection)) {
    build_in_clause('collection', $filter_collection, $total_collections, $where_stock_conditions, $params_stock, $types_stock, 'cm'); 
    build_in_clause('Collection', $filter_collection, $total_collections, $where_sales_conditions, $params_sales, $types_sales, 's');
}

// Style Code filter
if (!empty($filter_style)) {
    $style_like = "%" . $filter_style . "%";
    $where_stock_conditions[] = "s.StyleCode LIKE ?";
    $where_sales_conditions[] = "s.StyleCode LIKE ?";
    $params_stock[] = $style_like;
    $params_sales[] = $style_like;
    $types_stock .= "s";
    $types_sales .= "s";
}

// --- Apply Date Filter to Sales (must be done after dynamic filters to maintain param/type order) ---
if (!empty($filter_start_date) && !empty($filter_end_date)) {
    $where_sales_conditions[] = "s.TransactionDate BETWEEN ? AND ?";
    $params_sales[] = $filter_start_date;
    $params_sales[] = $filter_end_date;
    $types_sales .= "ss";
}

// --- Assemble Final Where Clauses ---
$where_stock = implode(' AND ', $where_stock_conditions);
$where_sales = implode(' AND ', $where_sales_conditions);
$where_stock_sql = " WHERE " . $where_stock;
$where_sales_sql = " WHERE " . $where_sales;

// --- SQL Joins ---
$join_stock = "FROM stock_data s";
if (!empty($filter_collection)) {
    $join_stock .= " LEFT JOIN collection_master cm ON s.StyleCode = cm.style_code AND s.company_id = cm.company_id";
}


// --- Execute KPI Queries ---

// 1. Total Stock Metrics (Qty, GrossWt, CaratWt)
$total_stock_qty = 0;
$total_stock_gross_wt = 0;
$total_stock_carat_wt = 0;

$sql_total_stock = "SELECT SUM(s.Qty) as TotalQty, SUM(s.GrossWt) as TotalGrossWt, SUM(s.DiaWt + s.CsWt) as TotalCaratWt
                    $join_stock
                    {$where_stock_sql}";

$stmt_total_stock = $conn->prepare($sql_total_stock);
if ($stmt_total_stock) {
    if (!empty($params_stock)) { $stmt_total_stock->bind_param($types_stock, ...$params_stock); }
    $stmt_total_stock->execute();
    $result = $stmt_total_stock->get_result()->fetch_assoc();
    $total_stock_qty = $result['TotalQty'] ?? 0;
    $total_stock_gross_wt = $result['TotalGrossWt'] ?? 0;
    $total_stock_carat_wt = $result['TotalCaratWt'] ?? 0;
    $stmt_total_stock->close();
} else {
    $error_message .= "Error preparing total stock query: " . $conn->error . "<br>";
}


// 2. Stock Availability by Location & Category 
$stock_availability = [];
$sql_stock_avail = "SELECT s.LocationName, s.Category, SUM(s.Qty) as TotalQty, SUM(s.GrossWt) as TotalGrossWt, SUM(s.DiaWt + s.CsWt) as TotalCaratWt
                    $join_stock
                    {$where_stock_sql}
                    GROUP BY s.LocationName, s.Category
                    ORDER BY s.LocationName, TotalQty DESC";

$stmt_stock_avail = $conn->prepare($sql_stock_avail);
if ($stmt_stock_avail) {
    if (!empty($params_stock)) { $stmt_stock_avail->bind_param($types_stock, ...$params_stock); }
    $stmt_stock_avail->execute();
    $result_stock_avail = $stmt_stock_avail->get_result();
    while ($row = $result_stock_avail->fetch_assoc()) {
        $stock_availability[] = $row;
    }
    $stmt_stock_avail->close();
} else {
    $error_message .= "Error preparing stock availability query: " . $conn->error . "<br>";
}

// 3. New KPI: Stock Turnaround by Category ONLY (As requested)
$stock_turnaround_category = [];
$sql_stock_turn_category = "SELECT s.ProductCategory, AVG(DATEDIFF(s.TransactionDate, s.InwardDate)) as AvgDaysToSell, COUNT(s.id) as SoldUnits
                   FROM sales_reports s
                   {$where_sales_sql}
                   GROUP BY s.ProductCategory
                   ORDER BY AvgDaysToSell ASC";

$stmt_stock_turn_category = $conn->prepare($sql_stock_turn_category);
if ($stmt_stock_turn_category) {
    if (!empty($params_sales)) {
        $stmt_stock_turn_category->bind_param($types_sales, ...$params_sales);
    }
    $stmt_stock_turn_category->execute();
    $result_stock_turn_category = $stmt_stock_turn_category->get_result();
    while ($row = $result_stock_turn_category->fetch_assoc()) {
        $stock_turnaround_category[] = $row;
    }
    $stmt_stock_turn_category->close();
} else {
     $error_message .= "Error preparing stock turnaround query (Category Only): " . $conn->error . "<br>";
}


// 4. Original KPI: Stock Turnaround by Category & Subcategory
$stock_turnaround_subcategory = [];
$sql_stock_turn_subcategory = "SELECT s.ProductCategory, s.ProductSubcategory, AVG(DATEDIFF(s.TransactionDate, s.InwardDate)) as AvgDaysToSell, COUNT(s.id) as SoldUnits
                   FROM sales_reports s
                   {$where_sales_sql}
                   GROUP BY s.ProductCategory, s.ProductSubcategory
                   ORDER BY AvgDaysToSell ASC";

$stmt_stock_turn_subcategory = $conn->prepare($sql_stock_turn_subcategory);
if ($stmt_stock_turn_subcategory) {
    if (!empty($params_sales)) {
        $stmt_stock_turn_subcategory->bind_param($types_sales, ...$params_sales);
    }
    $stmt_stock_turn_subcategory->execute();
    $result_stock_turn_subcategory = $stmt_stock_turn_subcategory->get_result();
    while ($row = $result_stock_turn_subcategory->fetch_assoc()) {
        $stock_turnaround_subcategory[] = $row;
    }
    $stmt_stock_turn_subcategory->close();
} else {
     $error_message .= "Error preparing stock turnaround query (Subcategory): " . $conn->error . "<br>";
}

// 5. NEW KPI: Average Stock Age (Days in Stock for CURRENT Stock) 
// Uses InwardDate (requires database fix or correct column name)
$avg_stock_age = [];
$sql_avg_stock_age = 'SELECT s.Category, AVG(DATEDIFF(NOW(), s.InwardDate)) as AvgAgeDays, COUNT(*) as TotalUnits
                      ' . $join_stock . ' 
                      ' . $where_stock_sql . ' 
                      AND s.InwardDate IS NOT NULL 
                      GROUP BY s.Category
                      ORDER BY AvgAgeDays DESC';

$stmt_avg_stock_age = $conn->prepare($sql_avg_stock_age);
if ($stmt_avg_stock_age) {
    if (!empty($params_stock)) {
        $stmt_avg_stock_age->bind_param($types_stock, ...$params_stock);
    }
    if ($stmt_avg_stock_age->execute()) {
        $result_avg_stock_age = $stmt_avg_stock_age->get_result();
        while ($row = $result_avg_stock_age->fetch_assoc()) {
            $avg_stock_age[] = $row;
        }
    }
    $stmt_avg_stock_age->close();
} else {
     $error_message .= "Error preparing Average Stock Age query. Please verify the 'InwardDate' column in your 'stock_data' table. DB Error: " . $conn->error . "<br>";
}


// 6. NEW KPI: Sales vs. Stock (Snapshot nearest dates)
$sales_stock_kpi = [
    'total_sales_units' => 0, 'opening_stock_units' => 0, 'closing_stock_units' => 0,
    'sales_vs_opening_ratio' => 0, 'sales_vs_closing_ratio' => 0
];

// 6a. Get Total Sales Units in Period
$sql_sales_units = "SELECT SUM(NetUnits) as TotalSoldUnits FROM sales_reports s {$where_sales_sql}";
$stmt_sales_units = $conn->prepare($sql_sales_units);
if ($stmt_sales_units) { 
    if (!empty($params_sales)) { $stmt_sales_units->bind_param($types_sales, ...$params_sales); }
    $stmt_sales_units->execute();
    $sales_stock_kpi['total_sales_units'] = $stmt_sales_units->get_result()->fetch_assoc()['TotalSoldUnits'] ?? 0;
    $stmt_sales_units->close();
} else {
    $error_message .= "Error preparing total sales units query: " . $conn->error . "<br>";
}


// 6b. Get Opening Stock (Snapshot before start_date)
$sql_opening_stock = "
    SELECT SUM(Qty) as TotalQty 
    FROM stock_snapshots 
    WHERE company_id = ? 
    AND snapshot_month = (
        SELECT MAX(snapshot_month) 
        FROM stock_snapshots 
        WHERE company_id = ? AND snapshot_month < ?
    )
";
$stmt_opening = $conn->prepare($sql_opening_stock);
if ($stmt_opening) {
    $stmt_opening->bind_param("iis", $company_id_context, $company_id_context, $filter_start_date);
    $stmt_opening->execute();
    $sales_stock_kpi['opening_stock_units'] = $stmt_opening->get_result()->fetch_assoc()['TotalQty'] ?? 0;
    $stmt_opening->close();
} else {
    $error_message .= "Error preparing opening stock query: " . $conn->error . "<br>";
}


// 6c. Get Closing Stock (Snapshot closest to or after end_date)
$sql_closing_stock = "
    SELECT SUM(Qty) as TotalQty 
    FROM stock_snapshots 
    WHERE company_id = ? 
    AND snapshot_month = (
        SELECT MIN(snapshot_month) 
        FROM stock_snapshots 
        WHERE company_id = ? AND snapshot_month >= ?
    )
";
$stmt_closing = $conn->prepare($sql_closing_stock);
if ($stmt_closing) {
    $stmt_closing->bind_param("iis", $company_id_context, $company_id_context, $filter_end_date);
    $stmt_closing->execute();
    $sales_stock_kpi['closing_stock_units'] = $stmt_closing->get_result()->fetch_assoc()['TotalQty'] ?? 0;
    $stmt_closing->close();
} else {
     $error_message .= "Error preparing closing stock query: " . $conn->error . "<br>";
}


// 6d. Calculate Ratios
$sales_stock_kpi['sales_vs_opening_ratio'] = ($sales_stock_kpi['opening_stock_units'] > 0) ? ($sales_stock_kpi['total_sales_units'] / $sales_stock_kpi['opening_stock_units']) * 100 : 0;
$sales_stock_kpi['sales_vs_closing_ratio'] = ($sales_stock_kpi['closing_stock_units'] > 0) ? ($sales_stock_kpi['total_sales_units'] / $sales_stock_kpi['closing_stock_units']) * 100 : 0;

?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Stock KPI Dashboard</h2>
    </div>

    <?php if ($error_message): ?><div class="alert alert-danger">**Database Error(s):** <?php echo $error_message; ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" id="filter-form">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label for="start_date" class="form-label">Date Range (for Turnaround)</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date ?? ''); ?>">
                        <input type="date" class="form-control mt-1" id="end_date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date ?? ''); ?>">
                    </div>

                    <div class="col-md-2">
                        <label for="store-select" class="form-label">Store</label>
                        <select id="store-select" name="store[]" class="form-select" multiple>
                            <?php foreach ($stores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store); ?>" <?php echo in_array($store, $filter_store) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($store); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="category-select" class="form-label">Category</label>
                        <select id="category-select" name="category[]" class="form-select" multiple>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo in_array($category, $filter_category) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="collection-select" class="form-label">Collection</label>
                        <select id="collection-select" name="collection[]" class="form-select" multiple>
                            <?php foreach ($collections as $collection): ?>
                                <option value="<?php echo htmlspecialchars($collection); ?>" <?php echo in_array($collection, $filter_collection) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($collection); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label for="style-filter" class="form-label">Style Code</label>
                        <input type="text" class="form-control" id="style-filter" name="style" value="<?php echo htmlspecialchars($filter_style); ?>" placeholder="Enter Style Code...">
                    </div>

                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary me-2">Apply</button>
                        <a href="stock_kpi_report.php" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total Stock (Qty)</h5>
                    <p class="card-text fs-2 fw-bold"><?php echo number_format($total_stock_qty, 0); ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total Stock (Gross Wt)</h5>
                    <p class="card-text fs-2 fw-bold"><?php echo number_format($total_stock_gross_wt, 2); ?> g</p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm text-center">
                <div class="card-body">
                    <h5 class="card-title text-muted">Total Stock (Carat Wt)</h5>
                    <p class="card-text fs-2 fw-bold"><?php echo number_format($total_stock_carat_wt, 2); ?> ct</p>
                </div>
            </div>
        </div>
    </div>
    
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Sales vs. Stock Coverage (Units)</h5>
                </div>
                <div class="card-body text-center">
                    <p class="text-muted">Analyzes units sold in the period against snapshot stock levels.</p>
                    <div class="row">
                        <div class="col-md-4">
                            <h6 class="text-muted">Total Units Sold (Period)</h6>
                            <p class="fs-4 fw-bold text-primary"><?php echo number_format($sales_stock_kpi['total_sales_units']); ?></p>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Sales vs. Opening Stock</h6>
                            <p class="fs-4 fw-bold text-success"><?php echo number_format($sales_stock_kpi['sales_vs_opening_ratio'], 2); ?>%</p>
                            <small class="text-muted">(Opening Stock: <?php echo number_format($sales_stock_kpi['opening_stock_units']); ?> units)</small>
                        </div>
                        <div class="col-md-4">
                            <h6 class="text-muted">Sales vs. Closing Stock</h6>
                            <p class="fs-4 fw-bold text-success"><?php echo number_format($sales_stock_kpi['sales_vs_closing_ratio'], 2); ?>%</p>
                            <small class="text-muted">(Closing Stock: <?php echo number_format($sales_stock_kpi['closing_stock_units']); ?> units)</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Stock Turnaround (Category)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Product Category</th>
                                    <th>Sold Units</th>
                                    <th>Avg. Days to Sell</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_turnaround_category)): ?>
                                    <tr><td colspan="3" class="text-center">No sales data found for Category.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($stock_turnaround_category as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                            <td><?php echo number_format($row['SoldUnits'], 0); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['AvgDaysToSell'], 1); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Average Current Stock Age</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Category</th>
                                    <th>Total Units (Qty)</th>
                                    <th>Avg. Days in Stock</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($avg_stock_age)): ?>
                                    <tr><td colspan="3" class="text-center">No current stock data found or 'InwardDate' column is missing/empty.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($avg_stock_age as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['Category']); ?></td>
                                            <td><?php echo number_format($row['TotalUnits'], 0); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['AvgAgeDays'], 1); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Stock Turnaround (Category/Subcategory)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Category</th>
                                    <th>Sub-Category</th>
                                    <th>Sold Units</th>
                                    <th>Avg. Days to Sell</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_turnaround_subcategory)): ?>
                                    <tr><td colspan="4" class="text-center">No sales data found for these filters. Try adjusting the date range.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($stock_turnaround_subcategory as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                            <td><?php echo htmlspecialchars($row['ProductSubcategory']); ?></td>
                                            <td><?php echo number_format($row['SoldUnits'], 0); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['AvgDaysToSell'], 1); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0">Current Stock Availability</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Location</th>
                                    <th>Category</th>
                                    <th>Total Qty</th>
                                    <th>Gross Wt (g)</th>
                                    <th>Carat Wt (ct)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_availability)): ?>
                                    <tr><td colspan="5" class="text-center">No current stock found for these filters.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($stock_availability as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['LocationName']); ?></td>
                                            <td><?php echo htmlspecialchars($row['Category']); ?></td>
                                            <td><?php echo number_format($row['TotalQty'], 0); ?></td>
                                            <td><?php echo number_format($row['TotalGrossWt'], 2); ?></td>
                                            <td><?php echo number_format($row['TotalCaratWt'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>


    </div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
$(document).ready(function() {
    // Initialize Select2
    $('#store-select').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Stores'
    });
    $('#category-select').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Categories'
    });
    $('#collection-select').select2({
        theme: 'bootstrap-5',
        placeholder: 'All Collections'
    });
});
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; // Main footer
?>