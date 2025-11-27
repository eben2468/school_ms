<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $unit = filter_input(INPUT_POST, 'unit', FILTER_SANITIZE_STRING);
    $cost_per_unit = filter_input(INPUT_POST, 'cost_per_unit', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $current_stock = filter_input(INPUT_POST, 'current_stock', FILTER_SANITIZE_NUMBER_INT);
    $minimum_stock = filter_input(INPUT_POST, 'minimum_stock', FILTER_SANITIZE_NUMBER_INT);
    $maximum_stock = filter_input(INPUT_POST, 'maximum_stock', FILTER_SANITIZE_NUMBER_INT);
    $supplier = filter_input(INPUT_POST, 'supplier', FILTER_SANITIZE_STRING);
    $location = filter_input(INPUT_POST, 'location', FILTER_SANITIZE_STRING);
    $barcode = filter_input(INPUT_POST, 'barcode', FILTER_SANITIZE_STRING);
    
    if ($name && $category && $unit && $cost_per_unit !== false) {
        try {
            $query = "INSERT INTO inventory_items (name, category, description, unit, cost_per_unit, current_stock, minimum_stock, maximum_stock, supplier, location, barcode, status, created_at) 
                     VALUES (:name, :category, :description, :unit, :cost_per_unit, :current_stock, :minimum_stock, :maximum_stock, :supplier, :location, :barcode, 'active', NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':category', $category);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':unit', $unit);
            $stmt->bindParam(':cost_per_unit', $cost_per_unit);
            $stmt->bindParam(':current_stock', $current_stock);
            $stmt->bindParam(':minimum_stock', $minimum_stock);
            $stmt->bindParam(':maximum_stock', $maximum_stock);
            $stmt->bindParam(':supplier', $supplier);
            $stmt->bindParam(':location', $location);
            $stmt->bindParam(':barcode', $barcode);
            $stmt->execute();
            
            $success = "Inventory item created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating item: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

$title = "Create Inventory Item";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Inventory', 'url' => '../index.php'],
    ['title' => 'Items', 'url' => 'index.php'],
    ['title' => 'Create Item']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Inventory Item</h1>
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
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item Name *</label>
                                    <input type="text" id="name" name="name" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category *</label>
                                    <select id="category" name="category" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Category</option>
                                        <option value="office_supplies">Office Supplies</option>
                                        <option value="classroom_materials">Classroom Materials</option>
                                        <option value="technology">Technology</option>
                                        <option value="furniture">Furniture</option>
                                        <option value="maintenance">Maintenance</option>
                                        <option value="sports_equipment">Sports Equipment</option>
                                        <option value="laboratory">Laboratory Equipment</option>
                                        <option value="library">Library Materials</option>
                                        <option value="cleaning">Cleaning Supplies</option>
                                        <option value="medical">Medical Supplies</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="unit" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit *</label>
                                    <select id="unit" name="unit" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Unit</option>
                                        <option value="pieces">Pieces</option>
                                        <option value="boxes">Boxes</option>
                                        <option value="packs">Packs</option>
                                        <option value="sets">Sets</option>
                                        <option value="kg">Kilogram</option>
                                        <option value="liters">Liters</option>
                                        <option value="meters">Meters</option>
                                        <option value="reams">Reams</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="cost_per_unit" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost per Unit (₵) *</label>
                                    <input type="number" id="cost_per_unit" name="cost_per_unit" step="0.01" min="0" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="current_stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Stock</label>
                                    <input type="number" id="current_stock" name="current_stock" min="0" value="0"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="minimum_stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Minimum Stock Level</label>
                                    <input type="number" id="minimum_stock" name="minimum_stock" min="0" value="5"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="maximum_stock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Maximum Stock Level</label>
                                    <input type="number" id="maximum_stock" name="maximum_stock" min="0"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="supplier" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Supplier</label>
                                    <input type="text" id="supplier" name="supplier"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="location" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Storage Location</label>
                                    <input type="text" id="location" name="location"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                           placeholder="e.g., Store Room A, Shelf 3">
                                </div>

                                <div>
                                    <label for="barcode" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Barcode/SKU</label>
                                    <input type="text" id="barcode" name="barcode"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="md:col-span-2">
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea id="description" name="description" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Enter item description, specifications, etc."></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Item
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

<script>
// Auto-generate barcode if empty
document.getElementById('name').addEventListener('blur', function() {
    const barcodeField = document.getElementById('barcode');
    if (!barcodeField.value && this.value) {
        // Generate simple SKU based on name and timestamp
        const sku = this.value.substring(0, 3).toUpperCase() + Date.now().toString().slice(-6);
        barcodeField.value = sku;
    }
});

// Validate minimum vs maximum stock
document.getElementById('maximum_stock').addEventListener('blur', function() {
    const minStock = parseInt(document.getElementById('minimum_stock').value) || 0;
    const maxStock = parseInt(this.value) || 0;
    
    if (maxStock > 0 && maxStock < minStock) {
        alert('Maximum stock should be greater than minimum stock');
        this.focus();
    }
});
</script>
