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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $staff_id = $_SESSION['user_id'];
    $special_instructions = isset($_POST['special_instructions']) ? trim($_POST['special_instructions']) : '';
    $items = $_POST['items'] ?? [];

    if (!empty($items)) {
        try {
            $db->beginTransaction();
            $orders_created = 0;

            foreach ($items as $menu_id => $quantity) {
                $quantity = intval($quantity);
                if ($quantity > 0) {
                    // Get menu item price
                    $price_query = "SELECT price, item_name, available_quantity FROM canteen_menu WHERE id = :menu_id AND status = 'available'";
                    $price_stmt = $db->prepare($price_query);
                    $price_stmt->bindParam(':menu_id', $menu_id, PDO::PARAM_INT);
                    $price_stmt->execute();
                    $menu_item = $price_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($menu_item) {
                        // Check available quantity
                        if ($menu_item['available_quantity'] < $quantity) {
                            throw new Exception("Not enough stock for " . $menu_item['item_name'] . ". Available: " . $menu_item['available_quantity']);
                        }

                        $total_price = $menu_item['price'] * $quantity;

                        // Insert order into canteen_orders
                        $order_query = "INSERT INTO canteen_orders (staff_id, menu_id, quantity, total_price, order_date, order_time, status, created_at)
                                       VALUES (:staff_id, :menu_id, :quantity, :total_price, CURDATE(), CURTIME(), 'pending', NOW())";
                        $order_stmt = $db->prepare($order_query);
                        $order_stmt->bindParam(':staff_id', $staff_id, PDO::PARAM_INT);
                        $order_stmt->bindParam(':menu_id', $menu_id, PDO::PARAM_INT);
                        $order_stmt->bindParam(':quantity', $quantity, PDO::PARAM_INT);
                        $order_stmt->bindParam(':total_price', $total_price);
                        $order_stmt->execute();

                        // Decrease available quantity
                        $update_qty = "UPDATE canteen_menu SET available_quantity = available_quantity - :qty WHERE id = :menu_id";
                        $update_stmt = $db->prepare($update_qty);
                        $update_stmt->bindParam(':qty', $quantity, PDO::PARAM_INT);
                        $update_stmt->bindParam(':menu_id', $menu_id, PDO::PARAM_INT);
                        $update_stmt->execute();

                        $orders_created++;
                    }
                }
            }

            if ($orders_created > 0) {
                $db->commit();
                $success = "Order placed successfully! $orders_created item(s) ordered.";
            } else {
                $db->rollBack();
                $error = "Please select at least one item with quantity greater than 0.";
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating order: " . $e->getMessage();
        }
    } else {
        $error = "Please select at least one item.";
    }
}

// Get all available menu items (the persistent menu) grouped by meal_type.
// The menu is no longer tied to a specific date, so any available, in-stock
// item can be ordered.
$menu_query = "SELECT * FROM canteen_menu WHERE status = 'available' AND available_quantity > 0 ORDER BY meal_type, item_name";
$menu_stmt = $db->prepare($menu_query);
$menu_stmt->execute();
$menu_items = $menu_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group items by meal_type
$menu_by_type = [];
$meal_type_labels = [
    'breakfast' => 'Breakfast',
    'lunch' => 'Lunch',
    'dinner' => 'Dinner',
    'snack' => 'Snack'
];
foreach ($menu_items as $item) {
    $menu_by_type[$item['meal_type']][] = $item;
}

$title = "Create Order";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Canteen', 'url' => '../index.php'],
    ['title' => 'Orders', 'url' => 'index.php'],
    ['title' => 'Create Order']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Order</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Place a new canteen order from the available menu</p>
                    </div>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <!-- Navigation breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Orders</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Create Order</span>
                </div>

                <?php if (isset($success)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create Order Form -->
                <form method="POST" class="space-y-6" id="orderForm">
                    <!-- Menu Items -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Available Menu</h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Select items and specify quantities</p>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($menu_by_type)): ?>
                                <?php foreach ($menu_by_type as $meal_type => $items): ?>
                                <div class="mb-8 last:mb-0">
                                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-4 flex items-center">
                                        <?php
                                        $meal_icons = [
                                            'breakfast' => 'fa-coffee',
                                            'lunch' => 'fa-hamburger',
                                            'dinner' => 'fa-moon',
                                            'snack' => 'fa-cookie-bite'
                                        ];
                                        $icon = $meal_icons[$meal_type] ?? 'fa-utensils';
                                        ?>
                                        <i class="fas <?php echo $icon; ?> mr-2 text-blue-500"></i>
                                        <?php echo $meal_type_labels[$meal_type] ?? ucfirst($meal_type); ?>
                                        <span class="ml-2 text-sm font-normal text-gray-500">(<?php echo count($items); ?> items)</span>
                                    </h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <?php foreach ($items as $item): ?>
                                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 hover:border-blue-300 dark:hover:border-blue-500 transition-colors">
                                            <div class="flex justify-between items-start mb-2">
                                                <h5 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['item_name']); ?></h5>
                                                <span class="text-lg font-bold text-green-600">₵<?php echo number_format($item['price'], 2); ?></span>
                                            </div>
                                            <?php if ($item['description']): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3"><?php echo htmlspecialchars($item['description']); ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center justify-between">
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    <i class="fas fa-box mr-1"></i>Stock: <?php echo $item['available_quantity']; ?>
                                                </span>
                                                <div class="flex items-center space-x-2">
                                                    <label for="item_<?php echo $item['id']; ?>" class="text-sm font-medium text-gray-700 dark:text-gray-300">Qty:</label>
                                                    <input type="number" id="item_<?php echo $item['id']; ?>" name="items[<?php echo $item['id']; ?>]"
                                                           min="0" max="<?php echo $item['available_quantity']; ?>" value="0"
                                                           data-price="<?php echo $item['price']; ?>"
                                                           class="w-20 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-md text-center focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white item-quantity"
                                                           onchange="updateTotal()">
                                                </div>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-12">
                                    <i class="fas fa-utensils text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Menu Items Available</h3>
                                    <p class="text-gray-500 dark:text-gray-400 mb-4">No available menu items in stock yet.</p>
                                    <a href="../menu/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors">
                                        <i class="fas fa-plus mr-2"></i>Add Menu Items
                                    </a>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($menu_by_type)): ?>
                            <!-- Order Summary -->
                            <div class="border-t border-gray-200 dark:border-gray-600 pt-6 mt-6">
                                <div class="flex justify-between items-center">
                                    <div>
                                        <span class="text-lg font-semibold text-gray-900 dark:text-white">Order Total:</span>
                                        <p class="text-sm text-gray-500 dark:text-gray-400" id="items-count">0 items selected</p>
                                    </div>
                                    <span id="total-amount" class="text-3xl font-bold text-green-600">₵0.00</span>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($menu_by_type)): ?>
                    <!-- Special Instructions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6">
                            <label for="special_instructions" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-sticky-note mr-1"></i> Special Instructions (Optional)
                            </label>
                            <textarea id="special_instructions" name="special_instructions" rows="2"
                                      class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                      placeholder="Any special dietary requirements or notes..."></textarea>
                        </div>
                    </div>

                    <!-- Form Actions -->
                    <div class="flex justify-end space-x-3">
                        <a href="index.php" class="inline-flex items-center px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                            Cancel
                        </a>
                        <button type="submit" id="submitBtn" class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors shadow-lg hover:shadow-xl disabled:opacity-50 disabled:cursor-not-allowed" disabled>
                            <i class="fas fa-shopping-cart mr-2"></i>Place Order
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function updateTotal() {
    let total = 0;
    let itemCount = 0;
    const inputs = document.querySelectorAll('.item-quantity');

    inputs.forEach(input => {
        const quantity = parseInt(input.value) || 0;
        const price = parseFloat(input.dataset.price) || 0;
        if (quantity > 0) {
            total += quantity * price;
            itemCount++;
        }
    });

    document.getElementById('total-amount').textContent = `₵${total.toFixed(2)}`;
    document.getElementById('items-count').textContent = `${itemCount} item(s) selected`;

    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = itemCount === 0;
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    updateTotal();
});
</script>