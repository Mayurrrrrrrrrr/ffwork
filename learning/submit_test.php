submit test

<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once 'init.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS AN EMPLOYEE --
if (!check_role('employee')) {
    header("location: " . BASE_URL . "login.php"); 
    exit;
}

// -- ENSURE COMPANY ID AND USER ID ARE AVAILABLE --
$company_id = get_current_company_id();
$user_id = $_SESSION['user_id'] ?? null;
if (!$company_id || !$user_id) {
    session_destroy(); header("location: " . BASE_URL . "login.php?error=session_error"); exit;
}

// --- ACTION HANDLING: GRADE THE TEST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_test'])) {
    
    // Get all POST data
    $assignment_id = $_POST['assignment_id'] ?? null;
    $test_id = $_POST['test_id'] ?? null;
    $module_id = $_POST['module_id'] ?? null;
    $answers = $_POST['answers'] ?? []; // Array of [question_id => selected_option_id]

    // Basic validation
    if (!$assignment_id || !$test_id || !$module_id || empty($answers)) {
        header("location: " . BASE_URL . "learning/index.php?error=invalid_submission");
        exit;
    }

    $error_message = '';
    $total_score = 0;
    $total_possible_points = 0;
    $pass_mark_percentage = 70; // Default
    $course_id = 0; // Will be set

    try {
        // 1. Get Test Pass Mark and verify this assignment is valid
        $sql_test_info = "SELECT 
                            t.pass_mark_percentage, a.course_id
                          FROM tests t
                          JOIN course_modules m ON t.module_id = m.id
                          JOIN courses c ON m.course_id = c.id
                          JOIN course_assignments a ON c.id = a.course_id
                          WHERE a.id = ? AND t.id = ? AND a.user_id = ? AND a.company_id = ?";
        $stmt_info = $conn->prepare($sql_test_info);
        $stmt_info->bind_param("iiii", $assignment_id, $test_id, $user_id, $company_id);
        $stmt_info->execute();
        $result_info = $stmt_info->get_result();
        $test_data = $result_info->fetch_assoc();
        $stmt_info->close();
        
        if(!$test_data) { throw new Exception("Invalid test or assignment."); }
        $pass_mark_percentage = $test_data['pass_mark_percentage'];
        $course_id = $test_data['course_id'];

        // 2. Get all correct answers and points for this test
        $sql_answers = "SELECT q.id as question_id, q.points, o.id as correct_option_id 
                        FROM test_questions q
                        JOIN question_options o ON q.id = o.question_id
                        WHERE q.test_id = ? AND o.is_correct = 1";
        $stmt_answers = $conn->prepare($sql_answers);
        $stmt_answers->bind_param("i", $test_id);
        $stmt_answers->execute();
        $result_answers = $stmt_answers->get_result();
        $correct_answers_map = [];
        while($row = $result_answers->fetch_assoc()){
            $correct_answers_map[$row['question_id']] = [
                'option_id' => $row['correct_option_id'],
                'points' => $row['points']
            ];
            $total_possible_points += $row['points']; // Sum up total possible points
        }
        $stmt_answers->close();
        if(empty($correct_answers_map)) { throw new Exception("Test has no answer key."); }

        // 3. Grade the submitted answers
        foreach ($answers as $question_id => $submitted_option_id) {
            if (isset($correct_answers_map[$question_id])) {
                $correct_answer = $correct_answers_map[$question_id];
                // Check if the user's submitted answer matches the correct option ID
                if ($submitted_option_id == $correct_answer['option_id']) {
                    $total_score += $correct_answer['points']; // Add points if correct
                }
            }
        }

        // 4. Calculate final percentage and status
        $percentage = ($total_possible_points > 0) ? ($total_score / $total_possible_points) * 100 : 0;
        $status = ($percentage >= $pass_mark_percentage) ? 'Passed' : 'Failed';

        // 5. Save the results (Transaction)
        $conn->begin_transaction();

        // Save the test result
        $sql_save_result = "INSERT INTO test_results (assignment_id, test_id, score, percentage, status, taken_at) 
                            VALUES (?, ?, ?, ?, ?, NOW())
                            ON DUPLICATE KEY UPDATE score = VALUES(score), percentage = VALUES(percentage), status = VALUES(status), taken_at = NOW()";
        $stmt_save = $conn->prepare($sql_save_result);
        $stmt_save->bind_param("iiids", $assignment_id, $test_id, $total_score, $percentage, $status);
        if(!$stmt_save->execute()) { throw new Exception("Failed to save test result: " . $stmt_save->error); }
        $stmt_save->close();

        // If passed, mark module as complete and update course percentage
        if ($status == 'Passed') {
            // Mark module as complete
            $sql_mark = "INSERT INTO module_progress (assignment_id, module_id, is_completed, completed_at)
                         VALUES (?, ?, 1, NOW())
                         ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = IFNULL(completed_at, NOW())"; // Only set completed_at once
            $stmt_mark = $conn->prepare($sql_mark);
            $stmt_mark->bind_param("ii", $assignment_id, $module_id);
            if(!$stmt_mark->execute()) { throw new Exception("Failed to mark module as complete: " . $stmt_mark->error); }
            $stmt_mark->close();
        }
        
        // Recalculate overall course completion percentage (regardless of pass/fail, as some HTML modules might be done)
        $sql_recalc = "
            UPDATE course_assignments a
            LEFT JOIN (
                SELECT 
                    a_inner.id as assignment_id,
                    (COUNT(mp.id) / (SELECT COUNT(*) FROM course_modules WHERE course_id = a_inner.course_id)) * 100 AS new_percentage,
                    (SELECT COUNT(*) FROM course_modules cm_inner WHERE cm_inner.course_id = a_inner.course_id) as total_modules
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
        log_audit_action($conn, 'test_submitted', "Submitted test {$test_id}. Score: {$total_score}/{$total_possible_points} ({$status})", $user_id, $company_id, 'test_result', $test_id);
        
        // Redirect back to the course page with the result
        header("location: " . BASE_URL . "learning/take_course.php?id={$assignment_id}&module_id={$module_id}&result={$status}&score=" . round($percentage, 0));
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        error_log("Test Submission Error: " . $e->getMessage());
        header("location: " . BASE_URL . "learning/take_course.php?id={$assignment_id}&module_id={$module_id}&error=" . urlencode($e->getMessage()));
        exit;
    }

} else {
    // If not a POST request, just redirect away.
    header("location: " . BASE_URL . "learning/index.php");
    exit;
}

?>



