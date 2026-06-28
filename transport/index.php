<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('transport');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('transport'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Get transport statistics
$stats = [
    'total_routes' => 0,
    'total_vehicles' => 0,
    'total_students' => 0,
    'active_vehicles' => 0
];

// Get total routes
$routes_query = "SELECT COUNT(*) as count FROM transport_routes WHERE status = 'active'";
$routes_stmt = $db->prepare($routes_query);
$routes_stmt->execute();
$stats['total_routes'] = $routes_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total vehicles
$vehicles_query = "SELECT COUNT(*) as count FROM transport_vehicles";
$vehicles_stmt = $db->prepare($vehicles_query);
$vehicles_stmt->execute();
$stats['total_vehicles'] = $vehicles_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get active vehicles
$active_vehicles_query = "SELECT COUNT(*) as count FROM transport_vehicles WHERE status = 'active'";
$active_vehicles_stmt = $db->prepare($active_vehicles_query);
$active_vehicles_stmt->execute();
$stats['active_vehicles'] = $active_vehicles_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get students using transport
$students_query = "SELECT COUNT(*) as count FROM student_transport WHERE status = 'active'";
$students_stmt = $db->prepare($students_query);
$students_stmt->execute();
$stats['total_students'] = $students_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent routes
$recent_routes_query = "
    SELECT tr.*, tv.vehicle_number, tv.driver_name,
           COUNT(st.id) as student_count
    FROM transport_routes tr
    LEFT JOIN transport_vehicles tv ON tr.id = tv.route_id
    LEFT JOIN student_transport st ON tr.id = st.route_id AND st.status = 'active'
    WHERE tr.status = 'active'
    GROUP BY tr.id
    ORDER BY tr.created_at DESC
    LIMIT 5
";
$recent_routes_stmt = $db->prepare($recent_routes_query);
$recent_routes_stmt->execute();
$recent_routes = $recent_routes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Transport Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Transport Management']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Transport Management</h1>
                                <p class="text-green-100 text-lg">Manage school transport routes, vehicles, and student assignments</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-green-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-route mr-2"></i>
                                        <?php echo number_format($stats['total_routes']); ?> Routes
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-bus mr-2"></i>
                                        <?php echo number_format($stats['total_vehicles']); ?> Vehicles
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-user-graduate mr-2"></i>
                                        <?php echo number_format($stats['total_students']); ?> Students
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-bus text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                        <a href="routes/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center whitespace-nowrap">
                            <i class="fas fa-plus mr-2"></i>Add Route
                        </a>
                        <a href="vehicles/create.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center whitespace-nowrap">
                            <i class="fas fa-bus mr-2"></i>Add Vehicle
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportTransportData()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Routes -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Routes</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_routes']); ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-route mr-1"></i>
                                    Active routes
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-route text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Total Vehicles -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Vehicles</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['total_vehicles']); ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-bus mr-1"></i>
                                    Fleet size
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bus text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Active Vehicles -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Vehicles</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['active_vehicles']); ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    In service
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Students Using Transport -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Students Using Transport</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['total_students']); ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-user-graduate mr-1"></i>
                                    Enrolled students
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-graduate text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transport Management Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Routes Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                                    <i class="fas fa-route text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <a href="routes/create.php" class="text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Routes</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage transport routes and stops.</p>
                            <a href="routes/index.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Routes</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Vehicles Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                                    <i class="fas fa-bus text-green-600 dark:text-green-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <a href="vehicles/create.php" class="text-green-500 hover:text-green-600 dark:text-green-400 dark:hover:text-green-300 p-2 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Vehicles</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage vehicles and driver assignments.</p>
                            <a href="vehicles/index.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Vehicles</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Student Assignments -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                                    <i class="fas fa-user-graduate text-purple-600 dark:text-purple-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <a href="assignments/index.php" class="text-purple-500 hover:text-purple-600 dark:text-purple-400 dark:hover:text-purple-300 p-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/50 transition-colors duration-200" title="Manage Student Assignments">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Student Assignments</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Assign students to transport routes.</p>
                            <a href="assignments/index.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Assignments</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Drivers Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                                    <i class="fas fa-id-card text-orange-600 dark:text-orange-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <a href="drivers/create.php" class="text-orange-500 hover:text-orange-600 dark:text-orange-400 dark:hover:text-orange-300 p-2 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Drivers</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage driver information and licenses.</p>
                            <a href="drivers/index.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Drivers</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Transport Reports -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                                    <i class="fas fa-chart-bar text-indigo-600 dark:text-indigo-400 text-xl"></i>
                                </div>
                                <a href="reports/index.php" class="text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 dark:hover:text-indigo-300 p-2 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/50 transition-colors duration-200" title="View Transport Reports">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Transport Reports</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Generate transport usage and efficiency reports.</p>
                            <a href="reports/index.php" class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>View Reports</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Maintenance -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center group-hover:bg-red-200 dark:group-hover:bg-red-800 transition-colors duration-200">
                                    <i class="fas fa-tools text-red-600 dark:text-red-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                <a href="maintenance/index.php" class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/50 transition-colors duration-200" title="Manage Maintenance">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Maintenance</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Track vehicle maintenance and repairs.</p>
                            <a href="maintenance/index.php" class="inline-flex items-center text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Maintenance</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Routes -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Routes</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Latest transport routes and their status</p>
                            </div>
                            <a href="routes/index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium">
                                View All Routes
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($recent_routes)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Route</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Code</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Vehicle</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Driver</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Students</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Distance</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($recent_routes as $route): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-green-600 rounded-full flex items-center justify-center text-white font-semibold mr-3">
                                                <i class="fas fa-route"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($route['route_name']); ?></div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($route['start_point'] . ' → ' . $route['end_point']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            <?php echo htmlspecialchars($route['route_code']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <?php if ($route['vehicle_number']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-bus mr-2 text-green-500"></i>
                                                <?php echo htmlspecialchars($route['vehicle_number']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <?php if ($route['driver_name']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-user mr-2 text-orange-500"></i>
                                                <?php echo htmlspecialchars($route['driver_name']); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500">Not Assigned</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                            <i class="fas fa-user-graduate mr-1"></i>
                                            <?php echo $route['student_count']; ?> students
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <?php if ($route['distance_km']): ?>
                                            <div class="flex items-center">
                                                <i class="fas fa-road mr-2 text-gray-500"></i>
                                                <?php echo number_format($route['distance_km'], 1) . ' km'; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-gray-400 dark:text-gray-500">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <a href="routes/view.php?id=<?php echo $route['id']; ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800 transition-colors duration-200">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </a>
                                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                                            <a href="routes/edit.php?id=<?php echo $route['id']; ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 dark:bg-indigo-900 dark:text-indigo-200 dark:hover:bg-indigo-800 transition-colors duration-200">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-route text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No routes found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-4">Get started by creating your first transport route.</p>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
                        <a href="routes/create.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Create First Route
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function exportTransportData() {
    ExportUtils.showExportModal({
        title: 'Export Transport Data',
        csvCallback: () => {
            // Prepare data for export
            const data = [
                <?php foreach ($routes as $route): ?>
                {
                    'Route Name': '<?php echo addslashes($route['route_name']); ?>',
                    'Vehicle': '<?php echo addslashes($route['vehicle_number'] ?? 'N/A'); ?>',
                    'Driver': '<?php echo addslashes($route['driver_name'] ?? 'N/A'); ?>',
                    'Capacity': '<?php echo $route['capacity'] ?? 'N/A'; ?>',
                    'Students': '<?php echo $route['student_count'] ?? 0; ?>',
                    'Status': '<?php echo ucfirst($route['status']); ?>'
                },
                <?php endforeach; ?>
            ];

            ExportUtils.exportArrayToCSV(
                data,
                ExportUtils.generateFilename('transport_routes'),
                ['Route Name', 'Vehicle', 'Driver', 'Capacity', 'Students', 'Status']
            );
            ExportUtils.showSuccessMessage('Transport data exported successfully!');
        },
        pdfCallback: () => {
            ExportUtils.exportToPDF('Transport Routes Report', 'main');
        }
    });
}
</script>
