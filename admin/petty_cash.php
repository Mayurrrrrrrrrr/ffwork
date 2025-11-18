<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS OR ADMIN --
if (!has_any_role(['accounts', 'admin'])) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE --
$company_id = get_current_company_id();
if (!$company_id) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error");
    exit;
}

$error_message = '';
$success_message = '';
$current_user_id = $_SESSION['user_id']; // User performing the action

// --- FORM HANDLING: ADD FUNDS TO A WALLET ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_funds'])) {
    $wallet_id_to_update = $_POST['wallet_id'];
    $amount_to_add = $_POST['amount'];
    $description = trim($_POST['description']);

    if (!is_numeric($amount_to_add) || $amount_to_add <= 0) {
        $error_message = "Invalid amount specified.";
    } elseif (empty($description)) {
        $error_message = "Description is required when adding funds.";
    } else {
        // Use a transaction to ensure atomicity
        $conn->begin_transaction();
        try {
            // 1. Update the wallet balance
            $sql_update_wallet = "UPDATE petty_cash_wallets SET current_balance = current_balance + ? 
                                  WHERE id = ? AND company_id = ?";
            $stmt_update = $conn->prepare($sql_update_wallet);
            $stmt_update->bind_param("dii", $amount_to_add, $wallet_id_to_update, $company_id);
            $stmt_update->execute();

            if ($stmt_update->affected_rows === 0) {
                throw new Exception("Wallet not found or does not belong to this company.");
            }

            // 2. Log the transaction
            $sql_log = "INSERT INTO petty_cash_transactions 
                        (wallet_id, transaction_type, amount, description, processed_by_user_id) 
                        VALUES (?, 'add', ?, ?, ?)";
            $stmt_log = $conn->prepare($sql_log);
            $stmt_log->bind_param("idsi", $wallet_id_to_update, $amount_to_add, $description, $current_user_id);
            $stmt_log->execute();
            
            $conn->commit();
            $success_message = "Funds added successfully.";

            $stmt_update->close();
            $stmt_log->close();

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error adding funds: " . $e->getMessage();
             if(isset($stmt_update)) $stmt_update->close();
             if(isset($stmt_log)) $stmt_log->close();
        }
    }
}

// --- FORM HANDLING: CREATE NEW WALLET ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_wallet'])) {
     $user_id_for_wallet = $_POST['user_id'];
     $initial_balance = $_POST['initial_balance'] ?? 0;

     if(empty($user_id_for_wallet)){
         $error_message = "Please select a user to assign the wallet to.";
     } elseif (!is_numeric($initial_balance) || $initial_balance < 0) {
         $error_message = "Invalid initial balance.";
     } else {
         // Check if user already has a wallet
         $sql_check = "SELECT id FROM petty_cash_wallets WHERE user_id = ? AND company_id = ?";
         if($stmt_check = $conn->prepare($sql_check)){
             $stmt_check->bind_param("ii", $user_id_for_wallet, $company_id);
             $stmt_check->execute();
             $stmt_check->store_result();
             if($stmt_check->num_rows > 0){
                 $error_message = "This user already has a petty cash wallet.";
             } else {
                 // Create the wallet
                 $sql_create = "INSERT INTO petty_cash_wallets (company_id, user_id, current_balance) VALUES (?, ?, ?)";
                 if($stmt_create = $conn->prepare($sql_create)){
                     $stmt_create->bind_param("iid", $company_id, $user_id_for_wallet, $initial_balance);
                     if($stmt_create->execute()){
                         $success_message = "Petty cash wallet created successfully.";
                         // Optionally log initial balance if > 0
                         if($initial_balance > 0){
                            $new_wallet_id = $conn->insert_id;
                             $sql_log_init = "INSERT INTO petty_cash_transactions 
                                        (wallet_id, transaction_type, amount, description, processed_by_user_id) 
                                        VALUES (?, 'add', ?, 'Initial balance', ?)";
                            $stmt_log_init = $conn->prepare($sql_log_init);
                            $stmt_log_init->bind_param("idi", $new_wallet_id, $initial_balance, $current_user_id);
                            $stmt_log_init->execute();
                            $stmt_log_init->close();
                         }
                     } else { $error_message = "Error creating wallet: " . $stmt_create->error; }
                     $stmt_create->close();
                 }
             }
             $stmt_check->close();
         }
     }
}


// --- DATA FETCHING ---
// Fetch existing wallets for the company
$wallets = [];
$sql_wallets = "SELECT pcw.id, pcw.current_balance, pcw.last_updated, u.full_name 
                FROM petty_cash_wallets pcw
                JOIN users u ON pcw.user_id = u.id
                WHERE pcw.company_id = ? ORDER BY u.full_name";
if ($stmt_wallets = $conn->prepare($sql_wallets)) {
    $stmt_wallets->bind_param("i", $company_id);
    if ($stmt_wallets->execute()) {
        $result = $stmt_wallets->get_result();
        while ($row = $result->fetch_assoc()) {
            $wallets[] = $row;
        }
    } else { $error_message = "Error fetching wallets."; }
    $stmt_wallets->close();
}

// Fetch users within the company who DON'T have a wallet yet, to populate the 'Create' dropdown
$users_without_wallets = [];
$sql_users = "SELECT u.id, u.full_name 
              FROM users u 
              LEFT JOIN petty_cash_wallets pcw ON u.id = pcw.user_id
              WHERE u.company_id = ? AND pcw.id IS NULL
              ORDER BY u.full_name";
if($stmt_users = $conn->prepare($sql_users)){
    $stmt_users->bind_param("i", $company_id);
    if($stmt_users->execute()){
        $result_users = $stmt_users->get_result();
        while($row_user = $result_users->fetch_assoc()){
            $users_without_wallets[] = $row_user;
        }
    } else { $error_message = "Error fetching users."; }
    $stmt_users->close();
}

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Petty Cash Management</h2>
    <p>Manage petty cash wallets for users within your company.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Create New Wallet Form -->
    <div class="card mb-4">
        <div class="card-header"><h4>Create New Petty Cash Wallet</h4></div>
        <div class="card-body">
            <form action="admin/petty_cash.php" method="post">
                <div class="row g-3 align-items-end">
                    <div class="col-md-5">
                        <label for="user_id" class="form-label">Assign to User</label>
                        <select id="user_id" name="user_id" class="form-select" required>
                            <option value="">-- Select User --</option>
                            <?php foreach ($users_without_wallets as $user): ?>
                                <option value="<?php echo $user['id']; ?>"><?php echo htmlspecialchars($user['full_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="initial_balance" class="form-label">Initial Balance (Rs.)</label>
                        <input type="number" step="0.01" min="0" class="form-control" id="initial_balance" name="initial_balance" value="0.00">
                    </div>
                    <div class="col-md-3">
                        <button type="submit" name="create_wallet" class="btn btn-primary w-100">Create Wallet</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Wallets List & Add Funds -->
    <div class="card">
        <div class="card-header"><h4>Existing Wallets</h4></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Current Balance (Rs.)</th>
                            <th>Last Updated</th>
                            <th>Add Funds</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($wallets)): ?>
                            <tr><td colspan="4">No petty cash wallets created yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($wallets as $wallet): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($wallet['full_name']); ?></td>
                                    <td><strong><?php echo number_format($wallet['current_balance'], 2); ?></strong></td>
                                    <td><?php echo date("Y-m-d H:i", strtotime($wallet['last_updated'])); ?></td>
                                    <td>
                                        <form action="admin/petty_cash.php" method="post" class="d-flex gap-2">
                                            <input type="hidden" name="wallet_id" value="<?php echo $wallet['id']; ?>">
                                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control form-control-sm" placeholder="Amount" required style="width: 100px;">
                                            <input type="text" name="description" class="form-control form-control-sm" placeholder="Description (e.g., Monthly top-up)" required>
                                            <button type="submit" name="add_funds" class="btn btn-success btn-sm">Add</button>
                                        </form>
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

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>


