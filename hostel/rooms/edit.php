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

$success = '';
$error = '';

// Fetch room details
$query = "SELECT * FROM hostel_rooms WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $room_id);
$stmt->execute();
$room = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$room) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $block_id = filter_input(INPUT_POST, 'block_id', FILTER_SANITIZE_NUMBER_INT);
    $room_number = filter_input(INPUT_POST, 'room_number', FILTER_SANITIZE_STRING);
    $floor_number = filter_input(INPUT_POST, 'floor_number', FILTER_SANITIZE_NUMBER_INT);
    $room_type = filter_input(INPUT_POST, 'room_type', FILTER_SANITIZE_STRING);
    $capacity = filter_input(INPUT_POST, 'capacity', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

    if ($block_id && $room_number && $floor_number !== false && $room_type && $capacity) {
        try {
            // Check for duplicate room number in the same block (excluding this room)
            $check_query = "SELECT COUNT(*) FROM hostel_rooms WHERE block_id = :block_id AND room_number = :room_number AND id != :id";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':block_id', $block_id);
            $check_stmt->bindParam(':room_number', $room_number);
            $check_stmt->bindParam(':id', $room_id);
            $check_stmt->execute();
            
            if ($check_stmt->fetchColumn() > 0) {
                $error = "Room number already exists in this block.";
            } else {
                $update_query = "UPDATE hostel_rooms 
                                 SET block_id = :block_id, room_number = :room_number, floor_number = :floor_number, 
                                     room_type = :room_type, capacity = :capacity, status = :status
                                 WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':block_id', $block_id);
                $update_stmt->bindParam(':room_number', $room_number);
                $update_stmt->bindParam(':floor_number', $floor_number);
                $update_stmt->bindParam(':room_type', $room_type);
                $update_stmt->bindParam(':capacity', $capacity);
                $update_stmt->bindParam(':status', $status);
                $update_stmt->bindParam(':id', $room_id);
                
                if ($update_stmt->execute()) {
                    $success = "Room updated successfully!";
                    // Reload details
                    $room['block_id'] = $block_id;
                    $room['room_number'] = $room_number;
                    $room['floor_number'] = $floor_number;
                    $room['room_type'] = $room_type;
                    $room['capacity'] = $capacity;
                    $room['status'] = $status;
                } else {
                    $error = "Failed to update room.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Fetch active blocks for dropdown
$blocks_query = "SELECT id, name FROM hostel_blocks WHERE status = 'active' ORDER BY name";
$blocks_stmt = $db->query($blocks_query);
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Edit Room - Room " . htmlspecialchars($room['room_number']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Rooms', 'url' => 'index.php'],
    ['title' => 'Edit Room']
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
        <div class="max-w-4xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Hostel Room</h1>
                <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center transition">
                    <i class="fas fa-arrow-left mr-2"></i>Back
                </a>
            </div>

            <?php if ($success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Form Card -->
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                <div class="p-6">
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="block_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Hostel Block *</label>
                                <select id="block_id" name="block_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Block</option>
                                    <?php foreach ($blocks as $block): ?>
                                        <option value="<?php echo $block['id']; ?>" <?php echo $room['block_id'] == $block['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($block['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="room_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Number *</label>
                                <input type="text" id="room_number" name="room_number" required value="<?php echo htmlspecialchars($room['room_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <div>
                                <label for="floor_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Floor Number *</label>
                                <input type="number" id="floor_number" name="floor_number" required min="0" max="20" value="<?php echo htmlspecialchars($room['floor_number']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <div>
                                <label for="room_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room Type *</label>
                                <select id="room_type" name="room_type" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="single" <?php echo $room['room_type'] === 'single' ? 'selected' : ''; ?>>Single</option>
                                    <option value="double" <?php echo $room['room_type'] === 'double' ? 'selected' : ''; ?>>Double</option>
                                    <option value="triple" <?php echo $room['room_type'] === 'triple' ? 'selected' : ''; ?>>Triple</option>
                                    <option value="dormitory" <?php echo $room['room_type'] === 'dormitory' ? 'selected' : ''; ?>>Dormitory</option>
                                </select>
                            </div>

                            <div>
                                <label for="capacity" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Capacity (Beds) *</label>
                                <input type="number" id="capacity" name="capacity" required min="1" max="100" value="<?php echo htmlspecialchars($room['capacity']); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                                <select id="status" name="status"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="available" <?php echo $room['status'] === 'available' ? 'selected' : ''; ?>>Available</option>
                                    <option value="occupied" <?php echo $room['status'] === 'occupied' ? 'selected' : ''; ?>>Occupied</option>
                                    <option value="maintenance" <?php echo $room['status'] === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
                                    <option value="reserved" <?php echo $room['status'] === 'reserved' ? 'selected' : ''; ?>>Reserved</option>
                                </select>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg transition">
                                Cancel
                            </a>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-save mr-2"></i>Save Changes
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
