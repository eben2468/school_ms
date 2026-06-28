<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('attendance');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get filter parameters
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$selected_date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');

// Fetch classes based on user role
if ($user_role === 'teacher') {
    $classes_query = "SELECT DISTINCT c.id, c.name, c.grade_level
                     FROM classes c
                     JOIN class_teachers ct ON c.id = ct.class_id
                     WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
                     ORDER BY c.grade_level, c.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':teacher_id', $user_id);
    $classes_stmt->execute();
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $classes_stmt = $db->query($classes_query);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set default class if none selected
if (!$selected_class_id && !empty($classes)) {
    $selected_class_id = $classes[0]['id'];
}

// Fetch attendance data for selected class and date
$attendance_data = [];
if ($selected_class_id) {
    $attendance_query = "SELECT u.id as student_id, u.name as student_name, sp.student_id as roll_number,
                        a.status, a.notes, a.created_at as marked_at
                        FROM users u
                        JOIN student_classes sc ON u.id = sc.student_id
                        LEFT JOIN student_profiles sp ON u.id = sp.user_id
                        LEFT JOIN attendance a ON u.id = a.student_id AND a.class_id = :class_id AND a.date = :date
                        WHERE sc.class_id = :class_id AND sc.status = 'active' AND u.role = 'student'
                        ORDER BY u.name";
    $attendance_stmt = $db->prepare($attendance_query);
    $attendance_stmt->bindParam(':class_id', $selected_class_id);
    $attendance_stmt->bindParam(':date', $selected_date);
    $attendance_stmt->execute();
    $attendance_data = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get attendance statistics for the selected class
$stats = ['total' => 0, 'present' => 0, 'absent' => 0, 'late' => 0, 'not_marked' => 0];
foreach ($attendance_data as $record) {
    $stats['total']++;
    if ($record['status']) {
        $stats[$record['status']]++;
    } else {
        $stats['not_marked']++;
    }
}

$title = "Attendance Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Attendance Management']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Attendance Management</h1>
                                <p class="text-blue-100 text-lg">Track and manage student attendance records</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-check mr-2"></i>
                                        <?php echo date('F j, Y'); ?>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        <?php echo count($classes); ?> Classes
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-clipboard-check text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="attendance-action-buttons mb-6">
                    <div class="flex flex-wrap items-center gap-2 no-stack">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <a href="take.php" class="group inline-flex items-center gap-2 pl-2 pr-3.5 py-1.5 rounded-lg text-sm font-semibold text-white whitespace-nowrap shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" style="background-image: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <span class="w-6 h-6 rounded-md bg-white/20 flex items-center justify-center text-xs group-hover:bg-white/30 transition-colors"><i class="fas fa-clipboard-check"></i></span>
                            Take Attendance
                        </a>
                        <?php endif; ?>
                        <a href="reports.php" class="group inline-flex items-center gap-2 pl-2 pr-3.5 py-1.5 rounded-lg text-sm font-semibold text-white whitespace-nowrap shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" style="background-image: linear-gradient(135deg, #10b981, #059669);">
                            <span class="w-6 h-6 rounded-md bg-white/20 flex items-center justify-center text-xs group-hover:bg-white/30 transition-colors"><i class="fas fa-chart-bar"></i></span>
                            View Reports
                        </a>
                    </div>
                    <div class="export-button-wrapper">
                        <button onclick="exportAttendance()" class="group inline-flex items-center gap-2 pl-2 pr-3.5 py-1.5 rounded-lg text-sm font-semibold text-white whitespace-nowrap shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200" style="background-image: linear-gradient(135deg, #64748b, #475569);">
                            <span class="w-6 h-6 rounded-md bg-white/20 flex items-center justify-center text-xs group-hover:bg-white/30 transition-colors"><i class="fas fa-download"></i></span>
                            Export
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Filter Attendance</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Select class and date to view attendance records</p>
                    </div>
                    <div class="p-6">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-chalkboard mr-2 text-blue-500"></i>Select Class
                                </label>
                                <select id="class_id" name="class_id" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-calendar-alt mr-2 text-green-500"></i>Select Date
                                </label>
                                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center">
                                    <i class="fas fa-search mr-2"></i>View Attendance
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Attendance Statistics -->
                <?php if ($selected_class_id && !empty($attendance_data)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                    <!-- Total Students -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Students</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total']; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-users mr-1"></i>
                                    Enrolled
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Present -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Present</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['present']; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-check mr-1"></i>
                                    <?php echo $stats['total'] > 0 ? round(($stats['present'] / $stats['total']) * 100, 1) : 0; ?>%
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Absent -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Absent</p>
                                <p class="text-3xl font-bold text-red-600 dark:text-red-400"><?php echo $stats['absent']; ?></p>
                                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                                    <i class="fas fa-times mr-1"></i>
                                    <?php echo $stats['total'] > 0 ? round(($stats['absent'] / $stats['total']) * 100, 1) : 0; ?>%
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Late -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Late</p>
                                <p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo $stats['late']; ?></p>
                                <p class="text-sm text-yellow-600 dark:text-yellow-400 mt-1">
                                    <i class="fas fa-clock mr-1"></i>
                                    <?php echo $stats['total'] > 0 ? round(($stats['late'] / $stats['total']) * 100, 1) : 0; ?>%
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Not Marked -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Not Marked</p>
                                <p class="text-3xl font-bold text-gray-600 dark:text-gray-400"><?php echo $stats['not_marked']; ?></p>
                                <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    <i class="fas fa-question mr-1"></i>
                                    Pending
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center">
                                <i class="fas fa-question text-gray-600 dark:text-gray-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Attendance Records -->
                <?php if ($selected_class_id): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <div>
                            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                Attendance for <?php echo date('F j, Y', strtotime($selected_date)); ?>
                            </h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                <?php
                                $selected_class = array_filter($classes, function($class) use ($selected_class_id) {
                                    return $class['id'] == $selected_class_id;
                                });
                                $selected_class = reset($selected_class);
                                echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']);
                                ?>
                            </p>
                        </div>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <a href="take.php?class_id=<?php echo $selected_class_id; ?>&date=<?php echo $selected_date; ?>"
                            class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg text-sm shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                            <i class="fas fa-edit mr-2"></i>Mark Attendance
                        </a>
                        <?php endif; ?>
                    </div>

                    <?php if (empty($attendance_data)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Students Found</h3>
                        <p class="text-gray-500 dark:text-gray-400">No students are enrolled in this class.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Roll Number</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Notes</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Marked At</th>
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($attendance_data as $record): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold mr-3">
                                                <?php echo strtoupper(substr($record['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($record['student_name']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200">
                                            <?php echo htmlspecialchars($record['roll_number'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium
                                            <?php
                                            switch($record['status']) {
                                                case 'present':
                                                    echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                                    $icon = 'check';
                                                    break;
                                                case 'absent':
                                                    echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                                    $icon = 'times';
                                                    break;
                                                case 'late':
                                                    echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                                    $icon = 'clock';
                                                    break;
                                                default:
                                                    echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200';
                                                    $icon = 'question';
                                            }
                                            ?>">
                                            <i class="fas fa-<?php echo $icon; ?> mr-1"></i>
                                            <?php echo $record['status'] ? ucfirst($record['status']) : 'Not Marked'; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-900 dark:text-gray-300">
                                        <?php echo htmlspecialchars($record['notes'] ?? '-'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center">
                                            <i class="fas fa-clock mr-2"></i>
                                            <?php echo $record['marked_at'] ? date('g:i A', strtotime($record['marked_at'])) : '-'; ?>
                                        </div>
                                    </td>
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <a href="edit.php?student_id=<?php echo $record['student_id']; ?>&class_id=<?php echo $selected_class_id; ?>&date=<?php echo $selected_date; ?>"
                                            class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800 transition-colors duration-200">
                                            <i class="fas fa-edit mr-1"></i>Edit
                                        </a>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-clipboard-list text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Class Selected</h3>
                    <p class="text-gray-500 dark:text-gray-400">Please select a class to view attendance records.</p>
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

<script>
function exportAttendance() {
    // Add export functionality here
    alert('Export functionality will be implemented');
}
</script>