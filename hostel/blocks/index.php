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

// Handle block status toggle
if (isset($_POST['toggle_status']) && isset($_POST['block_id'])) {
    $block_id = filter_input(INPUT_POST, 'block_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE hostel_blocks SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :block_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':block_id', $block_id);
    if ($stmt->execute()) {
        $success_message = "Block status updated successfully!";
    } else {
        $error_message = "Error updating block status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(hb.name LIKE :search OR hb.description LIKE :search OR u.name LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "hb.status = :status";
    $params[':status'] = $status_filter;
}

if ($gender_filter) {
    $where_conditions[] = "hb.gender = :gender";
    $params[':gender'] = $gender_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch blocks with room and student information
$query = "SELECT hb.*,
          hb.name as block_name,
          COALESCE(hb.description, CONCAT('Block-', hb.id)) as block_code,
          u.name as warden_name,
          COUNT(DISTINCT hr.id) as total_rooms,
          COUNT(DISTINCT CASE WHEN hr.status = 'available' THEN hr.id END) as available_rooms,
          COUNT(DISTINCT ha.id) as total_students
          FROM hostel_blocks hb
          LEFT JOIN users u ON hb.warden_id = u.id
          LEFT JOIN hostel_rooms hr ON hb.id = hr.block_id
          LEFT JOIN hostel_allocations ha ON hb.id = hr.block_id AND ha.room_id = hr.id AND ha.status = 'active'
          $where_clause
          GROUP BY hb.id
          ORDER BY hb.name";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$blocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get block statistics
$stats_query = "SELECT
    COUNT(*) as total_blocks,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_blocks,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_blocks,
    COUNT(CASE WHEN block_type = 'male' THEN 1 END) as male_blocks,
    COUNT(CASE WHEN block_type = 'female' THEN 1 END) as female_blocks
    FROM hostel_blocks";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Hostel Blocks Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar space -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="mb-8" style="margin-top: 30px;">
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Hostel Blocks Management</h1>
                            <p class="text-blue-100 text-lg">Manage hostel blocks and accommodation facilities</p>
                            <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                <div class="flex items-center">
                                    <i class="fas fa-building mr-2"></i>
                                    Block Management
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-bed mr-2"></i>
                                    Room Allocation
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

            <div class="flex justify-between items-center mb-6">
                <div></div>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Hostel
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Add Block
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
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Blocks</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_blocks']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-building text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Blocks</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_blocks']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inactive Blocks</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['inactive_blocks']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Male Blocks</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['male_blocks']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-mars text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Female Blocks</p>
                            <p class="text-2xl font-bold text-pink-600"><?php echo number_format($stats['female_blocks']); ?></p>
                        </div>
                        <div class="p-3 bg-pink-100 rounded-full">
                            <i class="fas fa-venus text-pink-600 text-xl"></i>
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
                                placeholder="Search by block name, code, or warden..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="w-48">
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div class="w-48">
                            <select name="block_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Types</option>
                                <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                                <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                                <option value="mixed" <?php echo $gender_filter === 'mixed' ? 'selected' : ''; ?>>Mixed</option>
                            </select>
                        </div>
                        <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                            Filter
                        </button>
                    </form>
                </div>
            </div>

            <!-- Blocks Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($blocks as $block): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($block['block_name']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo htmlspecialchars($block['block_code']); ?></p>
                            </div>
                            <div class="flex flex-col items-end space-y-1">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php echo $block['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                    <?php echo ucfirst($block['status']); ?>
                                </span>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo ($block['block_type'] ?? 'mixed') === 'male' ? 'bg-blue-100 text-blue-800' : (($block['block_type'] ?? 'mixed') === 'female' ? 'bg-pink-100 text-pink-800' : 'bg-gray-100 text-gray-800'); ?>">
                                    <?php echo ucfirst($block['block_type'] ?? 'Mixed'); ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="text-sm text-gray-600 mb-2">
                                <span class="font-medium">Floors:</span> <?php echo $block['total_floors']; ?>
                            </div>
                            <?php if ($block['warden_name']): ?>
                            <div class="text-sm text-gray-600 mb-2">
                                <span class="font-medium">Warden:</span> <?php echo htmlspecialchars($block['warden_name']); ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($block['description']): ?>
                            <div class="text-sm text-gray-600">
                                <?php echo htmlspecialchars($block['description']); ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-3 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?php echo $block['total_rooms']; ?></div>
                                <div class="text-sm text-gray-600">Total Rooms</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600"><?php echo $block['available_rooms']; ?></div>
                                <div class="text-sm text-gray-600">Available</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-purple-600"><?php echo $block['total_students']; ?></div>
                                <div class="text-sm text-gray-600">Students</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <?php 
                                $occupancy_rate = $block['total_rooms'] > 0 ? (($block['total_rooms'] - $block['available_rooms']) / $block['total_rooms']) * 100 : 0;
                                ?>
                                <div class="bg-blue-600 h-2 rounded-full" style="width: <?php echo $occupancy_rate; ?>%"></div>
                            </div>
                            <div class="text-xs text-gray-600 mt-1">Occupancy: <?php echo number_format($occupancy_rate, 1); ?>%</div>
                        </div>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $block['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $block['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="block_id" value="<?php echo $block['id']; ?>">
                                    <button type="submit" name="toggle_status" 
                                        class="text-<?php echo $block['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $block['status'] === 'active' ? 'red' : 'green'; ?>-800"
                                        title="<?php echo $block['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Block">
                                        <i class="fas fa-<?php echo $block['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($blocks)): ?>
            <div class="text-center py-12">
                <i class="fas fa-building text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No hostel blocks found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $gender_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Get started by creating your first hostel block.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Block
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
