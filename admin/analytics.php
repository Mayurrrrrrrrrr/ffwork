<?php
// -- INITIALIZE SESSION AND DB CONNECTION --
// This MUST be the very first line to prevent blank pages.
require_once '../includes/init.php';

// -- SECURITY CHECK: ENSURE USER IS ACCOUNTS OR ADMIN --
if (!check_role('accounts') && !check_role('admin')) {
    header("Location: ../login.php");
    exit;
}

// --- DATA FETCHING FOR CHARTS ---
// We'll focus on 'approved' or 'paid' reports for accurate financial data.

// 1. Expenses by Category
$category_data = ['labels' => [], 'data' => []];
$sql_category = "SELECT i.category, SUM(i.amount) as total
                 FROM expense_items i
                 JOIN expense_reports r ON i.report_id = r.id
                 WHERE r.status IN ('approved', 'paid')
                 GROUP BY i.category
                 ORDER BY total DESC";
if ($result = $conn->query($sql_category)) {
    while ($row = $result->fetch_assoc()) {
        $category_data['labels'][] = $row['category'];
        $category_data['data'][] = $row['total'];
    }
}

// 2. Expenses by Department
$department_data = ['labels' => [], 'data' => []];
$sql_department = "SELECT u.department, SUM(r.approved_amount) as total
                   FROM expense_reports r
                   JOIN users u ON r.user_id = u.id
                   WHERE r.status IN ('approved', 'paid') AND u.department IS NOT NULL AND u.department != ''
                   GROUP BY u.department
                   ORDER BY total DESC";
if ($result = $conn->query($sql_department)) {
    while ($row = $result->fetch_assoc()) {
        $department_data['labels'][] = $row['department'];
        $department_data['data'][] = $row['total'];
    }
}

// 3. Expenses Over Time (by Month)
$monthly_data = ['labels' => [], 'data' => []];
$sql_monthly = "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS month, SUM(approved_amount) AS total
                FROM expense_reports
                WHERE status = 'paid' AND paid_at IS NOT NULL
                GROUP BY month
                ORDER BY month ASC
                LIMIT 12"; // Limit to the last 12 months for clarity
if ($result = $conn->query($sql_monthly)) {
    while ($row = $result->fetch_assoc()) {
        $monthly_data['labels'][] = date("M Y", strtotime($row['month'] . "-01"));
        $monthly_data['data'][] = $row['total'];
    }
}

// Convert PHP arrays to JSON for JavaScript
$category_json = json_encode($category_data);
$department_json = json_encode($department_data);
$monthly_json = json_encode($monthly_data);


// -- INCLUDE THE COMMON HTML HEADER --
require_once '../includes/header.php';
?>

<div class="container mt-4">
    <h2>Analytics Dashboard</h2>
    <p>Visual overview of company expenses. Data is based on approved and paid reports.</p>

    <div class="row g-4">
        <!-- Expenses by Category Chart -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h4>Expenses by Category</h4>
                </div>
                <div class="card-body">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Expenses by Department Chart -->
        <div class="col-lg-6">
            <div class="card">
                <div class="card-header">
                    <h4>Expenses by Department</h4>
                </div>
                <div class="card-body">
                    <canvas id="departmentChart"></canvas>
                </div>
            </div>
        </div>
        
        <!-- Expenses Over Time Chart -->
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4>Monthly Payouts Over Time</h4>
                </div>
                <div class="card-body">
                    <canvas id="monthlyChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const chartColors = ['#004E54', '#50A0B0', '#F39C12', '#C0392B', '#27AE60', '#6C757D'];

    // 1. Category Chart (Pie)
    const categoryCtx = document.getElementById('categoryChart').getContext('2d');
    const categoryData = <?php echo $category_json; ?>;
    if (categoryData.labels && categoryData.labels.length > 0) {
        new Chart(categoryCtx, {
            type: 'pie',
            data: {
                labels: categoryData.labels,
                datasets: [{
                    label: 'Expenses by Category',
                    data: categoryData.data,
                    backgroundColor: chartColors,
                    hoverOffset: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                }
            }
        });
    }

    // 2. Department Chart (Bar)
    const departmentCtx = document.getElementById('departmentChart').getContext('2d');
    const departmentData = <?php echo $department_json; ?>;
     if (departmentData.labels && departmentData.labels.length > 0) {
        new Chart(departmentCtx, {
            type: 'bar',
            data: {
                labels: departmentData.labels,
                datasets: [{
                    label: 'Total Expenses (Rs.)',
                    data: departmentData.data,
                    backgroundColor: '#004E54',
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: { beginAtZero: true }
                },
                plugins: {
                    legend: { display: false }
                }
            }
        });
    }

    // 3. Monthly Chart (Line)
    const monthlyCtx = document.getElementById('monthlyChart').getContext('2d');
    const monthlyData = <?php echo $monthly_json; ?>;
    if (monthlyData.labels && monthlyData.labels.length > 0) {
        new Chart(monthlyCtx, {
            type: 'line',
            data: {
                labels: monthlyData.labels,
                datasets: [{
                    label: 'Total Payouts (Rs.)',
                    data: monthlyData.data,
                    fill: false,
                    borderColor: '#004E54',
                    tension: 0.1
                }]
            },
            options: {
                responsive: true,
                 scales: {
                    y: { beginAtZero: true }
                },
                 plugins: {
                    legend: { display: false }
                }
            }
        });
    }
});
</script>

<?php
// -- CLOSE DATABASE CONNECTION AND INCLUDE FOOTER --
$conn->close();
require_once '../includes/footer.php';
?>

