<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle assignment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    
    if ($action === 'create' && in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])) {
        $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_SANITIZE_NUMBER_INT);
        $route_id = filter_input(INPUT_POST, 'route_id', FILTER_SANITIZE_NUMBER_INT);
        $departure_time = filter_input(INPUT_POST, 'departure_time', FILTER_SANITIZE_STRING);
        $return_time = filter_input(INPUT_POST, 'return_time', FILTER_SANITIZE_STRING);
        $effective_date = filter_input(INPUT_POST, 'effective_date', FILTER_SANITIZE_STRING);
        
        if ($vehicle_id && $route_id && $departure_time && $effective_date) {
            try {
                $query = "INSERT INTO transport_assignments (vehicle_id, route_id, departure_time, return_time, effective_date) 
                         VALUES (:vehicle_id, :route_id, :departure_time, :return_time, :effective_date)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':vehicle_id', $vehicle_id);
                $stmt->bindParam(':route_id', $route_id);
                $stmt->bindParam(':departure_time', $departure_time);
                $stmt->bindParam(':return_time', $return_time);
                $stmt->bindParam(':effective_date', $effective_date);
                
                if ($stmt->execute()) {
                    $success = "Transport assignment created successfully.";
                } else {
                    $error = "Failed to create assignment.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        } else {
            $error = "All required fields must be filled.";
        }
    } elseif ($action === 'delete' && in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])) {
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_SANITIZE_NUMBER_INT);
        
        if ($assignment_id) {
            try {
                $query = "DELETE FROM transport_assignments WHERE id = :assignment_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':assignment_id', $assignment_id);
                
                if ($stmt->execute()) {
                    $success = "Assignment deleted successfully.";
                } else {
                    $error = "Failed to delete assignment.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$route_filter = filter_input(INPUT_GET, 'route', FILTER_SANITIZE_NUMBER_INT) ?: '';
$vehicle_filter = filter_input(INPUT_GET, 'vehicle', FILTER_SANITIZE_NUMBER_INT) ?: '';

// Build where conditions
$where_conditions = ["ta.status = 'active'"];
$params = [];

if ($route_filter) {
    $where_conditions[] = "ta.route_id = :route_filter";
    $params[':route_filter'] = $route_filter;
}

if ($vehicle_filter) {
    $where_conditions[] = "ta.vehicle_id = :vehicle_filter";
    $params[':vehicle_filter'] = $vehicle_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get transport assignments
$query = "SELECT ta.*, 
          tr.route_name, tr.route_code, tr.start_point, tr.end_point,
          tv.vehicle_number, tv.vehicle_type, tv.capacity, tv.driver_name
          FROM transport_assignments ta
          JOIN transport_routes tr ON ta.route_id = tr.id
          JOIN transport_vehicles tv ON ta.vehicle_id = tv.id
          $where_clause
          ORDER BY ta.departure_time";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get routes and vehicles for filters and form
$routes_query = "SELECT id, route_name, route_code FROM transport_routes WHERE status = 'active' ORDER BY route_name";
$routes_stmt = $db->query($routes_query);
$routes = $routes_stmt->fetchAll(PDO::FETCH_ASSOC);

$vehicles_query = "SELECT id, vehicle_number, vehicle_type, capacity FROM transport_vehicles WHERE status = 'active' ORDER BY vehicle_number";
$vehicles_stmt = $db->query($vehicles_query);
$vehicles = $vehicles_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

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
                <h1 class="text-3xl font-semibold text-gray-800">Transport Assignments</h1>
                <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Transport
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

            <!-- Create Assignment Form (for authorized users) -->
            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Create New Assignment</h3>
                </div>
                <form action="" method="POST" class="p-6">
                    <input type="hidden" name="action" value="create">
                    <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label for="vehicle_id" class="block text-sm font-medium text-gray-700 mb-2">Vehicle</label>
                            <select id="vehicle_id" name="vehicle_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>">
                                        <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' (' . $vehicle['vehicle_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="route_id" class="block text-sm font-medium text-gray-700 mb-2">Route</label>
                            <select id="route_id" name="route_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select route...</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo $route['id']; ?>">
                                        <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="departure_time" class="block text-sm font-medium text-gray-700 mb-2">Departure Time</label>
                            <input type="time" id="departure_time" name="departure_time" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="return_time" class="block text-sm font-medium text-gray-700 mb-2">Return Time</label>
                            <input type="time" id="return_time" name="return_time"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="effective_date" class="block text-sm font-medium text-gray-700 mb-2">Effective Date</label>
                            <input type="date" id="effective_date" name="effective_date" required
                                value="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Create Assignment
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <select name="route" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Routes</option>
                                <?php foreach ($routes as $route): ?>
                                    <option value="<?php echo $route['id']; ?>" 
                                        <?php echo $route_filter == $route['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex-grow">
                            <select name="vehicle" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Vehicles</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>" 
                                        <?php echo $vehicle_filter == $vehicle['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($vehicle['vehicle_number'] . ' (' . $vehicle['vehicle_type'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-filter mr-2"></i>Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Assignments List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vehicle</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Route</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Schedule</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Driver</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Effective Date</th>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($assignments as $assignment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($assignment['vehicle_number']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($assignment['vehicle_type']); ?> (<?php echo $assignment['capacity']; ?> seats)</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($assignment['route_code']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($assignment['start_point'] . ' → ' . $assignment['end_point']); ?></div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">
                                        Departure: <?php echo date('g:i A', strtotime($assignment['departure_time'])); ?>
                                    </div>
                                    <?php if ($assignment['return_time']): ?>
                                    <div class="text-sm text-gray-500">
                                        Return: <?php echo date('g:i A', strtotime($assignment['return_time'])); ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($assignment['driver_name'] ?: 'Not assigned'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M j, Y', strtotime($assignment['effective_date'])); ?>
                                </td>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this assignment?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-900">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($assignments)): ?>
            <div class="text-center py-12">
                <i class="fas fa-route text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No transport assignments found</h3>
                <p class="text-gray-500">
                    <?php if ($route_filter || $vehicle_filter): ?>
                        Try adjusting your filter criteria.
                    <?php else: ?>
                        Start by creating transport assignments for your vehicles and routes.
                    <?php endif; ?>
                </p>
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
