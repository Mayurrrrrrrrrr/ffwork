<?php
// Use the new Tools header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id
?>

<!-- Custom styles from your original certificategenerator.html to preserve the look -->
<style>
    /* Custom font for simulation */
    @font-face {
        font-family: 'EdwardianScript';
        src: url('https://ffchampions.infinityfreeapp.com/assets/edwardian.eot');
        src: url('https://ffchampions.infinityfreeapp.com/assets/edwardian.eot?#iefix') format('embedded-opentype'),
             url('https://ffchampions.infinityfreeapp.com/assets/edwardian.woff2') format('woff2'),
             url('https://ffchampions.infinityfreeapp.com/assets/edwardian.woff') format('woff'),
             url('https://ffchampions.infinityfreeapp.com/assets/edwardian.ttf') format('truetype');
        font-weight: normal;
        font-style: normal;
    }
    
    :root {
        --firefly-dark-green: #015753;
        --bg-color: #F8F8F8;
    }
    
    .font-playfair { font-family: 'Playfair Display', serif; }
    .font-montserrat { font-family: 'Montserrat', sans-serif; }
    .font-edwardian { font-family: 'EdwardianScript', cursive; }

    .certificate-page {
        max-width: 800px;
        min-height: 565px; /* A4 aspect ratio */
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        background-color: white;
    }
    
    /* Print-specific styles */
    @media print {
        body, html {
            margin: 0;
            padding: 0;
            background: #fff;
        }
        .no-print {
            display: none;
        }
        .certificate-page {
            box-shadow: none;
            margin: 0;
            width: 100%;
            height: 100vh;
            border: 1px solid var(--firefly-dark-green); /* Add a border for printing */
        }
        .printable-content {
            -webkit-print-color-adjust: exact; /* Ensure colors print */
            color-adjust: exact;
        }
    }
</style>

<!-- Control Panel (No-Print) -->
<div class="card mb-4 no-print">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label for="customerName" class="form-label">Customer Name</label>
                <input type="text" id="customerName" class="form-control" placeholder="e.g., Mr. John Doe">
            </div>
            <div class="col-md-6">
                <label for="jewelCode" class="form-label">Jewel Code</label>
                <input type="text" id="jewelCode" class="form-control" placeholder="e.g., F00001">
            </div>
            <div class="col-md-6">
                <label for="certNumber" class="form-label">Certificate Number</label>
                <input type="text" id="certNumber" class="form-control" placeholder="e.g., 49J6979924">
            </div>
            <div class="col-md-6">
                <label for="issueDate" class="form-label">Date of Issue</label>
                <input type="date" id="issueDate" class="form-control" value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-12 d-grid">
                <button id="print-btn" class="btn btn-primary"><i data-lucide="printer" class="me-2"></i>Print to PDF</button>
            </div>
        </div>
    </div>
</div>

<!-- Certificate Preview -->
<div class="certificate-page p-4 mx-auto">
    <!-- Inner border -->
    <div class="border-4 border-gray-300 h-full p-4 printable-content">
        <!-- Main content area -->
        <div class="border-2 border-gray-400 h-full p-6 text-center flex flex-col justify-between items-center">
            
            <!-- Header Section -->
            <div class="w-full">
                <div class="flex justify-between items-start">
                    <img src="https://ffchampions.infinityfreeapp.com/assets/bis.png" alt="BIS Logo" class="h-20">
                    <img src="https://ffchampions.infinityfreeapp.com/assets/igi.png" alt="IGI Logo" class="h-16">
                </div>
                <div class="text-center mt-4">
                    <h1 class="font-playfair text-4xl font-bold italic" style="color: var(--firefly-dark-green);">CERTIFICATE OF AUTHENTICITY</h1>
                    <p class="font-montserrat text-sm tracking-widest text-gray-500">LABORATORY GROWN DIAMOND JEWELLERY</p>
                </div>
            </div>
            
            <!-- Main Content Section -->
            <div class="w-full text-center my-8">
                <p class="font-montserrat text-lg text-gray-600 mb-4">This is to certify that the jewellery described below is authentic<br>and has been manufactured by Firefly Diamonds.</p>
                
                <div class_content="my-6">
                    <h2 id="customer-name-display" class="font-edwardian text-6xl text-gray-800">Mr. John Doe</h2>
                    <p class="text-lg text-gray-500 font-medium">is the owner of the following jewellery article:</p>
                </div>

                <div class="mt-8 text-lg text-gray-700">
                    <p class="font-medium">Jewel Code: <strong id="jewel-code-display" class="font-bold">F00001</strong></sppan>
                    <p class="font-medium">Certificate No: <strong id="cert-number-display" class="font-bold">49J6979924</strong></p>
                </div>
            </div>

            <!-- Signature & Stamp Section -->
            <div class="w-full flex justify-between items-end">
                <div class="text-left">
                    <p class="font-montserrat text-sm text-gray-500">Date of Issue:</p>
                    <p id="date-display" class="font-montserrat text-lg font-bold text-gray-700">October 25, 2025</p>
                </div>
                
                <div class="text-center">
                    <!-- Signature Container -->
                    <div class="d-inline-block position-relative mb-2">
                        <!-- Tilted Stamp -->
                        <div class="position-relative" style="width: 8rem; height: 8rem; margin: 0 auto;">
                            <img src="https://ffchampions.infinityfreeapp.com/assets/tiltedstamp.png" alt="Official Stamp" 
                                 class="w-100 h-auto" 
                                 style="opacity: 0.7; transform: rotate(-10deg);">
                        </div>
                        <!-- Signature (positioned over the stamp) -->
                        <div class="position-absolute top-50 start-50 translate-middle">
                             <img src="https://ffchampions.infinityfreeapp.com/assets/aayushsign.png" alt="Aayush Bhansali Signature" 
                                 class="object-contain" style="width: 12rem; max-height: 80px;">
                        </div>
                    </div>
                    
                    <!-- Name and Title -->
                    <p class_name="text-xl font-bold text-gray-700 tracking-wider font-medium pt-2">AAYUSH BHANSALI</p>
                    <p class="text-sm text-gray-500 font-medium">Authorized Signatory, Firefly Diamonds</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function updateCertificate() {
        // Get values from inputs
        const customerName = document.getElementById('customerName').value || "Customer Name";
        const jewelCode = document.getElementById('jewelCode').value || "F0000X";
        const certNumber = document.getElementById('certNumber').value || "XXXXXXXXX";
        const issueDate = document.getElementById('issueDate').value;
        
        // Format the date
        const dateObj = new Date(issueDate + 'T00:00:00'); // Ensure it's treated as local date
        const formattedDate = dateObj.toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });

        // Update the display fields
        document.getElementById('customer-name-display').textContent = customerName;
        document.getElementById('jewel-code-display').textContent = jewelCode;
        document.getElementById('cert-number-display').textContent = certNumber;
        document.getElementById('date-display').textContent = formattedDate;
    }

    // Add listeners to all input fields
    document.getElementById('customerName').addEventListener('input', updateCertificate);
    document.getElementById('jewelCode').addEventListener('input', updateCertificate);
    document.getElementById('certNumber').addEventListener('input', updateCertificate);
    document.getElementById('issueDate').addEventListener('input', updateCertificate);

    // Print button
    document.getElementById('print-btn').addEventListener('click', () => {
        updateCertificate(); // Ensure data is up-to-date
        window.print();
    });

    // Initial call to set the date
    document.addEventListener('DOMContentLoaded', () => {
        updateCertificate();
    });
</script>

<?php
if(isset($conn)) $conn->close();
require_once '../includes/footer.php';
?>
