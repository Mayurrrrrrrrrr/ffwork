<?php
// admin/sales_importer.php

// --- DEBUGGING: Force PHP to show errors. ---
error_reporting(E_ALL);
ini_set('display_errors', 1);
// --- END DEBUGGING ---

require_once '../includes/header.php'; // Use '../' to go up one directory

// --- FIX: Explicitly define session variables ---
$user_id = $_SESSION['user_id'] ?? null;
$company_id_context = $_SESSION['company_id'] ?? null;
// --- END FIX ---

// --- SECURITY CHECK: Only Admins can access this ---
if (!has_any_role(['admin', 'platform_admin'])) {
    header("location: " . BASE_URL . "portal_home.php");
    exit;
}

// --- NEW: GET DATA FOR NOTICE BOX ---
$last_upload_time = 'N/A';
$max_transaction_date = 'N/A';

// Query 1: Get last upload time from audit log
$sql_last_upload = "SELECT timestamp FROM audit_log 
                    WHERE action = 'sales_csv_uploaded' AND company_id = ? 
                    ORDER BY timestamp DESC LIMIT 1";
if ($stmt_upload = $conn->prepare($sql_last_upload)) {
    $stmt_upload->bind_param("i", $company_id_context);
    if($stmt_upload->execute()){
        $result_upload = $stmt_upload->get_result();
        if ($row_upload = $result_upload->fetch_assoc()) {
            $last_upload_time = date('d-M-Y g:i A', strtotime($row_upload['timestamp']));
        }
    }
    $stmt_upload->close();
}

// Query 2: Get max transaction date from the data
$sql_max_date = "SELECT MAX(TransactionDate) as max_date FROM sales_reports";
if ($result_max_date = $conn->query($sql_max_date)) {
    if ($row_max_date = $result_max_date->fetch_assoc()) {
        if ($row_max_date['max_date']) {
            $max_transaction_date = date('d-M-Y', strtotime($row_max_date['max_date']));
        }
    }
}
// --- END: GET DATA FOR NOTICE BOX ---


$error_message = '';
$success_message = '';

// --- Business Logic from your rules ---
function getTransactionCalculations($entryType, $grossAmount, $quantity) {
    $entryType = strtoupper(trim($entryType));
    $netSales = 0;
    $netUnits = 0;

    $positive_types = ['FF'];
    $negative_types = ['7DE', '7DR', 'LB', 'LE', 'LU'];
    $ignore_types = ['RR', 'RI'];

    if (in_array($entryType, $positive_types)) {
        $netSales = (float)$grossAmount;
        $netUnits = (int)$quantity;
    } elseif (in_array($entryType, $negative_types)) {
        $netSales = -1 * (float)$grossAmount;
        $netUnits = -1 * (int)$quantity;
    }
    // Ignored types will default to 0 for both

    return ['NetSales' => $netSales, 'NetUnits' => $netUnits];
}

// --- Handle File Upload ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['sales_csv'])) {
    if ($_FILES['sales_csv']['error'] === UPLOAD_ERR_OK) {
        
        $csvFile = $_FILES['sales_csv']['tmp_name'];
        
        $newRows = 0;
        $skippedRows = 0;

        if (($handle = fopen($csvFile, "r")) !== FALSE) {
            
            // Use a transaction for speed
            $conn->begin_transaction();
            
            // Prepare the SQL statement ONCE
            $sql = "INSERT IGNORE INTO sales_reports (
                TransactionNo, TransactionDate, ClientName, ClientMobile, JewelCode, StyleCode, BaseMetal, GrossWt, NetWt, Itemsize, Stocktype,
                SolitairePieces, SolitaireWeight, TotDiaPc, TotDiaWt, ColourStonePieces, ColourStoneWeight, VendorCode, InwardDate,
                ProductCategory, ProductSubcategory, Collection, Occasion, Quantity, Location, SALESEXU, Discount, OriginalSellingPrice,
                Incentive, DiscountPercentage, DiscountAmount, AddlessDiscount, GrossAmountAfterDiscount, Loyalty, GiftCode, GST, FinalAmountWithGST, GrossMargin,
                EntryType, StoreCode, FiscalYear, TransactionID, NetSales, NetUnits
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?
            )";
            
            if($stmt = $conn->prepare($sql)) {

                // Skip the header row
                fgetcsv($handle); 

                // Loop through each data row in the CSV
                while (($data = fgetcsv($handle)) !== FALSE) {
                    
                    if (count($data) < 39) { $skippedRows++; continue; } // Skip malformed rows
                    $transactionNo = $data[23];
                    if (empty($transactionNo)) { $skippedRows++; continue; }
                    
                    $grossAmount = (float)str_replace(',', '', $data[33]);
                    $quantity = (int)$data[22];

                    // --- Parse TransactionNo ---
                    $parts = explode('/', $transactionNo);
                    $entryType = $parts[0] ?? null;
                    $storeCode = $parts[1] ?? null;
                    $fiscalYear = $parts[2] ?? null;
                    $transactionID = $parts[3] ?? null;

                    // --- Apply Business Logic ---
                    $calcs = getTransactionCalculations($entryType, $grossAmount, $quantity);

                    // --- Format Dates for SQL (handles 'dd-mm-yyyy') ---
                    $transactionDate = !empty($data[24]) ? date('Y-m-d', strtotime($data[24])) : null;
                    $inwardDate = !empty($data[17]) ? date('Y-m-d', strtotime($data[17])) : null;
                    
                    // Bind all 44 parameters
                    $stmt->bind_param("sssssssddssidididssssssissddsddddssdddssssdi",
                        $data[23], // TransactionNo
                        $transactionDate,
                        $data[1],  // ClientName
                        $data[2],  // ClientMobile
                        $data[3],  // JewelCode
                        $data[4],  // StyleCode
                        $data[5],  // BaseMetal
                        $data[6],  // GrossWt
                        $data[7],  // NetWt
                        $data[8],  // Itemsize
                        $data[9],  // Stocktype
                        $data[10], // SolitairePieces
                        $data[11], // SolitaireWeight
                        $data[12], // TotDiaPc
                        $data[13], // TotDiaWt
                        $data[14], // ColourStonePieces
                        $data[15], // ColourStoneWeight
                        $data[16], // VendorCode
                        $inwardDate, // (Var)
                        $data[18], // ProductCategory
                        $data[19], // ProductSubcategory
                        $data[20], // Collection
                        $data[21], // Occasion
                        $data[22], // Quantity
                        $data[25], // Location
                        $data[26], // SALESEXU
                        $data[27], // Discount
                        $data[28], // OriginalSellingPrice
                        $data[29], // Incentive
                        $data[30], // DiscountPercentage
                        $data[31], // DiscountAmount
                        $data[32], // AddlessDiscount
                        $data[33], // GrossAmountAfterDiscount
                        $data[34], // Loyalty
                        $data[35], // GiftCode
                        $data[36], // GST
                        $data[37], // FinalAmountWithGST
                        $data[38], // GrossMargin
                        $entryType,
                        $storeCode,
                        $fiscalYear,
                        $transactionID,
                        $calcs['NetSales'],
                        $calcs['NetUnits']
                    );

                    if ($stmt->execute()) {
                        if ($stmt->affected_rows > 0) {
                            $newRows++;
                        } else {
                            $skippedRows++;
                        }
                    } else {
                        $error_message = "Error during insert: " . $stmt->error;
                        break; // Stop on error
                    }
                }
                
                if (empty($error_message)) {
                    $conn->commit();
                    $success_message = "Upload Complete. <strong>$newRows new transactions added.</strong> <strong>$skippedRows duplicate/empty rows were ignored.</strong>";
                    
                    // --- NEW: Log this upload action ---
                    log_audit_action($conn, 'sales_csv_uploaded', "$newRows rows added, $skippedRows skipped", $user_id, $company_id_context, 'sales_importer', null);

                    // --- NEW: Refresh the notice data after successful upload ---
                    $last_upload_time = date('d-M-Y g:i A');
                    $sql_max_date = "SELECT MAX(TransactionDate) as max_date FROM sales_reports";
                    if ($result_max_date = $conn->query($sql_max_date)) {
                        if ($row_max_date = $result_max_date->fetch_assoc()) {
                            if ($row_max_date['max_date']) {
                                $max_transaction_date = date('d-M-Y', strtotime($row_max_date['max_date']));
                            }
                        }
                    }

                } else {
                    $conn->rollback();
                }

                fclose($handle);
                $stmt->close();

            } else {
                $error_message = "Database prepare error: " . $conn->error;
            }

        } else { $error_message = "Could not open the uploaded file."; }
    } else { $error_message = "File upload error. Code: " . $_FILES['sales_csv']['error']; }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Sales Report Importer</h2>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div><?php endif; ?>

<div class="alert alert-info" role="alert">
    <h5 class="alert-heading"><i data-lucide="info" class="me-2" style="width: 20px; height: 20px; vertical-align: -4px;"></i>Data Status</h5>
    <p class="mb-1">
        <strong>Last Upload Run:</strong> <?php echo $last_upload_time; ?>
    </p>
    <p class="mb-0">
        <strong>Data is uploaded until (Max Transaction Date):</strong> <?php echo $max_transaction_date; ?>
    </p>
</div>
<div class="card">
    <div class="card-header">
        <h4>Upload New Sales Report (CSV)</h4>
    </div>
    <div class="card-body">
        <p>This tool will upload the CSV, process the data, and add **only new transactions** to the database. Duplicates based on 'TransactionNo' will be automatically ignored.</p>
        
        <form action="admin/sales_importer.php" method="post" enctype="multipart/form-data">
            <div class="mb-3">
                <label for="sales_csv" class="form-label">Sales Report CSV File</label>
                <input class="form-control" type="file" id="sales_csv" name="sales_csv" accept=".csv" required>
            </div>
            <button type="submit" class="btn btn-primary btn-lg">
                <i data-lucide="upload" class="me-2"></i>Upload and Process File
            </button>
        </form>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>