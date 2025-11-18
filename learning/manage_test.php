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
$test_id = $_GET['id'] ?? null;
$test_details = null;
$test_questions = [];

if (!$test_id || !is_numeric($test_id)) {
    header("location: " . BASE_URL . "learning/manage_courses.php?error=invalid_test");
    exit;
}

// --- ACTION HANDLING: ADD NEW QUESTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_mc_question'])) {
    $question_text = trim($_POST['question_text']);
    $points = (int)($_POST['points']);
    $options = $_POST['options'] ?? []; // Array of option texts
    $correct_option_index = $_POST['is_correct'] ?? null; // Index of the correct answer

    // Validation
    if (empty($question_text) || $points <= 0 || count($options) < 2 || $correct_option_index === null) {
        $error_message = "Please provide question text, points, and at least two options, with one marked as correct.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Insert the question
            $sql_q = "INSERT INTO test_questions (test_id, question_text, question_type, points) VALUES (?, ?, 'multiple_choice', ?)";
            $stmt_q = $conn->prepare($sql_q);
            $stmt_q->bind_param("isi", $test_id, $question_text, $points);
            if(!$stmt_q->execute()) { throw new Exception("Failed to save question: " . $stmt_q->error); }
            $new_question_id = $conn->insert_id;
            $stmt_q->close();

            // 2. Insert the options
            $sql_o = "INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)";
            $stmt_o = $conn->prepare($sql_o);
            foreach ($options as $index => $option_text) {
                if (!empty(trim($option_text))) {
                    $is_correct = ($index == $correct_option_index) ? 1 : 0;
                    $stmt_o->bind_param("isi", $new_question_id, $option_text, $is_correct);
                    if(!$stmt_o->execute()) { throw new Exception("Failed to save option: " . $stmt_o->error); }
                }
            }
            $stmt_o->close();
            
            $conn->commit();
            $success_message = "New question added successfully.";
            log_audit_action($conn, 'test_question_created', "Added question '{$question_text}' to test ID {$test_id}", $user_id, $company_id_context, 'test', $test_id);

        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}


// --- DATA FETCHING ---
// Fetch test details and verify it belongs to the trainer's company
$sql_test = "SELECT t.*, m.title as module_title, m.course_id
             FROM tests t
             JOIN course_modules m ON t.module_id = m.id
             JOIN courses c ON m.course_id = c.id
             WHERE t.id = ? AND c.company_id = ?";
if($stmt_test = $conn->prepare($sql_test)) {
    $stmt_test->bind_param("ii", $test_id, $company_id_context);
    $stmt_test->execute();
    $result_test = $stmt_test->get_result();
    $test_details = $result_test->fetch_assoc();
    $stmt_test->close();
    if(!$test_details) {
         header("location: " . BASE_URL . "learning/manage_courses.php?error=notfound"); exit;
    }
} else { $error_message = "DB Error fetching test: " . $conn->error; }

// Fetch existing questions for this test
if($test_details){
    $sql_q = "SELECT * FROM test_questions WHERE test_id = ? ORDER BY id";
    if($stmt_q = $conn->prepare($sql_q)){
        $stmt_q->bind_param("i", $test_id);
        $stmt_q->execute();
        $result_q = $stmt_q->get_result();
        while($row_q = $result_q->fetch_assoc()){
            $test_questions[$row_q['id']] = $row_q;
            $test_questions[$row_q['id']]['options'] = []; // Prepare array for options
        }
        $stmt_q->close();

        // Now fetch all options for these questions
        $question_ids = array_keys($test_questions);
        if(!empty($question_ids)){
            $ids_placeholder = implode(',', array_fill(0, count($question_ids), '?'));
            $sql_o = "SELECT * FROM question_options WHERE question_id IN ($ids_placeholder) ORDER BY id";
            $stmt_o = $conn->prepare($sql_o);
            $types = str_repeat('i', count($question_ids));
            $stmt_o->bind_param($types, ...$question_ids);
            $stmt_o->execute();
            $result_o = $stmt_o->get_result();
            while($row_o = $result_o->fetch_assoc()){
                $test_questions[$row_o['question_id']]['options'][] = $row_o;
            }
            $stmt_o->close();
        }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Test Editor: <?php echo htmlspecialchars($test_details['title'] ?? 'Test'); ?></h2>
    <a href="learning/manage_courses.php?id=<?php echo $test_details['course_id']; ?>" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Course</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<div class="row g-4">
    <!-- Left Column: Existing Questions -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><h4>Existing Questions</h4></div>
            <div class="card-body">
                <?php if(empty($test_questions)): ?>
                    <p class="text-muted">No questions added yet. Use the form to add one.</p>
                <?php else: ?>
                    <?php foreach($test_questions as $q): ?>
                        <div class="mb-3 p-3 border rounded">
                            <div class="d-flex justify-content-between">
                                <strong><?php echo htmlspecialchars($q['question_text']); ?></strong>
                                <span class="badge bg-info"><?php echo $q['points']; ?> Points</span>
                            </div>
                            <ul class="list-group mt-2">
                                <?php foreach($q['options'] as $opt): ?>
                                <li class="list-group-item <?php echo $opt['is_correct'] ? 'list-group-item-success' : ''; ?>">
                                    <?php echo htmlspecialchars($opt['option_text']); ?>
                                    <?php if($opt['is_correct']): ?>
                                        <span class="badge bg-success float-end"><i data-lucide="check"></i> Correct</span>
                                    <?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="mt-2 text-end">
                                <a href="#" class="btn btn-sm btn-outline-danger">Delete</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Add New Question Form -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-primary text-white"><h5>Add New Question</h5></div>
            <div class="card-body">
                <form action="learning/manage_test.php?id=<?php echo $test_id; ?>" method="post" id="add-question-form">
                    <div class="mb-3">
                        <label class="form-label">Question Type</label>
                        <select class="form-select" id="question_type">
                            <option value="mc">Multiple Choice</option>
                            <option value="tf" disabled>True/False (Coming Soon)</option>
                        </select>
                    </div>

                    <!-- Multiple Choice Form -->
                    <div id="mc-form">
                        <div class="mb-3">
                            <label for="question_text" class="form-label">Question Text</label>
                            <textarea class="form-control" id="question_text" name="question_text" rows="3" required></textarea>
                        </div>
                        <div class="mb-3">
                            <label for="points" class="form-label">Points</label>
                            <input type="number" class="form-control" id="points" name="points" min="1" value="10" required>
                        </div>
                        <hr>
                        <label class="form-label">Answer Options (Provide at least 2)</label>
                        <div id="options-container">
                            <!-- Option Row 1 -->
                            <div class="input-group mb-2">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="is_correct" value="0" required>
                                </div>
                                <input type="text" class="form-control" name="options[]" placeholder="Option 1" required>
                            </div>
                            <!-- Option Row 2 -->
                            <div class="input-group mb-2">
                                <div class="input-group-text">
                                    <input class="form-check-input mt-0" type="radio" name="is_correct" value="1" required>
                                </div>
                                <input type="text" class="form-control" name="options[]" placeholder="Option 2" required>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="addOption()">+ Add Option</button>
                        
                        <button type="submit" name="add_mc_question" class="btn btn-primary mt-3 w-100">Save Question</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    let optionCount = 2; // Start at 2 because we have 2 hardcoded

    function addOption() {
        const container = document.getElementById('options-container');
        const newOption = document.createElement('div');
        newOption.className = 'input-group mb-2';
        newOption.innerHTML = `
            <div class="input-group-text">
                <input class="form-check-input mt-0" type="radio" name="is_correct" value="${optionCount}" required>
            </div>
            <input type="text" class="form-control" name="options[]" placeholder="Option ${optionCount + 1}" required>
            <button type="button" class="btn btn-outline-danger" onclick="this.parentElement.remove()">X</button>
        `;
        container.appendChild(newOption);
        optionCount++;
    }
</script>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>



