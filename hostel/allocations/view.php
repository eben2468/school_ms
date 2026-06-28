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

// Handle checkout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    $checkout_date = filter_input(INPUT_POST, 'checkout_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
    
    try {
        $db->beginTransaction();
        
        // Fetch allocation
        $alloc_stmt = $db->prepare("SELECT * FROM hostel_allocations WHERE id = :id FOR UPDATE");
        $alloc_stmt->bindParam(':id', $allocation_id);
        $alloc_stmt->execute();
        $allocation_data = $alloc_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($allocation_data && $allocation_data['status'] === 'active') {
            // Update allocation
            $update_stmt = $db->prepare("UPDATE hostel_allocations SET status = 'checked_out', checkout_date = :checkout_date WHERE id = :id");
            $update_stmt->bindParam(':checkout_date', $checkout_date);
            $update_stmt->bindParam(':id', $allocation_id);
            $update_stmt->execute();
            
            // Check room capacity and reset room status to available if it was occupied
            $room_id = $allocation_data['room_id'];
            $room_update = $db->prepare("UPDATE hostel_rooms SET status = 'available' WHERE id = :room_id AND status = 'occupied'");
            $room_update->bindParam(':room_id', $room_id);
            $room_update->execute();
            
            $db->commit();
            $success = "Student checked out successfully!";
        } else {
            throw new Exception("Allocation is not active.");
        }
    } catch (Exception $e) {
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Fetch allocation detail
$query = "SELECT ha.*, s.name as student_name, s.email as student_email, sp.phone as student_phone, sp.student_id as student_number,
                 hr.room_number, hr.floor_number, hr.room_type, hr.capacity, hb.name as block_name, hb.block_type, hb.id as block_id
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

$title = "Allocation Detail - " . htmlspecialchars($allocation['student_name']);
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Allocations', 'url' => 'index.php'],
    ['title' => 'Allocation Detail']
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
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Allocation Details</h1>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                    <?php if ($allocation['status'] === 'active'): ?>
                    <a href="edit.php?id=<?php echo $allocation['id']; ?>" class="inline-flex items-center whitespace-nowrap bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg transition">
                        <i class="fas fa-exchange-alt mr-2"></i>Transfer / Edit
                    </a>
                    <?php endif; ?>
                </div>
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

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left 2 cols: Main info -->
                <div class="md:col-span-2 space-y-6">
                    <!-- Student Info Card -->
                    <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4"><i class="fas fa-user-graduate mr-2 text-blue-500"></i>Student Information</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500 block">Full Name:</span>
                                <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($allocation['student_name']); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Student ID / Registration #:</span>
                                <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($allocation['student_number'] ?? 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Email Address:</span>
                                <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($allocation['student_email'] ?? 'N/A'); ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Phone Number:</span>
                                <span class="font-semibold text-gray-800"><?php echo htmlspecialchars($allocation['student_phone'] ?? 'N/A'); ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Accommodation details -->
                    <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4"><i class="fas fa-building mr-2 text-green-500"></i>Accommodation details</h3>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                            <div>
                                <span class="text-gray-500 block">Hostel Block:</span>
                                <span class="font-semibold text-gray-800">
                                    <a href="../blocks/view.php?id=<?php echo $allocation['block_id']; ?>" class="text-blue-600 hover:underline">
                                        <?php echo htmlspecialchars($allocation['block_name']); ?>
                                    </a>
                                    (<?php echo ucfirst($allocation['block_type']); ?> Block)
                                </span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Room & Floor:</span>
                                <span class="font-semibold text-gray-800">Room <?php echo htmlspecialchars($allocation['room_number']); ?> (Floor <?php echo htmlspecialchars($allocation['floor_number']); ?>)</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Room Type:</span>
                                <span class="font-semibold text-gray-800"><?php echo ucfirst($allocation['room_type']); ?> (Capacity: <?php echo htmlspecialchars($allocation['capacity']); ?> Beds)</span>
                            </div>
                            <div>
                                <span class="text-gray-500 block">Allocation Status:</span>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                    <?php echo $allocation['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                    <?php echo ucfirst(str_replace('_', ' ', $allocation['status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right 1 col: Dates & checkout -->
                <div class="md:col-span-1 space-y-6">
                    <div class="bg-white rounded-lg shadow border border-gray-200 p-6">
                        <h3 class="text-lg font-semibold text-gray-800 border-b pb-2 mb-4"><i class="fas fa-calendar-alt mr-2 text-purple-500"></i>Important Dates</h3>
                        <div class="space-y-3 text-sm mb-6">
                            <div>
                                <span class="text-gray-500 block">Check-in Date:</span>
                                <span class="font-medium text-gray-800"><?php echo date('M j, Y', strtotime($allocation['allocation_date'])); ?></span>
                            </div>
                            <?php if ($allocation['checkout_date']): ?>
                            <div>
                                <span class="text-gray-500 block">Check-out Date:</span>
                                <span class="font-medium text-gray-800"><?php echo date('M j, Y', strtotime($allocation['checkout_date'])); ?></span>
                            </div>
                            <?php endif; ?>
                            <div>
                                <span class="text-gray-500 block">Date Created:</span>
                                <span class="font-medium text-gray-800"><?php echo date('M j, Y', strtotime($allocation['created_at'])); ?></span>
                            </div>
                        </div>

                        <?php if ($allocation['status'] === 'active'): ?>
                        <div class="border-t pt-4">
                            <h4 class="text-sm font-semibold text-red-600 mb-3">Check out Student</h4>
                            <form method="POST" onsubmit="return confirm('Are you sure you want to check out this student from their room?');">
                                <div class="mb-3">
                                    <label for="checkout_date" class="block text-xs text-gray-500 mb-1">Check-out Date</label>
                                    <input type="date" id="checkout_date" name="checkout_date" required value="<?php echo date('Y-m-d'); ?>"
                                           class="w-full px-3 py-1.5 border border-gray-300 rounded text-sm focus:outline-none focus:ring-1 focus:ring-red-500">
                                </div>
                                <button type="submit" name="checkout" class="w-full bg-red-500 hover:bg-red-600 text-white font-medium py-2 rounded text-sm transition">
                                    Confirm Check-out
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
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
