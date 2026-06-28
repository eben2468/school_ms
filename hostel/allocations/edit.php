<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$allocation_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$allocation_id) {
    header("Location: index.php");
    exit();
}

$success = '';
$error = '';

// Fetch current allocation
$query = "SELECT ha.*, s.name as student_name, sp.student_id as student_number,
                 hr.room_number, hr.block_id, hb.name as block_name
          FROM hostel_allocations ha
          JOIN users s ON ha.student_id = s.id
          LEFT JOIN student_profiles sp ON s.id = sp.user_id
          JOIN hostel_rooms hr ON ha.room_id = hr.id
          JOIN hostel_blocks hb ON hr.block_id = hb.id
          WHERE ha.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $allocation_id);
$stmt->execute();
$allocation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$allocation) {
    header("Location: index.php");
    exit();
}

if ($allocation['status'] !== 'active') {
    header("Location: view.php?id=" . $allocation_id);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_room_id = filter_input(INPUT_POST, 'room_id', FILTER_SANITIZE_NUMBER_INT);
    $allocation_date = filter_input(INPUT_POST, 'allocation_date', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    if ($new_room_id && $allocation_date && $status) {
        try {
            $db->beginTransaction();
            
            $room_changed = ($new_room_id != $allocation['room_id']);
            
            if ($room_changed) {
                // 1. Fetch details of the new room
                $new_room_stmt = $db->prepare("
                    SELECT hr.*, 
                    (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.room_id = hr.id AND ha.status = 'active') as occupants
                    FROM hostel_rooms hr WHERE hr.id = :room_id FOR UPDATE
                ");
                $new_room_stmt->bindParam(':room_id', $new_room_id);
                $new_room_stmt->execute();
                $new_room = $new_room_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$new_room) {
                    throw new Exception("Target room not found.");
                }
                
                if ($new_room['status'] === 'maintenance') {
                    throw new Exception("Target room is in maintenance.");
                }
                
                if ($new_room['occupants'] >= $new_room['capacity']) {
                    throw new Exception("Target room is already full.");
                }
                
                // 2. Update allocation room
                $update_stmt = $db->prepare("UPDATE hostel_allocations SET room_id = :room_id, allocation_date = :allocation_date, status = :status WHERE id = :id");
                $update_stmt->bindParam(':room_id', $new_room_id);
                $update_stmt->bindParam(':allocation_date', $allocation_date);
                $update_stmt->bindParam(':status', $status);
                $update_stmt->bindParam(':id', $allocation_id);
                $update_stmt->execute();
                
                // 3. Update old room status if it was occupied (now has space)
                $old_room_update = $db->prepare("UPDATE hostel_rooms SET status = 'available' WHERE id = :room_id AND status = 'occupied'");
                $old_room_update->bindParam(':room_id', $allocation['room_id']);
                $old_room_update->execute();
                
                // 4. Update new room status if it is now full
                $new_occupancy = $new_room['occupants'] + 1;
                if ($new_occupancy >= $new_room['capacity']) {
                    $new_room_update = $db->prepare("UPDATE hostel_rooms SET status = 'occupied' WHERE id = :room_id");
                    $new_room_update->bindParam(':room_id', $new_room_id);
                    $new_room_update->execute();
                }
            } else {
                // Just update dates and status
                $update_stmt = $db->prepare("UPDATE hostel_allocations SET allocation_date = :allocation_date, status = :status WHERE id = :id");
                $update_stmt->bindParam(':allocation_date', $allocation_date);
                $update_stmt->bindParam(':status', $status);
                $update_stmt->bindParam(':id', $allocation_id);
                $update_stmt->execute();
            }
            
            // If checked out or transferred, free up room
            if ($status !== 'active') {
                $checkout_date = date('Y-m-d');
                $checkout_stmt = $db->prepare("UPDATE hostel_allocations SET checkout_date = :checkout_date WHERE id = :id");
                $checkout_stmt->bindParam(':checkout_date', $checkout_date);
                $checkout_stmt->bindParam(':id', $allocation_id);
                $checkout_stmt->execute();
                
                $final_room_id = $room_changed ? $new_room_id : $allocation['room_id'];
                $room_free = $db->prepare("UPDATE hostel_rooms SET status = 'available' WHERE id = :room_id AND status = 'occupied'");
                $room_free->bindParam(':room_id', $final_room_id);
                $room_free->execute();
            }
            
            $db->commit();
            $success = "Allocation details updated successfully!";
            
            // Reload details
            $stmt = $db->prepare("
                SELECT ha.*, s.name as student_name, sp.student_id as student_number,
                       hr.room_number, hr.block_id, hb.name as block_name
                FROM hostel_allocations ha
                JOIN users s ON ha.student_id = s.id
                LEFT JOIN student_profiles sp ON s.id = sp.user_id
                JOIN hostel_rooms hr ON ha.room_id = hr.id
                JOIN hostel_blocks hb ON hr.block_id = hb.id
                WHERE ha.id = :id
            ");
            $stmt->bindParam(':id', $allocation_id);
            $stmt->execute();
            $allocation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($allocation['status'] !== 'active') {
                header("Location: view.php?id=" . $allocation_id);
                exit();
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get all active blocks
$blocks_stmt = $db->query("SELECT id, name, block_type FROM hostel_blocks WHERE status = 'active' ORDER BY name");
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available rooms for transfer
$rooms_stmt = $db->query("
    SELECT hr.id, hr.room_number, hr.block_id, hr.capacity, hr.floor_number, hr.room_type,
           (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.room_id = hr.id AND ha.status = 'active') as occupants
    FROM hostel_rooms hr
    JOIN hostel_blocks hb ON hr.block_id = hb.id
    WHERE hr.status != 'maintenance' AND hb.status = 'active'
    ORDER BY hr.room_number
");
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Edit Allocation - " . htmlspecialchars($allocation['student_name']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Allocations', 'url' => 'index.php'],
    ['title' => 'Edit Allocation']
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
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit / Transfer Allocation</h1>
                <a href="view.php?id=<?php echo $allocation['id']; ?>" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center transition">
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
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6">
                <!-- Current Allocation Details -->
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6 text-sm grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <span class="text-xs text-blue-500 font-bold block uppercase tracking-wider">Student Name</span>
                        <span class="font-bold text-gray-800 text-lg"><?php echo htmlspecialchars($allocation['student_name']); ?></span>
                        <span class="text-xs text-gray-500 block">ID: <?php echo htmlspecialchars($allocation['student_number'] ?? 'N/A'); ?></span>
                    </div>
                    <div>
                        <span class="text-xs text-blue-500 font-bold block uppercase tracking-wider">Current Room</span>
                        <span class="font-bold text-gray-800 text-lg"><?php echo htmlspecialchars($allocation['block_name']); ?> - Room <?php echo htmlspecialchars($allocation['room_number']); ?></span>
                    </div>
                </div>

                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="block_select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Hostel Block *</label>
                            <select id="block_select" required onchange="filterRoomsByBlock(this.value)"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Block</option>
                                <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['id']; ?>" <?php echo $allocation['block_id'] == $block['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['name']); ?> (<?php echo ucfirst($block['block_type']); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="room_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Room (Transfer Target) *</label>
                            <select id="room_id" name="room_id" required
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="<?php echo $allocation['room_id']; ?>">
                                    Room <?php echo htmlspecialchars($allocation['room_number']); ?> (Current)
                                </option>
                            </select>
                        </div>

                        <div>
                            <label for="allocation_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Allocation Date *</label>
                            <input type="date" id="allocation_date" name="allocation_date" required value="<?php echo htmlspecialchars($allocation['allocation_date']); ?>"
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Status</label>
                            <select id="status" name="status" required
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="active" <?php echo $allocation['status'] === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="checked_out" <?php echo $allocation['status'] === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                <option value="transferred" <?php echo $allocation['status'] === 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex justify-end space-x-3 border-t pt-6">
                        <a href="view.php?id=<?php echo $allocation['id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg transition">
                            Cancel
                        </a>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                            <i class="fas fa-save mr-2"></i>Save Details
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
// Available rooms JSON array from database
const roomsData = <?php echo json_encode($rooms); ?>;
const currentRoomId = <?php echo $allocation['room_id']; ?>;
const currentRoomNumber = '<?php echo addslashes($allocation['room_number']); ?>';

function filterRoomsByBlock(blockId) {
    const roomSelect = document.getElementById('room_id');
    roomSelect.innerHTML = '';
    
    if (!blockId) return;
    
    // Filter rooms by block and check capacity
    const filteredRooms = roomsData.filter(room => room.block_id == blockId);
    
    filteredRooms.forEach(room => {
        const option = document.createElement('option');
        option.value = room.id;
        
        let occupantsCount = room.occupants;
        // If it is the current room, discount the current student from the occupancy count in options display
        if (room.id == currentRoomId) {
            option.text = `Room ${room.room_number} (Current Room)`;
            roomSelect.appendChild(option);
            return;
        }
        
        const availableBeds = room.capacity - occupantsCount;
        const text = `Room ${room.room_number} (Floor ${room.floor_number}, Type: ${room.room_type}) - ${occupantsCount}/${room.capacity} occupied (${availableBeds} beds free)`;
        option.text = text;
        
        if (availableBeds <= 0) {
            option.disabled = true;
            option.text += ' [FULL]';
        }
        
        roomSelect.appendChild(option);
    });
}

// Initial page load mapping
document.addEventListener("DOMContentLoaded", () => {
    filterRoomsByBlock(<?php echo $allocation['block_id']; ?>);
    document.getElementById('room_id').value = currentRoomId;
});
</script>

