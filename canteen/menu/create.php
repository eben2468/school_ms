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
    $date = isset($_POST['date']) ? htmlspecialchars(trim($_POST['date']), ENT_QUOTES, 'UTF-8') : '';
    $meal_type = isset($_POST['meal_type']) ? htmlspecialchars(trim($_POST['meal_type']), ENT_QUOTES, 'UTF-8') : '';
    $item_name = isset($_POST['item_name']) ? htmlspecialchars(trim($_POST['item_name']), ENT_QUOTES, 'UTF-8') : '';
    $description = isset($_POST['description']) ? htmlspecialchars(trim($_POST['description']), ENT_QUOTES, 'UTF-8') : '';
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $available_quantity = filter_input(INPUT_POST, 'available_quantity', FILTER_VALIDATE_INT);
    $status = isset($_POST['status']) ? htmlspecialchars(trim($_POST['status']), ENT_QUOTES, 'UTF-8') : '';

    if ($date && $meal_type && $item_name && $price !== false && $available_quantity !== false) {
        try {
            $query = "INSERT INTO canteen_menu (date, meal_type, item_name, description, price, available_quantity, status) 
                      VALUES (:date, :meal_type, :item_name, :description, :price, :available_quantity, :status)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':date', $date);
            $stmt->bindParam(':meal_type', $meal_type);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':price', $price);
            $stmt->bindParam(':available_quantity', $available_quantity);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $success_message = "Menu item created successfully!";
            } else {
                $error_message = "Error creating menu item.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields with valid data.";
    }
}

$title = "Create Menu Item";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Create Menu Item</h1>
                                <p class="text-green-100 text-lg">Add a new item to the canteen menu</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-utensils text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Menu</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Create Item</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Menu Item Details</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Fill in the information for the new menu item</p>
                    </div>

                    <form method="POST" class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Date -->
                            <div>
                                <label for="date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="date" name="date" required
                                    value="<?php echo date('Y-m-d'); ?>"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Meal Type -->
                            <div>
                                <label for="meal_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Meal Type <span class="text-red-500">*</span>
                                </label>
                                <select id="meal_type" name="meal_type" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select meal type</option>
                                    <option value="breakfast">Breakfast</option>
                                    <option value="lunch">Lunch</option>
                                    <option value="dinner">Dinner</option>
                                    <option value="snack">Snack</option>
                                </select>
                            </div>
                        </div>

                        <!-- Item Name -->
                        <div>
                            <label for="item_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Item Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="item_name" name="item_name" required
                                placeholder="Enter item name"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <!-- Description -->
                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Description
                            </label>
                            <textarea id="description" name="description" rows="3"
                                placeholder="Enter item description (optional)"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Price -->
                            <div>
                                <label for="price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Price (₵) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="price" name="price" step="0.01" min="0" required
                                    placeholder="0.00"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Available Quantity -->
                            <div>
                                <label for="available_quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Available Quantity <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="available_quantity" name="available_quantity" min="0" required
                                    placeholder="0"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Status -->
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Status <span class="text-red-500">*</span>
                                </label>
                                <select id="status" name="status" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="available">Available</option>
                                    <option value="unavailable">Unavailable</option>
                                </select>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" 
                                class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-save mr-2"></i>
                                Create Menu Item
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Quick Tips -->
                <div class="mt-8 bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
                    <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                        <i class="fas fa-lightbulb mr-2"></i>Quick Tips
                    </h3>
                    <ul class="space-y-2 text-blue-800 dark:text-blue-200">
                        <li class="flex items-start">
                            <i class="fas fa-check text-blue-600 mr-2 mt-1 text-sm"></i>
                            <span>Set realistic available quantities based on your kitchen capacity</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-blue-600 mr-2 mt-1 text-sm"></i>
                            <span>Include detailed descriptions to help students make informed choices</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-blue-600 mr-2 mt-1 text-sm"></i>
                            <span>You can create multiple items for the same meal type and date</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-check text-blue-600 mr-2 mt-1 text-sm"></i>
                            <span>Mark items as unavailable if they're temporarily out of stock</span>
                        </li>
                    </ul>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('item_name').focus();
});

// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const price = parseFloat(document.getElementById('price').value);
    const quantity = parseInt(document.getElementById('available_quantity').value);
    
    if (price < 0) {
        e.preventDefault();
        alert('Price cannot be negative');
        return;
    }
    
    if (quantity < 0) {
        e.preventDefault();
        alert('Available quantity cannot be negative');
        return;
    }
});
</script>
