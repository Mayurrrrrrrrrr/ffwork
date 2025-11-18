<?php
// Use the new Tools header
require_once 'includes/header.php'; // Provides $conn, role checks, $company_id_context, $user_id, $is_manager, $is_data_admin

// Define roles that get access to Cost Lookup and Certificate Generator
$privileged_tools_roles = [
    'accounts', 'order_team', 'inventory_team', 'purchase_team', 
    'purchase_head', 'admin', 'platform_admin', 'data_admin'
];

$is_privileged_tools_user = has_any_role($privileged_tools_roles);
?>

<h2>Tools Dashboard</h2>
<p>Welcome to the central hub for company tools. Please select a tool to continue.</p>

<div class="row g-4">
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="search" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Stock Lookup</h3>
                <p class="text-muted flex-grow-1">Find detailed stock information by searching for a Jewel Code.</p>
                <a href="tools/stocklookup.php" class="btn btn-primary mt-3">
                    Open Tool
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="filter" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Product Finder</h3>
                <p class="text-muted flex-grow-1">Filter all stock by category, metal, price, and location.</p>
                <a href="tools/productfinder.php" class="btn btn-primary mt-3">
                    Open Tool
                </a>
            </div>
        </div>
    </div>
    
    <!-- Cost Lookup (Restricted Access) -->
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm <?php echo $is_privileged_tools_user ? '' : 'bg-light'; ?>">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="dollar-sign" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Cost Lookup</h3>
                <p class="text-muted flex-grow-1">Find cost and sale price information for an item.</p>
                <?php if ($is_privileged_tools_user): ?>
                    <a href="tools/costlookup.php" class="btn btn-primary mt-3">
                        Open Tool
                    </a>
                <?php else: ?>
                    <a href="#" class="btn btn-secondary mt-3 disabled">
                        Access Denied
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="send" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Internal Store Order</h3>
                <p class="text-muted flex-grow-1">Request or send stock to other stores within the company.</p>
                <a href="stock_transfer/index.php" class="btn btn-primary mt-3">
                    Open ISO System
                </a>
            </div>
        </div>
    </div>
    
    <!-- Certificate Generator (Restricted Access) -->
     <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm <?php echo $is_privileged_tools_user ? '' : 'bg-light'; ?>">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="award" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Certificate Generator</h3>
                <p class="text-muted flex-grow-1">Generate customer certificates (migrated from old tool).</p>
                 <?php if ($is_privileged_tools_user): ?>
                    <a href="tools/certificate_generator.php" class="btn btn-primary mt-3">
                        Open Tool
                    </a>
                <?php else: ?>
                    <a href="#" class="btn btn-secondary mt-3 disabled">
                        Access Denied
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-6 col-lg-4">
        <div class="card h-100 shadow-sm <?php /* echo check_role('employee') ? '' : 'bg-light'; */ ?>">
            <div class="card-body text-center p-5 d-flex flex-column">
                <i data-lucide="gem" class="text-primary" style="width: 48px; height: 48px; stroke-width: 1.5px; margin: 0 auto;"></i>
                <h3 class="heading-font text-primary mt-3">Old Gold Form</h3>
                <p class="text-muted flex-grow-1">Tool for logging old gold purchases and generating Bill of Supply.</p>
                 <?php // Only enable if the user has employee role access to this feature ?>
                 <?php if (check_role('employee')): ?>
                    <a href="tools/old_gold_v2.php" class="btn btn-primary mt-3">
                        Open Form
                    </a>
                <?php else: ?>
                    <a href="#" class="btn btn-secondary mt-3 disabled">
                        Access Denied
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
if(isset($conn)) $conn->close();
// Corrected path for the footer include
require_once '../includes/footer.php';
?>

