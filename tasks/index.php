<?php
// Use the new Task Tracker header
// UPDATED: Changed to header.php to get correct company context
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = '';
$my_tasks = [];

// --- FIX: Define $is_manager in this scope --
$is_manager = has_any_role(['approver', 'admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'trainer']);

// --- DATA FETCHING (ROLE-AWARE & COMPANY-AWARE) ---

// 1. Fetch all tasks assigned to the current user
// UPDATED: Added main_task_id and parent_task.title to identify and group sub-tasks
$sql_tasks = "SELECT 
                t.id as task_id,
                t.title,
                t.status as main_task_status,
                t.due_date,
                ta.status as my_status,
                c.full_name as created_by,
                t.main_task_id,
                parent_task.title as parent_task_title
              FROM tasks t
              JOIN task_assignments ta ON t.id = ta.task_id
              LEFT JOIN users c ON t.created_by_user_id = c.id
              LEFT JOIN tasks parent_task ON t.main_task_id = parent_task.id
              WHERE ta.user_id = ? AND t.company_id = ?
              ORDER BY 
                -- Group sub-tasks under their main task
                COALESCE(t.main_task_id, t.id) ASC, -- Sort by parent task ID, or self if no parent
                t.main_task_id IS NOT NULL ASC, -- Ensure parent task (NULL) comes before children (NOT NULL)
                t.due_date ASC,
                t.status,
                ta.status";
    
if($stmt_tasks = $conn->prepare($sql_tasks)) {
    // UPDATED: Use $company_id_context from header.php
    $stmt_tasks->bind_param("ii", $user_id, $company_id_context);
    if($stmt_tasks->execute()) {
        $result = $stmt_tasks->get_result();
        while($row = $result->fetch_assoc()) { 
            $my_tasks[] = $row; 
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
if (!function_exists('get_priority_badge_class')) {
    function get_priority_badge_class($priority) {
        switch ($priority) {
            case 'High': return 'bg-danger';
            case 'Medium': return 'bg-warning text-dark';
            case 'Low': return 'bg-success';
            default: return 'bg-secondary';
        }
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h1 class="h3 mb-0">My Tasks</h1>
                <?php if ($is_manager): ?>
                    <!-- UPDATED: Added a clear link to the admin/manager pipeline -->
                    <a href="tasks/pipeline.php" class="btn btn-primary">
                        <i class="fas fa-clipboard-list me-1"></i> View All Tasks (Admin Dashboard)
                    </a>
                <?php endif; ?>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>

            <table class="table table-striped table-hover">
                <thead class="table-dark">
                    <tr>
                        <th>My Status</th>
                        <th>Task Title</th>
                        <th>Created By</th>
                        <th>Due Date</th>
                        <th>Main Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($my_tasks)): ?>
                        <tr>
                            <td colspan="6" class="text-center">
                                You have no tasks assigned directly to you.
                                <?php if ($is_manager): ?>
                                    <!-- UPDATED: Added helpful text for managers -->
                                    <br>
                                    <a href="tasks/pipeline.php">Go to the Admin Dashboard to see all company tasks.</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach($my_tasks as $task): ?>
                        <tr>
                            <td><span class="badge <?php echo get_task_status_badge($task['my_status']); ?>"><?php echo htmlspecialchars($task['my_status']); ?></span></td>
                            
                            <!-- UPDATED: Task Title column now shows sub-task hierarchy -->
                            <td>
                                <?php if ($task['main_task_id'] && $task['parent_task_title']): ?>
                                    <span class="ms-3 d-block">
                                        <i class="fas fa-level-up-alt fa-rotate-90 text-muted me-2"></i>
                                        <?php echo htmlspecialchars($task['title']); ?>
                                        <small class="d-block text-muted">
                                            Sub-task of: <?php echo htmlspecialchars($task['parent_task_title']); ?>
                                        </small>
                                    </span>
                                <?php else: ?>
                                    <strong><?php echo htmlspecialchars($task['title']); ?></strong>
                                <?php endif; ?>
                            </td>
                            
                            <td><?php echo htmlspecialchars($task['created_by'] ?? 'System'); ?></td>
                            <td>
                                <?php if($task['due_date']): ?>
                                    <span class="<?php echo (strtotime($task['due_date']) < time() && $task['my_status'] != 'Completed') ? 'text-danger fw-bold' : ''; ?>">
                                        <?php echo date("Y-m-d", strtotime($task['due_date'])); ?>
                                    </span>
                                <?php else: ?>
                                    N/A
                                <?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo get_task_status_badge($task['main_task_status']); ?>"><?php echo htmlspecialchars($task['main_task_status']); ?></span></td>
                            <td><a href="tasks/manage.php?id=<?php echo $task['task_id']; ?>" class="btn btn-sm btn-primary">View / Update</a></td>
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


