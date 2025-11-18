<?php
// Use the new purchase header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// --- 1. GET DATA ---
$po_id = $_GET['po_id'] ?? null;
$error_message = '';
$success_message = '';
$po_details = null;
$po_items = [];
$jewel_inventory = [];
$vendors = [];
$is_platform_admin = check_role('platform_admin');
$all_items_inwarded = false; // Flag for "Inward Complete" button
$all_items_qcd = true; // Flag for "QC Complete" button
$all_qc_passed_items_imaged = true; // Flag for "Imaging Complete" button

if (!$po_id || !is_numeric($po_id)) {
    header("location: " . BASE_URL . "purchase/index.php");
    exit;
}

// Check for success message from redirect
if(isset($_GET['success'])){
    if($_GET['success'] == 'created') $success_message = "New purchase request created. Please place the order.";
    if($_GET['success'] == 'inwarded') $success_message = "Jewel item inwarded successfully.";
    if($_GET['success'] == 'qc_updated') $success_message = "Item QC status updated.";
    if($_GET['success'] == 'imaging_updated') $success_message = "Item imaging status updated.";
    if($_GET['success'] == 'pf_generated') $success_message = "Purchase File marked as generated.";
    if($_GET['success'] == 'accounts_verified') $success_message = "Accounts have verified the purchase.";
    if($_GET['success'] == 'invoice_logged') $success_message = "Vendor invoice has been logged.";
    if($_GET['success'] == 'po_complete') $success_message = "Purchase Order marked as complete.";
    if($_GET['success'] == 'delivered') $success_message = "Order marked as delivered to customer.";
}

// --- 2. FORM ACTION HANDLING (Processing logic) ---
$company_id_for_action = 0;
if($is_platform_admin && $po_id) {
    // Platform admin needs to know which company this PO belongs to for logging/invoice creation
    $stmt_get_cid = $conn->prepare("SELECT company_id FROM purchase_orders WHERE id = ?");
    $stmt_get_cid->bind_param("i", $po_id);
    $stmt_get_cid->execute();
    $company_id_for_action = $stmt_get_cid->get_result()->fetch_assoc()['company_id'] ?? 0;
    $stmt_get_cid->close();
} else {
    $company_id_for_action = $company_id_context;
}


// -- ACTION 1: Place Order (Order Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_place_order'])) {
    if (has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $vendor_id = $_POST['vendor_id']; $po_number = trim($_POST['po_number']); $target_date = $_POST['target_date'];
        $design_codes = $_POST['design_code'] ?? []; $quantities = $_POST['quantity'] ?? [];
        if (!empty($vendor_id) && !empty($po_number) && !empty($target_date) && !empty($design_codes) && count($design_codes) === count($quantities)) {
            $conn->begin_transaction();
            try {
                $sql_po_update = "UPDATE purchase_orders SET vendor_id = ?, po_number = ?, target_date = ?, status = 'Order Placed' WHERE id = ? AND status = 'New Request' " . ($is_platform_admin ? "" : "AND company_id = ?");
                $stmt_po_update = $conn->prepare($sql_po_update);
                if($is_platform_admin) $stmt_po_update->bind_param("issi", $vendor_id, $po_number, $target_date, $po_id);
                else $stmt_po_update->bind_param("issii", $vendor_id, $po_number, $target_date, $po_id, $company_id_context);
                if(!$stmt_po_update->execute() || $stmt_po_update->affected_rows == 0){ throw new Exception("Failed to update PO status. It may have already been processed."); }
                $stmt_po_update->close();
                $sql_item_insert = "INSERT INTO po_items (po_id, design_code, quantity) VALUES (?, ?, ?)";
                $stmt_item = $conn->prepare($sql_item_insert);
                $total_items_count = 0;
                for ($i = 0; $i < count($design_codes); $i++) {
                    if(!empty($design_codes[$i]) && !empty($quantities[$i]) && $quantities[$i] > 0){
                        $stmt_item->bind_param("isi", $po_id, $design_codes[$i], $quantities[$i]);
                        if(!$stmt_item->execute()) throw new Exception("Failed to save PO items: " . $stmt_item->error);
                        $total_items_count += $quantities[$i];
                    }
                } $stmt_item->close();
                $conn->commit();
                $success_message = "Purchase Order placed successfully with {$total_items_count} items.";
                log_audit_action($conn, 'po_placed', "PO #{$po_number} placed with vendor ID {$vendor_id}. Total items: {$total_items_count}", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } catch (Exception $e) { $conn->rollback(); $error_message = $e->getMessage(); }
        } else { $error_message = "Please fill in all fields, including at least one design item."; }
    } else { $error_message = "You do not have permission to perform this action."; }
}

// -- ACTION 2: Receive Goods (Inventory Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_receive_goods'])) {
     if(has_any_role(['inventory_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $received_date = $_POST['received_date'];
        if(!empty($received_date)){
            $sql_receive = "UPDATE purchase_orders SET status = 'Goods Received', received_date = ? WHERE id = ? AND status = 'Order Placed' " . ($is_platform_admin ? "" : "AND company_id = ?");
            if($stmt_receive = $conn->prepare($sql_receive)){
                if($is_platform_admin) $stmt_receive->bind_param("si", $received_date, $po_id);
                else $stmt_receive->bind_param("sii", $received_date, $po_id, $company_id_context);
                if($stmt_receive->execute() && $stmt_receive->affected_rows > 0){
                    $success_message = "Goods marked as received. Ready for Inward process.";
                    log_audit_action($conn, 'po_goods_received', "Goods for PO ID {$po_id} marked as received.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                } else { $error_message = "Failed to update status. Report may be in an invalid state or already received."; }
                $stmt_receive->close();
            } else { $error_message = "DB Error: ".$conn->error; }
        } else { $error_message = "Received date is required."; }
    } else { $error_message = "You do not have permission to perform this action."; }
}

// -- ACTION 3: Inward a Single Jewel (Order Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_inward_item'])) {
    if(has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $po_item_id = $_POST['po_item_id']; $jewel_code = trim($_POST['jewel_code']);
        if(!empty($po_item_id) && !empty($jewel_code)){
            $conn->begin_transaction();
            try {
                $sql_check_code = "SELECT ji.id FROM jewel_inventory ji JOIN purchase_orders po ON ji.po_id = po.id WHERE ji.jewel_code = ? AND po.company_id = ?";
                $stmt_check = $conn->prepare($sql_check_code);
                $stmt_check->bind_param("si", $jewel_code, $company_id_for_action); 
                $stmt_check->execute(); $stmt_check->store_result();
                if($stmt_check->num_rows > 0) { throw new Exception("Jewel Code '{$jewel_code}' is already in use for this company."); }
                $stmt_check->close();
                $sql_check_qty = "SELECT quantity, quantity_received FROM po_items WHERE id = ? AND po_id = ?";
                $stmt_check_qty = $conn->prepare($sql_check_qty);
                $stmt_check_qty->bind_param("ii", $po_item_id, $po_id);
                $stmt_check_qty->execute();
                $item_qty_details = $stmt_check_qty->get_result()->fetch_assoc();
                $stmt_check_qty->close();
                if(!$item_qty_details) { throw new Exception("Invalid PO Item selected."); }
                if($item_qty_details['quantity_received'] >= $item_qty_details['quantity']) { throw new Exception("All items for this design have already been inwarded."); }
                $sql_insert_jewel = "INSERT INTO jewel_inventory (po_id, po_item_id, jewel_code, qc_status, image_status, inward_by_user_id) VALUES (?, ?, ?, 'Pending', 'Pending', ?)";
                $stmt_insert = $conn->prepare($sql_insert_jewel);
                $stmt_insert->bind_param("iisi", $po_id, $po_item_id, $jewel_code, $user_id);
                if(!$stmt_insert->execute()) { throw new Exception("Failed to save new jewel: " . $stmt_insert->error); }
                $stmt_insert->close();
                $sql_update_qty = "UPDATE po_items SET quantity_received = quantity_received + 1 WHERE id = ?";
                $stmt_update = $conn->prepare($sql_update_qty);
                $stmt_update->bind_param("i", $po_item_id);
                if(!$stmt_update->execute()) { throw new Exception("Failed to update PO item quantity: " . $stmt_update->error); }
                $stmt_update->close();
                $conn->commit();
                log_audit_action($conn, 'po_jewel_inwarded', "Jewel {$jewel_code} inwarded against PO ID {$po_id}", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                header("location: " . BASE_URL . "purchase/manage_po.php?po_id={$po_id}&success=inwarded");
                exit;
            } catch (Exception $e) { $conn->rollback(); $error_message = $e->getMessage(); }
        } else { $error_message = "Jewel Code and Item ID are required."; }
    } else { $error_message = "Permission denied."; }
}

// -- ACTION 4: Mark Inward Complete (Order Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mark_inward_complete'])) {
     if(has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $sql_complete = "UPDATE purchase_orders SET status = 'Inward Complete' WHERE id = ? AND status = 'Goods Received' " . ($is_platform_admin ? "" : "AND company_id = ?");
        if($stmt_complete = $conn->prepare($sql_complete)){
            if($is_platform_admin) $stmt_complete->bind_param("i", $po_id);
            else $stmt_complete->bind_param("ii", $po_id, $company_id_context);
            if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                $success_message = "Inward process marked complete. Ready for QC.";
                log_audit_action($conn, 'po_inward_complete', "PO ID {$po_id} marked as Inward Complete (Partial or Full).", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } else { $error_message = "Failed to update status. Already complete or invalid state."; }
            $stmt_complete->close();
        }
     } else { $error_message = "Permission denied."; }
}

// -- ACTION 5: Update QC Status (Order Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_qc'])) {
    if(has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $jewel_id = $_POST['jewel_id'];
        $qc_status = $_POST['action_update_qc']; // Value from button
        $qc_remarks = trim($_POST['qc_remarks'] ?? '');
        
        if(!empty($jewel_id) && in_array($qc_status, ['Pass', 'Fail'])){
            if($qc_status == 'Fail' && empty($qc_remarks)){ $error_message = "QC Remarks are required when failing an item."; } 
            else {
                if ($qc_status == 'Pass') $qc_remarks = ''; 
                $sql_qc_update = "UPDATE jewel_inventory SET qc_status = ?, qc_remarks = ? WHERE id = ? AND po_id = ?";
                if($stmt_qc = $conn->prepare($sql_qc_update)){
                    $stmt_qc->bind_param("ssii", $qc_status, $qc_remarks, $jewel_id, $po_id);
                    if($stmt_qc->execute() && $stmt_qc->affected_rows > 0){
                        log_audit_action($conn, 'po_jewel_qc_updated', "Jewel ID {$jewel_id} QC status set to {$qc_status}.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                        header("location: " . BASE_URL . "purchase/manage_po.php?po_id={$po_id}&success=qc_updated"); exit;
                    } else { $error_message = "Failed to update QC status. Item not found or status unchanged."; }
                    $stmt_qc->close();
                } else { $error_message = "DB Error (QC Update): ".$conn->error; }
            }
        } else { $error_message = "Invalid data provided for QC update."; }
    } else { $error_message = "Permission denied."; }
}

// -- ACTION 6: Mark QC Complete (Order Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mark_qc_complete'])) {
     if(has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) {
         $sql_check_qc = "SELECT COUNT(*) as pending_count FROM jewel_inventory WHERE po_id = ? AND qc_status = 'Pending'";
         $stmt_check_qc = $conn->prepare($sql_check_qc); $stmt_check_qc->bind_param("i", $po_id); $stmt_check_qc->execute();
         $pending_count = $stmt_check_qc->get_result()->fetch_assoc()['pending_count']; $stmt_check_qc->close();
         if($pending_count == 0) {
            $sql_check_fail = "SELECT COUNT(*) as fail_count FROM jewel_inventory WHERE po_id = ? AND qc_status = 'Fail'";
            $stmt_check_fail = $conn->prepare($sql_check_fail); $stmt_check_fail->bind_param("i", $po_id); $stmt_check_fail->execute();
            $fail_count = $stmt_check_fail->get_result()->fetch_assoc()['fail_count']; $stmt_check_fail->close();
            $new_po_status = ($fail_count > 0) ? 'QC Failed' : 'QC Passed'; 
            $sql_complete = "UPDATE purchase_orders SET status = ? WHERE id = ? AND status = 'Inward Complete' " . ($is_platform_admin ? "" : "AND company_id = ?");
            if($stmt_complete = $conn->prepare($sql_complete)){
                if($is_platform_admin) $stmt_complete->bind_param("si", $new_po_status, $po_id);
                else $stmt_complete->bind_param("sii", $new_po_status, $po_id, $company_id_context);
                if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                    $success_message = "QC process marked complete. Status set to {$new_po_status}.";
                    log_audit_action($conn, 'po_qc_complete', "PO ID {$po_id} marked as {$new_po_status}.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                } else { $error_message = "Failed to update status. Already complete or invalid state."; }
                $stmt_complete->close();
            }
         } else { $error_message = "Cannot mark complete: {$pending_count} item(s) are still pending QC."; }
     } else { $error_message = "Permission denied."; }
}

// -- ACTION 7: Update Image Status (Inventory Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_image_status'])) {
     if(has_any_role(['inventory_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $jewel_id = $_POST['jewel_id'];
        if(!empty($jewel_id)){
            $sql_img_update = "UPDATE jewel_inventory SET image_status = 'Completed' WHERE id = ? AND po_id = ? AND qc_status = 'Pass' AND image_status = 'Pending'";
            if($stmt_img = $conn->prepare($sql_img_update)){
                $stmt_img->bind_param("ii", $jewel_id, $po_id);
                if($stmt_img->execute() && $stmt_img->affected_rows > 0){
                    log_audit_action($conn, 'po_jewel_imaged', "Jewel ID {$jewel_id} marked as imaged.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                    header("location: " . BASE_URL . "purchase/manage_po.php?po_id={$po_id}&success=imaging_updated"); exit;
                } else { $error_message = "Failed to update image status. Item not found, not QC Passed, or already imaged."; }
                $stmt_img->close();
            } else { $error_message = "DB Error (Image Update): ".$conn->error; }
        } else { $error_message = "Invalid data provided for image status update."; }
    } else { $error_message = "Permission denied."; }
}

// -- ACTION 8: Mark Imaging Complete (Inventory Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_mark_imaging_complete'])) {
     if(has_any_role(['inventory_team', 'admin', 'platform_admin', 'purchase_head'])) {
         $sql_check_img = "SELECT COUNT(*) as pending_count FROM jewel_inventory WHERE po_id = ? AND qc_status = 'Pass' AND image_status = 'Pending'";
         $stmt_check_img = $conn->prepare($sql_check_img); $stmt_check_img->bind_param("i", $po_id); $stmt_check_img->execute();
         $pending_count = $stmt_check_img->get_result()->fetch_assoc()['pending_count']; $stmt_check_img->close();
         if($pending_count == 0) {
            $new_po_status = 'Imaging Complete';
            $sql_complete = "UPDATE purchase_orders SET status = ? WHERE id = ? AND status = 'QC Passed' " . ($is_platform_admin ? "" : "AND company_id = ?");
            if($stmt_complete = $conn->prepare($sql_complete)){
                if($is_platform_admin) $stmt_complete->bind_param("si", $new_po_status, $po_id);
                else $stmt_complete->bind_param("sii", $new_po_status, $po_id, $company_id_context);
                if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                    $success_message = "Imaging process marked complete.";
                    log_audit_action($conn, 'po_imaging_complete', "PO ID {$po_id} marked as {$new_po_status}.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                } else { $error_message = "Failed to update status. Already complete or invalid state."; }
                $stmt_complete->close();
            }
         } else { $error_message = "Cannot mark complete: {$pending_count} QC Passed item(s) are still pending imaging."; }
     } else { $error_message = "Permission denied."; }
}

// -- ACTION 9: Generate Purchase File (Purchase Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_generate_purchase_file'])) {
     if(has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])) {
         $sql_check = "SELECT status FROM purchase_orders WHERE id = ? AND status IN ('Imaging Complete', 'QC Failed')";
         $stmt_check = $conn->prepare($sql_check); $stmt_check->bind_param("i", $po_id); $stmt_check->execute();
         $result_check = $stmt_check->get_result();
         if($result_check->num_rows == 1){
            $new_po_status = 'Purchase File Generated';
            $sql_complete = "UPDATE purchase_orders SET status = ? WHERE id = ? " . ($is_platform_admin ? "" : "AND company_id = ?");
            if($stmt_complete = $conn->prepare($sql_complete)){
                if($is_platform_admin) $stmt_complete->bind_param("si", $new_po_status, $po_id);
                else $stmt_complete->bind_param("sii", $new_po_status, $po_id, $company_id_context);
                if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                    $success_message = "Purchase File marked as generated. Ready for Accounts.";
                    log_audit_action($conn, 'po_pf_generated', "PO ID {$po_id} marked as {$new_po_status}.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
                } else { $error_message = "Failed to update status. Already complete or invalid state."; }
                $stmt_complete->close();
            }
         } else { $error_message = "PO is not in the correct state (must be 'Imaging Complete' or 'QC Failed')."; }
         $stmt_check->close();
     } else { $error_message = "Permission denied."; }
}

// -- ACTION 10: Accounts Checking (Accounts Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_accounts_verify'])) {
     if(has_any_role(['accounts', 'admin', 'platform_admin', 'purchase_head'])) {
        $accounts_remarks = trim($_POST['accounts_remarks'] ?? '');
        $new_status = 'Accounts Verified';
        $sql_verify = "UPDATE purchase_orders SET status = ? WHERE id = ? AND status = 'Purchase File Generated' " . ($is_platform_admin ? "" : "AND company_id = ?");
        if($stmt_verify = $conn->prepare($sql_verify)){
            if($is_platform_admin) $stmt_verify->bind_param("si", $new_status, $po_id);
            else $stmt_verify->bind_param("sii", $new_status, $po_id, $company_id_context);
            if($stmt_verify->execute() && $stmt_verify->affected_rows > 0){
                // We will create the invoice record in the next step, but log remarks now.
                $sql_log_remarks = "INSERT INTO purchase_invoices (po_id, company_id, accounts_remarks, accounts_status, accounts_user_id) VALUES (?, ?, ?, 'Verified', ?)
                                    ON DUPLICATE KEY UPDATE accounts_remarks = VALUES(accounts_remarks), accounts_status = VALUES(accounts_status), accounts_user_id = VALUES(accounts_user_id)";
                $stmt_log = $conn->prepare($sql_log_remarks);
                $stmt_log->bind_param("iisi", $po_id, $company_id_for_action, $accounts_remarks, $user_id);
                $stmt_log->execute(); $stmt_log->close();
                $success_message = "Purchase File marked as Verified by Accounts.";
                log_audit_action($conn, 'po_accounts_verified', "PO ID {$po_id} marked as {$new_status}. Remarks: {$accounts_remarks}", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } else { $error_message = "Failed to update status. Already verified or invalid state."; }
            $stmt_verify->close();
        }
     } else { $error_message = "Permission denied."; }
}

// -- ACTION 11: Log Vendor Invoice (Purchase Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_log_invoice'])) {
    if(has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $invoice_number = trim($_POST['vendor_invoice_number']); $invoice_date = $_POST['invoice_date']; $invoice_amount = $_POST['total_amount'];
        if(!empty($invoice_number) && !empty($invoice_date) && !empty($invoice_amount) && is_numeric($invoice_amount)){
            $conn->begin_transaction();
            try {
                // 1. Insert/Update the invoice record
                $sql_insert_invoice = "INSERT INTO purchase_invoices 
                                       (po_id, company_id, vendor_invoice_number, invoice_date, total_amount, purchase_team_user_id) 
                                       VALUES (?, ?, ?, ?, ?, ?)
                                       ON DUPLICATE KEY UPDATE vendor_invoice_number = VALUES(vendor_invoice_number), invoice_date = VALUES(invoice_date), total_amount = VALUES(total_amount), purchase_team_user_id = VALUES(purchase_team_user_id)";
                $stmt_inv = $conn->prepare($sql_insert_invoice);
                $stmt_inv->bind_param("iisdsi", $po_id, $company_id_for_action, $invoice_number, $invoice_date, $invoice_amount, $user_id);
                if(!$stmt_inv->execute()){ throw new Exception("Failed to save invoice details: " . $stmt_inv->error); }
                $stmt_inv->close();
                // 2. Update the PO status
                $new_status = 'Invoice Received';
                $sql_update_po = "UPDATE purchase_orders SET status = ? WHERE id = ? AND status = 'Accounts Verified' " . ($is_platform_admin ? "" : "AND company_id = ?");
                $stmt_po = $conn->prepare($sql_update_po);
                if($is_platform_admin) $stmt_po->bind_param("si", $new_status, $po_id);
                else $stmt_po->bind_param("sii", $new_status, $po_id, $company_id_context);
                if(!$stmt_po->execute() || $stmt_po->affected_rows == 0){ throw new Exception("Failed to update PO status. Already processed or invalid state."); }
                $stmt_po->close();
                $conn->commit();
                $success_message = "Vendor invoice {$invoice_number} logged successfully.";
                log_audit_action($conn, 'po_invoice_logged', "Invoice {$invoice_number} (Amount: {$invoice_amount}) logged for PO ID {$po_id}", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } catch (Exception $e) { $conn->rollback(); $error_message = $e->getMessage(); }
        } else { $error_message = "Please fill in all invoice fields with valid data."; }
    } else { $error_message = "Permission denied."; }
}

// -- ACTION 12: Final Purchase Entry (Purchase Team) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_purchase_complete'])) {
    if(has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $new_status = 'Purchase Complete';
        $sql_complete = "UPDATE purchase_orders SET status = ? WHERE id = ? AND status = 'Invoice Received' " . ($is_platform_admin ? "" : "AND company_id = ?");
        if($stmt_complete = $conn->prepare($sql_complete)){
            if($is_platform_admin) $stmt_complete->bind_param("si", $new_status, $po_id);
            else $stmt_complete->bind_param("sii", $new_status, $po_id, $company_id_context);
            if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                $success_message = "Purchase Order has been marked as complete.";
                log_audit_action($conn, 'po_complete', "PO ID {$po_id} marked as {$new_status}.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } else { $error_message = "Failed to update status. Already complete or invalid state."; }
            $stmt_complete->close();
        }
    } else { $error_message = "Permission denied."; }
}

// -- ACTION 13: Confirm Customer Delivery (Sales Team / Admin) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_customer_delivery'])) {
    if(has_any_role(['sales_team', 'admin', 'platform_admin', 'purchase_head'])) {
        $new_status = 'Customer Delivered';
        $sql_complete = "UPDATE purchase_orders SET status = ? 
                         WHERE id = ? AND status = 'Purchase Complete' " . ($is_platform_admin ? "" : "AND company_id = ?");
        if($stmt_complete = $conn->prepare($sql_complete)){
            if($is_platform_admin) $stmt_complete->bind_param("si", $new_status, $po_id);
            else $stmt_complete->bind_param("sii", $new_status, $po_id, $company_id_context);

            if($stmt_complete->execute() && $stmt_complete->affected_rows > 0){
                $success_message = "Order marked as delivered to customer.";
                log_audit_action($conn, 'po_customer_delivered', "PO ID {$po_id} marked as {$new_status} by Sales/Admin.", $user_id, $company_id_for_action, 'purchase_order', $po_id);
            } else { $error_message = "Failed to update status. Already complete or invalid state."; }
            $stmt_complete->close();
        }
    } else { $error_message = "Permission denied."; }
}


// --- 3. FETCH DATA FOR DISPLAY (Re-fetch after potential updates) ---
$sql_po = "SELECT po.*, v.vendor_name, u.full_name as initiated_by_name, c.company_name,
           pi.vendor_invoice_number, pi.invoice_date, pi.total_amount as invoice_amount, pi.accounts_status, pi.accounts_remarks
           FROM purchase_orders po
           LEFT JOIN vendors v ON po.vendor_id = v.id
           JOIN users u ON po.initiated_by_user_id = u.id
           LEFT JOIN companies c ON po.company_id = c.id
           LEFT JOIN purchase_invoices pi ON po.id = pi.po_id
           WHERE po.id = ? " . ($is_platform_admin ? "" : "AND po.company_id = ?");
if($stmt_po = $conn->prepare($sql_po)){
    if($is_platform_admin) $stmt_po->bind_param("i", $po_id); else $stmt_po->bind_param("ii", $po_id, $company_id_context);
    if($stmt_po->execute()){ $po_details = $stmt_po->get_result()->fetch_assoc(); } else { $error_message = "Error fetching PO details: ".$stmt_po->error; }
    $stmt_po->close();
} else { $error_message = "DB Error (PO): ".$conn->error; }
if (!$po_details) { header("location: " . BASE_URL . "purchase/index.php?error=notfound"); exit; }

$all_items_inwarded = true; $sql_items = "SELECT * FROM po_items WHERE po_id = ?";
if($stmt_items = $conn->prepare($sql_items)){
    $stmt_items->bind_param("i", $po_id);
    if($stmt_items->execute()){
        $result_items = $stmt_items->get_result();
        while($row = $result_items->fetch_assoc()){ $po_items[] = $row; if($row['quantity'] != $row['quantity_received']) $all_items_inwarded = false; }
    } else { $error_message .= " Error fetching PO items: ".$stmt_items->error; }
    $stmt_items->close();
}
if(empty($po_items)) $all_items_inwarded = false; 

$all_items_qcd = true; $all_qc_passed_items_imaged = true; $sql_jewels = "SELECT * FROM jewel_inventory WHERE po_id = ? ORDER BY id";
if($stmt_jewels = $conn->prepare($sql_jewels)){
    $stmt_jewels->bind_param("i", $po_id);
    if($stmt_jewels->execute()){
        $result_jewels = $stmt_jewels->get_result();
        while($row = $result_jewels->fetch_assoc()){ 
            $jewel_inventory[] = $row; 
            if($row['qc_status'] == 'Pending') $all_items_qcd = false;
            if($row['qc_status'] == 'Pass' && $row['image_status'] == 'Pending') $all_qc_passed_items_imaged = false;
        }
    } else { $error_message .= " Error fetching jewel inventory: ".$stmt_jewels->error; }
    $stmt_jewels->close();
}
if(empty($jewel_inventory) && !empty($po_items)) { $all_items_qcd = false; $all_qc_passed_items_imaged = false; }

if(has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])) { 
    $sql_vendors = "SELECT id, vendor_name FROM vendors WHERE company_id = ? AND is_active = 1 ORDER BY vendor_name";
    if($stmt_v = $conn->prepare($sql_vendors)){ $stmt_v->bind_param("i", $po_details['company_id']); if($stmt_v->execute()){ $res_v = $stmt_v->get_result(); while($row_v = $res_v->fetch_assoc()){ $vendors[] = $row_v; } } $stmt_v->close(); }
}
// get_po_status_badge() is now in init.php
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Purchase Order</h2>
    <a href="purchase/index.php" class="btn btn-secondary">Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<!-- Main PO Details Card -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h4>PO Details (ID: <?php echo $po_details['id']; ?>)</h4>
        <span class="badge <?php echo get_po_status_badge($po_details['status']); ?> fs-6"><?php echo htmlspecialchars($po_details['status']); ?></span>
    </div>
    <div class="card-body">
        <div class="row">
            <?php if($is_platform_admin): ?><div class="col-12 mb-3"><p class="mb-1"><strong>Company:</strong> <span class="fs-5"><?php echo htmlspecialchars($po_details['company_name']); ?></span></p></div><?php endif; ?>
            <div class="col-md-4"><p class="mb-1"><strong>Customer:</strong> <?php echo htmlspecialchars($po_details['customer_name']); ?></p><p class="mb-1"><strong>Source:</strong> <?php echo htmlspecialchars($po_details['order_source']); ?></p><p class="mb-1"><strong>Initiated By:</strong> <?php echo htmlspecialchars($po_details['initiated_by_name']); ?></p></div>
            <div class="col-md-4"><p class="mb-1"><strong>PO Number:</strong> <?php echo htmlspecialchars($po_details['po_number'] ?? 'N/A'); ?></p><p class="mb-1"><strong>Vendor:</strong> <?php echo htmlspecialchars($po_details['vendor_name'] ?? 'N/A'); ?></p><p class="mb-1"><strong>Created On:</strong> <?php echo date("Y-m-d", strtotime($po_details['created_at'])); ?></p></div>
            <div class="col-md-4"><p class="mb-1"><strong>Target Date:</strong> <?php echo htmlspecialchars($po_details['target_date'] ?? 'N/A'); ?></p><p class="mb-1"><strong>Received Date:</strong> <?php echo htmlspecialchars($po_details['received_date'] ?? 'N/A'); ?></p></div>
            <?php if(!empty($po_details['requested_designs'])): ?><div class="col-12 mt-3"><p class="mb-1"><strong>Sales Request Notes:</strong></p><pre class="bg-light p-2 border rounded"><?php echo htmlspecialchars($po_details['requested_designs']); ?></pre></div><?php endif; ?>
            <?php if(!empty($po_details['vendor_invoice_number'])): ?>
            <div class="col-12 mt-3 pt-3 border-top">
                <h5>Invoice Details</h5>
                <p class="mb-1"><strong>Vendor Invoice #:</strong> <?php echo htmlspecialchars($po_details['vendor_invoice_number']); ?></p>
                <p class="mb-1"><strong>Invoice Date:</strong> <?php echo htmlspecialchars($po_details['invoice_date']); ?></p>
                <p class="mb-1"><strong>Invoice Amount:</strong> Rs. <?php echo number_format($po_details['invoice_amount'], 2); ?></p>
                <p class="mb-1"><strong>Accounts Status:</strong> <?php echo htmlspecialchars($po_details['accounts_status']); ?></p>
                <?php if(!empty($po_details['accounts_remarks'])): ?><p class="mb-1"><strong>Accounts Remarks:</strong> <?php echo htmlspecialchars($po_details['accounts_remarks']); ?></p><?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- === DYNAMIC ACTION FORMS === -->

<?php // --- ACTION 1: Order Placement ---
if ($po_details['status'] == 'New Request' && has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-primary"><div class="card-header bg-primary text-white"><h4>Action: Place Purchase Order</h4></div><div class="card-body">
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Vendor</label><select name="vendor_id" class="form-select" required><option value="">-- Select Vendor --</option><?php foreach($vendors as $vendor): ?><option value="<?php echo $vendor['id']; ?>"><?php echo htmlspecialchars($vendor['vendor_name']); ?></option><?php endforeach; ?><?php if(empty($vendors)): ?><option value="" disabled>No vendors found.</option><?php endif; ?></select></div>
            <div class="col-md-4"><label class="form-label">PO Number</label><input type="text" name="po_number" class="form-control" required></div>
            <div class="col-md-4"><label class="form-label">Target Delivery Date</label><input type="date" name="target_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>" required></div>
        </div><hr>
        <h5>Order Items (Design, Quantity)</h5>
        <div id="po-items-container"><div class="row g-3 mb-2 po-item-row"><div class="col-md-6"><input type="text" name="design_code[]" class="form-control" placeholder="Design Code" required></div><div class="col-md-4"><input type="number" min="1" name="quantity[]" class="form-control" placeholder="Quantity" required></div><div class="col-md-2"><button type="button" class="btn btn-danger" onclick="this.closest('.po-item-row').remove()">Remove</button></div></div></div>
        <button type="button" class="btn btn-secondary btn-sm mt-2" onclick="addPoItemRow()">+ Add Design</button>
        <button type="submit" name="action_place_order" class="btn btn-primary mt-3 float-end">Place Order</button>
    </form>
</div></div>
<?php endif; ?>


<?php // --- ACTION 2: Goods Received ---
if ($po_details['status'] == 'Order Placed' && has_any_role(['inventory_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-secondary"><div class="card-header bg-secondary text-white"><h4>Action: Receive Goods</h4></div><div class="card-body">
    <p>Acknowledge receipt of the physical items from the vendor.</p>
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post">
        <div class="mb-3"><label class="form-label">Date Received</label><input type="date" name="received_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required></div>
        <button type="submit" name="action_receive_goods" class="btn btn-primary">Confirm Goods Received</button>
    </form>
</div></div>
<?php endif; ?>

<?php // --- ACTION 3: Inward & Jewel Coding ---
if ($po_details['status'] == 'Goods Received' && has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-info"><div class="card-header bg-info text-dark"><h4>Action: Inward & Jewel Coding</h4></div><div class="card-body">
    <p>Log each individual item received against its design code. You can mark this complete even with partial receipts.</p>
    <?php foreach($po_items as $item): $remaining = $item['quantity'] - $item['quantity_received']; ?>
        <div class="card mb-3 <?php echo ($remaining <= 0) ? 'border-success' : ''; ?>"><div class="card-body"><div class="row align-items-center">
            <div class="col-md-4"><strong>Design:</strong> <?php echo htmlspecialchars($item['design_code']); ?><br><strong>Ordered:</strong> <?php echo $item['quantity']; ?> | <strong>Received:</strong> <?php echo $item['quantity_received']; ?> | <strong>Remaining:</strong> <?php echo $remaining; ?></div>
            <div class="col-md-8"><?php if ($remaining > 0): ?>
                <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" class="d-flex gap-2">
                    <input type="hidden" name="po_item_id" value="<?php echo $item['id']; ?>"><input type="text" name="jewel_code" class="form-control" placeholder="Enter Unique Jewel Code" required><button type="submit" name="action_inward_item" class="btn btn-primary">Add Jewel</button>
                </form>
                <?php else: ?><div class="text-success fw-bold">All items inwarded</div><?php endif; ?>
            </div>
        </div></div></div>
    <?php endforeach; ?>
    <?php if(!empty($po_items)): // Show Mark Complete button as long as there are items ?>
    <hr><form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" class="text-center"><p class="text-info">Press this button when you are finished inwarding all items *for this delivery* to move to QC.</p><button type="submit" name="action_mark_inward_complete" class="btn btn-success btn-lg">Mark Inward Process Complete</button></form>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<?php // --- ACTION 5: Quality Control ---
if ($po_details['status'] == 'Inward Complete' && has_any_role(['order_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-warning"><div class="card-header bg-warning text-dark"><h4>Action: Quality Control (QC)</h4></div><div class="card-body">
    <p>Inspect each item and mark its QC status. Remarks are required for failed items.</p>
    <?php if(empty($jewel_inventory)): ?> <p class="text-muted">No items were inwarded to perform QC on.</p> <?php endif; ?>
    <?php foreach($jewel_inventory as $jewel): ?>
        <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post"><input type="hidden" name="jewel_id" value="<?php echo $jewel['id']; ?>"><div class="card mb-2"><div class="card-body py-2 px-3"><div class="row align-items-center">
            <div class="col-md-3"><strong>Jewel Code:</strong> <?php echo htmlspecialchars($jewel['jewel_code']); ?><br><strong>Status:</strong> <?php echo htmlspecialchars($jewel['qc_status']); ?></div>
            <div class="col-md-5"><input type="text" name="qc_remarks" class="form-control" placeholder="QC Remarks (if any)" value="<?php echo htmlspecialchars($jewel['qc_remarks'] ?? ''); ?>"></div>
            <div class="col-md-4 btn-group"><button type="submit" name="action_update_qc" value="Pass" class="btn btn-success <?php echo $jewel['qc_status'] == 'Pass' ? 'active' : ''; ?>">Pass</button><button type="submit" name="action_update_qc" value="Fail" class="btn btn-danger <?php echo $jewel['qc_status'] == 'Fail' ? 'active' : ''; ?>">Fail</button></div>
        </div></div></div></form>
    <?php endforeach; ?>
    <?php if($all_items_qcd && !empty($jewel_inventory)): ?>
    <hr><form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" class="text-center"><p class="text-success">All items for this PO have been through QC.</p><button type="submit" name="action_mark_qc_complete" class="btn btn-success btn-lg">Mark QC Process Complete</button></form>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<?php // --- ACTION 7: Image Generation ---
if (in_array($po_details['status'], ['QC Passed', 'QC Failed']) && has_any_role(['inventory_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-dark"><div class="card-header bg-dark text-white"><h4>Action: Image Generation</h4></div><div class="card-body">
    <p>Mark items as "Imaged" once product photography is complete. Only QC Passed items can be imaged.</p>
    <?php $qc_passed_items = array_filter($jewel_inventory, function($item){ return $item['qc_status'] == 'Pass'; });
    if(empty($qc_passed_items) && $po_details['status'] == 'QC Passed'): ?><p class="text-muted">No items were marked as QC Passed for this order.</p>
    <?php elseif(empty($qc_passed_items) && $po_details['status'] == 'QC Failed'): ?><p class="text-danger">All items failed QC. No imaging required.</p>
    <?php else: ?>
        <?php foreach($qc_passed_items as $jewel): ?>
            <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post"><input type="hidden" name="jewel_id" value="<?php echo $jewel['id']; ?>"><div class="card mb-2"><div class="card-body py-2 px-3"><div class="row align-items-center">
                <div class="col-md-4"><strong>Jewel Code:</strong> <?php echo htmlspecialchars($jewel['jewel_code']); ?></div>
                <div class="col-md-4"><strong>Image Status:</strong> <?php echo htmlspecialchars($jewel['image_status']); ?></div>
                <div class="col-md-4 text-end"><?php if($jewel['image_status'] == 'Pending'): ?><button type="submit" name="action_update_image_status" value="Completed" class="btn btn-success">Mark as Imaged</button><?php else: ?><span class="badge bg-success">Completed</span><?php endif; ?></div>
            </div></div></div></form>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if($all_qc_passed_items_imaged && !empty($qc_passed_items) && $po_details['status'] == 'QC Passed'): ?>
    <hr><form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" class="text-center"><p class="text-success">All QC Passed items have been imaged.</p><button type="submit" name="action_mark_imaging_complete" class="btn btn-success btn-lg">Mark Imaging Process Complete</button></form>
    <?php endif; ?>
</div></div>
<?php endif; ?>

<?php // --- ACTION 9: Generate Purchase File ---
if (in_array($po_details['status'], ['Imaging Complete', 'QC Failed']) && has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-primary"><div class="card-header bg-primary text-white"><h4>Action: Generate Purchase File</h4></div><div class="card-body">
    <p>This finalizes the item list and moves the order to Accounts for verification.</p>
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to generate the Purchase File?');">
        <button type="submit" name="action_generate_purchase_file" class="btn btn-primary">Generate Purchase File</button>
    </form>
</div></div>
<?php endif; ?>

<?php // --- ACTION 10: Accounts Checking ---
if ($po_details['status'] == 'Purchase File Generated' && has_any_role(['accounts', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-success"><div class="card-header bg-success text-white"><h4>Action: Accounts Verification</h4></div><div class="card-body">
    <p>Verify the purchase file, check items and quantities, and add remarks.</p>
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post">
         <div class="mb-3"><label for="accounts_remarks" class="form-label">Accounts Remarks (Optional)</label><textarea class="form-control" id="accounts_remarks" name="accounts_remarks" rows="2"><?php echo htmlspecialchars($po_details['accounts_remarks'] ?? ''); ?></textarea></div>
        <button type="submit" name="action_accounts_verify" class="btn btn-success">Mark as Verified</button>
    </form>
</div></div>
<?php endif; ?>

<?php // --- ACTION 11: Log Vendor Invoice ---
if ($po_details['status'] == 'Accounts Verified' && has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-primary"><div class="card-header bg-primary text-white"><h4>Action: Log Vendor Invoice</h4></div><div class="card-body">
    <p>Log the final invoice details received from the vendor.</p>
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post">
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Vendor Invoice Number</label><input type="text" name="vendor_invoice_number" class="form-control" value="<?php echo htmlspecialchars($po_details['vendor_invoice_number'] ?? ''); ?>" required></div>
            <div class="col-md-4"><label class="form-label">Invoice Date</label><input type="date" name="invoice_date" class="form-control" value="<?php echo htmlspecialchars($po_details['invoice_date'] ?? date('Y-m-d')); ?>" required></div>
             <div class="col-md-4"><label class="form-label">Total Invoice Amount (Rs.)</label><input type="number" step="0.01" min="0" name="total_amount" class="form-control" value="<?php echo htmlspecialchars($po_details['invoice_amount'] ?? '0.00'); ?>" required></div>
        </div>
        <button type="submit" name="action_log_invoice" class="btn btn-primary mt-3">Log Invoice</button>
    </form>
</div></div>
<?php endif; ?>

<?php // --- ACTION 12: Final Purchase Entry ---
if ($po_details['status'] == 'Invoice Received' && has_any_role(['purchase_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-success"><div class="card-header bg-success text-white"><h4>Action: Final Purchase Entry</h4></div><div class="card-body">
    <p>All steps are complete. Mark this PO as complete to close it.</p>
    <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to mark this PO as complete?');">
        <button type="submit" name="action_purchase_complete" class="btn btn-success">Mark Purchase Complete</button>
    </form>
</div></div>
<?php endif; ?>

<?php // --- NEW ACTION 13: Confirm Customer Delivery ---
if ($po_details['status'] == 'Purchase Complete' && has_any_role(['sales_team', 'admin', 'platform_admin', 'purchase_head'])): ?>
<div class="card mb-4 border-info">
    <div class="card-header bg-info text-dark"><h4>Action: Confirm Customer Delivery</h4></div>
    <div class="card-body">
        <p>This is the final step. Confirm that the items for this order have been delivered to the customer.</p>
        <form action="purchase/manage_po.php?po_id=<?php echo $po_id; ?>" method="post" onsubmit="return confirm('Are you sure you want to mark this order as delivered to the customer?');">
            <button type="submit" name="action_customer_delivery" class="btn btn-info">Mark as Customer Delivered</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php // --- FINAL STATE: Customer Delivered ---
if ($po_details['status'] == 'Customer Delivered'): ?>
<div class="card mb-4 border-light"><div class="card-header bg-light text-dark"><h4>Process Closed</h4></div><div class="card-body">
    <p class="fs-5 text-center text-muted">This order was completed and delivered to the customer.</p>
</div></div>
<?php endif; ?>


<!-- === END DYNAMIC ACTION FORMS === -->


<!-- PO Items & Inventory Tables -->
<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5>PO Items (Designs Ordered)</h5></div>
            <div class="card-body"><div class="table-responsive"><table class="table">
                <thead><tr><th>Design Code</th><th>Qty Ordered</th><th>Qty Received</th></tr></thead>
                <tbody>
                    <?php if(empty($po_items)): ?>
                        <tr><td colspan="3" class="text-center text-muted">No items added to this PO yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($po_items as $item): ?>
                        <tr><td><?php echo htmlspecialchars($item['design_code']); ?></td><td><?php echo $item['quantity']; ?></td><td><strong><?php echo $item['quantity_received']; ?></strong></td></tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table></div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><h5>Jewel Inventory (Items Received)</h5></div>
            <div class="card-body"><div class="table-responsive"><table class="table table-sm">
                 <thead><tr><th>Jewel Code</th><th>Design Code</th><th>QC Status</th><th>Image Status</th><th>QC Remarks</th></tr></thead>
                 <tbody>
                    <?php if(empty($jewel_inventory)): ?>
                        <tr><td colspan="5" class="text-center text-muted">No items inwarded yet.</td></tr>
                    <?php else: ?>
                        <?php $design_map = array_column($po_items, 'design_code', 'id');
                        foreach($jewel_inventory as $jewel): ?>
                        <tr class="<?php echo $jewel['qc_status'] == 'Fail' ? 'table-danger' : ($jewel['qc_status'] == 'Pass' ? 'table-success' : ''); ?>">
                            <td><strong><?php echo htmlspecialchars($jewel['jewel_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($design_map[$jewel['po_item_id']] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($jewel['qc_status']); ?></td>
                            <td><?php echo htmlspecialchars($jewel['image_status']); ?></td>
                            <td><small><?php echo htmlspecialchars($jewel['qc_remarks'] ?? ''); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                 </tbody>
            </table></div></div>
        </div>
    </div>
</div>

<script>
function addPoItemRow() {
    const container = document.getElementById('po-items-container');
    const newRow = document.createElement('div');
    newRow.className = 'row g-3 mb-2 po-item-row';
    newRow.innerHTML = `
        <div class="col-md-6"><input type="text" name="design_code[]" class="form-control" placeholder="Design Code" required></div>
        <div class="col-md-4"><input type="number" min="1" name="quantity[]" class="form-control" placeholder="Quantity" required></div>
        <div class="col-md-2"><button type="button" class="btn btn-danger" onclick="this.closest('.po-item-row').remove()">Remove</button></div>
    `;
    container.appendChild(newRow);
}
</script>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



