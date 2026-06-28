<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle menu item deletion
if (isset($_POST['delete_item']) && isset($_POST['item_id'])) {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "DELETE FROM canteen_menu WHERE id = :item_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':item_id', $item_id);
    if ($stmt->execute()) {
        $success_message = "Menu item deleted successfully!";
    } else {
        $error_message = "Error deleting menu item.";
    }
}

// Get filter parameters. The menu is a persistent list the manager maintains,
// so by default we show the existing menu regardless of date. Date is an
// optional filter that only applies when the manager explicitly selects one.
$date_filter = isset($_GET['date']) ? $_GET['date'] : '';
$meal_type_filter = isset($_GET['meal_type']) ? $_GET['meal_type'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($date_filter) {
    $where_conditions[] = "cm.date = :date";
    $params[':date'] = $date_filter;
}

if ($meal_type_filter) {
    $where_conditions[] = "meal_type = :meal_type";
    $params[':meal_type'] = $meal_type_filter;
}

if ($status_filter) {
    $where_conditions[] = "status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch menu items
$query = "SELECT cm.*, COUNT(co.id) as order_count
          FROM canteen_menu cm
          LEFT JOIN canteen_orders co ON cm.id = co.menu_id AND co.order_date = cm.date
          $where_clause
          GROUP BY cm.id
          ORDER BY cm.meal_type, cm.item_name";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$menu_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Menu Management";
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
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Menu Management</h1>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Canteen
                    </a>
                    <a href="create.php" class="inline-flex items-center whitespace-nowrap bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Menu Item
                    </a>
                </div>
            </div>

            <!-- Navigation breadcrumb -->
            <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                <i class="fas fa-chevron-right text-xs"></i>
                <span class="text-gray-900 dark:text-white font-medium">Menu Management</span>
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

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <label for="date" class="block text-sm font-medium text-gray-700 mb-1">Date <span class="text-gray-400 font-normal">(optional)</span></label>
                            <input type="date" name="date" id="date" value="<?php echo htmlspecialchars($date_filter); ?>" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
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
                        <div class="w-48">
                            <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                            <select name="status" id="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="unavailable" <?php echo $status_filter === 'unavailable' ? 'selected' : ''; ?>>Unavailable</option>
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

            <!-- Menu Items Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php 
                $meal_types = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'dinner' => 'Dinner', 'snack' => 'Snack'];
                foreach ($menu_items as $item): 
                ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($item['item_name']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo $meal_types[$item['meal_type']] ?? ucfirst($item['meal_type']); ?></p>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $item['status'] === 'available' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($item['status']); ?>
                            </span>
                        </div>

                        <?php if ($item['description']): ?>
                        <p class="text-gray-600 text-sm mb-4"><?php echo htmlspecialchars($item['description']); ?></p>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <div class="text-lg font-bold text-green-600">₵<?php echo number_format($item['price'], 2); ?></div>
                                <div class="text-sm text-gray-600">Price</div>
                            </div>
                            <div>
                                <div class="text-lg font-bold text-blue-600"><?php echo $item['available_quantity']; ?></div>
                                <div class="text-sm text-gray-600">Available</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="text-sm text-gray-600">Orders: <span class="font-medium"><?php echo $item['order_count']; ?></span></div>
                        </div>

                        <div class="flex justify-end items-center">
                            <div class="flex items-center gap-2 no-stack">
                                <a href="edit.php?id=<?php echo $item['id']; ?>"
                                    class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-600 hover:text-white transition-colors duration-200" title="Edit Item">
                                    <i class="fas fa-edit text-sm"></i>
                                </a>
                                <form action="" method="POST" class="inline-flex" onsubmit="return confirm('Are you sure you want to delete this menu item?')">
                                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                                    <button type="submit" name="delete_item"
                                        class="inline-flex items-center justify-center w-9 h-9 rounded-lg bg-red-50 text-red-600 hover:bg-red-600 hover:text-white transition-colors duration-200" title="Delete Item">
                                        <i class="fas fa-trash text-sm"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($menu_items)): ?>
            <div class="text-center py-12">
                <i class="fas fa-utensils text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No menu items found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($date_filter || $meal_type_filter || $status_filter): ?>
                        Try adjusting your filters or select a different date.
                    <?php else: ?>
                        Get started by adding your first menu item.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Add Menu Item
                </a>
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
