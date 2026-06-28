<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success = '';
$error = '';
$preselected_room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $room_id = filter_input(INPUT_POST, 'room_id', FILTER_SANITIZE_NUMBER_INT);
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING) ?: 'medium';
    $reported_by = $_SESSION['user_id'];

    if ($room_id && $title && $description) {
        try {
            $query = "INSERT INTO hostel_maintenance (room_id, reported_by, title, description, priority, status, created_at)
                      VALUES (:room_id, :reported_by, :title, :description, :priority, 'pending', NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':room_id', $room_id);
            $stmt->bindParam(':reported_by', $reported_by);
            $stmt->bindParam(':title', $title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':priority', $priority);
            
            if ($stmt->execute()) {
                // If room status is 'available', set it to 'maintenance' to reserve it
                $update_room = $db->prepare("UPDATE hostel_rooms SET status = 'maintenance' WHERE id = :room_id AND status = 'available'");
                $update_room->bindParam(':room_id', $room_id);
                $update_room->execute();
                
                $success = "Maintenance issue reported successfully!";
            } else {
                $error = "Failed to report issue.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get all active blocks
$blocks_stmt = $db->query("SELECT id, name, block_type FROM hostel_blocks WHERE status = 'active' ORDER BY name");
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get all rooms in active blocks
$rooms_stmt = $db->query("
    SELECT hr.id, hr.room_number, hr.block_id, hr.floor_number, hr.room_type, hr.status
    FROM hostel_rooms hr
    JOIN hostel_blocks hb ON hr.block_id = hb.id
    WHERE hb.status = 'active'
    ORDER BY hr.room_number
");
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

$preselected_room = null;
if ($preselected_room_id) {
    foreach ($rooms as $r) {
        if ($r['id'] == $preselected_room_id) {
            $preselected_room = $r;
            break;
        }
    }
}

$page_title = "Report Repair Issue";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Maintenance', 'url' => 'index.php'],
    ['title' => 'Report Issue']
];

$title = $page_title;
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
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Report Maintenance Issue</h1>
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
                                <label for="block_select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Hostel Block *</label>
                                <select id="block_select" required onchange="filterRoomsByBlock(this.value)"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Block</option>
                                    <?php foreach ($blocks as $block): ?>
                                        <option value="<?php echo $block['id']; ?>" <?php echo ($preselected_room && $preselected_room['block_id'] == $block['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($block['name']); ?> (<?php echo ucfirst($block['block_type']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="room_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Room *</label>
                                <select id="room_id" name="room_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Room</option>
                                    <?php if ($preselected_room): ?>
                                        <option value="<?php echo $preselected_room['id']; ?>" selected>
                                            Room <?php echo htmlspecialchars($preselected_room['room_number']); ?> (Current Status: <?php echo ucfirst($preselected_room['status']); ?>)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div>
                                <label for="title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Issue Title *</label>
                                <input type="text" id="title" name="title" required placeholder="e.g. Leaking bathroom pipe, Faulty fan"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <div>
                                <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Priority Level</label>
                                <select id="priority" name="priority" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="low">Low</option>
                                    <option value="medium" selected>Medium</option>
                                    <option value="high">High</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Problem Description *</label>
                            <textarea id="description" name="description" rows="4" required
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                      placeholder="Provide detailed information about the issue..."></textarea>
                        </div>

                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg transition">
                                Cancel
                            </a>
                            <button type="submit" class="bg-orange-500 hover:bg-orange-600 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-paper-plane mr-2"></i>Submit Request
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

<script>
const roomsData = <?php echo json_encode($rooms); ?>;

function filterRoomsByBlock(blockId) {
    const roomSelect = document.getElementById('room_id');
    roomSelect.innerHTML = '<option value="">Select Room</option>';
    
    if (!blockId) return;
    
    const filteredRooms = roomsData.filter(room => room.block_id == blockId);
    
    filteredRooms.forEach(room => {
        const option = document.createElement('option');
        option.value = room.id;
        option.text = `Room ${room.room_number} (Floor ${room.floor_number}, Type: ${room.room_type}) [Status: ${room.status}]`;
        roomSelect.appendChild(option);
    });
}

<?php if ($preselected_room): ?>
document.addEventListener("DOMContentLoaded", () => {
    filterRoomsByBlock(<?php echo $preselected_room['block_id']; ?>);
    document.getElementById('room_id').value = <?php echo $preselected_room['id']; ?>;
});
<?php endif; ?>
</script>

