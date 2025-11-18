<?php
// Use the new Tools header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// *** START: Fetch User's Location (Store Name) ***
$user_location_name = 'N/A'; // Default
if (isset($_SESSION['user_id'])) {
    // This query links users.id -> users.store_id -> stores.id -> stores.store_name
    // This assumes 'stores.store_name' EXACTLY matches 'stock_data.LocationName'
    $sql_user_store = "SELECT s.store_name 
                       FROM users u 
                       JOIN stores s ON u.store_id = s.id 
                       WHERE u.id = ?";
    if($stmt_user_store = $conn->prepare($sql_user_store)) {
        $stmt_user_store->bind_param("i", $_SESSION['user_id']);
        if($stmt_user_store->execute()) {
            $user_store_result = $stmt_user_store->get_result()->fetch_assoc();
            if($user_store_result && !empty($user_store_result['store_name'])) {
                $user_location_name = $user_store_result['store_name'];
            }
        }
        $stmt_user_store->close();
    } else {
        error_log("StockLookup: Failed to prepare user store query: " . $conn->error);
    }
}
// *** END: Fetch User's Location ***


$search_code = $_GET['code'] ?? '';
$result_item = null;
$error_message = '';
$image_url = null;

// Map database fields to display labels
$display_keys = [
    "JewelCode" => "Jewel Code",
    "LocationName" => "Location",
    "Category" => "Category",
    "StyleCode" => "Style Code",
    "ItemSize" => "Size",
    "BaseMetal" => "Metal",
    "JewelryCertificateNo" => "Certificate #",
    "Qty" => "Quantity",
    "GrossWt" => "Gross Wt.",
    "NetWt" => "Net Wt.",
    "DiaPcs" => "Dia Pcs",
    "DiaWt" => "Dia Wt.",
    "CsPcs" => "CS Pcs",
    "CsWt" => "CS Wt.",
    "PureWt" => "Pure Wt.",
    "SalePrice" => "Sale Price (MRP)"
];

// --- DATA FETCHING ---
if (!empty($search_code)) {
    // UPDATED SQL: Search by JewelCode OR StyleCode
    $sql = "SELECT * FROM stock_data WHERE (JewelCode = ? OR StyleCode = ?) AND company_id = ?";
    if ($stmt = $conn->prepare($sql)) {
        // Pass the search code for both parameters
        $stmt->bind_param("ssi", $search_code, $search_code, $company_id_context);
        
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            $result_item = $result->fetch_assoc();
            if (!$result_item) {
                $error_message = "Code not found (searched JewelCode and StyleCode).";
            } else {
                // Image URL logic: Check for StyleCode and build URL
                if (!empty($result_item['StyleCode'])) {
                    $styleCode = $result_item['StyleCode'];
                    // Construct the group prefix (first 3 characters of style code)
                    $grpPrefix = substr($styleCode, 0, 3); 
                    
                    // *** CORRECTED IMAGE URL CONSTRUCTION (matching provided S3 path) ***
                    $image_url = "https://fireflylgd-assets.s3.eu-north-1.amazonaws.com/public/shopify/compressed-images/{$grpPrefix}/{$styleCode}.jpg";
                } else {
                    $image_url = null; // Forces placeholder in HTML
                }
            }
        } else { $error_message = "Error executing search: " . $stmt->error; }
        $stmt->close();
    } else { $error_message = "Database prepare error: " . $conn->error; }
}
?>

<!-- Custom styles from your original stocklookup.html to preserve the look -->
<style>
    :root {
        --cream-bg: #F4EFEA;
        --dark-text: #0D0D0D;
        --sherpa-blue: #004E54;
        --light-border: #D0CEC6;
    }
    /* Override main styles */
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
</style>

<!-- Header from original file -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h2 class="font-playfair" style="color: var(--sherpa-blue);">Stock Lookup</h2>
        <p class="text-muted">Search for an item by its Jewel Code or Style Code.</p>
    </div>
    <div class="text-end">
        <h5 id="clock" class="font-monospace"></h5>
        <p id="date" class="text-muted mb-0"></p>
    </div>
</div>

<!-- Search Bar -->
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form action="tools/stocklookup.php" method="get" class="d-flex gap-2">
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
    <div class="alert alert-warning">No item found for "<strong><?php echo htmlspecialchars($search_code); ?></strong>".</div>
<?php endif; ?>

<!-- Results -->
<?php if ($result_item): ?>
<div class="row g-4">
    <div class="col-md-8">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h4 class="mb-0">Details for: <span class="text-primary"><?php echo htmlspecialchars($result_item['JewelCode']); ?></span></h4>
                <span class="badge bg-success" style="font-size: 0.9rem;"><?php echo htmlspecialchars($result_item['LocationName']); ?></span>
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
        
        <!-- *** START: ADDED REQUEST BUTTON LOGIC *** -->
        <?php
        // Show request button ONLY if item is in stock AND at a DIFFERENT location
        $item_location = $result_item['LocationName'];
        $item_qty = (int)($result_item['Qty'] ?? 0);

        if ($user_location_name === 'N/A'):
        ?>
        <div class="alert alert-warning mt-4">
            Your user account is not linked to a store location, so you cannot request transfers. Please contact your administrator.
        </div>
        <?php elseif ($item_qty > 0 && $item_location != $user_location_name): ?>
        <div class="card shadow-sm mt-4">
            <div class="card-body text-center">
                <h5 class="card-title">Request Internal Transfer</h5>
                <p class="text-muted">Request this item from <strong><?php echo htmlspecialchars($item_location); ?></strong>.</p>
                <form action="stock_transfer/request_handler.php" method="POST">
                    <input type="hidden" name="action" value="create_request">
                    <input type="hidden" name="jewel_code" value="<?php echo htmlspecialchars($result_item['JewelCode']); ?>">
                    <input type="hidden" name="style_code" value="<?php echo htmlspecialchars($result_item['StyleCode'] ?? ''); ?>">
                    <input type="hidden" name="sender_location_name" value="<?php echo htmlspecialchars($item_location); ?>">
                    <button type="submit" class="btn btn-warning btn-lg w-100">
                        <i data-lucide="send" class="me-2"></i>Send Request
                    </button>
                </form>
            </div>
        </div>
        <?php elseif ($item_qty <= 0): ?>
        <div class="alert alert-secondary mt-4">
            Item is out of stock (Qty: 0). Cannot request transfer.
        </div>
        <?php elseif ($item_location == $user_location_name): ?>
        <div class="alert alert-info mt-4">
            This item is already at your location.
        </div>
        <?php endif; ?>
        <!-- *** END: ADDED REQUEST BUTTON LOGIC *** -->

    </div>
    <div class="col-md-4">
        <div class="card shadow-sm sticky-top" style="top: 20px;">
            <div class="card-header bg-white">
                <h4 class="mb-0">Product Image</h4>
            </div>
            <div class="card-body text-center">
                <?php if ($image_url): ?>
                    <img id="product-image" src="<?php echo $image_url; ?>"
                         alt="Product image for <?php echo htmlspecialchars($result_item['StyleCode'] ?? 'Item'); ?>"
                         class="img-fluid rounded"
                         onerror="this.src='https://placehold.co/400x400/EEEEEE/AAAAAA?text=Image+Not+Found'; this.onerror=null;">
                <?php else: ?>
                    <div class="text-muted p-4">
                        <i data-lucide="image-off" class="mb-2" style="width: 48px; height: 48px;"></i>
                        <p><?php echo empty($result_item['StyleCode']) ? 'No Style Code recorded.' : 'Image not found at URL.'; ?></p>
                    </div>
                <?php endif; ?>
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
         // Render icons
         lucide.createIcons();
    });

     // 'Enter' key listener
    const searchInput = document.getElementById('search-input');
    if(searchInput) {
        searchInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                // The form will submit on its own
                e.target.form.submit();
            }
        });
    }
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; // Corrected Path
?>


