<?php
// Use the new Learning Portal header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    header("location: " . BASE_URL . "learning/index.php");
    exit;
}

// Ensure essential session variables are set
if (!isset($_SESSION['user_id']) || !isset($_SESSION['company_id'])) {
    error_log("Error: Session user_id or company_id missing on take_test.php. User: " . ($_SESSION['user_id'] ?? 'null') . ", Company: " . ($_SESSION['company_id'] ?? 'null'));
    header("location: " . BASE_URL . "login.php?error=session_expired");
    exit;
}
// Use session variables directly for consistency and security
$user_id = $_SESSION['user_id'];
$company_id_context = $_SESSION['company_id'];


$error_message = '';
$success_message = '';

// Get the assignment ID and Test ID from the URL
// *** CORRECTION: Use 'assignment_id' from the URL parameter ***
$assignment_id = $_GET['assignment_id'] ?? null;
$test_id = $_GET['test_id'] ?? null;

// Validate IDs
if (!$assignment_id || !$test_id || !is_numeric($assignment_id) || !is_numeric($test_id)) {
    // Log the received parameters for debugging
    error_log("take_test.php: Invalid or missing IDs. Received assignment_id=" . print_r($_GET['assignment_id'] ?? 'null', true) . ", test_id=" . print_r($_GET['test_id'] ?? 'null', true));
    // Redirect with a generic error to avoid revealing parameter names
    header("location: " . BASE_URL . "learning/index.php?error=invalid_params");
    exit;
}

// --- DATA FETCHING ---
// 1. Fetch Test, Module, and Course info, and verify user is assigned to this course
$test_data = null;
$sql_test_info = "SELECT
                      t.id as test_id, t.title as test_title, t.pass_mark_percentage,
                      m.id as module_id, m.title as module_title,
                      c.id as course_id, c.title as course_title,
                      a.id as assignment_id
                    FROM tests t
                    LEFT JOIN course_modules m ON t.module_id = m.id
                    LEFT JOIN courses c ON m.course_id = c.id
                    LEFT JOIN course_assignments a ON c.id = a.course_id
                    WHERE a.id = ? AND t.id = ? AND a.user_id = ? AND c.company_id = ?"; // Check company_id on courses table for security

if($stmt_info = $conn->prepare($sql_test_info)){
    $stmt_info->bind_param("iiii", $assignment_id, $test_id, $user_id, $company_id_context);
    if ($stmt_info->execute()) {
        $result_info = $stmt_info->get_result();
        $test_data = $result_info->fetch_assoc();
        if(!$test_data) {
             error_log("take_test.php: Test data not found or permission denied. Assignment ID: {$assignment_id}, Test ID: {$test_id}, User ID: {$user_id}, Company ID: {$company_id_context}");
             header("location: " . BASE_URL . "learning/index.php?error=test_not_found");
             exit;
        }
    } else {
         error_log("take_test.php: Error executing test info query: " . $stmt_info->error);
         $error_message = "Error fetching test details.";
    }
    $stmt_info->close();
} else {
    error_log("take_test.php: Error preparing test info query: " . $conn->error);
    $error_message = "Database error fetching test details.";
}

// 2. Fetch all questions and options for this test
$questions = [];
if($test_data && empty($error_message)){ // Proceed only if test_data was found and no previous error
    $sql_q = "SELECT id, question_text, question_type, points FROM test_questions WHERE test_id = ? ORDER BY id";
    if($stmt_q = $conn->prepare($sql_q)){
        $stmt_q->bind_param("i", $test_id);
        if ($stmt_q->execute()) {
            $result_q = $stmt_q->get_result();
            while($row_q = $result_q->fetch_assoc()){
                // Ensure points is numeric, default to 1 if not set or invalid
                $row_q['points'] = (isset($row_q['points']) && is_numeric($row_q['points']) && $row_q['points'] > 0) ? (int)$row_q['points'] : 1;
                $questions[$row_q['id']] = $row_q;
                $questions[$row_q['id']]['options'] = []; // Prep for options
            }
        } else {
             error_log("take_test.php: Error executing questions query: " . $stmt_q->error);
             $error_message = "Error loading test questions.";
        }
        $stmt_q->close();

        // Fetch options only if questions were found
        if(!empty($questions) && empty($error_message)){
            $question_ids = array_keys($questions);
            $ids_placeholder = implode(',', array_fill(0, count($question_ids), '?'));
            $sql_o = "SELECT id, question_id, option_text FROM question_options WHERE question_id IN ($ids_placeholder) ORDER BY RAND()"; // Randomize options display order
            if ($stmt_o = $conn->prepare($sql_o)) {
                $types = str_repeat('i', count($question_ids));
                $stmt_o->bind_param($types, ...$question_ids);
                if ($stmt_o->execute()) {
                    $result_o = $stmt_o->get_result();
                    while($row_o = $result_o->fetch_assoc()){
                        // Ensure the question_id exists in our fetched questions before adding the option
                        if(isset($questions[$row_o['question_id']])) {
                            $questions[$row_o['question_id']]['options'][] = $row_o;
                        }
                    }
                } else {
                     error_log("take_test.php: Error executing options query: " . $stmt_o->error);
                     $error_message = "Error loading answer options.";
                }
                $stmt_o->close();
            } else {
                 error_log("take_test.php: Error preparing options query: " . $conn->error);
                 $error_message = "Database error loading options.";
            }
        } elseif (empty($error_message)) {
             // If $questions is empty but no DB error occurred yet
             $error_message = "No questions found for this test.";
        }
    } else {
        error_log("take_test.php: Error preparing questions query: " . $conn->error);
        $error_message = "Database error loading questions.";
    }
}

?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <!-- Display titles only if $test_data was successfully fetched -->
        <h2><?php echo htmlspecialchars($test_data['test_title'] ?? 'Test'); ?></h2>
        <h5 class="text-muted"><?php echo htmlspecialchars($test_data['course_title'] ?? 'Course'); ?></h5>
    </div>
    <!-- Always provide a back link, using the verified $assignment_id -->
    <a href="learning/take_course.php?id=<?php echo $assignment_id; ?>" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Course</a>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
<?php else: // Only show test if no error message ?>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Questions (<?php echo count($questions); ?> total)</span>
            <span>Pass Mark: <strong><?php echo htmlspecialchars($test_data['pass_mark_percentage'] ?? 'N/A'); ?>%</strong></span>
        </div>
        <div class="card-body">
            <?php if(empty($questions)): ?>
                <p class="text-center text-muted py-5">This test does not have any questions yet. Please contact your trainer.</p>
            <?php else: ?>
            <form action="learning/submit_test.php" method="post" id="testForm">
                <input type="hidden" name="assignment_id" value="<?php echo $assignment_id; ?>">
                <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
                <!-- Module ID might be null if test isn't linked, handle gracefully -->
                <input type="hidden" name="module_id" value="<?php echo $test_data['module_id'] ?? ''; ?>">

                <?php $q_number = 1; foreach($questions as $q_id => $q): ?>
                    <div class="mb-4 p-3 border rounded bg-light shadow-sm">
                        <p class="fw-bold mb-2"><?php echo $q_number++; ?>. <?php echo nl2br(htmlspecialchars($q['question_text'])); ?> <span class="badge bg-secondary ms-2"><?php echo $q['points']; ?> point<?php echo ($q['points'] != 1 ? 's' : ''); ?></span></p>
                        <div class="ms-3 mt-2">
                            <?php if($q['question_type'] == 'multiple_choice'): ?>
                                <?php if (!empty($q['options'])): ?>
                                    <?php foreach($q['options'] as $opt): ?>
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="radio"
                                                   name="answers[<?php echo $q_id; ?>]"
                                                   id="opt_<?php echo $opt['id']; ?>"
                                                   value="<?php echo $opt['id']; ?>" required>
                                            <label class="form-check-label" for="opt_<?php echo $opt['id']; ?>">
                                                <?php echo htmlspecialchars($opt['option_text']); ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                     <p class="text-danger small">Error: No options found for this question.</p>
                                <?php endif; ?>
                            <?php elseif ($q['question_type'] == 'true_false'): ?>
                                 <div class="form-check form-check-inline">
                                     <input class="form-check-input" type="radio" name="answers[<?php echo $q_id; ?>]" id="tf_<?php echo $q_id; ?>_true" value="true" required>
                                     <label class="form-check-label" for="tf_<?php echo $q_id; ?>_true">True</label>
                                 </div>
                                 <div class="form-check form-check-inline">
                                     <input class="form-check-input" type="radio" name="answers[<?php echo $q_id; ?>]" id="tf_<?php echo $q_id; ?>_false" value="false" required>
                                     <label class="form-check-label" for="tf_<?php echo $q_id; ?>_false">False</label>
                                 </div>
                             <?php else: ?>
                                 <p class="text-warning small">Unsupported question type: <?php echo htmlspecialchars($q['question_type']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <hr class="my-4">
                <div class="text-center">
                    <button type="submit" name="submit_test" class="btn btn-success btn-lg shadow-sm">
                        <i data-lucide="check-circle" class="me-2"></i>Submit Test for Grading
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; // End else for error message check ?>


<?php
// Close connection only if it was successfully opened
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
require_once 'includes/footer.php';
?>


