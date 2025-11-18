<?php
// Use the new Tools header - Path is relative to the root include header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    header("location: " . BASE_URL . "tools/index.php");
    exit;
}

$error_message = '';
$success_message = '';

// --- DATA ---
$my_stores = [];
$all_transactions = [];
$current_gold_rate = 0;

// Fetch stores this user is assigned to (same logic as sales report)
$sql_stores = "SELECT id, store_name, store_code FROM stores WHERE company_id = ? AND is_active = 1
               AND (EXISTS (SELECT 1 FROM users WHERE id = ? AND store_id = stores.id) OR ? = 1)"; // Allow admin/platform admin
if ($stmt_stores = $conn->prepare($sql_stores)) {
    $is_admin_check = (int)has_any_role(['admin', 'platform_admin']);
    $stmt_stores->bind_param("iii", $company_id_context, $user_id, $is_admin_check);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while ($row_store = $result_stores->fetch_assoc()) { $my_stores[] = $row_store; }
    $stmt_stores->close();
} else {
    // Handle prepare error if needed
    error_log("Failed to prepare statement for fetching stores: " . $conn->error);
}


// Fetch current gold rate
$sql_get_rate = "SELECT rate_per_gram FROM gold_rates WHERE company_id = ?";
if ($stmt_get_rate = $conn->prepare($sql_get_rate)) {
    $stmt_get_rate->bind_param("i", $company_id_context);
    if ($stmt_get_rate->execute()) {
        $rate_result = $stmt_get_rate->get_result();
        if ($rate_result->num_rows > 0) {
            $current_gold_rate = $rate_result->fetch_assoc()['rate_per_gram'] ?? 0;
        }
    } else {
        error_log("Failed to execute statement for fetching gold rate: " . $stmt_get_rate->error);
    }
    $stmt_get_rate->close();
} else {
     error_log("Failed to prepare statement for fetching gold rate: " . $conn->error);
}


// --- CHECK AND BYPASS: ALWAYS ALLOW FORM SUBMISSION REGARDLESS OF RATE ---
$gold_rate_check_passed = true;
$rate_warning_message = ''; // Initialize warning message
// Ensure $current_gold_rate is treated as a number for comparison
if (!is_numeric($current_gold_rate) || (float)$current_gold_rate <= 0) {
    $current_gold_rate = 0; // Set to 0 if invalid or non-positive
    $gold_rate_check_passed = false;
    $rate_warning_message = "Gold Rate is currently set to 0.00 or is invalid. The calculated value will also be 0.00. **Transaction saving is enabled**, but an admin MUST update the rate immediately after processing.";
}


// --- FORM HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_transaction'])) {
    // Get raw data (minimal validation as requested)
    $transaction_date = trim($_POST['transaction_date'] ?? date('Y-m-d'));
    $store_id = filter_input(INPUT_POST, 'store_id', FILTER_VALIDATE_INT);
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_mobile = trim($_POST['customer_mobile'] ?? '');
    $customer_pan = trim(strtoupper($_POST['customer_pan'] ?? ''));
    $customer_address = trim($_POST['customer_address'] ?? '');
    $item_description = trim($_POST['item_description'] ?? '');
    $gross_weight_before = empty($_POST['gross_weight_before']) ? null : (float)$_POST['gross_weight_before'];
    $purity_before = empty($_POST['purity_before']) ? null : (float)$_POST['purity_before'];
    $gross_weight_after = (float)($_POST['gross_weight_after'] ?? 0);
    $purity_after = (float)($_POST['purity_after'] ?? 0);
    $deduction_addition = empty($_POST['deduction_addition']) ? 0.000 : (float)$_POST['deduction_addition'];
    $remarks = trim($_POST['remarks'] ?? '');

    // Get store code for BOS
    $selected_store_code = 'NA';
    if ($store_id) {
        foreach ($my_stores as $store) {
            if ($store['id'] == $store_id) {
                $selected_store_code = $store['store_code'];
                break;
            }
        }
    }
    
    // Basic check for absolutely essential data
    if (empty($store_id) || empty($customer_name) || empty($customer_pan) || $gross_weight_after <= 0 || $purity_after <= 0) {
         $error_message = "Store, Customer Name, PAN, Gross Wt (After), and Purity (After) are absolutely required.";
    } else {
        // Calculations
        $net_weight_calculated = ($gross_weight_after * $purity_after) / 100.0;
        $final_value = $net_weight_calculated * (float)$current_gold_rate;

        // Generate Bill of Supply Number
        $year = date('Y', strtotime($transaction_date));
        $store_code_for_bos = $selected_store_code ?: 'NA';
        $bos_prefix = "BOS/" . $store_code_for_bos . "/" . $year . "/";

        $conn->begin_transaction();
        try {
            // Get the next sequence number (still using prepared statement for this SELECT, it's safer and not the failing part)
            $sql_seq = "SELECT MAX(CAST(SUBSTRING_INDEX(bill_of_supply_no, '/', -1) AS UNSIGNED)) as last_num
                        FROM old_gold_transactions
                        WHERE company_id = ? AND store_id = ? AND bill_of_supply_no LIKE ?";
            $stmt_seq = $conn->prepare($sql_seq);
            if(!$stmt_seq) throw new Exception("DB Prepare Error (Sequence): " . $conn->error);
            
            $like_pattern = $bos_prefix . '%';
            $stmt_seq->bind_param("iis", $company_id_context, $store_id, $like_pattern);
            if(!$stmt_seq->execute()) throw new Exception("DB Execute Error (Sequence): " . $stmt_seq->error);
            
            $result_seq = $stmt_seq->get_result();
            $last_num_row = $result_seq->fetch_assoc();
            $last_num = $last_num_row ? ($last_num_row['last_num'] ?? 0) : 0;
            $next_num = $last_num + 1;
            $bill_of_supply_no = $bos_prefix . str_pad($next_num, 4, '0', STR_PAD_LEFT);
            $stmt_seq->close();

            // --- TOTALLY DIFFERENT WAY: Manual SQL String Building ---
            // This is NOT best practice, but necessary for debugging this specific server issue.
            
            // Manually escape all string values
            $esc_transaction_date = "'" . $conn->real_escape_string($transaction_date) . "'";
            $esc_bill_of_supply_no = "'" . $conn->real_escape_string($bill_of_supply_no) . "'";
            $esc_customer_name = "'" . $conn->real_escape_string($customer_name) . "'";
            $esc_customer_mobile = "'" . $conn->real_escape_string($customer_mobile) . "'";
            $esc_customer_pan = "'" . $conn->real_escape_string($customer_pan) . "'";
            $esc_customer_address = "'" . $conn->real_escape_string($customer_address) . "'";
            $esc_item_description = "'" . $conn->real_escape_string($item_description) . "'";
            $esc_remarks = "'" . $conn->real_escape_string($remarks) . "'";

            // Handle NULL values for floats
            $esc_gross_weight_before = ($gross_weight_before === null) ? 'NULL' : (float)$gross_weight_before;
            $esc_purity_before = ($purity_before === null) ? 'NULL' : (float)$purity_before;

            // Cast all other numbers
            $esc_company_id = (int)$company_id_context;
            $esc_store_id = (int)$store_id;
            $esc_user_id = (int)$user_id;
            $esc_gross_weight_after = (float)$gross_weight_after;
            $esc_purity_after = (float)$purity_after;
            $esc_deduction_addition = (float)$deduction_addition;
            $esc_net_weight_calculated = (float)$net_weight_calculated;
            $esc_current_gold_rate = (float)$current_gold_rate;
            $esc_final_value = (float)$final_value;


            // Build the raw SQL query string
            $sql_insert = "INSERT INTO old_gold_transactions (
                                company_id, store_id, user_id, transaction_date, bill_of_supply_no,
                                customer_name, customer_mobile, customer_pan, customer_address,
                                item_description, gross_weight_before, purity_before,
                                gross_weight_after, purity_after, deduction_addition,
                                net_weight_calculated, gold_rate_applied, final_value, remarks
                           ) VALUES (
                                $esc_company_id, $esc_store_id, $esc_user_id, $esc_transaction_date, $esc_bill_of_supply_no,
                                $esc_customer_name, $esc_customer_mobile, $esc_customer_pan, $esc_customer_address,
                                $esc_item_description, $esc_gross_weight_before, $esc_purity_before,
                                $esc_gross_weight_after, $esc_purity_after, $esc_deduction_addition,
                                $esc_net_weight_calculated, $esc_current_gold_rate, $esc_final_value, $esc_remarks
                           )";
            
            // Execute with $conn->query() instead of prepared statement
            if ($conn->query($sql_insert) === TRUE) {
                $new_id = $conn->insert_id;
                log_audit_action($conn, 'old_gold_logged', "Logged old gold transaction. BOS: {$bill_of_supply_no}", $user_id, $company_id_context, 'old_gold', $new_id);
                $conn->commit();
                header("location: " . BASE_URL . "tools/old_gold_v2.php?success=saved&bos=" . urlencode($bill_of_supply_no));
                exit;
            } else {
                throw new Exception("Error saving transaction (Manual Query): " . $conn->error);
            }
            // --- END OF DIFFERENT WAY ---

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "An error occurred: " . $e->getMessage();
            error_log("Old Gold Save Error: " . $e->getMessage());
        }
    }
}

// --- DATA FETCHING (for Report View) ---
$report_start_date = $_GET['start_date'] ?? date('Y-m-01');
$report_end_date = $_GET['end_date'] ?? date('Y-m-d');
$report_store_filter = $_GET['store_id_filter'] ?? 'all';

$all_transactions = []; // Ensure it's always an array
$sql_report = "SELECT og.*, s.store_name, u.full_name as employee_name, s.store_code
                FROM old_gold_transactions og
                JOIN stores s ON og.store_id = s.id
                JOIN users u ON og.user_id = u.id
                WHERE og.company_id = ? AND og.transaction_date BETWEEN ? AND ?";
$params_report = [$company_id_context, $report_start_date, $report_end_date];
$types_report = "iss";

if ($report_store_filter !== 'all' && is_numeric($report_store_filter)) {
    $sql_report .= " AND og.store_id = ?";
    $params_report[] = (int)$report_store_filter; // Cast to int
    $types_report .= "i";
} elseif (!has_any_role(['admin', 'platform_admin'])) {
    // If not admin, only show stores they are assigned to
    $store_ids_user_can_access = array_column($my_stores, 'id');
    if (!empty($store_ids_user_can_access)) {
        // Ensure all IDs are integers
        $store_ids_user_can_access = array_map('intval', $store_ids_user_can_access);
        $placeholders = implode(',', array_fill(0, count($store_ids_user_can_access), '?'));
        $sql_report .= " AND og.store_id IN ($placeholders)";
        $params_report = array_merge($params_report, $store_ids_user_can_access);
        $types_report .= str_repeat('i', count($store_ids_user_can_access));
    } else {
        $sql_report .= " AND 1=0"; // Prevent showing anything if user has no stores
        $params_report = []; // Clear params if no stores accessible
        $types_report = "";
    }
}
// Admin 'all' stores logic
elseif ($report_store_filter === 'all_admin' && has_any_role(['admin', 'platform_admin'])) {
    // No additional store filter needed, params_report and types_report are already correct
}
elseif ($report_store_filter === 'all') {
     // User is not admin, 'all' means 'all my stores' - this logic is handled above
     // This block is just to ensure no unintended fall-through
}

$sql_report .= " ORDER BY og.transaction_date DESC, og.id DESC";

// Only proceed if there are parameters to bind or if it's an admin viewing all
if (!empty($params_report) || has_any_role(['admin', 'platform_admin'])) {
    if ($stmt_report = $conn->prepare($sql_report)) {
        // Only bind if there are types and params defined
        if (!empty($types_report) && !empty($params_report)) {
             if (!$stmt_report->bind_param($types_report, ...$params_report)) {
                 $error_message = "Error binding report parameters: " . $stmt_report->error;
             }
        }

        // Execute only if binding was successful or not needed
        if (empty($error_message) && $stmt_report->execute()) {
            $result_report = $stmt_report->get_result();
            while ($row = $result_report->fetch_assoc()) {
                $all_transactions[] = $row;
            }
        } elseif (empty($error_message)) {
            // Execution failed
            $error_message = "Error fetching report data: " . $stmt_report->error;
        }
        $stmt_report->close();
    } else {
        $error_message = "DB Error preparing report: " . $conn->error;
    }
} elseif (strpos($sql_report, "AND 1=0") !== false) {
    // If the query was intentionally made to return nothing (no stores for user), don't show DB error
    $all_transactions = [];
} else if (empty($params_report) && empty($types_report)) {
     // This can happen if a non-admin has no stores.
     $all_transactions = []; // Show no results
} else {
     $error_message = "Could not generate report due to missing parameters.";
}


// Check for success from redirect
if (isset($_GET['success']) && $_GET['success'] == 'saved') {
    $bos_number = htmlspecialchars($_GET['bos'] ?? 'N/A');
    // Using htmlspecialchars for the main message and allowing bold tags for the number
    $success_message = htmlspecialchars("Old Gold transaction saved successfully. Bill of Supply No: ") . "<strong>" . htmlspecialchars($bos_number) . "</strong>";
}

?>
<!-- Add Print Specific CSS -->
<style>
    @media print {
        body * { visibility: hidden; }
        .no-print, .no-print * { display: none !important; }
        #printable-bos-area, #printable-bos-area * { visibility: visible !important; }
        #printable-bos-area {
            position: absolute !important;
            left: 0 !important;
            top: 0 !important;
            width: 100% !important; /* Use % for better scaling */
            max-width: 800px; /* Optional: limit max width on print */
            margin: 0 auto !important; /* Center on print */
            padding: 15px !important; /* Adjust padding */
            border: 1px solid #aaa !important; /* Lighter border for print */
            font-size: 9pt !important; /* Smaller base font */
            background-color: white !important;
            box-shadow: none !important;
        }
        #printable-bos-area table, #printable-bos-area th, #printable-bos-area td {
            border: 1px solid black !important;
            border-collapse: collapse !important;
            padding: 3px 5px !important; /* Tighter padding */
            font-size: 8pt !important; /* Even smaller font for table */
            word-break: break-word; /* Prevent long words overflowing */
        }
         #printable-bos-area th {
            background-color: #eee !important;
            -webkit-print-color-adjust: exact; /* Required for Chrome */
            color-adjust: exact; /* Standard property */
            font-weight: bold;
            text-align: center;
        }
        .print-logo { filter: none !important; }
        .page-break { page-break-before: always; }
        h1, h4 { margin: 5px 0; }
        h1 { font-size: 13pt !important; } /* Slightly smaller heading */
        p, div, span { font-size: 8pt !important; line-height: 1.3; } /* Smaller text */
        strong, b { font-weight: bold; } /* Ensure bold prints */
        .grid-container { /* Helper for grid layout */
             display: grid !important;
             grid-template-columns: auto 1fr auto 1fr !important; /* Adjust column widths */
             gap: 3px 8px !important; /* Adjust gaps */
        }
        .grid-span-3 { grid-column: span 3 !important; }
         /* Ensure footer grid prints correctly */
        .footer-grid-container {
             display: grid !important;
             grid-template-columns: 1fr 1fr !important;
             gap: 8px 15px !important; /* Adjust gaps */
             margin-top: 2rem !important;
        }
        /* Hide scrollbars during print */
        html, body {
             height: auto;
             overflow: visible; /* Changed from hidden to visible for print */
        }
    }
    /* Standard screen styles */
    .text-success { color: #198754 !important; }
    .text-danger { color: #dc3545 !important; }
    /* Ensure footer doesn't overlap on print preview */
    html, body { height: auto; }

    /* Improve table readability on screen */
    .table th { background-color: #f8f9fa; }
    .table td.text-end { text-align: right; }
    .table td.fw-bold { font-weight: bold; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 no-print">
    <h2>Old Gold Purchase Entry (v2)</h2> <!-- Updated Title for clarity -->
    <a href="tools/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Tools</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger no-print" role="alert"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success no-print" role="alert"><?php echo $success_message; // Contains safe HTML ?></div><?php endif; ?>


<!-- Display Gold Rate Warning if needed -->
<?php if (!$gold_rate_check_passed && !empty($rate_warning_message)): ?>
    <div class="alert alert-warning no-print" role="alert">
        <i data-lucide="alert-triangle" class="me-2"></i> <?php echo $rate_warning_message; // Contains safe HTML ?>
    </div>
<?php endif; ?>

<!-- Entry Form -->
<!-- *** IMPORTANT: Form action points to v2 file name *** -->
<form action="tools/old_gold_v2.php" method="post" class="needs-validation no-print" novalidate>
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">
            <h4>Enter Old Gold Transaction Details</h4>
        </div>
        <div class="card-body">
            <div class="row g-3">
                 <!-- Transaction Date and Store -->
                <div class="col-md-4">
                    <label for="transaction_date" class="form-label">Transaction Date*</label>
                    <input type="date" class="form-control" id="transaction_date" name="transaction_date" value="<?php echo date('Y-m-d'); ?>" required max="<?php echo date('Y-m-d'); ?>">
                    <div class="invalid-feedback">Please select a valid date (today or past).</div>
                </div>
                <div class="col-md-8">
                    <label for="store_id" class="form-label">Store / Cost Center*</label>
                    <select id="store_id" name="store_id" class="form-select" required>
                        <option value="" disabled selected>-- Select Store --</option>
                        <?php foreach ($my_stores as $store): ?>
                            <option value="<?php echo $store['id']; ?>" data-store-code="<?php echo htmlspecialchars($store['store_code']); ?>">
                                <?php echo htmlspecialchars($store['store_name']) . ' (' . htmlspecialchars($store['store_code']) . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                        <?php if (empty($my_stores)): ?><option value="" disabled>No stores assigned. Contact Admin.</option><?php endif; ?>
                    </select>
                     <div class="invalid-feedback">Please select a store.</div>
                </div>

                 <!-- Customer Details -->
                <div class="col-12"><hr><h5 class="mt-2">Customer Details</h5></div>
                <div class="col-md-4">
                    <label for="customer_name" class="form-label">Customer Name*</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name" required pattern="\S+.*" maxlength="250">
                     <div class="invalid-feedback">Please enter customer name.</div>
                </div>
                <div class="col-md-4">
                    <label for="customer_mobile" class="form-label">Mobile No*</label>
                    <input type="tel" class="form-control" id="customer_mobile" name="customer_mobile" pattern="[6-9][0-9]{9}" title="Enter 10 digit Indian mobile number starting with 6, 7, 8, or 9" required maxlength="10">
                     <div class="invalid-feedback">Please enter a valid 10-digit mobile number.</div>
                </div>
                <div class="col-md-4">
                    <label for="customer_pan" class="form-label">PAN Card No*</label>
                    <input type="text" class="form-control" id="customer_pan" name="customer_pan" pattern="[A-Z]{5}[0-9]{4}[A-Z]{1}" title="Enter valid PAN format (ABCDE1234F)" style="text-transform: uppercase;" required maxlength="10">
                     <div class="invalid-feedback">Please enter a valid PAN number (e.g., ABCDE1234F).</div>
                </div>
                <div class="col-12">
                    <label for="customer_address" class="form-label">Customer Address</label>
                    <textarea class="form-control" id="customer_address" name="customer_address" rows="2"></textarea>
                </div>

                 <!-- Gold Details -->
                <div class="col-12"><hr><h5 class="mt-2">Gold Details</h5></div>
                <div class="col-12">
                    <label for="item_description" class="form-label">Item Description*</label>
                    <input type="text" class="form-control" id="item_description" name="item_description" placeholder="e.g., Old Gold Chain, Bangles (2pcs)" required pattern="\S+.*">
                    <div class="invalid-feedback">Please describe the item(s).</div>
                </div>
                <div class="col-md-6">
                    <label for="gross_weight_before" class="form-label">Gross Weight Before Melting (gms)</label>
                    <input type="number" step="0.001" class="form-control" id="gross_weight_before" name="gross_weight_before" min="0">
                </div>
                <div class="col-md-6">
                    <label for="purity_before" class="form-label">Purity Before Melting (%)</label>
                    <input type="number" step="0.01" min="0" max="100" class="form-control" id="purity_before" name="purity_before">
                </div>
                <div class="col-md-4">
                    <label for="gross_weight_after" class="form-label">Gross Weight After Melting (gms)*</label>
                    <input type="number" step="0.001" min="0.001" class="form-control" id="gross_weight_after" name="gross_weight_after" required>
                    <div class="invalid-feedback">Weight must be greater than 0 (e.g., 10.123).</div>
                </div>
                <div class="col-md-4">
                    <label for="purity_after" class="form-label">Purity After Melting (%)*</label>
                    <input type="number" step="0.01" min="0.01" max="100" class="form-control" id="purity_after" name="purity_after" required>
                    <div class="invalid-feedback">Purity must be between 0.01 and 100.</div>
                </div>
                <div class="col-md-4">
                    <label for="deduction_addition" class="form-label">Deduction(-) / Addition(+) (gms)</label>
                    <input type="number" step="0.001" class="form-control" id="deduction_addition" name="deduction_addition" placeholder="e.g., -0.100 for dust">
                </div>
                 <div class="col-12">
                    <label for="remarks" class="form-label">Remarks</label>
                    <textarea class="form-control" id="remarks" name="remarks" rows="2"></textarea>
                </div>

                 <!-- Calculations Display -->
                <div class="col-12 mt-3 p-3 bg-light border rounded">
                     <h6 class="text-muted">Calculations</h6>
                    <p class="mb-1"><strong>Current Gold Rate:</strong> Rs. <span id="current_rate_display"><?php echo number_format((float)$current_gold_rate, 2); ?></span> /gm</p>
                    <p class="mb-1"><strong>Calculated Net Weight (After Melting):</strong> <span id="calc_net_weight" class="fw-bold">0.000</span> gms</p>
                    <hr class="my-1">
                    <p class="mb-0"><strong>Calculated Final Value:</strong> Rs. <span id="calc_final_value" class="fw-bold text-success fs-5">0.00</span></p>
                </div>
            </div>
        </div>
        <div class="card-footer text-end">
            <button type="submit" name="save_transaction" class="btn btn-primary btn-lg"><i data-lucide="save" class="me-2"></i>Save Transaction</button>

        </div>
    </div>
</form>

<!-- Report Section -->
<div class="card mt-5 no-print">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>Transaction Report</h4>
         <!-- Optional: Add Print Report Button if needed
         <button class="btn btn-sm btn-outline-secondary" onclick="window.print();"><i data-lucide="printer" class="me-1"></i> Print View</button>
         -->
    </div>
    <div class="card-body">
        <!-- Report Filter -->
        <!-- *** IMPORTANT: Form action points to v2 file name *** -->
        <form action="tools/old_gold_v2.php" method="get" class="row g-3 align-items-end mb-3">
             <div class="col-md-4">
                 <label for="store_id_filter" class="form-label">Filter by Store</label>
                 <select id="store_id_filter" name="store_id_filter" class="form-select form-select-sm">
                     <option value="all">All My Stores</option>
                     <?php foreach ($my_stores as $store): ?>
                         <option value="<?php echo $store['id']; ?>" <?php if ($report_store_filter == $store['id']) echo 'selected'; ?>>
                             <?php echo htmlspecialchars($store['store_name']); ?>
                         </option>
                     <?php endforeach; ?>
                      <?php if (empty($my_stores) && !has_any_role(['admin', 'platform_admin'])): ?>
                         <option value="" disabled>No stores assigned</option>
                      <?php elseif (has_any_role(['admin', 'platform_admin'])): ?>
                         <option value="all_admin" <?php if ($report_store_filter == 'all_admin') echo 'selected'; ?>>-- All Company Stores (Admin) --</option>
                         <?php
                            // Fetch all stores for admin dropdown
                            $all_company_stores = [];
                            if (isset($conn) && $conn instanceof mysqli) { // Check if conn is valid
                                $sql_all = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
                                if($stmt_all = $conn->prepare($sql_all)){
                                    $stmt_all->bind_param("i", $company_id_context);
                                    $stmt_all->execute();
                                    $res_all = $stmt_all->get_result();
                                    while($r = $res_all->fetch_assoc()) $all_company_stores[] = $r;
                                    $stmt_all->close();
                                }
                            }
                            foreach($all_company_stores as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php if ($report_store_filter == $s['id']) echo 'selected'; ?>>
                                     <?php echo htmlspecialchars($s['store_name']); ?>
                                </option>
                         <?php endforeach; ?>
                      <?php endif; ?>
                 </select>
             </div>
             <div class="col-md-3">
                 <label for="start_date" class="form-label">Start Date</label>
                 <input type="date" id="start_date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($report_start_date); ?>">
             </div>
             <div class="col-md-3">
                 <label for="end_date" class="form-label">End Date</label>
                 <input type="date" id="end_date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($report_end_date); ?>">
             </div>
             <div class="col-md-2">
                 <button type="submit" class="btn btn-info btn-sm w-100"><i data-lucide="filter" class="me-1"></i>Filter</button>
             </div>
        </form>
        <hr>
        <!-- Report Table -->
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm caption-top small">
                <caption class="text-muted">Displaying transactions from <?php echo htmlspecialchars(date('d-M-Y', strtotime($report_start_date))); ?> to <?php echo htmlspecialchars(date('d-M-Y', strtotime($report_end_date))); ?></caption>
                <thead class="table-light">
                    <tr>
                        <th>Date</th>
                        <th>Store</th>
                        <th>BOS No.</th>
                        <th>Customer</th>
                        <th>PAN</th>
                        <th class="text-end">Net Wt (g)</th>
                        <th class="text-end">Rate (/g)</th>
                        <th class="text-end">Value (Rs.)</th>
                        <th>Processed By</th>
                        <th class="no-print text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_transactions)): ?>
                        <tr><td colspan="10" class="text-center text-muted fst-italic py-3">No transactions found for the selected criteria.</td></tr>
                    <?php else: ?>
                        <?php foreach ($all_transactions as $tx): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('d-M-Y', strtotime($tx['transaction_date']))); ?></td>
                            <td><?php echo htmlspecialchars($tx['store_name']); ?></td>
                            <td><?php echo htmlspecialchars($tx['bill_of_supply_no']); ?></td>
                            <td><?php echo htmlspecialchars($tx['customer_name']); ?></td>
                            <td><?php echo htmlspecialchars($tx['customer_pan']); ?></td>
                            <td class="text-end"><?php echo number_format($tx['net_weight_calculated'], 3); ?></td>
                            <td class="text-end"><?php echo number_format($tx['gold_rate_applied'], 2); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format($tx['final_value'], 2); ?></td>
                            <td><?php echo htmlspecialchars($tx['employee_name']); ?></td>
                            <td class="no-print text-center">
                                <button type="button" class="btn btn-outline-primary btn-sm p-1" onclick="generateBillOfSupply(this)"
                                    data-tx='<?php echo htmlspecialchars(json_encode($tx), ENT_QUOTES, 'UTF-8'); ?>' title="Print Bill of Supply for <?php echo htmlspecialchars($tx['bill_of_supply_no']); ?>">
                                    <i data-lucide="printer" style="width: 16px; height: 16px;"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                 <?php if (!empty($all_transactions)):
                     // Calculate Totals
                     $total_net_weight = array_sum(array_column($all_transactions, 'net_weight_calculated'));
                     $total_final_value = array_sum(array_column($all_transactions, 'final_value'));
                 ?>
                 <tfoot class="table-light fw-bold">
                     <tr>
                         <td colspan="5" class="text-end">Report Totals:</td>
                         <td class="text-end"><?php echo number_format($total_net_weight, 3); ?> g</td>
                         <td></td> <!-- Empty cell for Rate column -->
                         <td class="text-end"><?php echo number_format($total_final_value, 2); ?> Rs.</td>
                         <td colspan="2"></td> <!-- Span across Processed By and Action -->
                     </tr>
                 </tfoot>
                 <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<!-- Hidden Printable Area for BOS -->
<div id="printable-bos-area" style="display: none; border: 1px solid #ccc; font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: auto; background-color: white;">
     <div style="text-align: center; margin-bottom: 20px;">
         <!-- Use a placeholder if the logo might be missing -->
         <img src="assets/logo.png" alt="Company Logo" class="print-logo" style="width: 150px; height: auto;" onerror="this.style.display='none'; this.onerror=null;">
         <h4 style="margin-top: 5px; font-size: 10pt;"><?php echo htmlspecialchars($company_details['company_name'] ?? 'Your Company Name'); ?></h4>
         <p style="font-size: 8pt; margin: 2px 0;"><?php echo htmlspecialchars($company_details['address'] ?? 'Company Address'); ?></p>
         <p style="font-size: 8pt; margin: 2px 0;"><?php echo htmlspecialchars($company_details['contact_info'] ?? 'Contact Info'); ?></p>
     </div>
     <h1 style="text-align: center; font-size: 14pt; font-weight: bold; margin-bottom: 20px; border-bottom: 1px solid #000; padding-bottom: 5px;">Bill of Supply (Old Gold Purchase)</h1>
     <div style="font-size: 9pt; margin-bottom: 15px; border: 1px solid black; padding: 10px;" class="grid-container">
         <b style="grid-column: 1;">Date:</b>              <span id="print_bos_date" style="grid-column: 2;"></span>
         <b style="grid-column: 3;">Bill No.:</b>         <span id="print_bos_bill_no" style="grid-column: 4;"></span>

         <b style="grid-column: 1;">Customer Name:</b>    <span id="print_bos_customer_name" style="grid-column: 2;"></span>
         <b style="grid-column: 3;">PAN:</b>              <span id="print_bos_pan" style="grid-column: 4;"></span>

         <b style="grid-column: 1;">Mobile:</b>          <span id="print_bos_mobile" style="grid-column: 2;"></span>
         <b style="grid-column: 3;">Place of Supply:</b> <span id="print_bos_place_of_supply" style="grid-column: 4;"></span>

         <b style="grid-column: 1;">Customer Address:</b> <span id="print_bos_address" class="grid-span-3" style="grid-column: 2 / span 3;"></span>
     </div>

     <table style="width: 100%; border-collapse: collapse; border: 1px solid black; margin-bottom: 1rem; font-size: 8pt;">
         <thead style="background-color: #eee !important; font-weight: bold;">
             <tr>
                 <th style="border: 1px solid black; padding: 3px 5px;">SNo</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">HSN</th>
                 <th style="border: 1px solid black; padding: 3px 5px; text-align: left;">Description</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">Purity(%)</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">Gross Wt(g)</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">Net Wt(g)</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">Rate(/g)</th>
                 <th style="border: 1px solid black; padding: 3px 5px;">Value(INR)</th>
             </tr>
         </thead>
         <tbody>
             <tr>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: center;">1</td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: center;">7113</td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: left;" id="print_bos_description"></td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: center;" id="print_bos_purity"></td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: right;" id="print_bos_gross_weight"></td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: right;" id="print_bos_net_weight"></td>
                  <td style="border: 1px solid black; padding: 3px 5px; text-align: right;" id="print_bos_rate"></td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: right; font-weight: bold;" id="print_bos_value"></td>
             </tr>
             <!-- Optional: Add empty rows for spacing if needed -->
             <tr><td style="border: 1px solid black; padding: 10px;" colspan="8">&nbsp;</td></tr>
             <tr><td style="border: 1px solid black; padding: 10px;" colspan="8">&nbsp;</td></tr>

             <tr style="background-color: #eee !important; font-weight: bold;">
                 <td style="border: 1px solid black; padding: 3px 5px; font-weight: bold;" colspan="7">Total Value (in words): <span id="print_bos_total_in_words" style="font-weight: normal; font-style: italic;"></span></td>
                 <td style="border: 1px solid black; padding: 3px 5px; text-align: right; font-weight: bold;" id="print_bos_total_value"></td>
             </tr>
         </tbody>
     </table>
     <p style="font-size: 7pt; line-height: 1.2; margin-bottom: 1rem; text-align: justify;">
         I hereby declare that the aforesaid Gold - Jewellery / Coin / Bar, Diamond â€“ Studded Jewellery/ Loose Diamond , Silver -jewellery / Coin / Bar / Others, Platinum jewellery , Setting jewellery is my personal property and not use for any furtherance of business & commerce . I also hereby agree to the above valuation of the Gold - jewellery / Coin / Bar/ Diamond- Studded Jewellery/ Loose Diamond , Silver - jewellery / Coin/ Bar/ Others , Platinum jewellery , Setting jewellery offered to me by <?php echo htmlspecialchars($company_details['company_name'] ?? 'the Company'); ?> and I have no objection to the same. I understand and accept that there may be changes of shape and weight while processing the jewellery / Gold brought by me for ascertain the purity. Also I agree that any Stone, precious or semi-precious or artificial can be damaged while unsetting from the jewellery, in the ascertaining the purity and final weight. I understand the company may not accept any lower purity at or below 9 karat (37.5%). I agree that once the jewellery/ Gold is melted for ascertaining purity transaction is completed and the company is not bound to reverse the transaction.
     </p>

     <div style="margin-top: 3rem;" class="footer-grid-container">
         <div><b>Prepared by (Employee):</b> <span id="print_bos_employee_name"></span></div>
         <div><b>Signature of Supplier/Customer:</b><br><br>______________________</div>
         <div><b>Store Name:</b> <span id="print_bos_store_name"></span></div>
         <div><b>Date:</b> <span id="print_bos_signature_date"></span></div>
         <div><b>Product Checked by:</b> <span id="print_bos_product_checked_by"></span></div>
          <div><b>Authorised Signatory:</b> <br><br>______________________</div>
     </div>
</div>


<script>
    // --- Calculations ---
    const gwAfterInput = document.getElementById('gross_weight_after');
    const purityAfterInput = document.getElementById('purity_after');
    const calcNetWeightSpan = document.getElementById('calc_net_weight');
    const calcFinalValueSpan = document.getElementById('calc_final_value');
    const currentRateSpan = document.getElementById('current_rate_display'); // Added span for current rate
    const currentGoldRate = parseFloat(currentRateSpan?.textContent.replace(/[^0-9.]/g, '')) || 0;


    function calculateValues() {
        // Ensure elements exist before accessing value
        const gwAfter = parseFloat(gwAfterInput?.value) || 0;
        const purityAfter = parseFloat(purityAfterInput?.value) || 0;

        let finalValue = 0;
        let netWeight = 0;

        if (gwAfter > 0 && purityAfter > 0 && purityAfter <= 100) { // Added purity upper bound check
            netWeight = (gwAfter * purityAfter) / 100.0;
             // Only calculate value if rate is positive
            if (currentGoldRate > 0) {
                 finalValue = netWeight * currentGoldRate;
            }
            if (calcNetWeightSpan) calcNetWeightSpan.textContent = netWeight.toFixed(3);
            // Format final value with Indian locale (commas)
            if (calcFinalValueSpan) calcFinalValueSpan.textContent = finalValue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } else {
            if (calcNetWeightSpan) calcNetWeightSpan.textContent = '0.000';
            if (calcFinalValueSpan) calcFinalValueSpan.textContent = '0.00';
        }
    }

    // Add event listeners if the elements exist
    if (gwAfterInput) {
        gwAfterInput.addEventListener('input', calculateValues);
        gwAfterInput.addEventListener('change', calculateValues); // Also calculate on change (e.g., using arrows)
    }
     if (purityAfterInput) {
        purityAfterInput.addEventListener('input', calculateValues);
        purityAfterInput.addEventListener('change', calculateValues);
    }


    // --- BOS Printing ---
     function numberToWords(num) {
        num = Math.round(num); // Ensure integer for conversion
        const a = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        const b = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        function inWords(n) {
            let str = '';
            if (n === 0) return '';
            if (n >= 100) {
                str += a[Math.floor(n / 100)] + ' Hundred ';
                n %= 100;
            }
            if (n > 0) {
                 if (str !== '') str += 'and ';
                 if (n < 20) {
                     str += a[n];
                 } else {
                     str += b[Math.floor(n / 10)];
                     if (n % 10 > 0) {
                         str += '-' + a[n % 10];
                     }
                 }
            }
            return str.trim();
        }

        if (num === 0) return 'Zero Rupees Only';
        let res = '';
        const crore = Math.floor(num / 10000000);
        num %= 10000000;
        const lakh = Math.floor(num / 100000);
        num %= 100000;
        const thousand = Math.floor(num / 1000);
        num %= 1000;
        const hundreds = num;

        if (crore > 0) res += inWords(crore) + ' Crore ';
        if (lakh > 0) res += inWords(lakh) + ' Lakh ';
        if (thousand > 0) res += inWords(thousand) + ' Thousand ';
        if (hundreds > 0) res += inWords(hundreds);

        // Capitalize first letter and ensure proper spacing
        res = res.trim().replace(/\s+/g, ' '); // Consolidate spaces
        return res.charAt(0).toUpperCase() + res.slice(1) + ' Rupees Only';
     }


    function generateBillOfSupply(button) {
        try {
            const txDataString = button.getAttribute('data-tx');
            if (!txDataString) {
                console.error("No data-tx attribute found on button.");
                alert("Error: Transaction data not found. Cannot print BOS.");
                return;
            }
             // console.log("Raw txData string:", txDataString); // Debugging: Check raw data
            const txData = JSON.parse(txDataString);
             // console.log("Parsed txData object:", txData); // Debugging: Check parsed data

            const printableArea = document.getElementById('printable-bos-area');
            if (!printableArea) {
                 console.error("Printable area '#printable-bos-area' not found in the HTML.");
                 alert("Error: Printable area element missing. Cannot print BOS.");
                 return;
            }

             // --- Safely populate fields, checking if elements exist ---
             // Helper function to set text content safely
             const setText = (id, value) => {
                 const element = document.getElementById(id);
                 if (element) {
                     element.textContent = value !== null && value !== undefined ? value : 'N/A';
                 } else {
                     console.warn(`Element with ID "${id}" not found for printing.`);
                 }
             };

             // Format date consistently
             const transactionDate = txData.transaction_date ? new Date(txData.transaction_date + 'T00:00:00') : new Date(); // Use T00:00:00 to avoid timezone issues
             const formattedDate = transactionDate.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' }); // e.g., 26-Oct-2025


             setText('print_bos_date', formattedDate);
             setText('print_bos_customer_name', txData.customer_name);
             setText('print_bos_bill_no', txData.bill_of_supply_no);
             setText('print_bos_address', txData.customer_address || ''); // Use empty string if null/undefined
             setText('print_bos_place_of_supply', txData.store_name || '');
             setText('print_bos_pan', txData.customer_pan);
             setText('print_bos_mobile', txData.customer_mobile);

             // Item Row 1
             setText('print_bos_description', txData.item_description);
             setText('print_bos_purity', txData.purity_after ? parseFloat(txData.purity_after).toFixed(2) + '%' : '');
             setText('print_bos_gross_weight', txData.gross_weight_after ? parseFloat(txData.gross_weight_after).toFixed(3) + ' g' : '');
             setText('print_bos_net_weight', txData.net_weight_calculated ? parseFloat(txData.net_weight_calculated).toFixed(3) + ' g' : '');
             setText('print_bos_rate', txData.gold_rate_applied ? parseFloat(txData.gold_rate_applied).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '');
             setText('print_bos_value', txData.final_value ? parseFloat(txData.final_value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : '');

             // Totals
             const totalValue = parseFloat(txData.final_value) || 0;
             setText('print_bos_total_value', totalValue.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
             setText('print_bos_total_in_words', numberToWords(totalValue)); // Uses rounded value inside function

             // Footer details
             setText('print_bos_employee_name', txData.employee_name);
             setText('print_bos_store_name', txData.store_name);
             setText('print_bos_product_checked_by', txData.employee_name); // Assuming same person
             setText('print_bos_signature_date', formattedDate); // Use same formatted date


             // Show the printable area and trigger print
             printableArea.style.display = 'block';

             // Small delay to ensure content is rendered before printing
             setTimeout(() => {
                 try {
                     window.print();
                 } catch(printError) {
                      console.error("Error during window.print():", printError);
                      alert("Could not initiate print dialog. Please check browser settings or console.");
                 } finally {
                     // Ensure it gets hidden even if print fails
                    printableArea.style.display = 'none'; // Hide again after printing attempt
                 }
             }, 150); // 150ms delay

        } catch (e) {
            console.error("Error generating Bill of Supply:", e);
            // Provide more specific feedback if possible
            let userMessage = "An error occurred while trying to generate the Bill of Supply.";
            if (e instanceof SyntaxError) {
                 userMessage += " There might be an issue with the transaction data format.";
            }
            userMessage += " Please check the console for technical details.";
            alert(userMessage);
             // Ensure printable area is hidden if error occurs before print
            const printableArea = document.getElementById('printable-bos-area');
            if(printableArea) printableArea.style.display = 'none';
        }
    }

    // Add DOMContentLoaded listener
    document.addEventListener('DOMContentLoaded', () => {
        // Load Lucide Icons
        if (typeof lucide !== 'undefined') {
            try {
                lucide.createIcons();
            } catch (e) {
                console.error("Lucide icon creation failed:", e);
                // Optionally add fallback icons or text
            }
        } else {
             console.warn("Lucide library not found.");
        }


        // Initial calculation on page load
        calculateValues();

        // Bootstrap form validation
        // Fetch all the forms we want to apply custom Bootstrap validation styles to
        const forms = document.querySelectorAll('.needs-validation');

        // Loop over them and prevent submission
        Array.from(forms).forEach(form => {
            form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                 // Optional: Scroll to the first invalid field
                 const firstInvalid = form.querySelector(':invalid');
                 if(firstInvalid) {
                    firstInvalid.focus();
                    // Optionally add a small visual cue like a temporary border change
                 }
            }

            form.classList.add('was-validated');
            }, false);
        });
    });

</script>

<?php
// Ensure connection is closed if it was opened
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
// Correct path for footer in tools subfolder
require_once __DIR__ . '/../includes/footer.php'; // Use absolute path based on current file location
?>



