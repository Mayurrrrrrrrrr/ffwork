<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, all helpers, session start

// -- SECURITY CHECK: ENSURE USER IS A PRIVILEGED USER --
// Allow Accounts, Admin, and Platform Admin
if (!has_any_role(['accounts', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE --
$company_id_context = get_current_company_id();
$is_platform_admin = check_role('platform_admin');

if (!$company_id_context && !$is_platform_admin) {
     session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}
// TODO: Add a company selector for Platform Admin to view this report
if ($is_platform_admin && !$company_id_context) {
     header("location: " . BASE_URL . "platform_admin/companies.php?error=select_company_to_view_report"); 
     exit;
}

// Initialize variables
$error_message = '';
$flagged_items = [];
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$department_filter = $_GET['department'] ?? '';
$departments = [];

// --- DATA FETCHING (Dropdowns) ---
$sql_depts = "SELECT DISTINCT department FROM users WHERE company_id = ? AND department IS NOT NULL AND department != '' ORDER BY department";
if($stmt_depts = $conn->prepare($sql_depts)){
    $stmt_depts->bind_param("i", $company_id_context);
    $stmt_depts->execute();
    $result_depts = $stmt_depts->get_result();
    while($row = $result_depts->fetch_assoc()){
        $departments[] = $row['department'];
    }
    $stmt_depts->close();
}

// --- ANOMALY DETECTION LOGIC ---
if (isset($_GET['user_id']) || isset($_GET['start_date'])) { // Check if form was submitted
    $base_sql = "FROM expense_items i
                 JOIN expense_reports r ON i.report_id = r.id
                 JOIN users u ON r.user_id = u.id
                 WHERE r.company_id = ? 
                 AND r.status IN ('approved', 'paid')
                 AND i.item_date BETWEEN ? AND ?";
    
    $params = [$company_id_context, $start_date, $end_date];
    $types = "iss";

    if(!empty($department_filter)){
        $base_sql .= " AND u.department = ?";
        $params[] = $department_filter;
        $types .= "s";
    }

    // 1. Check for High Value items (2 Standard Deviations above category mean)
    $sql_high_value = "
        SELECT i.id, i.item_date, u.full_name, i.category, i.amount, i.description, 'High Value' as flag_reason
        FROM expense_items i
        JOIN expense_reports r ON i.report_id = r.id
        JOIN users u ON r.user_id = u.id
        JOIN (
            SELECT 
                category, 
                AVG(amount) as avg_amount, 
                STDDEV(amount) as std_dev_amount
            FROM expense_items
            JOIN expense_reports ON expense_items.report_id = expense_reports.id
            WHERE expense_reports.company_id = ? AND expense_reports.status IN ('approved', 'paid')
            GROUP BY category
        ) AS stats ON i.category = stats.category
        WHERE r.company_id = ?
        AND r.status IN ('approved', 'paid')
        AND i.item_date BETWEEN ? AND ?
        AND i.amount > (stats.avg_amount + (2 * stats.std_dev_amount)) AND stats.std_dev_amount > 0
    ";
    // Simplified params for this specific query
    if($stmt_hv = $conn->prepare($sql_high_value)){
        $stmt_hv->bind_param("isss", $company_id_context, $company_id_context, $start_date, $end_date);
        $stmt_hv->execute();
        $result_hv = $stmt_hv->get_result();
        while($row = $result_hv->fetch_assoc()){ $flagged_items[$row['id']] = $row; }
        $stmt_hv->close();
    } else { $error_message = "Error checking high value items: " . $conn->error; }


    // 2. Check for Vague Descriptions (less than 10 chars)
    $sql_vague = "SELECT i.id, i.item_date, u.full_name, i.category, i.amount, i.description, 'Vague Description' as flag_reason
                  " . $base_sql . " AND CHAR_LENGTH(i.description) < 10";
    if($stmt_vague = $conn->prepare($sql_vague)){
        $stmt_vague->bind_param($types, ...$params);
        $stmt_vague->execute();
        $result_vague = $stmt_vague->get_result();
        while($row = $result_vague->fetch_assoc()){ $flagged_items[$row['id']] = $row; } // Add to array, overwrites duplicates
        $stmt_vague->close();
    } else { $error_message = "Error checking vague descriptions: " . $conn->error; }

    // 3. Check for Weekend Spending
    $sql_weekend = "SELECT i.id, i.item_date, u.full_name, i.category, i.amount, i.description, 'Weekend Spending' as flag_reason
                    " . $base_sql . " AND DAYOFWEEK(i.item_date) IN (1, 7)"; // 1=Sunday, 7=Saturday
     if($stmt_weekend = $conn->prepare($sql_weekend)){
        $stmt_weekend->bind_param($types, ...$params);
        $stmt_weekend->execute();
        $result_weekend = $stmt_weekend->get_result();
        while($row = $result_weekend->fetch_assoc()){ $flagged_items[$row['id']] = $row; }
        $stmt_weekend->close();
    } else { $error_message = "Error checking weekend spending: " . $conn->error; }
    
    // Sort final flagged list by date
    if(!empty($flagged_items)){
        usort($flagged_items, function($a, $b) { return strtotime($b['item_date']) - strtotime($a['item_date']); });
    }
}


require_once '../includes/header.php'; 
?>
<style>
    /* Simple styles for printing */
    @media print {
        body * { visibility: hidden; }
        .no-print { display: none; }
        .printable-area, .printable-area * { visibility: visible; }
        .printable-area { position: absolute; left: 0; top: 0; width: 100%; padding: 20px; }
        .card { border: none !important; box-shadow: none !important; }
        .table-responsive { overflow: visible !important; }
        .badge { -webkit-print-color-adjust: exact; color-adjust: exact; }
    }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h2>Expense Anomaly Report</h2>
        <a href="admin/index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form action="admin/report_anomalies.php" method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="department" class="form-label">Department</label>
                    <select id="department" name="department" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo htmlspecialchars($dept); ?>" <?php if($department_filter == $dept) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($dept); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="start_date" class="form-label">Start Date</label>
                    <input type="date" id="start_date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
                </div>
                <div class="col-md-3">
                    <label for="end_date" class="form-label">End Date</label>
                    <input type="date" id="end_date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Run Report</button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($error_message): ?><div class="alert alert-danger no-print"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- Report Results -->
    <div class="printable-area">
        <h3 class="text-center">Expense Anomaly Report</h3>
        <p class="text-center text-muted">Period: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?> | Department: <?php echo htmlspecialchars($department_filter ?: 'All'); ?></p>
        <button onclick="window.print()" class="btn btn-secondary no-print float-end mb-2">Print Report</button>
        
        <div class="card">
            <div class="card-header"><h4>Flagged Expense Items</h4></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover table-sm">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Employee</th>
                                <th>Category</th>
                                <th>Amount (Rs.)</th>
                                <th>Description</th>
                                <th>Reason for Flag</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($flagged_items)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No anomalous items found for the selected criteria.</td></tr>
                            <?php else: ?>
                                <?php foreach($flagged_items as $item): ?>
                                    <tr class="table-warning">
                                        <td><?php echo htmlspecialchars($item['item_date']); ?></td>
                                        <td><?php echo htmlspecialchars($item['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category']); ?></td>
                                        <td><?php echo number_format($item['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars($item['description']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($item['flag_reason']); ?></strong></td>
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

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; 
?>


