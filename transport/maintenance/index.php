<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle new maintenance log creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_maintenance'])) {
    $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_SANITIZE_NUMBER_INT);
    $maintenance_type = filter_input(INPUT_POST, 'maintenance_type', FILTER_SANITIZE_STRING);
    $cost = filter_input(INPUT_POST, 'cost', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $maintenance_date = filter_input(INPUT_POST, 'maintenance_date', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $performed_by = filter_input(INPUT_POST, 'performed_by', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $next_due_date = filter_input(INPUT_POST, 'next_due_date', FILTER_SANITIZE_STRING);
    
    if (empty($next_due_date)) {
        $next_due_date = null;
    }

    if (empty($vehicle_id) || empty($maintenance_type) || empty($maintenance_date) || empty($description) || empty($performed_by)) {
        $error_message = "Please fill in all required fields.";
    } else {
        try {
            $query = "INSERT INTO transport_maintenance 
                      (vehicle_id, maintenance_type, cost, maintenance_date, description, performed_by, status, next_due_date) 
                      VALUES 
                      (:vehicle_id, :maintenance_type, :cost, :maintenance_date, :description, :performed_by, :status, :next_due_date)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':vehicle_id', $vehicle_id);
            $stmt->bindParam(':maintenance_type', $maintenance_type);
            $stmt->bindParam(':cost', $cost);
            $stmt->bindParam(':maintenance_date', $maintenance_date);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':performed_by', $performed_by);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':next_due_date', $next_due_date);
            
            if ($stmt->execute()) {
                // If status is completed or in_progress, update vehicle status in DB to reflect maintenance status
                if ($status === 'in_progress') {
                    $v_query = "UPDATE transport_vehicles SET status = 'maintenance' WHERE id = :vehicle_id";
                    $v_stmt = $db->prepare($v_query);
                    $v_stmt->bindParam(':vehicle_id', $vehicle_id);
                    $v_stmt->execute();
                }
                
                $success_message = "Maintenance record added successfully.";
            } else {
                $error_message = "Error saving maintenance record.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Handle status updates from list view
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $record_id = filter_input(INPUT_POST, 'record_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
    
    if (!empty($record_id) && !empty($new_status)) {
        try {
            $query = "UPDATE transport_maintenance SET status = :status WHERE id = :record_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':status', $new_status);
            $stmt->bindParam(':record_id', $record_id);
            
            if ($stmt->execute()) {
                // Update vehicle status based on maintenance status
                $v_stmt = $db->prepare("SELECT vehicle_id FROM transport_maintenance WHERE id = :record_id");
                $v_stmt->bindParam(':record_id', $record_id);
                $v_stmt->execute();
                $v_id = $v_stmt->fetchColumn();
                
                if ($new_status === 'completed' || $new_status === 'cancelled') {
                    // Reset vehicle status back to active (if active is default)
                    $v_query = "UPDATE transport_vehicles SET status = 'active' WHERE id = :v_id AND status = 'maintenance'";
                    $v_update = $db->prepare($v_query);
                    $v_update->bindParam(':v_id', $v_id);
                    $v_update->execute();
                } elseif ($new_status === 'in_progress') {
                    $v_query = "UPDATE transport_vehicles SET status = 'maintenance' WHERE id = :v_id";
                    $v_update = $db->prepare($v_query);
                    $v_update->bindParam(':v_id', $v_id);
                    $v_update->execute();
                }
                
                $success_message = "Maintenance status updated successfully!";
            } else {
                $error_message = "Error updating maintenance status.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(tv.vehicle_number LIKE :search OR tv.make_model LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "tm.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch maintenance logs
$logs_query = "
    SELECT tm.*, tv.vehicle_number, tv.make_model 
    FROM transport_maintenance tm
    JOIN transport_vehicles tv ON tm.vehicle_id = tv.id
    $where_clause
    ORDER BY tm.maintenance_date DESC
";
$logs_stmt = $db->prepare($logs_query);
foreach ($params as $key => $value) {
    $logs_stmt->bindValue($key, $value);
}
$logs_stmt->execute();
$logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active vehicles for dropdown selection
$vehicles_query = "SELECT id, vehicle_number, make_model FROM transport_vehicles WHERE status != 'inactive' ORDER BY vehicle_number";
$vehicles = $db->query($vehicles_query)->fetchAll(PDO::FETCH_ASSOC);

$title = "Vehicle Maintenance Logs";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Maintenance Logs']
];

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
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Vehicle Maintenance Management</h1>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                </div>
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

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Log Maintenance Form (Left side/col-span-1) -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 self-start">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4 border-b dark:border-gray-700 pb-3 flex items-center">
                        <i class="fas fa-tools text-orange-500 mr-2"></i>Log Vehicle Maintenance
                    </h2>

                    <form action="" method="POST" class="space-y-4">
                        <input type="hidden" name="add_maintenance" value="1">

                        <div>
                            <label for="vehicle_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Select Vehicle *</label>
                            <select id="vehicle_id" name="vehicle_id" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>">
                                        <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' (' . ($vehicle['make_model'] ?: 'N/A') . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="maintenance_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Maintenance Type *</label>
                            <select id="maintenance_type" name="maintenance_type" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="routine">Routine Checkup</option>
                                <option value="repair">Repair</option>
                                <option value="inspection">Inspection</option>
                                <option value="other">Other</option>
                            </select>
                        </div>

                        <div>
                            <label for="cost" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Cost (₵) *</label>
                            <input type="number" id="cost" name="cost" required min="0" step="0.01"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="0.00">
                        </div>

                        <div>
                            <label for="maintenance_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service Date *</label>
                            <input type="date" id="maintenance_date" name="maintenance_date" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo date('Y-m-d'); ?>">
                        </div>

                        <div>
                            <label for="next_due_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Next Due Date</label>
                            <input type="date" id="next_due_date" name="next_due_date"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label for="performed_by" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Technician / Workshop *</label>
                            <input type="text" id="performed_by" name="performed_by" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="e.g., Auto Mechanic Shop">
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Initial Status</label>
                            <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="scheduled">Scheduled</option>
                                <option value="in_progress">In Progress</option>
                                <option value="completed">Completed</option>
                            </select>
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Service Description *</label>
                            <textarea id="description" name="description" required rows="3"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Details of repairs/service..."></textarea>
                        </div>

                        <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 rounded-lg transition duration-200">
                            <i class="fas fa-save mr-2"></i>Save Log Entry
                        </button>
                    </form>
                </div>

                <!-- Logs Table List (Right side/col-span-2) -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden lg:col-span-2">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <!-- Filters -->
                        <form action="" method="GET" class="flex flex-col md:flex-row gap-4 justify-between items-center">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white w-full md:w-auto">Service Records History</h2>
                            
                            <div class="flex flex-col md:flex-row gap-2 w-full md:w-auto">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                    placeholder="Search vehicle number or model..." 
                                    class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                
                                <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                                    <option value="">All Statuses</option>
                                    <option value="scheduled" <?php echo $status_filter === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                                    <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
                                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                </select>
                                
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                                    Filter
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date & Cost</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Technician</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($logs as $log): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($log['vehicle_number']); ?></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($log['make_model'] ?: 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-300 capitalize">
                                            <?php echo htmlspecialchars($log['maintenance_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <div><?php echo date('Y-m-d', strtotime($log['maintenance_date'])); ?></div>
                                        <div class="text-xs text-green-600 font-semibold">₵<?php echo number_format($log['cost'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-355">
                                        <?php echo htmlspecialchars($log['performed_by']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                            <?php 
                                            switch($log['status']) {
                                                case 'completed': echo 'bg-green-100 text-green-800'; break;
                                                case 'in_progress': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'scheduled': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'cancelled': echo 'bg-red-100 text-red-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($log['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <?php if ($log['status'] !== 'completed' && $log['status'] !== 'cancelled'): ?>
                                        <div class="flex justify-end space-x-1">
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="record_id" value="<?php echo $log['id']; ?>">
                                                <input type="hidden" name="new_status" value="completed">
                                                <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-2 py-1 rounded text-xs transition duration-200">
                                                    Complete
                                                </button>
                                            </form>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="update_status" value="1">
                                                <input type="hidden" name="record_id" value="<?php echo $log['id']; ?>">
                                                <input type="hidden" name="new_status" value="cancelled">
                                                <button type="submit" class="bg-red-500 hover:bg-red-600 text-white px-2 py-1 rounded text-xs transition duration-200">
                                                    Cancel
                                                </button>
                                            </form>
                                        </div>
                                        <?php else: ?>
                                        <span class="text-xs text-gray-400">Archived</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($logs)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                        No maintenance logs found matching search criteria.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
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
