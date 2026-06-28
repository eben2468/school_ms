<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Overall Stats Queries
$stats = [
    'total_routes' => 0,
    'total_vehicles' => 0,
    'active_vehicles' => 0,
    'maintenance_vehicles' => 0,
    'total_drivers' => 0,
    'active_drivers' => 0,
    'total_students' => 0,
    'total_maintenance_cost' => 0.00
];

// Total routes
$stmt = $db->query("SELECT COUNT(*) FROM transport_routes WHERE status = 'active'");
$stats['total_routes'] = $stmt->fetchColumn();

// Vehicles counts
$stmt = $db->query("SELECT 
    COUNT(*), 
    COUNT(CASE WHEN status = 'active' THEN 1 END),
    COUNT(CASE WHEN status = 'maintenance' THEN 1 END)
    FROM transport_vehicles");
$row = $stmt->fetch(PDO::FETCH_NUM);
if ($row) {
    $stats['total_vehicles'] = $row[0];
    $stats['active_vehicles'] = $row[1];
    $stats['maintenance_vehicles'] = $row[2];
}

// Drivers counts
$stmt = $db->query("SELECT COUNT(*), COUNT(CASE WHEN status = 'active' THEN 1 END) FROM transport_drivers");
$row = $stmt->fetch(PDO::FETCH_NUM);
if ($row) {
    $stats['total_drivers'] = $row[0];
    $stats['active_drivers'] = $row[1];
}

// Students on transport
$stmt = $db->query("SELECT COUNT(*) FROM student_transport WHERE status = 'active'");
$stats['total_students'] = $stmt->fetchColumn();

// Maintenance cost
$stmt = $db->query("SELECT SUM(cost) FROM transport_maintenance WHERE status = 'completed'");
$stats['total_maintenance_cost'] = floatval($stmt->fetchColumn() ?: 0.00);

// Route utilization details
$routes_query = "
    SELECT tr.id, tr.route_name, tr.route_code, tr.start_point, tr.end_point, tr.distance_km,
           COUNT(DISTINCT tv.id) as vehicle_count,
           COALESCE(SUM(tv.capacity), 0) as total_capacity,
           COUNT(DISTINCT st.id) as student_count
    FROM transport_routes tr
    LEFT JOIN transport_vehicles tv ON tr.id = tv.route_id
    LEFT JOIN student_transport st ON tr.id = st.route_id AND st.status = 'active'
    GROUP BY tr.id
    ORDER BY tr.route_name
";
$routes_report = $db->query($routes_query)->fetchAll(PDO::FETCH_ASSOC);

// Vehicles by type count
$type_stmt = $db->query("SELECT vehicle_type, COUNT(*) as count FROM transport_vehicles GROUP BY vehicle_type");
$vehicle_types = $type_stmt->fetchAll(PDO::FETCH_ASSOC);

// Scheduled/In Progress Maintenance
$maint_query = "
    SELECT tm.*, tv.vehicle_number, tv.make_model
    FROM transport_maintenance tm
    JOIN transport_vehicles tv ON tm.vehicle_id = tv.id
    WHERE tm.status IN ('scheduled', 'in_progress')
    ORDER BY tm.maintenance_date ASC
    LIMIT 5
";
$upcoming_maintenance = $db->query($maint_query)->fetchAll(PDO::FETCH_ASSOC);

$title = "Transport Analytical Reports";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Reports']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Print Styling -->
<style>
@media print {
    body {
        background-color: white !important;
        color: black !important;
    }
    .sidebar-spacer, header, .no-print, nav, sidebar {
        display: none !important;
    }
    main {
        margin-top: 0 !important;
        padding: 0 !important;
    }
    .print-card {
        border: none !important;
        box-shadow: none !important;
    }
}
</style>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden no-print" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-grow">
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8 no-print">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Transport Analytical Reports</h1>
                                <p class="text-blue-100 text-lg">Detailed stats on fleet metrics, route capacities, student allocation, and maintenance economics</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-chart-pie text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6 no-print">
                    <a href="../index.php" class="inline-flex items-center whitespace-nowrap text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <a href="print.php" target="_blank" class="inline-flex items-center whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition shadow">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </a>
                        <button onclick="exportRoutesCSV()" class="inline-flex items-center whitespace-nowrap bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition shadow">
                            <i class="fas fa-file-csv mr-2"></i>Export Routes CSV
                        </button>
                    </div>
                </div>

                <!-- Print Header -->
                <div class="hidden print:block text-center mb-8 border-b pb-4">
                    <h1 class="text-3xl font-bold text-gray-800">School Management System</h1>
                    <h2 class="text-xl font-semibold text-gray-600">Transport Fleet and Routes Report</h2>
                    <p class="text-xs text-gray-500 mt-2">Generated on: <?php echo date('F j, Y, g:i a'); ?></p>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8 print-card">
                    <!-- Fleet Status Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">Fleet Size</h3>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-3xl font-extrabold text-blue-600 dark:text-blue-400"><?php echo $stats['total_vehicles']; ?></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">(<?php echo $stats['active_vehicles']; ?> Active)</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 mt-4">
                            <?php 
                            $v_rate = $stats['total_vehicles'] > 0 ? ($stats['active_vehicles'] / $stats['total_vehicles']) * 100 : 0;
                            ?>
                            <div class="bg-blue-600 h-2.5 rounded-full" style="width: <?php echo $v_rate; ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2"><?php echo $stats['maintenance_vehicles']; ?> vehicles in shop</p>
                    </div>

                    <!-- Students Allocated Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">Students Transported</h3>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-3xl font-extrabold text-green-600 dark:text-green-400"><?php echo number_format($stats['total_students']); ?></span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">Enrolled Users</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 mt-4">
                            <div class="bg-green-500 h-2.5 rounded-full" style="width: 100%"></div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Distributed across <?php echo $stats['total_routes']; ?> routes</p>
                    </div>

                    <!-- Drivers Status Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">Staffing Rate</h3>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-3xl font-extrabold text-purple-600 dark:text-purple-400">
                                <?php 
                                $driver_ratio = $stats['total_vehicles'] > 0 ? ($stats['total_drivers'] / $stats['total_vehicles']) * 100 : 0;
                                echo number_format($driver_ratio, 1) . '%';
                                ?>
                            </span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">(<?php echo $stats['total_drivers']; ?> Drivers)</span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 mt-4">
                            <div class="bg-purple-600 h-2.5 rounded-full" style="width: <?php echo min(100, $driver_ratio); ?>%"></div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2"><?php echo $stats['active_drivers']; ?> Active drivers available</p>
                    </div>

                    <!-- Maintenance Costs Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-xs font-bold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-2">Total Repair Outlay</h3>
                        <div class="flex items-baseline space-x-2">
                            <span class="text-3xl font-extrabold text-orange-600 dark:text-orange-400">₵<?php echo number_format($stats['total_maintenance_cost'], 2); ?></span>
                        </div>
                        <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5 mt-4">
                            <div class="bg-orange-500 h-2.5 rounded-full" style="width: 100%"></div>
                        </div>
                        <p class="text-xs text-gray-400 dark:text-gray-500 mt-2">Sum of all completed maintenance bills</p>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                    <!-- Route Capacity & Utilization -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden lg:col-span-2 print-card">
                        <div class="p-6 bg-gray-50 dark:bg-gray-750 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white flex items-center">
                                <i class="fas fa-route text-blue-500 mr-2"></i>
                                Route Capacity & Utilization
                            </h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-100 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Route Code & Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Vehicles</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Beds/Capacity</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Students</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Utilization</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($routes_report as $route): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($route['route_code']); ?></div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($route['route_name']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300"><?php echo $route['vehicle_count']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300"><?php echo $route['total_capacity']; ?> seats</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300"><?php echo $route['student_count']; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <?php 
                                            $util_rate = $route['total_capacity'] > 0 ? ($route['student_count'] / $route['total_capacity']) * 100 : 0;
                                            ?>
                                            <div class="flex items-center space-x-2">
                                                <span class="font-bold text-gray-700 dark:text-gray-300"><?php echo number_format($util_rate, 1); ?>%</span>
                                                <div class="w-16 bg-gray-200 dark:bg-gray-700 h-1.5 rounded">
                                                    <div class="h-1.5 rounded <?php echo $util_rate > 90 ? 'bg-red-500' : ($util_rate > 50 ? 'bg-green-500' : 'bg-blue-500'); ?>" 
                                                         style="width: <?php echo min(100, $util_rate); ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($routes_report)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                            No route statistics available.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Fleet Distribution & Tech Summary -->
                    <div class="space-y-8">
                        <!-- Fleet Breakdown -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 bg-gray-50 dark:bg-gray-750 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-md font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-bus text-indigo-500 mr-2"></i>
                                    Fleet Breakdown
                                </h3>
                            </div>
                            <div class="p-6 space-y-4 text-sm text-gray-600 dark:text-gray-400">
                                <?php foreach ($vehicle_types as $type): ?>
                                <div class="flex justify-between items-center border-b dark:border-gray-700 pb-2">
                                    <span class="capitalize"><?php echo htmlspecialchars($type['vehicle_type']); ?>s:</span>
                                    <span class="font-bold text-gray-900 dark:text-white"><?php echo $type['count']; ?></span>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($vehicle_types)): ?>
                                <div class="text-center text-gray-500">No vehicles registered yet.</div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Maintenance Pipeline -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 bg-gray-50 dark:bg-gray-750 border-b border-gray-200 dark:border-gray-700">
                                <h3 class="text-md font-semibold text-gray-800 dark:text-white flex items-center">
                                    <i class="fas fa-wrench text-orange-500 mr-2"></i>
                                    Maintenance Queue
                                </h3>
                            </div>
                            <div class="p-6 space-y-4">
                                <?php foreach ($upcoming_maintenance as $maint): ?>
                                <div class="border-b dark:border-gray-700 pb-3 last:border-b-0 last:pb-0">
                                    <div class="flex justify-between items-start">
                                        <div>
                                            <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($maint['vehicle_number']); ?></span>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">(<?php echo htmlspecialchars($maint['make_model']); ?>)</span>
                                        </div>
                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full 
                                            <?php echo $maint['status'] === 'in_progress' ? 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; ?>">
                                            <?php echo ucfirst($maint['status']); ?>
                                        </span>
                                    </div>
                                    <div class="flex justify-between items-center text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        <span>Type: <?php echo ucfirst($maint['maintenance_type']); ?></span>
                                        <span>Due: <?php echo date('Y-m-d', strtotime($maint['maintenance_date'])); ?></span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if (empty($upcoming_maintenance)): ?>
                                <div class="text-center py-4 text-sm text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                    No active maintenance logs.
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0 no-print">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function exportRoutesCSV() {
    const data = [
        <?php foreach ($routes_report as $route): ?>
        {
            'Route Code': '<?php echo addslashes($route['route_code']); ?>',
            'Route Name': '<?php echo addslashes($route['route_name']); ?>',
            'Start Point': '<?php echo addslashes($route['start_point']); ?>',
            'End Point': '<?php echo addslashes($route['end_point']); ?>',
            'Distance (KM)': '<?php echo $route['distance_km'] ?? 'N/A'; ?>',
            'Vehicle Count': '<?php echo $route['vehicle_count']; ?>',
            'Capacity': '<?php echo $route['total_capacity']; ?>',
            'Assigned Students': '<?php echo $route['student_count']; ?>',
            'Utilization (%)': '<?php echo number_format($route['total_capacity'] > 0 ? ($route['student_count'] / $route['total_capacity']) * 100 : 0, 1); ?>%'
        },
        <?php endforeach; ?>
    ];

    ExportUtils.exportArrayToCSV(
        data,
        ExportUtils.generateFilename('transport_route_utilization_report'),
        ['Route Code', 'Route Name', 'Start Point', 'End Point', 'Distance (KM)', 'Vehicle Count', 'Capacity', 'Assigned Students', 'Utilization (%)']
    );
}
</script>
