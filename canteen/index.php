<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Get canteen statistics
$stats = [
    'total_menu_items' => 0,
    'daily_orders' => 0,
    'registered_students' => 0,
    'monthly_revenue' => 0
];

// Get total menu items for today
$menu_query = "SELECT COUNT(*) as count FROM canteen_menu WHERE date = CURDATE() AND status = 'available'";
$menu_stmt = $db->prepare($menu_query);
$menu_stmt->execute();
$stats['total_menu_items'] = $menu_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get daily orders
$orders_query = "SELECT COUNT(*) as count FROM canteen_orders WHERE order_date = CURDATE()";
$orders_stmt = $db->prepare($orders_query);
$orders_stmt->execute();
$stats['daily_orders'] = $orders_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get registered students
$students_query = "SELECT COUNT(*) as count FROM canteen_registrations WHERE status = 'active' AND end_date >= CURDATE()";
$students_stmt = $db->prepare($students_query);
$students_stmt->execute();
$stats['registered_students'] = $students_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get monthly revenue
$revenue_query = "SELECT SUM(total_price) as total FROM canteen_orders WHERE MONTH(order_date) = MONTH(CURDATE()) AND YEAR(order_date) = YEAR(CURDATE())";
$revenue_stmt = $db->prepare($revenue_query);
$revenue_stmt->execute();
$revenue_result = $revenue_stmt->fetch(PDO::FETCH_ASSOC);
$stats['monthly_revenue'] = $revenue_result['total'] ?? 0;

// Get today's menu
$todays_menu_query = "
    SELECT cm.*, COUNT(co.id) as order_count
    FROM canteen_menu cm
    LEFT JOIN canteen_orders co ON cm.id = co.menu_id AND co.order_date = CURDATE()
    WHERE cm.date = CURDATE() AND cm.status = 'available'
    GROUP BY cm.id
    ORDER BY cm.meal_type, cm.item_name
";
$todays_menu_stmt = $db->prepare($todays_menu_query);
$todays_menu_stmt->execute();
$todays_menu = $todays_menu_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent orders
$recent_orders_query = "
    SELECT co.*, cm.item_name, cm.meal_type, u.name as staff_name
    FROM canteen_orders co
    JOIN canteen_menu cm ON co.menu_id = cm.id
    JOIN users u ON co.staff_id = u.id
    ORDER BY co.created_at DESC
    LIMIT 10
";
$recent_orders_stmt = $db->prepare($recent_orders_query);
$recent_orders_stmt->execute();
$recent_orders = $recent_orders_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Canteen Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Canteen Management']
];
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
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2 text-white">Canteen Management</h1>
                                <p class="text-blue-100 text-lg">Manage daily menus, orders, and canteen operations</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-utensils mr-2"></i>
                                        <?php echo number_format($stats['total_menu_items']); ?> Menu Items Today
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-shopping-cart mr-2"></i>
                                        <?php echo number_format($stats['daily_orders']); ?> Orders Today
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-utensils text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex space-x-3">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'canteen_manager'])): ?>
                        <a href="menu/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-plus mr-2"></i>Add Menu Item
                        </a>
                        <a href="orders/create.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-shopping-cart mr-2"></i>New Order
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportCanteenData()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Today's Menu Items -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Today's Menu Items</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_menu_items']); ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-utensils mr-1"></i>
                                    Available items
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-utensils text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Daily Orders -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Daily Orders</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['daily_orders']); ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-shopping-cart mr-1"></i>
                                    Orders today
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-shopping-cart text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Registered Students -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Registered Students</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['registered_students']); ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-user-graduate mr-1"></i>
                                    Active registrations
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-graduate text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Monthly Revenue -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Monthly Revenue</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400">₵<?php echo number_format($stats['monthly_revenue'], 2); ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-dollar-sign mr-1"></i>
                                    This month
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-dollar-sign text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Quick Access Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <!-- Menu Management -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Menu Management</h3>
                            <a href="menu/create.php" class="text-blue-500 hover:text-blue-600">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Manage daily menus and meal planning.</p>
                        <a href="menu/index.php" class="inline-flex items-center text-blue-500 hover:text-blue-600">
                            <span>Manage Menu</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Orders Management -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Orders</h3>
                            <a href="orders/create.php" class="text-green-500 hover:text-green-600">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Process and manage meal orders.</p>
                        <a href="orders/index.php" class="inline-flex items-center text-green-500 hover:text-green-600">
                            <span>Manage Orders</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Student Registrations -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Registrations</h3>
                            <a href="registrations/create.php" class="text-purple-500 hover:text-purple-600">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Manage student meal plan registrations.</p>
                        <a href="registrations/index.php" class="inline-flex items-center text-purple-500 hover:text-purple-600">
                            <span>Manage Registrations</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Inventory Management -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Inventory</h3>
                            <a href="inventory/create.php" class="text-orange-500 hover:text-orange-600">
                                <i class="fas fa-plus"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Track food inventory and supplies.</p>
                        <a href="inventory/index.php" class="inline-flex items-center text-orange-500 hover:text-orange-600">
                            <span>Manage Inventory</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Reports -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Reports</h3>
                            <a href="reports/generate.php" class="text-indigo-500 hover:text-indigo-600">
                                <i class="fas fa-chart-bar"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Generate sales and usage reports.</p>
                        <a href="reports/index.php" class="inline-flex items-center text-indigo-500 hover:text-indigo-600">
                            <span>View Reports</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Settings -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-semibold text-gray-800">Settings</h3>
                            <a href="settings/index.php" class="text-gray-500 hover:text-gray-600">
                                <i class="fas fa-cog"></i>
                            </a>
                        </div>
                        <p class="text-gray-600 mb-4">Configure canteen settings and preferences.</p>
                        <a href="settings/index.php" class="inline-flex items-center text-gray-500 hover:text-gray-600">
                            <span>Manage Settings</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Today's Menu -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Today's Menu</h2>
                    </div>
                    <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                        <?php if (!empty($todays_menu)): ?>
                            <?php 
                            $meal_types = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'snack' => 'Snack'];
                            $current_meal_type = '';
                            ?>
                            <?php foreach ($todays_menu as $item): ?>
                                <?php if ($current_meal_type !== $item['meal_type']): ?>
                                    <?php $current_meal_type = $item['meal_type']; ?>
                                    <div class="px-6 py-3 bg-gray-50">
                                        <h3 class="text-sm font-medium text-gray-800"><?php echo $meal_types[$current_meal_type] ?? ucfirst($current_meal_type); ?></h3>
                                    </div>
                                <?php endif; ?>
                                <div class="p-6">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1">
                                            <h4 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></h4>
                                            <?php if ($item['description']): ?>
                                            <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($item['description']); ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center mt-2">
                                                <span class="text-sm font-medium text-green-600">₵<?php echo number_format($item['price'], 2); ?></span>
                                                <span class="ml-4 text-xs text-gray-500"><?php echo $item['order_count']; ?> orders</span>
                                            </div>
                                        </div>
                                        <div class="ml-4">
                                            <?php if ($item['available_quantity'] > 0): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                                Available
                                            </span>
                                            <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                Sold Out
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-utensils text-4xl mb-2"></i>
                            <p>No menu items for today. <a href="menu/create.php" class="text-blue-600 hover:text-blue-800">Add menu items</a></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Orders -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Orders</h2>
                    </div>
                    <div class="divide-y divide-gray-200 max-h-96 overflow-y-auto">
                        <?php if (!empty($recent_orders)): ?>
                            <?php foreach ($recent_orders as $order): ?>
                            <div class="p-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center">
                                            <h4 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['item_name']); ?></h4>
                                            <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo ucfirst($order['meal_type']); ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-600 mt-1">
                                            Ordered by: <?php echo htmlspecialchars($order['staff_name']); ?>
                                        </p>
                                        <div class="flex items-center mt-2">
                                            <span class="text-sm text-gray-500">Qty: <?php echo $order['quantity']; ?></span>
                                            <span class="ml-4 text-sm font-medium text-green-600">₵<?php echo number_format($order['total_price'], 2); ?></span>
                                        </div>
                                    </div>
                                    <div class="ml-4">
                                        <?php
                                        $status_classes = [
                                            'pending' => 'bg-yellow-100 text-yellow-800',
                                            'confirmed' => 'bg-blue-100 text-blue-800',
                                            'served' => 'bg-green-100 text-green-800',
                                            'cancelled' => 'bg-red-100 text-red-800'
                                        ];
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$order['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo ucfirst($order['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-shopping-cart text-4xl mb-2"></i>
                            <p>No recent orders found.</p>
                        </div>
                        <?php endif; ?>
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
