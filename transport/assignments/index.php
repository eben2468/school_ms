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

// Retrieve GET parameters
if (isset($_GET['success'])) {
    $success = $_GET['success'];
}

// Handle assignment actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    
    if ($action === 'create' && in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])) {
        $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_SANITIZE_NUMBER_INT);
        $route_id = filter_input(INPUT_POST, 'route_id', FILTER_SANITIZE_NUMBER_INT);
        $driver_id = filter_input(INPUT_POST, 'driver_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $departure_time = filter_input(INPUT_POST, 'departure_time', FILTER_SANITIZE_STRING);
        $return_time = filter_input(INPUT_POST, 'return_time', FILTER_SANITIZE_STRING);
        $effective_date = filter_input(INPUT_POST, 'effective_date', FILTER_SANITIZE_STRING);
        
        if ($vehicle_id && $route_id && $departure_time && $effective_date) {
            try {
                $query = "INSERT INTO transport_assignments (vehicle_id, route_id, driver_id, departure_time, return_time, effective_date) 
                         VALUES (:vehicle_id, :route_id, :driver_id, :departure_time, :return_time, :effective_date)";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':vehicle_id', $vehicle_id);
                $stmt->bindParam(':route_id', $route_id);
                if ($driver_id === null) {
                    $stmt->bindValue(':driver_id', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindParam(':driver_id', $driver_id, PDO::PARAM_INT);
                }
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
    } elseif ($action === 'edit' && in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])) {
        $assignment_id = filter_input(INPUT_POST, 'assignment_id', FILTER_SANITIZE_NUMBER_INT);
        $vehicle_id = filter_input(INPUT_POST, 'vehicle_id', FILTER_SANITIZE_NUMBER_INT);
        $route_id = filter_input(INPUT_POST, 'route_id', FILTER_SANITIZE_NUMBER_INT);
        $driver_id = filter_input(INPUT_POST, 'driver_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
        $departure_time = filter_input(INPUT_POST, 'departure_time', FILTER_SANITIZE_STRING);
        $return_time = filter_input(INPUT_POST, 'return_time', FILTER_SANITIZE_STRING);
        $effective_date = filter_input(INPUT_POST, 'effective_date', FILTER_SANITIZE_STRING);
        
        if ($assignment_id && $vehicle_id && $route_id && $departure_time && $effective_date) {
            try {
                $query = "UPDATE transport_assignments 
                          SET vehicle_id = :vehicle_id, route_id = :route_id, driver_id = :driver_id, 
                              departure_time = :departure_time, return_time = :return_time, effective_date = :effective_date 
                          WHERE id = :assignment_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':vehicle_id', $vehicle_id);
                $stmt->bindParam(':route_id', $route_id);
                if ($driver_id === null) {
                    $stmt->bindValue(':driver_id', null, PDO::PARAM_NULL);
                } else {
                    $stmt->bindParam(':driver_id', $driver_id, PDO::PARAM_INT);
                }
                $stmt->bindParam(':departure_time', $departure_time);
                $stmt->bindParam(':return_time', $return_time);
                $stmt->bindParam(':effective_date', $effective_date);
                $stmt->bindParam(':assignment_id', $assignment_id);
                
                if ($stmt->execute()) {
                    $success = "Transport assignment updated successfully.";
                    header("Location: index.php?success=" . urlencode($success));
                    exit();
                } else {
                    $error = "Failed to update assignment.";
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

// Fetch assignment to edit
$edit_assignment = null;
if (isset($_GET['edit_id']) && in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])) {
    $edit_id = filter_input(INPUT_GET, 'edit_id', FILTER_SANITIZE_NUMBER_INT);
    if ($edit_id) {
        $edit_stmt = $db->prepare("SELECT * FROM transport_assignments WHERE id = :id");
        $edit_stmt->bindParam(':id', $edit_id);
        $edit_stmt->execute();
        $edit_assignment = $edit_stmt->fetch(PDO::FETCH_ASSOC);
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
          tv.vehicle_number, tv.vehicle_type, tv.capacity, tv.driver_name as vehicle_driver,
          td.name as assigned_driver
          FROM transport_assignments ta
          JOIN transport_routes tr ON ta.route_id = tr.id
          JOIN transport_vehicles tv ON ta.vehicle_id = tv.id
          LEFT JOIN transport_drivers td ON ta.driver_id = td.id
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

// Get drivers for selection
$drivers_query = "SELECT id, name FROM transport_drivers WHERE status = 'active' ORDER BY name";
$drivers_stmt = $db->query($drivers_query);
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
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

            <!-- Create/Edit Assignment Form (for authorized users) -->
            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6 border-b border-gray-200 flex justify-between items-center">
                    <h3 class="text-lg font-semibold text-gray-900">
                        <?php echo $edit_assignment ? 'Edit Assignment #' . $edit_assignment['id'] : 'Create New Assignment'; ?>
                    </h3>
                    <?php if ($edit_assignment): ?>
                        <a href="index.php" class="text-sm text-red-650 hover:text-red-800">
                            Cancel Edit
                        </a>
                    <?php endif; ?>
                </div>
                <form action="" method="POST" class="p-6">
                    <input type="hidden" name="action" value="<?php echo $edit_assignment ? 'edit' : 'create'; ?>">
                    <?php if ($edit_assignment): ?>
                        <input type="hidden" name="assignment_id" value="<?php echo $edit_assignment['id']; ?>">
                    <?php endif; ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4">
                        <div>
                            <label for="vehicle_id" class="block text-sm font-medium text-gray-700 mb-2">Vehicle</label>
                            <select id="vehicle_id" name="vehicle_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?php echo $vehicle['id']; ?>"
                                        <?php echo ($edit_assignment && $edit_assignment['vehicle_id'] == $vehicle['id']) ? 'selected' : ''; ?>>
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
                                    <option value="<?php echo $route['id']; ?>"
                                        <?php echo ($edit_assignment && $edit_assignment['route_id'] == $route['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($route['route_code'] . ' - ' . $route['route_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="driver_id" class="block text-sm font-medium text-gray-700 mb-2">Driver</label>
                            <select id="driver_id" name="driver_id"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Default Vehicle Driver</option>
                                <?php foreach ($drivers as $driver): ?>
                                    <option value="<?php echo $driver['id']; ?>"
                                        <?php echo ($edit_assignment && $edit_assignment['driver_id'] == $driver['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($driver['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label for="departure_time" class="block text-sm font-medium text-gray-700 mb-2">Departure Time</label>
                            <input type="time" id="departure_time" name="departure_time" required
                                value="<?php echo $edit_assignment ? htmlspecialchars($edit_assignment['departure_time']) : ''; ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="return_time" class="block text-sm font-medium text-gray-700 mb-2">Return Time</label>
                            <input type="time" id="return_time" name="return_time"
                                value="<?php echo $edit_assignment ? htmlspecialchars($edit_assignment['return_time']) : ''; ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        
                        <div>
                            <label for="effective_date" class="block text-sm font-medium text-gray-700 mb-2">Effective Date</label>
                            <input type="date" id="effective_date" name="effective_date" required
                                value="<?php echo $edit_assignment ? htmlspecialchars($edit_assignment['effective_date']) : date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <div class="mt-4 flex space-x-2">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                            <i class="fas <?php echo $edit_assignment ? 'fa-save' : 'fa-plus'; ?> mr-2"></i>
                            <?php echo $edit_assignment ? 'Update Assignment' : 'Create Assignment'; ?>
                        </button>
                        <?php if ($edit_assignment): ?>
                            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-800 px-4 py-2 rounded-lg font-medium">
                                Cancel
                            </a>
                        <?php endif; ?>
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
                                    <?php 
                                    if (!empty($assignment['assigned_driver'])) {
                                        echo htmlspecialchars($assignment['assigned_driver']) . ' <span class="text-xs text-blue-500 font-semibold">(Assigned)</span>';
                                    } else {
                                        echo htmlspecialchars($assignment['vehicle_driver'] ?: 'Not assigned');
                                    }
                                    ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo date('M j, Y', strtotime($assignment['effective_date'])); ?>
                                </td>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="?edit_id=<?php echo $assignment['id']; ?>" class="text-blue-650 hover:text-blue-900 mr-3 inline-block">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this assignment?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="assignment_id" value="<?php echo $assignment['id']; ?>">
                                        <button type="submit" class="text-red-650 hover:text-red-900">
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
