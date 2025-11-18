<?php
// Use the new Learning Portal header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS A TRAINER/ADMIN --
if (!has_any_role(['trainer', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "learning/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$course_id_from_url = $_GET['course_id'] ?? null; // Pre-select course if coming from link

// --- ACTION HANDLING: ASSIGN COURSE ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_course'])) {
    $course_id = $_POST['course_id'];
    $user_ids = $_POST['user_ids'] ?? []; // Array of user IDs to assign
    
    if (empty($course_id) || empty($user_ids)) {
        $error_message = "Please select a course and at least one employee.";
    } else {
        // First, verify the course belongs to this company
        $sql_check_course = "SELECT id FROM courses WHERE id = ? AND company_id = ?";
        if ($stmt_check = $conn->prepare($sql_check_course)) {
            $stmt_check->bind_param("ii", $course_id, $company_id_context);
            $stmt_check->execute();
            $stmt_check->store_result();
            if ($stmt_check->num_rows == 0) {
                $error_message = "Invalid course selected for your company.";
            }
            $stmt_check->close();
        }

        if (empty($error_message)) {
            // This query will add the assignment, or reset an existing one
            $sql_insert = "INSERT INTO course_assignments (course_id, user_id, company_id, status, completion_percentage, completed_at) 
                           VALUES (?, ?, ?, 'Assigned', 0, NULL)
                           ON DUPLICATE KEY UPDATE status = 'Assigned', completion_percentage = 0, completed_at = NULL, assigned_at = NOW()";
            
            $stmt_insert = $conn->prepare($sql_insert);
            if (!$stmt_insert) {
                $error_message = "DB Prepare Error: " . $conn->error;
            } else {
                $success_count = 0;
                $fail_count = 0;
                
                // Verify all selected users belong to this company
                $user_ids_placeholder = implode(',', array_fill(0, count($user_ids), '?'));
                $sql_verify_users = "SELECT id FROM users WHERE company_id = ? AND id IN ($user_ids_placeholder)";
                $stmt_verify = $conn->prepare($sql_verify_users);
                $types = "i" . str_repeat('i', count($user_ids));
                $params = array_merge([$company_id_context], $user_ids);
                $stmt_verify->bind_param($types, ...$params);
                $stmt_verify->execute();
                $result_users = $stmt_verify->get_result();
                $valid_user_ids = [];
                while($row = $result_users->fetch_assoc()){ $valid_user_ids[] = $row['id']; }
                $stmt_verify->close();

                // Loop through and assign only to valid users
                foreach ($user_ids as $assign_user_id) {
                    if(in_array($assign_user_id, $valid_user_ids)) {
                        $stmt_insert->bind_param("iii", $course_id, $assign_user_id, $company_id_context);
                        if ($stmt_insert->execute()) {
                            $success_count++;
                        } else { $fail_count++; }
                    } else { $fail_count++; } // User ID didn't belong to company
                }
                $stmt_insert->close();
                
                $success_message = "Successfully assigned course to {$success_count} user(s).";
                if($fail_count > 0) $error_message = "Failed to assign course to {$fail_count} user(s) (user not found in this company).";
                
                log_audit_action($conn, 'course_assigned', "Course ID {$course_id} assigned to {$success_count} users.", $user_id, $company_id_context, 'course', $course_id);
            }
        }
    }
}


// --- DATA FETCHING ---
$all_courses = [];
$all_employees = [];

// Fetch all courses for this company
$sql_courses = "SELECT id, title FROM courses WHERE company_id = ? AND is_active = 1 ORDER BY title";
if($stmt_c = $conn->prepare($sql_courses)){
    $stmt_c->bind_param("i", $company_id_context);
    $stmt_c->execute();
    $result_c = $stmt_c->get_result();
    while($row_c = $result_c->fetch_assoc()){ $all_courses[] = $row_c; }
    $stmt_c->close();
} else { $error_message = "Error fetching courses: " . $conn->error; }

// Fetch all 'employee' users for this company
$employee_role_id = 1; // Assuming 1 = 'employee'
$sql_users = "SELECT u.id, u.full_name, u.email 
              FROM users u
              JOIN user_roles ur ON u.id = ur.user_id
              WHERE u.company_id = ? AND ur.role_id = ?
              ORDER BY u.full_name";
if($stmt_u = $conn->prepare($sql_users)){
    $stmt_u->bind_param("ii", $company_id_context, $employee_role_id);
    $stmt_u->execute();
    $result_u = $stmt_u->get_result();
    while($row_u = $result_u->fetch_assoc()){ $all_employees[] = $row_u; }
    $stmt_u->close();
} else { $error_message = "Error fetching employees: " . $conn->error; }

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Assign Course to Employees</h2>
    <a href="learning/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<div class="card">
    <div class="card-header"><h4>Course Assignment</h4></div>
    <div class="card-body">
        <form action="learning/assign_courses.php" method="post">
            <div class="row g-3">
                <!-- Select Course -->
                <div class="col-md-12">
                    <label for="course_id" class="form-label">1. Select Course to Assign</label>
                    <select class="form-select" id="course_id" name="course_id" required>
                        <option value="">-- Select a Course --</option>
                        <?php foreach($all_courses as $course): ?>
                        <option value="<?php echo $course['id']; ?>" <?php if($course['id'] == $course_id_from_url) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($course['title']); ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if(empty($all_courses)): ?>
                        <option value="" disabled>No active courses found. Please create one first.</option>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- Select Employees -->
                <div class="col-md-12">
                    <label for="user_ids" class="form-label">2. Select Employees to Assign</label>
                    <p class="form-text">Hold Ctrl/Cmd to select multiple. Re-assigning a course will reset a user's progress.</p>
                    <select class="form-select" id="user_ids" name="user_ids[]" required multiple size="15">
                        <?php foreach($all_employees as $emp): ?>
                        <option value="<?php echo $emp['id']; ?>">
                            <?php echo htmlspecialchars($emp['full_name'] . ' (' . $emp['email'] . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                        <?php if(empty($all_employees)): ?>
                        <option value="" disabled>No employees found in this company.</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            
            <button type="submit" name="assign_course" class="btn btn-primary mt-4">Assign Course</button>
        </form>
    </div>
</div>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



