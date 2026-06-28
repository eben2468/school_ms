<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$parent_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? null;

// Verify parent has access to this student
if ($student_id) {
    $access_query = "SELECT COUNT(*) as count FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':parent_id', $parent_id);
    $access_stmt->bindParam(':student_id', $student_id);
    $access_stmt->execute();
    $access = $access_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($access['count'] == 0) {
        header("Location: index.php");
        exit();
    }
}

// Get parent's children if no specific student selected
if (!$student_id) {
    $children_query = "
        SELECT u.id, u.name 
        FROM users u
        JOIN parent_students ps ON u.id = ps.student_id
        WHERE ps.parent_id = :parent_id AND u.status = 'active'
        ORDER BY u.name
    ";
    $children_stmt = $db->prepare($children_query);
    $children_stmt->bindParam(':parent_id', $parent_id);
    $children_stmt->execute();
    $children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($children) == 1) {
        $student_id = $children[0]['id'];
    }
}

$student_info = null;
$attendance_records = [];
$attendance_summary = null;

if ($student_id) {
    // Get student information
    $student_query = "
        SELECT u.name, sp.student_id, c.name as class_name, c.grade_level
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE u.id = :student_id
    ";
    $student_stmt = $db->prepare($student_query);
    $student_stmt->bindParam(':student_id', $student_id);
    $student_stmt->execute();
    $student_info = $student_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get attendance records for the current month
    $month = $_GET['month'] ?? date('Y-m');
    $start_date = $month . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $attendance_query = "
        SELECT date, status, remarks, created_at
        FROM attendance 
        WHERE student_id = :student_id 
        AND date BETWEEN :start_date AND :end_date
        ORDER BY date DESC
    ";
    $attendance_stmt = $db->prepare($attendance_query);
    $attendance_stmt->bindParam(':student_id', $student_id);
    $attendance_stmt->bindParam(':start_date', $start_date);
    $attendance_stmt->bindParam(':end_date', $end_date);
    $attendance_stmt->execute();
    $attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate attendance summary
    $summary_query = "
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
        FROM attendance 
        WHERE student_id = :student_id 
        AND date BETWEEN :start_date AND :end_date
    ";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->bindParam(':student_id', $student_id);
    $summary_stmt->bindParam(':start_date', $start_date);
    $summary_stmt->bindParam(':end_date', $end_date);
    $summary_stmt->execute();
    $attendance_summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);
}

$title = "Student Attendance";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Student Attendance</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">View your child's attendance records</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <a href="?student_id=<?php echo $child['id']; ?>" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($child['name']); ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_info): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student_info['name']); ?></h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo htmlspecialchars($student_info['class_name'] ?? 'No Class Assigned'); ?>
                                    <?php if ($student_info['student_id']): ?>
                                    • ID: <?php echo htmlspecialchars($student_info['student_id']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Month Selector -->
                        <div>
                            <form method="GET" class="flex items-center space-x-2">
                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Month:</label>
                                <input type="month" name="month" value="<?php echo $month ?? date('Y-m'); ?>" 
                                       class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Attendance Summary -->
                <?php if ($attendance_summary): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Days</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $attendance_summary['total_days']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Present</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $attendance_summary['present_days']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Absent</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400"><?php echo $attendance_summary['absent_days']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Late</h3>
                                <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo $attendance_summary['late_days']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Attendance Records -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Attendance Records</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            <?php echo date('F Y', strtotime($month ?? date('Y-m'))); ?>
                        </p>
                    </div>

                    <?php if (empty($attendance_records)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Attendance Records</h3>
                        <p class="text-gray-500 dark:text-gray-400">No attendance records found for the selected month.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Recorded</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($attendance_records as $record): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo date('M j, Y', strtotime($record['date'])); ?>
                                        <span class="text-gray-500 dark:text-gray-400">(<?php echo date('D', strtotime($record['date'])); ?>)</span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($record['status']) {
                                                case 'present': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'absent': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                case 'late': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'; break;
                                            }
                                            ?>">
                                            <i class="fas fa-<?php
                                            switch($record['status']) {
                                                case 'present': echo 'check'; break;
                                                case 'absent': echo 'times'; break;
                                                case 'late': echo 'clock'; break;
                                                default: echo 'question'; break;
                                            }
                                            ?> mr-1"></i>
                                            <?php echo ucfirst($record['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($record['remarks'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, g:i A', strtotime($record['created_at'])); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
