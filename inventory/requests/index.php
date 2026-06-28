<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Handle request status updates (for admins)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status']) && in_array($user_role, ['super_admin', 'school_admin', 'inventory_manager', 'principal'])) {
    $request_id = filter_input(INPUT_POST, 'request_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);

    try {
        $db->beginTransaction();

        // Get request details first
        $req_stmt = $db->prepare("SELECT * FROM inventory_requests WHERE id = :id");
        $req_stmt->execute([':id' => $request_id]);
        $request = $req_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception("Request not found.");
        }

        // If transitioning to approved or fulfilled from pending, decrement quantity
        if ($request['status'] === 'pending' && in_array($status, ['approved', 'fulfilled'])) {
            // Fetch item details
            $item_stmt = $db->prepare("SELECT quantity_available, item_name FROM inventory_items WHERE id = :item_id");
            $item_stmt->execute([':item_id' => $request['item_id']]);
            $item = $item_stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                throw new Exception("Item not found in inventory.");
            }

            if ($item['quantity_available'] < $request['quantity_requested']) {
                throw new Exception("Cannot approve request. Requested quantity ({$request['quantity_requested']}) exceeds available stock ({$item['quantity_available']}).");
            }

            // Decrement quantity
            $new_qty = $item['quantity_available'] - $request['quantity_requested'];
            $dec_stmt = $db->prepare("UPDATE inventory_items SET quantity_available = :qty, status = :status WHERE id = :item_id");
            $new_status = $new_qty == 0 ? 'out_of_stock' : 'available';
            $dec_stmt->execute([
                ':qty' => $new_qty,
                ':status' => $new_status,
                ':item_id' => $request['item_id']
            ]);

            // Log movement
            $move_notes = "Disbursed for Request #" . $request_id . ". Purpose: " . $request['purpose'] . ". " . ($notes ? "Remarks: " . $notes : "");
            $move_stmt = $db->prepare("INSERT INTO inventory_movements (item_id, user_id, movement_type, quantity, reference_id, reference_type, notes) 
                                      VALUES (:item_id, :user_id, 'out', :quantity, :ref_id, 'request', :notes)");
            $move_stmt->execute([
                ':item_id' => $request['item_id'],
                ':user_id' => $_SESSION['user_id'],
                ':quantity' => $request['quantity_requested'],
                ':ref_id' => $request_id,
                ':notes' => $move_notes
            ]);
        }

        $query = "UPDATE inventory_requests SET status = :status, remarks = :notes, approved_by = :approved_by, approval_date = CURDATE() WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':status' => $status,
            ':notes' => $notes,
            ':approved_by' => $user_id,
            ':id' => $request_id
        ]);
        
        $db->commit();
        $success = "Request status updated successfully!";
    } catch (Exception $e) {
        $db->rollBack();
        $error = "Error updating request: " . $e->getMessage();
    }
}

// Get requests with filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING);
$priority_filter = filter_input(INPUT_GET, 'priority', FILTER_SANITIZE_STRING);

$where_conditions = ["1=1"];
$params = [];

// If not admin, only show user's own requests
if (!in_array($user_role, ['super_admin', 'school_admin', 'inventory_manager', 'principal'])) {
    $where_conditions[] = "ir.requested_by = :user_id";
    $params[':user_id'] = $user_id;
}

if ($search) {
    $where_conditions[] = "(ii.item_name LIKE :search OR u.name LIKE :search OR ir.purpose LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter && $status_filter !== 'all') {
    $where_conditions[] = "ir.status = :status";
    $params[':status'] = $status_filter;
}

if ($priority_filter && $priority_filter !== 'all') {
    $where_conditions[] = "ir.priority = :priority";
    $params[':priority'] = $priority_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Pagination
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Get total count
$count_query = "SELECT COUNT(*) 
                FROM inventory_requests ir 
                JOIN users u ON ir.requested_by = u.id 
                JOIN inventory_items ii ON ir.item_id = ii.id
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_requests = $count_stmt->fetchColumn();
$total_pages = ceil($total_requests / $per_page);

// Fetch requests
$query = "SELECT ir.*, u.name as requester_name, ii.item_name, ii.item_code
          FROM inventory_requests ir
          JOIN users u ON ir.requested_by = u.id
          JOIN inventory_items ii ON ir.item_id = ii.id
          $where_clause
          ORDER BY ir.created_at DESC
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Inventory Requests";
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
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Inventory Requests</h1>
                    <div class="flex flex-row items-center gap-3">
                        <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                        </a>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg whitespace-nowrap flex-shrink-0 inline-flex items-center">
                            <i class="fas fa-plus mr-2"></i>New Request
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
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                placeholder="Search requests..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                        </div>
                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select id="status" name="status" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                                <option value="all">All Status</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                                <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                                <option value="fulfilled" <?php echo $status_filter === 'fulfilled' ? 'selected' : ''; ?>>Fulfilled</option>
                            </select>
                        </div>
                        <div>
                            <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Priority</label>
                            <select id="priority" name="priority" class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md">
                                <option value="all">All Priorities</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Requests List -->
                <div class="space-y-4">
                    <?php if (!empty($requests)): ?>
                        <?php foreach ($requests as $request): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6 border-l-4 
                            <?php 
                            switch($request['priority']) {
                                case 'urgent': echo 'border-l-red-500'; break;
                                case 'high': echo 'border-l-orange-500'; break;
                                case 'medium': echo 'border-l-yellow-500'; break;
                                default: echo 'border-l-blue-500';
                            }
                            ?>">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Request #<?php echo $request['id']; ?></h3>
                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                            <?php 
                                            switch($request['status']) {
                                                case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'approved': echo 'bg-green-100 text-green-800'; break;
                                                case 'rejected': echo 'bg-red-100 text-red-800'; break;
                                                case 'fulfilled': echo 'bg-blue-100 text-blue-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($request['status']); ?>
                                        </span>
                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full
                                            <?php 
                                            switch($request['priority']) {
                                                case 'urgent': echo 'bg-red-100 text-red-800'; break;
                                                case 'high': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'medium': echo 'bg-yellow-100 text-yellow-800'; break;
                                                default: echo 'bg-blue-100 text-blue-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($request['priority']); ?>
                                        </span>
                                    </div>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 text-sm">
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 font-medium">Requested Item:</p>
                                            <p class="text-gray-900 dark:text-white font-semibold"><?php echo htmlspecialchars($request['item_name']); ?> (<?php echo htmlspecialchars($request['item_code']); ?>)</p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 font-medium">Quantity Requested:</p>
                                            <p class="text-gray-900 dark:text-white font-semibold"><?php echo $request['quantity_requested']; ?></p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 font-medium">Requested By:</p>
                                            <p class="text-gray-900 dark:text-white font-semibold"><?php echo htmlspecialchars($request['requester_name']); ?></p>
                                        </div>
                                        <div>
                                            <p class="text-gray-500 dark:text-gray-400 font-medium">Required Date:</p>
                                            <p class="text-gray-900 dark:text-white font-semibold"><?php echo $request['required_date'] ? date('M j, Y', strtotime($request['required_date'])) : 'Not specified'; ?></p>
                                        </div>
                                    </div>
                                    <div class="mb-4 text-sm">
                                        <p class="text-gray-500 dark:text-gray-400 font-medium">Purpose:</p>
                                        <p class="text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($request['purpose']); ?></p>
                                    </div>
                                    <?php if ($request['notes']): ?>
                                    <div class="mb-4 text-sm">
                                        <p class="text-gray-500 dark:text-gray-400 font-medium">Requester Notes:</p>
                                        <p class="text-gray-800 dark:text-gray-200"><?php echo nl2br(htmlspecialchars($request['notes'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($request['remarks']): ?>
                                    <div class="mb-4 text-sm bg-gray-50 dark:bg-gray-750 p-3 rounded-lg">
                                        <p class="text-gray-500 dark:text-gray-400 font-medium">Admin Remarks:</p>
                                        <p class="text-gray-900 dark:text-white"><?php echo nl2br(htmlspecialchars($request['remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <div class="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400">
                                        <span><i class="fas fa-calendar mr-1"></i>Requested: <?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></span>
                                        <?php if ($request['approval_date']): ?>
                                        <span><i class="fas fa-check-circle mr-1"></i>Processed Date: <?php echo date('M j, Y', strtotime($request['approval_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'inventory_manager', 'principal']) && $request['status'] === 'pending'): ?>
                                <div class="flex space-x-2">
                                    <button onclick="openStatusModal(<?php echo $request['id']; ?>, 'approved')" 
                                        class="text-green-600 hover:bg-green-600 hover:text-white px-3 py-1 border border-green-600 rounded-lg transition">
                                        Approve
                                    </button>
                                    <button onclick="openStatusModal(<?php echo $request['id']; ?>, 'rejected')" 
                                        class="text-red-600 hover:bg-red-600 hover:text-white px-3 py-1 border border-red-600 rounded-lg transition">
                                        Reject
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="text-center py-12 bg-white dark:bg-gray-800 rounded-lg border dark:border-gray-700">
                        <i class="fas fa-clipboard-list text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No requests found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-4">
                            <?php if ($search || $status_filter || $priority_filter): ?>
                                Try adjusting your search criteria.
                            <?php else: ?>
                                No inventory requests have been submitted yet.
                            <?php endif; ?>
                        </p>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            Create First Request
                        </a>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-8 flex justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $status_filter ? "&status=$status_filter" : ''; ?><?php echo $priority_filter ? "&priority=$priority_filter" : ''; ?>" 
                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-750 
                            <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600 dark:bg-blue-900/30' : ''; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor; ?>
                    </nav>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<?php if (in_array($user_role, ['super_admin', 'school_admin', 'inventory_manager', 'principal'])): ?>
<!-- Status Update Modal -->
<div id="statusModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800 dark:border-gray-700">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white">Update Request Status</h3>
                <button onclick="closeStatusModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" id="statusRequestId" name="request_id">
                <input type="hidden" id="statusValue" name="status">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Action</label>
                    <p id="statusText" class="mt-1 text-sm font-bold capitalize text-blue-600 dark:text-blue-400"></p>
                </div>
                <div>
                    <label for="statusNotes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Admin Remarks / Notes</label>
                    <textarea id="statusNotes" name="notes" rows="3" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-md"
                        placeholder="Add remarks or justification..."></textarea>
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeStatusModal()" 
                        class="px-4 py-2 bg-gray-300 dark:bg-gray-750 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" name="update_status"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Confirm Action
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openStatusModal(requestId, status) {
    document.getElementById('statusRequestId').value = requestId;
    document.getElementById('statusValue').value = status;
    document.getElementById('statusText').textContent = status === 'approved' ? 'Approve & Disburse Stock' : 'Reject Request';
    document.getElementById('statusModal').classList.remove('hidden');
}

function closeStatusModal() {
    document.getElementById('statusModal').classList.add('hidden');
}
</script>
