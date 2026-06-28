<?php
session_start();
// Teacher performance is an evaluative report — restricted to school leadership.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
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

// ---- Filters ----
$term_map = ['first' => '1', 'second' => '2', 'third' => '3'];
$selected_term = isset($_GET['term']) && isset($term_map[$_GET['term']]) ? $_GET['term'] : 'third';
$term_number = $term_map[$selected_term];

$years = $db->query("SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$default_year_id = null;
foreach ($years as $y) { if ($y['status'] === 'active') { $default_year_id = (int)$y['id']; break; } }
if ($default_year_id === null && !empty($years)) { $default_year_id = (int)$years[0]['id']; }
$selected_year = isset($_GET['year_id']) && $_GET['year_id'] !== '' ? (int)$_GET['year_id'] : $default_year_id;

// Shared WHERE for grade-record aggregation
$where = " WHERE at.term_number = :term_number AND sar.academic_year_id = :year_id ";
$params = [':term_number' => $term_number, ':year_id' => $selected_year];

// ---- Per-teacher aggregation (based on grade records they recorded) ----
$teacher_query = "
    SELECT sar.teacher_id, u.name AS teacher_name, u.email,
        COUNT(sar.id) AS graded_records,
        COUNT(DISTINCT sar.student_id) AS students_assessed,
        COUNT(DISTINCT sar.subject_id) AS subjects_taught,
        COUNT(DISTINCT sar.class_id) AS classes_taught,
        AVG(sar.total_score) AS avg_score,
        SUM(CASE WHEN sar.total_score >= 50 THEN 1 ELSE 0 END) AS pass_count
    FROM student_academic_records sar
    JOIN users u ON sar.teacher_id = u.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    $where
    GROUP BY sar.teacher_id, u.name, u.email
    HAVING graded_records > 0
    ORDER BY avg_score DESC
";
$teacher_stmt = $db->prepare($teacher_query);
$teacher_stmt->execute($params);
$teacher_data = $teacher_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Assignments created per teacher (productivity) ----
$assignment_counts = [];
try {
    $asg = $db->query("SELECT teacher_id, COUNT(*) AS cnt FROM assignments GROUP BY teacher_id");
    foreach ($asg->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $assignment_counts[(int)$row['teacher_id']] = (int)$row['cnt'];
    }
} catch (PDOException $e) { /* ignore */ }

// ---- Overall summary + grade distribution ----
$dist_stmt = $db->prepare("
    SELECT
        COUNT(sar.id) AS total_records,
        COUNT(DISTINCT sar.teacher_id) AS total_teachers,
        AVG(sar.total_score) AS overall_avg,
        SUM(CASE WHEN sar.total_score >= 50 THEN 1 ELSE 0 END) AS pass_count,
        SUM(CASE WHEN sar.total_score >= 80 THEN 1 ELSE 0 END) AS grade_a,
        SUM(CASE WHEN sar.total_score >= 70 AND sar.total_score < 80 THEN 1 ELSE 0 END) AS grade_b,
        SUM(CASE WHEN sar.total_score >= 60 AND sar.total_score < 70 THEN 1 ELSE 0 END) AS grade_c,
        SUM(CASE WHEN sar.total_score >= 50 AND sar.total_score < 60 THEN 1 ELSE 0 END) AS grade_d,
        SUM(CASE WHEN sar.total_score < 50 THEN 1 ELSE 0 END) AS grade_f
    FROM student_academic_records sar
    JOIN academic_terms at ON sar.academic_term_id = at.id
    $where
");
$dist_stmt->execute($params);
$dist = $dist_stmt->fetch(PDO::FETCH_ASSOC);

$total_records = (int)($dist['total_records'] ?? 0);
$total_teachers = (int)($dist['total_teachers'] ?? 0);
$overall_avg = (float)($dist['overall_avg'] ?? 0);
$pass_count = (int)($dist['pass_count'] ?? 0);
$overall_pass_rate = $total_records > 0 ? ($pass_count / $total_records) * 100 : 0;
$grade_a = (int)($dist['grade_a'] ?? 0);
$grade_b = (int)($dist['grade_b'] ?? 0);
$grade_c = (int)($dist['grade_c'] ?? 0);
$grade_d = (int)($dist['grade_d'] ?? 0);
$grade_f = (int)($dist['grade_f'] ?? 0);
$total_assignments = array_sum($assignment_counts);

$teachers_evaluated = count($teacher_data);

function performance_rating($avg)
{
    if ($avg >= 70) return ['Excellent', 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300'];
    if ($avg >= 60) return ['Very Good', 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'];
    if ($avg >= 50) return ['Satisfactory', 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'];
    return ['Needs Support', 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300'];
}

$selected_year_name = '';
foreach ($years as $y) { if ((int)$y['id'] === $selected_year) { $selected_year_name = $y['year_name']; break; } }

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="teacher_performance_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Teacher Performance Report']);
    fputcsv($out, ['Academic Year', $selected_year_name]);
    fputcsv($out, ['Term', ucfirst($selected_term) . ' Term']);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Rank', 'Teacher', 'Classes', 'Subjects', 'Students Assessed', 'Records Graded', 'Assignments', 'Avg Student Score', 'Pass Rate (%)']);
    $rank = 1;
    foreach ($teacher_data as $row) {
        $pass_rate = $row['graded_records'] > 0 ? round(($row['pass_count'] / $row['graded_records']) * 100, 1) : 0;
        fputcsv($out, [
            $rank++, $row['teacher_name'], $row['classes_taught'], $row['subjects_taught'],
            $row['students_assessed'], $row['graded_records'],
            $assignment_counts[(int)$row['teacher_id']] ?? 0,
            number_format($row['avg_score'], 1), $pass_rate,
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'year_id' => $selected_year,
    'term' => $selected_term,
    'export' => 'csv',
]);

$title = "Teacher Performance Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Teacher Performance Report']
];

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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Teacher Performance Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Evaluate teaching outcomes by student results, teaching load, and assignment activity.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($teacher_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($teacher_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                            <select name="year_id" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $selected_year === (int)$year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?><?php echo $year['status'] === 'active' ? ' (Current)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                            <select name="term" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="first" <?php echo $selected_term === 'first' ? 'selected' : ''; ?>>First Term</option>
                                <option value="second" <?php echo $selected_term === 'second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="third" <?php echo $selected_term === 'third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_year_name); ?></span> &bull;
                        <span class="font-semibold"><?php echo ucfirst($selected_term); ?> Term</span>
                    </p>
                </div>

                <?php if ($teachers_evaluated > 0): ?>
                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Teachers Evaluated</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $teachers_evaluated; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chalkboard-teacher text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Avg Student Score</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($overall_avg, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Records Graded</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($total_records); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-check text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Assignments Created</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($total_assignments); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tasks text-amber-600 dark:text-amber-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Top Teachers by Student Average</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Highest mean student score (top 10)</p>
                        <div class="relative" style="height: 300px;">
                            <canvas id="teacherChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Student Grade Distribution</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Across all graded records in the term</p>
                        <div class="relative flex items-center justify-center" style="height: 300px;">
                            <canvas id="gradeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Teacher Standings</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo $teachers_evaluated; ?> teacher(s) &bull; <?php echo htmlspecialchars($selected_year_name); ?>
                            &bull; <?php echo ucfirst($selected_term); ?> Term &bull; ranked by student average
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Teacher</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Classes</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subjects</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Students</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Records</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Assignments</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Avg Score</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Pass Rate</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rating</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php
                                $rank = 1;
                                foreach ($teacher_data as $t):
                                    $avg = (float)$t['avg_score'];
                                    $pass_rate = $t['graded_records'] > 0 ? ($t['pass_count'] / $t['graded_records']) * 100 : 0;
                                    list($rating, $rating_class) = performance_rating($avg);
                                    $tid = (int)$t['teacher_id'];
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">#<?php echo $rank; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($t['teacher_name']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($t['email'] ?? ''); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$t['classes_taught']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$t['subjects_taught']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$t['students_assessed']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$t['graded_records']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $assignment_counts[$tid] ?? 0; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white"><?php echo number_format($avg, 1); ?>%</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-semibold text-gray-700 dark:text-gray-300"><?php echo number_format($pass_rate, 1); ?>%</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $rating_class; ?>"><?php echo $rating; ?></span>
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
                        <i class="fas fa-chalkboard-teacher text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Performance Data</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No graded records are linked to teachers for the selected year and term. Try a different term.</p>
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
<?php if ($teachers_evaluated > 0): ?>
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

        <div class="print-title-banner"><h2>Teacher Performance Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Academic Year:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_year_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Term:</span><span class="print-meta-value"><?php echo ucfirst($selected_term); ?> Term</span></div>
            <div class="print-meta-item"><span class="print-meta-label">Teachers:</span><span class="print-meta-value"><?php echo $teachers_evaluated; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Avg Student Score:</span><span class="print-meta-value"><?php echo number_format($overall_avg, 1); ?>%</span></div>
        </div>

        <div class="print-section-title">Teacher Standings (Ranked by Student Average)</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:40px">Rank</th>
                    <th style="text-align:left">Teacher</th>
                    <th>Classes</th>
                    <th>Subjects</th>
                    <th>Students</th>
                    <th>Records</th>
                    <th>Assignments</th>
                    <th>Avg</th>
                    <th>Pass Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $print_rank = 1;
                foreach ($teacher_data as $t):
                    $pass_rate = $t['graded_records'] > 0 ? ($t['pass_count'] / $t['graded_records']) * 100 : 0;
                ?>
                <tr>
                    <td><?php echo $print_rank; ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($t['teacher_name']); ?></td>
                    <td><?php echo (int)$t['classes_taught']; ?></td>
                    <td><?php echo (int)$t['subjects_taught']; ?></td>
                    <td><?php echo (int)$t['students_assessed']; ?></td>
                    <td><?php echo (int)$t['graded_records']; ?></td>
                    <td><?php echo $assignment_counts[(int)$t['teacher_id']] ?? 0; ?></td>
                    <td class="pct-cell"><?php echo number_format((float)$t['avg_score'], 1); ?>%</td>
                    <td><?php echo number_format($pass_rate, 1); ?>%</td>
                </tr>
                <?php $print_rank++; endforeach; ?>
            </tbody>
        </table>

        <div class="print-grading-key">
            <div class="print-grading-key-title">Student Grade Distribution (All Records)</div>
            <table class="grading-key-table">
                <tr><th>Grade</th><th>A (80+)</th><th>B (70-79)</th><th>C (60-69)</th><th>D (50-59)</th><th>F (&lt;50)</th></tr>
                <tr><td class="gk-label">Records</td><td><?php echo $grade_a; ?></td><td><?php echo $grade_b; ?></td><td><?php echo $grade_c; ?></td><td><?php echo $grade_d; ?></td><td><?php echo $grade_f; ?></td></tr>
            </table>
        </div>

        <?php echo signatureRow(['Academic Coordinator', 'Deputy Headmaster/Headmistress', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>Confidential — for internal review only. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
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
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#3730a3,#6d28d9); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #3730a3; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #3730a3; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #eef2ff; border-left: 4px solid #6d28d9; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#3730a3,#6d28d9); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #312e81; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #3730a3; }
    .print-grading-key { margin: 14px 0; }
    .print-grading-key-title { font-size: 9px; font-weight: 700; color: #1e3a5f; text-transform: uppercase; letter-spacing: 0.4px; margin-bottom: 5px; }
    .grading-key-table { width: 100%; border-collapse: collapse; font-size: 9px; }
    .grading-key-table th, .grading-key-table td { padding: 3px 8px; border: 1px solid #e5e7eb; text-align: center; }
    .grading-key-table th { background: #f0f4f8; font-weight: 600; color: #1e3a5f; }
    .gk-label { font-weight: 600; background: #f8fafc; text-align: left !important; color: #374151; }
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
<?php if ($teachers_evaluated > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Top teachers by student average (horizontal bar, top 10)
    const teacherCanvas = document.getElementById('teacherChart');
    if (teacherCanvas) {
        <?php $top10 = array_slice($teacher_data, 0, 10); ?>
        const avgs = <?php echo json_encode(array_map(fn($r) => round((float)$r['avg_score'], 1), $top10)); ?>;
        new Chart(teacherCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['teacher_name'], $top10)); ?>,
                datasets: [{
                    label: 'Avg Student Score',
                    data: avgs,
                    backgroundColor: avgs.map(v => v >= 70 ? 'rgba(34,197,94,0.85)' : v >= 60 ? 'rgba(59,130,246,0.85)' : v >= 50 ? 'rgba(245,158,11,0.85)' : 'rgba(239,68,68,0.85)'),
                    borderRadius: 5, borderWidth: 0
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.x + '%' } } },
                scales: {
                    x: { max: 100, beginAtZero: true, ticks: { color: labelColor, callback: v => v + '%' }, grid: { color: gridColor } },
                    y: { ticks: { color: labelColor, font: { size: 10 } }, grid: { display: false } }
                }
            }
        });
    }

    // Student grade distribution (doughnut)
    const gradeCanvas = document.getElementById('gradeChart');
    if (gradeCanvas) {
        new Chart(gradeCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['A (80+)', 'B (70-79)', 'C (60-69)', 'D (50-59)', 'F (<50)'],
                datasets: [{
                    data: [<?php echo $grade_a; ?>, <?php echo $grade_b; ?>, <?php echo $grade_c; ?>, <?php echo $grade_d; ?>, <?php echo $grade_f; ?>],
                    backgroundColor: ['rgba(34,197,94,0.85)','rgba(59,130,246,0.85)','rgba(20,184,166,0.85)','rgba(245,158,11,0.85)','rgba(239,68,68,0.85)'],
                    borderColor: isDark ? '#1f2937' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, padding: 14, font: { size: 11 } } } }
            }
        });
    }
});
<?php endif; ?>
</script>
