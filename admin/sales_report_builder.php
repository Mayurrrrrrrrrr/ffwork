<?php
// admin/sales_report_builder.php

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
$column_headers = [];

// --- Define available fields for the builder ---
$available_group_by_fields = [
    'StoreCode' => 'Store Name',
    'ProductCategory' => 'Category',
    'ProductSubcategory' => 'Subcategory',
    'StyleCode' => 'Style Code',
    'BaseMetal' => 'Base Metal',
    'Stocktype' => 'Stock Type',
    'Itemsize' => 'Size',
    'SALESEXU' => 'Salesperson',
    'EntryType' => 'Transaction Type',
];

$available_value_fields = [
    'SUM(NetSales)' => 'Total Net Sales',
    'COUNT(*)' => 'Total Net Units',
    'COUNT(DISTINCT TransactionNo)' => 'Total Transactions',
    'AVG(NetSales)' => 'Average Sales Price (ASP)',
    'SUM(NetSales) / COUNT(DISTINCT TransactionNo)' => 'Average Transaction Value (ATV)',
    'COUNT(*) / COUNT(DISTINCT TransactionNo)' => 'Units Per Transaction (UPT)',
    'SUM(DiscountAmount)' => 'Total Discount Amount',
    '(SUM(DiscountAmount) / SUM(OriginalSellingPrice)) * 100' => 'Effective Discount %'
];

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

// --- Set Builder Defaults ---
$group_by_fields = $_GET['group_by'] ?? ['StoreCode', 'ProductCategory'];
$value_fields = $_GET['values'] ?? ['SUM(NetSales)', 'COUNT(*)'];
$sort_by = $_GET['sort_by'] ?? 'SUM(NetSales)';
$sort_order = $_GET['sort_order'] ?? 'DESC';


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

    // --- 1. Build WHERE clause (Filters) ---
    $where_conditions = []; 
    $where_conditions[] = "TransactionDate BETWEEN ? AND ?";
    $params = [$start_date, $end_date];
    $types = "ss";

    // --- ***** REVISED: Call build_in_clause with total counts ***** ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $where_conditions, $params, $types);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions, $params, $types);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions, $params, $types);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions, $params, $types);
    
    $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    
    // --- 2. Build SELECT clause (Values & Grouping) ---
    $select_cols = [];
    $column_headers = [];
    
    // Add Group By fields to SELECT and Headers
    foreach ($group_by_fields as $field) {
        if (array_key_exists($field, $available_group_by_fields)) {
            $select_cols[] = "$field";
            $column_headers[] = $available_group_by_fields[$field];
        }
    }
    
    // Add Value fields to SELECT and Headers
    $value_aliases = [];
    $i = 1;
    foreach ($value_fields as $field) {
        if (array_key_exists($field, $available_value_fields)) {
            $alias = "value_col_$i";
            $select_cols[] = "$field as $alias";
            $column_headers[] = $available_value_fields[$field];
            $value_aliases[$alias] = $field; // Store for sorting
            $i++;
        }
    }
    
    if (empty($select_cols) || empty($group_by_fields)) {
        $error_message = "Invalid report. You must select at least one 'Group By' field and one 'Value' field.";
    } else {
        $select_clause = implode(', ', $select_cols);
        $group_by_clause = "GROUP BY " . implode(', ', $group_by_fields);
        
        // --- 3. Build ORDER BY clause ---
        $order_by_clause = "";
        // Check if sort_by is one of the selected group_by fields
        if (in_array($sort_by, $group_by_fields)) {
            $order_by_clause = "ORDER BY $sort_by $sort_order";
        } 
        // Check if sort_by is one of the selected value fields
        else if (in_array($sort_by, $value_fields)) {
            // Find the alias
            $alias_to_sort = array_search($sort_by, $value_aliases);
            if ($alias_to_sort) {
                $order_by_clause = "ORDER BY $alias_to_sort $sort_order";
            }
        }
        
        // Fallback if sort column is invalid
        if (empty($order_by_clause)) {
            $order_by_clause = "ORDER BY " . reset($select_cols) . " $sort_order";
        }
        
        // --- 4. Assemble Final Query ---
        $sql_report = "SELECT $select_clause FROM sales_reports $where_clause $group_by_clause $order_by_clause LIMIT 1000";
                        
        if($stmt = $conn->prepare($sql_report)){
            $stmt->bind_param($types, ...$params);
            if($stmt->execute()){
                $result = $stmt->get_result();
                while($row = $result->fetch_assoc()){ 
                    $report_results[] = $row; 
                }
                if (empty($report_results)) {
                    $error_message = "No data found for the selected criteria.";
                }
            } else { $error_message .= "Report Generation Error: ". $stmt->error . "<br>"; }
            $stmt->close();
        } else { $error_message .= "DB Prepare Error (Report): ". $conn->error . "<br>"; }
    }
}

?>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .report-card-body {
        max-height: 600px;
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
    
    /* Make builder options scrollable */
    .builder-col { max-height: 250px; overflow-y: auto; border: 1px solid #eee; padding: 10px; border-radius: 5px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Sales Report Builder</h2>
</div>

<?php if ($error_message && !isset($_GET['generate_report'])): // Show config errors only if not running report ?>
    <div class="alert alert-danger"><?php echo $error_message; ?></div>
<?php endif; ?>

<form action="admin/sales_report_builder.php" method="get" class="card mb-4 no-print shadow-sm">
    <input type="hidden" name="generate_report" value="1">
    
    <div class="card-header bg-light">
        <h5 class="mb-0"><i data-lucide="filter" class="me-2 text-primary"></i>1. Filters</h5>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
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
            <div class="col-md-4">
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
            <div class="col-md-4">
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
        </div>
    </div>
    
    <div class="card-header bg-light border-top">
        <h5 class="mb-0"><i data-lucide="layout-grid" class="me-2 text-primary"></i>2. Report Layout</h5>
    </div>
    <div class="card-body">
         <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label fw-bold">Group By (Rows)</label>
                <div class="builder-col">
                    <?php foreach($available_group_by_fields as $field => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="group_by[]" value="<?php echo $field; ?>" id="gb_<?php echo $field; ?>"
                               <?php echo in_array($field, $group_by_fields) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="gb_<?php echo $field; ?>"><?php echo $label; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-5">
                <label class="form-label fw-bold">Show Values (Columns)</label>
                 <div class="builder-col">
                    <?php foreach($available_value_fields as $field => $label): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="values[]" value="<?php echo $field; ?>" id="val_<?php echo md5($field); ?>"
                               <?php echo in_array($field, $value_fields) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="val_<?php echo md5($field); ?>"><?php echo $label; ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="col-md-2">
                <label for="sort_by" class="form-label fw-bold">Sort By</label>
                <select id="sort_by" name="sort_by" class="form-select mb-2">
                    <optgroup label="Group By Fields">
                        <?php foreach($available_group_by_fields as $field => $label): ?>
                        <option value="<?php echo $field; ?>" <?php echo ($sort_by == $field) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                     <optgroup label="Value Fields">
                        <?php foreach($available_value_fields as $field => $label): ?>
                        <option value="<?php echo $field; ?>" <?php echo ($sort_by == $field) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
                
                <label for="sort_order" class="form-label fw-bold">Order</label>
                <select id="sort_order" name="sort_order" class="form-select">
                    <option value="DESC" <?php echo ($sort_order == 'DESC') ? 'selected' : ''; ?>>Descending</option>
                    <option value="ASC" <?php echo ($sort_order == 'ASC') ? 'selected' : ''; ?>>Ascending</option>
                </select>
            </div>
        </div>
    </div>
    
    <div class="card-footer">
        <button type="submit" class="btn btn-primary w-100">
            <i data-lucide="bar-chart-3" class="me-2"></i>Generate Report
        </button>
    </div>
</form>

<?php if (isset($_GET['generate_report']) && !empty($report_results)): ?>

<div class="card mb-4" id="report-custom">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i data-lucide="table" class="me-2 text-muted"></i>Custom Report Results</h5>
        <button class="btn btn-outline-success btn-sm no-print" onclick="exportTableToCSV('table-custom', 'custom_report.csv')">
            <i data-lucide="download" class="me-1" style="width:16px;"></i> Export
        </button>
    </div>
    <div class="card-body report-card-body">
        <?php if ($error_message): ?>
            <div class="alert alert-danger"><?php echo $error_message; ?></div>
        <?php else: ?>
        <table class="table table-striped table-hover table-sm" id="table-custom">
            <thead class="table-light sticky-top">
                <tr>
                    <?php foreach($column_headers as $header): ?>
                        <th><?php echo htmlspecialchars($header); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report_results as $row): ?>
                    <tr>
                        <?php foreach ($row as $key => $value): ?>
                            <?php
                            // Check if the value is a number for formatting
                            if (is_numeric($value)) {
                                $label = $column_headers[array_search($key, array_keys($row))] ?? '';
                                // Format based on column name
                                if (str_contains($label, '(L)')) {
                                    $formatted_value = formatInLakhs($value);
                                } else if (str_contains($label, '%') || str_contains($label, 'UPT')) {
                                    $formatted_value = number_format($value, 2);
                                } else if (is_float($value) || str_contains($label, 'ATV') || str_contains($label, 'ASP')) {
                                    $formatted_value = number_format($value, 0);
                                } else {
                                    $formatted_value = number_format($value, 0);
                                }
                                echo "<td class='text-end'>$formatted_value</td>";
                            } else {
                                // Text values
                                echo "<td>" . htmlspecialchars($value) . "</td>";
                            }
                            ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php elseif (isset($_GET['generate_report']) && $error_message): ?>
    <div class="alert alert-warning"><?php echo $error_message; ?></div>
<?php endif; ?>


<script>
// --- Export to CSV Function ---
function exportTableToCSV(tableId, filename) {
    var csv = [];
    var rows = document.querySelectorAll("#" + tableId + " tr");
    
    for (var i = 0; i < rows.length; i++) {
        var row = [], cols = rows[i].querySelectorAll("td, th");
        
        for (var j = 0; j < cols.length; j++) {
            var data = cols[j].innerText.replace(/(\r\n|\n|\r)/gm, " ")
                                        .replace(/\s\s+/g, ' ')
                                        .replace(/,/g, '') // Remove commas from numbers
                                        .replace(/%/g, '') // Remove percent signs
                                        .replace(/\(L\)/g, '') // Remove (L)
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

// --- Filter Logic ---
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