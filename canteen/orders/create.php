<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager', 'student'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = $_SESSION['role'] === 'student' ? $_SESSION['user_id'] : filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $delivery_time = filter_input(INPUT_POST, 'delivery_time', FILTER_SANITIZE_STRING);
    $special_instructions = filter_input(INPUT_POST, 'special_instructions', FILTER_SANITIZE_STRING);
    $items = $_POST['items'] ?? [];

    if ($student_id && !empty($items)) {
        try {
            $db->beginTransaction();

            $total_amount = 0;

            // Calculate total amount
            foreach ($items as $item_id => $quantity) {
                if ($quantity > 0) {
                    $price_query = "SELECT price FROM canteen_menu WHERE id = :item_id";
                    $price_stmt = $db->prepare($price_query);
                    $price_stmt->bindParam(':item_id', $item_id);
                    $price_stmt->execute();
                    $price = $price_stmt->fetchColumn();
                    $total_amount += $price * $quantity;
                }
            }

            // Create order
            $order_query = "INSERT INTO canteen_orders (student_id, order_date, total_amount, delivery_time, special_instructions, status, created_at)
                           VALUES (:student_id, CURDATE(), :total_amount, :delivery_time, :special_instructions, 'pending', NOW())";
            $order_stmt = $db->prepare($order_query);
            $order_stmt->bindParam(':student_id', $student_id);
            $order_stmt->bindParam(':total_amount', $total_amount);
            $order_stmt->bindParam(':delivery_time', $delivery_time);
            $order_stmt->bindParam(':special_instructions', $special_instructions);
            $order_stmt->execute();

            $order_id = $db->lastInsertId();

            // Add order items
            foreach ($items as $item_id => $quantity) {
                if ($quantity > 0) {
                    $price_query = "SELECT price FROM canteen_menu WHERE id = :item_id";
                    $price_stmt = $db->prepare($price_query);
                    $price_stmt->bindParam(':item_id', $item_id);
                    $price_stmt->execute();
                    $unit_price = $price_stmt->fetchColumn();
                    $total_price = $unit_price * $quantity;

                    $item_query = "INSERT INTO canteen_order_items (order_id, menu_item_id, quantity, unit_price, total_price)
                                  VALUES (:order_id, :menu_item_id, :quantity, :unit_price, :total_price)";
                    $item_stmt = $db->prepare($item_query);
                    $item_stmt->bindParam(':order_id', $order_id);
                    $item_stmt->bindParam(':menu_item_id', $item_id);
                    $item_stmt->bindParam(':quantity', $quantity);
                    $item_stmt->bindParam(':unit_price', $unit_price);
                    $item_stmt->bindParam(':total_price', $total_price);
                    $item_stmt->execute();
                }
            }

            $db->commit();
            $success = "Order created successfully! Order ID: #" . $order_id;
        } catch (PDOException $e) {
            $db->rollBack();
            $error = "Error creating order: " . $e->getMessage();
        }
    } else {
        $error = "Please select at least one item.";
    }
}

// Get menu items
$menu_query = "SELECT * FROM canteen_menu WHERE status = 'available' ORDER BY category, name";
$menu_stmt = $db->query($menu_query);
$menu_items = $menu_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group items by category
$menu_by_category = [];
foreach ($menu_items as $item) {
    $menu_by_category[$item['category']][] = $item;
}

// Get students (for admin/manager)
if ($_SESSION['role'] !== 'student') {
    $students_query = "SELECT u.id, u.name, sp.student_id FROM users u
                       LEFT JOIN student_profiles sp ON u.id = sp.user_id
                       WHERE u.role = 'student' AND u.status = 'active'
                       ORDER BY u.name";
    $students_stmt = $db->query($students_query);
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Create Canteen Order";
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
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 64px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Canteen Order</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <?php if (isset($success)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- Create Form -->
                <form method="POST" class="space-y-6">
                    <!-- Order Details -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Order Details</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <?php if ($_SESSION['role'] !== 'student'): ?>
                                <div>
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student *</label>
                                    <select id="student_id" name="student_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                                <?php if ($student['student_id']): ?>
                                                    (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <?php endif; ?>

                                <div>
                                    <label for="delivery_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Preferred Delivery Time</label>
                                    <select id="delivery_time" name="delivery_time"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Any time</option>
                                        <option value="08:00">8:00 AM - Breakfast</option>
                                        <option value="10:00">10:00 AM - Break</option>
                                        <option value="12:00">12:00 PM - Lunch</option>
                                        <option value="15:00">3:00 PM - Afternoon Break</option>
                                        <option value="18:00">6:00 PM - Dinner</option>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="special_instructions" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Special Instructions</label>
                                    <textarea id="special_instructions" name="special_instructions" rows="2"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Any special dietary requirements or delivery instructions"></textarea>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Menu Items -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Items</h3>

                            <?php if (!empty($menu_by_category)): ?>
                                <?php foreach ($menu_by_category as $category => $items): ?>
                                <div class="mb-6">
                                    <h4 class="text-md font-medium text-gray-800 dark:text-gray-200 mb-3 capitalize"><?php echo htmlspecialchars($category); ?></h4>
                                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                        <?php foreach ($items as $item): ?>
                                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                                            <div class="flex justify-between items-start mb-2">
                                                <h5 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['name']); ?></h5>
                                                <span class="text-lg font-bold text-green-600">₵<?php echo number_format($item['price'], 2); ?></span>
                                            </div>
                                            <?php if ($item['description']): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-3"><?php echo htmlspecialchars($item['description']); ?></p>
                                            <?php endif; ?>
                                            <div class="flex items-center space-x-2">
                                                <label for="item_<?php echo $item['id']; ?>" class="text-sm font-medium text-gray-700 dark:text-gray-300">Quantity:</label>
                                                <input type="number" id="item_<?php echo $item['id']; ?>" name="items[<?php echo $item['id']; ?>]"
                                                       min="0" max="10" value="0"
                                                       class="w-20 px-2 py-1 border border-gray-300 dark:border-gray-600 rounded-md text-center focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                                       onchange="updateTotal()">
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-utensils text-gray-400 text-4xl mb-4"></i>
                                    <p class="text-gray-500 dark:text-gray-400">No menu items available at the moment.</p>
                                </div>
                            <?php endif; ?>

                            <!-- Order Total -->
                            <div class="border-t border-gray-200 dark:border-gray-600 pt-4">
                                <div class="flex justify-between items-center">
                                    <span class="text-lg font-semibold text-gray-900 dark:text-white">Total Amount:</span>
                                    <span id="total-amount" class="text-2xl font-bold text-green-600">₵0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3">
                        <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-shopping-cart mr-2"></i>Place Order
                        </button>
                    </div>
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
// Menu items data for JavaScript
const menuItems = <?php echo json_encode($menu_items); ?>;

function updateTotal() {
    let total = 0;

    menuItems.forEach(item => {
        const quantityInput = document.getElementById(`item_${item.id}`);
        if (quantityInput) {
            const quantity = parseInt(quantityInput.value) || 0;
            total += quantity * parseFloat(item.price);
        }
    });

    document.getElementById('total-amount').textContent = `₵${total.toFixed(2)}`;
}

// Initialize total calculation
document.addEventListener('DOMContentLoaded', function() {
    updateTotal();
});
</script>