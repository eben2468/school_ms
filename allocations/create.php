<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('allocations');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $room_id = filter_input(INPUT_POST, 'room_id', FILTER_SANITIZE_NUMBER_INT);
    $allocation_date = filter_input(INPUT_POST, 'allocation_date', FILTER_SANITIZE_STRING);
    $academic_year = filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if ($student_id && $room_id && $allocation_date) {
        try {
            $db->beginTransaction();
            
            // Check if student already has an active allocation
            $check_query = "SELECT id FROM hostel_allocations WHERE student_id = :student_id AND status = 'active'";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':student_id', $student_id);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                throw new Exception("Student already has an active hostel allocation.");
            }
            
            // Check room capacity
            $room_query = "SELECT hr.capacity, COUNT(ha.id) as current_occupancy 
                          FROM hostel_rooms hr 
                          LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
                          WHERE hr.id = :room_id 
                          GROUP BY hr.id, hr.capacity";
            $room_stmt = $db->prepare($room_query);
            $room_stmt->bindParam(':room_id', $room_id);
            $room_stmt->execute();
            $room_info = $room_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$room_info) {
                throw new Exception("Room not found.");
            }
            
            if ($room_info['current_occupancy'] >= $room_info['capacity']) {
                throw new Exception("Room is at full capacity.");
            }
            
            // Create allocation
            $query = "INSERT INTO hostel_allocations (student_id, room_id, allocation_date, academic_year, status, notes, created_by, created_at) 
                     VALUES (:student_id, :room_id, :allocation_date, :academic_year, 'active', :notes, :created_by, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':room_id', $room_id);
            $stmt->bindParam(':allocation_date', $allocation_date);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();
            
            $db->commit();
            $success = "Hostel allocation created successfully!";
        } catch (Exception $e) {
            $db->rollBack();
            $error = "Error creating allocation: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get students without active allocations
$students_query = "SELECT u.id, u.name, sp.student_id 
                   FROM users u 
                   LEFT JOIN student_profiles sp ON u.id = sp.user_id 
                   LEFT JOIN hostel_allocations ha ON u.id = ha.student_id AND ha.status = 'active'
                   WHERE u.role = 'student' AND u.status = 'active' AND ha.id IS NULL
                   ORDER BY u.name";
$students_stmt = $db->query($students_query);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available rooms
$rooms_query = "SELECT hr.id, hr.room_number, hr.floor_number, hr.capacity, hb.name as block_name,
                       COUNT(ha.id) as current_occupancy,
                       (hr.capacity - COUNT(ha.id)) as available_spaces
                FROM hostel_rooms hr
                JOIN hostel_blocks hb ON hr.block_id = hb.id
                LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
                WHERE hr.status = 'available'
                GROUP BY hr.id, hr.room_number, hr.floor_number, hr.capacity, hb.name
                HAVING available_spaces > 0
                ORDER BY hb.name, hr.floor_number, hr.room_number";
$rooms_stmt = $db->query($rooms_query);
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Hostel Allocation";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Allocations', 'url' => 'index.php'],
    ['title' => 'Create Allocation']
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 64px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Hostel Allocation</h1>
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
                                    <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Student *</label>
                                    <select id="student_id" name="student_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Student</option>
                                        <?php foreach ($students as $student): ?>
                                            <option value="<?php echo $student['id']; ?>">
                                                <?php echo htmlspecialchars($student['name']); ?>
                                                <?php if ($student['student_id']): ?>
                                                    (<?php echo htmlspecialchars($student['student_id']); ?>)
                                                <?php endif; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="room_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Room *</label>
                                    <select id="room_id" name="room_id" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Room</option>
                                        <?php foreach ($rooms as $room): ?>
                                            <option value="<?php echo $room['id']; ?>">
                                                <?php echo htmlspecialchars($room['block_name']); ?> - Room <?php echo htmlspecialchars($room['room_number']); ?>
                                                (Floor <?php echo $room['floor_number']; ?>, <?php echo $room['available_spaces']; ?>/<?php echo $room['capacity']; ?> available)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="allocation_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Allocation Date *</label>
                                    <input type="date" id="allocation_date" name="allocation_date" value="<?php echo date('Y-m-d'); ?>" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year</label>
                                    <input type="text" id="academic_year" name="academic_year" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="md:col-span-2">
                                    <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Notes</label>
                                    <textarea id="notes" name="notes" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Any special notes or requirements"></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Allocation
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Show room details when selected
document.getElementById('room_id').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    if (selectedOption.value) {
        console.log('Selected room:', selectedOption.text);
    }
});
</script>
