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

$course_id = $_GET['course_id'] ?? null; // Get selected course
$courses_list = [];
$assignments_list = [];
$course_title = "All Courses";

// --- DATA FETCHING ---

// 1. Fetch all courses for the dropdown
$sql_courses = "SELECT id, title FROM courses WHERE company_id = ? AND is_active = 1 ORDER BY title";
if($stmt_c = $conn->prepare($sql_courses)){
    $stmt_c->bind_param("i", $company_id_context);
    $stmt_c->execute();
    $result_c = $stmt_c->get_result();
    while($row_c = $result_c->fetch_assoc()){ $courses_list[] = $row_c; }
    $stmt_c->close();
} else { $error_message = "Error fetching courses: " . $conn->error; }

// 2. If a specific course is selected, fetch its assignments
if ($course_id && is_numeric($course_id)) {
    // Verify course belongs to company
    $sql_course_check = "SELECT title FROM courses WHERE id = ? AND company_id = ?";
    if($stmt_check = $conn->prepare($sql_course_check)){
        $stmt_check->bind_param("ii", $course_id, $company_id_context);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        if($row_check = $result_check->fetch_assoc()){
            $course_title = $row_check['title'];
            
            // Fetch all assignments for this course
            $sql_assign = "SELECT 
                                a.id as assignment_id, a.status, a.completion_percentage, a.completed_at,
                                u.full_name, u.email, u.department,
                                (SELECT GROUP_CONCAT(CONCAT(t.title, ': ', tr.percentage, '%') SEPARATOR '; ') 
                                 FROM test_results tr 
                                 JOIN tests t ON tr.test_id = t.id
                                 WHERE tr.assignment_id = a.id) as test_scores
                           FROM course_assignments a
                           JOIN users u ON a.user_id = u.id
                           WHERE a.course_id = ? AND a.company_id = ?
                           ORDER BY u.full_name";
            
            if($stmt_assign = $conn->prepare($sql_assign)){
                $stmt_assign->bind_param("ii", $course_id, $company_id_context);
                $stmt_assign->execute();
                $result_assign = $stmt_assign->get_result();
                while($row_assign = $result_assign->fetch_assoc()){
                    $assignments_list[] = $row_assign;
                }
                $stmt_assign->close();
            } else { $error_message = "Error fetching assignments: " . $conn->error; }

        } else {
            $error_message = "Selected course not found for this company.";
            $course_id = null;
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>View Course Progress</h2>
    <a href="learning/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Dashboard</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<!-- Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <form action="learning/view_progress.php" method="get" class="row g-3 align-items-end">
            <div class="col-md-10">
                <label for="course_id" class="form-label">Select a Course to View Progress</label>
                <select class="form-select" id="course_id" name="course_id" required>
                    <option value="">-- Select a Course --</option>
                    <?php foreach($courses_list as $course): ?>
                    <option value="<?php echo $course['id']; ?>" <?php if($course['id'] == $course_id) echo 'selected'; ?>>
                        <?php echo htmlspecialchars($course['title']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">View</button>
            </div>
        </form>
    </div>
</div>

<?php if ($course_id && $course_title): ?>
<!-- Report Results -->
<div class="card">
    <div class="card-header">
        <h4>Progress for: <?php echo htmlspecialchars($course_title); ?></h4>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped table-hover table-sm">
                <thead>
                    <tr>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Progress</th>
                        <th>Test Scores</th>
                        <th>Completed On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($assignments_list)): ?>
                        <tr><td colspan="7" class="text-center text-muted">This course has not been assigned to any employees yet.</td></tr>
                    <?php else: ?>
                        <?php foreach($assignments_list as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['department']); ?></td>
                            <td><?php echo htmlspecialchars($item['email']); ?></td>
                            <td>
                                <span class="badge <?php echo $item['status'] == 'Completed' ? 'bg-success' : 'bg-secondary'; ?>">
                                    <?php echo htmlspecialchars($item['status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar" role="progressbar" style="width: <?php echo $item['completion_percentage']; ?>%;" aria-valuenow="<?php echo $item['completion_percentage']; ?>" aria-valuemin="0" aria-valuemax="100">
                                        <?php echo (int)$item['completion_percentage']; ?>%
                                    </div>
                                </div>
                            </td>
                            <td><small><?php echo htmlspecialchars($item['test_scores'] ?? 'N/A'); ?></small></td>
                            <td><?php echo $item['completed_at'] ? date("Y-m-d", strtotime($item['completed_at'])) : 'N/A'; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


