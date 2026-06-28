<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get active academic context
$academic_context = $database->getCurrentAcademicContext();
$selected_year_id = filter_input(INPUT_GET, 'year_id', FILTER_SANITIZE_NUMBER_INT) ?: ($academic_context['year_id'] ?? '');
$selected_term_id = filter_input(INPUT_GET, 'term_id', FILTER_SANITIZE_NUMBER_INT) ?: ($academic_context['term_id'] ?? '');
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$search = filter_input(INPUT_GET, 'search', FILTER_DEFAULT);

// Get academic years
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get terms for selected year
$terms = [];
if ($selected_year_id) {
    $terms_sql = "SELECT * FROM academic_terms WHERE academic_year_id = :year_id ORDER BY term_number";
    $stmt = $db->prepare($terms_sql);
    $stmt->execute([':year_id' => $selected_year_id]);
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get classes
$classes = [];
if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])) {
    $classes_sql = "SELECT * FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $classes = $db->query($classes_sql)->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'teacher') {
    // Only classes taught by teacher
    $classes_sql = "
        SELECT DISTINCT c.* FROM classes c
        JOIN class_teachers ct ON c.id = ct.class_id
        WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
        ORDER BY c.grade_level, c.name
    ";
    $stmt = $db->prepare($classes_sql);
    $stmt->execute([':teacher_id' => $user_id]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Reports matching filters
$reports = [];
$class_stats = ['average' => 0, 'highest' => 0, 'count' => 0];

if ($selected_year_id && $selected_term_id && ($selected_class_id || in_array($user_role, ['student', 'parent']))) {
    
    $query = "
        SELECT 
            tr.id as report_id,
            tr.total_subjects,
            tr.average_score,
            tr.position_in_class,
            tr.class_size,
            tr.overall_grade,
            tr.report_generated_at,
            u.name as student_name,
            sp.student_id as student_code,
            c.name as class_name
        FROM term_reports tr
        JOIN users u ON tr.student_id = u.id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        JOIN classes c ON tr.class_id = c.id
        WHERE tr.academic_year_id = :year_id 
          AND tr.academic_term_id = :term_id
    ";

    $params = [
        ':year_id' => $selected_year_id,
        ':term_id' => $selected_term_id
    ];

    if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        if ($selected_class_id) {
            $query .= " AND tr.class_id = :class_id";
            $params[':class_id'] = $selected_class_id;
        }
        
        // Teachers see only their assigned classes if no class selected
        if ($user_role === 'teacher' && !$selected_class_id) {
            $query .= " AND tr.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :teacher_id)";
            $params[':teacher_id'] = $user_id;
        }
    } elseif ($user_role === 'student') {
        $query .= " AND tr.student_id = :student_id";
        $params[':student_id'] = $user_id;
    } elseif ($user_role === 'parent') {
        $query .= " AND tr.student_id IN (SELECT student_id FROM parent_students WHERE parent_id = :parent_id)";
        $params[':parent_id'] = $user_id;
    }

    if ($search) {
        $query .= " AND (u.name LIKE :search OR sp.student_id LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $query .= " ORDER BY tr.position_in_class ASC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate class stats if class is selected
    if ($selected_class_id && !empty($reports)) {
        $avg_sum = 0;
        $highest = 0;
        foreach ($reports as $rep) {
            $avg_sum += $rep['average_score'];
            if ($rep['average_score'] > $highest) {
                $highest = $rep['average_score'];
            }
        }
        $class_stats['count'] = count($reports);
        $class_stats['average'] = $class_stats['count'] > 0 ? ($avg_sum / $class_stats['count']) : 0;
        $class_stats['highest'] = $highest;
    }
}

$title = "Student Term Reports";
include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-indigo-700 via-purple-700 to-pink-700 rounded-xl p-6 text-white shadow-lg flex flex-col md:flex-row md:items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Student Term Reports</h1>
                            <p class="text-purple-100 text-lg">Search, review, and print compiled terminal performance reports.</p>
                        </div>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <div class="mt-4 md:mt-0 flex gap-3 flex-wrap">
                            <a href="grading_key.php" class="bg-indigo-800 hover:bg-indigo-900 border border-indigo-600 text-white font-semibold px-5 py-2.5 rounded-lg shadow transition flex items-center">
                                <i class="fas fa-key mr-2"></i> Grading Key
                            </a>
                            <a href="generate.php" class="bg-white hover:bg-gray-50 text-indigo-700 font-semibold px-5 py-2.5 rounded-lg shadow transition flex items-center">
                                <i class="fas fa-magic mr-2"></i> Compile Reports
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Term Reports</span>
                </div>

                <!-- Filter Controls -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <form method="GET" action="index.php" class="grid grid-cols-1 md:grid-cols-4 gap-6 items-end">
                        <!-- Academic Year -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                            <select name="year_id" id="year-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Year</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $year['id'] == $selected_year_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Term -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Term</label>
                            <select name="term_id" id="term-select" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Term</option>
                                <?php foreach ($terms as $term): ?>
                                <option value="<?php echo $term['id']; ?>" <?php echo $term['id'] == $selected_term_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term['term_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Class (Only for admin/teacher) -->
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select name="class_id" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">All Assigned Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $selected_class_id ? 'selected' : ''; ?>>
                                    Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                        <div class="hidden"></div>
                        <?php endif; ?>

                        <!-- Submit Filter Button -->
                        <div>
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-6 rounded-lg transition shadow-md flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i> Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Stats summary block -->
                <?php if ($selected_class_id && !empty($reports)): ?>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400 mr-4">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $class_stats['count']; ?></div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Reports Compiled</div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-full flex items-center justify-center text-emerald-600 dark:text-emerald-400 mr-4">
                            <i class="fas fa-chart-line text-xl"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($class_stats['average'], 2); ?>%</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Class Average Score</div>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-5 flex items-center">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/30 rounded-full flex items-center justify-center text-yellow-600 dark:text-yellow-400 mr-4">
                            <i class="fas fa-trophy text-xl"></i>
                        </div>
                        <div>
                            <div class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($class_stats['highest'], 2); ?>%</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wider">Class Highest Score</div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Table Card -->
                <?php if (!empty($reports)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white">Compiled Report Cards</h3>
                        
                        <!-- Search & Bulk Printing Actions -->
                        <div class="flex flex-wrap items-center gap-3">
                            <!-- Search inside class -->
                            <form method="GET" class="relative">
                                <input type="hidden" name="year_id" value="<?php echo $selected_year_id; ?>">
                                <input type="hidden" name="term_id" value="<?php echo $selected_term_id; ?>">
                                <?php if ($selected_class_id): ?>
                                <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                                <?php endif; ?>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search student..." 
                                    class="w-60 pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400 text-sm"></i>
                            </form>

                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                            <!-- Bulk print selection trigger -->
                            <button type="button" id="btn-bulk-print" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2 px-4 rounded-lg text-sm transition flex items-center shadow shadow-indigo-600/30">
                                <i class="fas fa-print mr-2"></i> Print Selected
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Table -->
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <th class="w-12 px-6 py-3 text-left">
                                        <input type="checkbox" id="select-all-reports" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    </th>
                                    <?php endif; ?>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Position</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Average</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Generated At</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                <?php foreach ($reports as $row): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <input type="checkbox" class="report-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500 h-4 w-4 cursor-pointer" value="<?php echo $row['report_id']; ?>">
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo $row['position_in_class']; ?> <span class="text-xs text-gray-400">/ <?php echo $row['class_size']; ?></span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-9 h-9 bg-purple-100 dark:bg-purple-900/30 rounded-full flex items-center justify-center text-purple-600 dark:text-purple-400 font-bold text-sm">
                                                <?php echo htmlspecialchars(substr($row['student_name'], 0, 1)); ?>
                                            </div>
                                            <div class="ml-3">
                                                <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($row['student_name']); ?></div>
                                                <div class="text-2xs text-gray-500 dark:text-gray-400 font-mono"><?php echo htmlspecialchars($row['student_code'] ?? 'NO_ID'); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($row['class_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">
                                        <?php echo number_format($row['average_score'], 1); ?>%
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php
                                            $grade = $row['overall_grade'];
                                            $colorClass = 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
                                            if (strpos($grade, 'A') === 0) $colorClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                                            elseif (strpos($grade, 'B') === 0) $colorClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                                            elseif (strpos($grade, 'C') === 0) $colorClass = 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300';
                                            elseif (strpos($grade, 'D') === 0 || strpos($grade, 'E') === 0) $colorClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                                            elseif (strpos($grade, 'F') === 0) $colorClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
                                        ?>
                                        <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold <?php echo $colorClass; ?>">
                                            <?php echo htmlspecialchars($grade); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-xs text-gray-500 dark:text-gray-400">
                                        <?php echo date('M d, Y h:i A', strtotime($row['report_generated_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                        <a href="view.php?id=<?php echo $row['report_id']; ?>" class="text-blue-600 hover:text-blue-950 dark:text-blue-400 dark:hover:text-blue-200 transition">
                                            <i class="fas fa-eye" title="View details"></i> View
                                        </a>
                                        <a href="print.php?id=<?php echo $row['report_id']; ?>" target="_blank" class="text-emerald-600 hover:text-emerald-950 dark:text-emerald-400 dark:hover:text-emerald-200 transition">
                                            <i class="fas fa-print" title="Print card"></i> Print
                                        </a>
                                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                        <a href="edit_report.php?id=<?php echo $row['report_id']; ?>" class="text-indigo-600 hover:text-indigo-950 dark:text-indigo-400 dark:hover:text-indigo-200 transition">
                                            <i class="fas fa-edit" title="Edit details"></i> Edit
                                        </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php elseif ($selected_year_id && $selected_term_id): ?>
                <!-- Context is selected, but zero reports compiled yet -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <div class="w-16 h-16 bg-yellow-50 dark:bg-yellow-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clipboard-list text-2xl text-yellow-500"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">No Reports Compiled</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">
                        No report cards have been compiled for this term or class yet.
                    </p>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <a href="generate.php?year_id=<?php echo $selected_year_id; ?>&term_id=<?php echo $selected_term_id; ?>&class_id=<?php echo $selected_class_id; ?>" 
                       class="inline-flex bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2.5 px-6 rounded-lg transition shadow-md">
                        <i class="fas fa-magic mr-2 mt-0.5"></i> Compile Class Reports
                    </a>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <!-- Request user to select filter context -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <div class="w-16 h-16 bg-blue-50 dark:bg-blue-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-filter text-2xl text-blue-500"></i>
                    </div>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Select Filters</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">
                        Please choose an Academic Year and Term from the dropdown filter above to list the compiled student reports.
                    </p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const yearSelect = document.getElementById('year-select');
    const termSelect = document.getElementById('term-select');
    
    // Step 1: Academic Year Change -> Fetch Terms via AJAX
    yearSelect.addEventListener('change', function() {
        const yearId = this.value;
        termSelect.disabled = true;
        termSelect.innerHTML = '<option value="">Loading...</option>';

        if (!yearId) {
            termSelect.innerHTML = '<option value="">Select Term</option>';
            return;
        }

        fetch(`../../api/reports/get_terms.php?year_id=${yearId}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    let html = '<option value="">Select Term</option>';
                    data.terms.forEach(term => {
                        html += `<option value="${term.id}">${term.term_name}</option>`;
                    });
                    termSelect.innerHTML = html;
                    termSelect.disabled = false;
                } else {
                    termSelect.innerHTML = '<option value="">Error</option>';
                }
            })
            .catch(err => {
                termSelect.innerHTML = '<option value="">Error</option>';
            });
    });

    // Checkbox selectors
    const selectAllCheckbox = document.getElementById('select-all-reports');
    const reportCheckboxes = document.querySelectorAll('.report-checkbox');
    const btnBulkPrint = document.getElementById('btn-bulk-print');

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            reportCheckboxes.forEach(cb => {
                cb.checked = this.checked;
            });
        });
    }

    if (btnBulkPrint) {
        btnBulkPrint.addEventListener('click', function() {
            const checked = document.querySelectorAll('.report-checkbox:checked');
            if (checked.length === 0) {
                alert('Please select at least one report to print.');
                return;
            }

            const ids = Array.from(checked).map(cb => cb.value).join(',');
            window.open(`print.php?ids=${ids}`, '_blank');
        });
    }
});
</script>
