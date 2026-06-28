<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
$database = new Database();
$db = $database->getConnection();

// Fetch school settings for print report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();
$school_motto = '';
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    $motto_result = $motto_stmt->fetch(PDO::FETCH_ASSOC);
    if ($motto_result) $school_motto = $motto_result['setting_value'];
} catch (PDOException $e) {
    // Not available
}

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get filter parameters
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
// Default start date to 1 year ago to capture existing June 2025 records
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d', strtotime('-1 year'));
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
$report_type = filter_input(INPUT_GET, 'report_type', FILTER_SANITIZE_STRING) ?: 'summary';

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

// Generate report data based on type
$report_data = [];
$attendance_totals = [
    'present' => 0,
    'absent' => 0,
    'late' => 0
];

if ($selected_class_id && $report_type === 'summary') {
    // Summary report: attendance statistics per student
    $summary_query = "SELECT u.id, u.name, sp.student_id as roll_number,
                     COUNT(a.id) as total_days,
                     SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                     SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                     SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
                     ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 1) as attendance_percentage
                     FROM users u
                     JOIN student_classes sc ON u.id = sc.student_id
                     LEFT JOIN student_profiles sp ON u.id = sp.user_id
                     LEFT JOIN attendance a ON u.id = a.student_id AND a.class_id = :class_id 
                         AND a.date BETWEEN :start_date AND :end_date
                     WHERE sc.class_id = :class_id AND sc.status = 'active' AND u.role = 'student'
                     GROUP BY u.id
                     HAVING total_days > 0
                     ORDER BY u.name";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->bindParam(':class_id', $selected_class_id);
    $summary_stmt->bindParam(':start_date', $start_date);
    $summary_stmt->bindParam(':end_date', $end_date);
    $summary_stmt->execute();
    $report_data = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate overall class totals for doughnut chart
    foreach ($report_data as $row) {
        $attendance_totals['present'] += (int)$row['present_days'];
        $attendance_totals['absent'] += (int)$row['absent_days'];
        $attendance_totals['late'] += (int)$row['late_days'];
    }
} elseif ($selected_class_id && $report_type === 'daily') {
    // Daily report: attendance for each day
    $daily_query = "SELECT a.date,
                   COUNT(a.id) as total_marked,
                   SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                   SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                   ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 1) as attendance_percentage
                   FROM attendance a
                   WHERE a.class_id = :class_id AND a.date BETWEEN :start_date AND :end_date
                   GROUP BY a.date
                   ORDER BY a.date ASC"; // Sorted ASC for the line chart chronological flow
    $daily_stmt = $db->prepare($daily_query);
    $daily_stmt->bindParam(':class_id', $selected_class_id);
    $daily_stmt->bindParam(':start_date', $start_date);
    $daily_stmt->bindParam(':end_date', $end_date);
    $daily_stmt->execute();
    $report_data = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get selected class details
$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $class) {
        if ($class['id'] == $selected_class_id) {
            $selected_class = $class;
            break;
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div id="web-layout" class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Attendance Reports</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 font-medium">Generate statistics and charts of class and student attendance rates.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <?php if ($selected_class_id && !empty($report_data)): ?>
                    <button onclick="exportReport()" class="border border-indigo-600 text-indigo-600 hover:bg-indigo-50 dark:border-indigo-400 dark:text-indigo-400 dark:hover:bg-indigo-950/30 font-semibold px-4 py-2 rounded-lg shadow-sm transition inline-flex items-center whitespace-nowrap">
                        <i class="fas fa-download mr-2"></i>Export CSV
                    </button>
                    <button onclick="printReport()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition inline-flex items-center whitespace-nowrap">
                        <i class="fas fa-print mr-2"></i>Print Report
                    </button>
                    <?php endif; ?>
                    <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition inline-flex items-center whitespace-nowrap shadow-sm">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label for="class_id" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select id="class_id" name="class_id" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="report_type" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Report Type</label>
                            <select id="report_type" name="report_type"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Student Summary</option>
                                <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Summary</option>
                            </select>
                        </div>
                        <div>
                            <label for="start_date" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-chart-line mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id && !empty($report_data)): ?>
            
            <!-- Graphic Insight Card -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Visual Analytics</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">
                    <?php echo $report_type === 'summary' ? 'Breakdown of present, absent, and late tallies across selected class.' : 'Daily attendance trends over the selected period.'; ?>
                </p>
                <div class="relative w-full flex items-center justify-center" style="height: 250px;">
                    <canvas id="attendanceChart"></canvas>
                </div>
            </div>

            <!-- Report Content -->
            <div id="report-content" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">
                        <?php echo $report_type === 'summary' ? 'Student Summary' : 'Daily Summary'; ?> Attendance Report - <?php echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']); ?>
                    </h2>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Period: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <?php if ($report_type === 'summary'): ?>
                    <!-- Student Summary Report -->
                    <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                        <thead class="bg-gray-50 dark:bg-gray-750">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Roll/ID Number</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total Days</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Present</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Absent</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Late</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Attendance %</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($report_data as $student): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-350">
                                    <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-950 dark:text-white text-center">
                                    <?php echo $student['total_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-650 dark:text-green-400 text-center font-bold">
                                    <?php echo $student['present_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-650 dark:text-red-400 text-center font-bold">
                                    <?php echo $student['absent_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 dark:text-yellow-400 text-center font-bold">
                                    <?php echo $student['late_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    <?php
                                    $pct = (float)$student['attendance_percentage'];
                                    $badge = 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300';
                                    if ($pct >= 90) {
                                        $badge = 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
                                    } elseif ($pct >= 75) {
                                        $badge = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300';
                                    }
                                    ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full <?php echo $badge; ?>">
                                        <?php echo $student['attendance_percentage'] ?? 0; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <!-- Daily Summary Report -->
                    <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                        <thead class="bg-gray-50 dark:bg-gray-750">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-semibold">Total Marked</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Present</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Absent</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Late</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Attendance %</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach (array_reverse($report_data) as $day): // Show latest dates first in table ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo date('M j, Y (D)', strtotime($day['date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-950 dark:text-white text-center font-medium">
                                    <?php echo $day['total_marked']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-650 dark:text-green-400 text-center font-bold">
                                    <?php echo $day['present_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-650 dark:text-red-400 text-center font-bold">
                                    <?php echo $day['absent_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 dark:text-yellow-400 text-center font-bold">
                                    <?php echo $day['late_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-bold">
                                    <?php
                                    $pct = (float)$day['attendance_percentage'];
                                    $badge = 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300';
                                    if ($pct >= 90) {
                                        $badge = 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
                                    } elseif ($pct >= 75) {
                                        $badge = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-300';
                                    }
                                    ?>
                                    <span class="px-2.5 py-1 text-xs font-bold rounded-full <?php echo $badge; ?>">
                                        <?php echo $day['attendance_percentage'] ?? 0; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($selected_class_id && empty($report_data)): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-calendar-xmark text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Attendance Data Found</h3>
                <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">There is no attendance marked for the selected class during this period (<?php echo date('M Y', strtotime($start_date)); ?> to <?php echo date('M Y', strtotime($end_date)); ?>).</p>
            </div>
            <?php else: ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                <div class="w-20 h-20 bg-indigo-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-filter text-indigo-500 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Generate Attendance Breakdown</h3>
                <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">Select a class and define the date bounds above to fetch comprehensive school attendance records.</p>
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

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
function exportReport() {
    // Simple CSV export functionality
    const table = document.querySelector('#report-content table');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'attendance_report_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function printReport() {
    document.body.classList.add('printing-report');
    setTimeout(function() {
        window.print();
        document.body.classList.remove('printing-report');
    }, 100);
}
</script>

<?php if ($selected_class_id && !empty($report_data)): ?>
<!-- ============================================================ -->
<!-- PRINT REPORT TEMPLATE (Hidden on screen, shown during print) -->
<!-- ============================================================ -->
<div id="print-attendance" class="print-report-container">
    <div class="print-page">
        <!-- School Letterhead -->
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="print-logo-fallback" style="display:none">
                        <?php echo strtoupper(substr($school_name, 0, 1)); ?>
                    </div>
                </div>
                <div class="print-school-info">
                    <h1 class="print-school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                    <?php if ($school_motto): ?>
                    <p class="print-motto">"<?php echo htmlspecialchars($school_motto); ?>"</p>
                    <?php endif; ?>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <!-- Report Title Banner -->
        <div class="print-title-banner">
            <h2><?php echo $report_type === 'summary' ? 'Student Attendance Summary' : 'Daily Attendance Summary'; ?></h2>
        </div>

        <!-- Report Meta Information -->
        <div class="print-meta-grid">
            <div class="print-meta-item">
                <span class="print-meta-label">Class:</span>
                <span class="print-meta-value"><?php echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Report Type:</span>
                <span class="print-meta-value"><?php echo $report_type === 'summary' ? 'Student Breakdown' : 'Daily Trend'; ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Period:</span>
                <span class="print-meta-value"><?php echo date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date)); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Date Generated:</span>
                <span class="print-meta-value"><?php echo date('F j, Y'); ?></span>
            </div>
        </div>

        <?php if ($report_type === 'summary'): ?>
            <?php
            // Calculate student summary stats
            $total_students = count($report_data);
            $sum_percentage = 0;
            $excellent_count = 0;
            $satisfactory_count = 0;
            $needs_support_count = 0;

            foreach ($report_data as $student) {
                $pct = (float)$student['attendance_percentage'];
                $sum_percentage += $pct;

                if ($pct >= 90) {
                    $excellent_count++;
                } elseif ($pct >= 75) {
                    $satisfactory_count++;
                } else {
                    $needs_support_count++;
                }
            }
            $class_average = $total_students > 0 ? ($sum_percentage / $total_students) : 0;
            ?>

            <!-- Summary Statistics -->
            <div class="print-section-title">Attendance Summary</div>
            <div class="print-summary-grid">
                <div class="print-summary-card print-summary-blue">
                    <div class="print-summary-value"><?php echo $total_students; ?></div>
                    <div class="print-summary-label">Students Evaluated</div>
                </div>
                <div class="print-summary-card print-summary-green">
                    <div class="print-summary-value"><?php echo number_format($class_average, 1); ?>%</div>
                    <div class="print-summary-label">Class Average</div>
                </div>
                <div class="print-summary-card print-summary-purple">
                    <div class="print-summary-value"><?php echo $excellent_count; ?></div>
                    <div class="print-summary-label">Excellent (90%+)</div>
                </div>
                <div class="print-summary-card print-summary-red">
                    <div class="print-summary-value"><?php echo $needs_support_count; ?></div>
                    <div class="print-summary-label">Support Needed (&lt;75%)</div>
                </div>
            </div>

            <!-- Distribution Bar -->
            <div class="print-section-title">Attendance Distribution</div>
            <div class="print-distribution-bar">
                <?php if ($total_students > 0): ?>
                <?php $pct_excellent = ($excellent_count / $total_students) * 100; ?>
                <?php $pct_satisfactory = ($satisfactory_count / $total_students) * 100; ?>
                <?php $pct_needs = ($needs_support_count / $total_students) * 100; ?>
                <?php if ($pct_excellent > 0): ?><div class="dist-segment dist-excellent" style="width:<?php echo $pct_excellent; ?>%"><span><?php echo $excellent_count; ?></span></div><?php endif; ?>
                <?php if ($pct_satisfactory > 0): ?><div class="dist-segment dist-good" style="width:<?php echo $pct_satisfactory; ?>%"><span><?php echo $satisfactory_count; ?></span></div><?php endif; ?>
                <?php if ($pct_needs > 0): ?><div class="dist-segment dist-needs" style="width:<?php echo $pct_needs; ?>%"><span><?php echo $needs_support_count; ?></span></div><?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="print-dist-legend">
                <span class="legend-item"><span class="legend-dot" style="background:#059669"></span>Excellent (90%+)</span>
                <span class="legend-item"><span class="legend-dot" style="background:#2563eb"></span>Satisfactory (75-89%)</span>
                <span class="legend-item"><span class="legend-dot" style="background:#dc2626"></span>Needs Support (&lt;75%)</span>
            </div>

            <!-- Standings Table -->
            <div class="print-section-title">Student Attendance Breakdown</div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:50px">No.</th>
                        <th style="text-align:left">Student Name</th>
                        <th>Roll/ID Number</th>
                        <th>Total Days</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Attendance %</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $count = 1;
                    foreach ($report_data as $student):
                        $pct = (float)$student['attendance_percentage'];
                        $status = 'Needs Support';
                        $status_class = 'grade-f';
                        if ($pct >= 90) {
                            $status = 'Excellent';
                            $status_class = 'grade-a';
                        } elseif ($pct >= 75) {
                            $status = 'Satisfactory';
                            $status_class = 'grade-b';
                        }
                    ?>
                    <tr>
                        <td><?php echo $count++; ?></td>
                        <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($student['name']); ?></td>
                        <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                        <td><?php echo $student['total_days']; ?></td>
                        <td style="color:#059669;font-weight:700"><?php echo $student['present_days']; ?></td>
                        <td style="color:#dc2626;font-weight:700"><?php echo $student['absent_days']; ?></td>
                        <td style="color:#d97706;font-weight:700"><?php echo $student['late_days']; ?></td>
                        <td class="pct-cell"><?php echo number_format($pct, 1); ?>%</td>
                        <td><span class="print-grade-badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

        <?php else: ?>
            <?php
            // Calculate daily summary stats
            $total_days = count($report_data);
            $sum_percentage = 0;
            $best_pct = 0;
            $best_date = 'N/A';
            $worst_pct = 100;
            $worst_date = 'N/A';

            foreach ($report_data as $day) {
                $pct = (float)$day['attendance_percentage'];
                $sum_percentage += $pct;

                if ($pct > $best_pct) {
                    $best_pct = $pct;
                    $best_date = date('M j, Y', strtotime($day['date']));
                }
                if ($pct < $worst_pct) {
                    $worst_pct = $pct;
                    $worst_date = date('M j, Y', strtotime($day['date']));
                }
            }
            $avg_daily_rate = $total_days > 0 ? ($sum_percentage / $total_days) : 0;
            if ($worst_date === 'N/A') $worst_pct = 0;
            ?>

            <!-- Summary Statistics -->
            <div class="print-section-title">Attendance Summary</div>
            <div class="print-summary-grid">
                <div class="print-summary-card print-summary-blue">
                    <div class="print-summary-value"><?php echo $total_days; ?></div>
                    <div class="print-summary-label">Days Logged</div>
                </div>
                <div class="print-summary-card print-summary-green">
                    <div class="print-summary-value"><?php echo number_format($avg_daily_rate, 1); ?>%</div>
                    <div class="print-summary-label">Average Attendance</div>
                </div>
                <div class="print-summary-card print-summary-purple">
                    <div class="print-summary-value"><?php echo number_format($best_pct, 1); ?>%</div>
                    <div class="print-summary-label">Best (<?php echo $best_date; ?>)</div>
                </div>
                <div class="print-summary-card print-summary-red">
                    <div class="print-summary-value"><?php echo number_format($worst_pct, 1); ?>%</div>
                    <div class="print-summary-label">Lowest (<?php echo $worst_date; ?>)</div>
                </div>
            </div>

            <!-- Breakdown Standings Table -->
            <div class="print-section-title">Daily Attendance Logs</div>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:50px">No.</th>
                        <th style="text-align:left">Date</th>
                        <th>Total Marked</th>
                        <th>Present</th>
                        <th>Absent</th>
                        <th>Late</th>
                        <th>Attendance Rate</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $count = 1;
                    foreach ($report_data as $day):
                        $pct = (float)$day['attendance_percentage'];
                        $status = 'Needs Support';
                        $status_class = 'grade-f';
                        if ($pct >= 90) {
                            $status = 'Excellent';
                            $status_class = 'grade-a';
                        } elseif ($pct >= 75) {
                            $status = 'Satisfactory';
                            $status_class = 'grade-b';
                        }
                    ?>
                    <tr>
                        <td><?php echo $count++; ?></td>
                        <td style="text-align:left;font-weight:600"><?php echo date('F j, Y (D)', strtotime($day['date'])); ?></td>
                        <td><?php echo $day['total_marked']; ?></td>
                        <td style="color:#059669;font-weight:700"><?php echo $day['present_count']; ?></td>
                        <td style="color:#dc2626;font-weight:700"><?php echo $day['absent_count']; ?></td>
                        <td style="color:#d97706;font-weight:700"><?php echo $day['late_count']; ?></td>
                        <td class="pct-cell"><?php echo number_format($pct, 1); ?>%</td>
                        <td><span class="print-grade-badge <?php echo $status_class; ?>"><?php echo $status; ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Signatures Section -->
        <?php echo signatureRow(['Class Teacher', 'Headmaster/Headmistress']); ?>

        <!-- Footer -->
        <div class="print-footer">
            <p>This is a computer-generated document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Print Report Styles -->
<style>
    /* ===== PRINT REPORT ON-SCREEN: HIDDEN ===== */
    .print-report-container {
        display: none;
    }

    /* ===== PRINT MEDIA STYLES ===== */
    @media print {
        /* Hide screen-only elements */
        header,
        #sidebar,
        #web-layout,
        .search-overlay {
            display: none !important;
        }
        
        /* Ensure the print container is visible */
        .print-report-container {
            display: block !important;
        }
        
        /* Reset body and main element layout for printing */
        body, main {
            display: block !important;
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
            min-height: auto !important;
            height: auto !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
    }

    /* ===== Print Page Layout ===== */
    .print-page {
        font-family: 'Inter', 'Segoe UI', -apple-system, sans-serif;
        font-size: 10.5px;
        line-height: 1.45;
        color: #1a1a2e;
        max-width: 210mm;
        margin: 0 auto;
    }

    /* ===== School Header / Letterhead ===== */
    .print-header {
        margin-bottom: 4px;
    }
    .print-header-inner {
        display: flex;
        align-items: center;
        gap: 16px;
        padding-bottom: 10px;
    }
    .print-logo {
        flex-shrink: 0;
    }
    .print-logo img {
        width: 62px;
        height: 62px;
        object-fit: contain;
    }
    .print-logo-fallback {
        width: 62px;
        height: 62px;
        background: linear-gradient(135deg, #1e3a5f, #2563eb);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 28px;
        font-weight: 800;
    }
    .print-school-info {
        flex: 1;
    }
    .print-school-name {
        font-size: 22px;
        font-weight: 800;
        color: #1e3a5f;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        margin: 0 0 2px 0;
        line-height: 1.2;
    }
    .print-motto {
        font-size: 10.5px;
        font-style: italic;
        color: #2563eb;
        font-weight: 500;
        margin: 0 0 3px 0;
    }
    .print-contact-line {
        font-size: 9px;
        color: #6b7280;
        margin: 0;
    }
    .print-header-divider {
        height: 3px;
        background: linear-gradient(to right, #1e3a5f, #2563eb, #7c3aed);
        border-radius: 3px;
        margin-bottom: 12px;
    }

    /* ===== Title Banner ===== */
    .print-title-banner {
        text-align: center;
        background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
        color: white;
        padding: 7px 20px;
        border-radius: 5px;
        margin-bottom: 12px;
    }
    .print-title-banner h2 {
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        margin: 0;
    }

    /* ===== Report Meta Grid ===== */
    .print-meta-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        overflow: hidden;
        margin-bottom: 14px;
    }
    .print-meta-item {
        padding: 6px 12px;
        border-right: 1px solid #e5e7eb;
        background: #f8fafc;
    }
    .print-meta-item:last-child {
        border-right: none;
    }
    .print-meta-label {
        font-size: 8.5px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        display: block;
    }
    .print-meta-value {
        font-size: 11px;
        font-weight: 700;
        color: #1e3a5f;
        display: block;
    }

    /* ===== Section Titles ===== */
    .print-section-title {
        font-size: 11px;
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        padding: 5px 10px;
        background: #eef2f7;
        border-left: 4px solid #1e3a5f;
        border-radius: 0 4px 4px 0;
        margin-bottom: 8px;
    }

    /* ===== Summary Cards ===== */
    .print-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 14px;
    }
    .print-summary-card {
        text-align: center;
        padding: 10px 8px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
    }
    .print-summary-value {
        font-size: 20px;
        font-weight: 800;
        line-height: 1.2;
    }
    .print-summary-label {
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
        font-weight: 600;
    }
    .print-summary-blue {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    .print-summary-blue .print-summary-value { color: #1d4ed8; }
    .print-summary-blue .print-summary-label { color: #3b82f6; }
    .print-summary-green {
        background: #f0fdf4;
        border-color: #bbf7d0;
    }
    .print-summary-green .print-summary-value { color: #15803d; }
    .print-summary-green .print-summary-label { color: #22c55e; }
    .print-summary-purple {
        background: #faf5ff;
        border-color: #e9d5ff;
    }
    .print-summary-purple .print-summary-value { color: #7e22ce; }
    .print-summary-purple .print-summary-label { color: #a855f7; }
    .print-summary-red {
        background: #fef2f2;
        border-color: #fecaca;
    }
    .print-summary-red .print-summary-value { color: #b91c1c; }
    .print-summary-red .print-summary-label { color: #ef4444; }

    /* ===== Distribution Bar ===== */
    .print-distribution-bar {
        display: flex;
        height: 22px;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 6px;
        border: 1px solid #d1d5db;
    }
    .dist-segment {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
    }
    .dist-segment span {
        font-size: 9px;
        font-weight: 700;
        color: white;
    }
    .dist-excellent { background: #059669; }
    .dist-good { background: #2563eb; }
    .dist-needs { background: #dc2626; }
    .print-dist-legend {
        display: flex;
        gap: 16px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .legend-item {
        font-size: 8.5px;
        color: #4b5563;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 2px;
        display: inline-block;
    }

    /* ===== Performance Table ===== */
    .print-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
        font-size: 10px;
    }
    .print-table thead th {
        background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
        color: white;
        padding: 7px 8px;
        text-align: center;
        font-weight: 600;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border: 1px solid #1a3455;
    }
    .print-table tbody td {
        padding: 5px 8px;
        text-align: center;
        border: 1px solid #e5e7eb;
        font-size: 10px;
    }
    .print-table tbody tr:nth-child(even) {
        background: #f9fafb;
    }
    .pct-cell {
        font-weight: 700;
        color: #1e3a5f;
    }
    .print-grade-badge {
        display: inline-block;
        padding: 2px 10px;
        border-radius: 10px;
        font-weight: 700;
        font-size: 9.5px;
    }
    .grade-a { background: #d1fae5; color: #065f46; }
    .grade-b { background: #dbeafe; color: #1e40af; }
    .grade-f { background: #fecaca; color: #991b1b; }

    /* ===== Signatures ===== */
    .print-signatures {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 30px;
        margin-top: 24px;
        margin-bottom: 16px;
    }
    .print-signature-block {
        text-align: center;
    }
    .print-signature-block .signature-line {
        border-top: 1.5px solid #374151;
        margin-top: 36px;
        padding-top: 4px;
    }
    .signature-title {
        font-size: 10px;
        font-weight: 700;
        color: #1e3a5f;
    }
    .signature-date {
        font-size: 8.5px;
        color: #6b7280;
        margin-top: 2px;
    }

    /* ===== Footer ===== */
    .print-footer {
        text-align: center;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        margin-top: 10px;
    }
    .print-footer p {
        font-size: 8px;
        color: #9ca3af;
        margin: 0;
        font-style: italic;
    }
</style>

<script>
<?php if ($selected_class_id && !empty($report_data)): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDarkMode = document.documentElement.classList.contains('dark');
    const labelColor = isDarkMode ? '#9ca3af' : '#4b5563';
    const gridColor = isDarkMode ? '#374151' : '#f3f4f6';
    const ctx = document.getElementById('attendanceChart').getContext('2d');

    <?php if ($report_type === 'summary'): ?>
    // summary doughnut
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['Present days', 'Absent days', 'Late days'],
            datasets: [{
                data: [
                    <?php echo $attendance_totals['present']; ?>,
                    <?php echo $attendance_totals['absent']; ?>,
                    <?php echo $attendance_totals['late']; ?>
                ],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.85)',
                    'rgba(239, 68, 68, 0.85)',
                    'rgba(234, 179, 8, 0.85)'
                ],
                borderColor: isDarkMode ? '#1f2937' : '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: labelColor
                    }
                }
            }
        }
    });
    <?php else: ?>
    // daily line chart
    <?php
    $dates = [];
    $pcts = [];
    foreach ($report_data as $day) {
        $dates[] = date('M j', strtotime($day['date']));
        $pcts[] = (float)$day['attendance_percentage'];
    }
    ?>

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($dates); ?>,
            datasets: [{
                label: 'Attendance Rate (%)',
                data: <?php echo json_encode($pcts); ?>,
                borderColor: 'rgb(79, 70, 229)',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2,
                pointRadius: 4,
                pointBackgroundColor: 'rgb(79, 70, 229)'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    max: 100,
                    beginAtZero: true,
                    ticks: {
                        color: labelColor
                    },
                    grid: {
                        color: gridColor
                    }
                },
                x: {
                    ticks: {
                        color: labelColor
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
    <?php endif; ?>
});
<?php endif; ?>
</script>

