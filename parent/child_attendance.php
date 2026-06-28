<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? 0;

// Verify this student belongs to this parent
$verify_sql = "SELECT COUNT(*) FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
$stmt = $db->prepare($verify_sql);
$stmt->bindParam(':parent_id', $user_id);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();

if ($stmt->fetchColumn() == 0) {
    header('Location: dashboard.php');
    exit();
}

// Get student information
$student_sql = "SELECT u.name, sp.student_id, c.name as class_name, c.grade_level
FROM users u
JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
WHERE u.id = :student_id";

$stmt = $db->prepare($student_sql);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Heal older tenant DBs missing optional attendance time columns.
require_once '../includes/schema_helpers.php';
ensureAttendanceColumns($db);

// Get attendance records for current month
$current_month = date('Y-m');
$attendance_sql = "SELECT 
    a.date,
    a.status,
    a.remarks,
    a.time_in,
    a.time_out
FROM attendance a
WHERE a.student_id = :student_id 
AND DATE_FORMAT(a.date, '%Y-%m') = :current_month
ORDER BY a.date DESC";

$attendance_records = [];
try {
    $stmt = $db->prepare($attendance_sql);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->bindParam(':current_month', $current_month);
    $stmt->execute();
    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("child_attendance query failed: " . $e->getMessage());
}

// Calculate attendance statistics
$total_days = count($attendance_records);
$present_days = count(array_filter($attendance_records, function($record) {
    return $record['status'] === 'present';
}));
$absent_days = count(array_filter($attendance_records, function($record) {
    return $record['status'] === 'absent';
}));
$late_days = count(array_filter($attendance_records, function($record) {
    return $record['status'] === 'late';
}));

$attendance_percentage = $total_days > 0 ? round(($present_days / $total_days) * 100, 1) : 0;

$title = "Attendance - " . htmlspecialchars($student['name']);
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 80px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Attendance Record</h1>
                                <p class="text-blue-100 text-lg"><?php echo htmlspecialchars($student['name']); ?></p>
                                <div class="mt-2 flex items-center space-x-4 text-sm text-blue-100">
                                    <span>Student ID: <?php echo htmlspecialchars($student['student_id']); ?></span>
                                    <?php if ($student['class_name']): ?>
                                    <span>Grade <?php echo htmlspecialchars($student['grade_level']); ?> - <?php echo htmlspecialchars($student['class_name']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-calendar-check text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Parent Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Attendance</span>
                </div>

                <!-- Attendance Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-percentage text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Attendance Rate</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $attendance_percentage; ?>%</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Present Days</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $present_days; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Absent Days</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $absent_days; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Late Days</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $late_days; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Attendance Records -->
                <?php if (!empty($attendance_records)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Daily Attendance - <?php echo date('F Y'); ?></h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Daily attendance records for the current month</p>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time In</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time Out</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($attendance_records as $record): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo date('M j, Y', strtotime($record['date'])); ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo date('l', strtotime($record['date'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php
                                            $status_classes = [
                                                'present' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                                'absent' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
                                                'late' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                                'excused' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'
                                            ];
                                            $class = $status_classes[$record['status']] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200';
                                            ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $class; ?>">
                                                <?php echo ucfirst($record['status']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['remarks'] ? htmlspecialchars($record['remarks']) : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Attendance Records</h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            No attendance records found for <?php echo date('F Y'); ?>.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
