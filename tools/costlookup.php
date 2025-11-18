<?php
// Use the new Tools header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$search_code = $_GET['code'] ?? '';
$cost_item = null;
$stock_item = null;
$result_item = null;
$error_message = '';
$image_url = null;
// Removed gold_rate, calculated_cost, cost_price, net_wt variables

// --- DATA FETCHING (No Gold Rate Check) ---

// 2. If a search code is provided, fetch all data
if (!empty($search_code) && empty($error_message)) {

    // --- LOGIC: FIND JEWEL CODE USING EITHER JEWELCODE OR STYLECODE ---
    // 1. Check stock data first to confirm the primary code used for both lookups
    $target_jewel_code = null;
    $sql_find_code = "SELECT JewelCode FROM stock_data WHERE (JewelCode = ? OR StyleCode = ?) AND company_id = ?";
    if($stmt_find = $conn->prepare($sql_find_code)){
        $stmt_find->bind_param("ssi", $search_code, $search_code, $company_id_context);
        $stmt_find->execute();
        $target_jewel_code = $stmt_find->get_result()->fetch_assoc()['JewelCode'] ?? null;
        $stmt_find->close();
    } else {
        $error_message = "Search initialization error: " . $conn->error;
    }
    
    if (!$target_jewel_code) {
        $error_message = "Jewel Code or Style Code not found in stock data.";
    } else {
        $search_code_key = $target_jewel_code; // Use the confirmed JewelCode for both lookups

        // 2a. Fetch from cost_data (using the confirmed JewelCode)
        $sql_cost = "SELECT * FROM cost_data WHERE JewelCode = ? AND company_id = ?";
        if ($stmt_cost = $conn->prepare($sql_cost)) {
            $stmt_cost->bind_param("si", $search_code_key, $company_id_context);
            $stmt_cost->execute();
            $cost_item = $stmt_cost->get_result()->fetch_assoc();
            $stmt_cost->close();
        } else { $error_message = "Cost data search error: " . $conn->error; }

        // 2b. Fetch from stock_data (for NetWt and StyleCode - using the confirmed JewelCode)
        $sql_stock = "SELECT NetWt, StyleCode FROM stock_data WHERE JewelCode = ? AND company_id = ?";
        if ($stmt_stock = $conn->prepare($sql_stock)) {
            $stmt_stock->bind_param("si", $search_code_key, $company_id_context);
            $stmt_stock->execute();
            $stock_item = $stmt_stock->get_result()->fetch_assoc();
            $stmt_stock->close();
        } else { $error_message = "Stock data search error: " . $conn->error; }

        // 3. Process and Final Result
        if ($cost_item && $stock_item) {
            $result_item = array_merge($cost_item, $stock_item); // Combine data

            // Get image URL - UPDATED TO NEW S3 PATH
            if (!empty($result_item['StyleCode'])) {
                $styleCode = $result_item['StyleCode'];
                $grpPrefix = substr($styleCode, 0, 3);
                 // *** CORRECTED IMAGE URL CONSTRUCTION (matching provided S3 path) ***
                $image_url = "https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{$grpPrefix}/{$styleCode}.jpg";
            }
        } elseif (empty($error_message)) {
            // Only set this error if no other DB error occurred
            $error_message = "Jewel Code not found in one or both data tables (Cost or Stock).";
        }
    }
}

// Map database fields to display labels
$display_keys = [
    "JewelCode" => "Jewel Code",
    "Style" => "Style",
    "Category" => "Category",
    "GrossWt" => "Gross Wt.",
    "TotDiaPc" => "Dia Pcs",
    "TotDiaWt" => "Dia Wt.",
    "CostPrice" => "Cost Price", // This is the component cost
    "SalePrice" => "Sale Price (MRP)"
];

?>

<!-- Custom styles from your original costlookup.html to preserve the look -->
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
    .form-control {
        border-color: var(--light-border);
    }
    .btn-primary {
        background-color: var(--sherpa-blue) !important;
        border-color: var(--sherpa-blue) !important;
    }
    /* Removed .calc-box related CSS */
</style>

<!-- Header from original file -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="font-playfair" style="color: var(--sherpa-blue);">Cost Lookup</h2>
        <p class="text-muted">Search for an item's data in the Cost and Stock tables.</p>
    </div>
    <div class="text-end">
        <h5 id="clock" class="font-monospace"></h5>
        <p id="date" class="text-muted mb-0"></p>
    </div>
</div>

<!-- Search Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form action="tools/costlookup.php" method="get" class="d-flex gap-2">
            <input type="text" name="code" id="search-input" class="form-control form-control-lg"
                   value="<?php echo htmlspecialchars($search_code); ?>"
                   placeholder="Enter Jewel Code or Style Code" required>
            <button type="submit" class="btn btn-primary btn-lg d-flex align-items-center">
                <i data-lucide="search" class="me-2"></i> Search
            </button>
        </form>
    </div>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php endif; ?>

<?php if (!$result_item && !empty($search_code) && empty($error_message)): ?>
    <div class="alert alert-warning">Code "<strong><?php echo htmlspecialchars($search_code); ?></strong>" not found in cost or stock data.</div>
<?php endif; ?>

<!-- Results -->
<?php if ($result_item): // Display results if found ?>
<div class="row g-4">
    <!-- Left Column: Details -->
    <div class="col-md-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Details for: <span class="text-primary"><?php echo htmlspecialchars($result_item['JewelCode']); ?></span></h4>
            </div>
            <div class="card-body">
                <div class="p-3">
                    <?php foreach($display_keys as $db_key => $label): ?>
                        <?php if (isset($result_item[$db_key]) && $result_item[$db_key] !== ''): ?>
                        <div class="d-flex justify-content-between border-bottom py-2">
                            <span class="fw-medium text-muted"><?php echo $label; ?>:</span>
                            <span class="fw-bold"><?php echo htmlspecialchars($result_item[$db_key]); ?></span>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Column: Image Display (No Calculation) -->
    <div class="col-md-5">
        <div class="card shadow-sm sticky-top" style="top: 20px;">
            <div class="card-header bg-white">
                <h4 class="mb-0">Product Image</h4>
            </div>
            <div class="card-body">
                <!-- Image Display -->
                <div class="mt-3 text-center">
                    <?php if (!empty($result_item['StyleCode'])): 
                        $styleCode = $result_item['StyleCode'];
                        $grpPrefix = substr($styleCode, 0, 3);
                        $image_url_correct = "https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{$grpPrefix}/{$styleCode}.jpg";
                    ?>
                        <img id="product-image" src="<?php echo $image_url_correct; ?>"
                             alt="Product image for <?php echo htmlspecialchars($result_item['StyleCode']); ?>"
                             class="img-fluid rounded mt-2"
                             onerror="this.src='https://placehold.co/400x400/EEEEEE/AAAAAA?text=Image+Not+Found'; this.onerror=null;">
                    <?php else: ?>
                        <div class="text-muted p-4">
                            <i data-lucide="image-off" class="mb-2" style="width: 48px; height: 48px;"></i>
                            <p><?php echo empty($result_item['StyleCode']) ? 'No Style Code found for image.' : 'Image not found.'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


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
        // Set focus on the search input
        const searchInput = document.getElementById('search-input');
        if (searchInput) searchInput.focus();
    });

    // 'Enter' key listener
    const searchInput = document.getElementById('search-input');
    if(searchInput) {
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                // The form will submit on its own, but we can also trigger it
                e.target.form.submit();
            }
        });
    }
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; // Corrected Path
?>

