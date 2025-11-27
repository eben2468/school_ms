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

// Handle vehicle status toggle
if (isset($_POST['toggle_status']) && isset($_POST['vehicle_id'])) {
    $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE transport_vehicles SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :vehicle_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':vehicle_id', $vehicle_id);
    if ($stmt->execute()) {
        $success_message = "Vehicle status updated successfully!";
    } else {
        $error_message = "Error updating vehicle status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(vehicle_number LIKE :search OR driver_name LIKE :search OR model LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "tv.status = :status";
    $params[':status'] = $status_filter;
}

if ($type_filter) {
    $where_conditions[] = "tv.vehicle_type = :type";
    $params[':type'] = $type_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch vehicles with route information
$query = "SELECT tv.*, tr.route_name, tr.route_code,
          COUNT(DISTINCT st.id) as student_count
          FROM transport_vehicles tv
          LEFT JOIN transport_routes tr ON tv.route_id = tr.id
          LEFT JOIN student_transport st ON tv.route_id = st.route_id AND st.status = 'active'
          $where_clause
          GROUP BY tv.id
          ORDER BY tv.vehicle_number";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get vehicle statistics
$stats_query = "SELECT 
    COUNT(*) as total_vehicles,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_vehicles,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_vehicles,
    COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_vehicles
    FROM transport_vehicles";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Vehicles Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Vehicles Management</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Vehicle
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

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Vehicles</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_vehicles']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-bus text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Vehicles</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_vehicles']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">In Maintenance</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['maintenance_vehicles']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-wrench text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inactive Vehicles</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['inactive_vehicles']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by vehicle number, driver, or model..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                        <div class="w-48">
                            <select name="type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Types</option>
                                <option value="bus" <?php echo $type_filter === 'bus' ? 'selected' : ''; ?>>Bus</option>
                                <option value="van" <?php echo $type_filter === 'van' ? 'selected' : ''; ?>>Van</option>
                                <option value="car" <?php echo $type_filter === 'car' ? 'selected' : ''; ?>>Car</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Vehicles Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($vehicles as $vehicle): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($vehicle['vehicle_number']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo htmlspecialchars($vehicle['model'] ?? 'N/A'); ?></p>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                switch($vehicle['status']) {
                                    case 'active': echo 'bg-green-100 text-green-800'; break;
                                    case 'maintenance': echo 'bg-yellow-100 text-yellow-800'; break;
                                    case 'inactive': echo 'bg-red-100 text-red-800'; break;
                                    default: echo 'bg-gray-100 text-gray-800';
                                }
                                ?>">
                                <?php echo ucfirst($vehicle['status']); ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-car text-blue-500 mr-2"></i>
                                <span class="font-medium">Type:</span>
                                <span class="ml-1"><?php echo ucfirst($vehicle['vehicle_type'] ?? 'N/A'); ?></span>
                            </div>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-users text-green-500 mr-2"></i>
                                <span class="font-medium">Capacity:</span>
                                <span class="ml-1"><?php echo $vehicle['capacity']; ?> seats</span>
                            </div>
                            <?php if ($vehicle['driver_name']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-user text-purple-500 mr-2"></i>
                                <span class="font-medium">Driver:</span>
                                <span class="ml-1"><?php echo htmlspecialchars($vehicle['driver_name']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <?php if ($vehicle['route_name']): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900">Route: <?php echo htmlspecialchars($vehicle['route_name']); ?></div>
                                <div class="text-gray-600">Code: <?php echo htmlspecialchars($vehicle['route_code']); ?></div>
                                <div class="text-gray-600">Students: <?php echo $vehicle['student_count']; ?></div>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mb-4 p-3 bg-yellow-50 rounded-lg">
                            <div class="text-sm text-yellow-800">
                                <i class="fas fa-exclamation-triangle mr-1"></i>
                                No route assigned
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?php echo $vehicle['student_count']; ?></div>
                                <div class="text-sm text-gray-600">Students</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600"><?php echo $vehicle['capacity']; ?></div>
                                <div class="text-sm text-gray-600">Capacity</div>
                            </div>
                        </div>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $vehicle['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $vehicle['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="vehicle_id" value="<?php echo $vehicle['id']; ?>">
                                    <button type="submit" name="toggle_status" 
                                        class="text-<?php echo $vehicle['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $vehicle['status'] === 'active' ? 'red' : 'green'; ?>-800"
                                        title="<?php echo $vehicle['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Vehicle">
                                        <i class="fas fa-<?php echo $vehicle['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($vehicles)): ?>
            <div class="text-center py-12">
                <i class="fas fa-bus text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No vehicles found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $type_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Get started by adding your first vehicle.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Add First Vehicle
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
