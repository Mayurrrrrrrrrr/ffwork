<?php
// Path: D:\xampp\htdocs\Companyportal\referral\dashboard.php

// 1. Include init.php FIRST
require_once 'init.php'; 

// 2. Session check
if (!isset($_SESSION["referrer_loggedin"]) || $_SESSION["referrer_loggedin"] !== true) {
    header("location: ". BASE_URL . "referral/login.php");
    exit;
}

// 3. Get Referrer details from session
$referrer_id = $_SESSION['referrer_id'];
$user_full_name = $_SESSION["referrer_name"] ?? 'Referrer';
$user_referral_code = $_SESSION["referrer_mobile"] ?? ''; 

// 4. Fetch Referrer's Total Points Balance
$total_points_balance = 0;
$stmt_points = $conn->prepare("SELECT total_points_balance FROM referrers WHERE id = ?");
if ($stmt_points) {
    $stmt_points->bind_param("i", $referrer_id);
    if ($stmt_points->execute()) {
        $stmt_points->bind_result($total_points_balance);
        $stmt_points->fetch();
    }
    $stmt_points->close();
}

// 5. Fetch Summary Statistics from 'referrals' table
$stats = [
    'total_leads' => 0,
    'successful_leads' => 0,
    'points_pending' => 0,
];

// Total leads
$stmt_total = $conn->prepare("SELECT COUNT(id) FROM referrals WHERE referrer_id = ?");
if ($stmt_total) {
    $stmt_total->bind_param("i", $referrer_id);
    if($stmt_total->execute()) {
        $stmt_total->bind_result($stats['total_leads']);
        $stmt_total->fetch();
    }
    $stmt_total->close();
}

// Successful leads (Points Awarded)
$stmt_success = $conn->prepare("SELECT COUNT(id) FROM referrals WHERE referrer_id = ? AND status = 'Points Awarded'");
if ($stmt_success) {
    $stmt_success->bind_param("i", $referrer_id);
     if($stmt_success->execute()) {
        $stmt_success->bind_result($stats['successful_leads']);
        $stmt_success->fetch();
    }
    $stmt_success->close();
}

// Points Pending (From 'Purchased' status, not yet awarded)
$stmt_pending = $conn->prepare("SELECT SUM(points_earned) FROM referrals WHERE referrer_id = ? AND status = 'Purchased'");
if ($stmt_pending) {
    $stmt_pending->bind_param("i", $referrer_id);
    if($stmt_pending->execute()) {
        $stmt_pending->bind_result($stats['points_pending']);
        $stmt_pending->fetch();
        $stats['points_pending'] = $stats['points_pending'] ?? 0; // Ensure it's 0, not NULL
    }
    $stmt_pending->close();
}

// 6. Fetch Detailed Leads List
$leads = [];
$sql_leads = "SELECT id, invitee_name, invitee_contact, created_at, status, purchase_amount, points_earned, points_awarded_at
              FROM referrals
              WHERE referrer_id = ?
              ORDER BY created_at DESC";
if ($stmt_leads = $conn->prepare($sql_leads)) {
    $stmt_leads->bind_param("i", $referrer_id);
    if($stmt_leads->execute()) {
        $result_leads = $stmt_leads->get_result();
        $leads = $result_leads->fetch_all(MYSQLI_ASSOC);
        $result_leads->free();
    } else {
        error_log("Error fetching leads list: " . $stmt_leads->error);
    }
    $stmt_leads->close();
}

// 7. Badge Function
function get_status_badge_class($status) {
    switch ($status) {
        case 'Points Awarded': return 'success';
        case 'Purchased': return 'warning';
        case 'Contacted': return 'info';
        case 'Pending': return 'secondary';
        case 'Rejected':
        case 'Expired': return 'danger';
        default: return 'light text-dark';
    }
}

// 8. Include the referral-specific header
$page_title = "Referral Dashboard";
require_once 'includes/header.php'; 
?>

<!-- Import Google Font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">

<style>
    /* Custom styles for the new dashboard */
    body, .btn {
        font-family: 'Poppins', sans-serif;
    }
    .hero-section {
        position: relative;
        background: url('https://images.unsplash.com/photo-1610465251676-d3d0c9c43ee6?q=80&w=2070&auto=format&fit=crop') no-repeat center center;
        background-size: cover;
        border-radius: 0.75rem;
        overflow: hidden;
        padding: 5rem 2rem;
        color: white;
    }
    .hero-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.6);
        z-index: 1;
    }
    .hero-section .container {
        position: relative;
        z-index: 2;
    }
    .hero-section h1 {
        font-weight: 700;
        font-size: 3rem;
    }
    .hero-section .lead {
        font-weight: 300;
        font-size: 1.25rem;
    }
    .how-it-works-icon {
        width: 80px;
        height: 80px;
        background-color: #eaf6ff;
        color: #0d6efd;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1.5rem;
    }
    .points-card {
        background-color: #1a1a1a;
        color: white;
        border-radius: 0.75rem;
        box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    }
    .points-card .display-3 {
        font-weight: 700;
        color: #ffc107; /* Bootstrap Warning/Gold */
    }
    .stat-card {
        border: none;
        border-radius: 0.75rem;
        box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        transition: all 0.2s ease-in-out;
    }
    .stat-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.07);
    }
    .stat-card .display-6 {
        font-weight: 600;
    }
    .table-responsive {
        max-height: 600px;
        overflow-y: auto;
    }
</style>

<!-- 1. Hero Section -->
<div class="hero-section text-center mb-5">
    
    <div class="hero-overlay"></div>
    <div class="container">
        <h1 class="mb-3">Share the Sparkle</h1>
        <p class="lead mb-4">Refer your friends to Firefly Diamonds and get rewarded for every new customer you bring.</p>
        <a href="<?php echo BASE_URL; ?>referral/add_referral.php" class="btn btn-primary btn-lg px-5 py-3">
            <i data-lucide="plus" class="me-1"></i> Add New Referral
        </a>
    </div>
</div>

<!-- 2. How It Works -->
<div class="container my-5 py-4">
    <h2 class="text-center fw-bold text-dark mb-5">How It Works</h2>
    <div class="row text-center g-4">
        <div class="col-md-4">
            <div class="how-it-works-icon">
                <i data-lucide="user-plus" style="width: 40px; height: 40px;"></i>
            </div>
            <h3 class="h5 fw-bold text-dark">1. Refer a Friend</h3>
            <p class="text-muted">Submit your friend's details. They must be a new Firefly customer.</p>
        </div>
        <div class="col-md-4">
            <div class="how-it-works-icon" style="background-color: #fff0d9; color: #ffc107;">
                <i data-lucide="tag" style="width: 40px; height: 40px;"></i>
            </div>
            <h3 class="h5 fw-bold text-dark">2. They Get Rs 1000 Off</h3>
            <p class="text-muted">Your friend gets an exclusive **Rs 1000 discount** on their first purchase.</p>
        </div>
        <div class="col-md-4">
            <div class="how-it-works-icon" style="background-color: #d1e7dd; color: #198754;">
                <i data-lucide="award" style="width: 40px; height: 40px;"></i>
            </div>
            <h3 class="h5 fw-bold text-dark">3. You Earn 5% in Points</h3>
            <p class="text-muted">You get **5% of their purchase value** in loyalty points, 15 days after their purchase.</p>
        </div>
    </div>
</div>

<!-- 3. Your Dashboard -->
<div class="container my-5">
    <h2 class="text-center fw-bold text-dark mb-5">Your Dashboard</h2>
    
    <!-- Total Points & Referral Code -->
    <div class="points-card p-4 p-md-5 mb-4 text-center">
        <h6 class="text-white-50 text-uppercase">Total Loyalty Points</h6>
        <h1 class="display-3"><?php echo number_format($total_points_balance); ?></h1>
        <hr style="background-color: #444;">
        <p class="text-white-50 mb-1">Your Unique Referral Code (Mobile No.):</p>
        <h4 class="text-white mb-0"><?php echo htmlspecialchars($user_referral_code); ?></h4>
    </div>
    
    <!-- Stats Cards -->
    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card stat-card text-center h-100">
                <div class="card-body p-4">
                    <h6 class="text-muted card-title">Total Referrals</h6>
                    <p class="card-text display-6 fw-bold text-primary"><?php echo $stats['total_leads']; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card text-center h-100">
                <div class="card-body p-4">
                    <h6 class="text-muted card-title">Successful Referrals</h6>
                    <p class="card-text display-6 fw-bold text-success"><?php echo $stats['successful_leads']; ?></p>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card stat-card text-center h-100">
                <div class="card-body p-4">
                    <h6 class="text-muted card-title">Points Pending</h6>
                    <p class="card-text display-6 fw-bold text-warning"><?php echo $stats['points_pending']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Referrals Table -->
    <div class="card shadow-sm stat-card">
        <div class="card-header bg-white">
            <h5 class="mb-0">Your Referral History</h5>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <thead>
                        <tr>
                            <th scope="col">Invitee Name</th>
                            <th scope="col">Date Submitted</th>
                            <th scope="col">Status</th>
                            <th scope="col">Points Status</th>
                            <th scope="col">Award Date</th>
                            <th scope="col">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($leads)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted p-4">
                                    You haven't referred anyone yet. Click "Add New Referral" to start!
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($leads as $lead): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($lead['invitee_name']); ?></td>
                                    <td><?php echo date('d M Y', strtotime($lead['created_at'])); ?></td>
                                    <td><span class="badge bg-<?php echo get_status_badge_class($lead['status']); ?>"><?php echo htmlspecialchars($lead['status']); ?></span></td>
                                    <td>
                                        <?php if ($lead['status'] == 'Points Awarded'): ?>
                                            <span class="text-success fw-bold"><?php echo $lead['points_earned']; ?> Earned</span>
                                        <?php elseif ($lead['status'] == 'Purchased'): ?>
                                            <span class="text-warning"><?php echo $lead['points_earned']; ?> Pending</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($lead['status'] == 'Points Awarded' && $lead['points_awarded_at']): ?>
                                            <?php echo date('d M Y', strtotime($lead['points_awarded_at'])); ?>
                                        <?php elseif ($lead['status'] == 'Purchased'): ?>
                                            <span class="text-muted fst-italic">~ 15 days after purchase</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="<?php echo BASE_URL . 'referral/share.php?id=' . $lead['id']; ?>" class="btn btn-sm btn-outline-secondary">
                                            <i data-lucide="share-2" style="width:14px; height:14px;"></i> Share
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- Note: The copy to clipboard script is in footer.php, but let's add one here for this page's card -->
<script>
    document.addEventListener("DOMContentLoaded", function() {
        // This is for the card at the top of the dashboard
        const copyButton = document.getElementById("copyCodeBtn");
        const codeInput = document.getElementById("referralCodeInput");
        const copyAlert = document.getElementById("copyAlert");

        if (copyButton) {
            copyButton.addEventListener("click", function() {
                codeInput.select();
                codeInput.setSelectionRange(0, 99999); 

                try {
                    navigator.clipboard.writeText(codeInput.value).then(function() {
                        showCopyAlert();
                    }).catch(function(err) {
                        executeOldCopy();
                    });
                } catch (e) {
                    executeOldCopy();
                }
            });
        }
        
        function executeOldCopy() {
            try {
                 var successful = document.execCommand('copy');
                 if (successful) showCopyAlert();
            } catch (err) {
                console.error('Fallback: Oops, unable to copy', err);
            }
        }

        function showCopyAlert() {
             if (copyAlert) {
                copyAlert.classList.remove("d-none");
                setTimeout(function() {
                    copyAlert.classList.add("d-none");
                }, 3000); // Hide after 3 seconds
            }
        }
    });
</script>

<?php
// Includes Bootstrap JS and closes </body></html> tags
require_once 'includes/footer.php'; 
?>