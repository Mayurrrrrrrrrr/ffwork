<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS AN ADMIN --
if (!check_role('admin')) {
    header("location: " . BASE_URL . "login.php"); // Root-relative path
    exit;
}

// -- ENSURE COMPANY ID IS AVAILABLE --
$company_id = get_current_company_id();
if (!$company_id) {
    session_destroy();
    header("location: " . BASE_URL . "login.php?error=session_error"); // Root-relative path
    exit;
}

$error_message = '';
$success_message = '';
$edit_category = null; // Variable to hold category data for editing

// --- FORM HANDLING ---

// Handle Deleting a Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $category_id_to_delete = $_POST['category_id'];
    $sql_delete = "DELETE FROM expense_categories WHERE id = ? AND company_id = ?";
    if ($stmt_delete = $conn->prepare($sql_delete)) {
        $stmt_delete->bind_param("ii", $category_id_to_delete, $company_id);
        if ($stmt_delete->execute() && $stmt_delete->affected_rows > 0) {
            $success_message = "Category deleted successfully.";
        } else {
            $error_message = "Error deleting category or category not found.";
        }
        $stmt_delete->close();
    }
}

// Handle Adding or Editing a Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_category']) || isset($_POST['update_category']))) {
    $category_name = trim($_POST['category_name']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $category_id_to_edit = $_POST['edit_category_id'] ?? null;

    if (empty($category_name)) {
        $error_message = "Category name cannot be empty.";
    } else {
        if ($category_id_to_edit) {
            // Update Existing Category
            $sql = "UPDATE expense_categories SET category_name = ?, is_active = ? WHERE id = ? AND company_id = ?";
            if ($stmt = $conn->prepare($sql)) {
                $stmt->bind_param("siii", $category_name, $is_active, $category_id_to_edit, $company_id);
                if ($stmt->execute()) {
                    $success_message = "Category updated successfully.";
                } else {
                    $error_message = "Error updating category: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            // Add New Category - Check for duplicates first
            $sql_check = "SELECT id FROM expense_categories WHERE category_name = ? AND company_id = ?";
            if ($stmt_check = $conn->prepare($sql_check)) {
                $stmt_check->bind_param("si", $category_name, $company_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $error_message = "A category with this name already exists.";
                } else {
                    // Insert new category
                    $sql_insert = "INSERT INTO expense_categories (company_id, category_name, is_active) VALUES (?, ?, ?)";
                    if ($stmt_insert = $conn->prepare($sql_insert)) {
                        $stmt_insert->bind_param("isi", $company_id, $category_name, $is_active);
                        if ($stmt_insert->execute()) {
                            $success_message = "Category added successfully.";
                        } else {
                            $error_message = "Error adding category: " . $stmt_insert->error;
                        }
                        $stmt_insert->close();
                    }
                }
                $stmt_check->close();
            }
        }
    }
}

// Handle request to edit (load data into form)
if (isset($_GET['edit_id'])) {
    $edit_id = $_GET['edit_id'];
    $sql_edit = "SELECT * FROM expense_categories WHERE id = ? AND company_id = ?";
    if ($stmt_edit = $conn->prepare($sql_edit)) {
        $stmt_edit->bind_param("ii", $edit_id, $company_id);
        $stmt_edit->execute();
        $result = $stmt_edit->get_result();
        $edit_category = $result->fetch_assoc();
        $stmt_edit->close();
        if (!$edit_category) {
            $error_message = "Category not found for editing.";
        }
    }
}

// --- DATA FETCHING ---
// Fetch all categories for the current company
$categories = [];
$sql_fetch = "SELECT * FROM expense_categories WHERE company_id = ? ORDER BY category_name";
if ($stmt_fetch = $conn->prepare($sql_fetch)) {
    $stmt_fetch->bind_param("i", $company_id);
    if ($stmt_fetch->execute()) {
        $result = $stmt_fetch->get_result();
        while ($row = $result->fetch_assoc()) {
            $categories[] = $row;
        }
    } else {
        $error_message = "Error fetching categories.";
    }
    $stmt_fetch->close();
}

require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Manage Expense Categories</h2>
    <p>Add, edit, or disable expense categories for your company.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <!-- Add/Edit Category Form -->
    <div class="card mb-4">
        <div class="card-header">
            <h4><?php echo $edit_category ? 'Edit Category' : 'Add New Category'; ?></h4>
        </div>
        <div class="card-body">
            <form action="admin/categories.php" method="post">
                <?php if ($edit_category): ?>
                    <input type="hidden" name="edit_category_id" value="<?php echo $edit_category['id']; ?>">
                <?php endif; ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label for="category_name" class="form-label">Category Name</label>
                        <input type="text" class="form-control" id="category_name" name="category_name" 
                               value="<?php echo htmlspecialchars($edit_category['category_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" 
                                   <?php echo ($edit_category === null || $edit_category['is_active']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="is_active">Active</label>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <?php if ($edit_category): ?>
                            <button type="submit" name="update_category" class="btn btn-warning">Update Category</button>
                            <a href="admin/categories.php" class="btn btn-secondary">Cancel Edit</a>
                        <?php else: ?>
                            <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Existing Categories List -->
    <div class="card">
        <div class="card-header">
            <h4>Existing Categories</h4>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Category Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr><td colspan="3">No categories defined yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($categories as $category): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category_name']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $category['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo $category['is_active'] ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="admin/categories.php?edit_id=<?php echo $category['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                                        <form action="admin/categories.php" method="post" class="d-inline" onsubmit="return confirm('Delete this category?');">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <button type="submit" name="delete_category" class="btn btn-danger btn-sm">Delete</button>
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


