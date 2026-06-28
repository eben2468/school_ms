<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Get filter parameters
$month_filter = $_GET['month'] ?? date('Y-m');
$year_filter = $_GET['year'] ?? date('Y');

try {
    // Get student information
    $student_query = "SELECT u.name, sp.student_id, c.name as class_name, c.grade_level
                     FROM users u
                     LEFT JOIN student_profiles sp ON u.id = sp.user_id
                     LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                     LEFT JOIN classes c ON sc.class_id = c.id
                     WHERE u.id = :user_id";
    $student_stmt = $db->prepare($student_query);
    $student_stmt->bindParam(':user_id', $user_id);
    $student_stmt->execute();
    $student_info = $student_stmt->fetch(PDO::FETCH_ASSOC);

    // Get attendance records for selected month
    $start_date = $month_filter . '-01';
    $end_date = date('Y-m-t', strtotime($start_date));
    
    $attendance_query = "SELECT 
        a.date,
        a.status,
        a.notes,
        a.time_in,
        a.time_out,
        a.created_at
    FROM attendance a
    WHERE a.student_id = :user_id 
    AND a.date BETWEEN :start_date AND :end_date
    ORDER BY a.date DESC";
    
    $attendance_stmt = $db->prepare($attendance_query);
    $attendance_stmt->bindParam(':user_id', $user_id);
    $attendance_stmt->bindParam(':start_date', $start_date);
    $attendance_stmt->bindParam(':end_date', $end_date);
    $attendance_stmt->execute();
    $attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
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

    // Get available months for filter
    $months_query = "SELECT DISTINCT DATE_FORMAT(date, '%Y-%m') as month 
                    FROM attendance 
                    WHERE student_id = :user_id 
                    ORDER BY month DESC";
    $months_stmt = $db->prepare($months_query);
    $months_stmt->bindParam(':user_id', $user_id);
    $months_stmt->execute();
    $available_months = $months_stmt->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Helper function to get status color
function getStatusColor($status) {
    switch ($status) {
        case 'present': return 'text-green-600 bg-green-100';
        case 'absent': return 'text-red-600 bg-red-100';
        case 'late': return 'text-yellow-600 bg-yellow-100';
        case 'excused': return 'text-blue-600 bg-blue-100';
        default: return 'text-gray-600 bg-gray-100';
    }
}

// Helper function to get status icon
function getStatusIcon($status) {
    switch ($status) {
        case 'present': return 'fa-check-circle';
        case 'absent': return 'fa-times-circle';
        case 'late': return 'fa-clock';
        case 'excused': return 'fa-info-circle';
        default: return 'fa-question-circle';
    }
}

$title = "My Attendance";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Title -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-4 mb-8 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-calendar-check mr-3"></i>
                                My Attendance
                            </h1>
                            <p class="text-blue-100">Track your attendance records and performance</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold"><?= $attendance_percentage ?>%</div>
                            <div class="text-sm text-blue-100">Attendance Rate</div>
                        </div>
                    </div>
                </div>

                    <!-- Student Info Card -->
                    <?php if ($student_info): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-2xl"></i>
                            </div>
                            <div class="ml-6">
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($student_info['name']) ?></h2>
                                <p class="text-gray-600 dark:text-gray-400">Student ID: <?= htmlspecialchars($student_info['student_id'] ?? 'N/A') ?></p>
                                <p class="text-gray-600 dark:text-gray-400">Class: <?= htmlspecialchars($student_info['grade_level'] . ' - ' . $student_info['class_name']) ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Present Days</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $present_days ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-times-circle text-red-600 dark:text-red-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Absent Days</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $absent_days ?></p>
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
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $late_days ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-percentage text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Attendance Rate</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $attendance_percentage ?>%</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Filters -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                        <form method="GET" class="flex flex-wrap gap-4 items-end">
                            <div class="flex flex-col">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Month</label>
                                <select name="month" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php foreach ($available_months as $month): ?>
                                        <option value="<?= $month ?>" <?= $month_filter == $month ? 'selected' : '' ?>>
                                            <?= date('F Y', strtotime($month . '-01')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                                <i class="fas fa-filter mr-2"></i>Filter
                            </button>
                        </form>
                    </div>

                    <!-- Attendance Records -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-list mr-2"></i>
                                Attendance Records - <?= date('F Y', strtotime($month_filter . '-01')) ?>
                            </h2>
                        </div>

                        <?php if (empty($attendance_records)): ?>
                            <div class="p-8 text-center">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-calendar-times text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Attendance Records</h3>
                                <p class="text-gray-600 dark:text-gray-400">No attendance records found for the selected month.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="w-full">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Day</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time In</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time Out</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Notes</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($attendance_records as $record): ?>
                                            <?php
                                                $status_color = getStatusColor($record['status']);
                                                $status_icon = getStatusIcon($record['status']);
                                                $date = new DateTime($record['date']);
                                            ?>
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= $date->format('M j, Y') ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                                        <?= $date->format('l') ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?= $status_color ?>">
                                                        <i class="fas <?= $status_icon ?> mr-1"></i>
                                                        <?= ucfirst($record['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900 dark:text-white">
                                                        <?= $record['time_in'] ? date('g:i A', strtotime($record['time_in'])) : '-' ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm text-gray-900 dark:text-white">
                                                        <?= $record['time_out'] ? date('g:i A', strtotime($record['time_out'])) : '-' ?>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4">
                                                    <div class="text-sm text-gray-600 dark:text-gray-400">
                                                        <?= htmlspecialchars($record['notes'] ?? '-') ?>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
