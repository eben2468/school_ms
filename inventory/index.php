<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'inventory_manager'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';

// Get inventory statistics
$stats_query = "SELECT
    COUNT(*) as total_items,
    SUM(quantity_available) as total_quantity,
    COUNT(CASE WHEN quantity_available <= 10 THEN 1 END) as low_stock_items,
    SUM(quantity_available * unit_price) as total_value
    FROM inventory_items
    WHERE status = 'active'";
$stmt = $conn->prepare($stats_query);
$stmt->execute();
$stats = $stmt->get_result()->fetch_assoc();

if (!$stats) {
    $stats = [
        'total_items' => 0,
        'total_quantity' => 0,
        'low_stock_items' => 0,
        'total_value' => 0
    ];
}

// Get recent inventory items (since we don't have movements table yet)
$recent_items_query = "SELECT ii.*, 'system' as user_name
    FROM inventory_items ii
    WHERE ii.status = 'active'
    ORDER BY ii.created_at DESC
    LIMIT 10";
$stmt = $conn->prepare($recent_items_query);
$stmt->execute();
$recent_movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Transform to look like movements
foreach ($recent_movements as &$item) {
    $item['movement_type'] = 'in';
    $item['quantity'] = $item['quantity_available'];
    $item['item_name'] = $item['item_name'];
}

// Get low stock items
$low_stock_query = "SELECT * FROM inventory_items
    WHERE quantity_available <= 10 AND status = 'active'
    ORDER BY quantity_available ASC
    LIMIT 10";
$stmt = $conn->prepare($low_stock_query);
$stmt->execute();
$low_stock_items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

$title = "Inventory Management";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8" style="margin-top: 30px;">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Inventory Management</h1>
                                <p class="text-blue-100 text-lg">Track and manage all school inventory items</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-boxes mr-2"></i>
                                        <?php echo number_format($stats['total_items']); ?> Items
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-cubes mr-2"></i>
                                        <?php echo number_format($stats['total_quantity']); ?> Total Quantity
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-warehouse text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center mb-6">
                    <div></div>
                    <div class="flex space-x-3">
                        <a href="items/" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-boxes mr-2"></i>Manage Items
                        </a>
                        <a href="requests/" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-clipboard-list mr-2"></i>Requests
                        </a>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100">
                                <i class="fas fa-boxes text-blue-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Items</p>
                                <p class="text-2xl font-semibold text-blue-600"><?php echo number_format($stats['total_items']); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100">
                                <i class="fas fa-cubes text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Quantity</p>
                                <p class="text-2xl font-semibold text-green-600"><?php echo number_format($stats['total_quantity']); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Low Stock Items</p>
                                <p class="text-2xl font-semibold text-red-600"><?php echo number_format($stats['low_stock_items']); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-purple-100">
                                <i class="fas fa-dollar-sign text-purple-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Value</p>
                                <p class="text-2xl font-semibold text-purple-600">₵<?php echo number_format($stats['total_value'], 2); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Recent Movements -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h2 class="text-lg font-semibold text-gray-800">Recent Movements</h2>
                            <a href="movements/" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($recent_movements)): ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_movements as $movement): ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 rounded-lg">
                                    <div class="flex items-center space-x-3">
                                        <div class="p-2 rounded-full 
                                            <?php echo $movement['movement_type'] === 'in' ? 'bg-green-100' : 'bg-red-100'; ?>">
                                            <i class="fas fa-<?php echo $movement['movement_type'] === 'in' ? 'arrow-down text-green-600' : 'arrow-up text-red-600'; ?>"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($movement['item_name']); ?></p>
                                            <p class="text-sm text-gray-500">
                                                <?php echo $movement['movement_type'] === 'in' ? '+' : '-'; ?><?php echo $movement['quantity']; ?> units
                                                by <?php echo htmlspecialchars($movement['user_name']); ?>
                                            </p>
                                        </div>
                                    </div>
                                    <span class="text-xs text-gray-500"><?php echo date('M j', strtotime($movement['created_at'])); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-exchange-alt text-gray-400 text-3xl mb-2"></i>
                                <p class="text-gray-500">No recent movements</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Low Stock Alerts -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                            <h2 class="text-lg font-semibold text-gray-800">Low Stock Alerts</h2>
                            <a href="items/?filter=low_stock" class="text-red-600 hover:text-red-800 text-sm">View All</a>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($low_stock_items)): ?>
                            <div class="space-y-4">
                                <?php foreach ($low_stock_items as $item): ?>
                                <div class="flex items-center justify-between p-3 border border-red-200 rounded-lg bg-red-50">
                                    <div class="flex items-center space-x-3">
                                        <div class="p-2 bg-red-100 rounded-full">
                                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></p>
                                            <p class="text-sm text-red-600">
                                                Only <?php echo $item['quantity_available']; ?> left (Low stock alert)
                                            </p>
                                        </div>
                                    </div>
                                    <a href="items/restock.php?id=<?php echo $item['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        Restock
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <i class="fas fa-check-circle text-green-400 text-3xl mb-2"></i>
                                <p class="text-gray-500">All items are well stocked</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="mt-8 bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Quick Actions</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <a href="items/create.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                    <i class="fas fa-plus text-blue-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">Add New Item</div>
                                    <div class="text-sm text-gray-500">Add item to inventory</div>
                                </div>
                            </a>
                            
                            <a href="requests/create.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="p-2 bg-green-100 rounded-lg mr-3">
                                    <i class="fas fa-clipboard-list text-green-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">Create Request</div>
                                    <div class="text-sm text-gray-500">Request inventory items</div>
                                </div>
                            </a>
                            
                            <a href="reports/" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                                <div class="p-2 bg-purple-100 rounded-lg mr-3">
                                    <i class="fas fa-chart-bar text-purple-600"></i>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">View Reports</div>
                                    <div class="text-sm text-gray-500">Inventory analytics</div>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
