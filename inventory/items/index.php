<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'inventory_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle item updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_item'])) {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
    $minimum_stock = filter_input(INPUT_POST, 'minimum_stock', FILTER_SANITIZE_NUMBER_INT);
    $unit_price = filter_input(INPUT_POST, 'unit_price', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

    try {
        $query = "UPDATE inventory_items SET quantity = :quantity, minimum_stock = :minimum_stock, unit_price = :unit_price WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':quantity', $quantity);
        $stmt->bindParam(':minimum_stock', $minimum_stock);
        $stmt->bindParam(':unit_price', $unit_price);
        $stmt->bindParam(':id', $item_id);
        $stmt->execute();
        
        $success = "Item updated successfully!";
    } catch (PDOException $e) {
        $error = "Error updating item: " . $e->getMessage();
    }
}

// Get items with filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$category_filter = filter_input(INPUT_GET, 'category', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$stock_filter = filter_input(INPUT_GET, 'filter', FILTER_SANITIZE_STRING);

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(item_name LIKE :search OR item_code LIKE :search OR description LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($category_filter && $category_filter !== 'all') {
    $where_conditions[] = "item_type = :category";
    $params[':category'] = $category_filter;
}

if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "status = :status";
    $params[':status'] = $status_filter;
}

if ($stock_filter === 'low_stock') {
    $where_conditions[] = "quantity <= minimum_stock";
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_query = "SELECT COUNT(*) FROM inventory_items $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $per_page);

// Fetch items
$query = "SELECT * FROM inventory_items 
          $where_clause
          ORDER BY item_name ASC
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
$categories_query = "SELECT DISTINCT item_type as category FROM inventory_items WHERE item_type IS NOT NULL ORDER BY item_type";
$categories_stmt = $db->query($categories_query);
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

$title = "Inventory Items";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8" style="margin-top: 30px;">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Inventory Items</h1>
                                <p class="text-blue-100 text-lg">Manage and track all inventory items</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-boxes mr-2"></i>
                                        Item Management
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
                    <div class="flex space-x-3">
                        <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Inventory
                        </a>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Add Item
                        </a>
                    </div>
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

                <!-- Filters -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                placeholder="Search items..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label for="category" class="block text-sm font-medium text-gray-700">Category</label>
                            <select id="category" name="category" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="all">All Categories</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" 
                                    <?php echo $category_filter === $category ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700">Status</label>
                            <select id="status" name="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="all">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <label for="filter" class="block text-sm font-medium text-gray-700">Stock Filter</label>
                            <select id="filter" name="filter" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
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
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Category</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit Price</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($items)): ?>
                                    <?php foreach ($items as $item): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div>
                                                <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($item['item_name']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($item['item_code']); ?></div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                <?php echo htmlspecialchars($item['item_type'] ?? 'Uncategorized'); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit']); ?>
                                                <?php if ($item['quantity'] <= $item['minimum_stock']): ?>
                                                <span class="ml-2 px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                                    Low Stock
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-sm text-gray-500">Min: <?php echo $item['minimum_stock']; ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            ₵<?php echo number_format($item['unit_price'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php echo $item['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo ucfirst($item['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <button onclick="openUpdateModal(<?php echo $item['id']; ?>, '<?php echo htmlspecialchars($item['item_name']); ?>', <?php echo $item['quantity']; ?>, <?php echo $item['minimum_stock']; ?>, <?php echo $item['unit_price']; ?>)" 
                                                    class="text-blue-600 hover:text-blue-900">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <a href="view.php?id=<?php echo $item['id']; ?>" class="text-green-600 hover:text-green-900">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <button class="text-red-600 hover:text-red-900">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-12 text-center">
                                        <i class="fas fa-boxes text-gray-400 text-4xl mb-4"></i>
                                        <h3 class="text-lg font-medium text-gray-900 mb-2">No items found</h3>
                                        <p class="text-gray-500 mb-4">
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
                    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                               class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Previous
                            </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                               class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                Next
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div>
                                <p class="text-sm text-gray-700">
                                    Showing <span class="font-medium"><?php echo $offset + 1; ?></span> to 
                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_items); ?></span> of 
                                    <span class="font-medium"><?php echo $total_items; ?></span> results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $category_filter ? "&category=$category_filter" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?>" 
                                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
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

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Update Item Modal -->
<div id="updateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Update Item</h3>
                <button onclick="closeUpdateModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" id="updateItemId" name="item_id">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Item Name</label>
                    <p id="updateItemName" class="mt-1 text-sm text-gray-900"></p>
                </div>
                <div>
                    <label for="updateQuantity" class="block text-sm font-medium text-gray-700">Quantity</label>
                    <input type="number" id="updateQuantity" name="quantity" required min="0"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="updateMinStock" class="block text-sm font-medium text-gray-700">Minimum Stock</label>
                    <input type="number" id="updateMinStock" name="minimum_stock" required min="0"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="updatePrice" class="block text-sm font-medium text-gray-700">Unit Price (₵)</label>
                    <input type="number" id="updatePrice" name="unit_price" required min="0" step="0.01"
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeUpdateModal()" 
                        class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
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
