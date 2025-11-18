<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This MUST be the very first line.
require_once '../includes/init.php';

// -- SECURITY CHECK: USE THE CORRECT MULTI-ROLE FUNCTION --
// Allow access if user has admin, accounts, or approver role OR the platform_admin role
if (!has_any_role(['admin', 'accounts', 'approver', 'platform_admin'])) {
    // If user is logged in but has wrong role (e.g., employee only), redirect them
    if(check_role('employee')){
        header("location: " . BASE_URL . "employee/index.php"); 
    } else {
        // Otherwise, send to login
        header("location: " . BASE_URL . "login.php"); 
    }
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE (unless Platform Admin) --
$company_id = get_current_company_id(); // This returns null for platform admin
$is_platform_admin = check_role('platform_admin');

if (!$company_id && !$is_platform_admin) {
    // If no company ID and not platform admin, something is wrong.
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error"); 
    exit;
}

// -- PAGE-SPECIFIC LOGIC: FETCH REPORTS BASED ON USER ROLE & COMPANY --
$reports = [];
$user_roles = $_SESSION['roles'] ?? []; 
$user_id = $_SESSION['user_id'] ?? null;
$page_title = "Dashboard"; 

$sql = "";
$params = [];
$types = '';

// Determine title and query based on highest privilege
if ($is_platform_admin) {
    $page_title = "Platform Admin Dashboard";
    // Platform admin might see system-wide stats or manage companies.
    // For now, let's show all non-draft reports across ALL companies.
    $sql = "SELECT r.id, r.report_title, r.submitted_at, r.total_amount, r.status, u.full_name, c.company_name 
            FROM expense_reports r 
            JOIN users u ON r.user_id = u.id 
            JOIN companies c ON r.company_id = c.id 
            WHERE r.status != 'draft' 
            ORDER BY c.company_name, r.submitted_at DESC";

} elseif (in_array('admin', $user_roles)) {
    $page_title = "Company Admin Dashboard";
    // Company Admin sees all non-draft reports FOR THEIR COMPANY
    $sql = "SELECT r.id, r.report_title, r.submitted_at, r.total_amount, r.status, u.full_name 
            FROM expense_reports r JOIN users u ON r.user_id = u.id 
            WHERE r.status != 'draft' AND r.company_id = ? 
            ORDER BY r.submitted_at DESC";
    $params[] = $company_id; $types = 'i';

} elseif (in_array('accounts', $user_roles)) {
    $page_title = "Accounts Dashboard";
    // Accounts sees reports pending verification FOR THEIR COMPANY
    $sql = "SELECT r.id, r.report_title, r.submitted_at, r.total_amount, r.status, u.full_name 
            FROM expense_reports r JOIN users u ON r.user_id = u.id 
            WHERE r.status = 'pending_verification' AND r.company_id = ? 
            ORDER BY r.submitted_at ASC";
    $params[] = $company_id; $types = 'i';

} elseif (in_array('approver', $user_roles)) {
    $page_title = "Approver Dashboard";
    // Approver sees reports pending approval from their employees FOR THEIR COMPANY
    $sql = "SELECT r.id, r.report_title, r.submitted_at, r.total_amount, r.status, u.full_name 
            FROM expense_reports r JOIN users u ON r.user_id = u.id 
            WHERE r.status = 'pending_approval' AND u.approver_id = ? AND r.company_id = ?";
    $params[] = $user_id; $params[] = $company_id; $types = 'ii';
}

// Execute the query
if (!empty($sql)) {
    if ($stmt = $conn->prepare($sql)) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        if ($stmt->execute()) {
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $reports[] = $row;
            }
        } else { error_log("DB Error fetching reports: " . $stmt->error); }
        $stmt->close();
    } else { error_log("DB Error preparing statement: " . $conn->error); }
}

require_once '../includes/header.php'; // Use the latest header
?>

<div class="container mt-4">
    <h2><?php echo $page_title; ?></h2>
    <p>Welcome. Manage expense reports<?php echo $is_platform_admin ? ' across all companies' : ' for your company'; ?>.</p>

    <!-- Notification Alert -->
    <?php $notification_count = count($reports); ?>
    <?php if ($notification_count > 0 && !$is_platform_admin): ?>
    <div class="alert alert-info" role="alert">
        You have <strong><?php echo $notification_count; ?></strong> expense report(s) awaiting your action. Please review them below.
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h4><?php echo $is_platform_admin ? 'Recent Reports (All Companies)' : 'Reports Awaiting Your Action'; ?></h4></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <?php if ($is_platform_admin): ?><th>Company</th><?php endif; ?>
                            <th>Employee</th>
                            <th>Report Title</th>
                            <th>Submitted On</th>
                            <th>Amount</th>
                            <th>Current Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($reports)): ?>
                            <tr><td colspan="<?php echo $is_platform_admin ? '7' : '6'; ?>">No reports found matching criteria.</td></tr>
                        <?php else: ?>
                            <?php foreach ($reports as $report): ?>
                                <tr>
                                     <?php if ($is_platform_admin): ?><td><?php echo htmlspecialchars($report['company_name']); ?></td><?php endif; ?>
                                    <td><?php echo htmlspecialchars($report['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($report['report_title']); ?></td>
                                    <td><?php echo date("Y-m-d", strtotime($report['submitted_at'])); ?></td>
                                    <td>Rs. <?php echo number_format($report['total_amount'], 2); ?></td>
                                    <td>
                                        <span class="badge <?php echo get_status_badge($report['status']); ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $report['status']))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="admin/review.php?report_id=<?php echo $report['id']; ?>" class="btn btn-primary btn-sm">
                                            <?php 
                                                // Adjust button text based on role and status
                                                if ($is_platform_admin || in_array($report['status'], ['approved', 'paid', 'rejected'])) {
                                                    echo 'View';
                                                } else {
                                                    echo 'Review';
                                                }
                                            ?>
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
// get_status_badge() is now in init.php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>



