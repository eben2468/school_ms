<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$room_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$room_id) {
    header("Location: index.php");
    exit();
}

// Fetch room details
$query = "SELECT hr.*, hb.name as block_name, hb.id as block_id, hb.block_type
          FROM hostel_rooms hr
          JOIN hostel_blocks hb ON hr.block_id = hb.id
          WHERE hr.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $room_id);
$stmt->execute();
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header("Location: index.php");
    exit();
}

// Fetch current occupants
$occupants_query = "SELECT ha.*, s.name as student_name, sp.student_id as student_number
                    FROM hostel_allocations ha
                    JOIN users s ON ha.student_id = s.id
                    LEFT JOIN student_profiles sp ON s.id = sp.user_id
                    WHERE ha.room_id = :room_id AND ha.status = 'active'";
$occupants_stmt = $db->prepare($occupants_query);
$occupants_stmt->bindParam(':room_id', $room_id);
$occupants_stmt->execute();
$occupants = $occupants_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch maintenance requests
$maintenance_query = "SELECT hm.*, ur.name as reporter_name, ua.name as assignee_name
                      FROM hostel_maintenance hm
                      JOIN users ur ON hm.reported_by = ur.id
                      LEFT JOIN users ua ON hm.assigned_to = ua.id
                      WHERE hm.room_id = :room_id
                      ORDER BY hm.created_at DESC
                      LIMIT 10";
$maintenance_stmt = $db->prepare($maintenance_query);
$maintenance_stmt->bindParam(':room_id', $room_id);
$maintenance_stmt->execute();
$maintenance_issues = $maintenance_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Room Detail - Room " . htmlspecialchars($room['room_number']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Rooms', 'url' => 'index.php'],
    ['title' => 'Room ' . htmlspecialchars($room['room_number'])]
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <!-- Header section -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Room <?php echo htmlspecialchars($room['room_number']); ?></h1>
                    <p class="text-sm text-gray-500 mt-1">Block: <?php echo htmlspecialchars($room['block_name']); ?> | Floor: <?php echo htmlspecialchars($room['floor_number']); ?></p>
                </div>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Rooms
                    </a>
                    <a href="edit.php?id=<?php echo $room['id']; ?>" class="inline-flex items-center whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-edit mr-2"></i>Edit Room
                    </a>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Occupancy Status -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Occupancy</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo count($occupants); ?> / <?php echo $room['capacity']; ?></p>
                            <p class="text-xs text-gray-500 mt-1">Beds occupied</p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-bed text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Room Type -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Room Type</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo ucfirst($room['room_type']); ?></p>
                            <p class="text-xs text-gray-500 mt-1">Accommodation level</p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-expand text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Status -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Status</p>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full mt-2
                                <?php 
                                if ($room['status'] === 'available') echo 'bg-green-100 text-green-800';
                                elseif ($room['status'] === 'occupied') echo 'bg-blue-100 text-blue-800';
                                else echo 'bg-amber-100 text-amber-800';
                                ?>">
                                <?php echo ucfirst($room['status']); ?>
                            </span>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-info-circle text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Block Association -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Hostel Block</p>
                            <p class="text-lg font-bold text-gray-800 truncate max-w-[180px]"><?php echo htmlspecialchars($room['block_name']); ?></p>
                            <p class="text-xs text-gray-500 mt-1">Category: <?php echo ucfirst($room['block_type']); ?></p>
                        </div>
                        <div class="p-3 bg-orange-100 rounded-full">
                            <i class="fas fa-building text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Detailed Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left 2 cols: Occupants and Maintenance -->
                <div class="lg:col-span-2 space-y-8">
                    <!-- Current Occupants -->
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Current Occupants</h3>
                            <a href="../allocations/create.php?room_id=<?php echo $room['id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1.5 rounded-lg transition flex items-center">
                                <i class="fas fa-user-plus mr-1"></i>Allocate Student
                            </a>
                        </div>
                        
                        <?php if (empty($occupants)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-users-slash text-gray-400 text-5xl mb-3"></i>
                            <p class="text-gray-500">No students are currently allocated to this room.</p>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Allocation Date</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($occupants as $occupant): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                            <?php echo htmlspecialchars($occupant['student_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($occupant['student_number']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('M j, Y', strtotime($occupant['allocation_date'])); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="../allocations/view.php?id=<?php echo $occupant['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                            <a href="../allocations/edit.php?id=<?php echo $occupant['id']; ?>" class="text-indigo-600 hover:text-indigo-900">Edit/Checkout</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Maintenance History -->
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Recent Maintenance Requests</h3>
                            <a href="../maintenance/create.php?room_id=<?php echo $room['id']; ?>" class="bg-orange-500 hover:bg-orange-600 text-white text-sm px-3 py-1.5 rounded-lg transition flex items-center">
                                <i class="fas fa-tools mr-1"></i>Report Issue
                            </a>
                        </div>
                        
                        <?php if (empty($maintenance_issues)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-check-double text-gray-400 text-5xl mb-3"></i>
                            <p class="text-gray-500">No maintenance issues found for this room.</p>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title / Description</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($maintenance_issues as $issue): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-4">
                                            <div class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($issue['title']); ?></div>
                                            <div class="text-xs text-gray-500 truncate max-w-[250px]"><?php echo htmlspecialchars($issue['description']); ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                <?php 
                                                if ($issue['priority'] === 'high') echo 'bg-red-100 text-red-800';
                                                elseif ($issue['priority'] === 'medium') echo 'bg-orange-100 text-orange-800';
                                                else echo 'bg-gray-100 text-gray-800';
                                                ?>">
                                                <?php echo ucfirst($issue['priority']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                <?php 
                                                if ($issue['status'] === 'resolved') echo 'bg-green-100 text-green-800';
                                                elseif ($issue['status'] === 'in_progress') echo 'bg-blue-100 text-blue-800';
                                                elseif ($issue['status'] === 'cancelled') echo 'bg-red-100 text-red-800';
                                                else echo 'bg-gray-100 text-gray-800';
                                                ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $issue['status'])); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500">
                                            <?php echo date('M j, Y', strtotime($issue['created_at'])); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right 1 col: Room details card -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow border border-gray-200 p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Room Specs</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Hostel Block:</span>
                                    <span class="font-medium text-gray-800">
                                        <a href="../blocks/view.php?id=<?php echo $room['block_id']; ?>" class="text-blue-600 hover:underline">
                                            <?php echo htmlspecialchars($room['block_name']); ?>
                                        </a>
                                    </span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Floor:</span>
                                    <span class="font-medium text-gray-800">Floor <?php echo htmlspecialchars($room['floor_number']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Bed Capacity:</span>
                                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($room['capacity']); ?> Beds</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Room Type:</span>
                                    <span class="font-medium text-gray-800"><?php echo ucfirst($room['room_type']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Current Occupancy:</span>
                                    <span class="font-medium text-gray-800"><?php echo count($occupants); ?> Residents</span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Date Created:</span>
                                    <span class="font-medium text-gray-800"><?php echo date('M j, Y', strtotime($room['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

