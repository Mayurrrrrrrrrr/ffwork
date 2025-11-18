<?php
// Path: D:\xampp\htdocs\Companyportal\referral\share.php

require_once 'init.php'; 

if (!isset($_SESSION["referrer_loggedin"]) || $_SESSION["referrer_loggedin"] !== true) {
    header("location: ". BASE_URL . "referral/login.php");
    exit;
}

if (!isset($_GET['id']) || empty(trim($_GET['id']))) {
    header("location: ". BASE_URL . "referral/dashboard.php");
    exit;
}
$referral_id = (int)$_GET['id'];
$referrer_id = $_SESSION['referrer_id'];

$sql = "SELECT r.invitee_name, r.referral_code, r.invitee_contact, ref.full_name AS referrer_name 
        FROM referrals r 
        JOIN referrers ref ON r.referrer_id = ref.id 
        WHERE r.id = ? AND r.referrer_id = ?";
        
$invitee_name = $referral_code = $referrer_name = $invitee_contact = "";

if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("ii", $referral_id, $referrer_id);
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        if ($result->num_rows == 1) {
            $row = $result->fetch_assoc();
            $invitee_name = $row['invitee_name'];
            $referral_code = $row['referral_code'];
            $referrer_name = $row['referrer_name'];
            $invitee_contact = $row['invitee_contact'];
        } else {
            header("location: ". BASE_URL . "referral/dashboard.php");
            exit;
        }
    }
    $stmt->close();
} else {
    die("Database error. Please try again.");
}

// --- UPDATED SHARE MESSAGE ---
$share_message_template = "As a fan of Firefly diamonds, I am referring Mr/Ms %s to Firefly Diamonds. Show this code to get Rs 1000 OFF your first purchase! Code: %s";
$share_message = sprintf($share_message_template, $invitee_name, $referral_code);
$page_url = BASE_URL . "referral/share.php?id=" . $referral_id;
$generic_whatsapp_link = "https://wa.me/?text=" . urlencode($share_message . "\n" . $page_url);

$page_title = "Share Your Referral";
require_once 'includes/header.php'; 
?>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<style>
    .referral-card {
        background: linear-gradient(135deg, #fdfbfb 0%, #ebedee 100%);
        border: 1px solid #ddd;
        border-radius: 15px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.07);
        overflow: hidden;
        max-width: 500px;
        margin: auto;
    }
    .referral-header {
        background-color: #343a40;
        padding: 2rem;
        text-align: center;
    }
    .referral-header img {
        height: 40px;
    }
    .referral-body {
        padding: 2.5rem;
        text-align: center;
    }
    /* --- NEW: Style for the benefit text --- */
    .benefit-text {
        font-size: 1.75rem;
        font-weight: 600;
        color: #198754; /* Bootstrap success green */
    }
    .referral-code-box {
        font-size: 2.5rem;
        font-weight: bold;
        color: #004E54;
        background: #f1f3f5;
        border: 2px dashed #004E54;
        border-radius: 10px;
        padding: 1.5rem;
        letter-spacing: 2px;
        margin-top: 1.5rem;
    }
    .share-buttons .btn {
        width: 100%;
        font-size: 1.1rem;
        padding: 0.75rem;
    }
</style>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-10">

        <?php if (isset($_GET['new']) && $_GET['new'] == 1): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i data-lucide="check-circle" class="me-2"></i>
                <strong>Success!</strong> Your referral for <strong><?php echo htmlspecialchars($invitee_name); ?></strong> has been created.
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <div class="referral-card mb-4" id="referralCard">
            <div class="referral-header">
                <img src="<?php echo BASE_URL; ?>assets/logo.png" alt="Logo">
            </div>
            <div class="referral-body">
                <h2 class="h5 text-dark">A Special Referral For You!</h2>
                <p class="lead">
                    Your friend, <strong><?php echo htmlspecialchars($referrer_name); ?></strong>, has referred you to Firefly Diamonds.
                </p>
                
                <p class="text-muted mb-2">Show this card at the store to get:</p>
                <div class="benefit-text">
                    Rs 1000 OFF
                </div>
                <p class="text-muted mt-0">your first purchase!</p>
                <p class="text-muted small">Please use the one-time code below:</p>
                <div class="referral-code-box">
                    <?php echo htmlspecialchars($referral_code); ?>
                </div>
            </div>
        </div>

        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h4 class="text-center mb-3">Share this Card</h4>
                <div class="d-grid gap-3 share-buttons">
                    
                    <button class="btn btn-success btn-lg" id="shareImageButton">
                        <i data-lucide="image" class="me-2"></i> 
                        <span id="shareImageText">Generate & Share Image</span>
                    </button>
                    
                    <a href="<?php echo $generic_whatsapp_link; ?>" target="_blank" class="btn btn-secondary">
                        <i data-lucide="share-2" class="me-2"></i> Share as Text
                    </a>
                    <button class="btn btn-outline-dark" id="copyButton">
                        <i data-lucide="copy" class="me-2"></i> <span id="copyButtonText">Copy Share Link</span>
                    </button>
                </div>
                <div id="copyAlert" class="alert alert-info mt-3 d-none" role="alert">
                    Link copied to clipboard!
                </div>
            </div>
        </div>
        
        <div class="text-center mt-3">
             <a href="<?php echo BASE_URL; ?>referral/dashboard.php"><i data-lucide="arrow-left" class="me-1" style="width:16px;"></i> Back to Dashboard</a>
        </div>

    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const copyButton = document.getElementById("copyButton");
        const copyButtonText = document.getElementById("copyButtonText");
        const copyAlert = document.getElementById("copyAlert");
        const shareLink = "<?php echo $page_url; ?>";

        if (copyButton) {
            copyButton.addEventListener("click", function() {
                navigator.clipboard.writeText(shareLink).then(function() {
                    copyButtonText.innerText = "Copied!";
                    copyAlert.classList.remove("d-none");
                    setTimeout(() => {
                        copyButtonText.innerText = "Copy Share Link";
                        copyAlert.classList.add("d-none");
                    }, 3000);
                }).catch(err => {
                    copyButtonText.innerText = "Error Copying";
                });
            });
        }
        
        const shareImageButton = document.getElementById("shareImageButton");
        const shareImageText = document.getElementById("shareImageText");
        const cardToCapture = document.getElementById("referralCard");

        if (shareImageButton && cardToCapture && navigator.share) {
            shareImageButton.addEventListener("click", async () => {
                shareImageText.innerText = "Generating...";
                
                try {
                    const canvas = await html2canvas(cardToCapture, { useCORS: true, scale: 2 });
                    
                    canvas.toBlob(async (blob) => {
                        const file = new File([blob], "referral-card.png", { type: "image/png" });
                        
                        await navigator.share({
                            title: "Firefly Referral",
                            text: "<?php echo addslashes($share_message); ?>",
                            files: [file]
                        });
                        
                        shareImageText.innerText = "Generate & Share Image";
                    }, "image/png");

                } catch (err) {
                    console.error("Share failed:", err);
                    shareImageText.innerText = "Share Failed, Try Text";
                    setTimeout(() => {
                        shareImageText.innerText = "Generate & Share Image";
                    }, 3000);
                }
            });
        } else if (shareImageButton) {
            shareImageButton.addEventListener("click", async () => {
                shareImageText.innerText = "Generating...";
                const canvas = await html2canvas(cardToCapture, { useCORS: true, scale: 2 });
                const link = document.createElement('a');
                link.download = 'firefly-referral.png';
                link.href = canvas.toDataURL("image/png");
                link.click();
                shareImageText.innerText = "Image Downloaded!";
                setTimeout(() => {
                    shareImageText.innerText = "Generate & Share Image";
                }, 3000);
            });
        }
    });
</script>

<?php
require_once 'includes/footer.php'; 
?>