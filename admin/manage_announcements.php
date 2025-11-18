<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php'; // Provides $conn, role checks, $company_id_context

// -- SECURITY CHECK: ENSURE USER IS A COMPANY ADMIN OR PLATFORM ADMIN --
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "admin/index.php"); 
    exit;
}

// -- DETERMINE COMPANY CONTEXT --
$company_id_context = null;
$company_name_context = "Your Company";
$is_platform_admin = check_role('platform_admin');
$user_id = $_SESSION['user_id'] ?? null; // <-- THIS IS THE FIX

if ($is_platform_admin) {
    if (isset($_GET['company_id']) && is_numeric($_GET['company_id'])) {
        $company_id_context = (int)$_GET['company_id'];
        // Fetch company name for display
        $sql_get_cname = "SELECT company_name FROM companies WHERE id = ?";
        if($stmt_cname = $conn->prepare($sql_get_cname)){
            $stmt_cname->bind_param("i", $company_id_context);
            $stmt_cname->execute();
            $stmt_cname->bind_result($fetched_company_name);
            $stmt_cname->fetch();
            $stmt_cname->close();
            if(empty($fetched_company_name)) {
                 die("Invalid Company ID specified."); // Or redirect
            }
             $company_name_context = htmlspecialchars($fetched_company_name);
        }
    } else {
        // Platform admin must select a company
        header("location: " . BASE_URL . "platform_admin/companies.php?error=select_company_to_manage_announcements"); 
        exit;
    }
} else {
    // Company Admin uses their own company ID from the session
    $company_id_context = get_current_company_id();
     if (!$company_id_context) {
        session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
    }
}


$error_message = '';
$success_message = '';
$edit_announcement = null; // Variable to hold data for editing

// --- ACTION HANDLING ---

// Handle Deleting an Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_announcement'])) {
    $announcement_id = $_POST['announcement_id'];
    
    $sql_delete = "DELETE FROM announcements WHERE id = ? AND company_id = ?";
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("ii", $announcement_id, $company_id_context);
        if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
            $success_message = "Announcement deleted successfully.";
            log_audit_action($conn, 'announcement_deleted', "Admin deleted announcement ID: {$announcement_id}", $user_id, $company_id_context, 'announcement', $announcement_id);
        } else { $error_message = "Error deleting announcement or announcement not found."; }
        $stmt_delete->close();
    }
}

// Handle Adding or Editing an Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_announcement']) || isset($_POST['update_announcement']))) {
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);
    $post_date = $_POST['post_date'];
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $edit_announcement_id = $_POST['edit_announcement_id'] ?? null;

    if (empty($title) || empty($content) || empty($post_date)) {
        $error_message = "Title, Content, and Post Date are required.";
    } else {
        if ($edit_announcement_id) {
            // Update Existing
            $sql = "UPDATE announcements SET title = ?, content = ?, post_date = ?, is_active = ? 
                    WHERE id = ? AND company_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("sssiii", $title, $content, $post_date, $is_active, $edit_announcement_id, $company_id_context);
                if ($stmt->execute()) {
                    $success_message = "Announcement updated successfully.";
                    log_audit_action($conn, 'announcement_updated', "Admin updated announcement ID: {$edit_announcement_id}", $user_id, $company_id_context, 'announcement', $edit_announcement_id);
                } else { $error_message = "Error updating announcement: " . $stmt->error; }
                $stmt->close();
            }
        } else {
            // Add New
            $sql_insert = "INSERT INTO announcements (company_id, title, content, post_date, is_active) VALUES (?, ?, ?, ?, ?)";
            if ($stmt_insert = $conn->prepare($sql_insert)) {
                $stmt_insert->bind_param("isssi", $company_id_context, $title, $content, $post_date, $is_active);
                if ($stmt_insert->execute()) {
                    $new_id = $conn->insert_id;
                    $success_message = "Announcement added successfully.";
                    log_audit_action($conn, 'announcement_created', "Admin created announcement: {$title}", $user_id, $company_id_context, 'announcement', $new_id);
                } else { $error_message = "Error adding announcement: " . $stmt_insert->error; }
                $stmt_insert->close();
            }
        }
    }
    
    if(!empty($error_message) && $edit_announcement_id) {
        $edit_announcement = ['id' => $edit_announcement_id, 'title' => $title, 'content' => $content, 'post_date' => $post_date, 'is_active' => $is_active];
    }
}

// Handle request to edit (load data into form)
if (isset($_GET['edit_id']) && empty($_POST)) { // Check empty POST
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT * FROM announcements WHERE id = ? AND company_id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("ii", $edit_id, $company_id_context);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_announcement = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_announcement) { $error_message = "Announcement not found for editing."; }
    }
}

// --- DATA FETCHING ---
// Fetch all announcements for the current company
$announcements_list = [];
$sql_fetch = "SELECT * FROM announcements WHERE company_id = ? ORDER BY post_date DESC";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $company_id_context);
    if ($stmt_fetch->execute()) {
        $result = $stmt_fetch->get_result();
        while ($row = $result->fetch_assoc()) {
            $announcements_list[] = $row;
        }
    } else { $error_message = "Error fetching announcements."; }
    $stmt_fetch->close();
}

require_once '../includes/header.php'; 
?>

<div class="container mt-4">
    <h2>Manage Announcements</h2>
    <p>Create, edit, or deactivate company-wide announcements.</p>
    <?php if ($is_platform_admin): ?>
    <div class="alert alert-info">
        You are managing announcements for: <strong><?php echo $company_name_context; ?></strong>. <a href="platform_admin/companies.php">Return to Company List</a>.
    </div>
    <?php endif; ?>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h4><?php echo $edit_announcement ? 'Edit Announcement' : 'Add New Announcement'; ?></h4>
        </div>
        <div class="card-body">
            <form action="admin/manage_announcements.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post">
                <?php if ($edit_announcement): ?>
                    <input type="hidden" name="edit_announcement_id" value="<?php echo $edit_announcement['id']; ?>">
                <?php endif; ?>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label for="title" class="form-label">Title</label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?php echo htmlspecialchars($edit_announcement['title'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label for="post_date" class="form-label">Post Date</label>
                        <input type="date" class="form-control" id="post_date" name="post_date" 
                               value="<?php echo htmlspecialchars($edit_announcement['post_date'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-12">
                        <label for="content" class="form-label">Content</label>
                        <textarea class="form-control" id="content" name="content" rows="3" required><?php echo htmlspecialchars($edit_announcement['content'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12">
                         <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   value="1" <?php echo ($edit_announcement === null || $edit_announcement['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active (Visible on Portal Home)</label>
                        </div>
                    </div>
                </div>
                <div class="mt-3">
                    <?php if ($edit_announcement): ?>
                        <button type="submit" name="update_announcement" class="btn btn-warning">Update Announcement</button>
                        <a href="admin/manage_announcements.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" class="btn btn-secondary">Cancel Edit</a>
                    <?php else: ?>
                        <button type="submit" name="add_announcement" class="btn btn-primary">Add Announcement</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Announcements List -->
    <div class="card">
        <div class="card-header">
            <h4>Existing Announcements</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Post Date</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($announcements_list)): ?>
                            <tr><td colspan="4" class="text-center text-muted">No announcements found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($announcements_list as $ann): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ann['title']); ?></td>
                                    <td><?php echo htmlspecialchars($ann['post_date']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $ann['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $ann['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="admin/manage_announcements.php?<?php echo $is_platform_admin ? 'company_id='.$company_id_context.'&' : ''; ?>edit_id=<?php echo $ann['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="admin/manage_announcements.php<?php echo $is_platform_admin ? '?company_id='.$company_id_context : ''; ?>" method="post" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this announcement?');">
                                            <input type="hidden" name="announcement_id" value="<?php echo $ann['id']; ?>">
                                            <button type="submit" name="delete_announcement" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
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
if(isset($conn)) $conn->close();
require_once '../includes/footer.php'; 
?>



