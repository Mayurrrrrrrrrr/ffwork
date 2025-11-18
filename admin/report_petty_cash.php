<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Includes $conn, all helpers, session start

// -- SECURITY CHECK: ENSURE USER IS A PRIVILEGED USER --
// Allow Approvers (for their team), Accounts, and Admin
if (!has_any_role(['approver', 'accounts', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE --
$company_id_context = get_current_company_id();
$is_platform_admin = check_role('platform_admin');

if (!$company_id_context && !$is_platform_admin) {
     session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}
// Note: Platform admin will need to select a company first.
// For now, this report is scoped to the admin's current company.
// TODO: Add a company selector for Platform Admin.
if ($is_platform_admin && !$company_id_context) {
     // For now, redirect platform admin if they haven't "impersonated" a company
     header("location: " . BASE_URL . "platform_admin/companies.php?error=select_company_to_view_report"); 
     exit;
}

// Initialize variables
$error_message = '';
$wallet_users = [];
$transactions = [];
$summary = ['opening' => 0.00, 'added' => 0.00, 'spent' => 0.00, 'closing' => 0.00];
$selected_user_id = $_GET['user_id'] ?? null;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$selected_user_name = '';

// --- DATA FETCHING (Dropdowns) ---
// Fetch all users with a petty cash wallet in this company
$sql_users = "SELECT u.id, u.full_name 
              FROM users u
              JOIN petty_cash_wallets pcw ON u.id = pcw.user_id
              WHERE u.company_id = ?
              ORDER BY u.full_name";
if($stmt_users = $conn->prepare($sql_users)){
    $stmt_users->bind_param("i", $company_id_context);
    $stmt_users->execute();
    $result_users = $stmt_users->get_result();
    while($row = $result_users->fetch_assoc()){
        $wallet_users[] = $row;
    }
    $stmt_users->close();
} else {
    $error_message = "Error fetching user list: " . $conn->error;
}


// --- REPORT GENERATION LOGIC ---
if ($selected_user_id && empty($error_message)) {
    // Get the wallet_id and selected user's name
    $wallet_id = null;
    $sql_wallet = "SELECT pcw.id, u.full_name 
                   FROM petty_cash_wallets pcw
                   JOIN users u ON pcw.user_id = u.id
                   WHERE pcw.user_id = ? AND pcw.company_id = ?";
    if($stmt_wallet = $conn->prepare($sql_wallet)){
        $stmt_wallet->bind_param("ii", $selected_user_id, $company_id_context);
        $stmt_wallet->execute();
        $result_wallet = $stmt_wallet->get_result();
        if($row_wallet = $result_wallet->fetch_assoc()){
            $wallet_id = $row_wallet['id'];
            $selected_user_name = $row_wallet['full_name'];
        } else {
            $error_message = "Selected user does not have a valid wallet in this company.";
        }
        $stmt_wallet->close();
    }

    if($wallet_id){
        // 1. Calculate Opening Balance (all transactions *before* start_date)
        $sql_opening = "SELECT 
                        (SUM(CASE WHEN transaction_type = 'add' THEN amount ELSE 0 END) - 
                         SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) +
                         SUM(CASE WHEN transaction_type = 'adjustment' THEN amount ELSE 0 END)) as opening_balance
                        FROM petty_cash_transactions
                        WHERE wallet_id = ? AND transaction_date < ?";
        if($stmt_open = $conn->prepare($sql_opening)){
            $stmt_open->bind_param("is", $wallet_id, $start_date);
            $stmt_open->execute();
            $summary['opening'] = $stmt_open->get_result()->fetch_assoc()['opening_balance'] ?? 0.00;
            $stmt_open->close();
        } else { $error_message = "Error calculating opening balance: " . $conn->error; }

        // 2. Fetch Transactions *within* date range
        $sql_tx = "SELECT t.*, u.full_name as processed_by 
                   FROM petty_cash_transactions t
                   LEFT JOIN users u ON t.processed_by_user_id = u.id
                   WHERE t.wallet_id = ? AND t.transaction_date >= ? AND t.transaction_date <= ?
                   ORDER BY t.transaction_date ASC, t.id ASC";
        if($stmt_tx = $conn->prepare($sql_tx)){
            $end_date_full = $end_date . ' 23:59:59';
            $stmt_tx->bind_param("iss", $wallet_id, $start_date, $end_date_full);
            $stmt_tx->execute();
            $result_tx = $stmt_tx->get_result();
            while($tx = $result_tx->fetch_assoc()){
                $transactions[] = $tx;
                // 3. Calculate summary totals
                if($tx['transaction_type'] == 'add') $summary['added'] += $tx['amount'];
                if($tx['transaction_type'] == 'expense') $summary['spent'] += $tx['amount'];
                if($tx['transaction_type'] == 'adjustment') $summary['added'] += $tx['amount']; // Adjustments count as 'added'
            }
            $stmt_tx->close();
        } else { $error_message = "Error fetching transactions: " . $conn->error; }

        // 4. Calculate Closing Balance
        $summary['closing'] = $summary['opening'] + $summary['added'] - $summary['spent'];
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
        .text-success { color: #198754 !important; }
        .text-danger { color: #dc3545 !important; }
    }
</style>

<div class="container mt-4">
    <div class="d-flex justify-content-between align-items-center mb-3 no-print">
        <h2>Petty Cash User Statement</h2>
        <a href="admin/index.php" class="btn btn-secondary">Back to Dashboard</a>
    </div>

    <!-- Filter Form -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form action="admin/report_petty_cash.php" method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label for="user_id" class="form-label">User / Wallet Holder</label>
                    <select id="user_id" name="user_id" class="form-select" required>
                        <option value="">-- Select a User --</option>
                        <?php foreach ($wallet_users as $user): ?>
                            <option value="<?php echo $user['id']; ?>" <?php if($selected_user_id == $user['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($wallet_users)): ?>
                            <option value="" disabled>No users with petty cash wallets found in this company.</option>
                        <?php endif; ?>
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
                    <button type="submit" class="btn btn-primary w-100">Generate</button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if ($error_message): ?><div class="alert alert-danger no-print"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- Report Results -->
    <?php if ($selected_user_id && $wallet_id && empty($error_message)): ?>
        <div class="printable-area">
            <h3 class="text-center">Petty Cash Statement</h3>
            <p class="text-center fs-5">For: <strong><?php echo htmlspecialchars($selected_user_name); ?></strong></p>
            <p class="text-center text-muted">Period: <?php echo htmlspecialchars($start_date); ?> to <?php echo htmlspecialchars($end_date); ?></p>
            <button onclick="window.print()" class="btn btn-secondary no-print float-end mb-2">Print Report</button>
            
            <!-- Summary Box -->
            <div class="card mb-4">
                <div class="card-header"><h4>Summary</h4></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 col-6 text-center mb-3 mb-md-0">
                            <h6 class="text-muted">Opening Balance</h6>
                            <p class="fs-5">Rs. <?php echo number_format($summary['opening'], 2); ?></p>
                        </div>
                        <div class="col-md-3 col-6 text-center mb-3 mb-md-0">
                            <h6 class="text-muted">Funds Added</h6>
                            <p class="fs-5 text-success">+ Rs. <?php echo number_format($summary['added'], 2); ?></p>
                        </div>
                        <div class="col-md-3 col-6 text-center">
                            <h6 class="text-muted">Funds Spent</h6>
                            <p class="fs-5 text-danger">- Rs. <?php echo number_format($summary['spent'], 2); ?></p>
                        </div>
                        <div class="col-md-3 col-6 text-center">
                            <h6 class="text-muted">Closing Balance</h6>
                            <p class="fs-5 fw-bold">Rs. <?php echo number_format($summary['closing'], 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Transaction Ledger -->
            <div class="card">
                <div class="card-header"><h4>Detailed Ledger</h4></div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Processed By</th><th>Amount (Rs.)</th></tr></thead>
                            <tbody>
                                <?php if (empty($transactions)): ?>
                                    <tr><td colspan="5" class="text-center text-muted">No transactions found in this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach($transactions as $tx): ?>
                                        <tr>
                                            <td><?php echo date("Y-m-d H:i", strtotime($tx['transaction_date'])); ?></td>
                                            <td>
                                                <?php if($tx['transaction_type'] == 'add'): ?><span class="badge bg-success">Add</span>
                                                <?php elseif($tx['transaction_type'] == 'expense'): ?><span class="badge bg-danger">Expense</span>
                                                <?php else: ?><span class="badge bg-info">Adjustment</span><?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($tx['description']); ?>
                                                <?php // Find the report_id for the linked expense item
                                                if($tx['related_expense_item_id']): 
                                                    $report_id_query = $conn->query("SELECT report_id FROM expense_items WHERE id = " . (int)$tx['related_expense_item_id']);
                                                    $report_link_id = $report_id_query->fetch_assoc()['report_id'] ?? 0;
                                                    if($report_link_id):
                                                ?>
                                                    <a href="admin/review.php?report_id=<?php echo $report_link_id; ?>" target="_blank" class="no-print">(View Report)</a>
                                                <?php endif; endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($tx['processed_by'] ?? 'System/Employee'); ?></td>
                                            <td class="<?php echo ($tx['transaction_type'] == 'expense') ? 'text-danger' : 'text-success'; ?>">
                                                <?php echo ($tx['transaction_type'] == 'expense') ? '-' : '+'; ?>
                                                <?php echo number_format($tx['amount'], 2); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; 
?>


