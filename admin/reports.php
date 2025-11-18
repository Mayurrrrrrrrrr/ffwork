<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This MUST be the very first line to prevent blank pages.
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS A SUPER ADMIN --
// This page is for the 'admin' role only.
if (!check_role('admin')) {
    header("Location: ../login.php");
    exit;
}

// -- DATA FETCHING FOR FILTERS --
$employees = [];
// Correctly fetch users who have the 'employee' role
$sql_employees = "SELECT u.id, u.full_name 
                  FROM users u
                  JOIN user_roles ur ON u.id = ur.user_id
                  WHERE ur.role_id = (SELECT id FROM roles WHERE name = 'employee')
                  ORDER BY u.full_name";
if ($result = $conn->query($sql_employees)) {
    while ($row = $result->fetch_assoc()) {
        $employees[] = $row;
    }
    $result->free();
}

// -- FILTERING LOGIC --
$filtered_reports = [];
$category_summary = [];
$payment_summary = [];
$where_clauses = [];
$params = [];
$types = '';
$filters_active = false;

$sql_reports = "SELECT r.*, u.full_name, u.department 
                FROM expense_reports r 
                JOIN users u ON r.user_id = u.id";
$sql_summary_base = "FROM expense_items i JOIN expense_reports r ON i.report_id = r.id";

$user_id_filter = $_GET['user_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

if (!empty($user_id_filter) || !empty($status_filter) || !empty($start_date_filter) || !empty($end_date_filter)) {
    $filters_active = true;
}

if (!empty($user_id_filter)) { $where_clauses[] = "r.user_id = ?"; $params[] = $user_id_filter; $types .= 'i'; }
if (!empty($status_filter)) { $where_clauses[] = "r.status = ?"; $params[] = $status_filter; $types .= 's'; }
if (!empty($start_date_filter)) { $where_clauses[] = "r.submitted_at >= ?"; $params[] = $start_date_filter . ' 00:00:00'; $types .= 's'; }
if (!empty($end_date_filter)) { $where_clauses[] = "r.submitted_at <= ?"; $params[] = $end_date_filter . ' 23:59:59'; $types .= 's'; }

$where_sql = "";
if (!empty($where_clauses)) {
    $where_sql = " WHERE " . implode(' AND ', $where_clauses);
}

// --- Query 1: Get individual filtered reports ---
$sql_reports .= $where_sql . " ORDER BY r.submitted_at DESC";
if ($stmt = $conn->prepare($sql_reports)) {
    if (!empty($params)) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) { $filtered_reports[] = $row; }
    $stmt->close();
}

// --- Query 2 & 3: Get summary data if filters are active ---
if ($filters_active && !empty($filtered_reports)) {
    // Category Summary
    $sql_cat_summary = "SELECT i.category, SUM(i.amount) as total " . $sql_summary_base . $where_sql . " GROUP BY i.category ORDER BY total DESC";
    if ($stmt_cat = $conn->prepare($sql_cat_summary)) {
        if (!empty($params)) { $stmt_cat->bind_param($types, ...$params); }
        $stmt_cat->execute();
        $result_cat = $stmt_cat->get_result();
        while($row = $result_cat->fetch_assoc()) { $category_summary[] = $row; }
        $stmt_cat->close();
    }

    // Payment Method Summary
    $sql_pay_summary = "SELECT i.payment_method, SUM(i.amount) as total " . $sql_summary_base . $where_sql . " GROUP BY i.payment_method ORDER BY total DESC";
     if ($stmt_pay = $conn->prepare($sql_pay_summary)) {
        if (!empty($params)) { $stmt_pay->bind_param($types, ...$params); }
        $stmt_pay->execute();
        $result_pay = $stmt_pay->get_result();
        while($row = $result_pay->fetch_assoc()) { $payment_summary[] = $row; }
        $stmt_pay->close();
    }
}

require_once '../includes/header.php';
?>
<style>
    @media print {
        body * { visibility: hidden; }
        .printable-area, .printable-area * { visibility: visible; }
        .printable-area { position: absolute; left: 0; top: 0; width: 100%; }
        .no-print { display: none; }
    }
</style>

<div class="container mt-4">
    <div class="no-print">
        <h3>Generate Reports</h3>
        <div class="card mb-4">
            <div class="card-body">
                <form action="reports.php" method="get" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label for="user_id" class="form-label">Employee</label>
                        <select id="user_id" name="user_id" class="form-select">
                            <option value="">All Employees</option>
                            <?php foreach ($employees as $employee): ?>
                                <option value="<?php echo $employee['id']; ?>" <?php echo ($user_id_filter == $employee['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($employee['full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">Status</label>
                        <select id="status" name="status" class="form-select">
                            <option value="">All Statuses</option>
                            <option value="pending_approval" <?php echo ($status_filter == 'pending_approval') ? 'selected' : ''; ?>>Pending Approval</option>
                            <option value="pending_verification" <?php echo ($status_filter == 'pending_verification') ? 'selected' : ''; ?>>Pending Verification</option>
                            <option value="approved" <?php echo ($status_filter == 'approved') ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo ($status_filter == 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                            <option value="paid" <?php echo ($status_filter == 'paid') ? 'selected' : ''; ?>>Paid</option>
                        </select>
                    </div>
                    <div class="col-md-3"><label for="start_date" class="form-label">Start Date</label><input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date_filter); ?>"></div>
                    <div class="col-md-3"><label for="end_date" class="form-label">End Date</label><input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date_filter); ?>"></div>
                    <div class="col-md-1"><button type="submit" class="btn btn-primary">Filter</button></div>
                </form>
            </div>
        </div>
    </div>

    <div class="printable-area">
         <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>Filtered Results</h4>
            <div class="no-print">
                <?php if ($filters_active && !empty($filtered_reports)): ?>
                    <a href="export.php?<?php echo http_build_query($_GET); ?>" class="btn btn-success">Export to Excel</a>
                <?php endif; ?>
                <button onclick="window.print()" class="btn btn-secondary">Print Report</button>
            </div>
        </div>
        
        <?php if (!$filters_active): ?>
            <div class="alert alert-info">Please select at least one filter to generate a report.</div>
        <?php elseif (empty($filtered_reports)): ?>
            <div class="alert alert-warning">No reports found for the selected criteria.</div>
        <?php else: ?>
            <div class="row mb-4 no-print">
                <div class="col-md-6">
                    <div class="card"><div class="card-header"><strong>Expenses by Category</strong></div><div class="card-body"><div class="table-responsive"><table class="table">
                        <?php foreach($category_summary as $item): ?>
                        <tr><td><?php echo htmlspecialchars($item['category']); ?></td><td class="text-end">Rs. <?php echo number_format($item['total'], 2); ?></td></tr>
                        <?php endforeach; ?>
                    </table></div></div></div>
                </div>
                <div class="col-md-6">
                    <div class="card"><div class="card-header"><strong>Expenses by Payment Method</strong></div><div class="card-body"><div class="table-responsive"><table class="table">
                        <?php foreach($payment_summary as $item): ?>
                        <tr><td><?php echo htmlspecialchars($item['payment_method']); ?></td><td class="text-end">Rs. <?php echo number_format($item['total'], 2); ?></td></tr>
                        <?php endforeach; ?>
                    </table></div></div></div>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header"><strong>Individual Report Details</strong></div>
                <div class="card-body">
                     <div class="table-responsive">
                         <table class="table table-striped table-hover">
                            <thead><tr><th>Employee</th><th>Report Title</th><th>Submitted</th><th>Status</th><th>Claimed</th><th>Approved</th><th>Action</th></tr></thead>
                            <tbody>
                                <?php foreach ($filtered_reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['report_title']); ?></td>
                                    <td><?php echo date("Y-m-d", strtotime($report['submitted_at'])); ?></td>
                                    <td><span class="badge <?php 
                                        switch($report['status']) {
                                            case 'approved': case 'paid': echo 'bg-success'; break;
                                            case 'rejected': echo 'bg-danger'; break;
                                            case 'pending_approval': echo 'bg-warning text-dark'; break;
                                            case 'pending_verification': echo 'bg-info text-dark'; break;
                                            default: echo 'bg-secondary';
                                        }
                                    ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report['status']))); ?></span></td>
                                    <td>Rs. <?php echo number_format($report['total_amount'], 2); ?></td>
                                    <td>Rs. <?php echo number_format($report['approved_amount'], 2); ?></td>
                                    <td><a href="review.php?report_id=<?php echo $report['id']; ?>" class="btn btn-secondary btn-sm">View Details</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$conn->close();
require_once '../includes/footer.php';
?>

