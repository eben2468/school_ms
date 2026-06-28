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

// Fetch item details
$query = "SELECT * FROM inventory_items WHERE id = :id AND status != 'discontinued'";
$stmt = $db->prepare($query);
$stmt->execute([':id' => $id]);
$item = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$item) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

// Handle restocking form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $qty_to_add = filter_input(INPUT_POST, 'quantity', FILTER_SANITIZE_NUMBER_INT);
    $unit_cost = filter_input(INPUT_POST, 'unit_cost', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $supplier = filter_input(INPUT_POST, 'supplier', FILTER_SANITIZE_STRING) ?: $item['supplier'];
    $log_expense = isset($_POST['log_expense']) ? true : false;
    $expense_status = filter_input(INPUT_POST, 'expense_status', FILTER_SANITIZE_STRING) ?: 'approved';
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

    if ($qty_to_add && $qty_to_add > 0 && $unit_cost !== false) {
        try {
            $db->beginTransaction();

            // 1. Update item stock quantity and price if new price provided
            $new_quantity = $item['quantity_available'] + $qty_to_add;
            $update_stmt = $db->prepare("UPDATE inventory_items SET quantity_available = :qty, unit_price = :price, supplier = :supplier, status = 'available' WHERE id = :id");
            $update_stmt->execute([
                ':qty' => $new_quantity,
                ':price' => $unit_cost > 0 ? $unit_cost : $item['unit_price'],
                ':supplier' => $supplier,
                ':id' => $id
            ]);

            // 2. Log in inventory movements
            $notes_log = "Restocked: Added " . $qty_to_add . " units. " . ($notes ? "Notes: " . $notes : "");
            $move_stmt = $db->prepare("INSERT INTO inventory_movements (item_id, user_id, movement_type, quantity, reference_type, notes) 
                                      VALUES (:item_id, :user_id, 'in', :quantity, 'restock', :notes)");
            $move_stmt->execute([
                ':item_id' => $id,
                ':user_id' => $_SESSION['user_id'],
                ':quantity' => $qty_to_add,
                ':notes' => $notes_log
            ]);

            // 3. Integrate with Finance Expenses if selected
            if ($log_expense && $unit_cost > 0) {
                $total_amount = $qty_to_add * $unit_cost;
                $description = "Restocked inventory item: " . $item['item_name'] . " (SKU: " . $item['item_code'] . ") - Quantity: " . $qty_to_add . " x ₵" . number_format($unit_cost, 2);
                
                $exp_stmt = $db->prepare("INSERT INTO finance_expenses (category, amount, description, vendor, expense_date, recorded_by, status, approved_by) 
                                          VALUES ('maintenance', :amount, :description, :vendor, CURDATE(), :recorded_by, :status, :approved_by)");
                $exp_stmt->execute([
                    ':amount' => $total_amount,
                    ':description' => $description,
                    ':vendor' => $supplier ?: 'Inventory Supplier',
                    ':recorded_by' => $_SESSION['user_id'],
                    ':status' => $expense_status,
                    ':approved_by' => $expense_status === 'approved' ? $_SESSION['user_id'] : null
                ]);
            }

            $db->commit();
            $success = "Item restocked successfully! Stock updated from {$item['quantity_available']} to {$new_quantity}.";
            $item['quantity_available'] = $new_quantity;
            if ($unit_cost > 0) {
                $item['unit_price'] = $unit_cost;
            }
            if ($supplier) {
                $item['supplier'] = $supplier;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error restocking item: " . $e->getMessage();
        }
    } else {
        $error = "Please enter a valid quantity greater than zero.";
    }
}

$title = "Restock Item: " . $item['item_name'];
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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Restock: <?php echo htmlspecialchars($item['item_name']); ?></h1>
                    <div class="flex space-x-3">
                        <a href="view.php?id=<?php echo $item['id']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Details
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

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Left Column: Form -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="quantity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity to Add *</label>
                                    <input type="number" id="quantity" name="quantity" min="1" required placeholder="e.g. 50"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>

                                <div>
                                    <label for="unit_cost" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Cost per Unit (₵) *</label>
                                    <input type="number" id="unit_cost" name="unit_cost" step="0.01" min="0" required value="<?php echo htmlspecialchars($item['unit_price']); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <span class="text-xs text-gray-500">Defaults to the current price. Setting to 0 keeps the current price without logging costs.</span>
                                </div>

                                <div>
                                    <label for="supplier" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Supplier/Vendor</label>
                                    <input type="text" id="supplier" name="supplier" value="<?php echo htmlspecialchars($item['supplier'] ?? ''); ?>" placeholder="e.g. Kingdom Books"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>

                                <div>
                                    <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <input type="text" id="notes" name="notes" placeholder="e.g. Annual classroom restocking"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                </div>
                            </div>

                            <!-- Finance Integration Section -->
                            <div class="bg-rose-50 dark:bg-rose-950/20 rounded-xl p-6 border border-rose-200 dark:border-rose-900/30">
                                <div class="flex items-start">
                                    <div class="flex items-center h-5">
                                        <input id="log_expense" name="log_expense" type="checkbox" value="1" checked
                                               class="h-4 w-4 text-rose-600 border-gray-300 rounded focus:ring-rose-500">
                                    </div>
                                    <div class="ml-3 text-sm">
                                        <label for="log_expense" class="font-semibold text-rose-900 dark:text-rose-350">Log in Finance Expenses</label>
                                        <p class="text-rose-700 dark:text-rose-400">If checked, the total purchase cost (Quantity × Cost per Unit) will be added to the finance expenses ledger under the 'maintenance' category.</p>
                                    </div>
                                </div>
                                <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label for="expense_status" class="block text-xs font-semibold text-rose-900 dark:text-rose-350 uppercase">Expense Status Workflow</label>
                                        <select id="expense_status" name="expense_status"
                                                class="mt-1 block w-full px-3 py-2 border border-rose-300 dark:border-rose-900/50 dark:bg-gray-750 dark:text-white rounded-md text-sm">
                                            <option value="approved">Approved & Disbursed (Paid)</option>
                                            <option value="pending">Pending Approval</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="view.php?id=<?php echo $item['id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-rose-600 hover:bg-rose-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-truck-loading mr-2"></i>Complete Restock
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Right Column: Current Info -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 space-y-6 h-fit">
                        <h2 class="text-lg font-semibold text-gray-800 dark:text-white">Current Stock Info</h2>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-500">Current Quantity:</span>
                                <span class="font-bold text-gray-900 dark:text-white"><?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Min Stock Level:</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo $item['minimum_stock_level']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">SKU/Code:</span>
                                <span class="font-mono text-gray-950 dark:text-white"><?php echo htmlspecialchars($item['item_code']); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-500">Storage Location:</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['location'] ?: 'Not set'); ?></span>
                            </div>
                        </div>
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
