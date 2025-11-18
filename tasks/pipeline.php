<?php
// Use the new Task Tracker header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// --- FIX: Define $is_manager in this scope (This was missing) ---
$is_manager = has_any_role(['approver', 'admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'trainer']);

// --- SECURITY CHECK: ENSURE USER IS A MANAGER/ADMIN ---
// Note: $is_manager is now defined above.
if (!$is_manager) {
    header("location: " . BASE_URL . "tasks/index.php"); 
    exit;
}

$error_message = '';
$all_tasks = [];

// --- DATA FETCHING (COMPANY-AWARE) ---

// 1. Fetch all main tasks for the company
// UPDATED: Switched to LEFT JOIN for creator to prevent errors if creator is deleted
$sql_tasks = "SELECT 
                t.id as task_id,
                t.title,
                t.status,
                t.due_date,
                c.full_name as created_by,
                (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) as total_assigned,
                (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id AND ta.status = 'Completed') as total_completed
              FROM tasks t
              LEFT JOIN users c ON t.created_by_user_id = c.id
              WHERE t.company_id = ?
              AND t.main_task_id IS NULL -- Only show main tasks on this pipeline view
              ORDER BY t.due_date ASC, t.status";
    
if($stmt_tasks = $conn->prepare($sql_tasks)) {
    $stmt_tasks->bind_param("i", $company_id_context);
    if($stmt_tasks->execute()) {
        $result = $stmt_tasks->get_result();
        while($row = $result->fetch_assoc()) { 
            $all_tasks[] = $row; 
        }
    } else {
        $error_message = "Error fetching tasks: " . $stmt_tasks->error;
    }
    $stmt_tasks->close();
} else {
    $error_message = "DB Error (Tasks): " . $conn->error;
}


// --- Helper Functions for Badges (if not already defined) ---
if (!function_exists('get_task_status_badge')) {
    function get_task_status_badge($status) {
        switch ($status) {
            case 'Completed': return 'bg-success';
            case 'In Progress': return 'bg-info';
            case 'Pending': return 'bg-warning text-dark';
            case 'Open': return 'bg-primary';
            case 'Closed': return 'bg-secondary';
            default: return 'bg-light text-dark';
        }
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0">Task Pipeline (All Tasks)</h1>
                <a href="tasks/manage.php?action=new" class="btn btn-primary">
                    <i class="fas fa-plus me-1"></i> Create New Task
                </a>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>Status</th>
                        <th>Task Title</th>
                        <th>Created By</th>
                        <th>Due Date</th>
                        <th>Progress</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($all_tasks)): ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                No tasks have been created for this company yet.
                                <a href="tasks/manage.php?action=new">Create the first one!</a>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($all_tasks as $task): ?>
                        <tr>
                            <td><span class="badge <?php echo get_task_status_badge($task['status']); ?>"><?php echo htmlspecialchars($task['status']); ?></span></td>
                            <td><?php echo htmlspecialchars($task['title']); ?></td>
                            <td><?php echo htmlspecialchars($task['created_by'] ?? 'System'); ?></td>
                            <td>
                                <?php if($task['due_date']): ?>
                                    <span class="<?php echo (strtotime($task['due_date']) < time() && $task['status'] != 'Completed') ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo date("Y-m-d", strtotime($task['due_date'])); ?>
                                    </span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                    $progress_percent = ($task['total_assigned'] > 0) ? ($task['total_completed'] / $task['total_assigned']) * 100 : 0;
                                ?>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $progress_percent; ?>%;" aria-valuenow="<?php echo $progress_percent; ?>" aria-valuemin="0" aria-valuemax="100">
                                        <?php echo (int)$progress_percent; ?>%
                                    </div>
                                </div>
                                <small class="text-muted"><?php echo $task['total_completed']; ?> of <?php echo $task['total_assigned']; ?> user(s) completed.</small>
                            </td>
                            <td><a href="tasks/manage.php?id=<?php echo $task['task_id']; ?>" class="btn btn-sm btn-primary">View / Edit</a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


