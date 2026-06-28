<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// --- 1. Fetch Block Occupancy Report ---
$blocks_query = "
    SELECT hb.id, hb.name, hb.block_type, hb.status,
           COUNT(DISTINCT hr.id) as total_rooms,
           COALESCE(SUM(hr.capacity), 0) as total_capacity,
           COUNT(DISTINCT ha.id) as total_residents
    FROM hostel_blocks hb
    LEFT JOIN hostel_rooms hr ON hb.id = hr.block_id AND hr.status != 'maintenance'
    LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
    GROUP BY hb.id
    ORDER BY hb.name
";
$blocks_stmt = $db->query($blocks_query);
$blocks_report = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Overall occupancy metrics
$total_capacity = 0;
$total_residents = 0;
foreach ($blocks_report as $block) {
    $total_capacity += $block['total_capacity'];
    $total_residents += $block['total_residents'];
}
$overall_occupancy_rate = $total_capacity > 0 ? ($total_residents / $total_capacity) * 100 : 0;

// --- 2. Fetch Maintenance Status Summary ---
$maint_query = "
    SELECT status, priority, COUNT(*) as count
    FROM hostel_maintenance
    GROUP BY status, priority
";
$maint_stmt = $db->query($maint_query);
$maint_summary = $maint_stmt->fetchAll(PDO::FETCH_ASSOC);

$maint_stats = ['pending' => 0, 'in_progress' => 0, 'resolved' => 0, 'cancelled' => 0, 'total' => 0];
$maint_priority = ['high' => 0, 'medium' => 0, 'low' => 0];

foreach ($maint_summary as $item) {
    $maint_stats[$item['status']] += $item['count'];
    $maint_stats['total'] += $item['count'];
    $maint_priority[$item['priority']] += $item['count'];
}

// --- 3. Fetch Fees Summary ---
$cat_stmt = $db->query("SELECT id FROM finance_fee_categories WHERE name LIKE '%Boarding%' OR name LIKE '%Hostel%' LIMIT 1");
$cat_row = $cat_stmt->fetch(PDO::FETCH_ASSOC);
$boarding_cat_id = $cat_row ? $cat_row['id'] : null;

$fees_report = ['invoiced' => 0.00, 'collected' => 0.00, 'outstanding' => 0.00];

if ($boarding_cat_id) {
    $fees_query = "
        SELECT 
            SUM(fii.amount) as total_invoiced,
            SUM(CASE WHEN fi.status = 'paid' THEN fii.amount 
                     WHEN fi.status = 'partially_paid' THEN fii.amount * (fi.amount_paid / NULLIF(fi.total_amount + fi.penalty_amount - fi.discount_amount, 0))
                     ELSE 0 END) as total_paid
        FROM finance_invoice_items fii
        JOIN finance_invoices fi ON fii.invoice_id = fi.id
        WHERE fii.category_id = :boarding_cat_id AND fi.status != 'cancelled'
    ";
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':boarding_cat_id', $boarding_cat_id);
    $fees_stmt->execute();
    $fees_row = $fees_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($fees_row) {
        $fees_report['invoiced'] = floatval($fees_row['total_invoiced']);
        $fees_report['collected'] = floatval($fees_row['total_paid']);
        $fees_report['outstanding'] = $fees_report['invoiced'] - $fees_report['collected'];
    }
}

$title = "Hostel Reports";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Reports']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Print Styling -->
<style>
@media print {
    body {
        background-color: white !important;
        color: black !important;
    }
    .sidebar-spacer, header, .no-print, nav, sidebar {
        display: none !important;
    }
    main {
        margin-top: 0 !important;
        padding: 0 !important;
    }
    .print-card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden no-print" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-8 flex-grow">
        <div class="max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="mb-8 no-print">
                <div class="bg-gradient-to-r from-purple-600 via-pink-600 to-red-500 rounded-2xl p-8 text-white shadow-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Hostel Analytical Reports</h1>
                            <p class="text-purple-100 text-lg">Detailed metrics on room occupancy, maintenance request pipelines, and boarding finance</p>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-chart-line text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Print & Export Buttons -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6 no-print">
                <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-medium px-4 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-200 self-start">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Hostel
                </a>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="print_report.php" target="_blank" class="inline-flex items-center whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition shadow">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </a>
                    <button onclick="exportReportsCSV()" class="inline-flex items-center whitespace-nowrap bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition shadow">
                        <i class="fas fa-file-csv mr-2"></i>Export Occupancy CSV
                    </button>
                </div>
            </div>

            <!-- Report Header for Print -->
            <div class="hidden print:block text-center mb-8 border-b pb-4">
                <h1 class="text-3xl font-bold text-gray-800">School Management System</h1>
                <h2 class="text-xl font-semibold text-gray-600">Hostel Management Summary Report</h2>
                <p class="text-xs text-gray-500 mt-2">Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
            </div>

            <!-- Summary Cards Section -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8 print-card">
                <!-- Occupancy Card -->
                <div class="bg-white rounded-lg shadow border p-6">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Overall Occupancy</h3>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-blue-600"><?php echo number_format($overall_occupancy_rate, 1); ?>%</span>
                        <span class="text-sm text-gray-500">(<?php echo $total_residents; ?> / <?php echo $total_capacity; ?> Beds)</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5 mt-4">
                        <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $overall_occupancy_rate; ?>%"></div>
                    </div>
                </div>

                <!-- Maintenance Card -->
                <div class="bg-white rounded-lg shadow border p-6">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Active Repairs</h3>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-orange-600"><?php echo $maint_stats['pending'] + $maint_stats['in_progress']; ?></span>
                        <span class="text-sm text-gray-500">Unresolved requests</span>
                    </div>
                    <p class="text-xs text-gray-400 mt-4">Resolved: <?php echo $maint_stats['resolved']; ?> | High Priority: <?php echo $maint_priority['high']; ?></p>
                </div>

                <!-- Financial Card -->
                <div class="bg-white rounded-lg shadow border p-6">
                    <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Boarding Fee Collections</h3>
                    <div class="flex items-baseline space-x-2">
                        <span class="text-3xl font-extrabold text-green-600">
                            <?php 
                            $coll_rate = $fees_report['invoiced'] > 0 ? ($fees_report['collected'] / $fees_report['invoiced']) * 100 : 0;
                            echo number_format($coll_rate, 1) . '%';
                            ?>
                        </span>
                        <span class="text-sm text-gray-500">Collection rate</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2.5 mt-4">
                        <div class="bg-green-500 h-2.5 rounded-full" style="width: <?php echo $coll_rate; ?>%"></div>
                    </div>
                </div>
            </div>

            <!-- Detailed Tables Grid -->
            <div class="grid grid-cols-1 gap-8">
                <!-- 1. Occupancy Breakdown Table -->
                <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden print-card">
                    <div class="p-6 bg-gray-50 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-building mr-2 text-blue-500"></i>Hostel Block Occupancy Details</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-100">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Block Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Rooms Count</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bed Capacity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Current Residents</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Occupancy Rate</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($blocks_report as $block): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900"><?php echo htmlspecialchars($block['name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo ucfirst($block['block_type']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($block['total_rooms']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($block['total_capacity']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($block['total_residents']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php 
                                        $b_rate = $block['total_capacity'] > 0 ? ($block['total_residents'] / $block['total_capacity']) * 100 : 0;
                                        ?>
                                        <div class="flex items-center space-x-2">
                                            <span class="font-semibold text-gray-700"><?php echo number_format($b_rate, 1); ?>%</span>
                                            <div class="w-16 bg-gray-200 h-1.5 rounded">
                                                <div class="bg-blue-600 h-1.5 rounded" style="width: <?php echo $b_rate; ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php echo $block['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                            <?php echo ucfirst($block['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 2. Maintenance & Financial Summaries side-by-side -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 print-card">
                    <!-- Maintenance Summary -->
                    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                        <div class="p-6 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-tools mr-2 text-orange-500"></i>Maintenance request stats</h3>
                        </div>
                        <div class="p-6 space-y-4 text-sm">
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Pending Approvals:</span>
                                <span class="font-bold text-red-600"><?php echo $maint_stats['pending']; ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">In Progress Works:</span>
                                <span class="font-bold text-blue-600"><?php echo $maint_stats['in_progress']; ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Resolved Requests:</span>
                                <span class="font-bold text-green-600"><?php echo $maint_stats['resolved']; ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Total Logged Tickets:</span>
                                <span class="font-bold text-gray-800"><?php echo $maint_stats['total']; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Financial Summary -->
                    <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                        <div class="p-6 bg-gray-50 border-b border-gray-200">
                            <h3 class="text-lg font-semibold text-gray-800"><i class="fas fa-coins mr-2 text-green-500"></i>Boarding Fees Summary</h3>
                        </div>
                        <div class="p-6 space-y-4 text-sm">
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Total Billed Invoices:</span>
                                <span class="font-bold text-gray-800">₵<?php echo number_format($fees_report['invoiced'], 2); ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Total Collected Payments:</span>
                                <span class="font-bold text-green-600">₵<?php echo number_format($fees_report['collected'], 2); ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Total Outstanding Payments:</span>
                                <span class="font-bold text-red-600">₵<?php echo number_format($fees_report['outstanding'], 2); ?></span>
                            </div>
                            <div class="flex justify-between border-b pb-2">
                                <span class="text-gray-500">Collection Rate:</span>
                                <span class="font-bold text-blue-600"><?php echo number_format($coll_rate, 1); ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <!-- Footer -->
    <div class="lg:ml-0 no-print">
        <?php include '../../includes/footer.php'; ?>
    </div>
</div>
</div>

<script>
function exportReportsCSV() {
    const data = [
        <?php foreach ($blocks_report as $block): ?>
        {
            'Block Name': '<?php echo addslashes($block['name']); ?>',
            'Block Type': '<?php echo ucfirst($block['block_type']); ?>',
            'Rooms Count': '<?php echo $block['total_rooms']; ?>',
            'Bed Capacity': '<?php echo $block['total_capacity']; ?>',
            'Current Residents': '<?php echo $block['total_residents']; ?>',
            'Occupancy Rate': '<?php echo number_format($block['total_capacity'] > 0 ? ($block['total_residents'] / $block['total_capacity']) * 100 : 0, 1); ?>%',
            'Status': '<?php echo ucfirst($block['status']); ?>'
        },
        <?php endforeach; ?>
    ];
    
    ExportUtils.exportArrayToCSV(
        data,
        ExportUtils.generateFilename('hostel_occupancy_report'),
        ['Block Name', 'Block Type', 'Rooms Count', 'Bed Capacity', 'Current Residents', 'Occupancy Rate', 'Status']
    );
}
</script>