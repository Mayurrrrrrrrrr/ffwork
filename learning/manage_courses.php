<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

// -- SECURITY CHECK: ENSURE USER IS A TRAINER/ADMIN --
if (!has_any_role(['trainer', 'admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "learning/index.php"); 
    exit;
}

$error_message = '';
$success_message = '';
$course_id = $_GET['id'] ?? null;
$course_details = null;
$course_modules = [];
$is_editing = false;

// --- FILE UPLOAD HELPER FUNCTION ---
function handle_course_file_upload($file_input_name, $course_id) {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $file_info = $_FILES[$file_input_name];
        
        // **FIX for open_basedir:** Use DOCUMENT_ROOT to ensure the path is always absolute and permitted.
        // We assume the web-accessible 'uploads' folder is directly inside the document root (htdocs).
        $target_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/course_files/'; 
        $web_path_prefix = 'uploads/course_files/';
        
        if (!is_dir($target_dir)) { 
             // Try to create the directory if it doesn't exist
             if (!@mkdir($target_dir, 0755, true)) {
                 throw new Exception("Failed to create upload directory. Check permissions.");
             }
        }
        if (!is_writable($target_dir)) { 
            throw new Exception("Upload directory is not writable. Check folder permissions (e.g., 755 or 777)."); 
        }
        
        $file_extension = strtolower(pathinfo($file_info["name"], PATHINFO_EXTENSION));
        $safe_filename = "course{$course_id}_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $file_extension;
        $target_file = $target_dir . $safe_filename;
        
        $allowed_types = ['pdf', 'ppt', 'pptx', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($file_extension, $allowed_types)) {
            throw new Exception("Invalid file type. Only common documents (PPT, PDF, DOCX) allowed.");
        }
        if ($file_info['size'] > 20 * 1024 * 1024) { // 20MB limit
            throw new Exception("File is too large (Max 20MB).");
        }
        if (!move_uploaded_file($file_info["tmp_name"], $target_file)) {
            throw new Exception("Failed to save uploaded file.");
        }
        return $web_path_prefix . $safe_filename; // Return the web-relative path
    }
    return null; 
}


// --- ACTION HANDLING ---

// Handle Deleting a Course
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_course_id'])) {
    $course_id_to_delete = (int)$_POST['delete_course_id'];

    if ($course_id_to_delete > 0) {
        // IMPORTANT: Use transactions to ensure all related data is deleted or none is.
        $conn->begin_transaction();
        try {
            // Get course title for logging before deleting
            $sql_get_title = "SELECT title FROM courses WHERE id = ? AND company_id = ?";
            $stmt_title = $conn->prepare($sql_get_title);
            $stmt_title->bind_param("ii", $course_id_to_delete, $company_id_context);
            $stmt_title->execute();
            $title_result = $stmt_title->get_result()->fetch_assoc();
            $deleted_course_title = $title_result ? $title_result['title'] : "ID {$course_id_to_delete}";
            $stmt_title->close();


            // 1. Delete Certificates linked via assignments
            $sql_del_certs = "DELETE FROM certificates WHERE assignment_id IN (SELECT id FROM course_assignments WHERE course_id = ?)";
            $stmt_del_certs = $conn->prepare($sql_del_certs);
            $stmt_del_certs->bind_param("i", $course_id_to_delete);
            if(!$stmt_del_certs->execute()) { throw new Exception("Failed to delete certificates: " . $stmt_del_certs->error); }
            $stmt_del_certs->close();

             // 2. Delete Test Results linked via assignments
            $sql_del_results = "DELETE tr FROM test_results tr JOIN course_assignments ca ON tr.assignment_id = ca.id WHERE ca.course_id = ?";
            $stmt_del_results = $conn->prepare($sql_del_results);
            $stmt_del_results->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_results->execute()) { throw new Exception("Failed to delete test results: " . $stmt_del_results->error); }
             $stmt_del_results->close();

             // 3. Delete Question Options (need to join via questions -> tests -> modules)
            $sql_del_options = "DELETE qo FROM question_options qo
                                JOIN test_questions tq ON qo.question_id = tq.id
                                JOIN tests t ON tq.test_id = t.id
                                JOIN course_modules cm ON t.module_id = cm.id
                                WHERE cm.course_id = ?";
             $stmt_del_options = $conn->prepare($sql_del_options);
             $stmt_del_options->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_options->execute()) { throw new Exception("Failed to delete question options: " . $stmt_del_options->error); }
             $stmt_del_options->close();

            // 4. Delete Questions (need to join via tests -> modules)
            $sql_del_questions = "DELETE tq FROM test_questions tq
                                  JOIN tests t ON tq.test_id = t.id
                                  JOIN course_modules cm ON t.module_id = cm.id
                                  WHERE cm.course_id = ?";
             $stmt_del_questions = $conn->prepare($sql_del_questions);
             $stmt_del_questions->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_questions->execute()) { throw new Exception("Failed to delete questions: " . $stmt_del_questions->error); }
             $stmt_del_questions->close();

             // 5. Delete Tests (linked via modules)
             $sql_del_tests = "DELETE t FROM tests t JOIN course_modules cm ON t.module_id = cm.id WHERE cm.course_id = ?";
             $stmt_del_tests = $conn->prepare($sql_del_tests);
             $stmt_del_tests->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_tests->execute()) { throw new Exception("Failed to delete tests: " . $stmt_del_tests->error); }
             $stmt_del_tests->close();

             // 6. Delete Module Progress
             $sql_del_prog = "DELETE mp FROM module_progress mp JOIN course_modules cm ON mp.module_id = cm.id WHERE cm.course_id = ?";
             $stmt_del_prog = $conn->prepare($sql_del_prog);
             $stmt_del_prog->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_prog->execute()) { throw new Exception("Failed to delete module progress: " . $stmt_del_prog->error); }
             $stmt_del_prog->close();

             // 7. Delete Module HTML Content
             $sql_del_html = "DELETE mhc FROM module_html_content mhc JOIN course_modules cm ON mhc.module_id = cm.id WHERE cm.course_id = ?";
             $stmt_del_html = $conn->prepare($sql_del_html);
             $stmt_del_html->bind_param("i", $course_id_to_delete);
             if(!$stmt_del_html->execute()) { throw new Exception("Failed to delete module html content: " . $stmt_del_html->error); }
             $stmt_del_html->close();

            // 8. Delete Modules
            $sql_del_modules = "DELETE FROM course_modules WHERE course_id = ?";
            $stmt_del_modules = $conn->prepare($sql_del_modules);
            $stmt_del_modules->bind_param("i", $course_id_to_delete);
            if(!$stmt_del_modules->execute()) { throw new Exception("Failed to delete modules: " . $stmt_del_modules->error); }
            $stmt_del_modules->close();

            // 9. Delete Assignments
            $sql_del_assign = "DELETE FROM course_assignments WHERE course_id = ?";
            $stmt_del_assign = $conn->prepare($sql_del_assign);
             if(!$stmt_del_assign->execute()) { throw new Exception("Failed to delete assignments: " . $stmt_del_assign->error); }
             $stmt_del_assign->close();

            // 10. Delete Course itself (Add company check for security)
            $sql_del_course = "DELETE FROM courses WHERE id = ? AND company_id = ?";
            $stmt_del_course = $conn->prepare($sql_del_course);
            $stmt_del_course->bind_param("ii", $course_id_to_delete, $company_id_context);
            if(!$stmt_del_course->execute()) { throw new Exception("Failed to delete course: " . $stmt_del_course->error); }
             $rows_affected = $stmt_del_course->affected_rows;
             $stmt_del_course->close();

            if ($rows_affected > 0) {
                 $conn->commit();
                 log_audit_action($conn, 'course_deleted', "Trainer deleted course: {$deleted_course_title} (ID: {$course_id_to_delete})", $user_id, $company_id_context, 'course', $course_id_to_delete);
                 $success_message = "Course '{$deleted_course_title}' and all related data deleted successfully.";
                 // Redirect to prevent resubmission on refresh
                 header("location: " . BASE_URL . "learning/manage_courses.php?success=deleted");
                 exit;
            } else {
                 throw new Exception("Course not found or permission denied.");
            }

        } catch (Exception $e) {
            $conn->rollback();
            error_log("Error deleting course ID {$course_id_to_delete}: " . $e->getMessage());
            $error_message = "Error deleting course: " . $e->getMessage();
        }
    } else {
        $error_message = "Invalid Course ID for deletion.";
    }
}

// **NEW ACTION: Handle Deleting a Module**
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['delete_module_id'])) {
    $module_id_to_delete = (int)$_POST['delete_module_id'];
    
    // Begin transaction for safe multi-table deletion
    $conn->begin_transaction();
    try {
        // 1. Check module ownership/existence and get module type/title
        $sql_check = "SELECT title, module_type, test_id FROM course_modules WHERE id = ? AND course_id = ?";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param("ii", $module_id_to_delete, $course_id);
        $stmt_check->execute();
        $module_data = $stmt_check->get_result()->fetch_assoc();
        $stmt_check->close();

        if (!$module_data) {
            throw new Exception("Module not found or does not belong to this course.");
        }
        $module_title = $module_data['title'];
        $test_id = $module_data['test_id'];
        
        // 2. Delete related test, questions, and options if it's a 'test' module
        if ($module_data['module_type'] == 'test' && $test_id) {
            $sql_del_opt = "DELETE FROM question_options WHERE question_id IN (SELECT id FROM test_questions WHERE test_id = ?)";
            $stmt_del_opt = $conn->prepare($sql_del_opt);
            $stmt_del_opt->bind_param("i", $test_id);
            if(!$stmt_del_opt->execute()) { throw new Exception("Failed to delete test options: " . $stmt_del_opt->error); }
            $stmt_del_opt->close();

            $sql_del_q = "DELETE FROM test_questions WHERE test_id = ?";
            $stmt_del_q = $conn->prepare($sql_del_q);
            $stmt_del_q->bind_param("i", $test_id);
            if(!$stmt_del_q->execute()) { throw new Exception("Failed to delete test questions: " . $stmt_del_q->error); }
            $stmt_del_q->close();
            
            $sql_del_test = "DELETE FROM tests WHERE id = ?";
            $stmt_del_test = $conn->prepare($sql_del_test);
            $stmt_del_test->bind_param("i", $test_id);
            if(!$stmt_del_test->execute()) { throw new Exception("Failed to delete test: " . $stmt_del_test->error); }
            $stmt_del_test->close();
        }
        
        // 3. Delete common module content/progress records
        $sql_del_html = "DELETE FROM module_html_content WHERE module_id = ?";
        $stmt_del_html = $conn->prepare($sql_del_html);
        $stmt_del_html->bind_param("i", $module_id_to_delete);
        if(!$stmt_del_html->execute()) { throw new Exception("Failed to delete content: " . $stmt_del_html->error); }
        $stmt_del_html->close();
        
        $sql_del_prog = "DELETE FROM module_progress WHERE module_id = ?";
        $stmt_del_prog = $conn->prepare($sql_del_prog);
        $stmt_del_prog->bind_param("i", $module_id_to_delete);
        if(!$stmt_del_prog->execute()) { throw new Exception("Failed to delete progress: " . $stmt_del_prog->error); }
        $stmt_del_prog->close();
        
        // 4. Finally, delete the module record itself
        $sql_del_module = "DELETE FROM course_modules WHERE id = ?";
        $stmt_del_module = $conn->prepare($sql_del_module);
        $stmt_del_module->bind_param("i", $module_id_to_delete);
        if(!$stmt_del_module->execute() || $stmt_del_module->affected_rows == 0) { throw new Exception("Failed to delete module record."); }
        $stmt_del_module->close();

        // 5. Success commit and audit log
        $conn->commit();
        $success_message = "Module '{$module_title}' deleted successfully.";
        log_audit_action($conn, 'module_deleted', "Deleted module '{$module_title}' from course ID {$course_id}", $user_id, $company_id_context, 'course_module', $module_id_to_delete);
        
        // Redirect to ensure UI updates and prevents resubmission
        header("location: " . BASE_URL . "learning/manage_courses.php?id={$course_id}&success=module_deleted");
        exit;
        
    } catch (Exception $e) {
        $conn->rollback();
        $error_message = "Error deleting module: " . $e->getMessage();
    }
}


// Handle Create New Course
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_course'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    if (empty($title)) {
        $error_message = "Course Title is required.";
    } else {
        $sql_insert = "INSERT INTO courses (company_id, created_by_user_id, title, description, is_active) 
                       VALUES (?, ?, ?, ?, ?)";
        if ($stmt = $conn->prepare($sql_insert)) {
            $stmt->bind_param("iissi", $company_id_context, $user_id, $title, $description, $is_active);
            if ($stmt->execute()) {
                $new_course_id = $conn->insert_id;
                log_audit_action($conn, 'course_created', "Trainer created course: {$title}", $user_id, $company_id_context, 'course', $new_course_id);
                header("location: " . BASE_URL . "learning/manage_courses.php?id={$new_course_id}&success=created");
                exit;
            } else { $error_message = "Error creating course: " . $stmt->error; }
            $stmt->close();
        } else { $error_message = "DB Prepare Error: " . $conn->error; }
    }
}

// Handle Add HTML Module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_html_module']) && $course_id) {
    $module_title = trim($_POST['module_title']);
    $html_content = $_POST['html_content']; // Get the raw HTML
    
    if (empty($module_title) || empty($html_content)) {
        $error_message = "Module Title and HTML Content are required.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Get next sort order
            $sql_sort = "SELECT IFNULL(MAX(sort_order), 0) + 1 FROM course_modules WHERE course_id = ?";
            $stmt_sort = $conn->prepare($sql_sort);
            $stmt_sort->bind_param("i", $course_id);
            $stmt_sort->execute();
            $stmt_sort->bind_result($new_sort_order);
            $stmt_sort->fetch();
            $stmt_sort->close();
            
            // 2. Create the module record
            $sql_module = "INSERT INTO course_modules (course_id, title, module_type, sort_order) 
                           VALUES (?, ?, 'html_content', ?)";
            $stmt_module = $conn->prepare($sql_module);
            $stmt_module->bind_param("isi", $course_id, $module_title, $new_sort_order);
            if(!$stmt_module->execute()) { throw new Exception("Failed to create module: " . $stmt_module->error); }
            $new_module_id = $conn->insert_id;
            $stmt_module->close();

            // 3. Insert the HTML content
            $sql_html = "INSERT INTO module_html_content (module_id, html_body) VALUES (?, ?)";
            $stmt_html = $conn->prepare($sql_html);
            $stmt_html->bind_param("is", $new_module_id, $html_content);
            if(!$stmt_html->execute()) { throw new Exception("Failed to save HTML content: " . $stmt_html->error); }
            $stmt_html->close();
            
            $conn->commit();
            $success_message = "HTML module '{$module_title}' added successfully.";
            log_audit_action($conn, 'module_created', "Added HTML module '{$module_title}' to course ID {$course_id}", $user_id, $company_id_context, 'course_module', $new_module_id);
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Handle Add PPT/Document Module (Embedded Viewer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_document_module']) && $course_id) {
    $module_title = trim($_POST['doc_module_title']);
    
    if (empty($module_title) || !isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = "Module Title and a valid document file upload are required.";
    } else {
        $conn->begin_transaction();
        try {
            // 1. Handle File Upload
            $file_path_relative = handle_course_file_upload('document_file', $course_id);

            // 2. Construct the public URL for Google Docs Viewer
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
            // NOTE: The URL must be fully qualified and public for Google Docs Viewer to work.
            $public_file_url = $protocol . '://' . $_SERVER['HTTP_HOST'] . '/' . $file_path_relative;
            
            // 3. Generate the embeddable iframe HTML
            $embed_html = '<div style="position:relative; height:0; padding-bottom:56.25%;"><iframe src="https://docs.google.com/gview?url=' . urlencode($public_file_url) . '&embedded=true" style="position:absolute; top:0; left:0; width:100%; height:100%; border:0;" frameborder="0"></iframe></div>' .
                          '<p class="text-center mt-3 text-muted">Viewing document: ' . htmlspecialchars(basename($file_path_relative)) . '</p>';

            // 4. Get next sort order
            $sql_sort = "SELECT IFNULL(MAX(sort_order), 0) + 1 FROM course_modules WHERE course_id = ?";
            $stmt_sort = $conn->prepare($sql_sort);
            $stmt_sort->bind_param("i", $course_id);
            $stmt_sort->execute();
            $stmt_sort->bind_result($new_sort_order);
            $stmt_sort->fetch();
            $stmt_sort->close();
            
            // 5. Create the module record (using document_embed as type for clarity)
            $sql_module = "INSERT INTO course_modules (course_id, title, module_type, sort_order) 
                           VALUES (?, ?, 'document_embed', ?)";
            $stmt_module = $conn->prepare($sql_module);
            $stmt_module->bind_param("isi", $course_id, $module_title, $new_sort_order);
            if(!$stmt_module->execute()) { throw new Exception("Failed to create module: " . $stmt_module->error); }
            $new_module_id = $conn->insert_id;
            $stmt_module->close();

            // 6. Insert the EMBED HTML content into module_html_content, including file path in HTML
            $final_html_body = $embed_html; 

            $sql_html = "INSERT INTO module_html_content (module_id, html_body) VALUES (?, ?)";
            $stmt_html = $conn->prepare($sql_html);
            $stmt_html->bind_param("is", $new_module_id, $final_html_body);
            if(!$stmt_html->execute()) { throw new Exception("Failed to save embedded content: " . $stmt_html->error); }
            $stmt_html->close();
            
            $conn->commit();
            $success_message = "Document module '{$module_title}' added successfully. File embedded via Google Docs Viewer.";
            log_audit_action($conn, 'module_created', "Added Embedded Document module '{$module_title}' to course ID {$course_id}", $user_id, $company_id_context, 'course_module', $new_module_id);
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}


// Handle Add Test Module
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_test_module']) && $course_id) {
    $module_title = trim($_POST['test_title']);
    $pass_mark = (int)($_POST['pass_mark_percentage']);
    
    if (empty($module_title) || $pass_mark <= 0 || $pass_mark > 100) {
        $error_message = "Test Title and a valid Pass Mark (1-100) are required.";
    } else {
         $conn->begin_transaction();
         try {
            // *** START: REVISED LOGIC TO FIX CIRCULAR KEY AND BROKEN LINKS ***

            // 1. Get next sort order
            $sql_sort = "SELECT IFNULL(MAX(sort_order), 0) + 1 FROM course_modules WHERE course_id = ?";
            $stmt_sort = $conn->prepare($sql_sort);
            $stmt_sort->bind_param("i", $course_id);
            $stmt_sort->execute();
            $stmt_sort->bind_result($new_sort_order);
            $stmt_sort->fetch();
            $stmt_sort->close();

            // 2. Create the module record first, with test_id as NULL
            $sql_module = "INSERT INTO course_modules (course_id, title, module_type, sort_order, test_id) 
                           VALUES (?, ?, 'test', ?, NULL)";
            $stmt_module = $conn->prepare($sql_module);
            $stmt_module->bind_param("isi", $course_id, $module_title, $new_sort_order);
            if(!$stmt_module->execute()) { throw new Exception("Failed to create module record: " . $stmt_module->error); }
            $new_module_id = $conn->insert_id; // Get the ID of the new module
            $stmt_module->close();

            // 3. Create the test record, linking it to the new module
            $sql_test = "INSERT INTO tests (module_id, title, pass_mark_percentage) VALUES (?, ?, ?)";
            $stmt_test = $conn->prepare($sql_test);
            $stmt_test->bind_param("isi", $new_module_id, $module_title, $pass_mark);
            if(!$stmt_test->execute()) { throw new Exception("Failed to create test record: " . $stmt_test->error); }
            $new_test_id = $conn->insert_id; // Get the ID of the new test
            $stmt_test->close();

            // 4. Update the module record to store the new test_id (completes the link)
            $sql_update_module = "UPDATE course_modules SET test_id = ? WHERE id = ?";
            $stmt_update_module = $conn->prepare($sql_update_module);
            $stmt_update_module->bind_param("ii", $new_test_id, $new_module_id);
            if(!$stmt_update_module->execute()) { throw new Exception("Failed to link module to test: " . $stmt_update_module->error); }
            $stmt_update_module->close();
            
            // *** END: REVISED LOGIC ***

            $conn->commit();
            $success_message = "Test module '{$module_title}' created. You can now add questions.";
            log_audit_action($conn, 'module_created', "Added Test module '{$module_title}' to course ID {$course_id}", $user_id, $company_id_context, 'course_module', $new_module_id);
         } catch (Exception $e) {
             $conn->rollback();
             $error_message = "Transaction Failed: " . $e->getMessage();
         }
    }
}


// --- DATA FETCHING ---
if ($course_id) {
    // We are editing a specific course
    $is_editing = true;
    
    // Fetch Course Details
    $sql_course = "SELECT * FROM courses WHERE id = ? AND company_id = ?";
    if($stmt_course = $conn->prepare($sql_course)) {
        $stmt_course->bind_param("ii", $course_id, $company_id_context);
        $stmt_course->execute();
        $result = $stmt_course->get_result();
        $course_details = $result->fetch_assoc();
        $stmt_course->close();
        if(!$course_details) {
             header("location: " . BASE_URL . "learning/manage_courses.php?error=notfound"); exit;
        }
    } else { $error_message = "DB Error: " . $conn->error; }
    
    // Fetch Modules for this course
    // *** MODIFIED: Use the 'test_id' from 'course_modules' table for the link ***
    $sql_modules = "SELECT m.*, m.test_id as test_id 
                    FROM course_modules m 
                    WHERE m.course_id = ? 
                    ORDER BY m.sort_order, m.id"; // Added id to sort
    if($stmt_modules = $conn->prepare($sql_modules)) {
        $stmt_modules->bind_param("i", $course_id);
        if($stmt_modules->execute()){
            $result_modules = $stmt_modules->get_result();
            while($row = $result_modules->fetch_assoc()) {
                $course_modules[] = $row;
            }
        } else { $error_message = "Error fetching modules: " . $stmt_modules->error; }
        $stmt_modules->close();
    } else { $error_message = "Error fetching modules: " . $conn->error; }

} else {
    // We are on the main list page, fetch all courses for the company
    $is_editing = false;
    $trainer_courses = []; // Use the variable from index.php
    $sql_trainer = "SELECT c.*, COUNT(a.id) as total_assigned
                    FROM courses c
                    LEFT JOIN course_assignments a ON c.id = a.course_id
                    WHERE c.company_id = ?
                    GROUP BY c.id
                    ORDER BY c.created_at DESC";
    if($stmt_trainer = $conn->prepare($sql_trainer)) {
        $stmt_trainer->bind_param("i", $company_id_context);
        if($stmt_trainer->execute()) {
            $result_trainer = $stmt_trainer->get_result();
            while($row_trainer = $result_trainer->fetch_assoc()) {
                $trainer_courses[] = $row_trainer;
            }
        } else { $error_message = "Error fetching courses: ".$stmt_trainer->error; }
        $stmt_trainer->close();
    } else { $error_message = "DB Error: " . $conn->error; }
}

if(isset($_GET['success']) && $_GET['success'] == 'created') {
    $success_message = "Course created successfully. You can now add modules.";
}
if(isset($_GET['success']) && $_GET['success'] == 'deleted') {
    $success_message = "Course deleted successfully.";
}
if(isset($_GET['success']) && $_GET['success'] == 'module_deleted') {
    $success_message = "Module deleted successfully.";
}

require_once 'includes/header.php';
?>

<?php if ($is_editing): ?>
    <!-- ============================================= -->
    <!--     COURSE EDITOR PAGE (Edit single course)   -->
    <!-- ============================================= -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Course Editor</h2>
        <a href="learning/manage_courses.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to All Courses</a>
    </div>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <div class="card mb-4">
        <div class="card-header"><h4>Course Details: <?php echo htmlspecialchars($course_details['title']); ?></h4></div>
        <div class="card-body">
            <!-- TODO: Add form to edit course details (title, description, is_active) -->
            <p><?php echo htmlspecialchars($course_details['description']); ?></p>
        </div>
    </div>
    
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header"><h5>Course Modules</h5></div>
                <div class="card-body">
                    <?php if(empty($course_modules)): ?>
                        <p class="text-muted">No modules created yet. Use the forms to add your first module.</p>
                    <?php else: ?>
                        <ul class="list-group">
                        <?php foreach($course_modules as $module): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i data-lucide="<?php 
                                        if ($module['module_type'] == 'test') echo 'file-question'; 
                                        elseif ($module['module_type'] == 'document_embed') echo 'file-text';
                                        else echo 'layout-dashboard'; 
                                    ?>"></i>
                                    <strong><?php echo htmlspecialchars($module['title']); ?></strong>
                                    <?php if ($module['module_type'] == 'document_embed' && isset($module['file_url'])): ?>
                                        <a href="<?php echo htmlspecialchars($module['file_url']); ?>" class="ms-3 badge bg-primary" target="_blank" title="View/Download Original File">Download</a>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?php if($module['module_type'] == 'test'): ?>
                                        <a href="learning/manage_test.php?id=<?php echo $module['test_id']; ?>" class="btn btn-warning btn-sm">Edit Test</a>
                                    <?php elseif($module['module_type'] == 'html_content' || $module['module_type'] == 'document_embed'): ?>
                                        <!-- Enabled Edit Content (placeholder) -->
                                        <a href="#" class="btn btn-warning btn-sm disabled">Edit Content</a>
                                    <?php endif; ?>
                                    <!-- Enabled Delete Button -->
                                    <form action="learning/manage_courses.php?id=<?php echo $course_id; ?>" method="post" class="d-inline" onsubmit="return confirm('WARNING: Deleting this module will delete all associated test data and user progress for this module. Are you sure?');">
                                        <input type="hidden" name="delete_module_id" value="<?php echo $module['id']; ?>">
                                        <button type="submit" name="delete_module" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><h5>Add HTML Module</h5></div>
                <div class="card-body">
                    <form action="learning/manage_courses.php?id=<?php echo $course_id; ?>" method="post">
                        <div class="mb-3">
                            <label class="form-label">Module Title</label>
                            <input type="text" name="module_title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">HTML Content</label>
                            <textarea class="form-control" name="html_content" rows="5" placeholder="Paste your raw HTML code here..."></textarea>
                            <div class="form-text">Your HTML will be saved and rendered as-is.</div>
                        </div>
                        <button type="submit" name="add_html_module" class="btn btn-primary">Add HTML Module</button>
                    </form>
                </div>
            </div>
            
            <!-- NEW: Embedded Document Module -->
            <div class="card mb-3">
                <div class="card-header bg-info text-dark"><h5>Add PPT/Document Module</h5></div>
                <div class="card-body">
                    <form action="learning/manage_courses.php?id=<?php echo $course_id; ?>" method="post" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Module Title</label>
                            <input type="text" name="doc_module_title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Upload File (PPT, PDF, DOCX)</label>
                            <input type="file" name="document_file" class="form-control" accept=".pdf,.ppt,.pptx,.doc,.docx,.xls,.xlsx" required>
                            <div class="form-text">File will be embedded using Google Docs Viewer. (Max 20MB)</div>
                        </div>
                        <button type="submit" name="add_document_module" class="btn btn-info">Add Embedded Document</button>
                    </form>
                </div>
            </div>
            
            <div class="card">
                <div class="card-header bg-warning text-dark"><h5>Add Test Module</h5></div>
                <div class="card-body">
                     <form action="learning/manage_courses.php?id=<?php echo $course_id; ?>" method="post">
                        <div class="mb-3">
                            <label class="form-label">Test Title</label>
                            <input type="text" name="test_title" class="form-control" required>
                        </div>
                         <div class="mb-3">
                            <label class="form-label">Pass Mark (%)</label>
                            <input type="number" name="pass_mark_percentage" class="form-control" min="1" max="100" value="70" required>
                        </div>
                        <button type="submit" name="add_test_module" class="btn btn-warning">Create Test</button>
                     </form>
                </div>
            </div>
        </div>
    </div>

<?php else: ?>
    <!-- ============================================= -->
    <!--     COURSE LIST PAGE (Main landing page)      -->
    <!-- ============================================= -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2>Manage Courses</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createCourseModal">
            <i data-lucide="plus"></i> Create New Course
        </button>
    </div>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-header"><h4>Your Company's Courses</h4></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                     <thead>
                        <tr>
                            <th>Course Title</th>
                            <th>Status</th>
                            <th>Total Assigned</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                         <?php if (empty($trainer_courses)): ?>
                            <tr><td colspan="4" class="text-center text-muted">You have not created any courses yet.</td></tr>
                        <?php else: ?>
                            <?php foreach($trainer_courses as $course): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($course['title']); ?></td>
                                <td>
                                    <span class="badge <?php echo $course['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                        <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td><?php echo $course['total_assigned']; ?></td>
                                <td>
                                    <a href="learning/manage_courses.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-warning">Edit Course</a>
                                    <a href="learning/assign_courses.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info">Assign</a>
                                    <!-- ADD DELETE BUTTON HERE -->
                                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?php echo $course['id']; ?>, '<?php echo htmlspecialchars(addslashes($course['title'])); ?>')">Delete</button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Create Course Modal -->
    <div class="modal fade" id="createCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form action="learning/manage_courses.php" method="post">
                    <div class="modal-header">
                        <h5 class="modal-title">Create New Course</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="title" class="form-label">Course Title</label>
                            <input type="text" class="form-control" id="title" name="title" required>
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                        </div>
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                            <label class="form-check-label" for="is_active">Set as Active</label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" name="create_course" class="btn btn-primary">Create Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<?php endif; ?>

<!-- *** ADD JAVASCRIPT FOR DELETE CONFIRM *** -->
<script>
function confirmDelete(courseId, courseTitle) {
    if (confirm(`Are you sure you want to delete the course "${courseTitle}"?\n\nWARNING: This will delete the course, all its modules, assignments, and related progress. This action cannot be undone.`)) {
        // Create a form dynamically and submit it
        const form = document.createElement('form');
        form.method = 'post';
        form.action = '/learning/manage_courses.php'; // Post back to the same page

        const idInput = document.createElement('input');
        idInput.type = 'hidden';
        idInput.name = 'delete_course_id';
        idInput.value = courseId;
        form.appendChild(idInput);

        document.body.appendChild(form);
        form.submit();
    }
}

function addOption() {
    // This function is defined in the Test module section, but included here for completeness
    let optionCount = document.querySelectorAll('#options-container .input-group').length;
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
}
</script>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


