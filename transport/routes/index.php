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

// Handle route status toggle
if (isset($_POST['toggle_status']) && isset($_POST['route_id'])) {
    $route_id = filter_input(INPUT_POST, 'route_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE transport_routes SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :route_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':route_id', $route_id);
    if ($stmt->execute()) {
        $success_message = "Route status updated successfully!";
    } else {
        $error_message = "Error updating route status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(route_name LIKE :search OR route_code LIKE :search OR start_point LIKE :search OR end_point LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "tr.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch routes with vehicle and student count
$query = "SELECT tr.*, 
          tv.vehicle_number, tv.driver_name,
          COUNT(DISTINCT st.id) as student_count
          FROM transport_routes tr
          LEFT JOIN transport_vehicles tv ON tr.id = tv.route_id
          LEFT JOIN student_transport st ON tr.id = st.route_id AND st.status = 'active'
          $where_clause
          GROUP BY tr.id
          ORDER BY tr.route_name";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get route statistics
$stats_query = "SELECT 
    COUNT(*) as total_routes,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_routes,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_routes,
    AVG(distance_km) as avg_distance
    FROM transport_routes";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Routes Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

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
                                <h1 class="text-3xl font-bold mb-2">Routes Management</h1>
                                <p class="text-blue-100 text-lg">Manage transport routes and schedules</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-route mr-2"></i>
                                        Route Planning
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-map-marker-alt mr-2"></i>
                                        Stop Management
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-route text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <div class="flex justify-between items-center mb-6">
                <div></div>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="../index.php" class="inline-flex items-center whitespace-nowrap text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                    <a href="create.php" class="inline-flex items-center whitespace-nowrap bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Route
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
                            <p class="text-sm font-medium text-gray-600">Total Routes</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_routes']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-route text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Routes</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_routes']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inactive Routes</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['inactive_routes']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Avg Distance</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['avg_distance'], 1); ?> km</p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-road text-purple-600 text-xl"></i>
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
                                placeholder="Search by route name, code, or location..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Routes Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($routes as $route): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($route['route_name']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo htmlspecialchars($route['route_code']); ?></p>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $route['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($route['status']); ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-map-marker-alt text-green-500 mr-2"></i>
                                <span class="font-medium">From:</span>
                                <span class="ml-1"><?php echo htmlspecialchars($route['start_point']); ?></span>
                            </div>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-map-marker-alt text-red-500 mr-2"></i>
                                <span class="font-medium">To:</span>
                                <span class="ml-1"><?php echo htmlspecialchars($route['end_point']); ?></span>
                            </div>
                            <?php if ($route['distance_km']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-road text-blue-500 mr-2"></i>
                                <span class="font-medium">Distance:</span>
                                <span class="ml-1"><?php echo number_format($route['distance_km'], 1); ?> km</span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?php echo $route['student_count']; ?></div>
                                <div class="text-sm text-gray-600">Students</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600">
                                    <?php echo $route['vehicle_number'] ? '1' : '0'; ?>
                                </div>
                                <div class="text-sm text-gray-600">Vehicle</div>
                            </div>
                        </div>

                        <?php if ($route['vehicle_number']): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900">Vehicle: <?php echo htmlspecialchars($route['vehicle_number']); ?></div>
                                <?php if ($route['driver_name']): ?>
                                <div class="text-gray-600">Driver: <?php echo htmlspecialchars($route['driver_name']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $route['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $route['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="route_id" value="<?php echo $route['id']; ?>">
                                    <button type="submit" name="toggle_status" 
                                        class="text-<?php echo $route['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $route['status'] === 'active' ? 'red' : 'green'; ?>-800"
                                        title="<?php echo $route['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Route">
                                        <i class="fas fa-<?php echo $route['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($routes)): ?>
            <div class="text-center py-12">
                <i class="fas fa-route text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No routes found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Get started by creating your first transport route.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Route
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
