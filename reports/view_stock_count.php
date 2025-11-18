<?php
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id, $is_manager

$error_message = '';
$stock_counts = [];

// --- Set default date filters ---
$start_date = $_GET['start_date'] ?? date('Y-m-01'); // Default to start of current month
$end_date = $_GET['end_date'] ?? date('Y-m-d');     // Default to today
$store_filter = $_GET['store_id'] ?? 'all';

// --- DATA FETCHING ---
$all_stores = [];
// Fetch stores for the filter
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
if($stmt_stores = $conn->prepare($sql_stores)){
    $stmt_stores->bind_param("i", $company_id_context);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while($row_store = $result_stores->fetch_assoc()){ $all_stores[] = $row_store; }
    $stmt_stores->close();
}

// --- Build the main SQL query ---
$sql_query = "SELECT 
                r.*, 
                u.full_name, 
                s.store_name 
              FROM report_stock_count r
              JOIN users u ON r.user_id = u.id
              JOIN stores s ON r.store_id = s.id
              WHERE r.company_id = ? AND r.report_date BETWEEN ? AND ?";
$params = [$company_id_context, $start_date, $end_date];
$types = "iss";

// Add filters based on role
if (!$is_manager) {
    // Regular employee can only see their own
    $sql_query .= " AND r.user_id = ?";
    $params[] = $user_id;
    $types .= "i";
} elseif ($store_filter !== 'all' && is_numeric($store_filter)) {
    // Manager can filter by store
    $sql_query .= " AND r.store_id = ?";
    $params[] = $store_filter;
    $types .= "i";
}

$sql_query .= " ORDER BY r.report_date DESC, s.store_name";

if($stmt = $conn->prepare($sql_query)){
    $stmt->bind_param($types, ...$params);
    if($stmt->execute()){
        $result = $stmt->get_result();
        while($row = $result->fetch_assoc()) { $stock_counts[] = $row; }
    } else { $error_message = "Error generating report: " . $stmt->error; }
    $stmt->close();
} else { $error_message = "Database prepare error: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>View Stock Counts</h2>
    <a href="reports/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Forms</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

<!-- Filter Form -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <form action="reports/view_stock_count.php" method="get" class="row g-3 align-items-end">
            <?php if($is_manager): ?>
            <div class="col-md-4">
                <label for="store_id" class="form-label">Store / Cost Center</label>
                <select id="store_id" name="store_id" class="form-select">
                    <option value="all">All Stores</option>
                    <?php foreach ($all_stores as $store): ?>
                        <option value="<?php echo $store['id']; ?>" <?php if($store_filter == $store['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($store['store_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label for="start_date" class="form-label">Start Date</label>
                <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">End Date</label>
                <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Report Results -->
<div class="card printable-area">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Stock Count Submissions</h4>
        <button onclick="window.print()" class="btn btn-outline-secondary no-print"><i data-lucide="printer" class="me-2"></i>Print</button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Store</th>
                        <th>Submitted By</th>
                        <th>System Count</th>
                        <th>Physical Count</th>
                        <th>Discrepancy</th>
                        <th>File</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($stock_counts)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No stock counts found for this period.</td></tr>
                    <?php else: ?>
                        <?php foreach($stock_counts as $item): ?>
                        <tr class="<?php echo $item['discrepancy'] != 0 ? 'table-danger' : ''; ?>">
                            <td><?php echo htmlspecialchars($item['report_date']); ?></td>
                            <td><?php echo htmlspecialchars($item['store_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['full_name']); ?></td>
                            <td><?php echo $item['system_count']; ?></td>
                            <td><?php echo $item['physical_count']; ?></td>
                            <td class="fw-bold"><?php echo $item['discrepancy']; ?></td>
                            <td><?php echo $item['file_url'] ? '<a href="'.$item['file_url'].'" target="_blank">View</a>' : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($item['remarks']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


