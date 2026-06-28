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

$success = '';
$error = '';

// Fetch existing item details
$item_stmt = $db->prepare("SELECT * FROM inventory_items WHERE id = :id AND status != 'discontinued'");
$item_stmt->execute([':id' => $id]);
$item = $item_stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_name = filter_input(INPUT_POST, 'item_name', FILTER_SANITIZE_STRING);
    $item_code = filter_input(INPUT_POST, 'item_code', FILTER_SANITIZE_STRING);
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_SANITIZE_NUMBER_INT);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $unit = filter_input(INPUT_POST, 'unit', FILTER_SANITIZE_STRING) ?: 'pcs';
    $unit_price = filter_input(INPUT_POST, 'unit_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $quantity_available = filter_input(INPUT_POST, 'quantity_available', FILTER_SANITIZE_NUMBER_INT) ?: 0;
    $minimum_stock_level = filter_input(INPUT_POST, 'minimum_stock_level', FILTER_SANITIZE_NUMBER_INT) ?: 0;
    $supplier = filter_input(INPUT_POST, 'supplier', FILTER_SANITIZE_STRING);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

    if ($item_name && $item_code && $category_id && $unit_price !== false && $status) {
        try {
            $db->beginTransaction();

            // Check if item code is unique elsewhere
            $check_code = $db->prepare("SELECT id FROM inventory_items WHERE item_code = :code AND id != :id AND status != 'discontinued'");
            $check_code->execute([':code' => $item_code, ':id' => $id]);
            if ($check_code->rowCount() > 0) {
                throw new Exception("An item with code '{$item_code}' already exists.");
            }

            // Calculate quantity difference for movements
            $qty_diff = $quantity_available - $item['quantity_available'];

            $query = "UPDATE inventory_items SET 
                        category_id = :category_id, 
                        item_name = :item_name, 
                        item_code = :item_code, 
                        description = :description, 
                        quantity_available = :quantity_available, 
                        minimum_stock_level = :minimum_stock_level, 
                        unit_price = :unit_price, 
                        location = :location, 
                        status = :status, 
                        unit = :unit, 
                        supplier = :supplier 
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':category_id', $category_id);
            $stmt->bindParam(':item_name', $item_name);
            $stmt->bindParam(':item_code', $item_code);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':quantity_available', $quantity_available);
            $stmt->bindParam(':minimum_stock_level', $minimum_stock_level);
            $stmt->bindParam(':unit_price', $unit_price);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':unit', $unit);
            $stmt->bindParam(':supplier', $supplier);
            $stmt->bindParam(':id', $id);
            $stmt->execute();

            // Log movement if quantity changed
            if ($qty_diff != 0) {
                $movement_type = $qty_diff > 0 ? 'in' : 'out';
                $move_qty = abs($qty_diff);
                
                $move_stmt = $db->prepare("INSERT INTO inventory_movements (item_id, user_id, movement_type, quantity, reference_type, notes) 
                                          VALUES (:item_id, :user_id, :movement_type, :quantity, 'adjustment', 'Stock level adjusted on edit')");
                $move_stmt->execute([
                    ':item_id' => $id,
                    ':user_id' => $_SESSION['user_id'],
                    ':movement_type' => $movement_type,
                    ':quantity' => $move_qty
                ]);
            }

            $db->commit();
            $success = "Inventory item updated successfully!";
            
            // Refresh local item details
            $item['item_name'] = $item_name;
            $item['item_code'] = $item_code;
            $item['category_id'] = $category_id;
            $item['description'] = $description;
            $item['quantity_available'] = $quantity_available;
            $item['minimum_stock_level'] = $minimum_stock_level;
            $item['unit_price'] = $unit_price;
            $item['location'] = $location;
            $item['status'] = $status;
            $item['unit'] = $unit;
            $item['supplier'] = $supplier;

        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error updating item: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Fetch categories dynamically
$categories_stmt = $db->query("SELECT id, name FROM inventory_categories ORDER BY name");
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Edit Inventory Item";
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
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Inventory Item: <?php echo htmlspecialchars($item['item_name']); ?></h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- Edit Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="item_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item Name *</label>
                                    <input type="text" id="item_name" name="item_name" required value="<?php echo htmlspecialchars($item['item_name']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="category_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category *</label>
                                    <select id="category_id" name="category_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                            <option value="<?php echo $cat['id']; ?>" <?php echo $item['category_id'] == $cat['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cat['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="item_code" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item SKU/Code *</label>
                                    <input type="text" id="item_code" name="item_code" required value="<?php echo htmlspecialchars($item['item_code']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="unit" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit *</label>
                                    <input type="text" id="unit" name="unit" required value="<?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="unit_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit Price (₵) *</label>
                                    <input type="number" id="unit_price" name="unit_price" step="0.01" min="0" required value="<?php echo htmlspecialchars($item['unit_price']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="quantity_available" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Stock</label>
                                    <input type="number" id="quantity_available" name="quantity_available" min="0" value="<?php echo htmlspecialchars($item['quantity_available']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="minimum_stock_level" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Minimum Stock Level</label>
                                    <input type="number" id="minimum_stock_level" name="minimum_stock_level" min="0" value="<?php echo htmlspecialchars($item['minimum_stock_level'] ?? 0); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status *</label>
                                    <select id="status" name="status" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="available" <?php echo $item['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                        <option value="out_of_stock" <?php echo $item['status'] === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="supplier" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Supplier</label>
                                    <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($item['supplier'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Storage Location</label>
                                    <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($item['location'] ?? ''); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="md:col-span-2">
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea id="description" name="description" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($item['description'] ?? ''); ?></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Save Changes
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
