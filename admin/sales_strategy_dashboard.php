<?php
// admin/sales_strategy_dashboard.php

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
$error_message = '';
$base_data = null; // This will hold our single row of summary data

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


    // --- Base Filters ---
    $where_conditions = []; 
    $where_conditions[] = "TransactionDate BETWEEN ? AND ?";
    // We only care about sales transactions for this dashboard
    $where_conditions[] = "EntryType = 'FF'"; 
    $params = [$start_date, $end_date];
    $types = "ss";

    // --- ***** REVISED: Call build_in_clause with total counts ***** ---
    build_in_clause('StoreCode', $f_stores, $total_stores, $where_conditions, $params, $types);
    build_in_clause('ProductCategory', $f_categories, $total_categories, $where_conditions, $params, $types);
    build_in_clause('Stocktype', $f_stocktypes, $total_stocktypes, $where_conditions, $params, $types);
    build_in_clause('BaseMetal', $f_basemetals, $total_basemetals, $where_conditions, $params, $types);
    
    $where_clause = " WHERE " . implode(' AND ', $where_conditions);
    
    // --- ***** THE MAIN (AND ONLY) QUERY ***** ---
    // This query gets the total summary for the filtered period.
    $sql_summary = "SELECT
                        SUM(NetSales) as TotalNetSales,
                        COUNT(*) as TotalNetUnits,
                        COUNT(DISTINCT TransactionNo) as TotalTransactions,
                        SUM(OriginalSellingPrice) as TotalOriginalPrice,
                        SUM(DiscountAmount) as TotalDiscountAmount
                    FROM sales_reports
                    $where_clause";
                    
    if($stmt = $conn->prepare($sql_summary)){
        $stmt->bind_param($types, ...$params);
        if($stmt->execute()){
            $base_data = $stmt->get_result()->fetch_assoc();
            // Check if we got data
            if(is_null($base_data['TotalNetSales'])) { // Check if query returned NULL (no rows found)
                $error_message = "No sales data found for the selected filters. Please expand your criteria.";
                $base_data = null; // Unset data to prevent dashboard from loading
            }
        } else { $error_message .= "Summary Report Error: ". $stmt->error . "<br>"; }
        $stmt->close();
    } else { $error_message .= "DB Prepare Error (Summary): ". $conn->error . "<br>"; }
}

?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.7.1/dist/chart.min.js"></script>
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .filter-checkbox-list {
        max-height: 150px;
        overflow-y: auto;
        border: 1px solid #ced4da;
        padding: 10px;
        border-radius: 0.25rem;
        background-color: #fff;
    }
    .kpi-card {
        border: 1px solid #e0e0e0;
        border-radius: 0.5rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }
    .kpi-card .card-header {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    .kpi-title {
        font-size: 0.9rem;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
    }
    .kpi-value {
        font-size: 1.75rem;
        font-weight: 700;
        color: #212529;
    }
    .kpi-value-projected {
        font-size: 1.75rem;
        font-weight: 700;
        color: #0d6efd; /* Blue for projected */
    }
    .slider-container label {
        font-weight: 600;
    }
    .slider-value {
        font-weight: 700;
        font-size: 1.25rem;
        color: #0d6efd;
    }
    .gauge-container {
        position: relative;
        height: 150px;
    }
    .gauge-label {
        position: absolute;
        bottom: 10px;
        left: 50%;
        transform: translateX(-50%);
        font-size: 1.5rem;
        font-weight: 700;
    }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Strategic Planning Dashboard</h2>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>

<div class="card mb-4 no-print shadow-sm">
    <div class="card-header">
        <h5 class="mb-0"><i data-lucide="filter" class="me-2"></i>Dashboard Filters</h5>
    </div>
    <div class="card-body">
        <form action="admin/sales_strategy_dashboard.php" method="get" class="row g-3 align-items-end">
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
            
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary w-100">
                    <i data-lucide="bar-chart-3" class="me-2"></i>Generate Dashboard
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($base_data): ?>

    <div class="card kpi-card mb-4">
        <div class="card-header">
            <i data-lucide="check-circle" class="me-2 text-success"></i>Base Performance (Filtered)
        </div>
        <div class="card-body">
            <div class="row text-center">
                <div class="col">
                    <span class="kpi-title">Base Net Sales</span>
                    <h3 class="kpi-value" id="base-sales">...</h3>
                </div>
                <div class="col">
                    <span class="kpi-title">Base Units</span>
                    <h3 class="kpi-value" id="base-units">...</h3>
                </div>
                <div class="col">
                    <span class="kpi-title">Base ATV</span>
                    <h3 class="kpi-value" id="base-atv">...</h3>
                </div>
                <div class="col">
                    <span class="kpi-title">Base ASP</span>
                    <h3 class="kpi-value" id="base-asp">...</h3>
                </div>
                <div class="col">
                    <span class="kpi-title">Base Discount %</span>
                    <h3 class="kpi-value" id="base-discount-pct">...</h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-5">
            <div class="card kpi-card mb-4">
                <div class="card-header">
                    <i data-lucide="target" class="me-2 text-danger"></i>Target Setting
                </div>
                <div class="card-body">
                    <div class="slider-container mb-4">
                        <label for="target-sales-slider" class="form-label">Sales Target (L)</label>
                        <input type="range" class="form-range" id="target-sales-slider" min="0" max="1000" value="150" step="10">
                        <div class="text-center mt-2">
                            <span class="slider-value" id="target-sales-value">150 L</span>
                        </div>
                    </div>
                    <div class="slider-container">
                        <label for="target-atv-slider" class="form-label">ATV Target (₹)</label>
                        <input type="range" class="form-range" id="target-atv-slider" min="5000" max="50000" value="15000" step="500">
                        <div class="text-center mt-2">
                            <span class="slider-value" id="target-atv-value">₹15,000</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card kpi-card mb-4">
                <div class="card-header">
                    <i data-lucide="sliders" class="me-2 text-primary"></i>What-If Levers
                </div>
                <div class="card-body">
                    <div class="slider-container mb-4">
                        <label for="lever-units-slider" class="form-label">% Change in Units Sold</label>
                        <input type="range" class="form-range" id="lever-units-slider" min="-50" max="100" value="0" step="5">
                        <div class="text-center mt-2">
                            <span class="slider-value" id="lever-units-value">0%</span>
                        </div>
                    </div>
                    <div class="slider-container">
                        <label for="lever-discount-slider" class="form-label">Change in Avg. Discount %</label>
                        <input type="range" class="form-range" id="lever-discount-slider" min="-10" max="10" value="0" step="1">
                        <div class="text-center mt-2">
                            <span class="slider-value" id="lever-discount-value">0.0 pts</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card kpi-card mb-4">
                <div class="card-header">
                    <i data-lucide="trending-up" class="me-2 text-primary"></i>Projected Performance
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col">
                            <span class="kpi-title">Projected Sales</span>
                            <h3 class="kpi-value-projected" id="proj-sales">...</h3>
                        </div>
                        <div class="col">
                            <span class="kpi-title">Projected Units</span>
                            <h3 class="kpi-value-projected" id="proj-units">...</h3>
                        </div>
                        <div class="col">
                            <span class="kpi-title">Projected ATV</span>
                            <h3 class="kpi-value-projected" id="proj-atv">...</h3>
                        </div>
                        <div class="col">
                            <span class="kpi-title">Projected ASP</span>
                            <h3 class="kpi-value-projected" id="proj-asp">...</h3>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card kpi-card mb-4">
                <div class="card-header">
                    <i data-lucide="activity" class="me-2 text-warning"></i>Target vs. Projected
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 text-center">
                            <h5>Sales Target</h5>
                            <div class="gauge-container">
                                <canvas id="sales-gauge"></canvas>
                                <div class="gauge-label" id="sales-gauge-label">...</div>
                            </div>
                        </div>
                        <div class="col-md-6 text-center">
                            <h5>ATV Target</h5>
                            <div class="gauge-container">
                                <canvas id="atv-gauge"></canvas>
                                <div class="gauge-label" id="atv-gauge-label">...</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div> <script>
        // --- 1. Get Base Data from PHP ---
        const baseData = <?php echo json_encode($base_data); ?>;

        // --- 2. Chart & UI Element Globals ---
        let charts = { sales: null, atv: null };
        const sliders = {
            targetSales: document.getElementById('target-sales-slider'),
            targetATV: document.getElementById('target-atv-slider'),
            leverUnits: document.getElementById('lever-units-slider'),
            leverDiscount: document.getElementById('lever-discount-slider')
        };
        const labels = {
            targetSales: document.getElementById('target-sales-value'),
            targetATV: document.getElementById('target-atv-value'),
            leverUnits: document.getElementById('lever-units-value'),
            leverDiscount: document.getElementById('lever-discount-value'),
            salesGauge: document.getElementById('sales-gauge-label'),
            atvGauge: document.getElementById('atv-gauge-label')
        };
        const kpi = {
            baseSales: document.getElementById('base-sales'),
            baseUnits: document.getElementById('base-units'),
            baseATV: document.getElementById('base-atv'),
            baseASP: document.getElementById('base-asp'),
            baseDiscountPct: document.getElementById('base-discount-pct'),
            projSales: document.getElementById('proj-sales'),
            projUnits: document.getElementById('proj-units'),
            projATV: document.getElementById('proj-atv'),
            projASP: document.getElementById('proj-asp')
        };
        
        // --- 3. Formatters ---
        const currencyFormatter = new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 });
        const numberFormatter = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 0 });
        const percentFormatter = new Intl.NumberFormat('en-IN', { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 });

        // --- 4. Core "Base Data" Calculation (runs once) ---
        const baseMetrics = {
            sales: parseFloat(baseData.TotalNetSales || 0),
            units: parseInt(baseData.TotalNetUnits || 0),
            transactions: parseInt(baseData.TotalTransactions || 0),
            originalPrice: parseFloat(baseData.TotalOriginalPrice || 0),
            discountAmount: parseFloat(baseData.TotalDiscountAmount || 0)
        };
        
        baseMetrics.atv = (baseMetrics.transactions > 0) ? (baseMetrics.sales / baseMetrics.transactions) : 0;
        baseMetrics.asp = (baseMetrics.units > 0) ? (baseMetrics.sales / baseMetrics.units) : 0;
        baseMetrics.upt = (baseMetrics.transactions > 0) ? (baseMetrics.units / baseMetrics.transactions) : 0;
        baseMetrics.discountPct = (baseMetrics.originalPrice > 0) ? (baseMetrics.discountAmount / baseMetrics.originalPrice) : 0;
        baseMetrics.originalASP = (baseMetrics.units > 0) ? (baseMetrics.originalPrice / baseMetrics.units) : 0;

        // --- 5. Main Update Function (runs on every slider change) ---
        function updateDashboard() {
            // A. Get current values from all sliders
            const inputs = {
                targetSales: parseFloat(sliders.targetSales.value) * 100000, // Convert Lakhs
                targetATV: parseFloat(sliders.targetATV.value),
                unitChangePct: parseFloat(sliders.leverUnits.value) / 100,
                discountChangePts: parseFloat(sliders.leverDiscount.value) / 100
            };
            
            // B. Run the projection math
            const projection = {};
            projection.units = baseMetrics.units * (1 + inputs.unitChangePct);
            
            projection.discountPct = Math.max(0, Math.min(1, baseMetrics.discountPct + inputs.discountChangePts));
            
            projection.asp = baseMetrics.originalASP * (1 - projection.discountPct);
            
            projection.sales = projection.units * projection.asp;
            
            projection.transactions = (baseMetrics.upt > 0) ? (projection.units / baseMetrics.upt) : 0;
            
            projection.atv = (projection.transactions > 0) ? (projection.sales / projection.transactions) : 0;
            
            // C. Update Slider Labels
            labels.targetSales.textContent = `${(inputs.targetSales / 100000).toFixed(0)} L`;
            labels.targetATV.textContent = currencyFormatter.format(inputs.targetATV);
            labels.leverUnits.textContent = `${(inputs.unitChangePct * 100).toFixed(0)}%`;
            labels.leverDiscount.textContent = `${(inputs.discountChangePts * 100).toFixed(1)} pts`;

            // D. Update Projected KPI Card
            kpi.projSales.textContent = `${formatInLakhs(projection.sales)} L`;
            kpi.projUnits.textContent = numberFormatter.format(projection.units);
            kpi.projATV.textContent = currencyFormatter.format(projection.atv);
            kpi.projASP.textContent = currencyFormatter.format(projection.asp);
            
            // E. Update Gauge Labels
            const salesGaugePct = (inputs.targetSales > 0) ? (projection.sales / inputs.targetSales) * 100 : 0;
            const atvGaugePct = (inputs.targetATV > 0) ? (projection.atv / inputs.targetATV) * 100 : 0;
            labels.salesGauge.textContent = `${salesGaugePct.toFixed(0)}%`;
            labels.atvGauge.textContent = `${atvGaugePct.toFixed(0)}%`;
            
            // F. Update Gauge Charts
            updateGauge(charts.sales, projection.sales, inputs.targetSales);
            updateGauge(charts.atv, projection.atv, inputs.targetATV);
        }

        // --- 6. Chart Helper Functions ---
        
        /** Creates a doughnut gauge chart */
        function createGauge(ctx, label) {
            return new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: [label, 'Remaining'],
                    datasets: [{
                        data: [0, 100], // Start at 0%
                        backgroundColor: ['#0d6efd', '#e9ecef'],
                        borderColor: ['#0d6efd', '#e9ecef'],
                        borderWidth: 1,
                        circumference: 180, // Half circle
                        rotation: 270      // Start at the bottom
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { enabled: false } },
                    cutout: '70%' // Makes it a gauge
                }
            });
        }
        
        /** Updates a gauge chart's data */
        function updateGauge(chart, value, target) {
            if (!chart) return;
            
            let valuePct = 0;
            if (target > 0) {
                valuePct = Math.max(0, (value / target));
            }
            
            if (valuePct > 1) {
                chart.data.datasets[0].data = [1, 0]; // 100% filled
                chart.data.datasets[0].backgroundColor = ['#198754', '#e9ecef']; // Green
                chart.data.datasets[0].borderColor = ['#198754', '#e9ecef'];
            } else {
                chart.data.datasets[0].data = [valuePct, 1 - valuePct];
                chart.data.datasets[0].backgroundColor = ['#0d6efd', '#e9ecef']; // Blue
                chart.data.datasets[0].borderColor = ['#0d6efd', '#e9ecef'];
            }
            
            chart.update();
        }

        // --- 7. Initialization ---
        // ***** THIS IS THE MAIN FIX *****
        // This code is no longer in a 'DOMContentLoaded' wrapper.
        
        // A. Update the "Base Performance" card
        kpi.baseSales.textContent = `${formatInLakhs(baseMetrics.sales)} L`;
        kpi.baseUnits.textContent = numberFormatter.format(baseMetrics.units);
        kpi.baseATV.textContent = currencyFormatter.format(baseMetrics.atv);
        kpi.baseASP.textContent = currencyFormatter.format(baseMetrics.asp);
        kpi.baseDiscountPct.textContent = percentFormatter.format(baseMetrics.discountPct);
        
        // B. Dynamically set slider max values based on base data
        const baseSalesLakhs = baseMetrics.sales / 100000;
        sliders.targetSales.min = (baseSalesLakhs * 0.5).toFixed(0);
        sliders.targetSales.max = (baseSalesLakhs * 2.0).toFixed(0);
        sliders.targetSales.value = (baseSalesLakhs * 1.2).toFixed(0);
        
        sliders.targetATV.min = (baseMetrics.atv * 0.5).toFixed(0);
        sliders.targetATV.max = (baseMetrics.atv * 2.0).toFixed(0);
        sliders.targetATV.value = (baseMetrics.atv * 1.1).toFixed(0);
        
        // C. Create the gauges
        charts.sales = createGauge(document.getElementById('sales-gauge').getContext('2d'), 'Sales');
        charts.atv = createGauge(document.getElementById('atv-gauge').getContext('2d'), 'ATV');

        // D. Attach event listeners to all sliders
        for (const key in sliders) {
            sliders[key].addEventListener('input', updateDashboard);
        }
        
        // E. Run the calculations once to initialize the dashboard
        updateDashboard();
        
    </script>
<?php endif; ?>
<script>
// --- Filter Checkbox & Select2 Logic (runs on every page load) ---
$(document).ready(function() {
    function initSelect2(selector) {
        $(selector).select2({
            theme: "bootstrap-5",
            placeholder: "All (Default)", // Changed placeholder
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
            for (const item of items) { item.checked = selectAll.checked; }
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