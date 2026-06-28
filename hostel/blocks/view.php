<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$block_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$block_id) {
    header("Location: index.php");
    exit();
}

// Fetch block details
$query = "SELECT hb.*, u.name as warden_name, u.email as warden_email, NULL as warden_phone
          FROM hostel_blocks hb
          LEFT JOIN users u ON hb.warden_id = u.id
          WHERE hb.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $block_id);
$stmt->execute();
$block = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$block) {
    header("Location: index.php");
    exit();
}

// Fetch rooms in this block
$rooms_query = "SELECT hr.*, 
                COUNT(DISTINCT ha.id) as current_occupants
                FROM hostel_rooms hr
                LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
                WHERE hr.block_id = :block_id
                GROUP BY hr.id
                ORDER BY hr.floor_number, hr.room_number";
$rooms_stmt = $db->prepare($rooms_query);
$rooms_stmt->bindParam(':block_id', $block_id);
$rooms_stmt->execute();
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$total_rooms = count($rooms);
$available_rooms = 0;
$occupied_rooms = 0;
$maintenance_rooms = 0;
$total_capacity = 0;
$current_residents = 0;

foreach ($rooms as $room) {
    $total_capacity += $room['capacity'];
    $current_residents += $room['current_occupants'];
    
    if ($room['status'] === 'available') {
        $available_rooms++;
    } elseif ($room['status'] === 'occupied') {
        $occupied_rooms++;
    } elseif ($room['status'] === 'maintenance') {
        $maintenance_rooms++;
    }
}

$title = "Block Detail - " . htmlspecialchars($block['name']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Blocks', 'url' => 'index.php'],
    ['title' => htmlspecialchars($block['name'])]
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-8 flex-grow">
        <div class="max-w-7xl mx-auto">
            <!-- Header section -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($block['name']); ?></h1>
                    <p class="text-sm text-gray-500 mt-1">Hostel Block Details & Room Directory</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Blocks
                    </a>
                    <a href="edit.php?id=<?php echo $block['id']; ?>" class="inline-flex items-center whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-edit mr-2"></i>Edit Block
                    </a>
                </div>
            </div>

            <!-- Dashboard Stats -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Occupancy Rate -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Occupancy Rate</p>
                            <p class="text-2xl font-bold text-blue-600">
                                <?php 
                                $rate = $total_capacity > 0 ? ($current_residents / $total_capacity) * 100 : 0;
                                echo number_format($rate, 1) . '%';
                                ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $current_residents; ?> of <?php echo $total_capacity; ?> beds occupied</p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-chart-pie text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Rooms -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Rooms</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo $total_rooms; ?></p>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $available_rooms; ?> Available, <?php echo $maintenance_rooms; ?> Maintenance</p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-door-open text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Gender/Block Type -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Block Type</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo ucfirst($block['block_type']); ?></p>
                            <p class="text-xs text-gray-500 mt-1">Hostel category</p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-venus-mars text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Warden details -->
                <div class="bg-white rounded-xl shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Assigned Warden</p>
                            <p class="text-lg font-bold text-gray-800 truncate max-w-[180px]">
                                <?php echo $block['warden_name'] ? htmlspecialchars($block['warden_name']) : 'Unassigned'; ?>
                            </p>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $block['warden_email'] ? htmlspecialchars($block['warden_email']) : 'N/A'; ?></p>
                        </div>
                        <div class="p-3 bg-orange-100 rounded-full">
                            <i class="fas fa-user-shield text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main Layout Details -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- Left 2 cols: Rooms Directory -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-xl shadow border border-gray-200 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 bg-gray-50 flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Rooms Directory</h3>
                            <a href="../rooms/create.php?block_id=<?php echo $block['id']; ?>" class="bg-blue-500 hover:bg-blue-600 text-white text-sm px-3 py-1.5 rounded-lg transition flex items-center">
                                <i class="fas fa-plus mr-1"></i>Add Room
                            </a>
                        </div>
                        
                        <?php if (empty($rooms)): ?>
                        <div class="text-center py-12">
                            <i class="fas fa-bed text-gray-400 text-5xl mb-3"></i>
                            <p class="text-gray-500">No rooms found in this block.</p>
                        </div>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room #</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Floor</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Occupancy</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($rooms as $room): ?>
                                    <tr class="hover:bg-gray-50 transition">
                                        <td class="px-6 py-4 whitespace-nowrap font-medium text-gray-900">
                                            Room <?php echo htmlspecialchars($room['room_number']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($room['floor_number']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo ucfirst($room['room_type']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <div class="flex items-center space-x-2">
                                                <span><?php echo $room['current_occupants']; ?> / <?php echo $room['capacity']; ?></span>
                                                <div class="w-16 bg-gray-200 rounded-full h-1.5">
                                                    <?php 
                                                    $pct = $room['capacity'] > 0 ? ($room['current_occupants'] / $room['capacity']) * 100 : 0;
                                                    ?>
                                                    <div class="bg-blue-600 h-1.5 rounded-full" style="width: <?php echo $pct; ?>%"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                <?php 
                                                if ($room['status'] === 'available') echo 'bg-green-100 text-green-800';
                                                elseif ($room['status'] === 'occupied') echo 'bg-blue-100 text-blue-800';
                                                else echo 'bg-amber-100 text-amber-800';
                                                ?>">
                                                <?php echo ucfirst($room['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <a href="../rooms/view.php?id=<?php echo $room['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                            <a href="../rooms/edit.php?id=<?php echo $room['id']; ?>" class="text-indigo-600 hover:text-indigo-900">Edit</a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Right 1 col: Block Info Card -->
                <div class="lg:col-span-1">
                    <div class="bg-white rounded-xl shadow border border-gray-200 p-6 space-y-6">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Block Details</h3>
                            <div class="space-y-3 text-sm">
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Block Code / Code Name:</span>
                                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($block['name']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Total Floors:</span>
                                    <span class="font-medium text-gray-800"><?php echo htmlspecialchars($block['total_floors']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Status:</span>
                                    <span class="font-medium text-gray-800"><?php echo ucfirst($block['status']); ?></span>
                                </div>
                                <div class="flex justify-between">
                                    <span class="text-gray-500">Date Created:</span>
                                    <span class="font-medium text-gray-800"><?php echo date('M j, Y', strtotime($block['created_at'])); ?></span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-3 border-b pb-2">Description / Info</h3>
                            <p class="text-sm text-gray-600 leading-relaxed">
                                <?php echo $block['description'] ? nl2br(htmlspecialchars($block['description'])) : 'No additional description provided.'; ?>
                            </p>
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
