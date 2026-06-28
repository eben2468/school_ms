<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'inventory_manager', 'principal'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle item updates (quick adjustment)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
    $minimum_stock = filter_input(INPUT_POST, 'minimum_stock', FILTER_SANITIZE_NUMBER_INT);
    $unit_price = filter_input(INPUT_POST, 'unit_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

    try {
        $db->beginTransaction();

        // Get old quantity for movements log
        $old_qty_stmt = $db->prepare("SELECT quantity_available FROM inventory_items WHERE id = :id");
        $old_qty_stmt->execute([':id' => $item_id]);
        $old_qty = $old_qty_stmt->fetchColumn() ?: 0;

        $qty_diff = $quantity - $old_qty;

        $query = "UPDATE inventory_items SET quantity_available = :quantity, minimum_stock_level = :minimum_stock, unit_price = :unit_price WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':minimum_stock', $minimum_stock);
        $stmt->bindParam(':unit_price', $unit_price);
        $stmt->bindParam(':id', $item_id);
        $stmt->execute();
        
        // Log movement if quantity changed
        if ($qty_diff != 0) {
            $movement_type = $qty_diff > 0 ? 'in' : 'out';
            $move_qty = abs($qty_diff);
            
            $move_stmt = $db->prepare("INSERT INTO inventory_movements (item_id, user_id, movement_type, quantity, reference_type, notes) 
                                      VALUES (:item_id, :user_id, :movement_type, :quantity, 'adjustment', 'Stock level adjusted manually')");
            $move_stmt->execute([
                ':item_id' => $item_id,
                ':user_id' => $_SESSION['user_id'],
                ':movement_type' => $movement_type,
                ':quantity' => $move_qty
            ]);
        }

        $db->commit();
        $success = "Item updated successfully!";
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error updating item: " . $e->getMessage();
    }
}

// Handle item deletion (soft delete by marking as discontinued)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
    try {
        $query = "UPDATE inventory_items SET status = 'discontinued' WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $item_id);
        $stmt->execute();
        $success = "Item discontinued successfully!";
    } catch (PDOException $e) {
        $error = "Error deleting item: " . $e->getMessage();
    }
}

// Get items with filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$category_filter = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$stock_filter = filter_input(INPUT_GET, 'filter', FILTER_SANITIZE_STRING);

$where_conditions = ["ii.status != 'discontinued'"];
$params = [];

if ($search) {
    $where_conditions[] = "(ii.item_name LIKE :search OR ii.item_code LIKE :search OR ii.description LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_filter && $category_filter !== 'all') {
    $where_conditions[] = "ii.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "ii.status = :status";
    $params[':status'] = $status_filter;
}

if ($stock_filter === 'low_stock') {
    $where_conditions[] = "ii.quantity_available <= ii.minimum_stock_level";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_query = "SELECT COUNT(*) FROM inventory_items ii $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $per_page);

// Fetch items
$query = "SELECT ii.*, ic.name as category_name 
          FROM inventory_items ii
          LEFT JOIN inventory_categories ic ON ii.category_id = ic.id
          $where_clause
          ORDER BY ii.item_name ASC
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get categories for filter
$categories_query = "SELECT id, name FROM inventory_categories ORDER BY name";
$categories_stmt = $db->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Inventory Items";
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
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8" style="margin-top: 20px;">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Inventory Items</h1>
                                <p class="text-blue-100 text-lg">Manage and track all school assets and supplies</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-boxes mr-2"></i>
                                        <?php echo number_format($total_items); ?> Total Items
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-warehouse mr-2"></i>
                                        Stock Control
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-boxes text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center mb-6">
                    <div></div>
                    <div class="flex space-x-3" style="flex-direction: row !important;">
                        <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Add Item
                        </a>
                    </div>
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

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                placeholder="Search name, code..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Category</label>
                            <select id="category" name="category" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" 
                                    <?php echo (string)$category_filter === (string)$cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select id="status" name="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                                <option value="all">All Status</option>
                                <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="out_of_stock" <?php echo $status_filter === 'out_of_stock' ? 'selected' : ''; ?>>Out of Stock</option>
                            </select>
                        </div>
                        <div>
                            <label for="filter" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Stock Filter</label>
                            <select id="filter" name="filter" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                                <option value="">All Items</option>
                                <option value="low_stock" <?php echo $stock_filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock Only</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Items Table -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-900">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Category</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Unit Price</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider dark:text-gray-400">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if (!empty($items)): ?>
                                    <?php foreach ($items as $item): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($item['item_code']); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($item['category_name'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?>
                                                <?php if ($item['quantity_available'] <= $item['minimum_stock_level']): ?>
                                                <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                    Low Stock
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">Min: <?php echo $item['minimum_stock_level']; ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            ₵<?php echo number_format($item['unit_price'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($item['status'] === 'available'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Available</span>
                                            <?php elseif ($item['status'] === 'out_of_stock'): ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Out of Stock</span>
                                            <?php else: ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800"><?php echo ucfirst($item['status']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex items-center space-x-3">
                                                <button onclick="openUpdateModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars(addslashes($item['item_name'])); ?>', <?php echo $item['quantity_available']; ?>, <?php echo $item['minimum_stock_level']; ?>, <?php echo $item['unit_price']; ?>)" 
                                                    class="text-blue-600 hover:text-blue-900 dark:hover:text-blue-400" title="Quick Adjust">
                                                    <i class="fas fa-sliders-h"></i>
                                                </button>
                                                <a href="edit.php?id=<?php echo $item['id']; ?>" class="text-indigo-600 hover:text-indigo-900 dark:hover:text-indigo-400" title="Edit Full Info">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                                <a href="view.php?id=<?php echo $item['id']; ?>" class="text-green-600 hover:text-green-900 dark:hover:text-green-400" title="View Details">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="restock.php?id=<?php echo $item['id']; ?>" class="text-orange-600 hover:text-orange-900 dark:hover:text-orange-400" title="Restock">
                                                    <i class="fas fa-plus-circle"></i>
                                                </a>
                                                <form method="POST" action="" class="inline" onsubmit="return confirm('Are you sure you want to discontinue this item?');">
                                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                                    <button type="submit" name="delete_item" class="text-red-600 hover:text-red-900 dark:hover:text-red-400" title="Discontinue">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center dark:bg-gray-800">
                                        <i class="fas fa-boxes text-gray-400 text-4xl mb-4"></i>
                                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No items found</h3>
                                        <p class="text-gray-500 dark:text-gray-400 mb-4">
                                            <?php if ($search || $category_filter || $status_filter): ?>
                                                Try adjusting your search criteria.
                                            <?php else: ?>
                                                Get started by adding your first inventory item.
                                            <?php endif; ?>
                                        </p>
                                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                            Add First Item
                                        </a>
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="bg-white dark:bg-gray-800 px-4 py-3 flex items-center justify-between border-t border-gray-200 dark:border-gray-700 sm:px-6">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-750 hover:bg-gray-50">
                                Previous
                            </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                               class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-200 bg-white dark:bg-gray-750 hover:bg-gray-50">
                                Next
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_items); ?></span> of 
                                    <span class="font-medium"><?php echo $total_items; ?></span> results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700
                                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600 dark:bg-blue-900/30' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                    <?php endfor; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Quick Update Item Modal -->
<div id="updateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800 dark:border-gray-700">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">Quick Adjust Stock</h3>
                <button onclick="closeUpdateModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" id="updateItemId" name="item_id">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Item Name</label>
                    <p id="updateItemName" class="mt-1 text-sm text-gray-900 dark:text-white font-medium"></p>
                </div>
                <div>
                    <label for="updateQuantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Current Stock</label>
                    <input type="number" id="updateQuantity" name="quantity" required min="0"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md shadow-sm">
                </div>
                <div>
                    <label for="updateMinStock" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Minimum Stock Level</label>
                    <input type="number" id="updateMinStock" name="minimum_stock" required min="0"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md shadow-sm">
                </div>
                <div>
                    <label for="updatePrice" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Unit Price (₵)</label>
                    <input type="number" id="updatePrice" name="unit_price" required min="0" step="0.01"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md shadow-sm">
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeUpdateModal()" 
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-750 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" name="update_item"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Update
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openUpdateModal(id, name, quantity, minStock, price) {
    document.getElementById('updateItemId').value = id;
    document.getElementById('updateItemName').textContent = name;
    document.getElementById('updateQuantity').value = quantity;
    document.getElementById('updateMinStock').value = minStock;
    document.getElementById('updatePrice').value = price;
    document.getElementById('updateModal').classList.remove('hidden');
}

function closeUpdateModal() {
    document.getElementById('updateModal').classList.add('hidden');
}
</script>
