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

// School settings for the printable transcript
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();
$school_motto = '';
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    $m = $motto_stmt->fetch(PDO::FETCH_ASSOC);
    if ($m) { $school_motto = $m['setting_value']; }
} catch (PDOException $e) { /* optional */ }

$is_teacher = $_SESSION['role'] === 'teacher';
$teacher_id = (int)$_SESSION['user_id'];

// ---- Filters ----
$selected_class   = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : '';
$selected_student = isset($_GET['student_id']) && $_GET['student_id'] !== '' ? (int)$_GET['student_id'] : '';

// Performance tier (A–F) derived from the numeric score. Used for badge
// colours, the distribution summary and the percentage banding key — kept
// independent of how individual grades are displayed so those remain coherent
// whatever grading system the school has chosen.
function transcript_tier($total)
{
    $t = (float)$total;
    if ($t >= 80) return 'A';
    if ($t >= 70) return 'B';
    if ($t >= 60) return 'C';
    if ($t >= 50) return 'D';
    return 'F';
}
// Grade shown to the reader, in the school's configured grading style
// (percentage / letter / GPA / points) via the central helper.
function transcript_grade($total, $stored = null)
{
    return formatGrade($total);
}
function grade_badge_class($grade)
{
    switch (strtoupper($grade)) {
        case 'A': return 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300';
        case 'B': return 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300';
        case 'C': return 'text-teal-800 bg-teal-100 dark:bg-teal-900/40 dark:text-teal-300';
        case 'D': return 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300';
        default:  return 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300';
    }
}

// Teacher scoping: a teacher can only access students in the classes they teach.
$teacher_record_scope = '';
$teacher_param = [];
if ($is_teacher) {
    $teacher_record_scope = " AND sar.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id) ";
    $teacher_param[':teacher_id'] = $teacher_id;
}

// Classes for the filter dropdown (scoped for teachers)
$classes_sql = "SELECT c.id, c.name FROM classes c WHERE c.status = 'active'";
if ($is_teacher) {
    $classes_sql .= " AND c.id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id)";
}
$classes_sql .= " ORDER BY c.name";
$classes_stmt = $db->prepare($classes_sql);
$classes_stmt->execute($teacher_param);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Students that have academic records (optionally filtered by class), scoped for teachers
$students_sql = "
    SELECT DISTINCT u.id, u.name, sp.student_id AS roll_number
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE u.role = 'student' $teacher_record_scope
";
$students_params = $teacher_param;
if ($selected_class !== '') {
    $students_sql .= " AND sar.class_id = :class_id ";
    $students_params[':class_id'] = $selected_class;
}
$students_sql .= " ORDER BY u.name";
$students_stmt = $db->prepare($students_sql);
$students_stmt->execute($students_params);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

$student = null;
$transcript = [];      // [year_name][term_label] => ['subjects' => [...], 'sum' => float, 'count' => int]
$flat_records = [];    // for CSV export
$overall_sum = 0.0;
$overall_count = 0;
$grade_dist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
$access_denied = false;

if ($selected_student !== '') {
    // Verify teacher access to this student
    if ($is_teacher) {
        $chk = $db->prepare("SELECT COUNT(*) FROM student_academic_records sar WHERE sar.student_id = :sid AND sar.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :tid)");
        $chk->execute([':sid' => $selected_student, ':tid' => $teacher_id]);
        if ($chk->fetchColumn() == 0) { $access_denied = true; }
    }

    if (!$access_denied) {
        // Student profile
        $stu_stmt = $db->prepare("
            SELECT u.name, u.email, sp.student_id AS roll_number, sp.date_of_birth, sp.gender, sp.admission_date,
                   c.name AS class_name, c.grade_level
            FROM users u
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
            LEFT JOIN classes c ON sc.class_id = c.id
            WHERE u.id = :sid AND u.role = 'student'
            LIMIT 1
        ");
        $stu_stmt->execute([':sid' => $selected_student]);
        $student = $stu_stmt->fetch(PDO::FETCH_ASSOC);

        if ($student) {
            // All academic records for this student, ordered chronologically
            $rec_stmt = $db->prepare("
                SELECT ay.year_name, at.term_name, at.term_number,
                       s.name AS subject_name, s.code AS subject_code,
                       sar.continuous_assessment, sar.mid_term_exam, sar.final_exam,
                       sar.total_score, sar.grade, sar.remarks,
                       c.name AS class_name
                FROM student_academic_records sar
                JOIN subjects s ON sar.subject_id = s.id
                JOIN academic_years ay ON sar.academic_year_id = ay.id
                JOIN academic_terms at ON sar.academic_term_id = at.id
                LEFT JOIN classes c ON sar.class_id = c.id
                WHERE sar.student_id = :sid
                ORDER BY ay.year_name ASC, at.term_number ASC, s.name ASC
            ");
            $rec_stmt->execute([':sid' => $selected_student]);
            $records = $rec_stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as $r) {
                $year = $r['year_name'];
                $term = $r['term_name'];
                if (!isset($transcript[$year])) { $transcript[$year] = []; }
                if (!isset($transcript[$year][$term])) {
                    $transcript[$year][$term] = ['subjects' => [], 'sum' => 0.0, 'count' => 0, 'class_name' => $r['class_name']];
                }
                $grade = transcript_grade($r['total_score'], $r['grade']);
                $tier = transcript_tier($r['total_score']);
                $r['computed_grade'] = $grade;
                $r['computed_tier'] = $tier;
                $transcript[$year][$term]['subjects'][] = $r;
                $transcript[$year][$term]['sum'] += (float)$r['total_score'];
                $transcript[$year][$term]['count']++;

                $overall_sum += (float)$r['total_score'];
                $overall_count++;
                if (isset($grade_dist[$tier])) { $grade_dist[$tier]++; }

                $flat_records[] = [
                    'year' => $year, 'term' => $term, 'class' => $r['class_name'],
                    'subject' => $r['subject_name'], 'ca' => $r['continuous_assessment'],
                    'mid' => $r['mid_term_exam'], 'final' => $r['final_exam'],
                    'total' => $r['total_score'], 'grade' => $grade, 'remarks' => $r['remarks'],
                ];
            }
        }
    }
}

$overall_average = $overall_count > 0 ? $overall_sum / $overall_count : 0;
$overall_grade = $overall_count > 0 ? transcript_grade($overall_average, null) : '-';
$overall_tier  = $overall_count > 0 ? transcript_tier($overall_average) : '-';

// ---- CSV export (must run before any HTML output) ----
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $student && !empty($flat_records)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="transcript_' . preg_replace('/[^A-Za-z0-9]+/', '_', $student['name']) . '_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Academic Transcript']);
    fputcsv($out, ['Student', $student['name']]);
    fputcsv($out, ['Student ID', $student['roll_number'] ?? 'N/A']);
    fputcsv($out, ['Current Class', $student['class_name'] ? ('Grade ' . $student['grade_level'] . ' - ' . $student['class_name']) : 'N/A']);
    fputcsv($out, ['Cumulative Average', number_format($overall_average, 2) . '%']);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Academic Year', 'Term', 'Class', 'Subject', 'CA', 'Mid-Term', 'Final', 'Total', 'Grade', 'Remarks']);
    foreach ($flat_records as $row) {
        fputcsv($out, [
            $row['year'], $row['term'], $row['class'], $row['subject'],
            $row['ca'], $row['mid'], $row['final'], $row['total'], $row['grade'], $row['remarks'],
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'class_id' => $selected_class,
    'student_id' => $selected_student,
    'export' => 'csv',
]);

$title = "Student Transcripts";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Student Transcripts']
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Student Transcripts</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Generate a complete academic history for any student across years and terms.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <?php if ($student && !empty($flat_records)): ?>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                            <i class="fas fa-print mr-2"></i>Print Transcript
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Select Student</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class (Optional)</label>
                            <select name="class_id" id="classSelect" onchange="this.form.submit()" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Student</label>
                            <select name="student_id" required class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">Select a student</option>
                                <?php foreach ($students as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo $selected_student === (int)$s['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($s['name']); ?><?php echo $s['roll_number'] ? ' (' . htmlspecialchars($s['roll_number']) . ')' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-scroll mr-2"></i>Generate Transcript
                            </button>
                        </div>
                    </form>
                    <?php if (empty($students)): ?>
                    <p class="text-xs text-amber-500 mt-3"><i class="fas fa-info-circle mr-1"></i>No students with academic records found<?php echo $selected_class ? ' for the selected class' : ''; ?>.</p>
                    <?php endif; ?>
                </div>

                <?php if ($access_denied): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-red-50 dark:bg-red-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-lock text-red-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Access Restricted</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">You can only generate transcripts for students in the classes you teach.</p>
                </div>
                <?php elseif ($student && $overall_count > 0): ?>

                <!-- Student Overview -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <div class="flex flex-col md:flex-row md:items-center gap-4 justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-16 h-16 rounded-2xl flex items-center justify-center text-white text-2xl font-bold flex-shrink-0" style="background-image: linear-gradient(135deg, #4f46e5, #7c3aed);">
                                <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                            </div>
                            <div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['name']); ?></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></p>
                                <?php if ($student['class_name']): ?>
                                <span class="inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                    Grade <?php echo htmlspecialchars($student['grade_level']); ?> - <?php echo htmlspecialchars($student['class_name']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex gap-4">
                            <div class="text-center px-4">
                                <p class="text-3xl font-extrabold text-indigo-600 dark:text-indigo-400"><?php echo number_format($overall_average, 1); ?>%</p>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Cumulative</p>
                            </div>
                            <div class="text-center px-4 border-l border-gray-200 dark:border-gray-700">
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?php echo $overall_count; ?></p>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide">Records</p>
                            </div>
                            <div class="text-center px-4 border-l border-gray-200 dark:border-gray-700">
                                <span class="inline-flex items-center justify-center w-12 h-12 rounded-full text-xl font-extrabold <?php echo grade_badge_class($overall_tier); ?>"><?php echo htmlspecialchars($overall_grade); ?></span>
                                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wide mt-1">Grade</p>
                            </div>
                        </div>
                    </div>

                    <!-- Grade distribution chips -->
                    <div class="flex flex-wrap gap-2 mt-5 pt-4 border-t border-gray-100 dark:border-gray-700">
                        <?php foreach ($grade_dist as $g => $count): ?>
                        <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold <?php echo grade_badge_class($g); ?>">
                            <?php echo $g; ?> <span class="opacity-70">&times; <?php echo $count; ?></span>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Transcript by Year / Term -->
                <?php foreach ($transcript as $year => $terms): ?>
                <div class="mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                        <i class="fas fa-calendar-alt text-indigo-500"></i> Academic Year <?php echo htmlspecialchars($year); ?>
                    </h2>
                    <?php foreach ($terms as $term => $data): ?>
                    <?php $term_avg = $data['count'] > 0 ? $data['sum'] / $data['count'] : 0; ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-4">
                        <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700 flex items-center justify-between">
                            <div>
                                <h3 class="text-base font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($term); ?></h3>
                                <?php if (!empty($data['class_name'])): ?>
                                <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($data['class_name']); ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="text-right">
                                <span class="text-sm text-gray-500 dark:text-gray-400">Term Average:</span>
                                <span class="text-lg font-bold text-indigo-600 dark:text-indigo-400 ml-1"><?php echo number_format($term_avg, 1); ?>%</span>
                            </div>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                                <thead class="bg-gray-50 dark:bg-gray-750">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">CA</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Mid-Term</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Final</th>
                                        <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                        <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($data['subjects'] as $subj): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($subj['subject_name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $subj['continuous_assessment'] !== null ? number_format($subj['continuous_assessment'], 1) : '-'; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $subj['mid_term_exam'] !== null ? number_format($subj['mid_term_exam'], 1) : '-'; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo $subj['final_exam'] !== null ? number_format($subj['final_exam'], 1) : '-'; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white"><?php echo number_format((float)$subj['total_score'], 1); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                            <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo grade_badge_class($subj['computed_tier']); ?>"><?php echo htmlspecialchars($subj['computed_grade']); ?></span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400"><?php echo $subj['remarks'] ? htmlspecialchars($subj['remarks']) : '-'; ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

                <?php elseif ($selected_student !== '' && $student && $overall_count === 0): ?>
                <!-- Student selected but no records -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Academic Records</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No graded records exist for <?php echo htmlspecialchars($student['name']); ?> yet.</p>
                </div>
                <?php else: ?>
                <!-- Instruction -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-indigo-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-scroll text-indigo-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Generate a Transcript</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">Select a student above to view and print their complete academic transcript across all years and terms.</p>
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
<!-- PRINT TRANSCRIPT TEMPLATE                                    -->
<!-- ============================================================ -->
<?php if ($student && $overall_count > 0): ?>
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
                    <?php if ($school_motto): ?><p class="print-motto">"<?php echo htmlspecialchars($school_motto); ?>"</p><?php endif; ?>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <div class="print-title-banner"><h2>Official Academic Transcript</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Student:</span><span class="print-meta-value"><?php echo htmlspecialchars($student['name']); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Student ID:</span><span class="print-meta-value"><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Current Class:</span><span class="print-meta-value"><?php echo $student['class_name'] ? htmlspecialchars('Grade ' . $student['grade_level'] . ' - ' . $student['class_name']) : 'N/A'; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Cumulative Avg:</span><span class="print-meta-value"><?php echo number_format($overall_average, 2); ?>% (<?php echo $overall_grade; ?>)</span></div>
        </div>

        <?php foreach ($transcript as $year => $terms): ?>
        <?php foreach ($terms as $term => $data): ?>
        <?php $term_avg = $data['count'] > 0 ? $data['sum'] / $data['count'] : 0; ?>
        <div class="print-section-title"><?php echo htmlspecialchars($year . ' — ' . $term); ?> &nbsp;|&nbsp; Term Average: <?php echo number_format($term_avg, 1); ?>%</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="text-align:left">Subject</th>
                    <th>CA</th>
                    <th>Mid-Term</th>
                    <th>Final</th>
                    <th>Total</th>
                    <th>Grade</th>
                    <th style="text-align:left">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data['subjects'] as $subj): ?>
                <tr>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($subj['subject_name']); ?></td>
                    <td><?php echo $subj['continuous_assessment'] !== null ? number_format($subj['continuous_assessment'], 1) : '-'; ?></td>
                    <td><?php echo $subj['mid_term_exam'] !== null ? number_format($subj['mid_term_exam'], 1) : '-'; ?></td>
                    <td><?php echo $subj['final_exam'] !== null ? number_format($subj['final_exam'], 1) : '-'; ?></td>
                    <td class="pct-cell"><?php echo number_format((float)$subj['total_score'], 1); ?></td>
                    <td><span class="print-grade-badge grade-<?php echo strtolower($subj['computed_tier']); ?>"><?php echo htmlspecialchars($subj['computed_grade']); ?></span></td>
                    <td style="text-align:left"><?php echo $subj['remarks'] ? htmlspecialchars($subj['remarks']) : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="print-grading-key">
            <div class="print-grading-key-title">Grading Key</div>
            <table class="grading-key-table">
                <tr><th>Band</th><th>A</th><th>B</th><th>C</th><th>D</th><th>F</th></tr>
                <tr><td class="gk-label">Range</td><td>80–100%</td><td>70–79%</td><td>60–69%</td><td>50–59%</td><td>0–49%</td></tr>
                <tr><td class="gk-label">Interpretation</td><td>Excellent</td><td>Very Good</td><td>Good</td><td>Pass</td><td>Fail</td></tr>
            </table>
        </div>

        <?php echo signatureRow(['Class Teacher', 'Registrar', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>This is an official computer-generated transcript. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
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
    .print-motto { font-size: 10px; font-style: italic; color: #2563eb; font-weight: 500; margin: 0 0 3px 0; }
    .print-contact-line { font-size: 9px; color: #6b7280; margin: 0; }
    .print-header-divider { height: 3px; background: linear-gradient(to right,#1e3a5f,#2563eb,#7c3aed); border-radius: 3px; margin-bottom: 12px; }
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#3730a3,#6d28d9); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #1e3a5f; display: block; }
    .print-section-title { font-size: 10.5px; font-weight: 700; color: #3730a3; text-transform: uppercase; letter-spacing: 0.5px; padding: 5px 10px; background: #eef2ff; border-left: 4px solid #6d28d9; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#3730a3,#6d28d9); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #312e81; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .pct-cell { font-weight: 700; color: #3730a3; }
    .print-grade-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; font-weight: 700; font-size: 9px; }
    .grade-a { background: #d1fae5; color: #065f46; }
    .grade-b { background: #dbeafe; color: #1e40af; }
    .grade-c { background: #ccfbf1; color: #115e59; }
    .grade-d { background: #fef3c7; color: #92400e; }
    .grade-f { background: #fecaca; color: #991b1b; }
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
