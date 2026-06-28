<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'inventory_manager', 'principal'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle CSV export action
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=inventory_report_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    
    // Output column headers
    fputcsv($output, ['Item Name', 'SKU Code', 'Category', 'Quantity Available', 'Unit', 'Unit Price (₵)', 'Total Valuation (₵)', 'Location', 'Status', 'Supplier', 'Created At']);
    
    // Fetch all items
    $export_query = "SELECT ii.*, ic.name as category_name 
                    FROM inventory_items ii
                    LEFT JOIN inventory_categories ic ON ii.category_id = ic.id
                    WHERE ii.status != 'discontinued'
                    ORDER BY ic.name, ii.item_name";
    $stmt = $db->query($export_query);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $total_val = $row['quantity_available'] * $row['unit_price'];
        fputcsv($output, [
            $row['item_name'],
            $row['item_code'],
            $row['category_name'] ?: 'Uncategorized',
            $row['quantity_available'],
            $row['unit'] ?: 'pcs',
            $row['unit_price'],
            $total_val,
            $row['location'] ?: 'N/A',
            ucfirst($row['status']),
            $row['supplier'] ?: 'N/A',
            $row['created_at']
        ]);
    }
    fclose($output);
    exit();
}

// 1. Fetch key statistics
$stats_query = "SELECT 
    COUNT(CASE WHEN status != 'discontinued' THEN 1 END) as total_items,
    SUM(CASE WHEN status != 'discontinued' THEN quantity_available ELSE 0 END) as total_qty,
    SUM(CASE WHEN status != 'discontinued' THEN quantity_available * unit_price ELSE 0.00 END) as total_value,
    COUNT(CASE WHEN status = 'available' AND quantity_available <= minimum_stock_level THEN 1 END) as low_stock_items
    FROM inventory_items";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

// 2. Fetch category breakdown
$category_breakdown_query = "SELECT 
    ic.name as category_name,
    COUNT(ii.id) as item_count,
    SUM(ii.quantity_available) as category_qty,
    SUM(ii.quantity_available * ii.unit_price) as category_value
    FROM inventory_categories ic
    LEFT JOIN inventory_items ii ON ii.category_id = ic.id AND ii.status != 'discontinued'
    GROUP BY ic.id, ic.name
    ORDER BY ic.name";
$categories_data = $db->query($category_breakdown_query)->fetchAll(PDO::FETCH_ASSOC);

// 3. Fetch low stock items list
$low_stock_query = "SELECT ii.*, ic.name as category_name
                    FROM inventory_items ii
                    LEFT JOIN inventory_categories ic ON ii.category_id = ic.id
                    WHERE ii.status = 'available' AND ii.quantity_available <= ii.minimum_stock_level
                    ORDER BY ii.quantity_available ASC
                    LIMIT 20";
$low_stock_list = $db->query($low_stock_query)->fetchAll(PDO::FETCH_ASSOC);

$title = "Inventory Reports & Analytics";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Inventory Reports & Analytics</h1>
                    <div class="flex space-x-3">
                        <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                        <a href="?action=export" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg font-semibold flex items-center gap-2">
                            <i class="fas fa-file-csv text-lg"></i> Export to CSV
                        </a>
                    </div>
                </div>

                <!-- Stats Panel -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-150 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Total Items Registered</span>
                        <span class="text-2xl font-extrabold text-blue-600 dark:text-blue-450"><?php echo number_format($stats['total_items'] ?: 0); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-150 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Total Quantity In Stock</span>
                        <span class="text-2xl font-extrabold text-green-600 dark:text-green-450"><?php echo number_format($stats['total_qty'] ?: 0); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-150 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Total Assets Valuation</span>
                        <span class="text-2xl font-extrabold text-purple-600 dark:text-purple-450">₵<?php echo number_format($stats['total_value'] ?: 0.00, 2); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-150 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Low Stock Warnings</span>
                        <span class="text-2xl font-extrabold text-red-650 <?php echo ($stats['low_stock_items'] ?: 0) > 0 ? 'animate-pulse' : ''; ?>">
                            <?php echo number_format($stats['low_stock_items'] ?: 0); ?>
                        </span>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Category Breakdown -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-semibold text-gray-850 dark:text-white mb-4"><i class="fas fa-chart-pie mr-2 text-indigo-500"></i>Category Breakdown</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-400 uppercase">Category</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">Items</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">Total Qty</th>
                                        <th class="px-4 py-2.5 text-right text-xs font-bold text-gray-400 uppercase">Value (₵)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($categories_data as $cat): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750">
                                        <td class="px-4 py-3 text-gray-900 dark:text-white font-semibold"><?php echo htmlspecialchars($cat['category_name']); ?></td>
                                        <td class="px-4 py-3 text-center text-gray-700 dark:text-gray-300"><?php echo $cat['item_count']; ?></td>
                                        <td class="px-4 py-3 text-center text-gray-700 dark:text-gray-300"><?php echo number_format($cat['category_qty'] ?: 0); ?></td>
                                        <td class="px-4 py-3 text-right text-gray-900 dark:text-white font-bold">₵<?php echo number_format($cat['category_value'] ?: 0.00, 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Low Stock Alerts List -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-semibold text-gray-850 dark:text-white mb-4"><i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>Low Stock Warnings</h2>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-900">
                                    <tr>
                                        <th class="px-4 py-2.5 text-left text-xs font-bold text-gray-400 uppercase">Item Name</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">SKU</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">In Stock</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">Min Level</th>
                                        <th class="px-4 py-2.5 text-center text-xs font-bold text-gray-400 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if (!empty($low_stock_list)): ?>
                                        <?php foreach ($low_stock_list as $item): ?>
                                        <tr class="hover:bg-red-50/30 dark:hover:bg-red-950/10">
                                            <td class="px-4 py-3 text-gray-900 dark:text-white font-semibold"><?php echo htmlspecialchars($item['item_name']); ?></td>
                                            <td class="px-4 py-3 text-center font-mono text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($item['item_code']); ?></td>
                                            <td class="px-4 py-3 text-center text-red-650 font-bold"><?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></td>
                                            <td class="px-4 py-3 text-center text-gray-750 dark:text-gray-300"><?php echo $item['minimum_stock_level']; ?></td>
                                            <td class="px-4 py-3 text-center">
                                                <a href="../items/restock.php?id=<?php echo $item['id']; ?>" class="text-blue-600 hover:text-blue-800 font-semibold text-xs border border-blue-600 px-2 py-1 rounded transition">
                                                    Restock
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                    <tr>
                                        <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                                            All items are adequately stocked. No warnings!
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
