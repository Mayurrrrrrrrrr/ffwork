<?php
// reports/index.php
// This is now a central hub for all reports.

// Use the local init.php which also loads the main one.
require_once 'init.php'; 

// Use the local header
require_once 'includes/header.php';

// Check if user is an admin to show special reports
$isAdmin = has_any_role(['admin', 'platform_admin']);
?>

<style>
/* --- MODERN LIGHT DASHBOARD STYLES --- */
:root {
    --primary-light: #4c87b9;
    --primary-accent: #17a2b8; /* Teal for Admin */
    --secondary-light: #6c757d;
    --shadow-light: 0 4px 8px rgba(0, 0, 0, 0.08);
    --hover-shadow: 0 6px 12px rgba(0, 0, 0, 0.12);
    --border-color: #dee2e6;
}

/* Base cleanup */
body {
    background-color: #f8f9fa !important; /* Off-white background */
}

/* Main Title */
.reports-hub-title {
    color: var(--secondary-light);
    font-weight: 300;
    margin-bottom: 25px;
    border-bottom: 1px solid #e9ecef;
    padding-bottom: 10px;
}

/* Dashboard Panel Styles */
.dashboard-panel {
    border: none;
    border-radius: 8px;
    box-shadow: var(--shadow-light);
    transition: all 0.3s ease;
    overflow: hidden;
}
.dashboard-panel:hover {
    box-shadow: var(--hover-shadow);
}

.panel-header {
    background-color: #f8f9fa; /* Light header base */
    border-bottom: 1px solid var(--border-color);
    color: var(--primary-light);
    font-weight: 600;
    padding: 15px;
    text-transform: uppercase;
    font-size: 1rem;
}

/* Admin Panel specific header accent */
.admin-panel-header {
    background-color: var(--primary-accent); /* Teal background */
    color: white;
    border-bottom: 1px solid var(--primary-accent);
}

/* List Group Styles (Report Links) */
.report-link {
    background-color: white;
    color: #343a40; /* Dark text */
    border-bottom: 1px solid #f1f1f1;
    padding: 15px 20px;
    transition: background-color 0.2s, transform 0.1s;
    font-weight: 400;
}
.report-link:hover {
    background-color: #e9ecef;
    color: var(--primary-light);
    transform: none; /* Removed translation for light theme */
}

.report-link i[data-lucide] {
    color: var(--primary-light);
    width: 20px;
}

.admin-panel-links i[data-lucide] {
    color: var(--primary-accent); /* Teal icons for Admin links */
}

.report-link:hover i[data-lucide] {
    color: var(--primary-light);
}

</style>

<div class="container-fluid">
    <h2 class="reports-hub-title">EXECUTIVE REPORT OVERVIEW // ACCESS MODULES</h2>

    <div class="row">
        <div class="col-md-6 mb-4">
            <div class="dashboard-panel card">
                <div class="panel-header">
                    Field & Operations Reports
                </div>
                <div class="list-group list-group-flush">
                    <a href="checklist.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="check-square" class="me-3"></i>Field Checklist Compliance</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                    <a href="competition.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="zap" class="me-3"></i>Competition Analysis</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                    <a href="sales.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="shopping-cart" class="me-3"></i>Field Sales Entry Logs</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                    <a href="stock_count.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="clipboard-list" class="me-3"></i>Stock Count Submissions</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                    <a href="vm_upload.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="image-up" class="me-3"></i>VM Picture Upload</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                    <a href="view_vm_pictures.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center">
                        <span><i data-lucide="image" class="me-3"></i>View VM Pictures Gallery</span>
                        <i data-lucide="chevron-right"></i>
                    </a>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <div class="col-md-6 mb-4">
            <div class="dashboard-panel card">
                <div class="panel-header admin-panel-header">
                    Admin Analytics & Sales Reports
                </div>
                <div class="list-group list-group-flush admin-panel-links">
                    <a href="../admin/sales_dashboard.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="layout-dashboard" class="me-3"></i>Sales Executive Dashboard</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>

                    <a href="../admin/kpi_comparison_dashboard.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="git-compare" class="me-3"></i>KPI Performance Comparison</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    
                    <a href="../admin/sales_analysis_report.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="trending-up" class="me-3"></i>Advanced Sales Analysis</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    
                    <a href="../admin/stock_kpi_report.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="bar-chart-2" class="me-3"></i>Inventory KPI Metrics</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    
                    <a href="../admin/sales_financial_report.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="line-chart" class="me-3"></i>Financial Report (YoY Analysis)</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    
                    <a href="../admin/sales_transaction_report.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="list" class="me-3"></i>Detailed Transaction Report</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    
                    <a href="../admin/analytics.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="pie-chart" class="me-3"></i>Expense Analytics</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    <a href="../admin/sales_report_builder.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="file-cog" class="me-3"></i>Sales Report Builder</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    <a href="../admin/sales_customer_report.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="users" class="me-3"></i>Customer Profile Report</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    <a href="../admin/sales_repeat_customer_analysis.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="repeat" class="me-3"></i>Repeat Customer Analysis</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    <a href="../admin/report_anomalies.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="alert-triangle" class="me-3"></i>Anomaly Report</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                     <a href="../admin/report_petty_cash.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="wallet" class="me-3"></i>Petty Cash Report</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                     <a href="../admin/audit_logs.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="shield" class="me-3"></i>Audit Logs</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                    <a href="../admin/payouts.php" class="list-group-item list-group-item-action report-link d-flex justify-content-between align-items-center" target="_blank">
                        <span><i data-lucide="award" class="me-3"></i>Referral Payouts</span>
                        <i data-lucide="external-link" style="width:16px;"></i>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php 
// Use the local footer
require_once 'includes/footer.php'; 
?>