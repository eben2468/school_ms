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

$search_query = isset($_GET['search_student']) ? trim($_GET['search_student']) : '';
$class_filter = isset($_GET['class_id']) ? intval($_GET['class_id']) : null;
$gender_filter = isset($_GET['gender']) ? trim($_GET['gender']) : '';
$selected_student_id = isset($_GET['student_id']) ? intval($_GET['student_id']) : null;
$preselected_room_id = isset($_GET['room_id']) ? intval($_GET['room_id']) : null;

// Handle allocation post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['allocate'])) {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    $room_id = filter_input(INPUT_POST, 'room_id', FILTER_SANITIZE_NUMBER_INT);
    $allocation_date = filter_input(INPUT_POST, 'allocation_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
    
    if ($student_id && $room_id && $allocation_date) {
        try {
            $db->beginTransaction();
            
            // 1. Check if student already has an active allocation
            $active_check = $db->prepare("SELECT COUNT(*) FROM hostel_allocations WHERE student_id = :student_id AND status = 'active'");
            $active_check->bindParam(':student_id', $student_id);
            $active_check->execute();
            if ($active_check->fetchColumn() > 0) {
                throw new Exception("This student is already allocated to an active room.");
            }
            
            // 2. Fetch room capacity and current occupancy
            $room_check = $db->prepare("
                SELECT hr.*, 
                (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.room_id = hr.id AND ha.status = 'active') as current_occupants
                FROM hostel_rooms hr WHERE hr.id = :room_id FOR UPDATE
            ");
            $room_check->bindParam(':room_id', $room_id);
            $room_check->execute();
            $room_info = $room_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$room_info) {
                throw new Exception("Room not found.");
            }
            
            if ($room_info['status'] === 'maintenance') {
                throw new Exception("This room is currently undergoing maintenance.");
            }
            
            if ($room_info['current_occupants'] >= $room_info['capacity']) {
                throw new Exception("This room is already at full capacity.");
            }
            
            // 3. Insert allocation record
            $insert_query = "INSERT INTO hostel_allocations (student_id, room_id, allocation_date, status, created_at)
                             VALUES (:student_id, :room_id, :allocation_date, 'active', NOW())";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':student_id', $student_id);
            $insert_stmt->bindParam(':room_id', $room_id);
            $insert_stmt->bindParam(':allocation_date', $allocation_date);
            $insert_stmt->execute();
            
            // 4. Update room status to occupied if it is now full
            $new_occupancy = $room_info['current_occupants'] + 1;
            if ($new_occupancy >= $room_info['capacity']) {
                $update_room = $db->prepare("UPDATE hostel_rooms SET status = 'occupied' WHERE id = :room_id");
                $update_room->bindParam(':room_id', $room_id);
                $update_room->execute();
            }
            
            $db->commit();
            $success = "Student allocated successfully!";
            $selected_student_id = null; // Reset selection
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Student search results
$search_results = [];
if ($search_query || $class_filter || $gender_filter) {
    $where_clauses = ["u.role = 'student'"];
    $bind_params = [];
    
    if ($search_query) {
        $where_clauses[] = "(u.name LIKE :search OR sp.student_id LIKE :search)";
        $bind_params[':search'] = "%$search_query%";
    }
    if ($class_filter) {
        $where_clauses[] = "sc.class_id = :class_id";
        $bind_params[':class_id'] = $class_filter;
    }
    if ($gender_filter) {
        $where_clauses[] = "sp.gender = :gender";
        $bind_params[':gender'] = $gender_filter;
    }
    
    $where_str = implode(" AND ", $where_clauses);
    $search_stmt = $db->prepare("
        SELECT u.id, u.name, u.email, sp.student_id as student_number, c.name as class_name, sp.gender
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE $where_str
        LIMIT 25
    ");
    
    foreach ($bind_params as $param_name => $param_val) {
        $search_stmt->bindValue($param_name, $param_val);
    }
    $search_stmt->execute();
    $search_results = $search_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch all classes for the search filter
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$filter_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// If student selected, load their details
$selected_student = null;
if ($selected_student_id) {
    $sel_stmt = $db->prepare("
        SELECT u.id, u.name, u.email, sp.student_id as student_number
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        WHERE u.id = :student_id AND u.role = 'student'
    ");
    $sel_stmt->bindParam(':student_id', $selected_student_id);
    $sel_stmt->execute();
    $selected_student = $sel_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all active blocks
$blocks_stmt = $db->query("SELECT id, name, block_type FROM hostel_blocks WHERE status = 'active' ORDER BY name");
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available rooms and their current occupancies
$rooms_stmt = $db->query("
    SELECT hr.id, hr.room_number, hr.block_id, hr.capacity, hr.floor_number, hr.room_type,
           (SELECT COUNT(*) FROM hostel_allocations ha WHERE ha.room_id = hr.id AND ha.status = 'active') as occupants
    FROM hostel_rooms hr
    JOIN hostel_blocks hb ON hr.block_id = hb.id
    WHERE hr.status != 'maintenance' AND hb.status = 'active'
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

$title = "Create Room Allocation";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Allocations', 'url' => 'index.php'],
    ['title' => 'Create Allocation']
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
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Room Allocation</h1>
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

            <div class="grid grid-cols-1 gap-8">
                <!-- Step 1: Search Student -->
                <?php if (!$selected_student): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-150 dark:border-gray-700 overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-4 border-b border-gray-150 dark:border-gray-750">
                        <h3 class="text-lg font-semibold text-white flex items-center">
                            <i class="fas fa-search mr-2.5 text-blue-100"></i>
                            Step 1: Search & Filter Students
                        </h3>
                    </div>
                    
                    <div class="p-6">
                        <form method="GET" class="space-y-4 mb-6">
                            <input type="hidden" name="room_id" value="<?php echo $preselected_room_id; ?>">
                            
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Search Query</label>
                                    <div class="relative">
                                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                        <input type="text" name="search_student" value="<?php echo htmlspecialchars($search_query); ?>"
                                               placeholder="Name or ID number..."
                                               class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm transition">
                                    </div>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Class Filter</label>
                                    <select name="class_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm transition">
                                        <option value="">All Classes</option>
                                        <?php foreach ($filter_classes as $cls): ?>
                                            <option value="<?php echo $cls['id']; ?>" <?php echo $class_filter == $cls['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cls['grade_level'] . ' - ' . $cls['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Gender Filter</label>
                                    <select name="gender" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm transition">
                                        <option value="">All Genders</option>
                                        <option value="male" <?php echo $gender_filter === 'male' ? 'selected' : ''; ?>>Male</option>
                                        <option value="female" <?php echo $gender_filter === 'female' ? 'selected' : ''; ?>>Female</option>
                                        <option value="other" <?php echo $gender_filter === 'other' ? 'selected' : ''; ?>>Other</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="flex justify-end pt-2">
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg font-medium text-sm transition shadow-sm hover:shadow flex items-center">
                                    <i class="fas fa-filter mr-2"></i>Filter Students
                                </button>
                            </div>
                        </form>

                        <?php if (!empty($search_results)): ?>
                        <div class="border-t border-gray-100 dark:border-gray-700 pt-6">
                            <h4 class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-4">Results (<?php echo count($search_results); ?> students found)</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <?php foreach ($search_results as $student): ?>
                                <div class="bg-gray-50 dark:bg-gray-750 hover:bg-gray-100/50 dark:hover:bg-gray-700/50 rounded-xl border border-gray-100 dark:border-gray-700 p-4 shadow-sm transition flex items-center justify-between group">
                                    <div class="flex items-center space-x-3 min-w-0">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 font-bold shrink-0">
                                            <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                        </div>
                                        <div class="min-w-0">
                                            <h4 class="font-semibold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($student['name']); ?></h4>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">ID: <?php echo htmlspecialchars($student['student_number'] ?? 'N/A'); ?></p>
                                            <div class="flex items-center space-x-2 mt-1">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                                    <?php echo htmlspecialchars($student['class_name'] ?? 'Unassigned'); ?>
                                                </span>
                                                <?php if (!empty($student['gender'])): ?>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium <?php 
                                                    echo $student['gender'] === 'male' ? 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/50 dark:text-indigo-300' : ($student['gender'] === 'female' ? 'bg-pink-100 text-pink-800 dark:bg-pink-900/50 dark:text-pink-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'); ?>">
                                                    <?php echo ucfirst($student['gender']); ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <a href="create.php?student_id=<?php echo $student['id']; ?>&room_id=<?php echo $preselected_room_id; ?>" 
                                       class="bg-green-600 hover:bg-green-700 text-white text-xs px-3.5 py-2 rounded-lg font-medium transition shadow-sm hover:shadow shrink-0 ml-4">
                                        Select Student
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php elseif ($search_query || $class_filter || $gender_filter): ?>
                        <div class="text-center py-12 border border-dashed border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50/50">
                            <i class="fas fa-user-slash text-gray-400 text-5xl mb-3"></i>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">No students found matching the selected filters.</p>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-12 border border-dashed border-gray-200 dark:border-gray-700 rounded-xl bg-gray-50/50">
                            <i class="fas fa-users-viewfinder text-gray-400 text-5xl mb-3"></i>
                            <p class="text-gray-500 dark:text-gray-400 text-sm">Use the filters above to search for students to allocate.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Step 2: Allocation Form -->
                <?php if ($selected_student): ?>
                <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4"><i class="fas fa-bed mr-2 text-green-500"></i>Step 2: Complete Allocation details</h3>
                    
                    <!-- Selected Student Alert -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 flex justify-between items-center mb-6">
                        <div>
                            <span class="text-xs text-blue-500 font-bold block uppercase tracking-wider">Selected Student</span>
                            <span class="font-bold text-gray-800 text-lg"><?php echo htmlspecialchars($selected_student['name']); ?></span>
                            <span class="text-sm text-gray-500 ml-2">(ID: <?php echo htmlspecialchars($selected_student['student_number'] ?? 'N/A'); ?>)</span>
                        </div>
                        <a href="create.php?room_id=<?php echo $preselected_room_id; ?>" class="text-red-500 hover:text-red-700 text-sm font-medium">
                            Change Student
                        </a>
                    </div>

                    <form method="POST" class="space-y-6">
                        <input type="hidden" name="student_id" value="<?php echo $selected_student['id']; ?>">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="block_select" class="block text-sm font-medium text-gray-700">Hostel Block *</label>
                                <select id="block_select" required onchange="filterRoomsByBlock(this.value)"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Block</option>
                                    <?php foreach ($blocks as $block): ?>
                                        <option value="<?php echo $block['id']; ?>" <?php echo ($preselected_room && $preselected_room['block_id'] == $block['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($block['name']); ?> (<?php echo ucfirst($block['block_type']); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label for="room_id" class="block text-sm font-medium text-gray-700">Select Room *</label>
                                <select id="room_id" name="room_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                    <option value="">Select Room</option>
                                    <?php if ($preselected_room): ?>
                                        <option value="<?php echo $preselected_room['id']; ?>" selected>
                                            Room <?php echo htmlspecialchars($preselected_room['room_number']); ?> (<?php echo $preselected_room['occupants']; ?>/<?php echo $preselected_room['capacity']; ?> beds occupied)
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div>
                                <label for="allocation_date" class="block text-sm font-medium text-gray-700">Allocation Date *</label>
                                <input type="date" id="allocation_date" name="allocation_date" required value="<?php echo date('Y-m-d'); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500">
                            </div>
                        </div>

                        <div class="flex justify-end space-x-3 border-t pt-6">
                            <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg transition">
                                Cancel
                            </a>
                            <button type="submit" name="allocate" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition">
                                <i class="fas fa-save mr-2"></i>Allocate Room
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

<script>
// Available rooms JSON array from database
const roomsData = <?php echo json_encode($rooms); ?>;

function filterRoomsByBlock(blockId) {
    const roomSelect = document.getElementById('room_id');
    roomSelect.innerHTML = '<option value="">Select Room</option>';
    
    if (!blockId) return;
    
    // Filter rooms by block and check capacity
    const filteredRooms = roomsData.filter(room => room.block_id == blockId);
    
    filteredRooms.forEach(room => {
        const option = document.createElement('option');
        option.value = room.id;
        
        const availableBeds = room.capacity - room.occupants;
        const text = `Room ${room.room_number} (Floor ${room.floor_number}, Type: ${room.room_type}) - ${room.occupants}/${room.capacity} occupied (${availableBeds} beds free)`;
        option.text = text;
        
        // Disable option if it has no free beds
        if (availableBeds <= 0) {
            option.disabled = true;
            option.text += ' [FULL]';
        }
        
        roomSelect.appendChild(option);
    });
}

// If preselected block exists, trigger filter
<?php if ($preselected_room): ?>
document.addEventListener("DOMContentLoaded", () => {
    filterRoomsByBlock(<?php echo $preselected_room['block_id']; ?>);
    document.getElementById('room_id').value = <?php echo $preselected_room['id']; ?>;
});
<?php endif; ?>
</script>
        </main>
        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>