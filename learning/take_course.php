<?php
// Use the new Learning Portal header
require_once '../includes/header.php'; // Provides $conn, role checks, and sets session variables

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    header("location: " . BASE_URL . "learning/index.php");
    exit;
}

// Ensure essential session variables are set
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    error_log("Error: Session user_id or company_id missing on take_course.php. User: " . ($_SESSION['user_id'] ?? 'null') . ", Company: " . ($_SESSION['company_id'] ?? 'null'));
    // Redirect to login or show error - a broken session needs login
    header("location: " . BASE_URL . "login.php?error=session_expired");
    exit;
}
// Use session variables directly
$user_id = $_SESSION['user_id'];
$company_id_context = $_SESSION['company_id'];


$error_message = '';
$success_message = '';

// Check for redirection messages from submit_test.php
if (isset($_GET['result']) && $_GET['result'] == 'Passed') {
    $success_message = "Test Passed! Your progress has been updated.";
}
if (isset($_GET['result']) && $_GET['result'] == 'Failed') {
    $error_message = "Test Failed. Please review the material and try again to pass.";
}
if (isset($_GET['error'])) {
     $error_message = htmlspecialchars($_GET['error']);
}


// *** Get the assignment ID from the URL ***
$assignment_id = $_GET['id'] ?? null;
if (!$assignment_id || !is_numeric($assignment_id)) {
    header("location: " . BASE_URL . "learning/index.php?error=invalid_course");
    exit;
}

// Get the specific module to view (optional)
$module_id = $_GET['module_id'] ?? null;

// --- DATA FETCHING (Based on Assignment ID) ---
$assignment_details = null;
$course_details = null; // Will derive from assignment
$modules = [];
$current_module = null;


// 1. Fetch Assignment Details and Course Info, verifying user and company
$sql_assignment = "SELECT
                       a.id as assignment_id, a.course_id, a.user_id, a.status as assignment_status, a.completed_at, a.completion_percentage,
                       c.title as course_title, c.description as course_description, c.company_id
                   FROM course_assignments a
                   JOIN courses c ON a.course_id = c.id
                   WHERE a.id = ? AND a.user_id = ? AND c.company_id = ?";

if ($stmt_assign = $conn->prepare($sql_assignment)) {
    $stmt_assign->bind_param("iii", $assignment_id, $user_id, $company_id_context);

    if ($stmt_assign->execute()) {
        $result_assign = $stmt_assign->get_result();
        $assignment_details = $result_assign->fetch_assoc();
        
        if (!$assignment_details) {
            error_log("Verification failed for assignment ID: $assignment_id, User ID: {$user_id}, Company ID: {$company_id_context}");
            header("location: " . BASE_URL . "learning/index.php?error=not_assigned");
            exit;
        }
        $course_details = $assignment_details;
        $course_id = $assignment_details['course_id'];

    } else {
        error_log("Error executing assignment fetch query: " . $stmt_assign->error);
        $error_message = "Error loading course data.";
    }
    $stmt_assign->close();
} else {
    error_log("Error preparing assignment fetch query: " . $conn->error);
    $error_message = "Database error.";
}


// Helper function to check module completion based on module_progress table
if (!function_exists('check_module_completion_status')) {
    function check_module_completion_status($db_conn, $assign_id, $mod_id) {
        $sql_check = "SELECT is_completed FROM module_progress WHERE assignment_id = ? AND module_id = ? AND is_completed = 1";
        $stmt = $db_conn->prepare($sql_check);
        if ($stmt) {
            $stmt->bind_param("ii", $assign_id, $mod_id);
            if($stmt->execute()){
                $stmt->store_result();
                $is_completed = $stmt->num_rows > 0;
                $stmt->close();
                return $is_completed;
            }
            $stmt->close();
        } 
        return false;
    }
}


// Proceed only if assignment was found and no error
if ($assignment_details && empty($error_message)) {

    // --- Fetch Course Modules (Using $course_id derived from assignment) ---
    $sql_modules = "SELECT m.*, t.id as test_id, t.title as test_title FROM course_modules m 
                    LEFT JOIN tests t ON m.id = t.module_id 
                    WHERE m.course_id = ? 
                    ORDER BY m.sort_order, m.id ASC";
    if ($stmt_modules = $conn->prepare($sql_modules)) {
        $stmt_modules->bind_param("i", $course_id);
        if ($stmt_modules->execute()) {
            $result_modules = $stmt_modules->get_result();
            $temp_order = 1; // Counter for module order if sort_order is missing
            while ($row = $result_modules->fetch_assoc()) {
                // Normalize column names
                if (!isset($row['sort_order']) || empty($row['sort_order'])) {
                    $row['sort_order'] = $temp_order++;
                }
                
                // Add completion status check
                $row['is_completed'] = check_module_completion_status($conn, $assignment_id, $row['id']);
                $modules[] = $row;
            }
        } else {
            error_log("DB Error fetching modules: " . $stmt_modules->error);
            $error_message = "Error loading course structure.";
        }
        $stmt_modules->close();
    } else {
        error_log("DB Error preparing modules: " . $conn->error);
        $error_message = "Database error.";
    }

    // --- Determine Current Module ---
    if (!empty($modules)) {
        // Find module by ID from URL or default to the first one
        $current_module = null;
        if ($module_id) {
            foreach ($modules as $mod) {
                if ($mod['id'] == $module_id) {
                    $current_module = $mod;
                    break;
                }
            }
        }
        if (!$current_module) {
             $current_module = $modules[0];
             $module_id = $current_module['id'];
        }

        // --- Fetch Current Module Content ---
        if ($current_module) {
            $current_module['html_data'] = null; // Initialize
            $module_type = $current_module['module_type']; // Get the determined type

            if ($module_type == 'html_content' || $module_type == 'document_embed') {
                 // Fetch content from module_html_content using module_id
                $sql_html_content = "SELECT html_body FROM module_html_content WHERE module_id = ?";
                if ($stmt_html = $conn->prepare($sql_html_content)) {
                    $stmt_html->bind_param("i", $current_module['id']);
                    if ($stmt_html->execute()) {
                        $result_html = $stmt_html->get_result();
                        $html_result = $result_html->fetch_assoc();
                        if ($html_result && isset($html_result['html_body'])) {
                            $current_module['html_data'] = $html_result['html_body'];
                        } else {
                             $current_module['html_data'] = "<p class='text-danger'>Error: Module Content not found for this module.</p>";
                        }
                    } else {
                        $error_message = "Error loading module content.";
                    }
                    $stmt_html->close();
                } else {
                    $error_message = "Database error loading content.";
                }
                
                // *** NEW LOGIC: Auto-Mark HTML/Document Module as Complete on view (ONLY if not already complete) ***
                if (empty($error_message) && !$current_module['is_completed']) {
                    $conn->begin_transaction();
                    try {
                        $sql_mark_complete = "INSERT INTO module_progress (assignment_id, module_id, is_completed, completed_at)
                                              VALUES (?, ?, 1, NOW())
                                              ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = IFNULL(completed_at, NOW())";
                        $stmt_mark = $conn->prepare($sql_mark_complete);
                        $stmt_mark->bind_param("ii", $assignment_id, $module_id);
                        if(!$stmt_mark->execute()) { throw new Exception("Failed to mark module as complete: " . $stmt_mark->error); }
                        $stmt_mark->close();

                        // Recalculate overall course completion percentage and update status
                        $sql_recalc = "
                            UPDATE course_assignments a
                            LEFT JOIN (
                                SELECT 
                                    a_inner.id as assignment_id,
                                    (COUNT(mp.id) / (SELECT COUNT(*) FROM course_modules WHERE course_id = a_inner.course_id)) * 100 AS new_percentage
                                FROM course_assignments a_inner
                                JOIN course_modules m ON a_inner.course_id = m.course_id
                                LEFT JOIN module_progress mp ON a_inner.id = mp.assignment_id AND m.id = mp.module_id AND mp.is_completed = 1
                                WHERE a_inner.id = ?
                                GROUP BY a_inner.id, a_inner.course_id
                            ) AS progress ON a.id = progress.assignment_id
                            SET a.completion_percentage = IFNULL(progress.new_percentage, 0),
                                a.status = IF(IFNULL(progress.new_percentage, 0) = 100, 'Completed', 'In Progress'),
                                a.completed_at = IF(IFNULL(progress.new_percentage, 0) = 100 AND a.completed_at IS NULL, NOW(), a.completed_at)
                            WHERE a.id = ?;
                        ";
                        $stmt_recalc = $conn->prepare($sql_recalc);
                        $stmt_recalc->bind_param("ii", $assignment_id, $assignment_id);
                        if(!$stmt_recalc->execute()) { throw new Exception("Failed to recalculate course progress: " . $stmt_recalc->error); }
                        $stmt_recalc->close();
                        $conn->commit();
                        $current_module['is_completed'] = true; // Update local status
                        $assignment_details['assignment_status'] = $assignment_details['assignment_status'] === 'Assigned' ? 'In Progress' : $assignment_details['assignment_status']; // Update assignment status if needed

                    } catch (Exception $e) {
                        $conn->rollback();
                        error_log("HTML Module Auto-Completion Error: " . $e->getMessage());
                        $error_message = "Error saving module progress: " . $e->getMessage();
                    }
                }
                // *** END NEW LOGIC ***


            } elseif ($module_type == 'test') {
                 if (empty($current_module['test_id'])) {
                     error_log("Module type is 'test' but test_id is missing for module ID: " . $module_id);
                     $error_message = "Configuration error: Test module is missing test link.";
                 }
            }

            // --- Update Assignment Status if 'Not Started' ---
            if (empty($error_message) && $assignment_details['assignment_status'] == 'Assigned') {
                $new_status = 'In Progress';
                $sql_update_status = "UPDATE course_assignments SET status = ? WHERE id = ?";
                if ($stmt_update = $conn->prepare($sql_update_status)) {
                    $stmt_update->bind_param("si", $new_status, $assignment_details['assignment_id']);
                    if (!$stmt_update->execute()) {
                        error_log("DB Error updating assignment status: " . $stmt_update->error);
                    } else {
                        $assignment_details['assignment_status'] = $new_status; // Update locally
                    }
                    $stmt_update->close();
                } else {
                    error_log("DB Error preparing assignment status update: " . $conn->error);
                }
            }

        } else if (empty($error_message)){
             $error_message = "This course does not appear to have any valid modules.";
        }

    } else if (empty($error_message)){
        $error_message = "Could not find any modules for this course.";
    }
}

// Determine next/previous module IDs
$prev_module_id = null;
$next_module_id = null;
$current_index = -1;
if ($current_module && !empty($modules)) {
    foreach ($modules as $index => $mod) {
        if ($mod['id'] == $current_module['id']) {
            $current_index = $index;
            break;
        }
    }
    if ($current_index > 0) {
        $prev_module_id = $modules[$current_index - 1]['id'];
    }
    if ($current_index < count($modules) - 1) {
        $next_module_id = $modules[$current_index + 1]['id'];
    }
}

// --- Dynamic Styling for Modern Look ---
$progress_bg_color = 'bg-primary';
$progress_bar_width = min(100, (int)($assignment_details['completion_percentage'] ?? 0)) . '%';

// Use this variable to control the display of the full-width content
// This is used for styling the content wrapper to remove padding if it's an embed or raw HTML
$is_full_width_content = ($current_module['module_type'] == 'html_content' || $current_module['module_type'] == 'document_embed'); 

?>

<!-- Custom Styles for the Learning Module -->
<style>
    /* Customization for full-width content display */
    .module-text-content-wrapper {
        padding: 0 !important; /* Remove padding for edge-to-edge HTML content */
        border: none; /* Remove border from the wrapper card */
    }
    .module-text-content-wrapper .card-body {
        padding: <?php echo $is_full_width_content ? '0' : '1.5rem'; ?> !important; 
    }
    /* Module list styling for clarity */
    .module-list .list-group-item { 
        transition: background-color 0.2s ease-in-out; 
        border-radius: 0.5rem;
    }
    .module-list .list-group-item.active { 
        z-index: 2;
        font-weight: 600; 
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
    }
    .module-list-wrapper {
        max-height: 80vh; 
        overflow-y: auto;
    }
</style>


<div class="container-fluid mt-4">
    <?php if ($error_message): ?>
        <div class="alert alert-danger">
            <?php echo htmlspecialchars($error_message); ?>
            <a href="learning/index.php" class="btn btn-secondary btn-sm ms-3">Back to Courses</a>
        </div>
    <?php elseif ($success_message): ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success_message); ?>
        </div>
    <?php elseif ($course_details && $current_module): ?>
        
        <!-- Course Header -->
        <div class="d-flex justify-content-between align-items-center mb-3 p-3 bg-white shadow-sm rounded">
            <div>
                <h2 class="mb-0 text-primary"><?php echo htmlspecialchars($course_details['course_title']); ?></h2>
                <p class="text-muted mb-0 small"><?php echo htmlspecialchars($course_details['course_description']); ?></p>
            </div>
            <div class="text-end">
                 <h5 class="mb-1 text-muted">Progress</h5>
                 <div class="progress" style="width: 150px; height: 10px;">
                    <div class="progress-bar <?php echo $progress_bg_color; ?>" role="progressbar" style="width: <?php echo $progress_bar_width; ?>;" aria-valuenow="<?php echo (int)($assignment_details['completion_percentage'] ?? 0); ?>" aria-valuemin="0" aria-valuemax="100"></div>
                 </div>
                 <span class="text-secondary small"><?php echo round($assignment_details['completion_percentage'] ?? 0, 0); ?>% Complete</span>
            </div>
        </div>

        <!-- **** START LAYOUT CHANGE **** -->
        <div class="row g-4">
            <!-- Module Navigation Sidebar (Hidden on tiny screens) -->
            <div class="col-md-3 d-none d-md-block">
                <div class="card sticky-top shadow-sm" style="top: 20px;">
                     <div class="card-header bg-light">
                         <h6 class="mb-0 fw-bold">Modules</h6>
                     </div>
                    <div class="list-group list-group-flush module-list-wrapper">
                        <?php foreach ($modules as $mod): ?>
                            <a href="take_course.php?id=<?php echo $assignment_id; ?>&module_id=<?php echo $mod['id']; ?>"
                               class="list-group-item list-group-item-action py-2 px-3 d-flex justify-content-between align-items-center <?php echo ($mod['id'] == $current_module['id']) ? 'active bg-primary text-white border-primary' : ''; ?>">
                               <small>
                                   <?php if($mod['module_type'] == 'test'): ?> <i class="fas fa-edit me-1"></i> <?php endif; ?>
                                   <?php if($mod['module_type'] == 'html_content' || $mod['module_type'] == 'document_embed'): ?> <i class="fas fa-file-alt me-1"></i> <?php endif; ?>
                                   <?php echo htmlspecialchars($mod['title']); ?>
                                </small>
                                <?php if($mod['is_completed']): ?>
                                    <i class="fas fa-check-circle text-success small <?php echo ($mod['id'] == $current_module['id']) ? 'text-white' : 'text-success'; ?>"></i>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                     <div class="card-footer text-center py-2">
                         <a href="learning/index.php" class="btn btn-sm btn-outline-secondary py-1">
                             <i class="fas fa-arrow-left me-1"></i> All Courses
                         </a>
                    </div>
                </div>
            </div>

            <!-- Module Content Area (Full Width on Mobile, Expanded on Desktop) -->
            <div class="col-12 col-md-9">
                <div class="card shadow-lg module-text-content-wrapper">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Module <?php echo htmlspecialchars($current_module['sort_order']); ?>: <?php echo htmlspecialchars($current_module['title']); ?></h5>
                        <!-- "View Certificate" button shows when course is completed -->
                        <?php if ($assignment_details['assignment_status'] === 'Completed'): ?>
                             <a href="generate_certificate.php?id=<?php echo $assignment_id; ?>" class="btn btn-success btn-sm">
                                <i class="fas fa-certificate me-1"></i> View Certificate
                             </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body module-text-content" style="min-height: 400px;">
                        <?php
                        // Render content based on type handling
                        $render_type = $current_module['module_type'];
                        $content = $current_module['html_data'];

                        if ($render_type == 'html_content' || $render_type == 'document_embed') {
                            // Document embed content and HTML content both use the raw HTML saved in the content table
                             echo $content; 
                        } elseif ($render_type == 'test') {
                            // Display information about the test and a button to start it
                            echo '<div class="text-center p-5">';
                            echo '<h4><i class="fas fa-edit me-2 text-primary"></i>Test Module</h4>';
                            echo '<p class="text-muted">This module requires you to complete and pass a test.</p>';
                            if (!empty($current_module['test_id'])) {
                                $test_link = "take_test.php?assignment_id={$assignment_id}&test_id={$current_module['test_id']}";
                                echo '<a href="' . $test_link . '" class="btn btn-lg btn-warning shadow-sm">';
                                echo ($current_module['is_completed'] ? 'Retake Test' : 'Start Test');
                                echo ' <i class="fas fa-arrow-right ms-1"></i>';
                                echo '</a>';
                                if($current_module['is_completed']) {
                                    echo '<p class="text-success small mt-3"><i class="fas fa-check-circle me-1"></i> You have already passed this test.</p>';
                                }
                            } else {
                                echo '<p class="text-danger">Error: Test link is missing for this module.</p>';
                            }
                            echo '</div>';
                        } else {
                            echo '<p class="text-muted">Unsupported module type: ' . htmlspecialchars($render_type ?? 'Unknown') . '</p>';
                        }
                        ?>
                    </div>
                    <div class="card-footer d-flex justify-content-between align-items-center bg-light">
                        <?php if ($prev_module_id): ?>
                            <a href="take_course.php?id=<?php echo $assignment_id; ?>&module_id=<?php echo $prev_module_id; ?>" class="btn btn-outline-secondary btn-sm">
                               <i class="fas fa-chevron-left me-1"></i> Previous Module
                            </a>
                        <?php else: ?>
                            <span class="text-muted small">Start of Course</span>
                        <?php endif; ?>

                        <!-- Center element for page numbers or status -->
                        <span class="text-muted small">
                            Module <?php echo htmlspecialchars($current_module['sort_order']); ?> of <?php echo count($modules); ?>
                        </span>

                        <?php if ($next_module_id): ?>
                            <a href="take_course.php?id=<?php echo $assignment_id; ?>&module_id=<?php echo $next_module_id; ?>" class="btn btn-primary btn-sm">
                                Next Module <i class="fas fa-chevron-right ms-1"></i>
                            </a>
                        <?php else: // This is the last module ?>
                            <?php if ($assignment_details['assignment_status'] === 'Completed'): ?>
                                 <span class="text-success fw-bold small"><i class="fas fa-check-circle me-1"></i> Course Complete</span>
                             <?php else: ?>
                                 <span class="text-muted small">End of Course</span>
                             <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
         <!-- **** END LAYOUT CHANGE **** -->
    <?php else: ?>
         <div class="alert alert-warning">
             Could not load course details or modules. Please go back and try again.
             <a href="learning/index.php" class="btn btn-secondary btn-sm ms-3">Back to Courses</a>
         </div>
    <?php endif; ?>
</div>

<?php
// Close connection only if it was successfully opened
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
require_once '../includes/footer.php';
?>


