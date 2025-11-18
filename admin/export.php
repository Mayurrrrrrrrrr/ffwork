<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS OR ADMIN --
if (!check_role('accounts') && !check_role('admin')) {
    header("Location: ../login.php");
    exit;
}

// --- REPLICATE FILTERING LOGIC FROM reports.php TO FETCH THE CORRECT DATA ---
$where_clauses = [];
$params = [];
$types = '';

// This SQL query is designed to fetch all relevant data for the export
$sql = "SELECT 
            u.full_name,
            u.department,
            r.report_title,
            r.report_type,
            r.submitted_at,
            r.status,
            r.total_amount,
            r.approved_amount,
            r.approved_at,
            r.paid_at
        FROM expense_reports r 
        JOIN users u ON r.user_id = u.id";

// Get filter parameters from the URL
$user_id_filter = $_GET['user_id'] ?? '';
$status_filter = $_GET['status'] ?? '';
$start_date_filter = $_GET['start_date'] ?? '';
$end_date_filter = $_GET['end_date'] ?? '';

// Build the WHERE clause dynamically based on the active filters
if (!empty($user_id_filter)) {
    $where_clauses[] = "r.user_id = ?";
    $params[] = $user_id_filter;
    $types .= 'i';
}
if (!empty($status_filter)) {
    $where_clauses[] = "r.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if (!empty($start_date_filter)) {
    $where_clauses[] = "r.submitted_at >= ?";
    $params[] = $start_date_filter . ' 00:00:00';
    $types .= 's';
}
if (!empty($end_date_filter)) {
    $where_clauses[] = "r.submitted_at <= ?";
    $params[] = $end_date_filter . ' 23:59:59';
    $types .= 's';
}

if (!empty($where_clauses)) {
    $sql .= " WHERE " . implode(' AND ', $where_clauses);
}

$sql .= " ORDER BY r.submitted_at DESC";

// --- FETCH DATA ---
$data_to_export = [];
if ($stmt = $conn->prepare($sql)) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $data_to_export[] = $row;
    }
    $stmt->close();
}
$conn->close();

// --- GENERATE AND OUTPUT CSV FILE ---
$filename = "expense_report_" . date('Y-m-d') . ".csv";

// Set HTTP headers to force the browser to download the file
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=' . $filename);

// Open a file pointer to the PHP output stream
$output = fopen('php://output', 'w');

// Write the CSV header row
fputcsv($output, [
    'Employee Name',
    'Department',
    'Report Title',
    'Report Type',
    'Submitted On',
    'Status',
    'Claimed Amount (Rs.)',
    'Approved Amount (Rs.)',
    'Approved On',
    'Paid On'
]);

// Loop through the fetched data and write each row to the CSV
foreach ($data_to_export as $row) {
    fputcsv($output, [
        $row['full_name'],
        $row['department'],
        $row['report_title'],
        $row['report_type'],
        $row['submitted_at'],
        ucwords(str_replace('_', ' ', $row['status'])), // Format status to be human-readable
        $row['total_amount'],
        $row['approved_amount'],
        $row['approved_at'],
        $row['paid_at']
    ]);
}

fclose($output);
exit;
