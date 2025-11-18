<?php
// Use the new Task Tracker header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// --- FIX: Define helper function if not already defined ---
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
// --- END FIX ---

$error_message = '';
$success_message = '';
$task_id_from_url = $_GET['id'] ?? null;
$action = $_GET['action'] ?? null; // 'new' or null
$task_details = null;
$sub_tasks = [];
$assigned_users = [];
$all_company_employees = [];
$all_stores = [];
$task_history = [];
$is_manager = has_any_role(['approver', 'admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'trainer']);
$is_assigned = false;
$my_assignment_status = null;

// --- DATA FETCHING (Stores & Employees) ---

// Fetch all company employees for assignment
$sql_emp = "SELECT id, full_name, email FROM users WHERE company_id = ? ORDER BY full_name";
if ($stmt_emp = $conn->prepare($sql_emp)) {
    $stmt_emp->bind_param("i", $company_id_context);
    $stmt_emp->execute();
    $result_emp = $stmt_emp->get_result();
    while ($row_emp = $result_emp->fetch_assoc()) {
        $all_company_employees[] = $row_emp;
    }
    $stmt_emp->close();
}

// Fetch all stores for assignment
$sql_stores = "SELECT id, store_name FROM stores WHERE company_id = ? AND is_active = 1 ORDER BY store_name";
if ($stmt_stores = $conn->prepare($sql_stores)) {
    $stmt_stores->bind_param("i", $company_id_context);
    $stmt_stores->execute();
    $result_stores = $stmt_stores->get_result();
    while ($row_store = $result_stores->fetch_assoc()) {
        $all_stores[] = $row_store;
    }
    $stmt_stores->close();
}


// --- ACTION HANDLING ---

// **FIX 1 (User Request): Handle User Status Update**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user_status'])) {
    $new_status = $_POST['my_status'];
    $comment_text = trim($_POST['comment_text']);
    $task_id_to_update = $_POST['task_id'];

    if ($task_id_to_update == $task_id_from_url && !empty($new_status)) {
        $conn->begin_transaction();
        try {
            // 1. Update the assignment status
            $sql_update_status = "UPDATE task_assignments SET status = ? WHERE task_id = ? AND user_id = ?";
            $stmt_update = $conn->prepare($sql_update_status);
            $stmt_update->bind_param("sii", $new_status, $task_id_to_update, $user_id);
            if (!$stmt_update->execute()) {
                throw new Exception("Failed to update status: " . $stmt_update->error);
            }
            $stmt_update->close();

            // 2. Add comment to history (if provided)
            if (!empty($comment_text)) {
                $history_detail = "User changed status to '{$new_status}' with comment: {$comment_text}";
                $sql_history = "INSERT INTO task_history (task_id, user_id, action, details) VALUES (?, ?, ?, ?)";
                $stmt_history = $conn->prepare($sql_history);
                $action_type = 'Comment';
                $stmt_history->bind_param("iiss", $task_id_to_update, $user_id, $action_type, $history_detail);
                if (!$stmt_history->execute()) {
                    throw new Exception("Failed to save comment: " . $stmt_history->error);
                }
                $stmt_history->close();
            }
            
            $conn->commit();
            $success_message = "Your status has been updated successfully.";

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Error: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid request to update status.";
    }
}


// **FIX 2 (Bug Fix): Handle Add/Update Main Task/Sub-Task**
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_task'])) {
    if ($is_manager) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $due_date = empty($_POST['due_date']) ? null : $_POST['due_date'];
        $status = $_POST['status'] ?? 'Open'; // Default for new tasks
        $assigned_user_ids = $_POST['assigned_users'] ?? [];
        
        // **NEW** Get new fields
        $store_id = $_POST['store_id'] ?? null;
        $priority = $_POST['priority'] ?? 'Medium';
        $category = $_POST['category'] ?? 'General';
        
        $existing_task_id = $_POST['task_id'] ?? null; // For editing
        $main_task_id = $_POST['main_task_id'] ?? null; // For creating sub-tasks

        if (empty($title) || empty($assigned_user_ids)) {
            $error_message = "Task Title and at least one Assigned User are required.";
        } else {
            $conn->begin_transaction();
            try {
                if ($existing_task_id && !$main_task_id) { // This is an EDIT of a main task
                    // Update existing task
                    // **NEW:** Added store_id, priority, category
                    $sql_update_task = "UPDATE tasks SET title = ?, description = ?, due_date = ?, status = ?, store_id = ?, priority = ?, category = ? 
                                        WHERE id = ? AND company_id = ?";
                    $stmt_update = $conn->prepare($sql_update_task);
                    $stmt_update->bind_param("ssssisssi", $title, $description, $due_date, $status, $store_id, $priority, $category, $existing_task_id, $company_id_context);
                    if (!$stmt_update->execute()) {
                        throw new Exception("Failed to update task: " . $stmt_update->error);
                    }
                    $stmt_update->close();
                    $task_id = $existing_task_id;
                    $success_message = "Task updated successfully.";
                    
                    // -- Smart Assignment Update --
                    // 1. Get current assignments
                    $current_assigned = [];
                    $sql_get_assigned = "SELECT user_id FROM task_assignments WHERE task_id = ?";
                    $stmt_get = $conn->prepare($sql_get_assigned);
                    $stmt_get->bind_param("i", $task_id);
                    $stmt_get->execute();
                    $res_get = $stmt_get->get_result();
                    while($row = $res_get->fetch_assoc()) { $current_assigned[] = $row['user_id']; }
                    $stmt_get->close();
                    
                    // 2. Find users to add and remove
                    $users_to_add = array_diff($assigned_user_ids, $current_assigned);
                    $users_to_remove = array_diff($current_assigned, $assigned_user_ids);

                    // 3. Add new users
                    if (!empty($users_to_add)) {
                        $sql_add_assign = "INSERT INTO task_assignments (task_id, user_id, status) VALUES (?, ?, 'Pending')";
                        $stmt_add_assign = $conn->prepare($sql_add_assign);
                        foreach ($users_to_add as $user_to_add_id) {
                            $stmt_add_assign->bind_param("ii", $task_id, $user_to_add_id);
                            $stmt_add_assign->execute();
                        }
                        $stmt_add_assign->close();
                    }
                    
                    // 4. Remove old users
                    if (!empty($users_to_remove)) {
                        $sql_remove_assign = "DELETE FROM task_assignments WHERE task_id = ? AND user_id = ?";
                        $stmt_remove_assign = $conn->prepare($sql_remove_assign);
                        foreach ($users_to_remove as $user_to_remove_id) {
                            $stmt_remove_assign->bind_param("ii", $task_id, $user_to_remove_id);
                            $stmt_remove_assign->execute();
                        }
                        $stmt_remove_assign->close();
                    }

                } else { 
                    // This is a NEW task (either main or sub-task)
                    $parent_store_id = $store_id;
                    $parent_priority = $priority;
                    $parent_category = $category;

                    // **FIX:** If it's a sub-task, inherit properties from parent
                    if (!empty($main_task_id)) {
                        $sql_get_parent = "SELECT store_id, priority, category FROM tasks WHERE id = ? AND company_id = ?";
                        $stmt_parent = $conn->prepare($sql_get_parent);
                        $stmt_parent->bind_param("ii", $main_task_id, $company_id_context);
                        $stmt_parent->execute();
                        $parent_data = $stmt_parent->get_result()->fetch_assoc();
                        if ($parent_data) {
                            $parent_store_id = $parent_data['store_id'];
                            $parent_priority = $parent_data['priority'];
                            $parent_category = $parent_data['category'];
                        }
                        $stmt_parent->close();
                    }
                    
                    // **NEW:** Added store_id, priority, category
                    $sql_insert_task = "INSERT INTO tasks (company_id, created_by_user_id, title, description, status, due_date, main_task_id, store_id, priority, category) 
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    $stmt_insert = $conn->prepare($sql_insert_task);
                    $stmt_insert->bind_param("iissssisis", 
                        $company_id_context, $user_id, $title, $description, 
                        $status, $due_date, $main_task_id, 
                        $parent_store_id, $parent_priority, $parent_category
                    );
                    
                    if (!$stmt_insert->execute()) {
                        throw new Exception("Failed to create task: " . $stmt_insert->error);
                    }
                    $task_id = $conn->insert_id;
                    $stmt_insert->close();
                    $success_message = "Task created successfully.";
                    
                    // Assign users
                    $sql_assign = "INSERT INTO task_assignments (task_id, user_id, status) VALUES (?, ?, 'Pending')";
                    $stmt_assign = $conn->prepare($sql_assign);
                    foreach ($assigned_user_ids as $assigned_user_id) {
                        $stmt_assign->bind_param("ii", $task_id, $assigned_user_id);
                        $stmt_assign->execute();
                    }
                    $stmt_assign->close();
                }

                $conn->commit();
                
                // If it was a new main task, redirect to the new manage page
                if (!$existing_task_id && !$main_task_id) {
                    header("location: " . BASE_URL . "tasks/manage.php?id=" . $task_id);
                    exit;
                }
                // If it was a sub-task, just reload the current page
                if ($main_task_id) {
                    // Refresh data for the parent task
                    header("location: " . BASE_URL . "tasks/manage.php?id=" . $main_task_id);
                    exit; 
                } else {
                    // Refresh data for the edited task
                     header("location: " . BASE_URL . "tasks/manage.php?id=" . $task_id);
                    exit;
                }

            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } else {
        $error_message = "You do not have permission to create or edit tasks.";
    }
}


// --- PAGE LOAD DATA FETCHING ---

if ($action === 'new' && $is_manager) {
    // This is the "Create New Task" page
    $task_details = [
        'id' => null, 'title' => '', 'description' => '', 'status' => 'Open', 'due_date' => null, 'main_task_id' => null,
        'store_id' => null, 'priority' => 'Medium', 'category' => 'General' // **NEW** defaults
    ];
    $assigned_users = [];
    $sub_tasks = [];
    
} elseif ($task_id_from_url) {
    // This is the "View/Manage Existing Task" page
    
    // 1. Fetch main task details
    // UPDATED: Fetched new columns
    $sql_task = "SELECT 
                    t.*, 
                    c.full_name as created_by_name,
                    s.store_name
                 FROM tasks t
                 LEFT JOIN users c ON t.created_by_user_id = c.id
                 LEFT JOIN stores s ON t.store_id = s.id
                 WHERE t.id = ? AND t.company_id = ?";
                 
    if ($stmt_task = $conn->prepare($sql_task)) {
        $stmt_task->bind_param("ii", $task_id_from_url, $company_id_context);
        if ($stmt_task->execute()) {
            $task_details = $stmt_task->get_result()->fetch_assoc();
        } else {
            $error_message = "Error fetching task details: " . $stmt_task->error;
        }
        $stmt_task->close();
    } else {
        $error_message = "DB Error (Task): " . $conn->error;
    }

    if ($task_details) {
        // 2. Fetch assigned users
        $sql_assigned = "SELECT u.id, u.full_name, u.email, ta.status
                         FROM task_assignments ta
                         JOIN users u ON ta.user_id = u.id
                         WHERE ta.task_id = ?";
        if ($stmt_assigned = $conn->prepare($sql_assigned)) {
            $stmt_assigned->bind_param("i", $task_id_from_url);
            $stmt_assigned->execute();
            $result_assigned = $stmt_assigned->get_result();
            while ($row = $result_assigned->fetch_assoc()) {
                $assigned_users[] = $row;
                if ($row['id'] == $user_id) {
                    $is_assigned = true;
                    $my_assignment_status = $row['status'];
                }
            }
            $stmt_assigned->close();
        }

        // 3. Fetch sub-tasks (if this is a main task)
        if (empty($task_details['main_task_id'])) {
            $sql_sub = "SELECT 
                            t.id, t.title, t.status, t.due_date,
                            (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id) as total_assigned,
                            (SELECT COUNT(*) FROM task_assignments ta WHERE ta.task_id = t.id AND ta.status = 'Completed') as total_completed
                        FROM tasks t
                        WHERE t.main_task_id = ? AND t.company_id = ?
                        ORDER BY t.due_date ASC, t.status";
            if ($stmt_sub = $conn->prepare($sql_sub)) {
                $stmt_sub->bind_param("ii", $task_id_from_url, $company_id_context);
                $stmt_sub->execute();
                $result_sub = $stmt_sub->get_result();
                while ($row_sub = $result_sub->fetch_assoc()) {
                    $sub_tasks[] = $row_sub;
                }
                $stmt_sub->close();
            }
        }
        
        // 4. Fetch task history
        $sql_history = "SELECT h.*, u.full_name 
                        FROM task_history h
                        JOIN users u ON h.user_id = u.id
                        WHERE h.task_id = ?
                        ORDER BY h.timestamp DESC";
        if($stmt_history = $conn->prepare($sql_history)) {
            $stmt_history->bind_param("i", $task_id_from_url);
            $stmt_history->execute();
            $result_history = $stmt_history->get_result();
            while($row_history = $result_history->fetch_assoc()) {
                $task_history[] = $row_history;
            }
            $stmt_history->close();
        }

        // 5. Security Check: Allow if manager OR assigned
        if (!$is_manager && !$is_assigned) {
            $error_message = "You do not have permission to view this task.";
            $task_details = null; // Block access
        }

    } else if (empty($error_message)) {
        // Only set this if no DB error occurred before
        $error_message = "Task not found or you do not have permission.";
    }
} else if ($action !== 'new') { 
    // Neither creating new nor viewing existing - invalid request
     $error_message = "Invalid task request.";
}

?>

<div class="container-fluid">
    <div class="row">
        
        <?php if ($error_message): ?>
            <div class="col-12">
                <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
                <a href="tasks/index.php" class="btn btn-primary">Back to My Tasks</a>
                 <?php if ($is_manager): ?>
                    <a href="tasks/pipeline.php" class="btn btn-secondary">Go to Task Pipeline</a>
                <?php endif; ?>
            </div>
        <?php elseif ($action === 'new' && $is_manager): ?>
            <!-- "CREATE NEW TASK" VIEW -->
            <div class="col-12">
                <h1 class="h3 mb-3">Create New Task</h1>
                <div class="card">
                    <div class="card-body">
                        <!-- Use the "save_task" action. No task_id or main_task_id needed. -->
                        <form action="tasks/manage.php?action=new" method="POST">
                            <?php 
                            // Start of inlined task_form_fields.php
                            // For NEW tasks, $current_assigned_ids is empty.
                            $current_assigned_ids = []; 
                            ?>
                            <div class="mb-3">
                                <label for="title" class="form-label">Task Title <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($task_details['title'] ?? ''); ?>" required>
                            </div>
                        
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($task_details['description'] ?? ''); ?></textarea>
                            </div>
                        
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="store_id" class="form-label">Store / Cost Center</label>
                                    <select class="form-select" id="store_id" name="store_id">
                                        <option value="">None</option>
                                        <?php foreach($all_stores as $store): ?>
                                            <option value="<?php echo $store['id']; ?>" <?php echo (isset($task_details['store_id']) && $task_details['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($store['store_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="due_date" class="form-label">Due Date</label>
                                    <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo htmlspecialchars($task_details['due_date'] ?? ''); ?>">
                                </div>
                            </div>
                        
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="priority" class="form-label">Priority</label>
                                    <select class="form-select" id="priority" name="priority">
                                        <option value="Low" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                                        <option value="Medium" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                        <option value="High" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="category" class="form-label">Category</label>
                                    <input type="text" class="form-control" id="category" name="category" value="<?php echo htmlspecialchars($task_details['category'] ?? 'General'); ?>">
                                </div>
                            </div>
                        
                            <?php // For NEW tasks, status is Open by default and hidden ?>
                            <input type="hidden" name="status" value="Open">
                        
                            <div class="mb-3">
                                <label for="assigned_users" class="form-label">Assign To <span class="text-danger">*</span></label>
                                <select class="form-select" id="assigned_users" name="assigned_users[]" required multiple size="8">
                                    <?php 
                                    foreach($all_company_employees as $emp): ?>
                                        <option value="<?php echo $emp['id']; ?>" <?php echo in_array($emp['id'], $current_assigned_ids) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['email'] . ')'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">Hold Ctrl/Cmd to select multiple users.</div>
                            </div>
                            <!-- End of inlined task_form_fields.php -->
                            
                            <div class="mt-3">
                                <a href="tasks/pipeline.php" class="btn btn-secondary">Cancel</a>
                                <button type="submit" name="save_task" class="btn btn-primary">Create Task</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        
        <?php elseif ($task_details): ?>
            <!-- "VIEW/MANAGE TASK" VIEW -->
            
            <!-- Page Header -->
            <div class="col-12 mb-3 d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-0"><?php echo htmlspecialchars($task_details['title']); ?></h1>
                    <span class="badge <?php echo get_task_status_badge($task_details['status']); ?> fs-6">
                        <?php echo htmlspecialchars($task_details['status']); ?>
                    </span>
                    <?php if($task_details['main_task_id']): ?>
                        <span class="ms-2 text-muted">
                            <i class="fas fa-level-up-alt fa-rotate-90"></i>
                            Sub-task of: <a href="tasks/manage.php?id=<?php echo $task_details['main_task_id']; ?>">Parent Task</a>
                        </span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($is_manager): ?>
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#editTaskModal">
                            <i class="fas fa-edit me-1"></i> Edit Task
                        </button>
                        <?php if (empty($task_details['main_task_id'])): // Only allow adding sub-tasks to main tasks ?>
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addSubTaskModal">
                                <i class="fas fa-plus me-1"></i> Add Sub-Task
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($success_message): ?>
                <div class="col-12">
                    <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
                </div>
            <?php endif; ?>

            <!-- Main Content Area -->
            <div class="col-lg-8">
                <!-- Task Description -->
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Task Description</h5></div>
                    <div class="card-body">
                        <p><?php echo nl2br(htmlspecialchars($task_details['description'])); ?></p>
                    </div>
                </div>
                
                <!-- **NEW:** "Update Your Status" Card (for assigned users) -->
                <?php if ($is_assigned && !$is_manager): // Show for assigned non-managers ?>
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Update Your Status</h5></div>
                    <div class="card-body">
                        <form action="tasks/manage.php?id=<?php echo $task_id_from_url; ?>" method="POST">
                            <input type="hidden" name="task_id" value="<?php echo $task_id_from_url; ?>">
                            <div class="mb-3">
                                <label for="my_status" class="form-label">My Status</label>
                                <select class="form-select" id="my_status" name="my_status">
                                    <option value="Pending" <?php echo ($my_assignment_status == 'Pending') ? 'selected' : ''; ?>>Pending</option>
                                    <option value="In Progress" <?php echo ($my_assignment_status == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="Completed" <?php echo ($my_assignment_status == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label for="comment_text" class="form-label">Add a Comment (Optional)</label>
                                <textarea class="form-control" id="comment_text" name="comment_text" rows="3"></textarea>
                            </div>
                            <button type="submit" name="update_user_status" class="btn btn-primary">Update Status</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>


                <!-- Sub-Tasks (if this is a main task) -->
                <?php if (!empty($sub_tasks)): ?>
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Sub-Tasks</h5></div>
                    <div class="card-body p-0">
                        <ul class="list-group list-group-flush">
                            <?php foreach($sub_tasks as $sub): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <a href="tasks/manage.php?id=<?php echo $sub['id']; ?>"><?php echo htmlspecialchars($sub['title']); ?></a>
                                    <small class="d-block text-muted">
                                        Status: <span class="badge <?php echo get_task_status_badge($sub['status']); ?>"><?php echo htmlspecialchars($sub['status']); ?></span>
                                        | Due: <?php echo htmlspecialchars($sub['due_date'] ?? 'N/A'); ?>
                                    </small>
                                </div>
                                <div class="text-end">
                                    <span class="badge bg-secondary rounded-pill"><?php echo $sub['total_completed']; ?>/<?php echo $sub['total_assigned']; ?> Done</span>
                                </div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Task History / Comments -->
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Task History & Comments</h5></div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php if (empty($task_history)): ?>
                                <li class="list-group-item">No history or comments for this task yet.</li>
                            <?php endif; ?>
                            <?php foreach($task_history as $history): ?>
                            <li class="list-group-item">
                                <p class="mb-1"><?php echo nl2br(htmlspecialchars($history['details'])); ?></p>
                                <small class="text-muted">
                                    By <?php echo htmlspecialchars($history['full_name']); ?> 
                                    on <?php echo date("Y-m-d H:i", strtotime($history['timestamp'])); ?>
                                </small>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>

            </div>
            
            <!-- Sidebar Area -->
            <div class="col-lg-4">
                <!-- Task Info Card -->
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Task Info</h5></div>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item"><strong>Created By:</strong> <?php echo htmlspecialchars($task_details['created_by_name'] ?? 'System'); ?></li>
                        <li class="list-group-item"><strong>Created At:</strong> <?php echo date("Y-m-d", strtotime($task_details['created_at'])); ?></li>
                        <li class="list-group-item"><strong>Due Date:</strong> <?php echo htmlspecialchars($task_details['due_date'] ?? 'N/A'); ?></li>
                        <!-- **NEW:** Display new fields -->
                        <li class="list-group-item"><strong>Store:</strong> <?php echo htmlspecialchars($task_details['store_name'] ?? 'N/A'); ?></li>
                        <li class="list-group-item"><strong>Priority:</strong> <?php echo htmlspecialchars($task_details['priority'] ?? 'N/A'); ?></li>
                        <li class="list-group-item"><strong>Category:</strong> <?php echo htmlspecialchars($task_details['category'] ?? 'N/A'); ?></li>
                    </ul>
                </div>

                <!-- Assigned Users Card -->
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title mb-0">Assigned Users</h5></div>
                    <ul class="list-group list-group-flush">
                        <?php if (empty($assigned_users)): ?>
                            <li class="list-group-item">No users assigned.</li>
                        <?php endif; ?>
                        <?php foreach($assigned_users as $user): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                <?php echo htmlspecialchars($user['full_name']); ?>
                                <small class="d-block text-muted"><?php echo htmlspecialchars($user['email']); ?></small>
                            </div>
                            <span class="badge <?php echo get_task_status_badge($user['status']); ?>"><?php echo htmlspecialchars($user['status']); ?></span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>

        <?php endif; ?>

    </div> <!-- / .row -->
</div> <!-- / .container-fluid -->


<?php if ($is_manager && $task_details && $action !== 'new'): ?>
<!-- MODALS (Only render if manager is viewing a task) -->

<!-- **UPDATED:** Modal: Edit Task -->
<div class="modal fade" id="editTaskModal" tabindex="-1" aria-labelledby="editTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editTaskModalLabel">Edit Task</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- Use the "save_task" action. Pass the task_id. -->
            <form action="tasks/manage.php?id=<?php echo $task_id_from_url; ?>" method="POST">
                <input type="hidden" name="task_id" value="<?php echo $task_id_from_url; ?>">
                <div class="modal-body">
                    <?php 
                    // This include requires $task_details and $all_company_employees to be set
                    // We also need $assigned_users to pre-select
                    $current_assigned_ids = array_column($assigned_users, 'id');
                    // include 'includes/task_form_fields.php'; 
                    // Start of inlined task_form_fields.php
                    ?>
                    <div class="mb-3">
                        <label for="title" class="form-label">Task Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" value="<?php echo htmlspecialchars($task_details['title'] ?? ''); ?>" required>
                    </div>
                
                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea class="form-control" id="description" name="description" rows="4"><?php echo htmlspecialchars($task_details['description'] ?? ''); ?></textarea>
                    </div>
                
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="store_id" class="form-label">Store / Cost Center</label>
                            <select class="form-select" id="store_id" name="store_id">
                                <option value="">None</option>
                                <?php foreach($all_stores as $store): ?>
                                    <option value="<?php echo $store['id']; ?>" <?php echo (isset($task_details['store_id']) && $task_details['store_id'] == $store['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($store['store_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="due_date" class="form-label">Due Date</label>
                            <input type="date" class="form-control" id="due_date" name="due_date" value="<?php echo htmlspecialchars($task_details['due_date'] ?? ''); ?>">
                        </div>
                    </div>
                
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="priority" class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority">
                                <option value="Low" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'Low') ? 'selected' : ''; ?>>Low</option>
                                <option value="Medium" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'Medium') ? 'selected' : ''; ?>>Medium</option>
                                <option value="High" <?php echo (isset($task_details['priority']) && $task_details['priority'] == 'High') ? 'selected' : ''; ?>>High</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="category" class="form-label">Category</label>
                            <input type="text" class="form-control" id="category" name="category" value="<?php echo htmlspecialchars($task_details['category'] ?? 'General'); ?>">
                        </div>
                    </div>
                
                    <?php if (isset($task_details['id']) && $task_details['id']): // Only show Status for EDITING tasks ?>
                    <div class="mb-3">
                        <label for="status" class="form-label">Main Task Status</label>
                        <select class="form-select" id="status" name="status">
                            <option value="Open" <?php echo (isset($task_details['status']) && $task_details['status'] == 'Open') ? 'selected' : ''; ?>>Open</option>
                            <option value="In Progress" <?php echo (isset($task_details['status']) && $task_details['status'] == 'In Progress') ? 'selected' : ''; ?>>In Progress</option>
                            <option value="Completed" <?php echo (isset($task_details['status']) && $task_details['status'] == 'Completed') ? 'selected' : ''; ?>>Completed</option>
                            <option value="Closed" <?php echo (isset($task_details['status']) && $task_details['status'] == 'Closed') ? 'selected' : ''; ?>>Closed</option>
                        </select>
                    </div>
                    <?php else: // This is a NEW task, so status is Open by default and hidden ?>
                        <input type="hidden" name="status" value="Open">
                    <?php endif; ?>
                
                    <div class="mb-3">
                        <label for="assigned_users" class="form-label">Assign To <span class="text-danger">*</span></label>
                        <select class="form-select" id="assigned_users" name="assigned_users[]" required multiple size="8">
                            <?php 
                            // Ensure $current_assigned_ids is an array even if not set
                            if (!isset($current_assigned_ids)) { $current_assigned_ids = []; }
                            foreach($all_company_employees as $emp): ?>
                                <option value="<?php echo $emp['id']; ?>" <?php echo in_array($emp['id'], $current_assigned_ids) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['email'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Hold Ctrl/Cmd to select multiple users.</div>
                    </div>
                    <!-- End of inlined task_form_fields.php -->

                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_task" class="btn btn-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Sub-Task -->
<?php if (empty($task_details['main_task_id'])): // Only show if parent is a main task ?>
<div class="modal fade" id="addSubTaskModal" tabindex="-1" aria-labelledby="addSubTaskModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="addSubTaskModalLabel">Add Sub-Task for "<?php echo htmlspecialchars($task_details['title']); ?>"</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- Use the "save_task" action. Pass the main_task_id. -->
            <form action="tasks/manage.php?id=<?php echo $task_id_from_url; ?>" method="POST">
                <input type="hidden" name="main_task_id" value="<?php echo $task_id_from_url; ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="sub_title" class="form-label">Sub-Task Title <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sub_title" name="title" required>
                    </div>
                    <div class="mb-3">
                        <label for="sub_description" class="form-label">Description</label>
                        <textarea class="form-control" id="sub_description" name="description" rows="3"></textarea>
                    </div>
                    <div class="mb-3">
                        <label for="sub_due_date" class="form-label">Due Date</label>
                        <input type="date" class="form-control" id="sub_due_date" name="due_date">
                    </div>
                    <div class="mb-3">
                        <label for="sub_assigned_users" class="form-label">Assign To <span class="text-danger">*</span></label>
                        <select class="form-select" id="sub_assigned_users" name="assigned_users[]" required multiple size="5">
                            <?php 
                            // Reuse $all_company_employees
                            foreach($all_company_employees as $emp): ?>
                            <option value="<?php echo $emp['id']; ?>">
                                <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['email'] . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">The selected user(s) will see this on their Task Dashboard.</div>
                    </div>
                    <input type="hidden" name="status" value="Open">
                    <!-- **NOTE:** store_id, priority, category will be inherited from parent task -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="save_task" class="btn btn-primary">Create Sub-Task</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



