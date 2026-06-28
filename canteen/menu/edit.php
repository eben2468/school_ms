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

// Get menu item ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header("Location: index.php");
    exit();
}

// Fetch item details
$query = "SELECT * FROM canteen_menu WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = isset($_POST['date']) ? trim($_POST['date']) : '';
    $meal_type = isset($_POST['meal_type']) ? trim($_POST['meal_type']) : '';
    $item_name = isset($_POST['item_name']) ? trim($_POST['item_name']) : '';
    $description = isset($_POST['description']) ? trim($_POST['description']) : '';
    $price = filter_input(INPUT_POST, 'price', FILTER_VALIDATE_FLOAT);
    $available_quantity = filter_input(INPUT_POST, 'available_quantity', FILTER_VALIDATE_INT);
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';

    if ($date && $meal_type && $item_name && $price !== false && $available_quantity !== false && $status) {
        try {
            $update_query = "UPDATE canteen_menu 
                             SET date = :date, meal_type = :meal_type, item_name = :item_name, 
                                 description = :description, price = :price, 
                                 available_quantity = :available_quantity, status = :status 
                             WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':date', $date);
            $update_stmt->bindParam(':meal_type', $meal_type);
            $update_stmt->bindParam(':item_name', $item_name);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':price', $price);
            $update_stmt->bindParam(':available_quantity', $available_quantity, PDO::PARAM_INT);
            $update_stmt->bindParam(':status', $status);
            $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);

            if ($update_stmt->execute()) {
                $success_message = "Menu item updated successfully!";
                // Refresh item data
                $item['date'] = $date;
                $item['meal_type'] = $meal_type;
                $item['item_name'] = $item_name;
                $item['description'] = $description;
                $item['price'] = $price;
                $item['available_quantity'] = $available_quantity;
                $item['status'] = $status;
            } else {
                $error_message = "Error updating menu item.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields with valid data.";
    }
}

$title = "Edit Menu Item";
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
                                <h1 class="text-3xl font-bold mb-2">Edit Menu Item</h1>
                                <p class="text-green-100 text-lg">Modify canteen menu item details</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-edit text-6xl text-white/80"></i>
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
                    <span class="text-gray-900 dark:text-white font-medium">Edit Item</span>
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

                <!-- Edit Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Menu Item Details</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Update the information for this menu item</p>
                    </div>

                    <form method="POST" class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Date -->
                            <div>
                                <label for="date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="date" name="date" required
                                    value="<?php echo htmlspecialchars($item['date']); ?>"
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
                                    <option value="breakfast" <?php echo $item['meal_type'] === 'breakfast' ? 'selected' : ''; ?>>Breakfast</option>
                                    <option value="lunch" <?php echo $item['meal_type'] === 'lunch' ? 'selected' : ''; ?>>Lunch</option>
                                    <option value="dinner" <?php echo $item['meal_type'] === 'dinner' ? 'selected' : ''; ?>>Dinner</option>
                                    <option value="snack" <?php echo $item['meal_type'] === 'snack' ? 'selected' : ''; ?>>Snack</option>
                                </select>
                            </div>
                        </div>

                        <!-- Item Name -->
                        <div>
                            <label for="item_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Item Name <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="item_name" name="item_name" required
                                value="<?php echo htmlspecialchars($item['item_name']); ?>"
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
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($item['description']); ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Price -->
                            <div>
                                <label for="price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Price (₵) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="price" name="price" step="0.01" min="0" required
                                    value="<?php echo htmlspecialchars($item['price']); ?>"
                                    placeholder="0.00"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Available Quantity -->
                            <div>
                                <label for="available_quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Available Quantity <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="available_quantity" name="available_quantity" min="0" required
                                    value="<?php echo htmlspecialchars($item['available_quantity']); ?>"
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
                                    <option value="available" <?php echo $item['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="unavailable" <?php echo $item['status'] === 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
                                </select>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" 
                                class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Menu
                            </a>
                            <button type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-save mr-2"></i>
                                Update Menu Item
                            </button>
                        </div>
                    </form>
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
