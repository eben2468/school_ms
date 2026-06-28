<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle room status toggle
if (isset($_POST['toggle_status']) && isset($_POST['room_id'])) {
    $room_id = filter_input(INPUT_POST, 'room_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE hostel_rooms SET status = CASE WHEN status = 'available' THEN 'occupied' ELSE 'available' END WHERE id = :room_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    if ($stmt->execute()) {
        $success_message = "Room status updated successfully!";
    } else {
        $error_message = "Error updating room status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$block_filter = isset($_GET['block']) ? $_GET['block'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$floor_filter = isset($_GET['floor']) ? $_GET['floor'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(hr.room_number LIKE :search OR hb.name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($block_filter) {
    $where_conditions[] = "hr.block_id = :block";
    $params[':block'] = $block_filter;
}

if ($status_filter) {
    $where_conditions[] = "hr.status = :status";
    $params[':status'] = $status_filter;
}

if ($floor_filter) {
    $where_conditions[] = "hr.floor_number = :floor";
    $params[':floor'] = $floor_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch rooms with block and student information
$query = "SELECT hr.*,
          hb.name as block_name,
          COALESCE(hb.description, CONCAT('BLK-', hb.id)) as block_code,
          COALESCE(hb.block_type, 'mixed') as gender,
          COUNT(DISTINCT ha.id) as student_count
          FROM hostel_rooms hr
          JOIN hostel_blocks hb ON hr.block_id = hb.id
          LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
          $where_clause
          GROUP BY hr.id
          ORDER BY hb.name, hr.floor_number, hr.room_number";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get blocks for filter
$blocks_query = "SELECT id, name as block_name FROM hostel_blocks ORDER BY name";
$blocks_stmt = $db->query($blocks_query);
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get room statistics
$stats_query = "SELECT 
    COUNT(*) as total_rooms,
    COUNT(CASE WHEN status = 'available' THEN 1 END) as available_rooms,
    COUNT(CASE WHEN status = 'occupied' THEN 1 END) as occupied_rooms,
    COUNT(CASE WHEN status = 'maintenance' THEN 1 END) as maintenance_rooms
    FROM hostel_rooms";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Hostel Rooms Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Hostel Rooms Management</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Hostel
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Room
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
                            <p class="text-sm font-medium text-gray-600">Total Rooms</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_rooms']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-door-open text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Available</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['available_rooms']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Occupied</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['occupied_rooms']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-user text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Maintenance</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['maintenance_rooms']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-wrench text-yellow-600 text-xl"></i>
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
                                placeholder="Search by room number or block name..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="block" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Blocks</option>
                                <?php foreach ($blocks as $block): ?>
                                <option value="<?php echo $block['id']; ?>" <?php echo $block_filter == $block['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($block['block_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Available</option>
                                <option value="occupied" <?php echo $status_filter === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                <option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                            </select>
                        </div>
                        <div class="w-32">
                            <input type="number" name="floor" value="<?php echo htmlspecialchars($floor_filter); ?>" 
                                placeholder="Floor" min="1" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Rooms Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <?php foreach ($rooms as $room): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800">Room <?php echo htmlspecialchars($room['room_number']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo htmlspecialchars($room['block_name']); ?></p>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php 
                                switch($room['status']) {
                                    case 'available': echo 'bg-green-100 text-green-800'; break;
                                    case 'occupied': echo 'bg-red-100 text-red-800'; break;
                                    case 'maintenance': echo 'bg-yellow-100 text-yellow-800'; break;
                                    default: echo 'bg-gray-100 text-gray-800';
                                }
                                ?>">
                                <?php echo ucfirst($room['status']); ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-layer-group text-blue-500 mr-2"></i>
                                <span class="font-medium">Floor:</span>
                                <span class="ml-1"><?php echo $room['floor_number']; ?></span>
                            </div>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-bed text-green-500 mr-2"></i>
                                <span class="font-medium">Capacity:</span>
                                <span class="ml-1"><?php echo $room['capacity']; ?> students</span>
                            </div>
                            <div class="flex items-center text-sm text-gray-600 mb-2">
                                <i class="fas fa-<?php echo $room['gender'] === 'male' ? 'mars' : 'venus'; ?> text-purple-500 mr-2"></i>
                                <span class="font-medium">Gender:</span>
                                <span class="ml-1"><?php echo ucfirst($room['gender']); ?></span>
                            </div>
                            <?php if ($room['room_type']): ?>
                            <div class="flex items-center text-sm text-gray-600">
                                <i class="fas fa-home text-yellow-500 mr-2"></i>
                                <span class="font-medium">Type:</span>
                                <span class="ml-1"><?php echo ucfirst($room['room_type']); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?php echo $room['student_count']; ?></div>
                                <div class="text-sm text-gray-600">Current</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600"><?php echo $room['capacity']; ?></div>
                                <div class="text-sm text-gray-600">Capacity</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <?php 
                                $occupancy_rate = $room['capacity'] > 0 ? ($room['student_count'] / $room['capacity']) * 100 : 0;
                                ?>
                                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo min($occupancy_rate, 100); ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-600 mt-1">Occupancy: <?php echo number_format($occupancy_rate, 1); ?>%</div>
                        </div>

                        <?php if (isset($room['amenities']) && !empty($room['amenities'])): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm">
                                <div class="font-medium text-gray-900 mb-1">Amenities:</div>
                                <div class="text-gray-600"><?php echo htmlspecialchars($room['amenities']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $room['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $room['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($room['status'] !== 'maintenance'): ?>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="room_id" value="<?php echo $room['id']; ?>">
                                    <button type="submit" name="toggle_status" 
                                        class="text-<?php echo $room['status'] === 'available' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $room['status'] === 'available' ? 'red' : 'green'; ?>-800"
                                        title="Mark as <?php echo $room['status'] === 'available' ? 'Occupied' : 'Available'; ?>">
                                        <i class="fas fa-<?php echo $room['status'] === 'available' ? 'user' : 'check'; ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($rooms)): ?>
            <div class="text-center py-12">
                <i class="fas fa-door-open text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No rooms found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $block_filter || $status_filter || $floor_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Get started by creating your first hostel room.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Room
                </a>
            </div>
            <?php endif; ?>
                </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

