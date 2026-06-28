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

// School settings for the printable report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();

$is_teacher = $_SESSION['role'] === 'teacher';
$teacher_id = (int)$_SESSION['user_id'];

// Teachers only report on the classes they teach (class_teachers junction).
$teacher_scope_sql = '';
$teacher_param = [];
if ($is_teacher) {
    $teacher_scope_sql = " AND a.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id) ";
    $teacher_param[':teacher_id'] = $teacher_id;
}

// ---- Filters ----
$selected_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : '';
$min_absences   = isset($_GET['min_absences']) && $_GET['min_absences'] !== '' ? max(1, (int)$_GET['min_absences']) : 1;

// Determine available attendance date range (respecting teacher scope) for sensible defaults.
$range_sql = "SELECT MIN(date) AS mn, MAX(date) AS mx FROM attendance a WHERE 1=1" . $teacher_scope_sql;
$range_stmt = $db->prepare($range_sql);
$range_stmt->execute($teacher_param);
$range = $range_stmt->fetch(PDO::FETCH_ASSOC);
$data_min = $range['mn'] ?? date('Y-m-d');
$data_max = $range['mx'] ?? date('Y-m-d');

$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $data_min;
$to_date   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $data_max;

// Validate date inputs (fall back to data range on malformed values)
$d1 = DateTime::createFromFormat('Y-m-d', $from_date);
$d2 = DateTime::createFromFormat('Y-m-d', $to_date);
if (!$d1 || $d1->format('Y-m-d') !== $from_date) { $from_date = $data_min; }
if (!$d2 || $d2->format('Y-m-d') !== $to_date) { $to_date = $data_max; }
if ($from_date > $to_date) { $tmp = $from_date; $from_date = $to_date; $to_date = $tmp; }

// Classes for the filter dropdown
$classes_sql = "SELECT c.id, c.name FROM classes c WHERE c.status = 'active'";
if ($is_teacher) {
    $classes_sql .= " AND c.id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id)";
}
$classes_sql .= " ORDER BY c.name";
$classes_stmt = $db->prepare($classes_sql);
$classes_stmt->execute($teacher_param);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Shared WHERE clause + params for every data query
$where = " WHERE a.date BETWEEN :from_date AND :to_date " . $teacher_scope_sql;
$base_params = array_merge([':from_date' => $from_date, ':to_date' => $to_date], $teacher_param);
if ($selected_class !== '') {
    $where .= " AND a.class_id = :class_id ";
    $base_params[':class_id'] = $selected_class;
}

// ---- Per-student absenteeism data ----
$report_query = "
    SELECT
        u.id AS student_id,
        u.name AS student_name,
        sp.student_id AS roll_number,
        c.name AS class_name,
        COUNT(a.id) AS total_records,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS present_days
    FROM attendance a
    JOIN users u ON a.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    JOIN classes c ON a.class_id = c.id
    $where
    GROUP BY u.id, u.name, sp.student_id, c.name
    HAVING absent_days >= :min_absences OR late_days > 0
    ORDER BY absent_days DESC, late_days DESC, student_name ASC
";
$report_params = array_merge($base_params, [':min_absences' => $min_absences]);
$report_stmt = $db->prepare($report_query);
$report_stmt->execute($report_params);
$report_data = $report_stmt->fetchAll(PDO::FETCH_ASSOC);

// Filter to only those meeting the absence threshold (keeps late-only rows out when threshold > 1 absences requested)
$report_data = array_values(array_filter($report_data, function ($r) use ($min_absences) {
    return (int)$r['absent_days'] >= $min_absences || ((int)$r['late_days'] > 0 && $min_absences <= 1);
}));

// ---- Aggregate summary across the whole period ----
$summary_query = "
    SELECT
        COUNT(a.id) AS total_records,
        SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS total_absent,
        SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS total_late,
        SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) AS total_present,
        COUNT(DISTINCT CASE WHEN a.status = 'absent' THEN a.student_id END) AS students_absent
    FROM attendance a
    $where
";
$summary_stmt = $db->prepare($summary_query);
$summary_stmt->execute($base_params);
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

$total_records = (int)($summary['total_records'] ?? 0);
$total_absent  = (int)($summary['total_absent'] ?? 0);
$total_late    = (int)($summary['total_late'] ?? 0);
$total_present = (int)($summary['total_present'] ?? 0);
$students_absent = (int)($summary['students_absent'] ?? 0);
$absence_rate = $total_records > 0 ? ($total_absent / $total_records) * 100 : 0;

// ---- Absences by class (bar chart) ----
$class_query = "
    SELECT c.name AS class_name,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
           SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days
    FROM attendance a
    JOIN classes c ON a.class_id = c.id
    $where
    GROUP BY c.id, c.name
    HAVING absent_days > 0 OR late_days > 0
    ORDER BY absent_days DESC
    LIMIT 10
";
$class_stmt = $db->prepare($class_query);
$class_stmt->execute($base_params);
$class_data = $class_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Daily trend (line chart) ----
$trend_query = "
    SELECT a.date,
           SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) AS absent_days,
           SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) AS late_days
    FROM attendance a
    $where
    GROUP BY a.date
    ORDER BY a.date ASC
";
$trend_stmt = $db->prepare($trend_query);
$trend_stmt->execute($base_params);
$trend_data = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper: resolve selected class name
$selected_class_name = 'All Classes';
foreach ($classes as $c) {
    if ($c['id'] == $selected_class) { $selected_class_name = $c['name']; break; }
}

// ---- CSV export (must run before any HTML output) ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="absenteeism_report_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Absenteeism Report']);
    fputcsv($out, ['Class', $selected_class_name]);
    fputcsv($out, ['Period', $from_date . ' to ' . $to_date]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Rank', 'Student Name', 'Student ID', 'Class', 'Days Recorded', 'Absences', 'Lates', 'Present', 'Absence Rate (%)']);
    $rank = 1;
    foreach ($report_data as $row) {
        $rate = $row['total_records'] > 0 ? round(($row['absent_days'] / $row['total_records']) * 100, 1) : 0;
        fputcsv($out, [
            $rank++,
            $row['student_name'],
            $row['roll_number'] ?? 'N/A',
            $row['class_name'],
            $row['total_records'],
            $row['absent_days'],
            $row['late_days'],
            $row['present_days'],
            $rate,
        ]);
    }
    fclose($out);
    exit();
}

$title = "Absenteeism Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Absenteeism Report']
];

// Build the query string used by the CSV export link (preserves current filters)
$export_qs = http_build_query([
    'class_id' => $selected_class,
    'from_date' => $from_date,
    'to_date' => $to_date,
    'min_absences' => $min_absences,
    'export' => 'csv',
]);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div id="web-layout" class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Absenteeism Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track student absences and lateness patterns across classes and time.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($report_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($report_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select name="class_id" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">From Date</label>
                            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">To Date</label>
                            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Min. Absences</label>
                            <input type="number" name="min_absences" min="1" value="<?php echo (int)$min_absences; ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        Showing data for <span class="font-semibold"><?php echo htmlspecialchars($selected_class_name); ?></span>
                        from <span class="font-semibold"><?php echo htmlspecialchars($from_date); ?></span>
                        to <span class="font-semibold"><?php echo htmlspecialchars($to_date); ?></span>.
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Absences</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $total_absent; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-times text-red-600 dark:text-red-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Lates</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $total_late; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-amber-600 dark:text-amber-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Students Absent</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $students_absent; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Absence Rate</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($absence_rate, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-percentage text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($total_records > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Absence Trend</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Daily absences and lateness over the selected period</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Absences by Class</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Classes with the most absences in the period</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="classChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results Table -->
                <?php if (!empty($report_data)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Students with Absences</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo count($report_data); ?> student(s) &bull; <?php echo htmlspecialchars($selected_class_name); ?>
                            &bull; <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student ID</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Days Recorded</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Absences</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Lates</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Absence Rate</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Risk</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php
                                $rank = 1;
                                foreach ($report_data as $student):
                                    $absent = (int)$student['absent_days'];
                                    $records = (int)$student['total_records'];
                                    $rate = $records > 0 ? ($absent / $records) * 100 : 0;
                                    if ($rate >= 25) {
                                        $risk = 'High'; $risk_class = 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300';
                                    } elseif ($rate >= 10) {
                                        $risk = 'Moderate'; $risk_class = 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300';
                                    } else {
                                        $risk = 'Low'; $risk_class = 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300';
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">#<?php echo $rank; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['student_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($student['class_name']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $records; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-bold text-red-600 dark:text-red-400"><?php echo $absent; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center font-semibold text-amber-600 dark:text-amber-400"><?php echo (int)$student['late_days']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white"><?php echo number_format($rate, 1); ?>%</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $risk_class; ?>"><?php echo $risk; ?></span>
                                    </td>
                                </tr>
                                <?php $rank++; endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-check text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Absenteeism Records</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No absences or lateness match the selected filters. Try widening the date range, changing the class, or lowering the minimum absences.</p>
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

<!-- ============================================================ -->
<!-- PRINT REPORT TEMPLATE                                        -->
<!-- ============================================================ -->
<?php if (!empty($report_data)): ?>
<div id="print-report" class="print-report-container">
    <div class="print-page">
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="print-logo-fallback" style="display:none"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
                </div>
                <div class="print-school-info">
                    <h1 class="print-school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <div class="print-title-banner"><h2>Absenteeism Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item">
                <span class="print-meta-label">Class:</span>
                <span class="print-meta-value"><?php echo htmlspecialchars($selected_class_name); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Period:</span>
                <span class="print-meta-value"><?php echo htmlspecialchars($from_date . ' – ' . $to_date); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Total Absences:</span>
                <span class="print-meta-value"><?php echo $total_absent; ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Date Generated:</span>
                <span class="print-meta-value"><?php echo date('F j, Y'); ?></span>
            </div>
        </div>

        <div class="print-section-title">Students with Absences</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:45px">Rank</th>
                    <th style="text-align:left">Student Name</th>
                    <th>Student ID</th>
                    <th>Class</th>
                    <th>Days</th>
                    <th>Absences</th>
                    <th>Lates</th>
                    <th>Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $print_rank = 1;
                foreach ($report_data as $student):
                    $records = (int)$student['total_records'];
                    $rate = $records > 0 ? ((int)$student['absent_days'] / $records) * 100 : 0;
                ?>
                <tr>
                    <td><?php echo $print_rank; ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($student['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($student['class_name']); ?></td>
                    <td><?php echo $records; ?></td>
                    <td class="pct-cell"><?php echo (int)$student['absent_days']; ?></td>
                    <td><?php echo (int)$student['late_days']; ?></td>
                    <td class="pct-cell"><?php echo number_format($rate, 1); ?>%</td>
                </tr>
                <?php $print_rank++; endforeach; ?>
            </tbody>
        </table>

        <?php echo signatureRow(['Class Teacher', 'Head of Department', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>This is a computer-generated document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .print-report-container { display: none; }
    @media print {
        header, #sidebar, #web-layout, .search-overlay { display: none !important; }
        .print-report-container { display: block !important; }
        body, main {
            display: block !important; margin: 0 !important; padding: 0 !important;
            background: white !important; min-height: auto !important; height: auto !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        }
        @page { size: A4 portrait; margin: 10mm; }
    }
    .print-page { font-family: 'Inter','Segoe UI',sans-serif; font-size: 10.5px; line-height: 1.45; color: #1a1a2e; max-width: 210mm; margin: 0 auto; }
    .print-header-inner { display: flex; align-items: center; gap: 16px; padding-bottom: 10px; }
    .print-logo img, .print-logo-fallback { width: 60px; height: 60px; object-fit: contain; }
    .print-logo-fallback { background: linear-gradient(135deg,#1e3a5f,#2563eb); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:26px; font-weight:800; }
    .print-school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1.2px; text-transform: uppercase; margin: 0 0 2px 0; }
    .print-contact-line { font-size: 9px; color: #6b7280; margin: 0; }
    .print-header-divider { height: 3px; background: linear-gradient(to right,#1e3a5f,#2563eb,#7c3aed); border-radius: 3px; margin-bottom: 12px; }
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#7f1d1d,#b91c1c); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #1e3a5f; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #1e3a5f; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #b91c1c; border-radius: 0 4px 4px 0; margin-bottom: 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#7f1d1d,#b91c1c); color: #fff; padding: 7px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #7f1d1d; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #b91c1c; }
    .print-signatures { display: grid; grid-template-columns: repeat(3,1fr); gap: 30px; margin-top: 28px; margin-bottom: 16px; }
    .print-signature-block { text-align: center; }
    .print-signature-block .signature-line { border-top: 1.5px solid #374151; margin-top: 36px; padding-top: 4px; }
    .signature-title { font-size: 10px; font-weight: 700; color: #1e3a5f; }
    .print-footer { text-align: center; padding-top: 10px; border-top: 1px solid #e5e7eb; margin-top: 10px; }
    .print-footer p { font-size: 8px; color: #9ca3af; margin: 0; font-style: italic; }
</style>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($total_records > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Absence trend (line)
    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => date('M j', strtotime($r['date'])), $trend_data)); ?>,
                datasets: [
                    {
                        label: 'Absences',
                        data: <?php echo json_encode(array_map(fn($r) => (int)$r['absent_days'], $trend_data)); ?>,
                        borderColor: 'rgb(239, 68, 68)',
                        backgroundColor: 'rgba(239, 68, 68, 0.12)',
                        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 2
                    },
                    {
                        label: 'Lates',
                        data: <?php echo json_encode(array_map(fn($r) => (int)$r['late_days'], $trend_data)); ?>,
                        borderColor: 'rgb(245, 158, 11)',
                        backgroundColor: 'rgba(245, 158, 11, 0.12)',
                        borderWidth: 2, tension: 0.35, fill: true, pointRadius: 2
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, font: { size: 11 } } } },
                scales: {
                    x: { ticks: { color: labelColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor, precision: 0 }, grid: { color: gridColor } }
                }
            }
        });
    }

    // Absences by class (bar)
    const classCanvas = document.getElementById('classChart');
    if (classCanvas) {
        new Chart(classCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['class_name'], $class_data)); ?>,
                datasets: [{
                    label: 'Absences',
                    data: <?php echo json_encode(array_map(fn($r) => (int)$r['absent_days'], $class_data)); ?>,
                    backgroundColor: 'rgba(239, 68, 68, 0.85)',
                    borderColor: 'rgb(239, 68, 68)', borderWidth: 1, borderRadius: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: labelColor, font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor, precision: 0 }, grid: { color: gridColor } }
                }
            }
        });
    }
});
<?php endif; ?>
</script>
