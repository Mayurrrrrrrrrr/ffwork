<?php
// Use the new purchase header
require_once 'includes/header.php';

// -- SECURITY CHECK: ENSURE USER CAN INITIATE REQUESTS --
if (!has_any_role(['sales_team', 'admin', 'platform_admin'])) {
    // Redirect non-authorized users away
    header("location: " . BASE_URL . "purchase/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$company_id_context = get_current_company_id();
$user_id = $_SESSION['user_id'];

// --- FORM HANDLING: CREATE NEW PURCHASE REQUEST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_request'])) {
    // (Omitted platform admin check for brevity - it's in your file)
    if(check_role('platform_admin')){
        $error_message = "Platform Admin cannot create requests directly. Please log in as a Company Admin.";
    } 
    elseif (empty($company_id_context)) {
         $error_message = "Cannot determine your company. Session invalid.";
    } else {
        $customer_name = trim($_POST['customer_name']);
        $order_source = trim($_POST['order_source']);
        $requested_designs = trim($_POST['requested_designs']); // Can be a simple text block for now

        if (empty($customer_name) || empty($order_source)) {
            $error_message = "Customer Name and Order Source are required.";
        } else {
            // Insert into purchase_orders table
            $sql_insert = "INSERT INTO purchase_orders 
                           (company_id, initiated_by_user_id, customer_name, order_source, status, requested_designs)
                           VALUES (?, ?, ?, ?, 'New Request', ?)";
            
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("iisss", $company_id_context, $user_id, $customer_name, $order_source, $requested_designs);
                if ($stmt_insert->execute()) {
                    $new_po_id = $conn->insert_id;
                    // Log the action
                    log_audit_action($conn, 'purchase_request_created', "User created new purchase request. Customer: {$customer_name}", $user_id, $company_id_context, 'purchase_order', $new_po_id);
                    
                    // --- REDIRECT TO THE NEW MANAGE PO PAGE ---
                    // This is the usability improvement.
                    header("location: " . BASE_URL . "purchase/manage_po.php?po_id={$new_po_id}&success=created");
                    exit;

                } else {
                    $error_message = "Database error creating request: " . $stmt_insert->error;
                }
                $stmt_insert->close();
            } else {
                 $error_message = "Database prepare error: " . $conn->error;
            }
        }
    }
}

?>

<h2>Initiate New Purchase Request</h2>
<p>Create a new purchase lead to be processed by the Order Team.</p>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h4>New Request Details</h4></div>
    <div class="card-body">
        <form action="purchase/new_request.php" method="post">
            <div class="row g-3">
                <div class="col-md-6">
                    <label for="customer_name" class="form-label">Customer Name</label>
                    <input type="text" class="form-control" id="customer_name" name="customer_name" required>
                </div>
                <div class="col-md-6">
                    <label for="order_source" class="form-label">Order Source</label>
                    <select class="form-select" id="order_source" name="order_source" required>
                        <option value="">-- Select Source --</option>
                        <option value="Store Walk-in">Store Walk-in</option>
                        <option value="Exhibition">Exhibition</option>
                        <option value="Website Inquiry">Website Inquiry</option>
                        <option value="Referral">Referral</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="col-12">
                    <label for="requested_designs" class="form-label">Requested Designs / Notes</label>
                    <textarea class="form-control" id="requested_designs" name="requested_designs" rows="4" placeholder="e.g., 2x Ring Design #R123, 1x Necklace #N456 (custom size)"></textarea>
                    <div class="form-text">Provide any details about the designs, quantities, or customer requests.</div>
                </div>
            </div>
            <button type="submit" name="create_request" class="btn btn-primary mt-4">Submit Request</button>
        </form>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



