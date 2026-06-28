<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('hostel');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('hostel'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Get hostel statistics
$stats = [
    'total_blocks' => 0,
    'total_rooms' => 0,
    'total_students' => 0,
    'available_rooms' => 0
];

// Get total blocks
$blocks_query = "SELECT COUNT(*) as count FROM hostel_blocks WHERE status = 'active'";
$blocks_stmt = $db->prepare($blocks_query);
$blocks_stmt->execute();
$stats['total_blocks'] = $blocks_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get total rooms
$rooms_query = "SELECT COUNT(*) as count FROM hostel_rooms";
$rooms_stmt = $db->prepare($rooms_query);
$rooms_stmt->execute();
$stats['total_rooms'] = $rooms_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get available rooms
$available_rooms_query = "SELECT COUNT(*) as count FROM hostel_rooms WHERE status = 'available'";
$available_rooms_stmt = $db->prepare($available_rooms_query);
$available_rooms_stmt->execute();
$stats['available_rooms'] = $available_rooms_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get students in hostel
$students_query = "SELECT COUNT(*) as count FROM hostel_allocations WHERE status = 'active'";
$students_stmt = $db->prepare($students_query);
$students_stmt->execute();
$stats['total_students'] = $students_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent allocations
$recent_allocations_query = "
    SELECT ha.*, u.name as student_name, hr.room_number,
           COALESCE(hb.name, 'Unknown Block') as block_name
    FROM hostel_allocations ha
    JOIN users u ON ha.student_id = u.id AND u.role = 'student'
    JOIN hostel_rooms hr ON ha.room_id = hr.id
    JOIN hostel_blocks hb ON hr.block_id = hb.id
    WHERE ha.status = 'active'
    ORDER BY ha.created_at DESC
    LIMIT 5
";
$recent_allocations_stmt = $db->prepare($recent_allocations_query);
$recent_allocations_stmt->execute();
$recent_allocations = $recent_allocations_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Hostel Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Hostel Management']
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
                    <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Hostel Management</h1>
                                <p class="text-indigo-100 text-lg">Manage hostel blocks, rooms, and student accommodations</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-indigo-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-building mr-2"></i>
                                        <?php echo number_format($stats['total_blocks']); ?> Blocks
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-bed mr-2"></i>
                                        <?php echo number_format($stats['total_rooms']); ?> Rooms
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-user-graduate mr-2"></i>
                                        <?php echo number_format($stats['total_students']); ?> Students
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-building text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                        <a href="blocks/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center whitespace-nowrap">
                            <i class="fas fa-plus mr-2"></i>Add Block
                        </a>
                        <a href="rooms/create.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center whitespace-nowrap">
                            <i class="fas fa-bed mr-2"></i>Add Room
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportHostelData()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Blocks -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Blocks</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_blocks']); ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-building mr-1"></i>
                                    Active blocks
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-building text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Total Rooms -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Rooms</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['total_rooms']); ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-bed mr-1"></i>
                                    All rooms
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bed text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Available Rooms -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Available Rooms</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['available_rooms']); ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-check-circle mr-1"></i>
                                    Ready for allocation
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Students in Hostel -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Students in Hostel</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['total_students']); ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-user-graduate mr-1"></i>
                                    Current residents
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-graduate text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Hostel Management Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Blocks Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                                    <i class="fas fa-building text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                <a href="blocks/create.php" class="text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Hostel Blocks</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage hostel blocks and their facilities.</p>
                            <a href="blocks/index.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Blocks</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Rooms Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                                    <i class="fas fa-bed text-green-600 dark:text-green-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                <a href="rooms/create.php" class="text-green-500 hover:text-green-600 dark:text-green-400 dark:hover:text-green-300 p-2 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Rooms</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage individual rooms and their capacity.</p>
                            <a href="rooms/index.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Rooms</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Student Allocations -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                                    <i class="fas fa-user-graduate text-purple-600 dark:text-purple-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                <a href="allocations/create.php" class="text-purple-500 hover:text-purple-600 dark:text-purple-400 dark:hover:text-purple-300 p-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Student Allocations</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Allocate students to hostel rooms.</p>
                            <a href="allocations/index.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Allocations</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Maintenance -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                                    <i class="fas fa-tools text-orange-600 dark:text-orange-400 text-xl"></i>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                <a href="maintenance/create.php" class="text-orange-500 hover:text-orange-600 dark:text-orange-400 dark:hover:text-orange-300 p-2 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/50 transition-colors duration-200">
                                    <i class="fas fa-plus"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Maintenance</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Track hostel maintenance and repairs.</p>
                            <a href="maintenance/index.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Maintenance</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Fees & Payments -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                                    <i class="fas fa-dollar-sign text-indigo-600 dark:text-indigo-400 text-xl"></i>
                                </div>
                                <a href="fees/index.php" class="text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 dark:hover:text-indigo-300 p-2 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/50 transition-colors duration-200">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Fees & Payments</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage hostel fees and payment tracking.</p>
                            <a href="fees/index.php" class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>Manage Fees</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Reports -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center group-hover:bg-red-200 dark:group-hover:bg-red-800 transition-colors duration-200">
                                    <i class="fas fa-chart-bar text-red-600 dark:text-red-400 text-xl"></i>
                                </div>
                                <a href="reports/index.php" class="text-red-500 hover:text-red-600 dark:text-red-400 dark:hover:text-red-300 p-2 rounded-lg hover:bg-red-50 dark:hover:bg-red-900/50 transition-colors duration-200">
                                    <i class="fas fa-chart-bar"></i>
                                </a>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Hostel Reports</h3>
                            <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Generate occupancy and financial reports.</p>
                            <a href="reports/index.php" class="inline-flex items-center text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                                <span>View Reports</span>
                                <i class="fas fa-arrow-right ml-2"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Recent Allocations -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Allocations</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">Latest student room allocations</p>
                            </div>
                            <a href="allocations/index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium">
                                View All Allocations
                            </a>
                        </div>
                    </div>

                    <?php if (!empty($recent_allocations)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Block</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Room</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Allocated Date</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($recent_allocations as $allocation): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-r from-purple-500 to-pink-600 rounded-full flex items-center justify-center text-white font-semibold mr-3">
                                                <?php echo strtoupper(substr($allocation['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['student_name']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                            <i class="fas fa-building mr-1"></i>
                                            <?php echo htmlspecialchars($allocation['block_name']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                            <i class="fas fa-bed mr-1"></i>
                                            Room <?php echo htmlspecialchars($allocation['room_number']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-alt mr-2 text-gray-500"></i>
                                            <?php echo date('M j, Y', strtotime($allocation['created_at'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <a href="allocations/view.php?id=<?php echo $allocation['id']; ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800 transition-colors duration-200">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </a>
                                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                            <a href="allocations/edit.php?id=<?php echo $allocation['id']; ?>"
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
                            <i class="fas fa-user-graduate text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No allocations found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-4">Get started by allocating students to hostel rooms.</p>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                        <a href="allocations/create.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Create First Allocation
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
function exportHostelData() {
    ExportUtils.showExportModal({
        title: 'Export Hostel Data',
        csvCallback: () => {
            // Prepare data for export
            const data = [
                <?php foreach ($recent_allocations as $allocation): ?>
                {
                    'Student': '<?php echo addslashes($allocation['student_name']); ?>',
                    'Block': '<?php echo addslashes($allocation['block_name']); ?>',
                    'Room': '<?php echo addslashes($allocation['room_number']); ?>',
                    'Allocation Date': '<?php echo date('Y-m-d', strtotime($allocation['allocation_date'])); ?>',
                    'Status': '<?php echo ucfirst(str_replace('_', ' ', $allocation['status'])); ?>'
                },
                <?php endforeach; ?>
            ];

            ExportUtils.exportArrayToCSV(
                data,
                ExportUtils.generateFilename('hostel_allocations'),
                ['Student', 'Block', 'Room', 'Allocation Date', 'Status']
            );
            ExportUtils.showSuccessMessage('Hostel data exported successfully!');
        },
        pdfCallback: () => {
            ExportUtils.exportToPDF('Hostel Allocations Report', 'main');
        }
    });
}
</script>
