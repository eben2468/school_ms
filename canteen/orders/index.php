<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle order status update
if (isset($_POST['update_status']) && isset($_POST['order_id']) && isset($_POST['status'])) {
    $order_id = filter_input(INPUT_POST, 'order_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE canteen_orders SET status = :status WHERE id = :order_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':order_id', $order_id);
    
    if ($stmt->execute()) {
        $success_message = "Order status updated successfully!";
    } else {
        $error_message = "Error updating order status.";
    }
}

// Get filter parameters
$date_filter = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$meal_type_filter = isset($_GET['meal_type']) ? $_GET['meal_type'] : '';

// Build where conditions
$where_conditions = ["co.order_date = :date"];
$params = [':date' => $date_filter];

if ($status_filter) {
    $where_conditions[] = "co.status = :status";
    $params[':status'] = $status_filter;
}

if ($meal_type_filter) {
    $where_conditions[] = "cm.meal_type = :meal_type";
    $params[':meal_type'] = $meal_type_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch orders
$query = "SELECT co.*, cm.item_name, cm.meal_type, cm.price, u.name as staff_name
          FROM canteen_orders co
          JOIN canteen_menu cm ON co.menu_id = cm.id
          JOIN users u ON co.staff_id = u.id
          $where_clause
          ORDER BY co.order_time DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get order statistics for the selected date
$stats_query = "SELECT 
    COUNT(*) as total_orders,
    SUM(total_price) as total_revenue,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_orders,
    COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as confirmed_orders,
    COUNT(CASE WHEN status = 'served' THEN 1 END) as served_orders
    FROM canteen_orders 
    WHERE order_date = :date";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':date', $date_filter);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Orders Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Orders Management</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Canteen
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>New Order
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Orders</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_orders']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-shopping-cart text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Revenue</p>
                            <p class="text-2xl font-bold text-green-600">₵<?php echo number_format($stats['total_revenue'], 2); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Orders</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['pending_orders']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Served Orders</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['served_orders']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date</label>
                            <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($date_filter); ?>" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="confirmed" <?php echo $status_filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                                <option value="served" <?php echo $status_filter === 'served' ? 'selected' : ''; ?>>Served</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
                        </div>
                        <div class="w-48">
                            <label for="meal_type" class="block text-sm font-medium text-gray-700 mb-1">Meal Type</label>
                            <select name="meal_type" id="meal_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Meals</option>
                                <option value="breakfast" <?php echo $meal_type_filter === 'breakfast' ? 'selected' : ''; ?>>Breakfast</option>
                                <option value="lunch" <?php echo $meal_type_filter === 'lunch' ? 'selected' : ''; ?>>Lunch</option>
                                <option value="dinner" <?php echo $meal_type_filter === 'dinner' ? 'selected' : ''; ?>>Dinner</option>
                                <option value="snack" <?php echo $meal_type_filter === 'snack' ? 'selected' : ''; ?>>Snack</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Orders Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Order Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($orders as $order): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['item_name']); ?></div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo ucfirst($order['meal_type']); ?> • Qty: <?php echo $order['quantity']; ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo date('g:i A', strtotime($order['order_time'])); ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($order['staff_name']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">₵<?php echo number_format($order['total_price'], 2); ?></div>
                                    <div class="text-xs text-gray-500">₵<?php echo number_format($order['price'], 2); ?> each</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
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
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <?php if ($order['status'] === 'pending'): ?>
                                    <form action="" method="POST" class="inline mr-2">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <input type="hidden" name="status" value="confirmed">
                                        <button type="submit" name="update_status" class="text-blue-600 hover:text-blue-900">Confirm</button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($order['status'] === 'confirmed'): ?>
                                    <form action="" method="POST" class="inline mr-2">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <input type="hidden" name="status" value="served">
                                        <button type="submit" name="update_status" class="text-green-600 hover:text-green-900">Mark Served</button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
                                        <input type="hidden" name="status" value="cancelled">
                                        <button type="submit" name="update_status" class="text-red-600 hover:text-red-900" 
                                                onclick="return confirm('Are you sure you want to cancel this order?')">Cancel</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($orders)): ?>
            <div class="text-center py-12">
                <i class="fas fa-shopping-cart text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No orders found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($date_filter || $status_filter || $meal_type_filter): ?>
                        Try adjusting your filters or select a different date.
                    <?php else: ?>
                        No orders have been placed yet.
                    <?php endif; ?>
                </p>
            </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
