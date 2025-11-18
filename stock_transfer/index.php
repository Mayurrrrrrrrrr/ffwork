<?php
// We now get $user_gati_location_name and $user_friendly_store_name from the header
require_once 'includes/header.php'; 

$error_message = $_GET['error'] ?? '';
$success_message = $_GET['success'] ?? '';

$incoming_requests = [];
$outgoing_requests = [];

// *** FIX 1: Check the SESSION variable set by the header ***
// Also check that it's not empty, just in case.
if (!isset($_SESSION['gati_location_name']) || $_SESSION['gati_location_name'] === 'N/A' || empty($_SESSION['gati_location_name'])) {
    $error_message = "Your user account is not linked to a store location (or GATI location name is missing in admin panel). Please contact your administrator.";
} else {
    
    // *** FIX 1 (continued): Correctly get the GATI location name from the session
    $user_gati_location_name = $_SESSION['gati_location_name'];

    // 1. Fetch INCOMING requests (Requests sent to MY store)
    // *** FIX 2: Use correct column 'iso.requester_store_name' in the JOIN ***
    $sql_incoming = "SELECT iso.*, u.full_name as requester_full_name, s.store_name as requester_friendly_name
                     FROM internal_stock_orders iso
                     JOIN users u ON iso.requester_user_id = u.id
                     LEFT JOIN stores s ON iso.requester_store_name = s.gati_location_name
                     WHERE iso.sender_location_name = ?
                     AND iso.company_id = ?
                     AND iso.status IN ('Pending', 'Accepted')
                     ORDER BY iso.created_at DESC";
    
    if ($stmt_incoming = $conn->prepare($sql_incoming)) {
        // Bind the GATI location name from the variable we just set
        $stmt_incoming->bind_param("si", $user_gati_location_name, $company_id_context);
        
        if($stmt_incoming->execute()) {
            $result_incoming = $stmt_incoming->get_result();
            while($row = $result_incoming->fetch_assoc()) {
                $incoming_requests[] = $row;
            }
        } else { 
            // Check for specific error
            if ($conn->errno === 1054) { // Error 1054 is "Unknown column"
                $error_message = "Database error: A required column (like 'requester_store_name') might be missing from the 'internal_stock_orders' table. Please run the SQL file again.";
                error_log("Stock Transfer index.php SQL Error: " . $stmt_incoming->error);
            } else {
                $error_message = "Error fetching incoming requests: " . $stmt_incoming->error;
            }
        }
        $stmt_incoming->close();
    } else { $error_message = "DB Error (Incoming): " . $conn->error; }

    // 2. Fetch OUTGOING requests (Requests I have made)
    // We get $user_id from the header
    $sql_outgoing = "SELECT * FROM internal_stock_orders
                     WHERE requester_user_id = ?
                     AND company_id = ?
                     ORDER BY created_at DESC";
    if ($stmt_outgoing = $conn->prepare($sql_outgoing)) {
        $stmt_outgoing->bind_param("ii", $user_id, $company_id_context);
         if($stmt_outgoing->execute()) {
            $result_outgoing = $stmt_outgoing->get_result();
            while($row = $result_outgoing->fetch_assoc()) {
                $outgoing_requests[] = $row;
            }
        } else { $error_message = "Error fetching outgoing requests: " . $stmt_outgoing->error; }
        $stmt_outgoing->close();
    } else { $error_message = "DB Error (Outgoing): " . $conn->error; }
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="font-playfair" style="color: var(--sherpa-blue);">Stock Transfer Dashboard</h2>
    <a href="tools/stocklookup.php" class="btn btn-primary">
        <i data-lucide="plus" class="me-2"></i>New Stock Request
    </a>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(urldecode($error_message)); ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars(urldecode($success_message)); ?></div>
<?php endif; ?>

<!-- INCOMING REQUESTS (My store needs to action) -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">Incoming Requests (Items I Need to Send)</h4>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Jewel Code</th>
                        <th>Style Code</th>
                        <th>Requested By</th>
                        <th>Requester's Store</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($incoming_requests)): ?>
                        <tr><td colspan="7" class="text-center text-muted p-4">No incoming requests to fulfill.</td></tr>
                    <?php else: ?>
                        <?php foreach($incoming_requests as $req): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($req['jewel_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($req['style_code']); ?></td>
                            <td><?php echo htmlspecialchars($req['requester_full_name']); ?></td>
                            <!-- Display the friendly name, or the GATI name from the 'requester_store_name' column -->
                            <td><?php echo htmlspecialchars($req['requester_friendly_name'] ?? $req['requester_store_name']); ?></td>
                            <td>
                                <?php
                                $badge_class = 'bg-secondary';
                                if ($req['status'] == 'Pending') $badge_class = 'bg-warning text-dark';
                                if ($req['status'] == 'Accepted') $badge_class = 'bg-info text-dark';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($req['status']); ?></span>
                            </td>
                            <td><span class="small text-muted"><?php echo date("d-M-Y H:i", strtotime($req['created_at'])); ?></span></td>
                            <td>
                                <?php if ($req['status'] == 'Pending'): ?>
                                    <form action="request_handler.php" method="POST" class="d-inline me-1">
                                        <input type="hidden" name="order_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="action" value="accept" class="btn btn-success btn-sm" title="Accept"><i data-lucide="check"></i></button>
                                    </form>
                                    <button type="button" class="btn btn-danger btn-sm" title="Reject" data-bs-toggle="modal" data-bs-target="#rejectModal_<?php echo $req['id']; ?>">
                                        <i data-lucide="x"></i>
                                    </button>
                                <?php elseif ($req['status'] == 'Accepted'): ?>
                                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#shipModal_<?php echo $req['id']; ?>">
                                        <i data-lucide="truck" class="me-1" style="width:16px; height:16px;"></i> Ship
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- OUTGOING REQUESTS (Items I have requested) -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white">
        <h4 class="mb-0">My Outgoing Requests</h4>
    </div>
    <div class="card-body">
         <div class="table-responsive">
            <table class="table table-striped table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Jewel Code</th>
                        <th>Style Code</th>
                        <th>Requested From (GATI Location)</th>
                        <th>Status</th>
                        <th>Shipment #</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($outgoing_requests)): ?>
                        <tr><td colspan="7" class="text-center text-muted p-4">You have not made any requests.</td></tr>
                    <?php else: ?>
                        <?php foreach($outgoing_requests as $req): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($req['jewel_code']); ?></strong></td>
                            <td><?php echo htmlspecialchars($req['style_code']); ?></td>
                            <td><?php echo htmlspecialchars($req['sender_location_name']); ?></td>
                            <td>
                                <?php
                                $badge_class = 'bg-secondary';
                                if ($req['status'] == 'Pending') $badge_class = 'bg-warning text-dark';
                                if ($req['status'] == 'Accepted') $badge_class = 'bg-info text-dark';
                                if ($req['status'] == 'Shipped') $badge_class = 'bg-primary';
                                if ($req['status'] == 'Received') $badge_class = 'bg-success';
                                if ($req['status'] == 'Rejected') $badge_class = 'bg-danger';
                                if ($req['status'] == 'Cancelled') $badge_class = 'bg-dark';
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($req['status']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($req['shipment_no'] ?? 'N/A'); ?></td>
                            <td><span class="small text-muted"><?php echo date("d-M-Y H:i", strtotime($req['created_at'])); ?></span></td>
                            <td>
                                <?php if ($req['status'] == 'Shipped'): ?>
                                    <form action="request_handler.php" method="POST" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="action" value="receive" class="btn btn-success btn-sm">
                                            <i data-lucide="package-check" class="me-1" style="width:16px; height:16px;"></i> Confirm Receipt
                                        </button>
                                    </form>
                                <?php elseif ($req['status'] == 'Pending'): ?>
                                    <form action="request_handler.php" method="POST" class="d-inline">
                                        <input type="hidden" name="order_id" value="<?php echo $req['id']; ?>">
                                        <button type="submit" name="action" value="cancel" class="btn btn-danger btn-sm" title="Cancel Request" onclick="return confirm('Are you sure you want to cancel this request?');">
                                            <i data-lucide="x-circle"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small">No action</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<!-- Modals for Shipping/Rejecting -->
<?php foreach($incoming_requests as $req): ?>
    <?php if ($req['status'] == 'Accepted'): ?>
    <!-- Ship Modal -->
    <div class="modal fade" id="shipModal_<?php echo $req['id']; ?>" tabindex="-1" aria-labelledby="shipModalLabel_<?php echo $req['id']; ?>" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form action="request_handler.php" method="POST">
            <div class="modal-header">
              <h5 class="modal-title" id="shipModalLabel_<?php echo $req['id']; ?>">Ship Item: <?php echo htmlspecialchars($req['jewel_code']); ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="order_id" value="<?php echo $req['id']; ?>">
              <input type="hidden" name="action" value="ship">
              <div class="mb-3">
                <label for="shipment_no_<?php echo $req['id']; ?>" class="form-label">Shipment / AWB No. <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="shipment_no_<?php echo $req['id']; ?>" name="shipment_no" required>
              </div>
               <div class="mb-3">
                <label for="remarks_<?php echo $req['id']; ?>" class="form-label">Remarks (Optional)</label>
                <textarea class="form-control" id="remarks_<?php echo $req['id']; ?>" name="remarks" rows="2"></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Confirm Shipment</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($req['status'] == 'Pending'): ?>
    <!-- Reject Modal -->
    <div class="modal fade" id="rejectModal_<?php echo $req['id']; ?>" tabindex="-1" aria-labelledby="rejectModalLabel_<?php echo $req['id']; ?>" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <form action="request_handler.php" method="POST">
            <div class="modal-header">
              <h5 class="modal-title" id="rejectModalLabel_<?php echo $req['id']; ?>">Reject Item: <?php echo htmlspecialchars($req['jewel_code']); ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <input type="hidden" name="order_id" value="<?php echo $req['id']; ?>">
              <input type="hidden" name="action" value="reject">
               <div class="mb-3">
                <label for="remarks_reject_<?php echo $req['id']; ?>" class="form-label">Reason for Rejection (Optional)</label>
                <textarea class="form-control" id="remarks_reject_<?php echo $req['id']; ?>" name="remarks" rows="3" placeholder="e.g., Item not found, Item damaged..."></textarea>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-danger">Confirm Rejection</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
<?php endforeach; ?>


<?php
require_once 'includes/footer.php';
?>


