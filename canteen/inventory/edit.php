<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get inventory item ID
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header("Location: index.php");
    exit();
}

// Fetch item details
$query = "SELECT * FROM canteen_inventory WHERE id = :id";
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
    $item_name = isset($_POST['item_name']) ? trim($_POST['item_name']) : '';
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $unit = isset($_POST['unit']) ? trim($_POST['unit']) : '';
    $unit_price = filter_input(INPUT_POST, 'unit_price', FILTER_VALIDATE_FLOAT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_FLOAT);
    $reorder_level = filter_input(INPUT_POST, 'minimum_stock', FILTER_VALIDATE_FLOAT);
    $supplier = isset($_POST['supplier']) ? trim($_POST['supplier']) : '';
    $expiry_date = isset($_POST['expiry_date']) ? trim($_POST['expiry_date']) : null;
    if ($expiry_date === '') {
        $expiry_date = null;
    }
    
    if ($item_name && $category && $unit && $unit_price !== false) {
        try {
            $update_query = "UPDATE canteen_inventory 
                             SET item_name = :item_name, category = :category, quantity = :quantity, 
                                 unit = :unit, unit_price = :unit_price, cost_per_unit = :cost_per_unit, 
                                 reorder_level = :reorder_level, expiry_date = :expiry_date, supplier = :supplier 
                             WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':item_name', $item_name);
            $update_stmt->bindParam(':category', $category);
            $update_stmt->bindParam(':quantity', $quantity);
            $update_stmt->bindParam(':unit', $unit);
            $update_stmt->bindParam(':unit_price', $unit_price);
            $update_stmt->bindParam(':cost_per_unit', $unit_price); // Sync both price fields
            $update_stmt->bindParam(':reorder_level', $reorder_level);
            $update_stmt->bindParam(':expiry_date', $expiry_date);
            $update_stmt->bindParam(':supplier', $supplier);
            $update_stmt->bindParam(':id', $id, PDO::PARAM_INT);
            
            if ($update_stmt->execute()) {
                $success = "Inventory item updated successfully!";
                // Refresh data
                $item['item_name'] = $item_name;
                $item['category'] = $category;
                $item['quantity'] = $quantity;
                $item['unit'] = $unit;
                $item['unit_price'] = $unit_price;
                $item['reorder_level'] = $reorder_level;
                $item['expiry_date'] = $expiry_date;
                $item['supplier'] = $supplier;
            } else {
                $error = "Error updating item.";
            }
        } catch (PDOException $e) {
            $error = "Error updating item: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields with valid data.";
    }
}

$title = "Edit Inventory Item";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Canteen', 'url' => '../index.php'],
    ['title' => 'Inventory', 'url' => 'index.php'],
    ['title' => 'Edit Item']
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
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Inventory Item</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Modify details for this inventory item</p>
                    </div>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <!-- Navigation breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Inventory</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Edit Item</span>
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

                <!-- Edit Item Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Item Details</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Modify the fields below to update the item</p>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="item_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Item Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="item_name" name="item_name" required
                                           value="<?php echo htmlspecialchars($item['item_name']); ?>"
                                           placeholder="Enter item name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Category <span class="text-red-500">*</span>
                                    </label>
                                    <select id="category" name="category" required
                                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Category</option>
                                        <option value="vegetables" <?php echo $item['category'] === 'vegetables' ? 'selected' : ''; ?>>Vegetables</option>
                                        <option value="fruits" <?php echo $item['category'] === 'fruits' ? 'selected' : ''; ?>>Fruits</option>
                                        <option value="grains" <?php echo $item['category'] === 'grains' ? 'selected' : ''; ?>>Grains & Cereals</option>
                                        <option value="dairy" <?php echo $item['category'] === 'dairy' ? 'selected' : ''; ?>>Dairy Products</option>
                                        <option value="meat" <?php echo $item['category'] === 'meat' ? 'selected' : ''; ?>>Meat & Poultry</option>
                                        <option value="spices" <?php echo $item['category'] === 'spices' ? 'selected' : ''; ?>>Spices & Condiments</option>
                                        <option value="beverages" <?php echo $item['category'] === 'beverages' ? 'selected' : ''; ?>>Beverages</option>
                                        <option value="other" <?php echo $item['category'] === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="unit" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Unit <span class="text-red-500">*</span>
                                    </label>
                                    <select id="unit" name="unit" required
                                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Unit</option>
                                        <option value="kg" <?php echo $item['unit'] === 'kg' ? 'selected' : ''; ?>>Kilogram (kg)</option>
                                        <option value="g" <?php echo $item['unit'] === 'g' ? 'selected' : ''; ?>>Gram (g)</option>
                                        <option value="l" <?php echo $item['unit'] === 'l' ? 'selected' : ''; ?>>Liter (l)</option>
                                        <option value="ml" <?php echo $item['unit'] === 'ml' ? 'selected' : ''; ?>>Milliliter (ml)</option>
                                        <option value="pieces" <?php echo $item['unit'] === 'pieces' ? 'selected' : ''; ?>>Pieces</option>
                                        <option value="packets" <?php echo $item['unit'] === 'packets' ? 'selected' : ''; ?>>Packets</option>
                                        <option value="boxes" <?php echo $item['unit'] === 'boxes' ? 'selected' : ''; ?>>Boxes</option>
                                        <option value="bottles" <?php echo $item['unit'] === 'bottles' ? 'selected' : ''; ?>>Bottles</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="unit_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Unit Price (₵) <span class="text-red-500">*</span>
                                    </label>
                                    <input type="number" id="unit_price" name="unit_price" step="0.01" min="0" required
                                           value="<?php echo htmlspecialchars($item['unit_price']); ?>"
                                           placeholder="0.00"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Current Stock Quantity
                                    </label>
                                    <input type="number" id="quantity" name="quantity" step="0.01" min="0" 
                                           value="<?php echo htmlspecialchars($item['quantity']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="minimum_stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Minimum Stock Level
                                    </label>
                                    <input type="number" id="minimum_stock" name="minimum_stock" step="0.01" min="0" 
                                           value="<?php echo htmlspecialchars($item['reorder_level']); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="expiry_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Expiry Date
                                    </label>
                                    <input type="date" id="expiry_date" name="expiry_date"
                                           value="<?php echo htmlspecialchars($item['expiry_date'] ?? ''); ?>"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="supplier" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Supplier
                                    </label>
                                    <input type="text" id="supplier" name="supplier"
                                           value="<?php echo htmlspecialchars($item['supplier'] ?? ''); ?>"
                                           placeholder="Enter supplier name"
                                           class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                                <a href="index.php" 
                                    class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                    <i class="fas fa-arrow-left mr-2"></i>
                                    Back to Inventory
                                </a>
                                <button type="submit"
                                    class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                    <i class="fas fa-save mr-2"></i>
                                    Update Item
                                </button>
                            </div>
                        </form>
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
