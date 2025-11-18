<?php
// Path: D:\xampp\htdocs\Companyportal\admin\manage_referrals.php

require_once __DIR__ . '/../includes/init.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

$allowed_roles = ['admin', 'accounts', 'approver', 'sales_team'];
if (!has_any_role($allowed_roles)) {
    header("location: " . BASE_URL . "portal_home.php");
    exit;
}

$is_admin_or_accounts = has_any_role(['admin', 'accounts']);
$user_store_id = $_SESSION['store_id'] ?? 0;

$referrals = [];

// --- UPDATED SQL: Added new tracking columns ---
$sql = "SELECT 
            r.id, 
            r.invitee_name, 
            r.invitee_contact, 
            r.status, 
            r.referral_code,
            r.created_at,
            r.last_contact_date,  -- New
            r.staff_notes,        -- New
            ref.full_name AS referrer_name, 
            s.store_name 
        FROM referrals r 
        JOIN referrers ref ON r.referrer_id = ref.id 
        LEFT JOIN stores s ON r.store_id = s.id";

$params = [];
$types = "";

if (!$is_admin_or_accounts) {
    $sql .= " WHERE r.store_id = ?";
    $params[] = $user_store_id;
    $types = "i";
}

$sql .= " ORDER BY r.created_at DESC";

if ($stmt = $conn->prepare($sql)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $referrals = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else {
        error_log("Failed to execute referral query: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Failed to prepare referral query: " . $conn->error);
}

function get_status_badge_class($status) {
    switch ($status) {
        case 'Points Awarded': return 'bg-success';
        case 'Purchased': return 'bg-warning text-dark';
        case 'Contacted': return 'bg-info text-dark';
        case 'Pending': return 'bg-secondary';
        case 'Rejected':
        case 'Expired': return 'bg-danger';
        default: return 'bg-light text-dark';
    }
}

require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col-md-6">
            <h1 class="h3">Manage Referrals</h1>
        </div>
        <div class="col-md-6 text-md-end">
            <a href="<?php echo BASE_URL; ?>referral/register.php" class="btn btn-outline-secondary" target="_blank">
                <i data-lucide="user-plus" class="me-1"></i> Add New Referrer
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">All Submitted Referrals</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="referralsTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Invitee</th>
                            <th>Invitee Contact</th>
                            <th>Referrer</th>
                            <th>Referred to Store</th>
                            <th>Status</th>
                            <th>Last Contact</th> <th>Staff Notes</th> <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($referrals)): ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted p-4">
                                    <?php if ($is_admin_or_accounts): ?>
                                        No referrals have been submitted yet.
                                    <?php else: ?>
                                        No referrals have been assigned to your store (<?php echo htmlspecialchars($_SESSION['store_name'] ?? 'N/A'); ?>) yet.
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($referrals as $lead): ?>
                                <tr>
                                    <td><?php echo date('d M Y', strtotime($lead['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($lead['invitee_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lead['invitee_contact']); ?></td>
                                    <td><?php echo htmlspecialchars($lead['referrer_name']); ?></td>
                                    <td><?php echo htmlspecialchars($lead['store_name']); ?></td>
                                    <td><span class="badge <?php echo get_status_badge_class($lead['status']); ?>"><?php echo htmlspecialchars($lead['status']); ?></span></td>
                                    <td><?php echo !empty($lead['last_contact_date']) ? date('d M Y', strtotime($lead['last_contact_date'])) : '-'; ?></td>
                                    <td title="<?php echo htmlspecialchars($lead['staff_notes']); ?>">
                                        <?php echo !empty($lead['staff_notes']) ? substr(htmlspecialchars($lead['staff_notes']), 0, 30) . '...' : '-'; ?>
                                    </td>
                                    <td>
                                        <a href="admin/update_referral.php?id=<?php echo $lead['id']; ?>" class="btn btn-sm btn-primary">
                                            Update
                                        </a>
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
require_once __DIR__ . '/../includes/footer.php'; 
?>