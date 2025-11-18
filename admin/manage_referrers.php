<?php
// Path: D:\xampp\htdocs\Companyportal\admin\manage_referrers.php

// 1. Include the MAIN portal's init file
require_once __DIR__ . '/../includes/init.php';

// 2. Security Check: Ensure user is logged in
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . BASE_URL . "login.php");
    exit;
}

// 3. Permission Check: This page is for Admins only
if (!check_role('admin')) {
    $_SESSION['flash_message'] = "You do not have permission to access that page.";
    header("location: " . BASE_URL . "portal_home.php");
    exit;
}

// 4. Fetch All Referrers with their stats
$referrers = [];
$sql = "SELECT 
            r.id, 
            r.full_name, 
            r.mobile_number, 
            r.total_points_balance, 
            r.created_at, 
            COUNT(l.id) AS total_leads_submitted
        FROM referrers r
        LEFT JOIN referrals l ON r.id = l.referrer_id
        GROUP BY r.id, r.full_name, r.mobile_number, r.total_points_balance, r.created_at
        ORDER BY r.created_at DESC";

if ($stmt = $conn->prepare($sql)) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $referrers = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();
    } else {
        error_log("Failed to execute referrers query: " . $stmt->error);
    }
    $stmt->close();
} else {
    error_log("Failed to prepare referrers query: " . $conn->error);
}

// 5. Include the MAIN portal's header
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="row mb-3">
        <div class="col-md-6">
            <h1 class="h3">Manage Referrers</h1>
        </div>
        <div class="col-md-6 text-md-end">
            <a href="<?php echo BASE_URL; ?>admin/manage_referrals.php" class="btn btn-outline-secondary">
                <i data-lucide="list-filter" class="me-1"></i> Manage Leads
            </a>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header">
            <h5 class="mb-0">Registered Referrers</h5>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="referrersTable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Mobile Number</th>
                            <th>Date Joined</th>
                            <th>Total Leads Submitted</th>
                            <th>Total Points Balance</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($referrers)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted p-4">
                                    No referrers have signed up for the program yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($referrers as $referrer): ?>
                                <tr>
                                    <td><?php echo $referrer['id']; ?></td>
                                    <td><?php echo htmlspecialchars($referrer['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($referrer['mobile_number']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($referrer['created_at'])); ?></td>
                                    <td><span class="badge bg-info text-dark"><?php echo $referrer['total_leads_submitted']; ?></span></td>
                                    <td><span class="badge bg-success"><?php echo $referrer['total_points_balance']; ?></span></td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-secondary" disabled>
                                            Edit
                                        </button>
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
// 6. Include the MAIN portal's footer
require_once __DIR__ . '/../includes/footer.php'; 
?>