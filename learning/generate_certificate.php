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

// Get the assignment ID from the URL
$assignment_id = $_GET['id'] ?? null;
if (!$assignment_id || !is_numeric($assignment_id)) {
    header("location: " . BASE_URL . "learning/index.php?error=invalid_course");
    exit;
}

// --- DATA FETCHING & VALIDATION ---
// 1. Fetch assignment, course, and user details, BUT ONLY if it's 'Completed'
$cert_data = null;
$sql_cert = "SELECT 
                a.id as assignment_id, a.completed_at,
                c.title as course_title,
                u.full_name
             FROM course_assignments a
             JOIN courses c ON a.course_id = c.id
             JOIN users u ON a.user_id = u.id
             WHERE a.id = ? 
             AND a.user_id = ? 
             AND a.company_id = ?
             AND a.status = 'Completed'"; // CRITICAL: Only allow for completed courses

if($stmt_cert = $conn->prepare($sql_cert)){
    $stmt_cert->bind_param("iii", $assignment_id, $user_id, $company_id);
    $stmt_cert->execute();
    $result_cert = $stmt_cert->get_result();
    $cert_data = $result_cert->fetch_assoc();
    $stmt_cert->close();
    if(!$cert_data) {
         // User has not completed this course or it's invalid
         die("Error: Certificate not found or course not yet completed.");
    }
} else { die("Database error."); }

// 2. Generate or Fetch Certificate Code
$certificate_code = '';
$sql_find_code = "SELECT certificate_code FROM certificates WHERE assignment_id = ?";
if($stmt_find = $conn->prepare($sql_find_code)){
    $stmt_find->bind_param("i", $assignment_id);
    $stmt_find->execute();
    $result_find = $stmt_find->get_result();
    if($row_find = $result_find->fetch_assoc()){
        // Code already exists, use it
        $certificate_code = $row_find['certificate_code'];
    } else {
        // Code does not exist, create one
        $issue_date = date('Y-m-d', strtotime($cert_data['completed_at']));
        $certificate_code = "FIREFLY-" . $cert_data['assignment_id'] . "-" . $user_id . "-" . date('Ymd', strtotime($issue_date));
        
        $sql_insert_code = "INSERT INTO certificates (assignment_id, user_id, course_title, issue_date, certificate_code) 
                            VALUES (?, ?, ?, ?, ?)";
        if($stmt_insert = $conn->prepare($sql_insert_code)){
            $stmt_insert->bind_param("iisss", $assignment_id, $user_id, $cert_data['course_title'], $issue_date, $certificate_code);
            $stmt_insert->execute();
            $stmt_insert->close();
        }
    }
    $stmt_find->close();
}
$conn->close();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate of Completion</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body {
            background-color: #f0f2f5;
        }
        .no-print {
            padding: 20px;
            text-align: center;
        }
        .certificate-wrapper {
            width: 1100px;
            height: 850px;
            margin: 20px auto;
            background: #ffffff;
            border: 10px solid #004E54;
            padding: 40px;
            position: relative;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .certificate-content {
            border: 2px solid #004E54;
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .logo {
            max-width: 200px;
            margin-bottom: 20px;
        }
        .inter { font-family: 'Inter', sans-serif; }
        .playfair { font-family: 'Playfair Display', serif; }
        
        .c-title {
            font-size: 50px;
            font-weight: 700;
            color: #004E54;
            margin-bottom: 30px;
        }
        .c-subtitle {
            font-size: 24px;
            color: #555;
        }
        .c-name {
            font-size: 44px;
            font-weight: 700;
            color: #333;
            margin: 30px 0;
            border-bottom: 2px solid #ddd;
            padding-bottom: 10px;
        }
        .c-text {
            font-size: 18px;
            color: #444;
            max-width: 70%;
            line-height: 1.6;
        }
        .c-footer {
            margin-top: 50px;
            width: 100%;
            display: flex;
            justify-content: space-between;
        }
        .c-footer .signature {
            border-top: 1px solid #777;
            padding-top: 10px;
            width: 250px;
        }
        .c-code {
            position: absolute;
            bottom: 65px;
            left: 65px;
            font-size: 12px;
            color: #999;
        }
        
        @media print {
            body, html {
                margin: 0;
                padding: 0;
                background: #fff;
            }
            .no-print {
                display: none;
            }
            .certificate-wrapper {
                margin: 0;
                box-shadow: none;
                width: 100%;
                height: 100vh;
                border: 10px solid #004E54;
            }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <p class="lead">Congratulations on completing your course!</p>
        <button class="btn btn-primary" onclick="window.print();"><i data-lucide="printer" class="me-2"></i>Print Certificate</button>
        <a href="learning/take_course.php?id=<?php echo $assignment_id; ?>" class="btn btn-secondary">Back to Course</a>
    </div>

    <div class="certificate-wrapper">
        <div class="certificate-content text-center">
            
            <img src="assets/logo.png" alt="Company Logo" class="logo" onerror="this.onerror=null; this.src='https://placehold.co/200x60/004E54/FFFFFF?text=PORTAL';">
            
            <p class="c-subtitle inter">This certificate is proudly presented to</p>
            
            <h1 class="c-name playfair"><?php echo htmlspecialchars($cert_data['full_name']); ?></h1>
            
            <p class="c-subtitle inter">for the successful completion of the course</p>
            
            <h2 class="c-title playfair mt-3"><?php echo htmlspecialchars($cert_data['course_title']); ?></h2>
            
            <div class="c-footer">
                <div class="text-center">
                    <p class="inter c-signature mb-0"><strong>Training Department</strong></p>
                </div>
                <div class="text-center">
                     <p class="inter mb-1">Issued on</p>
                     <strong class="inter"><?php echo date("F j, Y", strtotime($cert_data['completed_at'])); ?></strong>
                </div>
            </div>
            
            <div class="c-code inter">
                Certificate Code: <?php echo htmlspecialchars($certificate_code); ?>
            </div>
            
        </div>
    </div>
    
    <script>
        lucide.createIcons();
    </script>

</body>
</html>



