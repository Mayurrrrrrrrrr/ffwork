<?php
// admin/sales_customer_report.php

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
    'stores' => [], 'categories' => [], 'stocktypes' => [], 'basemetals' => []
];
$report_results = [];
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
$filter_options['basemetals'] = get_distinct_values($conn, 'BaseMetal');

// --- Set Filter Defaults ---
$start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$f_stores = $_GET['stores'] ?? [];
$f_categories = $_GET['categories'] ?? [];
$f_stocktypes = $_GET['stocktypes'] ?? [];
$f_basemetals = $_GET['basemetals'] ?? [];

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

// --- DYNAMIC SQL BUILDER (if form is submitted) ---
if (isset($_GET['generate_report'])) {
    
    // --- Get total counts for each filter type ---
    $total_stores = count($filter_options['stores']);
    $total_categories = count($filter_options['categories']);
    $total_stocktypes = count($filter_options['stocktypes']);
    $total_basemetals = count($filter_options['basemetals']);

    // --- Base Filters ---
    $where_conditions = []; 
    $where_conditions[] = "TransactionDate BETWEEN ? AND ?";
    $where_conditions[] = "EntryType = 'FF'"; // Customer report is only for sales
    $params = [$start_date, $end_date];
    $types = "ss";

    // --- ***** REVISED: Call build_in_clause with total counts ***** ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $where_conditions, $params, $types);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions, $params, $types);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions, $params, $types);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions, $params, $types);
    
    $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    
    // --- ***** CUSTOMER REPORT QUERY ***** ---
    // This query finds all customers who match the filters
    $sql_report = "SELECT
                    ClientMobile,
                    ClientName,
                    COUNT(DISTINCT TransactionNo) as TotalVisits,
                    COUNT(*) as TotalUnits,
                    SUM(NetSales) as TotalSales,
                    MAX(TransactionDate) as LastVisitDate,
                    (SUM(NetSales) / COUNT(DISTINCT TransactionNo)) as ATV,
                    (SUM(NetSales) / COUNT(*)) as ASP
                   FROM sales_reports
                   $where_clause
                   AND ClientMobile IS NOT NULL AND ClientMobile != ''
                   GROUP BY ClientMobile, ClientName
                   ORDER BY TotalSales DESC
                   LIMIT 500"; // Limit to 500
                    
    if($stmt = $conn->prepare($sql_report)){
        $stmt->bind_param($types, ...$params);
        if($stmt->execute()){
            $result = $stmt->get_result();
            while($row = $result->fetch_assoc()){ 
                $report_results[] = $row; 
            }
        } else { $error_message .= "Customer Report Error: ". $stmt->error . "<br>"; }
        $stmt->close();
    } else { $error_message .= "DB Prepare Error (Customer): ". $conn->error . "<br>"; }
}

?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .report-card-body {
        max-height: 500px;
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
    .table th { background-color: #f8f9fa; }
    .table td.text-end { text-align: right; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Customer Analytics Report</h2>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

<div class="card mb-4 no-print shadow-sm">
    <div class="card-body">
        <form action="admin/sales_customer_report.php" method="get" class="row g-3 align-items-end">
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

<div class="card mb-4" id="report-customer">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i data-lucide="users" class="me-2 text-muted"></i>Customer Report (Top 500 by Sales)</h5>
        <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-customer', 'customer_report.csv')">
            <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
        </button>
    </div>
    <div class="card-body report-card-body">
        <table class="table table-striped table-hover table-sm" id="table-customer">
            <thead class="table-light sticky-top">
                <tr>
                    <th class="text-start">Client Name</th>
                    <th class="text-start">Client Mobile</th>
                    <th class="text-end">Total Sales (L)</th>
                    <th class="text-end">Total Visits</th>
                    <th class="text-end">Total Units</th>
                    <th class="text-end">ATV</th>
                    <th class="text-end">ASP</th>
                    <th class="text-start">Last Visit Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($report_results)): ?>
                    <tr><td colspan="8" class="text-center">No customers found matching criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($report_results as $row): ?>
                        <tr>
                            <td class="text-start"><?php echo htmlspecialchars($row['ClientName']); ?></td>
                            <td class="text-start"><?php echo htmlspecialchars($row['ClientMobile']); ?></td>
                            <td class="text-end"><?php echo formatInLakhs($row['TotalSales']); ?></td>
                            <td class="text-end"><?php echo number_format($row['TotalVisits']); ?></td>
                            <td class="text-end"><?php echo number_format($row['TotalUnits']); ?></td>
                            <td class="text-end"><?php echo number_format($row['ATV'], 0); ?></td>
                            <td class="text-end"><?php echo number_format($row['ASP'], 0); ?></td>
                            <td class="text-start"><?php echo date('d-M-Y', strtotime($row['LastVisitDate'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>


<script>
// --- Export to CSV Function ---
// Exports raw, unformatted numbers
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var rows = document.querySelectorAll("#" + tableId + " tr");
    
    var rawData = {
        'table-customer': <?php echo json_encode($report_results); ?>
    };

    if (tableId === 'table-customer') {
        csv.push('"Client Name","Client Mobile","Total Sales","Total Visits","Total Units","ATV","ASP","Last Visit Date"');
        for (var i in rawData['table-customer']) {
            var row = rawData['table-customer'][i];
            csv.push([
                '"' + row.ClientName + '"',
                '"' + row.ClientMobile + '"',
                row.TotalSales,
                row.TotalVisits,
                row.TotalUnits,
                row.ATV,
                row.ASP,
                '"' + row.LastVisitDate + '"'
            ].join(','));
        }
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