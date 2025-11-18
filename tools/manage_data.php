<?php
// Use the new Tools header
require_once '../includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id, $is_data_admin

// -- SECURITY CHECK: ENSURE USER IS A DATA ADMIN --
/*
if (!$is_data_admin) {
    header("location: " . BASE_URL . "tools/index.php");
    exit;
}
*/
// --- PHP WARNING FIX & DB FIX: Ensure these variables are defined for safe logging ---
// Changing default from 0 to NULL to satisfy the Foreign Key constraint for company_id and user_id.
$user_id = $user_id ?? NULL;
$company_id_context = $company_id_context ?? NULL;
// --------------------------------------------------------------------------

$error_message = '';
$success_message = '';

// --- Helper function for Sales Importer ---
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


// --- ACTION 1: Handle Data File Upload (JSON or CSV) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['data_file'])) {
    $file_type = $_POST['file_type'];
    $file_tmp_path = $_FILES['data_file']['tmp_name'];
    $file_error = $_FILES['data_file']['error'];
    $file_name = $_FILES['data_file']['name'];
    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    
    $target_table = '';
    $data_model_db = []; // Database column names
    $data_model_json = []; // Corresponding JSON keys (with spaces)
    $types = ""; // To store bind_param types
    $is_json_upload = false; // Flag to differentiate logic

    if ($file_error !== UPLOAD_ERR_OK) {
        $error_message = "Error uploading file (Code: {$file_error}).";
    } else {
        // 1. Determine which table to update and map JSON keys to DB columns
        switch($file_type) {
            case 'stock':
            case 'opening_stock':
                if ($file_extension != 'json') {
                    $error_message = "Invalid file type. Stock data requires a .json file.";
                    break;
                }
                $is_json_upload = true;
                $target_table = ($file_type == 'stock') ? 'stock_data' : 'stock_snapshots';
                
                // Database columns (17 fields) - Added "SubCategory"
                $data_model_db = ["JewelCode", "LocationName", "Category", "StyleCode", "ItemSize", "SubCategory", "BaseMetal", "JewelryCertificateNo", "Qty", "GrossWt", "NetWt", "DiaPcs", "DiaWt", "CsPcs", "CsWt", "PureWt", "SalePrice"];
                // Keys from FFSTOCK (1).json (17 fields) - Added "Sub Category"
                $data_model_json = ["Jewel Code", "Location Name", "Category", "Style Code", "Item Size", "Sub Category", "Base Metal", "Jewelry CertificateNo", "Qty", "Gross Wt", "Net Wt", "Dia Pcs", "Dia Wt", "CS Pcs", "CS Wt", "Pure Wt", "Sale Price"];
                
                // Base types (18 chars: i(company_id) + 17 data fields) - Added 's' for SubCategory
                $types = "issssssssidiididds"; // Original was 17 chars for 16 fields
                
                if ($file_type == 'opening_stock') {
                    // Add snapshot_month field
                    // i(company_id), s(snapshot_month), s(JewelCode), s(LocationName), ...
                    $types = "iss" . substr($types, 1); // Add 's' for snapshot_month after company_id
                    // $types is now 19 chars: i, s, + 17 data fields
                }
                break;
            case 'cost':
                if ($file_extension != 'json') {
                    $error_message = "Invalid file type. Cost data requires a .json file.";
                    break;
                }
                $is_json_upload = true;
                $target_table = 'cost_data';
                $data_model_db = ["JewelCode", "Style", "Category", "GrossWt", "TotDiaPc", "TotDiaWt", "CostPrice", "SalePrice"];
                // Keys from cost.json (assuming structure)
                $data_model_json = ["JewelCode", "Style", "Category", "GrossWt", "TotDiaPc", "TotDiaWt", "CostPrice", "SalePrice"]; // Adjust if needed for cost.json
                // i(company_id), s(JewelCode), s(Style), s(Category), d(GrossWt), i(TotDiaPc), d(TotDiaWt), d(CostPrice), d(SalePrice)
                $types = "isssdiddd"; // 9 chars for 9 fields (company_id + 8)
                break;
            case 'sales':
                if ($file_extension != 'csv') {
                    $error_message = "Invalid file type. Sales data requires a .csv file.";
                    break;
                }
                // Sales logic is completely different, will be handled below.
                break;
            case 'collection':
                if ($file_extension != 'csv') {
                    $error_message = "Invalid file type. Collection Master requires a .csv file.";
                    break;
                }
                // Collection Master logic will be handled below.
                break;
            default:
                $error_message = "Invalid file type selected.";
        }
    }

    if(empty($error_message) && $is_json_upload){
        // --- THIS IS THE JSON (Stock/Cost/Opening Stock) UPLOAD LOGIC ---
        $json_content = file_get_contents($file_tmp_path);
        $data = json_decode($json_content, true);
        
        // ** MODIFICATION START: Pre-scan file for months if it's 'opening_stock' **
        $months_in_file = [];
        if ($file_type == 'opening_stock') {
            if($data === null || !is_array($data)){
                $error_message = "Failed to decode JSON file. Ensure it is a valid JSON array.";
            } else {
                foreach ($data as $item) {
                    if (isset($item['Date']) && !empty($item['Date'])) {
                        // Standardize the date to the 1st of the month
                        $month = date('Y-m-01', strtotime($item['Date']));
                        $months_in_file[$month] = true; // Use keys for automatic uniqueness
                    }
                }
                if (empty($months_in_file)) {
                    $error_message = "No valid 'Date' fields found in the opening stock file. Cannot determine which months to update.";
                }
            }
        }
        // ** MODIFICATION END **

        if($data === null || !is_array($data)){
            $error_message = "Failed to decode JSON file. Ensure it is a valid JSON array.";
        } 
        elseif(empty($error_message)) { // Continue only if no errors (like no months found)
            $conn->begin_transaction();
            try {
                // 1. Clear existing data
                if ($file_type == 'opening_stock') {
                    // ** MODIFICATION START: Delete for all months in the file **
                    $months_to_delete = array_keys($months_in_file);
                    
                    if (!empty($months_to_delete)) {
                        $placeholders_delete = implode(',', array_fill(0, count($months_to_delete), '?'));
                        $types_delete = "i" . str_repeat('s', count($months_to_delete));
                        
                        $sql_delete = "DELETE FROM {$target_table} WHERE company_id = ? AND snapshot_month IN ({$placeholders_delete})";
                        $stmt_del = $conn->prepare($sql_delete);
                        
                        if (!$stmt_del) {
                            throw new Exception("Failed to prepare delete statement: " . $conn->error);
                        }
                        
                        $stmt_del->bind_param($types_delete, $company_id_context, ...$months_to_delete);
                        
                        if (!$stmt_del->execute()) {
                            throw new Exception("Failed to clear existing data: " . $stmt_del->error);
                        }
                        $stmt_del->close();
                    }
                    // If $months_to_delete is empty, we simply don't delete anything
                    // ** MODIFICATION END **
                } else {
                    // Original logic for 'stock' and 'cost'
                    $sql_delete = "DELETE FROM {$target_table} WHERE company_id = ?";
                    $stmt_del = $conn->prepare($sql_delete);
                    $stmt_del->bind_param("i", $company_id_context);
                    if (!$stmt_del->execute()) {
                        throw new Exception("Failed to clear existing data: " . $stmt_del->error);
                    }
                    $stmt_del->close();
                }

                // 2. Prepare the new insert query
                $fields_db = implode(", ", $data_model_db);
                $placeholders = implode(',', array_fill(0, count($data_model_db), '?'));
                
                if ($file_type == 'opening_stock') {
                    $sql_insert = "INSERT INTO {$target_table} (company_id, snapshot_month, {$fields_db}) VALUES (?, ?, {$placeholders})";
                } else {
                    $sql_insert = "INSERT INTO {$target_table} (company_id, {$fields_db}) VALUES (?, {$placeholders})";
                }
                
                $stmt_insert = $conn->prepare($sql_insert);
                if (!$stmt_insert) {
                    throw new Exception("Failed to prepare insert statement: " . $conn->error);
                }

                $success_count = 0;
                $skipped_count = 0;

                // 3. Loop and insert each item
                foreach($data as $item_index => $item){
                    
                    $params = [$company_id_context];
                    
                    // ** MODIFICATION START: Get snapshot_month from item 'Date' **
                    if ($file_type == 'opening_stock') {
                        $item_date = $item['Date'] ?? null;
                        if (empty($item_date)) {
                            // Skip this row if it's missing the date
                            error_log("Skipping item {$item_index} for opening stock: Missing 'Date' field.");
                            $skipped_count++;
                            continue; // Skip to the next item
                        }
                        $item_snapshot_month = date('Y-m-01', strtotime($item_date));
                        $params[] = $item_snapshot_month;
                    }
                    // ** MODIFICATION END **
                    
                    foreach($data_model_json as $json_key){
                        $value = $item[$json_key] ?? null;

                        // Data Cleaning/Type Conversion
                        if ($value !== null) {
                            $db_key_index = array_search($json_key, $data_model_json);
                            $db_key = $data_model_db[$db_key_index]; // Get DB key name
                            
                            // Adjust type index for opening_stock (it has an extra 's' at the start)
                            $type_index = ($file_type == 'opening_stock') ? $db_key_index + 2 : $db_key_index + 1;
                            $param_type = $types[$type_index];

                            // Clean price/weight fields (remove commas)
                            if ($param_type == 'd' || $db_key == 'SalePrice') { // Clean SalePrice even if it's string
                                $value = str_replace(',', '', $value);
                            }
                            
                            // Convert empty strings to NULL for non-string types
                            if (($param_type == 'i' || $param_type == 'd') && $value === '') {
                                $value = null;
                            }
                        }
                        $params[] = $value;
                    }

                    // Check if number of params matches types string length
                    if (count($params) != strlen($types)) {
                        // Log this error but continue processing other items
                        error_log("Parameter count mismatch for item {$item_index}. Expected " . strlen($types) . ", got " . count($params) . " | Data: " . json_encode($item));
                        $skipped_count++;
                        continue; 
                    }

                    $stmt_insert->bind_param($types, ...$params);
                    if (!$stmt_insert->execute()) {
                        // Log specific error and problematic item
                        error_log("Failed to insert item {$item_index} for table {$target_table}: " . $stmt_insert->error . " | Data: " . json_encode($item));
                        $skipped_count++; // Count as skipped
                    } else {
                        $success_count++;
                    }
                }

                $stmt_insert->close();
                $conn->commit();
                $success_message = "Successfully imported {$success_count} records into {$target_table}.";
                if ($skipped_count > 0) {
                    $success_message .= " {$skipped_count} records were skipped due to errors (e.g., missing 'Date' or data mismatch). Check server logs for details.";
                }
                // $user_id and $company_id_context are now guaranteed to be defined (or NULL)
                log_audit_action($conn, 'data_upload', "Uploaded {$success_count} records to {$target_table} (skipped {$skipped_count})", $user_id, $company_id_context, $target_table, $success_count);

            } catch (Exception $e) {
                $conn->rollback();
                $error_message = "Transaction failed: " . $e->getMessage();
                 // Ensure statement is closed if it exists before rollback attempt
                 if (isset($stmt_insert) && $stmt_insert instanceof mysqli_stmt) {
                     $stmt_insert->close();
                 }
            }
        }
    } 
    elseif (empty($error_message) && $file_type == 'sales') {
        // --- THIS IS THE CSV (Sales) UPLOAD LOGIC ---
        
        $newRows = 0;
        $updatedRows = 0;
        $skippedMalformedRows = 0;

        if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
            
            $conn->begin_transaction();
            
            // --- UPDATED SQL FOR NEW COLUMNS: FreeGold, GSTNO, PANNO ---
            $sql = "INSERT INTO sales_reports (
                TransactionNo, TransactionDate, ClientName, ClientMobile, JewelCode, StyleCode, BaseMetal, GrossWt, NetWt, Itemsize, Stocktype,
                FreeGold,  -- ADDED COLUMN
                SolitairePieces, SolitaireWeight, TotDiaPc, TotDiaWt, ColourStonePieces, ColourStoneWeight, VendorCode, GSTNO, InwardDate, PANNO, -- GSTNO & PANNO ADDED
                ProductCategory, ProductSubcategory, Collection, Occasion, Quantity, Location, SALESEXU, Discount, OriginalSellingPrice,
                Incentive, DiscountPercentage, DiscountAmount, AddlessDiscount, GrossAmountAfterDiscount, Loyalty, GiftCode, GST, FinalAmountWithGST, GrossMargin,
                EntryType, StoreCode, FiscalYear, TransactionID, NetSales, NetUnits
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, ?, ?
            ) ON DUPLICATE KEY UPDATE
                TransactionDate = VALUES(TransactionDate), ClientName = VALUES(ClientName), ClientMobile = VALUES(ClientMobile), JewelCode = VALUES(JewelCode),
                StyleCode = VALUES(StyleCode), BaseMetal = VALUES(BaseMetal), GrossWt = VALUES(GrossWt), NetWt = VALUES(NetWt), Itemsize = VALUES(Itemsize),
                Stocktype = VALUES(Stocktype),
                FreeGold = VALUES(FreeGold), -- ADDED UPDATE
                SolitairePieces = VALUES(SolitairePieces), SolitaireWeight = VALUES(SolitaireWeight), TotDiaPc = VALUES(TotDiaPc),
                TotDiaWt = VALUES(TotDiaWt), ColourStonePieces = VALUES(ColourStonePieces), ColourStoneWeight = VALUES(ColourStoneWeight), 
                VendorCode = VALUES(VendorCode),
                GSTNO = VALUES(GSTNO), -- ADDED UPDATE
                InwardDate = VALUES(InwardDate),
                PANNO = VALUES(PANNO), -- ADDED UPDATE
                ProductCategory = VALUES(ProductCategory), ProductSubcategory = VALUES(ProductSubcategory), Collection = VALUES(Collection),
                Occasion = VALUES(Occasion), Quantity = VALUES(Quantity), Location = VALUES(Location), SALESEXU = VALUES(SALESEXU), Discount = VALUES(Discount),
                OriginalSellingPrice = VALUES(OriginalSellingPrice), Incentive = VALUES(Incentive), DiscountPercentage = VALUES(DiscountPercentage),
                DiscountAmount = VALUES(DiscountAmount), AddlessDiscount = VALUES(AddlessDiscount), GrossAmountAfterDiscount = VALUES(GrossAmountAfterDiscount),
                Loyalty = VALUES(Loyalty), GiftCode = VALUES(GiftCode), GST = VALUES(GST), FinalAmountWithGST = VALUES(FinalAmountWithGST), GrossMargin = VALUES(GrossMargin),
                EntryType = VALUES(EntryType), StoreCode = VALUES(StoreCode), FiscalYear = VALUES(FiscalYear), TransactionID = VALUES(TransactionID),
                NetSales = VALUES(NetSales), NetUnits = VALUES(NetUnits)";
            // --- END UPDATED SQL ---
            
            if($stmt = $conn->prepare($sql)) {

                // Skip the header row
                fgetcsv($handle); 

                // Loop through each data row in the CSV
                while (($data = fgetcsv($handle)) !== FALSE) {
                    
                    // Column Index Fix: Check if there are enough columns (42 columns in the header)
                    if (count($data) < 42) { $skippedMalformedRows++; continue; } 
                    
                    // --- Get critical fields using their correct 0-based index ---
                    $transactionNo = $data[26];
                    $grossAmount = (float)str_replace(',', '', $data[36]);
                    $quantity = (int)$data[25];

                    // Keep this check for empty transaction numbers
                    if (empty($transactionNo)) { $skippedMalformedRows++; continue; } 

                    // --- Parse TransactionNo ---
                    $parts = explode('/', $transactionNo);
                    
                    // This correctly skips rows from the CSV that are malformed
                    if (count($parts) < 4) {
                        $skippedMalformedRows++;
                        continue; // Skip this row, it's malformed
                    }

                    $entryType = $parts[0];
                    $storeCode = $parts[1];
                    $fiscalYear = $parts[2];
                    $transactionID = $parts[3];

                    // --- Apply Business Logic ---
                    $calcs = getTransactionCalculations($entryType, $grossAmount, $quantity);

                    // --- Format Dates for SQL (handles 'dd-mm-yyyy') ---
                    // Transaction Date is at index 27
                    $transactionDate = !empty($data[27]) ? date('Y-m-d', strtotime($data[27])) : null;
                    // Inward Date is at index 19
                    $inwardDate = !empty($data[19]) ? date('Y-m-d', strtotime($data[19])) : null;
                    
                    // --- UPDATED BIND_PARAM: 47 total parameters (42 CSV + 5 Calculated) ---
                    // Type string is 47 characters: sssssssddsdidididsssissddsddddssdddssssdi
                    $stmt->bind_param("sssssssddsdidididsssissddsddddssdddssssdi",
                        $data[26], // 1. TransactionNo (s, Index 26)
                        $transactionDate, // 2. TransactionDate (s)
                        $data[1],  // 3. ClientName (s)
                        $data[2],  // 4. ClientMobile (s)
                        $data[3],  // 5. JewelCode (s)
                        $data[4],  // 6. StyleCode (s)
                        $data[5],  // 7. BaseMetal (s)
                        $data[6],  // 8. GrossWt (d)
                        $data[7],  // 9. NetWt (d)
                        $data[8],  // 10. Itemsize (s)
                        $data[9],  // 11. Stocktype (s)
                        $data[10], // 12. FreeGold (d) **NEW**
                        $data[11], // 13. Solitaire Pieces (i)
                        $data[12], // 14. Solitaire Weight (d)
                        $data[13], // 15. TotDiaPc (i)
                        $data[14], // 16. TotDiaWt (d)
                        $data[15], // 17. ColourStone Pieces (i)
                        $data[16], // 18. ColourStone Weight (d)
                        $data[17], // 19. VendorCode (s)
                        $data[18], // 20. GSTNO (s) **NEW**
                        $inwardDate, // 21. InwardDate (s)
                        $data[20], // 22. PANNO (s) **NEW**
                        $data[21], // 23. ProductCategory (s)
                        $data[22], // 24. ProductSubcategory (s)
                        $data[23], // 25. Collection (s)
                        $data[24], // 26. Occasion (s)
                        $data[25], // 27. Quantity (i)
                        $data[28], // 28. Location (s)
                        $data[29], // 29. SALESEXU (s)
                        $data[30], // 30. Discount (d)
                        $data[31], // 31. OriginalSellingPrice (d)
                        $data[32], // 32. Incentive (s)
                        $data[33], // 33. DiscountPercentage (d)
                        $data[34], // 34. DiscountAmount (d)
                        $data[35], // 35. AddlessDiscount (d)
                        $data[36], // 36. GrossAmountAfterDiscount (d)
                        $data[37], // 37. Loyalty (s)
                        $data[38], // 38. GiftCode (s)
                        $data[39], // 39. GST (d)
                        $data[40], // 40. FinalAmountWithGST (d)
                        $data[41], // 41. GrossMargin (d)
                        $entryType, // 42. EntryType (s) (CALCULATED)
                        $storeCode, // 43. StoreCode (s) (CALCULATED)
                        $fiscalYear, // 44. FiscalYear (s) (CALCULATED)
                        $transactionID, // 45. TransactionID (s) (CALCULATED)
                        $calcs['NetSales'], // 46. NetSales (d) (CALCULATED)
                        $calcs['NetUnits'] // 47. NetUnits (i) (CALCULATED)
                    );

                    // New logic to handle affected_rows
                    if ($stmt->execute()) {
                        // 1 row inserted = 1 new row (affected_rows == 1)
                        // 1 row updated = 1 updated row (affected_rows == 2)
                        if ($stmt->affected_rows == 1) {
                            $newRows++;
                        } elseif ($stmt->affected_rows == 2) {
                            $updatedRows++;
                        }
                    } else {
                        $error_message = "Error during insert/update: " . $stmt->error;
                        break; // Stop on error
                    }
                }
                
                if (empty($error_message)) {
                    $conn->commit();
                    // Updated success message
                    $success_message = "Sales CSV Upload Complete. <strong>$newRows new transactions added.</strong> <strong>$updatedRows existing transactions updated.</strong> <strong>$skippedMalformedRows empty/malformed rows were ignored.</strong>";
                    // Now using NULL for company_id/user_id fallback
                    log_audit_action($conn, 'sales_csv_uploaded', "$newRows rows added, $updatedRows updated, $skippedMalformedRows skipped", $user_id, $company_id_context, 'sales_importer', null);
                } else {
                    $conn->rollback();
                }

                fclose($handle);
                $stmt->close();

            } else {
                $error_message = "Database prepare error: " . $conn->error;
            }
        } else { $error_message = "Could not open the uploaded CSV file."; }
    }
    elseif (empty($error_message) && $file_type == 'collection') {
        // --- THIS IS THE CSV (Collection Master) UPLOAD LOGIC ---
        
        $newOrUpdatedRows = 0;
        $skippedRows = 0;

        if (($handle = fopen($file_tmp_path, "r")) !== FALSE) {
            
            $conn->begin_transaction();
            
            // Get header row and map columns
            $headers = fgetcsv($handle);
            if ($headers === FALSE) {
                $error_message = "Could not read header row from CSV.";
            } else {
                $header_map = array_flip($headers);
                
                // Check for required column
                if (!isset($header_map['Finalize Jewel Code'])) {
                    $error_message = "CSV is missing the required column: 'Finalize Jewel Code'";
                } else {
                    $style_code_idx = $header_map['Finalize Jewel Code'];
                    $collection_idx = $header_map['Collection'] ?? null;
                    $group_idx = $header_map['Collection Group'] ?? null;
                    $name_idx = $header_map['Product Name:'] ?? null;
                    $desc_idx = $header_map['Product Descrption:'] ?? null;

                    // Prepare the SQL statement ONCE
                    $sql = "INSERT INTO collection_master (company_id, style_code, collection, collection_group, product_name, product_description) 
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                collection = VALUES(collection), 
                                collection_group = VALUES(collection_group), 
                                product_name = VALUES(product_name), 
                                product_description = VALUES(product_description)";
                    
                    if($stmt = $conn->prepare($sql)) {
                        while (($data = fgetcsv($handle)) !== FALSE) {
                            $style_code = $data[$style_code_idx] ?? null;
                            
                            // As requested, only process rows where Finalize Jewel Code (StyleCode) is present
                            if (empty($style_code)) {
                                $skippedRows++;
                                continue;
                            }
                            
                            $collection = ($collection_idx !== null) ? $data[$collection_idx] : null;
                            $collection_group = ($group_idx !== null) ? $data[$group_idx] : null;
                            $product_name = ($name_idx !== null) ? $data[$name_idx] : null;
                            $product_description = ($desc_idx !== null) ? $data[$desc_idx] : null;

                            $stmt->bind_param("isssss",
                                $company_id_context,
                                $style_code,
                                $collection,
                                $collection_group,
                                $product_name,
                                $product_description
                            );

                            if ($stmt->execute()) {
                                if ($stmt->affected_rows > 0) {
                                    $newOrUpdatedRows++;
                                } else {
                                    // This happens on an INSERT IGNORE duplicate, or an UPDATE with identical values.
                                    $skippedRows++;
                                }
                            } else {
                                $error_message = "Error during insert/update: " . $stmt->error;
                                break; // Stop on error
                            }
                        }
                        
                        if (empty($error_message)) {
                            $conn->commit();
                            $success_message = "Collection Master CSV Upload Complete. <strong>$newOrUpdatedRows new or updated records.</strong> <strong>$skippedRows empty or unchanged rows were ignored.</strong>";
                            // Now using NULL for company_id/user_id fallback
                            log_audit_action($conn, 'collection_csv_uploaded', "$newOrUpdatedRows rows added/updated, $skippedRows skipped", $user_id, $company_id_context, 'collection_master', null);
                        } else {
                            $conn->rollback();
                        }
                        $stmt->close();

                    } else {
                        $error_message = "Database prepare error: " . $conn->error;
                    }
                }
            }
            fclose($handle);
        } else { $error_message = "Could not open the uploaded CSV file."; }
    }
}


// --- ACTION 2: Update Gold Rate ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_gold_rate'])) {
    $new_rate = (float)$_POST['gold_rate'];
    if($new_rate <= 0){
        $error_message = "Gold rate must be a positive number.";
    } else {
        $sql_rate = "INSERT INTO gold_rates (company_id, rate_per_gram, updated_by_user_id) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE rate_per_gram = VALUES(rate_per_gram), updated_by_user_id = VALUES(updated_by_user_id)";
        if($stmt_rate = $conn->prepare($sql_rate)){
            $stmt_rate->bind_param("idi", $company_id_context, $new_rate, $user_id);
            if($stmt_rate->execute()){
                $success_message = "Gold rate updated to Rs. {$new_rate} successfully.";
                // Now using NULL for company_id/user_id fallback
                log_audit_action($conn, 'gold_rate_updated', "Updated gold rate to {$new_rate}", $user_id, $company_id_context, 'gold_rate', $new_rate);
            } else { $error_message = "Failed to update gold rate: " . $stmt_rate->error; }
            $stmt_rate->close();
        } else {
             $error_message = "Database prepare error for gold rate: " . $conn->error;
        }
    }
}

// --- DATA FETCHING (Current Gold Rate & Sales Data Status) ---
$current_gold_rate = 0;
$sql_get_rate = "SELECT rate_per_gram FROM gold_rates WHERE company_id = ?";
if($stmt_get_rate = $conn->prepare($sql_get_rate)){
    $stmt_get_rate->bind_param("i", $company_id_context);
    if($stmt_get_rate->execute()){
        $current_gold_rate = $stmt_get_rate->get_result()->fetch_assoc()['rate_per_gram'] ?? 0;
    }
    $stmt_get_rate->close();
}

// --- GET DATA FOR SALES NOTICE BOX ---
$last_upload_time = 'N/A';
$max_transaction_date = 'N/A';

// Query 1: Get last upload time from audit log
// Only search if company_id_context is NOT NULL to avoid potential errors
if ($company_id_context !== NULL) {
    $sql_last_upload = "SELECT created_at FROM audit_logs 
                        WHERE action_type = 'sales_csv_uploaded' AND company_id = ? 
                        ORDER BY created_at DESC LIMIT 1";
    if ($stmt_upload = $conn->prepare($sql_last_upload)) {
        $stmt_upload->bind_param("i", $company_id_context);
        if($stmt_upload->execute()){
            $result_upload = $stmt_upload->get_result();
            if ($row_upload = $result_upload->fetch_assoc()) {
                $last_upload_time = date('d-M-Y g:i A', strtotime($row_upload['created_at']));
            }
        }
        $stmt_upload->close();
    }
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
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Manage Data & Uploads</h2>
    <a href="tools/index.php" class="btn btn-secondary"><i data-lucide="arrow-left" class="me-2"></i>Back to Tools</a>
</div>

<?php if ($error_message): ?><div class="alert alert-danger"><?php echo $error_message; ?></div><?php endif; ?>
<?php if ($success_message): ?><div class="alert alert-success"><?php echo $success_message; // Don't escape, as it contains <strong> tags ?></div><?php endif; ?>

<div class="alert alert-info" role="alert">
    <h5 class="alert-heading"><i data-lucide="info" class="me-2" style="width: 20px; height: 20px; vertical-align: -4px;"></i>Sales Data Status</h5>
    <p class="mb-1">
        <strong>Last Sales Upload Run:</strong> <?php echo $last_upload_time; ?>
    </p>
    <p class="mb-0">
        <strong>Sales Data is uploaded until (Max Transaction Date):</strong> <?php echo $max_transaction_date; ?>
    </p>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h4>Upload Data File</h4></div>
            <div class="card-body">
                <p><strong>Stock/Cost (JSON):</strong> <span class="text-danger">Overwrites all</span> existing data for this type.</p>
                <p><strong>Opening Stock (JSON):</strong> <span class="text-danger">Overwrites data</span> for <span class="text-danger">all months found</span> in the file (based on 'Date' field).</p>
                <p><strong>Sales/Collection (CSV):</strong> <span class="text-success">Adds new</span> or <span class="text-success">updates existing</span> records.</p>
                <form action="tools/manage_data.php" method="post" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label for="file_type" class="form-label">1. Select Data Type</label>
                        <select id="file_type" name="file_type" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <option value="stock">Stock Data (JSON - Overwrite)</option>
                            <option value="cost">Cost Data (JSON - Overwrite)</option>
                            <option value="opening_stock">Opening Stock (JSON - Overwrite Months in File)</option>
                            <option value="sales">Sales Data (CSV - Append)</option>
                            <option value="collection">Collection Master (CSV - Append/Update)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="data_file" class="form-label">2. Upload Data File</label>
                        <input type="file" class="form-control" name="data_file" id="data_file" accept=".json,.csv" required>
                    </div>
                    <div class="mb-3" id="month-input-wrapper" style="display: none;">
                        <label for="snapshot_month" class="form-label">3. Select Snapshot Month</label>
                        <input type="month" class="form-control" name="snapshot_month" id="snapshot_month">
                        <div class="form-text">Select the month this opening stock snapshot represents.</div>
                    </div>
                    <button type="submit" class="btn btn-danger w-100" onclick="return confirm('Are you sure you want to upload this file? Please confirm the action type (Overwrite vs. Append) shown above.');">
                        <i data-lucide="upload-cloud" class="me-2"></i>Upload and Process Data
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card">
            <div class="card-header"><h4>Manage Gold Rate</h4></div>
            <div class="card-body">
                <form action="tools/manage_data.php" method="post">
                    <div class="mb-3">
                        <label for="gold_rate" class="form-label">Current Gold Rate (per gram)</label>
                        <input type="number" step="0.01" class="form-control" name="gold_rate" id="gold_rate" value="<?php echo $current_gold_rate; ?>" required>
                    </div>
                    <button type="submit" name="update_gold_rate" class="btn btn-primary w-100">
                        <i data-lucide="save" class="me-2"></i>Update Gold Rate
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
// Use the new Tools footer
require_once '../includes/footer.php';
?>