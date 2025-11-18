<?php
// This is the central backend script for all stock transfer actions.
// We now get $user_id, $company_id_context, etc., from the header
require_once 'includes/header.php'; 

// Must be logged in
if (!isset($user_id)) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

// Ensure all required session variables are set
// *** FIX: Check the GATI location name from the SESSION ***
if (!isset($company_id_context) || !isset($_SESSION['gati_location_name']) || $_SESSION['gati_location_name'] === 'N/A' || empty($_SESSION['gati_location_name'])) {
     error_log("Stock Transfer Handler: Missing session variables or GATI location is 'N/A'. UserID: {$user_id}, Store: " . ($_SESSION['gati_location_name'] ?? 'Not Set'));
     header("Location: index.php?error=" . urlencode("Your session is invalid or your user is not linked to a GATI location. Please contact admin."));
     exit;
}

// *** FIX: Get the GATI location name from the session ***
$user_gati_location_name = $_SESSION['gati_location_name'];
$action = $_POST['action'] ?? null;

// Helper function for redirecting with messages
function redirect($message, $is_error = false) {
    $type = $is_error ? 'error' : 'success';
    header("Location: index.php?{$type}=" . urlencode($message));
    exit;
}

try {
    // --- ACTION: CREATE A NEW REQUEST ---
    if ($action === 'create_request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $jewel_code = $_POST['jewel_code'] ?? null;
        $style_code = $_POST['style_code'] ?? null;
        $sender_location = $_POST['sender_location_name'] ?? null; // This is the GATI name

        if (empty($jewel_code) || empty($sender_location)) {
            redirect("Missing data for request (Jewel Code or Sender Location).", true);
        }
        
        if ($sender_location == $user_gati_location_name) {
             redirect("You cannot request an item from your own store.", true);
        }
        
        // Check if an active request for this item already exists
        $sql_check = "SELECT id FROM internal_stock_orders 
                      WHERE requester_user_id = ? 
                      AND jewel_code = ? 
                      AND sender_location_name = ?
                      AND status IN ('Pending', 'Accepted', 'Shipped')";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("iss", $user_id, $jewel_code, $sender_location);
        $stmt_check->execute();
        $check_result = $stmt_check->get_result();
        if ($check_result->num_rows > 0) {
            redirect("You already have an active request for this item from this location.", true);
        }
        $stmt_check->close();

        // *** FIX: Use the correct column name 'requester_store_name' ***
        $sql = "INSERT INTO internal_stock_orders (company_id, requester_user_id, requester_store_name, sender_location_name, jewel_code, style_code, status)
                VALUES (?, ?, ?, ?, ?, ?, 'Pending')";
        if ($stmt = $conn->prepare($sql)) {
            // Bind the GATI location name from the session to this column
            $stmt->bind_param("iissss", $company_id_context, $user_id, $user_gati_location_name, $sender_location, $jewel_code, $style_code);
            if ($stmt->execute()) {
                // TODO: Add notification logic here for the sender
                redirect("Stock request for {$jewel_code} created successfully.", false);
            } else {
                throw new Exception("Database error creating request: " . $stmt->error);
            }
            $stmt->close();
        } else {
            throw new Exception("Database prepare error (create): " . $conn->error);
        }
    }

    // --- ACTIONS ON EXISTING ORDERS (Accept, Reject, Ship, Receive, Cancel) ---
    $order_id = $_POST['order_id'] ?? null;
    if (empty($order_id) || !is_numeric($order_id)) {
        // If action wasn't 'create', it must be one of these, and requires an order_id
        if ($action !== 'create_request') {
             redirect("Invalid Order ID.", true);
        }
        // This was the exit point, but if action is 'create_request', we should have already handled it.
        // If it's not 'create_request' and has no order_id, it's an error.
        if ($action !== 'create_request') {
             redirect("Invalid Order ID.", true);
        }
        exit; // Exit if no order_id and not a create action
    }

    // Fetch the order to verify ownership
    $sql_order = "SELECT * FROM internal_stock_orders WHERE id = ? AND company_id = ?";
    $stmt_order = $conn->prepare($sql_order);
    $stmt_order->bind_param("ii", $order_id, $company_id_context);
    $stmt_order->execute();
    $order = $stmt_order->get_result()->fetch_assoc();
    $stmt_order->close();

    if (!$order) {
        redirect("Order not found.", true);
    }

    // Check permissions
    $is_requester = ($order['requester_user_id'] == $user_id);
    $is_sender = ($order['sender_location_name'] == $user_gati_location_name);
    $remarks = trim($_POST['remarks'] ?? '');

    switch ($action) {
        case 'accept':
            if (!$is_sender || $order['status'] !== 'Pending') {
                redirect("Permission denied or invalid action.", true);
            }
            $sql = "UPDATE internal_stock_orders SET status = 'Accepted', updated_at = NOW() WHERE id = ?";
            $msg = "Order accepted.";
            // TODO: Add notification logic for requester
            break;

        case 'reject':
            if (!$is_sender || $order['status'] !== 'Pending') {
                redirect("Permission denied or invalid action.", true);
            }
            $sql = "UPDATE internal_stock_orders SET status = 'Rejected', remarks = ?, updated_at = NOW() WHERE id = ?";
            $msg = "Order rejected.";
            // TODO: Add notification logic for requester
            break;

        case 'ship':
            if (!$is_sender || $order['status'] !== 'Accepted') {
                redirect("Permission denied or invalid action.", true);
            }
            $shipment_no = trim($_POST['shipment_no'] ?? '');
            if(empty($shipment_no)) {
                redirect("Shipment number is required.", true);
            }
            $sql = "UPDATE internal_stock_orders SET status = 'Shipped', shipment_no = ?, remarks = ?, updated_at = NOW() WHERE id = ?";
            $msg = "Order marked as shipped.";
            // TODO: Add notification logic for requester
            break;

        case 'receive':
            if (!$is_requester || $order['status'] !== 'Shipped') {
                redirect("Permission denied or invalid action.", true);
            }
            $sql = "UPDATE internal_stock_orders SET status = 'Received', updated_at = NOW() WHERE id = ?";
            $msg = "Order receipt confirmed. Transfer complete.";
            // TODO: Add notification logic for sender
            break;

        case 'cancel':
             if (!$is_requester || $order['status'] !== 'Pending') {
                redirect("You can only cancel pending requests.", true);
            }
            $sql = "UPDATE internal_stock_orders SET status = 'Cancelled', remarks = 'Cancelled by requester', updated_at = NOW() WHERE id = ?";
            $msg = "Request cancelled.";
            break;

        default:
            redirect("Unknown action.", true);
    }

    // Execute the update
    if (isset($sql)) {
        if ($stmt_update = $conn->prepare($sql)) {
            if ($action === 'ship') {
                 $stmt_update->bind_param("ssi", $shipment_no, $remarks, $order_id);
            } else if ($action === 'reject') {
                 $stmt_update->bind_param("si", $remarks, $order_id);
            } else {
                 $stmt_update->bind_param("i", $order_id);
            }

            if ($stmt_update->execute()) {
                redirect($msg, false);
            } else {
                throw new Exception("Database error performing action '{$action}': " . $stmt_update->error);
            }
            $stmt_update->close();
        } else {
             throw new Exception("Database prepare error (update): " . $conn->error);
        }
    }

} catch (Exception $e) {
    error_log("Stock Transfer Error: " . $e->getMessage());
    redirect("An unexpected error occurred. Please try again.", true);
}

if(isset($conn)) $conn->close();
?>


