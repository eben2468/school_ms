<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hostel_warden'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $total_floors = filter_input(INPUT_POST, 'total_floors', FILTER_SANITIZE_NUMBER_INT);
    $rooms_per_floor = filter_input(INPUT_POST, 'rooms_per_floor', FILTER_SANITIZE_NUMBER_INT);
    $capacity_per_room = filter_input(INPUT_POST, 'capacity_per_room', FILTER_SANITIZE_NUMBER_INT);
    $block_type = filter_input(INPUT_POST, 'block_type', FILTER_SANITIZE_STRING);
    $warden_id = filter_input(INPUT_POST, 'warden_id', FILTER_SANITIZE_NUMBER_INT);

    if ($name && $total_floors && $rooms_per_floor && $capacity_per_room) {
        try {
            $db->beginTransaction();

            // Insert hostel block
            $query = "INSERT INTO hostel_blocks (name, description, total_floors, rooms_per_floor, capacity_per_room, block_type, warden_id, created_at)
                     VALUES (:name, :description, :total_floors, :rooms_per_floor, :capacity_per_room, :block_type, :warden_id, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':total_floors', $total_floors);
            $stmt->bindParam(':rooms_per_floor', $rooms_per_floor);
            $stmt->bindParam(':capacity_per_room', $capacity_per_room);
            $stmt->bindParam(':block_type', $block_type);
            $stmt->bindParam(':warden_id', $warden_id);
            $stmt->execute();

            $block_id = $db->lastInsertId();

            // Auto-create rooms for the block
            $total_rooms = $total_floors * $rooms_per_floor;
            for ($floor = 1; $floor <= $total_floors; $floor++) {
                for ($room = 1; $room <= $rooms_per_floor; $room++) {
                    $room_number = $floor . sprintf('%02d', $room);
                    $room_query = "INSERT INTO hostel_rooms (block_id, room_number, floor_number, capacity, status, created_at)
                                  VALUES (:block_id, :room_number, :floor_number, :capacity, 'available', NOW())";
                    $room_stmt = $db->prepare($room_query);
                    $room_stmt->bindParam(':block_id', $block_id);
                    $room_stmt->bindParam(':room_number', $room_number);
                    $room_stmt->bindParam(':floor_number', $floor);
                    $room_stmt->bindParam(':capacity', $capacity_per_room);
                    $room_stmt->execute();
                }
            }

            $db->commit();
            $success = "Hostel block created successfully with $total_rooms rooms!";
        } catch (PDOException $e) {
            $db->rollBack();
            $error = "Error creating hostel block: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get wardens for dropdown
$wardens_query = "SELECT id, name FROM users WHERE role IN ('hostel_warden', 'super_admin', 'school_admin') ORDER BY name";
$wardens_stmt = $db->query($wardens_query);
$wardens = $wardens_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Hostel Block";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'], ['title' => 'Hostel', 'url' => '../index.php'], ['title' => 'Blocks', 'url' => 'index.php'], ['title' => 'Create Block']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Hostel Block</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
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

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Block Name *</label>
                                    <input type="text" id="name" name="name" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="block_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Block Type</label>
                                    <select id="block_type" name="block_type"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="boys">Boys</option>
                                        <option value="girls">Girls</option>
                                        <option value="mixed">Mixed</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="total_floors" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Total Floors *</label>
                                    <input type="number" id="total_floors" name="total_floors" min="1" max="20" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="rooms_per_floor" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Rooms per Floor *</label>
                                    <input type="number" id="rooms_per_floor" name="rooms_per_floor" min="1" max="50" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="capacity_per_room" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Capacity per Room *</label>
                                    <input type="number" id="capacity_per_room" name="capacity_per_room" min="1" max="10" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="warden_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Assign Warden</label>
                                    <select id="warden_id" name="warden_id"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Warden</option>
                                        <?php foreach ($wardens as $warden): ?>
                                            <option value="<?php echo $warden['id']; ?>"><?php echo htmlspecialchars($warden['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                <textarea id="description" name="description" rows="3"
                                          class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                          placeholder="Enter block description, facilities, rules, etc."></textarea>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Block
                                </button>
                            </div>
                        </form>
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