<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'inventory_manager', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$id) {
    header("Location: index.php");
    exit();
}

// Fetch item details
$query = "SELECT ii.*, ic.name as category_name 
          FROM inventory_items ii
          LEFT JOIN inventory_categories ic ON ii.category_id = ic.id
          WHERE ii.id = :id AND ii.status != 'discontinued'";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php");
    exit();
}

// Fetch movement logs for this item
$movements_query = "SELECT im.*, u.name as user_name
                    FROM inventory_movements im
                    JOIN users u ON im.user_id = u.id
                    WHERE im.item_id = :item_id
                    ORDER BY im.created_at DESC";
$stmt = $db->prepare($movements_query);
$stmt->execute([':item_id' => $id]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats for display
$total_in = 0;
$total_out = 0;
foreach ($movements as $m) {
    if ($m['movement_type'] === 'in') {
        $total_in += $m['quantity'];
    } else {
        $total_out += $m['quantity'];
    }
}

$title = "View Item: " . $item['item_name'];
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
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['item_name']); ?> Details</h1>
                    <div class="flex flex-row items-center gap-3">
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                        <a href="restock.php?id=<?php echo $item['id']; ?>" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-plus mr-2"></i>Restock Item
                        </a>
                        <a href="edit.php?id=<?php echo $item['id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-edit mr-2"></i>Edit Item
                        </a>
                    </div>
                </div>

                <!-- Info Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Left: Item Specifications -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 lg:col-span-2 p-6">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Specifications</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Item Code:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($item['item_code']); ?></p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Category:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?></p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Unit of Measurement:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Unit Price:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white">₵<?php echo number_format($item['unit_price'], 2); ?></p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Supplier:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($item['supplier'] ?: 'N/A'); ?></p>
                            </div>
                            <div>
                                <span class="text-sm text-gray-500 dark:text-gray-400">Storage Location:</span>
                                <p class="text-base font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($item['location'] ?: 'N/A'); ?></p>
                            </div>
                        </div>
                        <?php if ($item['description']): ?>
                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700">
                            <span class="text-sm text-gray-500 dark:text-gray-400">Description:</span>
                            <p class="text-gray-700 dark:text-gray-300 mt-1"><?php echo nl2br(htmlspecialchars($item['description'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Right: Inventory Status Summary -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 flex flex-col justify-between">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-800 dark:text-white mb-4">Stock Status Summary</h2>
                            <div class="space-y-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Current Stock:</span>
                                    <span class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Minimum Stock Level:</span>
                                    <span class="text-lg font-semibold text-gray-800 dark:text-white"><?php echo $item['minimum_stock_level']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></span>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Inventory Status:</span>
                                    <?php if ($item['quantity_available'] == 0): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">Out of Stock</span>
                                    <?php elseif ($item['quantity_available'] <= $item['minimum_stock_level']): ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">Low Stock</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">In Stock</span>
                                    <?php endif; ?>
                                </div>
                                <div class="flex justify-between items-center">
                                    <span class="text-sm text-gray-500 dark:text-gray-400">Total Valuation:</span>
                                    <span class="text-lg font-bold text-purple-600 dark:text-purple-400">₵<?php echo number_format($item['quantity_available'] * $item['unit_price'], 2); ?></span>
                                </div>
                            </div>
                        </div>

                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 grid grid-cols-2 gap-4 text-center">
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Total Restocked</span>
                                <p class="text-xl font-bold text-green-600"><?php echo $total_in; ?></p>
                            </div>
                            <div>
                                <span class="text-xs text-gray-500 dark:text-gray-400 uppercase font-semibold">Total Disbursed</span>
                                <p class="text-xl font-bold text-red-600"><?php echo $total_out; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Stock Movements Table -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Stock Movement History</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Quantity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Performed By</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Reason / Reference</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if (!empty($movements)): ?>
                                    <?php foreach ($movements as $m): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo date('M j, Y g:i A', strtotime($m['created_at'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold">
                                            <?php if ($m['movement_type'] === 'in'): ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-800"><i class="fas fa-arrow-down mr-1"></i> Stock In</span>
                                            <?php else: ?>
                                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-800"><i class="fas fa-arrow-up mr-1"></i> Stock Out</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-medium">
                                            <?php echo $m['quantity']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-850 dark:text-gray-300">
                                            <?php echo htmlspecialchars($m['user_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 capitalize">
                                            <?php 
                                            if ($m['reference_type']) {
                                                echo str_replace('_', ' ', $m['reference_type']);
                                                if ($m['reference_id']) {
                                                    echo " (#" . $m['reference_id'] . ")";
                                                }
                                            } else {
                                                echo '-';
                                            }
                                            ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo htmlspecialchars($m['notes'] ?: '-'); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No movements logged for this item yet.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
