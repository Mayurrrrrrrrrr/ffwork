<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS A SUPER ADMIN --
if (!check_role('admin')) {
    header("location: " . BASE_URL . "login.php"); // Root-relative path
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE --
$company_id = get_current_company_id();
if (!$company_id && !check_role('platform_admin')) { // Allow platform admin if implemented later
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error"); // Root-relative path
    exit;
}

$error_message = '';
$logs = [];

// --- DATA FETCHING ---
// Fetch audit logs for the current company, most recent first.
// We join with users to get the name of the user who performed the action.
// Limit to recent logs for performance initially.
$sql_logs = "SELECT al.*, u.full_name AS user_name 
             FROM audit_logs al
             LEFT JOIN users u ON al.user_id = u.id AND al.company_id = u.company_id
             WHERE al.company_id = ?
             ORDER BY al.created_at DESC
             LIMIT 100"; // Limit to the latest 100 entries for now

if ($stmt_logs = $conn->prepare($sql_logs)) {
    $stmt_logs->bind_param("i", $company_id);
    if ($stmt_logs->execute()) {
        $result = $stmt_logs->get_result();
        while ($row = $result->fetch_assoc()) {
            $logs[] = $row;
        }
    } else {
        $error_message = "Error fetching audit logs: " . $stmt_logs->error;
    }
    $stmt_logs->close();
} else {
    $error_message = "Database prepare error: " . $conn->error;
}


require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Audit Log</h2>
    <p>Recent actions performed within your company portal.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h4>Activity Log (Latest 100)</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover table-sm">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>User</th>
                            <th>Action Type</th>
                            <th>Target</th>
                            <th>Details</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="6">No audit log entries found yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo date("Y-m-d H:i:s", strtotime($log['created_at'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></td>
                                    <td><?php echo htmlspecialchars($log['action_type']); ?></td>
                                    <td>
                                        <?php if($log['target_type'] && $log['target_id']): ?>
                                            <?php echo htmlspecialchars(ucfirst($log['target_type'])) . '#' . htmlspecialchars($log['target_id']); ?>
                                        <?php else: ?>
                                            N/A
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo nl2br(htmlspecialchars($log['log_message'])); ?></td>
                                    <td><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
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

