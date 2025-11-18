<?php
/**
 * This is a diagnostic file to check user-to-store linking.
 * 1. Upload this file to your /stock_transfer/ directory.
 * 2. Access it in your browser (e.g., .../stock_transfer/ch.php)
 * 3. It will show your current user's status.
 * 4. It will also show all stores and their GATI location mapping status.
 */

// We now get $user_id, $company_id_context, and all session variables are set in the header
// The header.php file now safely checks if 'gati_location_name' column exists.
require_once 'includes/header.php'; 

// Get variables set by the header
$current_user_id = $user_id ?? 'N/A (Not Logged In)';
$current_store_id = $_SESSION['store_id_from_db'] ?? 'N/A'; // This is set by the updated header
$current_friendly_name = $_SESSION['friendly_store_name'] ?? 'N/A';
$current_gati_name = $_SESSION['gati_location_name'] ?? 'N/A';
// This session variable is set by the updated header.php
$gati_column_exists_in_stores = $_SESSION['gati_column_exists'] ?? false;


$all_stores = [];
$all_stock_locations = [];

// 1. Get all stores from the 'stores' table
// We select * so we can safely check for columns in the loop
$sql_stores = "SELECT * FROM stores WHERE company_id = ? ORDER BY store_name";
if ($stmt_all_stores = $conn->prepare($sql_stores)) {
    $stmt_all_stores->bind_param("i", $company_id_context);
    if ($stmt_all_stores->execute()) {
        $result_stores = $stmt_all_stores->get_result();
        while($row = $result_stores->fetch_assoc()) {
            $all_stores[] = $row;
        }
    }
    $stmt_all_stores->close();
}

// 2. Get all unique locations from the 'stock_data' table
$sql_stock_locs = "SELECT DISTINCT LocationName FROM stock_data WHERE company_id = ? AND LocationName IS NOT NULL AND LocationName != '' ORDER BY LocationName";
if ($stmt_stock_locs = $conn->prepare($sql_stock_locs)) {
    $stmt_stock_locs->bind_param("i", $company_id_context);
    if ($stmt_stock_locs->execute()) {
        $result_stock_locs = $stmt_stock_locs->get_result();
        while($row = $result_stock_locs->fetch_assoc()) {
            $all_stock_locations[] = $row['LocationName'];
        }
    }
    $stmt_stock_locs->close();
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="font-playfair" style="color: var(--sherpa-blue);">Stock Transfer Setup Check</h2>
    <a href="index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<!-- Card 0: Database Column Check -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">Database Structure Check</h4>
    </div>
    <div class="card-body">
        <?php if ($gati_column_exists_in_stores): ?>
             <div class="alert alert-success">
                <strong>Success:</strong> The required column <code>gati_location_name</code> exists in your <code>stores</code> table.
            </div>
        <?php else: ?>
             <div class="alert alert-danger">
                <strong>CRITICAL ERROR:</strong> The column <code>gati_location_name</code> is **MISSING** from your <code>stores</code> table.
                <br>
                <strong>Solution:</strong> You must run the `ALTER TABLE` command from the <code>fix_database.sql</code> file in your database to add this column.
            </div>
        <?php endif; ?>
    </div>
</div>


<!-- Card 1: Current User Status -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">Current User Status Check</h4>
    </div>
    <div class="card-body">
        <p>This checks the settings for your currently logged-in user.</p>
        <table class="table table-bordered align-middle">
            <tr>
                <td style="width: 30%;">Current User ID</td>
                <td><strong><?php echo htmlspecialchars($current_user_id); ?></strong></td>
            </tr>
            <tr>
                <td>User's Store ID (`users.store_id`)</td>
                <td><strong><?php echo htmlspecialchars($current_store_id); ?></strong></td>
            </tr>
            <tr>
                <td>Friendly Store Name (`stores.store_name`)</td>
                <td><strong><?php echo htmlspecialchars($current_friendly_name); ?></strong></td>
            </tr>
            <tr>
                <td>GATI Location Name (`stores.gati_location_name`)</td>
                <td><strong><?php echo htmlspecialchars($current_gati_name); ?></strong></td>
            </tr>
        </table>

        <?php if (!$gati_column_exists_in_stores): ?>
             <div class="alert alert-danger">
                <strong>Error:</strong> Cannot check user status because the <code>gati_location_name</code> column is missing from the <code>stores</code> table. Please fix the database structure first (see card above).
            </div>
        <?php elseif ($current_store_id === 'N/A' || $current_store_id === 'NULL' || empty($current_store_id)): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> Your user account (ID: <?php echo htmlspecialchars($current_user_id); ?>) is not linked to any store.
                <br>
                <strong>Solution:</strong> Ask your admin to edit your user profile in the "User Management" panel and assign you to a store, or run the SQL query from <code>fix_database.sql</code>.
            </div>
        <?php elseif ($current_gati_name === 'N/A' || empty($current_gati_name)): ?>
            <div class="alert alert-danger">
                <strong>Error:</strong> Your user is linked to a store ("<?php echo htmlspecialchars($current_friendly_name); ?>"), but that store has no <code>gati_location_name</code> set.
                <br>
                <strong>Solution:</strong> Ask your admin to edit this store in the "Manage Stores" panel (or run the SQL from <code>fix_database.sql</code>) to add the correct GATI Location Name.
            </div>
        <?php else: ?>
            <div class="alert alert-success">
                <strong>Success:</strong> Your account is correctly linked to the GATI Location: <strong><?php echo htmlspecialchars($current_gati_name); ?></strong>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Card 2: Full Store Mapping -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">Full Store Location Mapping (`stores` table)</h4>
    </div>
    <div class="card-body">
        <p>This table shows the data in your `stores` table. The <strong>GATI Location Name</strong> column MUST be filled and MUST exactly match a name from the `stock_data.LocationName` list (see next card).</p>
        <div class="table-responsive">
            <table class="table table-striped table-hover table-bordered">
                <thead class="table-light">
                    <tr>
                        <th>Store ID</th>
                        <th>Friendly Name (`store_name`)</th>
                        <th>GATI Location Name (`gati_location_name`)</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_stores)): ?>
                        <tr><td colspan="4" class="text-center p-4 text-muted">No stores found in the `stores` table.</td></tr>
                    <?php else: ?>
                        <?php foreach($all_stores as $store): ?>
                            <tr>
                                <td><?php echo $store['id']; ?></td>
                                <td><?php echo htmlspecialchars($store['store_name']); ?></td>
                                
                                <!-- Safely check if column exists before trying to access it -->
                                <?php if (!$gati_column_exists_in_stores): ?>
                                    <td class="text-danger"><strong>COLUMN MISSING</strong></td>
                                    <td><span class="badge bg-danger">Not Linked</span></td>
                                <?php elseif (!isset($store['gati_location_name']) || $store['gati_location_name'] === NULL || $store['gati_location_name'] === ''): ?>
                                    <td class_name="text-warning"><strong>NULL / EMPTY</strong></td>
                                    <td><span class="badge bg-warning">Not Linked</span></td>
                                <?php else: ?>
                                    <td><strong><?php echo htmlspecialchars($store['gati_location_name']); ?></strong></td>
                                    <td><span class="badge bg-success">Linked</span></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Card 3: Stock Data Locations -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">Available Locations in Stock Data (`stock_data.LocationName`)</h4>
    </div>
    <div class="card-body">
        <p>The "GATI Location Name" from the table above must **EXACTLY** match one of these names:</p>
        <div class="row">
            <div class="col-md-6">
                <ul class="list-group">
                    <?php if (empty($all_stock_locations)): ?>
                        <li class="list-group-item">No locations found in stock data.</li>
                    <?php else: ?>
                        <?php 
                        $half = ceil(count($all_stock_locations) / 2);
                        foreach(array_slice($all_stock_locations, 0, $half) as $loc): ?>
                            <li class="list-group-item"><code><?php echo htmlspecialchars($loc); ?></code></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
            <div class="col-md-6">
                 <ul class="list-group">
                    <?php if (!empty($all_stock_locations)): ?>
                        <?php foreach(array_slice($all_stock_locations, $half) as $loc): ?>
                            <li class="list-group-item"><code><?php echo htmlspecialchars($loc); ?></code></li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>
