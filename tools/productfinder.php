<?php
// Use the new Tools header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$search_results = [];

// --- Filter Values from URL ---
$f_category = $_GET['category'] ?? 'all';
$f_metal = $_GET['metal'] ?? 'all';
$f_size = $_GET['size'] ?? 'all';
$f_location = $_GET['location'] ?? 'all';
$f_price_min = $_GET['price_min'] ?? '';
$f_price_max = $_GET['price_max'] ?? '';

// --- DATA FETCHING (Dynamic Filters) ---
// We need to run separate queries to get the unique, non-empty values for the dropdowns.
$filter_options = [
    'categories' => [],
    'metals' => [],
    'sizes' => [],
    'locations' => []
];

// Get Categories
$cat_result = $conn->query("SELECT DISTINCT Category FROM stock_data WHERE company_id = $company_id_context AND Category IS NOT NULL AND Category != '' ORDER BY Category");
if ($cat_result) while ($row = $cat_result->fetch_assoc()) $filter_options['categories'][] = $row['Category'];

// Get Metals
$metal_result = $conn->query("SELECT DISTINCT BaseMetal FROM stock_data WHERE company_id = $company_id_context AND BaseMetal IS NOT NULL AND BaseMetal != '' ORDER BY BaseMetal");
if ($metal_result) while ($row = $metal_result->fetch_assoc()) $filter_options['metals'][] = $row['BaseMetal'];

// Get Sizes
$size_result = $conn->query("SELECT DISTINCT ItemSize FROM stock_data WHERE company_id = $company_id_context AND ItemSize IS NOT NULL AND ItemSize != '' ORDER BY ItemSize");
if ($size_result) while ($row = $size_result->fetch_assoc()) $filter_options['sizes'][] = $row['ItemSize'];

// Get Locations
$loc_result = $conn->query("SELECT DISTINCT LocationName FROM stock_data WHERE company_id = $company_id_context AND LocationName IS NOT NULL AND LocationName != '' ORDER BY LocationName");
if ($loc_result) while ($row = $loc_result->fetch_assoc()) $filter_options['locations'][] = $row['LocationName'];


// --- MAIN SEARCH QUERY ---
// Only run the search if a filter has been applied
if (isset($_GET['filter'])) {
    $sql = "SELECT * FROM stock_data WHERE company_id = ?";
    $params = [$company_id_context];
    $types = "i";

    if ($f_category != 'all') { $sql .= " AND Category = ?"; $params[] = $f_category; $types .= "s"; }
    if ($f_metal != 'all') { $sql .= " AND BaseMetal = ?"; $params[] = $f_metal; $types .= "s"; }
    if ($f_size != 'all') { $sql .= " AND ItemSize = ?"; $params[] = $f_size; $types .= "s"; }
    if ($f_location != 'all') { $sql .= " AND LocationName = ?"; $params[] = $f_location; $types .= "s"; }

    // --- Handle Price (SalePrice is stored as "33,154" - a VARCHAR) ---
    // We must remove commas and cast to a number to filter.
    if (!empty($f_price_min)) {
        $sql .= " AND CAST(REPLACE(SalePrice, ',', '') AS DECIMAL(10,2)) >= ?";
        $params[] = (float)$f_price_min; $types .= "d";
    }
    if (!empty($f_price_max)) {
        $sql .= " AND CAST(REPLACE(SalePrice, ',', '') AS DECIMAL(10,2)) <= ?";
        $params[] = (float)$f_price_max; $types .= "d";
    }

    $sql .= " LIMIT 200"; // Safety limit

    if ($stmt = $conn->prepare($sql)) {
        // Handle dynamic parameter binding
        $bind_params = array();
        $bind_params[] = $types;
        for ($i = 0; $i < count($params); $i++) {
             // We need to use references for call_user_func_array, hence the &
             $bind_params[] = &$params[$i]; 
        }
        call_user_func_array(array($stmt, 'bind_param'), $bind_params);

        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $search_results[] = $row;
            }
        } else { $error_message = "Error executing search: " . $stmt->error; }
        $stmt->close();
    } else { $error_message = "Database prepare error: " . $conn->error; }
}

// Map database fields to display labels (from your original file)
$display_keys = [
    "JewelCode" => "Jewel Code", "LocationName" => "Location", "Category" => "Category",
    "StyleCode" => "Style Code", "ItemSize" => "Size", "BaseMetal" => "Metal",
    "JewelryCertificateNo" => "Certificate #", "Qty" => "Quantity", "GrossWt" => "Gross Wt.",
    "NetWt" => "Net Wt.", "DiaPcs" => "Dia Pcs", "DiaWt" => "Dia Wt.",
    "CsPcs" => "CS Pcs", "CsWt" => "CS Wt.", "PureWt" => "Pure Wt.",
    "SalePrice" => "Sale Price (MRP)"
];
?>

<!-- Custom styles from your original productfinder.html to preserve the look -->
<style>
    :root {
        --cream-bg: #F4EFEA;
        --dark-text: #0D0D0D;
        --sherpa-blue: #004E54;
        --light-border: #D0CEC6;
    }
    body {
        background-color: var(--cream-bg) !important;
        font-family: 'Inter', sans-serif;
        color: var(--dark-text);
    }
    .font-playfair { font-family: 'Playfair Display', serif; }
    .card {
        border: 1px solid var(--light-border);
        border-radius: 0.5rem;
    }
    .form-control, .form-select {
        border-color: var(--light-border);
    }
    .btn-primary {
        background-color: var(--sherpa-blue) !important;
        border-color: var(--sherpa-blue) !important;
    }
    /* Aspect ratio container for images */
    .image-container {
        position: relative;
        width: 100%;
        padding-top: 100%; /* Creates a 1:1 aspect ratio container */
    }
    .image-container img, .image-container .fallback {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="font-playfair" style="color: var(--sherpa-blue);">Product Finder</h2>
        <p class="text-muted">Filter your inventory to find the perfect item.</p>
    </div>
    <div class="text-end">
        <h5 id="clock" class="font-monospace"></h5>
        <p id="date" class="text-muted mb-0"></p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form action="tools/productfinder.php" method="get">
            <!-- Hidden input to trigger search on page load -->
            <input type="hidden" name="filter" value="1">
            <div class="row g-2">
                <div class="col-md-3">
                    <select id="location-select" name="location" class="form-select">
                        <option value="all">All Locations</option>
                        <?php foreach($filter_options['locations'] as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php if($f_location == $opt) echo 'selected'; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select id="category-select" name="category" class="form-select">
                        <option value="all">All Categories</option>
                        <?php foreach($filter_options['categories'] as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php if($f_category == $opt) echo 'selected'; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="metal-select" name="metal" class="form-select">
                        <option value="all">All Metals</option>
                        <?php foreach($filter_options['metals'] as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php if($f_metal == $opt) echo 'selected'; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select id="size-select" name="size" class="form-select">
                        <option value="all">All Sizes</option>
                        <?php foreach($filter_options['sizes'] as $opt): ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php if($f_size == $opt) echo 'selected'; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <input type="number" id="price-min" name="price_min" class="form-control" placeholder="Min" value="<?php echo htmlspecialchars($f_price_min); ?>">
                </div>
                <div class="col-md-1">
                    <input type="number" id="price-max" name="price_max" class="form-control" placeholder="Max" value="<?php echo htmlspecialchars($f_price_max); ?>">
                </div>
            </div>
             <div class="row g-2 mt-2">
                <div class="col-12">
                     <button type="submit" class="btn btn-primary w-100"><i data-lucide="search" class="me-2"></i>Filter Results</button>
                </div>
             </div>
        </form>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<!-- Results -->
<div class="card shadow-sm">
    <div class="card-header bg-white">
        <h4 class="mb-0">
            Results <span class="badge bg-secondary ms-2"><?php echo count($search_results); ?> Item<?php if(count($search_results) != 1) echo 's'; ?> Found</span>
        </h4>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <?php if (isset($_GET['filter']) && empty($search_results) && empty($error_message)): ?>
                <div class="col-12">
                    <p class="text-center text-muted fs-5 py-5">No items match your filter criteria.</p>
                </div>
            <?php elseif (!isset($_GET['filter'])): ?>
                 <div class="col-12">
                    <p class="text-center text-muted fs-5 py-5">Use the filters above to search your inventory.</p>
                </div>
            <?php else: ?>
                <?php foreach($search_results as $item): ?>
                    <?php
                    // --- Image URL Logic ---
                    $image_url = null;
                    if (!empty($item['StyleCode'])) {
                        $styleCode = $item['StyleCode'];
                        $grpPrefix = substr($styleCode, 0, 3);
                        // *** CORRECTED IMAGE URL CONSTRUCTION (matching provided S3 path) ***
                        $image_url = "https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{$grpPrefix}/{$styleCode}.jpg";
                    }
                    // --- End Image URL Logic ---
                    ?>
                    <div class="col-lg-6">
                        <div class="card h-100">
                            <div class="row g-0">
                                <div class="col-md-5">
                                    <div class="image-container">
                                        <?php if ($image_url): ?>
                                        <img src="<?php echo $image_url; ?>" 
                                             class="img-fluid rounded-start w-100" 
                                             alt="<?php echo $item['StyleCode']; ?>"
                                             onerror="this.src='https://placehold.co/400x400/EEEEEE/AAAAAA?text=Image+URL+Error'; this.onerror=null;">
                                        <?php else: ?>
                                         <div class="fallback text-muted p-4 d-flex flex-column justify-content-center align-items-center bg-gray-100 rounded-l-md">
                                            <i data-lucide="image-off" style="width: 32px; height: 32px;"></i>
                                            <p class="mt-2 text-sm text-center"><?php echo empty($item['StyleCode']) ? 'No Style Code recorded.' : 'Image URL error.'; ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="col-md-7">
                                    <div class="card-body p-3">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h5 class="card-title mb-0"><?php echo htmlspecialchars($item['JewelCode']); ?></h5>
                                            <span class="badge bg-success"><?php echo htmlspecialchars($item['LocationName']); ?></span>
                                        </div>
                                        <div class="font-monospace small">
                                        <?php foreach($display_keys as $db_key => $label): ?>
                                            <?php if (isset($item[$db_key]) && $item[$db_key] !== '' && !in_array($db_key, ['JewelCode', 'LocationName'])): ?>
                                            <div class="d-flex justify-content-between border-bottom py-1">
                                                <span class="text-muted"><?php echo $label; ?>:</span>
                                                <span class="fw-bold"><?php echo htmlspecialchars($item[$db_key]); ?></span>
                                            </div>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>


<script>
    // Clock function from your original file
    function updateClock() {
        const now = new Date();
        const clockEl = document.getElementById('clock');
        const dateEl = document.getElementById('date');
        if (clockEl) clockEl.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if (dateEl) dateEl.textContent = now.toLocaleDateString([], { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }
    setInterval(updateClock, 1000);
    document.addEventListener('DOMContentLoaded', () => {
        updateClock();
         // Render icons
         lucide.createIcons();
    });
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>

