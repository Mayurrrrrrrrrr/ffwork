<?php
// Use the new Learning Portal header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id

$error_message = $_GET['error'] ?? ''; // Display errors passed from other pages
$success_message = $_GET['success'] ?? '';
$my_assigned_courses = [];
$trainer_courses = []; // Courses created by this user if they are a trainer/admin
$my_total_points = 0;
$leaderboard = [];

// --- DATA FETCHING (ROLE-AWARE & COMPANY-AWARE) ---

// 1. Fetch courses assigned to the 'employee' (learner)
if (check_role('employee') && $user_id && $company_id_context) {
    // Keep the stricter query joining courses and checking company_id
    $sql_mine = "SELECT
                    c.id as course_id,
                    c.title, c.description,
                    a.id as assignment_id,
                    a.status,
                    a.completed_at -- Using completed_at as completion_date is missing
                 FROM course_assignments a
                 JOIN courses c ON a.course_id = c.id
                 WHERE a.user_id = ? AND c.company_id = ? AND c.is_active = 1
                 ORDER BY a.status, c.title";

    if($stmt_mine = $conn->prepare($sql_mine)) {
        $stmt_mine->bind_param("ii", $user_id, $company_id_context);
         if($stmt_mine->execute()) {
            $result = $stmt_mine->get_result();
            while($row = $result->fetch_assoc()) { $my_assigned_courses[] = $row; }
        } else { $error_message = "Error fetching my courses: ".$stmt_mine->error; }
        $stmt_mine->close();
    } else { $error_message = "DB Error (my courses): ".$conn->error; }
} else if (check_role('employee')) {
     $error_message = "Could not identify user or company context.";
}


// 2. Fetch courses managed by 'trainer' or 'admin'
if (has_any_role(['trainer', 'admin', 'platform_admin']) && $user_id && $company_id_context) {
    $sql_managed = "SELECT id, title, description, is_active, created_at
                    FROM courses
                    WHERE company_id = ?
                    ORDER BY is_active DESC, title ASC";
    if($stmt_managed = $conn->prepare($sql_managed)) {
        $stmt_managed->bind_param("i", $company_id_context);
        if ($stmt_managed->execute()) {
             $result_managed = $stmt_managed->get_result();
             while($row = $result_managed->fetch_assoc()) { $trainer_courses[] = $row; }
        } else { $error_message = "Error fetching managed courses: ".$stmt_managed->error; }
        $stmt_managed->close();
    } else { $error_message = "DB Error (managed courses): ".$conn->error; }
}

// 3. Fetch user's total available points (if employee)
if (check_role('employee') && $user_id) {
     $sql_points = "SELECT
                    COALESCE(SUM(tr.score), 0) as total_earned,
                    COALESCE((SELECT SUM(r.points_cost) FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id WHERE rr.user_id = ?), 0) as total_spent
                    FROM test_results tr
                    JOIN course_assignments ca ON tr.assignment_id = ca.id
                    WHERE ca.user_id = ?";
    if($stmt_pts = $conn->prepare($sql_points)){
        $stmt_pts->bind_param("ii", $user_id, $user_id);
        if($stmt_pts->execute()){
            $pts_result = $stmt_pts->get_result()->fetch_assoc();
            $my_total_points = ($pts_result['total_earned'] ?? 0) - ($pts_result['total_spent'] ?? 0);
        } else { $error_message = "Error fetching points: ".$stmt_pts->error; }
        $stmt_pts->close();
    } else { $error_message = "DB Error (points): ".$conn->error; }
}


// 4. Fetch company leaderboard (Top 10 users by available points)
if ($company_id_context) {
    $sql_leader = "SELECT
                       u.full_name,
                       (COALESCE(SUM(tr.score), 0) - COALESCE((SELECT SUM(r.points_cost) FROM reward_redemptions rr JOIN rewards r ON rr.reward_id = r.id WHERE rr.user_id = u.id), 0)) as available_points
                   FROM users u
                   LEFT JOIN course_assignments ca ON u.id = ca.user_id
                   LEFT JOIN test_results tr ON ca.id = tr.assignment_id
                   WHERE u.company_id = ?
                   GROUP BY u.id, u.full_name
                   HAVING available_points > 0 -- Only show users with points
                   ORDER BY available_points DESC
                   LIMIT 10";
     if($stmt_lead = $conn->prepare($sql_leader)){
         $stmt_lead->bind_param("i", $company_id_context);
         if($stmt_lead->execute()){
             $result_lead = $stmt_lead->get_result();
             while($row = $result_lead->fetch_assoc()){ $leaderboard[] = $row; }
         } else { $error_message = "Error fetching leaderboard: ".$stmt_lead->error; }
         $stmt_lead->close();
     } else { $error_message = "DB Error (leaderboard): ".$conn->error; }
}

// Map error codes to user-friendly messages
$error_map = [
    'not_assigned' => 'You are not assigned to that course, it may not exist, or it may belong to a different company.', // Updated message
    'invalid_course' => 'Invalid course or assignment specified.',
    'no_course' => 'No course or assignment ID was provided.',
    'invalid_test' => 'Invalid test specified.',
    'test_not_found' => 'The requested test could not be found.',
    'already_submitted' => 'You have already submitted this test.',
    'company_context_lost' => 'Your company session was lost. Please log in again.'
];
if (!empty($error_message) && isset($error_map[$error_message])) {
    $error_message = $error_map[$error_message];
}

// Map success codes
$success_map = [
    'assigned' => 'Course assigned successfully!',
    'test_submitted' => 'Test submitted successfully!',
    'reward_redeemed' => 'Reward redeemed successfully!'
    // Add more success messages here
];
if (!empty($success_message) && isset($success_map[$success_message])) {
    $success_message = $success_map[$success_message];
}


?>

<div class="container mt-4">
    <div class="row mb-4">
        <div class="col-md-8">
            <h1 class="h3 mb-0">Learning Portal</h1>
        </div>
        <div class="col-md-4 text-md-end">
            <?php if (has_any_role(['trainer', 'admin', 'platform_admin'])): ?>
                <a href="learning/manage_courses.php" class="btn btn-success me-1"><i class="fas fa-plus me-1"></i> Manage Courses</a>
                <a href="learning/assign_courses.php" class="btn btn-info me-1"><i class="fas fa-user-plus me-1"></i> Assign Courses</a>
                 <a href="learning/view_progress.php" class="btn btn-secondary"><i class="fas fa-chart-line me-1"></i> View Progress</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($error_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

<div class="row">
    <!-- Left Column: My Courses & Trainer Courses -->
    <div class="col-lg-8">
        <?php if (check_role('employee')): ?>
        <!-- My Assigned Courses -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header"><h4><i class="fas fa-book-reader me-2"></i>My Assigned Courses</h4></div>
            <div class="card-body">
                <?php if (empty($my_assigned_courses)): ?>
                    <p class="text-muted">You are not currently assigned to any courses.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($my_assigned_courses as $course): ?>
                            <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-1"><?php echo htmlspecialchars($course['title']); ?></h5>
                                    <small class="text-muted"><?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>...</small>
                                    <br>
                                     <span class="badge <?php
                                            switch($course['status']) {
                                                case 'Completed': echo 'bg-success'; break;
                                                case 'In Progress': echo 'bg-info'; break;
                                                default: echo 'bg-secondary'; // Not Started / Pending
                                            }
                                        ?>"><?php echo htmlspecialchars($course['status']); ?></span>
                                     <?php if ($course['completed_at']): ?>
                                        <small class="ms-2 text-muted">Completed: <?php echo date("M j, Y", strtotime($course['completed_at'])); ?></small>
                                     <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <?php if ($course['status'] == 'Completed'): ?>
                                        <a href="learning/generate_certificate.php?id=<?php echo $course['assignment_id']; ?>" class="btn btn-sm btn-outline-primary mb-1"><i class="fas fa-certificate me-1"></i> View Certificate</a>
                                         <?php // *** FIX: Link uses assignment_id *** ?>
                                         <a href="learning/take_course.php?id=<?php echo $course['assignment_id']; ?>" class="btn btn-sm btn-secondary"><i class="fas fa-redo me-1"></i> Review Course</a>
                                    <?php else: ?>
                                         <?php // *** FIX: Link uses assignment_id *** ?>
                                        <a href="learning/take_course.php?id=<?php echo $course['assignment_id']; ?>" class="btn btn-sm btn-primary">
                                            <?php echo ($course['status'] == 'In Progress') ? '<i class="fas fa-play-circle me-1"></i> Continue Course' : '<i class="fas fa-play me-1"></i> Start Course'; ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (has_any_role(['trainer', 'admin', 'platform_admin']) && !empty($trainer_courses)): ?>
         <!-- Courses Managed by Trainer/Admin -->
        <div class="card mb-4 shadow-sm">
            <div class="card-header bg-light"><h4><i class="fas fa-chalkboard-teacher me-2"></i>Courses You Manage</h4></div>
            <div class="card-body">
                 <div class="list-group">
                    <?php foreach ($trainer_courses as $course): ?>
                        <div class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                             <div>
                                <h5 class="mb-1"><?php echo htmlspecialchars($course['title']); ?></h5>
                                <small class="text-muted"><?php echo htmlspecialchars(substr($course['description'], 0, 100)); ?>...</small><br>
                                 <span class="badge <?php echo $course['is_active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                     <?php echo $course['is_active'] ? 'Active' : 'Inactive'; ?>
                                 </span>
                                 <small class="ms-2 text-muted">Created: <?php echo date("M j, Y", strtotime($course['created_at'])); ?></small>
                             </div>
                             <div class="text-end">
                                <a href="learning/manage_courses.php?id=<?php echo $course['id']; ?>" class="btn btn-sm btn-warning mb-1"><i class="fas fa-edit me-1"></i> Edit Modules</a>
                                <a href="learning/assign_courses.php?course_id=<?php echo $course['id']; ?>" class="btn btn-sm btn-info"><i class="fas fa-user-plus me-1"></i> Assign</a>
                             </div>
                        </div>
                    <?php endforeach; ?>
                 </div>
            </div>
             <div class="card-footer text-end">
                 <a href="learning/manage_courses.php" class="btn btn-sm btn-primary"><i class="fas fa-plus me-1"></i> Add/Manage All Courses</a>
             </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column: Points & Leaderboard -->
    <div class="col-lg-4">
        <?php if (check_role('employee')): ?>
        <div class="card mb-4 shadow-sm text-center">
             <div class="card-header"><h5><i class="fas fa-star me-2 text-warning"></i>My Points</h5></div>
            <div class="card-body">
                <p class="display-4 fw-bold text-primary mb-0"><?php echo number_format($my_total_points); ?></p>
                <p class="text-muted mb-2">Available Points</p>
                <a href="learning/rewards.php" class="btn btn-outline-primary btn-sm mt-2"><i class="fas fa-gift me-1"></i> View Rewards</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm">
            <div class="card-header"><h4><i class="fas fa-trophy me-2 text-warning"></i>Company Leaderboard</h4></div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php if (empty($leaderboard)): ?>
                        <li class="list-group-item text-muted text-center small py-3">No test results recorded yet.</li>
                    <?php else: ?>
                        <?php $rank = 1; foreach($leaderboard as $entry): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center py-2 px-3">
                            <div>
                                <span class="fw-bold me-2 small"><?php echo $rank++; ?>.</span>
                                <small><?php echo htmlspecialchars($entry['full_name']); ?></small>
                            </div>
                            <span class="badge bg-primary rounded-pill small"><?php echo number_format($entry['available_points']); ?> pts</span>
                        </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
             <?php if (has_any_role(['trainer', 'admin', 'platform_admin'])): ?>
             <div class="card-footer text-end py-1 px-3">
                 <a href="learning/manage_rewards.php" class="btn btn-sm btn-outline-secondary py-0 me-1">Manage Rewards</a>
                 <a href="learning/view_progress.php" class="btn btn-sm btn-outline-secondary py-0">View All Progress</a>
             </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</div>


<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


