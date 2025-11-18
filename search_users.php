<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once 'includes/init.php'; 

// -- SECURITY CHECK: ONLY ADMIN/MANAGEMENT ROLES CAN SEARCH PROFILES --
// Roles that require oversight capabilities
if (!has_any_role(['admin', 'platform_admin', 'purchase_head', 'marketing_manager', 'approver', 'trainer'])) {
    header("location: " . BASE_URL . "employee/index.php"); 
    exit;
}

$company_id_context = get_current_company_id();
$user_id = $_SESSION['user_id'];
$error_message = '';
$search_query = $_GET['q'] ?? '';
$search_results = [];
$view_user_id = $_GET['view_id'] ?? null;
$profile_details = null;

// --- DATA FETCHING: SEARCH LOGIC ---
if (!empty($search_query)) {
    // Search by full name, employee code, or email
    $sql_search = "SELECT id, full_name, employee_code, email, department
                   FROM users
                   WHERE company_id = ?
                   AND (full_name LIKE ? OR employee_code LIKE ? OR email LIKE ?)
                   ORDER BY full_name
                   LIMIT 50";
    
    if ($stmt_search = $conn->prepare($sql_search)) {
        $like_query = "%" . $search_query . "%";
        $stmt_search->bind_param("isss", $company_id_context, $like_query, $like_query, $like_query);
        
        if ($stmt_search->execute()) {
            $result = $stmt_search->get_result();
            while ($row = $result->fetch_assoc()) {
                $search_results[] = $row;
            }
        } else { $error_message = "Search error: " . $stmt_search->error; }
        $stmt_search->close();
    }
}

// --- DATA FETCHING: PROFILE VIEW LOGIC ---
if ($view_user_id) {
    $sql_profile = "SELECT 
                        u.*, 
                        s.store_name, 
                        a.full_name AS approver_name,
                        GROUP_CONCAT(r.name) AS roles
                    FROM users u
                    LEFT JOIN stores s ON u.store_id = s.id
                    LEFT JOIN users a ON u.approver_id = a.id
                    LEFT JOIN user_roles ur ON u.id = ur.user_id
                    LEFT JOIN roles r ON ur.role_id = r.id
                    WHERE u.id = ? AND u.company_id = ?
                    GROUP BY u.id";

    if ($stmt_profile = $conn->prepare($sql_profile)) {
        $stmt_profile->bind_param("ii", $view_user_id, $company_id_context);
        if ($stmt_profile->execute()) {
            $profile_details = $stmt_profile->get_result()->fetch_assoc();
        }
        $stmt_profile->close();
    }
    // If not found or outside company, clear profile
    if (!$profile_details || $profile_details['company_id'] != $company_id_context) {
        $error_message = "User not found or inaccessible.";
        $view_user_id = null;
    }
}

require_once 'includes/header.php'; 
?>

<div class="container mt-4">
    <h2>Employee & Profile Search</h2>
    <p>Find colleagues by name, email, or employee code to view their profile details.</p>

    <?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>

    <!-- Search Form -->
    <div class="card mb-4 no-print">
        <div class="card-body">
            <form action="search_users.php" method="get" class="row g-3">
                <div class="col-md-10">
                    <input type="text" name="q" class="form-control form-control-lg" placeholder="Search by Name, Employee Code, or Email" value="<?php echo htmlspecialchars($search_query); ?>" required>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-lg w-100">Search</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($view_user_id && $profile_details): ?>
    <!-- --- PROFILE DETAIL VIEW --- -->
    <div class="card shadow-lg">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><?php echo htmlspecialchars($profile_details['full_name']); ?>'s Profile</h4>
        </div>
        <div class="card-body">
            <div class="row">
                <!-- Photo and Summary -->
                <div class="col-md-4 text-center">
                    <img src="<?php echo htmlspecialchars($profile_details['photo_url'] ?? 'https://placehold.co/150x150/EEEEEE/AAAAAA?text=No+Photo'); ?>" 
                         alt="Employee Photo" class="img-fluid rounded-circle mb-3" style="width: 150px; height: 150px; object-fit: cover;">
                    <p class="fs-5 fw-bold mb-0"><?php echo htmlspecialchars($profile_details['employee_code'] ?? 'â€”'); ?></p>
                    <p class="text-muted"><?php echo htmlspecialchars($profile_details['department']); ?></p>
                </div>
                <!-- Details -->
                <div class="col-md-8">
                    <h5 class="border-bottom pb-2">Employment Details</h5>
                    <div class="row">
                        <div class="col-sm-6 mb-2"><strong>Store/Cost Center:</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo htmlspecialchars($profile_details['store_name'] ?? 'Head Office'); ?></div>

                        <div class="col-sm-6 mb-2"><strong>Date of Joining:</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo $profile_details['doj'] ? date('Y-m-d', strtotime($profile_details['doj'])) : 'N/A'; ?></div>
                        
                        <div class="col-sm-6 mb-2"><strong>Date of Birth:</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo $profile_details['dob'] ? date('M j', strtotime($profile_details['dob'])) : 'N/A'; ?></div>

                        <div class="col-sm-6 mb-2"><strong>Manager (Approver):</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo htmlspecialchars($profile_details['approver_name'] ?? 'None'); ?></div>
                    </div>

                    <h5 class="border-bottom pb-2 mt-4">Contact & Access</h5>
                    <div class="row">
                         <div class="col-sm-6 mb-2"><strong>Email:</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo htmlspecialchars($profile_details['email']); ?></div>

                        <div class="col-sm-6 mb-2"><strong>Roles:</strong></div>
                        <div class="col-sm-6 mb-2"><?php echo htmlspecialchars(ucwords(str_replace(',', ', ', $profile_details['roles'] ?? ''))); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php elseif (!empty($search_results)): ?>
    <!-- --- SEARCH RESULTS LIST --- -->
    <div class="card">
        <div class="card-header"><h4>Search Results (<?php echo count($search_results); ?> Found)</h4></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Department</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($search_results as $result): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($result['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($result['employee_code'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($result['department']); ?></td>
                            <td><a href="search_users.php?view_id=<?php echo $result['id']; ?>" class="btn btn-sm btn-info">View Profile</a></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php elseif (!empty($search_query)): ?>
    <div class="alert alert-warning">No users found matching "<?php echo htmlspecialchars($search_query); ?>."</div>
    <?php endif; ?>
</div>

<?php
if(isset($conn)) $conn->close();
require_once 'includes/footer.php';
?>


