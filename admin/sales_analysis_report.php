<?php
require_once '../includes/init.php';
require_once '../includes/header.php';

// Security check: Only admins
if (!has_any_role(['admin', 'platform_admin'])) {
    header("Location: " . BASE_URL . "index.php");
    exit;
}

// Global error message collector
$error_message = '';

// --- NEW HELPER FUNCTIONS (Copied from Sales Dashboard) ---
// Helper function to get distinct values for filters
function get_distinct_values($conn, $table, $column, $company_id = null) {
    $options = [];
    $where = " WHERE $column IS NOT NULL AND $column != ''";
    if ($company_id !== null && $table === 'collection_master') {
        // Only collection_master is filtered by company_id here, sales_reports is assumed to be company-scoped elsewhere or not needed.
        $where = " WHERE company_id = $company_id AND $column IS NOT NULL AND $column != ''";
    }
    $sql = "SELECT DISTINCT $column FROM $table $where ORDER BY $column";
    
    // Use prepared statements for robustness if needed, but simple query is fine here
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $options[] = $row[$column];
        }
    }
    return $options;
}

// Helper function to build "IN (...)" clauses safely
function build_in_clause($field, $values, $total_options, &$where_conditions, &$params, &$types, $alias = 's') {
    // Only apply filter if something is selected AND not all options are selected 
    if (!empty($values) && count($values) < $total_options) {
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        $where_conditions[] = "$alias.$field IN ($placeholders)";
        foreach ($values as $val) {
            $params[] = $val;
            $types .= "s";
        }
    }
}
// --- END NEW HELPER FUNCTIONS ---


// --- Fetch data for filters (FULLY REVISED) ---
$all_stores = get_distinct_values($conn, 'sales_reports', 'StoreCode');
$all_stocktypes = get_distinct_values($conn, 'sales_reports', 'Stocktype');
$all_categories = get_distinct_values($conn, 'sales_reports', 'ProductCategory');
$all_basemetals = get_distinct_values($conn, 'sales_reports', 'BaseMetal');

// Fetch collections from collection_master (requires company_id context)
$collections = [];
$stmt_collections = $conn->prepare("SELECT DISTINCT collection FROM collection_master WHERE company_id = ? AND collection IS NOT NULL ORDER BY collection");
$stmt_collections->bind_param("i", $company_id_context);
$stmt_collections->execute();
$result_collections = $stmt_collections->get_result();
while ($row = $result_collections->fetch_assoc()) {
    $collections[] = $row['collection'];
}
$stmt_collections->close();


// --- Process Filters (FULLY REVISED) ---
$filter_start_date = $_GET['start_date'] ?? date('Y-m-01', strtotime('-1 year'));
$filter_end_date = $_GET['end_date'] ?? date('Y-m-d');
$filter_store = $_GET['store_id'] ?? 'all'; // Single select store filter
$filter_stocktype = $_GET['stocktypes'] ?? []; // Array for stocktype checkboxes
$filter_category = $_GET['categories'] ?? []; // Array for category checkboxes
$filter_basemetal = $_GET['basemetals'] ?? []; // Array for basemetal checkboxes
$filter_collection = $_GET['collections'] ?? []; // Array for collection checkboxes


// --- Sales Filters (for all KPIs) ---
$params_sales = [];
$where_sales_conditions = ["s.EntryType = 'FF'", "s.TransactionDate IS NOT NULL"];
$types_sales = "";

// Get total counts for the multi-select logic
$total_stores = count($all_stores);
$total_stocktypes = count($all_stocktypes);
$total_categories = count($all_categories);
$total_basemetals = count($all_basemetals);
$total_collections = count($collections);


// Date Filter
if (!empty($filter_start_date) && !empty($filter_end_date)) {
    $where_sales_conditions[] = "s.TransactionDate BETWEEN ? AND ?";
    $params_sales[] = $filter_start_date;
    $params_sales[] = $filter_end_date;
    $types_sales .= "ss";
}

// Store filter (Single Select)
if ($filter_store !== 'all') {
    $where_sales_conditions[] = "s.StoreCode = ?";
    $params_sales[] = $filter_store;
    $types_sales .= "s";
}

// Stock Type filter (Multi Select Checkboxes)
build_in_clause('Stocktype', $filter_stocktype, $total_stocktypes, $where_sales_conditions, $params_sales, $types_sales, 's');

// Category filter (Multi Select Checkboxes)
build_in_clause('ProductCategory', $filter_category, $total_categories, $where_sales_conditions, $params_sales, $types_sales, 's');

// Base Metal filter (Multi Select Checkboxes)
build_in_clause('BaseMetal', $filter_basemetal, $total_basemetals, $where_sales_conditions, $params_sales, $types_sales, 's');

// Collection filter (Multi Select Checkboxes)
build_in_clause('Collection', $filter_collection, $total_collections, $where_sales_conditions, $params_sales, $types_sales, 's');


// Assemble Final Where Clause
$where_sales = implode(' AND ', $where_sales_conditions);
$where_sales_sql = " WHERE " . $where_sales;


// --- Execute KPI Queries (UNCHANGED LOGIC) ---
$kpi_data = [];

$base_sql = "SELECT s.ProductCategory, s.ProductSubcategory, s.Collection, 
             SUM(s.NetUnits) AS TotalUnits, SUM(s.TotDiaWt) AS TotalDiamondWt,
             SUM(s.SolitairePieces) AS TotalSolitaireQty, SUM(s.SolitaireWeight) AS TotalSolitaireWt,
             AVG(s.DiscountPercentage) AS AvgDiscount, SUM(s.NetSales) AS TotalSale 
             FROM sales_reports s ";


// 1. Ct. wise stock sold (Categorywise)
$sql_ct_category = $base_sql . $where_sales_sql . " GROUP BY s.ProductCategory ORDER BY TotalDiamondWt DESC";
$stmt_ct_category = $conn->prepare($sql_ct_category);
if ($stmt_ct_category) {
    if (!empty($params_sales)) { $stmt_ct_category->bind_param($types_sales, ...$params_sales); }
    $stmt_ct_category->execute();
    $kpi_data['ct_category'] = $stmt_ct_category->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_ct_category->close();
} else { $error_message .= "Error preparing Ct. Category query: " . $conn->error . "<br>"; }

// 1. Ct. wise stock sold (SubCategorywise)
$sql_ct_subcategory = $base_sql . $where_sales_sql . " GROUP BY s.ProductCategory, s.ProductSubcategory ORDER BY TotalDiamondWt DESC";
$stmt_ct_subcategory = $conn->prepare($sql_ct_subcategory);
if ($stmt_ct_subcategory) {
    if (!empty($params_sales)) { $stmt_ct_subcategory->bind_param($types_sales, ...$params_sales); }
    $stmt_ct_subcategory->execute();
    $kpi_data['ct_subcategory'] = $stmt_ct_subcategory->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_ct_subcategory->close();
} else { $error_message .= "Error preparing Ct. SubCategory query: " . $conn->error . "<br>"; }


// 2. Sales contribution Solitaire wise (Overall)
$sql_solitaire = "SELECT SUM(s.NetUnits) AS TotalUnits, SUM(s.SolitairePieces) AS TotalSolitaireQty, SUM(s.SolitaireWeight) AS TotalSolitaireWt 
                  FROM sales_reports s " . $where_sales_sql;
$stmt_solitaire = $conn->prepare($sql_solitaire);
if ($stmt_solitaire) {
    if (!empty($params_sales)) { $stmt_solitaire->bind_param($types_sales, ...$params_sales); }
    $stmt_solitaire->execute();
    $kpi_data['solitaire'] = $stmt_solitaire->get_result()->fetch_assoc();
    $stmt_solitaire->close();
} else { $error_message .= "Error preparing Solitaire query: " . $conn->error . "<br>"; }


// 3. Collection wise reports (Units Sold)
$sql_collection_units = $base_sql . $where_sales_sql . " GROUP BY s.Collection ORDER BY TotalUnits DESC LIMIT 5";
$stmt_collection_units = $conn->prepare($sql_collection_units);
if ($stmt_collection_units) {
    if (!empty($params_sales)) { $stmt_collection_units->bind_param($types_sales, ...$params_sales); }
    $stmt_collection_units->execute();
    $kpi_data['collection_units'] = $stmt_collection_units->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_collection_units->close();
} else { $error_message .= "Error preparing Collection Units query: " . $conn->error . "<br>"; }

// 3. Collection wise reports (Avg Discount)
$sql_collection_discount = $base_sql . $where_sales_sql . " AND s.Collection IS NOT NULL GROUP BY s.Collection ORDER BY AvgDiscount DESC LIMIT 5";
$stmt_collection_discount = $conn->prepare($sql_collection_discount);
if ($stmt_collection_discount) {
    if (!empty($params_sales)) { $stmt_collection_discount->bind_param($types_sales, ...$params_sales); }
    $stmt_collection_discount->execute();
    $kpi_data['collection_discount'] = $stmt_collection_discount->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_collection_discount->close();
} else { $error_message .= "Error preparing Collection Discount query: " . $conn->error . "<br>"; }


// 4. Discount analysis by Category
$sql_discount_category = $base_sql . $where_sales_sql . " GROUP BY s.ProductCategory ORDER BY AvgDiscount DESC";
$stmt_discount_category = $conn->prepare($sql_discount_category);
if ($stmt_discount_category) {
    if (!empty($params_sales)) { $stmt_discount_category->bind_param($types_sales, ...$params_sales); }
    $stmt_discount_category->execute();
    $kpi_data['discount_category'] = $stmt_discount_category->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_discount_category->close();
} else { $error_message .= "Error preparing Discount Category query: " . $conn->error . "<br>"; }

?>

<style>
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
</style>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Sales Analysis Report</h2>
    </div>

    <?php if ($error_message): ?><div class="alert alert-danger">**Database Error(s):** <?php echo $error_message; ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="GET" action="" id="filter-form">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label for="start_date" class="form-label">Date Range</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($filter_start_date ?? ''); ?>">
                        <input type="date" class="form-control mt-1" id="end_date" name="end_date" value="<?php echo htmlspecialchars($filter_end_date ?? ''); ?>">
                    </div>

                    <div class="col-md-2">
                        <label for="store-select" class="form-label">Store</label>
                        <select id="store-select" name="store_id" class="form-select">
                            <option value="all" <?php if($filter_store == 'all') echo 'selected'; ?>>All Stores</option>
                            <?php foreach ($all_stores as $store): ?>
                                <option value="<?php echo htmlspecialchars($store); ?>" <?php if($filter_store == $store) echo 'selected'; ?>>
                                    <?php echo htmlspecialchars($store); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Stock Type</label>
                        <div class="filter-checkbox-list" id="stocktype-filter-list">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="stocktype-select-all">
                                <label class="form-check-label fw-bold" for="stocktype-select-all">Select All / None</label>
                            </div>
                            <hr class="my-1">
                            <?php foreach ($all_stocktypes as $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input stocktype-check-item" type="checkbox" name="stocktypes[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="st_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $filter_stocktype) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="st_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="col-md-2">
                        <label class="form-label">Category</label>
                        <div class="filter-checkbox-list" id="category-filter-list">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="category-select-all">
                                <label class="form-check-label fw-bold" for="category-select-all">Select All / None</label>
                            </div>
                            <hr class="my-1">
                            <?php foreach ($all_categories as $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input category-check-item" type="checkbox" name="categories[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="cat_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $filter_category) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="cat_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Base Metal</label>
                        <div class="filter-checkbox-list" id="basemetal-filter-list">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="basemetal-select-all">
                                <label class="form-check-label fw-bold" for="basemetal-select-all">Select All / None</label>
                            </div>
                            <hr class="my-1">
                            <?php foreach ($all_basemetals as $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input basemetal-check-item" type="checkbox" name="basemetals[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="bm_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $filter_basemetal) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="bm_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="col-md-2">
                        <label class="form-label">Collection</label>
                        <div class="filter-checkbox-list" id="collection-filter-list">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="collection-select-all">
                                <label class="form-check-label fw-bold" for="collection-select-all">Select All / None</label>
                            </div>
                            <hr class="my-1">
                            <?php foreach ($collections as $opt): ?>
                                <div class="form-check">
                                    <input class="form-check-input collection-check-item" type="checkbox" name="collections[]" 
                                        value="<?php echo htmlspecialchars($opt); ?>" id="col_<?php echo md5(htmlspecialchars($opt)); ?>" 
                                        <?php echo in_array($opt, $filter_collection) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="col_<?php echo md5(htmlspecialchars($opt)); ?>"><?php echo htmlspecialchars($opt); ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary me-2">Apply Filters</button>
                        <a href="sales_analysis_report.php" class="btn btn-secondary">Reset</a>
                    </div>
                </div>
            </form>
        </div>
    </div>


    <div class="card shadow-sm mb-4">
        <div class="card-header bg-info text-white">
            <h5 class="mb-0">Solitaire Sales Contribution (Overall Summary)</h5>
        </div>
        <div class="card-body text-center">
            <?php 
                $sol_qty = $kpi_data['solitaire']['TotalSolitaireQty'] ?? 0;
                $sol_wt = $kpi_data['solitaire']['TotalSolitaireWt'] ?? 0;
                $total_units = $kpi_data['solitaire']['TotalUnits'] ?? 0;
                $sol_qty_pct = ($total_units > 0) ? ($sol_qty / $total_units) * 100 : 0;
            ?>
            <div class="row">
                <div class="col-md-4">
                    <h6 class="text-muted">Total Units Sold (Net)</h6>
                    <p class="fs-3 fw-bold"><?php echo number_format($total_units, 0); ?></p>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted">Solitaire Pieces Sold</h6>
                    <p class="fs-3 fw-bold text-success"><?php echo number_format($sol_qty, 0); ?></p>
                    <small class="text-muted">(<?php echo number_format($sol_qty_pct, 1); ?>% of Total Units)</small>
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted">Total Solitaire Weight</h6>
                    <p class="fs-3 fw-bold text-success"><?php echo number_format($sol_wt, 3); ?> Ct.</p>
                </div>
            </div>
        </div>
    </div>


    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Diamond Weight Sold (Categorywise)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Category</th>
                                    <th>Total Diamond Ct.</th>
                                    <th>Units Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kpi_data['ct_category'])): ?>
                                    <tr><td colspan="3" class="text-center">No diamond sales data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kpi_data['ct_category'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['TotalDiamondWt'], 3); ?></td>
                                            <td><?php echo number_format($row['TotalUnits'], 0); ?></td>
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
                <div class="card-header bg-warning text-dark">
                    <h5 class="mb-0">Avg Discount Analysis (Categorywise)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Category</th>
                                    <th>Total Sale (Value)</th>
                                    <th>Avg. Discount %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kpi_data['discount_category'])): ?>
                                    <tr><td colspan="3" class="text-center">No discount data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kpi_data['discount_category'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                            <td><?php echo number_format($row['TotalSale'], 2); ?></td>
                                            <td class="fw-bold text-danger"><?php echo number_format($row['AvgDiscount'], 1); ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Diamond Weight Sold (Sub-Category Detail)</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Category</th>
                                    <th>Sub-Category</th>
                                    <th>Total Diamond Ct.</th>
                                    <th>Units Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kpi_data['ct_subcategory'])): ?>
                                    <tr><td colspan="4" class="text-center">No diamond sales data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kpi_data['ct_subcategory'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['ProductCategory']); ?></td>
                                            <td><?php echo htmlspecialchars($row['ProductSubcategory']); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['TotalDiamondWt'], 3); ?></td>
                                            <td><?php echo number_format($row['TotalUnits'], 0); ?></td>
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


    <div class="row">
        <div class="col-lg-6 mb-4">
            <div class="card shadow-sm">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Top Collections by Units Sold</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Collection</th>
                                    <th>Units Sold</th>
                                    <th>Avg. Discount %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kpi_data['collection_units'])): ?>
                                    <tr><td colspan="3" class="text-center">No collection sales data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kpi_data['collection_units'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['Collection'] ?? 'N/A'); ?></td>
                                            <td class="fw-bold"><?php echo number_format($row['TotalUnits'], 0); ?></td>
                                            <td><?php echo number_format($row['AvgDiscount'], 1); ?>%</td>
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
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0">Top Collections by Average Discount</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive" style="max-height: 400px;">
                        <table class="table table-striped table-hover table-sm">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>Collection</th>
                                    <th>Avg. Discount %</th>
                                    <th>Total Diamond Ct.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($kpi_data['collection_discount'])): ?>
                                    <tr><td colspan="3" class="text-center">No collection discount data found.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($kpi_data['collection_discount'] as $row): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($row['Collection'] ?? 'N/A'); ?></td>
                                            <td class="fw-bold text-danger"><?php echo number_format($row['AvgDiscount'], 1); ?>%</td>
                                            <td><?php echo number_format($row['TotalDiamondWt'], 3); ?></td>
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

<script src="https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js"></script>
<script>
$(document).ready(function() {
    
    /**
     * @param {string} selectAllId The ID of the "Select All" checkbox
     * @param {string} itemClass The class of the individual item checkboxes
     */
    function setupSelectAll(selectAllId, itemClass) {
        const selectAll = document.getElementById(selectAllId);
        const items = document.querySelectorAll('.' + itemClass);
        
        if (!selectAll) return;

        // Function to update "Select All" checkbox based on items' state
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

        // Event for "Select All" box
        selectAll.addEventListener('click', function() {
            for (const item of items) {
                item.checked = selectAll.checked;
            }
        });

        // Event for individual items
        for (const item of items) {
            item.addEventListener('click', function() {
                updateSelectAllState();
            });
        }
        
        // Run on page load
        updateSelectAllState();
    }

    // Initialize the checkbox logic for all multi-select filters
    setupSelectAll('stocktype-select-all', 'stocktype-check-item');
    setupSelectAll('category-select-all', 'category-check-item');
    setupSelectAll('basemetal-select-all', 'basemetal-check-item');
    setupSelectAll('collection-select-all', 'collection-check-item');
});
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; 
?>