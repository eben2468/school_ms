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

// ---- Filters ----
$term_map = ['first' => '1', 'second' => '2', 'third' => '3'];
$selected_term = isset($_GET['term']) && isset($term_map[$_GET['term']]) ? $_GET['term'] : 'third';
$term_number = $term_map[$selected_term];

// Academic years
$years = $db->query("SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$default_year_id = null;
foreach ($years as $y) { if ($y['status'] === 'active') { $default_year_id = (int)$y['id']; break; } }
if ($default_year_id === null && !empty($years)) { $default_year_id = (int)$years[0]['id']; }
$selected_year = isset($_GET['year_id']) && $_GET['year_id'] !== '' ? (int)$_GET['year_id'] : $default_year_id;

$selected_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : '';

// Classes for the dropdown (scoped for teachers)
$classes_sql = "SELECT c.id, c.name FROM classes c WHERE c.status = 'active'";
$class_params = [];
if ($is_teacher) {
    $classes_sql .= " AND c.id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id)";
    $class_params[':teacher_id'] = $teacher_id;
}
$classes_sql .= " ORDER BY c.name";
$classes_stmt = $db->prepare($classes_sql);
$classes_stmt->execute($class_params);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Shared WHERE + params (records joined to academic_terms for term filtering)
$where = " WHERE at.term_number = :term_number AND sar.academic_year_id = :year_id ";
$params = [':term_number' => $term_number, ':year_id' => $selected_year];
if ($selected_class !== '') {
    $where .= " AND sar.class_id = :class_id ";
    $params[':class_id'] = $selected_class;
}
if ($is_teacher) {
    // A teacher only sees subjects they teach in their classes (precise class+subject match)
    $where .= " AND EXISTS (SELECT 1 FROM class_teachers ct WHERE ct.teacher_id = :teacher_id
                            AND ct.class_id = sar.class_id AND ct.subject_id = sar.subject_id) ";
    $params[':teacher_id'] = $teacher_id;
}

// ---- Per-subject aggregation ----
$subject_query = "
    SELECT s.id, s.name AS subject_name, s.code AS subject_code,
        COUNT(sar.id) AS total_records,
        COUNT(DISTINCT sar.student_id) AS total_students,
        AVG(sar.total_score) AS avg_score,
        MAX(sar.total_score) AS max_score,
        MIN(sar.total_score) AS min_score,
        SUM(CASE WHEN sar.total_score >= 50 THEN 1 ELSE 0 END) AS pass_count
    FROM student_academic_records sar
    JOIN subjects s ON sar.subject_id = s.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    $where
    GROUP BY s.id, s.name, s.code
    HAVING total_records > 0
    ORDER BY avg_score DESC
";
$subject_stmt = $db->prepare($subject_query);
$subject_stmt->execute($params);
$subject_data = $subject_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Overall summary + grade distribution ----
$dist_query = "
    SELECT
        COUNT(sar.id) AS total_records,
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
";
$dist_stmt = $db->prepare($dist_query);
$dist_stmt->execute($params);
$dist = $dist_stmt->fetch(PDO::FETCH_ASSOC);

$total_records = (int)($dist['total_records'] ?? 0);
$overall_avg   = (float)($dist['overall_avg'] ?? 0);
$pass_count    = (int)($dist['pass_count'] ?? 0);
$overall_pass_rate = $total_records > 0 ? ($pass_count / $total_records) * 100 : 0;
$grade_a = (int)($dist['grade_a'] ?? 0);
$grade_b = (int)($dist['grade_b'] ?? 0);
$grade_c = (int)($dist['grade_c'] ?? 0);
$grade_d = (int)($dist['grade_d'] ?? 0);
$grade_f = (int)($dist['grade_f'] ?? 0);

$subjects_evaluated = count($subject_data);
$best_subject = $subjects_evaluated > 0 ? $subject_data[0] : null;
$weakest_subject = $subjects_evaluated > 0 ? $subject_data[$subjects_evaluated - 1] : null;

// Helpers
function performance_rating($avg)
{
    if ($avg >= 70) return ['Excellent', 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300'];
    if ($avg >= 60) return ['Good', 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'];
    if ($avg >= 50) return ['Average', 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'];
    return ['Needs Support', 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300'];
}

// Selected names
$selected_class_name = 'All Classes';
foreach ($classes as $c) { if ($c['id'] == $selected_class) { $selected_class_name = $c['name']; break; } }
$selected_year_name = '';
foreach ($years as $y) { if ((int)$y['id'] === $selected_year) { $selected_year_name = $y['year_name']; break; } }

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="subject_performance_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Subject Performance Report']);
    fputcsv($out, ['Academic Year', $selected_year_name]);
    fputcsv($out, ['Term', ucfirst($selected_term) . ' Term']);
    fputcsv($out, ['Class', $selected_class_name]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Rank', 'Subject', 'Code', 'Students', 'Records', 'Average', 'Highest', 'Lowest', 'Pass Rate (%)']);
    $rank = 1;
    foreach ($subject_data as $row) {
        $pass_rate = $row['total_records'] > 0 ? round(($row['pass_count'] / $row['total_records']) * 100, 1) : 0;
        fputcsv($out, [
            $rank++, $row['subject_name'], $row['subject_code'],
            $row['total_students'], $row['total_records'],
            number_format($row['avg_score'], 1), number_format($row['max_score'], 1),
            number_format($row['min_score'], 1), $pass_rate,
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'year_id' => $selected_year,
    'term' => $selected_term,
    'class_id' => $selected_class,
    'export' => 'csv',
]);

$title = "Subject Performance Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Subject Performance Report']
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Subject Performance Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Compare average scores, pass rates, and grade spread across subjects.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($subject_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($subject_data) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
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

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class (Optional)</label>
                            <select name="class_id" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
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
                        <span class="font-semibold"><?php echo ucfirst($selected_term); ?> Term</span> &bull;
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_class_name); ?></span>
                    </p>
                </div>

                <?php if ($subjects_evaluated > 0): ?>
                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Subjects Evaluated</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $subjects_evaluated; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Overall Average</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($overall_avg, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Pass Rate (&ge;50%)</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($overall_pass_rate, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Top / Lowest Subject</p>
                        <h3 class="text-base font-bold text-green-600 dark:text-green-400 mt-1 truncate" title="<?php echo htmlspecialchars($best_subject['subject_name']); ?>">
                            <i class="fas fa-arrow-up mr-1"></i><?php echo htmlspecialchars($best_subject['subject_name']); ?> (<?php echo number_format($best_subject['avg_score'], 1); ?>%)
                        </h3>
                        <h3 class="text-base font-bold text-red-600 dark:text-red-400 mt-1 truncate" title="<?php echo htmlspecialchars($weakest_subject['subject_name']); ?>">
                            <i class="fas fa-arrow-down mr-1"></i><?php echo htmlspecialchars($weakest_subject['subject_name']); ?> (<?php echo number_format($weakest_subject['avg_score'], 1); ?>%)
                        </h3>
                    </div>
                </div>

                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Average Score by Subject</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Mean total score for each subject</p>
                        <div class="relative" style="height: <?php echo max(260, $subjects_evaluated * 28); ?>px;">
                            <canvas id="subjectChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Grade Distribution</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">All graded records in the selected scope</p>
                        <div class="relative flex items-center justify-center" style="height: 260px;">
                            <canvas id="gradeChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Results Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Subject Standings</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo $subjects_evaluated; ?> subject(s) &bull; <?php echo htmlspecialchars($selected_year_name); ?>
                            &bull; <?php echo ucfirst($selected_term); ?> Term &bull; <?php echo htmlspecialchars($selected_class_name); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Students</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Records</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Average</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Highest</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Lowest</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Pass Rate</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rating</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php
                                $rank = 1;
                                foreach ($subject_data as $subj):
                                    $avg = (float)$subj['avg_score'];
                                    $pass_rate = $subj['total_records'] > 0 ? ($subj['pass_count'] / $subj['total_records']) * 100 : 0;
                                    list($rating, $rating_class) = performance_rating($avg);
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">#<?php echo $rank; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($subj['subject_name']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($subj['subject_code']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$subj['total_students']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo (int)$subj['total_records']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white"><?php echo number_format($avg, 1); ?>%</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-green-600 dark:text-green-400 font-medium"><?php echo number_format((float)$subj['max_score'], 1); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-red-600 dark:text-red-400 font-medium"><?php echo number_format((float)$subj['min_score'], 1); ?></td>
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
                        <i class="fas fa-chart-line text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Performance Records</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No graded records exist for the selected year, term, and class. Try a different term or clear the class filter.</p>
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
<?php if ($subjects_evaluated > 0): ?>
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

        <div class="print-title-banner"><h2>Subject Performance Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Academic Year:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_year_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Term:</span><span class="print-meta-value"><?php echo ucfirst($selected_term); ?> Term</span></div>
            <div class="print-meta-item"><span class="print-meta-label">Class:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_class_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Overall Average:</span><span class="print-meta-value"><?php echo number_format($overall_avg, 1); ?>%</span></div>
        </div>

        <div class="print-section-title">Subject Standings</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:40px">Rank</th>
                    <th style="text-align:left">Subject</th>
                    <th>Students</th>
                    <th>Records</th>
                    <th>Average</th>
                    <th>Highest</th>
                    <th>Lowest</th>
                    <th>Pass Rate</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $print_rank = 1;
                foreach ($subject_data as $subj):
                    $pass_rate = $subj['total_records'] > 0 ? ($subj['pass_count'] / $subj['total_records']) * 100 : 0;
                ?>
                <tr>
                    <td><?php echo $print_rank; ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($subj['subject_name']); ?></td>
                    <td><?php echo (int)$subj['total_students']; ?></td>
                    <td><?php echo (int)$subj['total_records']; ?></td>
                    <td class="pct-cell"><?php echo number_format((float)$subj['avg_score'], 1); ?>%</td>
                    <td><?php echo number_format((float)$subj['max_score'], 1); ?></td>
                    <td><?php echo number_format((float)$subj['min_score'], 1); ?></td>
                    <td><?php echo number_format($pass_rate, 1); ?>%</td>
                </tr>
                <?php $print_rank++; endforeach; ?>
            </tbody>
        </table>

        <div class="print-grading-key">
            <div class="print-grading-key-title">Grade Distribution (All Records)</div>
            <table class="grading-key-table">
                <tr><th>Grade</th><th>A (80+)</th><th>B (70-79)</th><th>C (60-69)</th><th>D (50-59)</th><th>F (&lt;50)</th></tr>
                <tr><td class="gk-label">Records</td><td><?php echo $grade_a; ?></td><td><?php echo $grade_b; ?></td><td><?php echo $grade_c; ?></td><td><?php echo $grade_d; ?></td><td><?php echo $grade_f; ?></td></tr>
            </table>
        </div>

        <?php echo signatureRow(['Academic Coordinator', 'Head of Department', 'Headmaster/Headmistress']); ?>

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
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#1e3a5f,#2d5a8e); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #1e3a5f; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #1e3a5f; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #1e3a5f; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 12px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#1e3a5f,#2d5a8e); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #1a3455; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #1e3a5f; }
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
<?php if ($subjects_evaluated > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Average score by subject (horizontal bar)
    const subjectCanvas = document.getElementById('subjectChart');
    if (subjectCanvas) {
        const avgs = <?php echo json_encode(array_map(fn($r) => round((float)$r['avg_score'], 1), $subject_data)); ?>;
        new Chart(subjectCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['subject_name'], $subject_data)); ?>,
                datasets: [{
                    label: 'Average',
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

    // Grade distribution (doughnut)
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
