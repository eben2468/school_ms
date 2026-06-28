<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = filter_input(INPUT_POST, 'item_id', FILTER_SANITIZE_NUMBER_INT);
    $quantity_requested = filter_input(INPUT_POST, 'quantity_requested', FILTER_SANITIZE_NUMBER_INT);
    $purpose = filter_input(INPUT_POST, 'purpose', FILTER_SANITIZE_STRING);
    $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING) ?: 'medium';
    $required_date = filter_input(INPUT_POST, 'required_date', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

    if ($item_id && $quantity_requested && $purpose) {
        try {
            // Check item availability
            $check_query = "SELECT item_name, quantity_available FROM inventory_items WHERE id = :item_id AND status = 'available'";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':item_id', $item_id);
            $check_stmt->execute();
            $item = $check_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Item not found or currently unavailable.");
            }

            if ($quantity_requested > $item['quantity_available']) {
                throw new Exception("Requested quantity ({$quantity_requested}) exceeds available stock ({$item['quantity_available']}).");
            }

            $query = "INSERT INTO inventory_requests (item_id, requested_by, quantity_requested, purpose, priority, request_date, required_date, notes, status, created_at)
                      VALUES (:item_id, :requested_by, :quantity_requested, :purpose, :priority, CURDATE(), :required_date, :notes, 'pending', NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':item_id', $item_id);
            $stmt->bindParam(':requested_by', $_SESSION['user_id']);
            $stmt->bindParam(':quantity_requested', $quantity_requested);
            $stmt->bindParam(':purpose', $purpose);
            $stmt->bindParam(':priority', $priority);
            $stmt->bindParam(':required_date', $required_date);
            $stmt->bindParam(':notes', $notes);
            $stmt->execute();

            $request_id = $db->lastInsertId();
            $success = "Inventory request submitted successfully! Request ID: #" . $request_id;
        } catch (Exception $e) {
            $error = "Error creating request: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get available inventory items grouped by category
$items_query = "SELECT ii.id, ii.item_name, ii.item_code, ii.quantity_available, ii.unit_price, ii.location, ii.unit,
                COALESCE(ic.name, 'General') as category_name
                FROM inventory_items ii
                LEFT JOIN inventory_categories ic ON ii.category_id = ic.id
                WHERE ii.status = 'available' AND ii.quantity_available > 0
                ORDER BY ic.name, ii.item_name";
$items_stmt = $db->query($items_query);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

$items_by_category = [];
foreach ($items as $item) {
    $items_by_category[$item['category_name']][] = $item;
}

$title = "Create Inventory Request";
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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Inventory Request</h1>
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

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="md:col-span-2">
                                    <label for="item_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Item *</label>
                                    <select id="item_id" name="item_id" required onchange="updateItemDetails()"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        <option value="">Choose an item</option>
                                        <?php if (!empty($items_by_category)): ?>
                                            <?php foreach ($items_by_category as $category => $category_items): ?>
                                                <optgroup label="<?php echo htmlspecialchars($category); ?>">
                                                    <?php foreach ($category_items as $item): ?>
                                                        <option value="<?php echo $item['id']; ?>"
                                                                data-available="<?php echo $item['quantity_available']; ?>"
                                                                data-unit="<?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?>"
                                                                data-code="<?php echo htmlspecialchars($item['item_code']); ?>"
                                                                data-location="<?php echo htmlspecialchars($item['location']); ?>"
                                                                data-price="<?php echo $item['unit_price']; ?>">
                                                            <?php echo htmlspecialchars($item['item_name']); ?>
                                                            (<?php echo htmlspecialchars($item['item_code']); ?>) -
                                                            Available: <?php echo $item['quantity_available']; ?> <?php echo htmlspecialchars($item['unit'] ?? 'pcs'); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </optgroup>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <option value="" disabled>No items available in stock</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <!-- Item Details Display -->
                                <div id="item-details" class="md:col-span-2 hidden bg-gray-50 dark:bg-gray-750 p-4 rounded-lg">
                                    <h4 class="font-medium text-gray-900 dark:text-white mb-2">Selected Item Info</h4>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">SKU Code:</span>
                                            <span id="item-code" class="font-medium text-gray-900 dark:text-white ml-1"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">In Stock:</span>
                                            <span id="item-available" class="font-medium text-green-600 ml-1"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Storage Location:</span>
                                            <span id="item-location" class="font-medium text-gray-900 dark:text-white ml-1"></span>
                                        </div>
                                        <div>
                                            <span class="text-gray-500 dark:text-gray-400">Estimated Value:</span>
                                            <span id="item-price" class="font-medium text-gray-900 dark:text-white ml-1"></span>
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label for="quantity_requested" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Quantity Requested *</label>
                                    <input type="number" id="quantity_requested" name="quantity_requested" min="1" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Maximum limit: <span id="max-quantity">-</span></p>
                                </div>

                                <div>
                                    <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Priority Level *</label>
                                    <select id="priority" name="priority" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        <option value="low">Low</option>
                                        <option value="medium" selected>Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">Urgent</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="required_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Required Date</label>
                                    <input type="date" id="required_date" name="required_date" min="<?php echo date('Y-m-d'); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="purpose" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Purpose / Activity *</label>
                                    <select id="purpose" name="purpose" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Purpose</option>
                                        <option value="Classroom teaching supplies">Classroom teaching supplies</option>
                                        <option value="Laboratory experiment setup">Laboratory experiment setup</option>
                                        <option value="Sports activity session">Sports activity session</option>
                                        <option value="Facility repairs & maintenance">Facility repairs & maintenance</option>
                                        <option value="Administrative office usage">Administrative office usage</option>
                                        <option value="School event / ceremony decoration">School event / ceremony decoration</option>
                                        <option value="Replacement of broken materials">Replacement of broken materials</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Additional Notes / Details</label>
                                    <textarea id="notes" name="notes" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm dark:bg-gray-700 dark:text-white"
                                              placeholder="Provide any extra context, department info, or specifications..."></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-paper-plane mr-2"></i>Submit Request
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
function updateItemDetails() {
    const select = document.getElementById('item_id');
    const selectedOption = select.options[select.selectedIndex];
    const detailsDiv = document.getElementById('item-details');

    if (selectedOption.value) {
        const available = selectedOption.getAttribute('data-available');
        const unit = selectedOption.getAttribute('data-unit');
        const code = selectedOption.getAttribute('data-code');
        const location = selectedOption.getAttribute('data-location');
        const price = selectedOption.getAttribute('data-price');

        document.getElementById('item-code').textContent = code;
        document.getElementById('item-available').textContent = `${available} ${unit}`;
        document.getElementById('item-location').textContent = location || 'Not specified';
        document.getElementById('item-price').textContent = price ? `₵${parseFloat(price).toFixed(2)}` : 'Not set';
        document.getElementById('max-quantity').textContent = `${available} ${unit}`;

        // Update quantity input max value
        document.getElementById('quantity_requested').setAttribute('max', available);

        detailsDiv.classList.remove('hidden');
    } else {
        detailsDiv.classList.add('hidden');
        document.getElementById('max-quantity').textContent = '-';
        document.getElementById('quantity_requested').removeAttribute('max');
    }
}

// Validate quantity on input
document.getElementById('quantity_requested').addEventListener('input', function() {
    const max = parseInt(this.getAttribute('max'));
    const value = parseInt(this.value);

    if (max && value > max) {
        this.setCustomValidity(`Quantity cannot exceed ${max} (available stock)`);
    } else {
        this.setCustomValidity('');
    }
});
</script>