<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
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

$stmt = $db->prepare("SELECT * FROM transport_routes WHERE id = :id");
$stmt->bindParam(':id', $id);
$stmt->execute();
$route = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$route) {
    header("Location: index.php");
    exit();
}

// Stops along the route (ordered).
$s_stmt = $db->prepare("SELECT * FROM transport_stops WHERE route_id = :id ORDER BY stop_order");
$s_stmt->execute([':id' => $id]);
$stops = $s_stmt->fetchAll(PDO::FETCH_ASSOC);

// Vehicles assigned to this route.
$v_stmt = $db->prepare("SELECT id, vehicle_number, vehicle_type, capacity, status, driver_name
                        FROM transport_vehicles WHERE route_id = :id ORDER BY vehicle_number");
$v_stmt->execute([':id' => $id]);
$vehicles = $v_stmt->fetchAll(PDO::FETCH_ASSOC);

// Students subscribed to this route (active).
$st_count = $db->prepare("SELECT COUNT(*) FROM student_transport WHERE route_id = :id AND status = 'active'");
$st_count->execute([':id' => $id]);
$student_count = (int)$st_count->fetchColumn();

$status_badge = $route['status'] === 'active'
    ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';

$title = "Route Details";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Routes', 'url' => 'index.php'],
    ['title' => 'Route Details']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';

function rfld($v) { return ($v === null || $v === '') ? '<span class="text-gray-400 italic">Not set</span>' : htmlspecialchars($v); }
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6 flex-wrap gap-3">
                <div>
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">
                        <i class="fas fa-route text-purple-600 mr-2"></i><?php echo htmlspecialchars($route['route_name']); ?>
                    </h1>
                    <div class="mt-2 flex items-center gap-2">
                        <span class="text-sm text-gray-500">Code: <strong><?php echo htmlspecialchars($route['route_code']); ?></strong></span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold <?php echo $status_badge; ?>"><?php echo ucfirst($route['status']); ?></span>
                    </div>
                </div>
                <div class="flex space-x-3">
                    <a href="edit.php?id=<?php echo $id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-edit mr-2"></i>Edit
                    </a>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Routes
                    </a>
                </div>
            </div>

            <!-- Summary cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-sm text-gray-500">Distance</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $route['distance_km'] !== null ? htmlspecialchars($route['distance_km']) . ' km' : '—'; ?></p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-sm text-gray-500">Estimated Time</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $route['estimated_time_minutes'] !== null ? (int)$route['estimated_time_minutes'] . ' min' : '—'; ?></p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-sm text-gray-500">Stops</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($stops); ?></p>
                </div>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5">
                    <p class="text-sm text-gray-500">Students</p>
                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $student_count; ?></p>
                </div>
            </div>

            <!-- Route information -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Route Information</h2>
                </div>
                <div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <dt class="text-sm font-medium text-gray-500">Start Point</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><i class="fas fa-map-marker-alt text-green-500 mr-1"></i><?php echo htmlspecialchars($route['start_point']); ?></dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-gray-500">End Point</dt>
                        <dd class="mt-1 text-lg text-gray-900 dark:text-white"><i class="fas fa-flag-checkered text-red-500 mr-1"></i><?php echo htmlspecialchars($route['end_point']); ?></dd>
                    </div>
                    <div class="md:col-span-2">
                        <dt class="text-sm font-medium text-gray-500">Description</dt>
                        <dd class="mt-1 text-gray-900 dark:text-white whitespace-pre-line"><?php echo rfld($route['description']); ?></dd>
                    </div>
                </div>
            </div>

            <!-- Stops -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-map-pin mr-2 text-blue-500"></i>Stops</h2>
                </div>
                <div class="p-6">
                    <?php if (empty($stops)): ?>
                        <p class="text-gray-500 text-center py-4">No stops have been added to this route yet.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">#</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Stop Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Address</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Pickup</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Drop</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($stops as $s): ?>
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo (int)$s['stop_order']; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($s['stop_name']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo rfld($s['stop_address']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $s['pickup_time'] ? date('g:i A', strtotime($s['pickup_time'])) : '—'; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo $s['drop_time'] ? date('g:i A', strtotime($s['drop_time'])) : '—'; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Vehicles on this route -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><i class="fas fa-bus mr-2 text-green-500"></i>Vehicles on this Route</h2>
                </div>
                <div class="p-6">
                    <?php if (empty($vehicles)): ?>
                        <p class="text-gray-500 text-center py-4">No vehicles are assigned to this route.</p>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Vehicle</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Type</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Capacity</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Driver</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($vehicles as $v): ?>
                                <tr>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($v['vehicle_number']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo ucfirst($v['vehicle_type']); ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo (int)$v['capacity']; ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-900 dark:text-white"><?php echo rfld($v['driver_name']); ?></td>
                                    <td class="px-4 py-3 text-sm"><span class="px-2 py-1 rounded-full text-xs font-semibold <?php echo $v['status'] === 'active' ? 'bg-green-100 text-green-800' : ($v['status'] === 'maintenance' ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>"><?php echo ucfirst($v['status']); ?></span></td>
                                    <td class="px-4 py-3 text-sm text-right"><a href="../vehicles/view.php?id=<?php echo $v['id']; ?>" class="text-blue-600 hover:text-blue-800">View</a></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
