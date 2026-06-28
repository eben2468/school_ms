<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../auth/login.php");
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
$selected_class = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$selected_subject = isset($_GET['subject_id']) ? $_GET['subject_id'] : '';
$selected_term = isset($_GET['term']) ? $_GET['term'] : 'third'; // Default to third since that has most data

// Get classes
$classes_query = "SELECT * FROM classes WHERE status = 'active' ORDER BY name";
$classes_stmt = $db->prepare($classes_query);
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subjects
$subjects_query = "SELECT * FROM subjects ORDER BY name";
$subjects_stmt = $db->prepare($subjects_query);
$subjects_stmt->execute();
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group subjects by class so the Subject filter can show only subjects taught in a class
$subjects_by_class = [];
foreach ($subjects as $s) {
    if (!empty($s['class_id'])) {
        $subjects_by_class[$s['class_id']][] = ['id' => $s['id'], 'name' => $s['name']];
    }
}

// Subjects to render in the dropdown: scoped to the selected class (if any)
$subject_options = ($selected_class && isset($subjects_by_class[$selected_class]))
    ? $subjects_by_class[$selected_class]
    : ($selected_class ? [] : $subjects);

// Term mapping helper
$term_map = [
    'first' => '1',
    'second' => '2',
    'third' => '3'
];
$term_number = $term_map[$selected_term] ?? '3';

// Get academic performance data from student_academic_records (source-of-truth grade table)
$performance_data = [];
if ($selected_class) {
    $performance_query = "
        SELECT 
            u.id as student_id,
            u.name as student_name,
            sp.student_id as roll_number,
            AVG(sar.total_score) as average_marks,
            COUNT(sar.id) as total_exams,
            AVG(sar.total_score) as percentage
        FROM users u
        JOIN student_classes sc ON u.id = sc.student_id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        JOIN student_academic_records sar ON u.id = sar.student_id AND sar.class_id = :class_id
        JOIN academic_terms at ON sar.academic_term_id = at.id
        WHERE sc.class_id = :class_id 
        AND sc.status = 'active' 
        AND u.role = 'student'
        AND at.term_number = :term_number
    ";
    
    $params = [
        ':class_id' => $selected_class,
        ':term_number' => $term_number
    ];
    
    if ($selected_subject) {
        $performance_query .= " AND sar.subject_id = :subject_id";
        $params[':subject_id'] = $selected_subject;
    }
    
    $performance_query .= "
        GROUP BY u.id, u.name, sp.student_id
        HAVING total_exams > 0
        ORDER BY percentage DESC
    ";
    
    $performance_stmt = $db->prepare($performance_query);
    $performance_stmt->execute($params);
    $performance_data = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Academic Progress Report";
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Academic Progress Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review student grade rankings, performance averages, and class rankings.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <button onclick="exportReport()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
    <i class="fas fa-print mr-2"></i>Print Report
</button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select name="class_id" id="classSelect" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition" required>
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Subject (Optional)</label>
                            <select name="subject_id" id="subjectSelect" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Subjects</option>
                                <?php foreach ($subject_options as $subject): ?>
                                <option value="<?php echo $subject['id']; ?>" <?php echo $selected_subject == $subject['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($subject['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                            <select name="term" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="first" <?php echo $selected_term == 'first' ? 'selected' : ''; ?>>First Term</option>
                                <option value="second" <?php echo $selected_term == 'second' ? 'selected' : ''; ?>>Second Term</option>
                                <option value="third" <?php echo $selected_term == 'third' ? 'selected' : ''; ?>>Third Term</option>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate Report
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Dynamically scope the Subject filter to the subjects taught in the selected class -->
                <script>
                    (function() {
                        const subjectsByClass = <?php echo json_encode($subjects_by_class, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                        const classSelect = document.getElementById('classSelect');
                        const subjectSelect = document.getElementById('subjectSelect');
                        if (!classSelect || !subjectSelect) return;

                        function populateSubjects(preserve) {
                            const classId = classSelect.value;
                            const current = preserve ? subjectSelect.value : '';
                            const list = (classId && subjectsByClass[classId]) ? subjectsByClass[classId] : [];
                            subjectSelect.innerHTML = '<option value="">All Subjects</option>';
                            list.forEach(function(s) {
                                const opt = document.createElement('option');
                                opt.value = s.id;
                                opt.textContent = s.name;
                                if (String(s.id) === String(current)) opt.selected = true;
                                subjectSelect.appendChild(opt);
                            });
                        }

                        classSelect.addEventListener('change', function() {
                            populateSubjects(false);
                        });
                    })();
                </script>

                <?php if (!empty($performance_data)): ?>
                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <?php
                    $total_students = count($performance_data);
                    $class_average = 0;
                    $excellent_count = 0;
                    $good_count = 0;
                    $average_count = 0;
                    $needs_improvement = 0;
                    
                    foreach ($performance_data as $student) {
                        $percentage = (float)$student['percentage'];
                        $class_average += $percentage;
                        if ($percentage >= 80) $excellent_count++;
                        elseif ($percentage >= 65) $good_count++;
                        elseif ($percentage >= 50) $average_count++;
                        else $needs_improvement++;
                    }
                    
                    $class_average = $total_students > 0 ? $class_average / $total_students : 0;
                    ?>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Students Evaluated</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $total_students; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 dark:text-blue-450 text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Class Average</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($class_average, 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-green-600 dark:text-green-450 text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Honors (80%+)</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $excellent_count; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-award text-purple-600 dark:text-purple-450 text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Needs Support (<50%)</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $needs_improvement; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-650 dark:text-red-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Left: Performance Categories Doughnut -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Performance Breakdown</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Overview of student academic tiers</p>
                        </div>
                        <div class="my-6 relative flex items-center justify-center" style="height: 240px;">
                            <canvas id="performanceBreakdownChart"></canvas>
                        </div>
                    </div>

                    <!-- Right: Top 10 Students Horizontal Bar Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Top Performers</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Top students in class based on average percentage score</p>
                        </div>
                        <div class="my-6 relative flex items-center justify-center" style="height: 240px;">
                            <canvas id="topPerformersChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Table Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Academic Performance Standings</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            Showing rankings for 
                            <?php 
                            $class_name = '';
                            foreach ($classes as $class) {
                                if ($class['id'] == $selected_class) {
                                    $class_name = $class['name'];
                                    break;
                                }
                            }
                            echo htmlspecialchars($class_name);
                            ?>
                            <?php if ($selected_subject): ?>
                            - <?php 
                            foreach ($subjects as $subject) {
                                if ($subject['id'] == $selected_subject) {
                                    echo htmlspecialchars($subject['name']);
                                    break;
                                }
                            }
                            ?>
                            <?php endif; ?>
                            (<?php echo ucfirst($selected_term); ?> Term)
                        </p>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Roll/ID Number</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-semibold">Records Compiled</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Average Marks</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Percentage</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rating</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php 
                                $rank = 1;
                                foreach ($performance_data as $student): 
                                    $percentage = (float)($student['percentage'] ?? 0);
                                    // Grade shown in the school's configured style; colour stays
                                    // tied to the numeric percentage band.
                                    $grade = formatGrade($percentage);
                                    $performance_class = '';

                                    if ($percentage >= 80) {
                                        $performance_class = 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300';
                                    } elseif ($percentage >= 70) {
                                        $performance_class = 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300';
                                    } elseif ($percentage >= 60) {
                                        $performance_class = 'text-teal-800 bg-teal-100 dark:bg-teal-900/40 dark:text-teal-300';
                                    } elseif ($percentage >= 50) {
                                        $performance_class = 'text-orange-850 bg-orange-100 dark:bg-orange-900/40 dark:text-orange-355';
                                    } else {
                                        $performance_class = 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300';
                                    }
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-bold text-gray-900 dark:text-white">
                                        #<?php echo $rank; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($student['student_name']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350">
                                        <?php echo $student['total_exams'] ?? 0; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-750 dark:text-gray-300 font-medium">
                                        <?php echo number_format($student['average_marks'] ?? 0, 1); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white">
                                        <?php echo number_format($percentage, 1); ?>%
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $performance_class; ?>">
                                            <?php echo htmlspecialchars($grade); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <?php if ($percentage >= 80): ?>
                                            <span class="text-green-600 font-semibold"><i class="fas fa-arrow-up mr-1"></i>Excellent</span>
                                        <?php elseif ($percentage >= 65): ?>
                                            <span class="text-blue-600 font-semibold"><i class="fas fa-arrow-right-long mr-1"></i>Good</span>
                                        <?php elseif ($percentage >= 50): ?>
                                            <span class="text-orange-500 font-semibold"><i class="fas fa-arrow-down mr-1"></i>Average</span>
                                        <?php else: ?>
                                            <span class="text-red-600 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i>Needs Help</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php 
                                $rank++;
                                endforeach; 
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php elseif ($selected_class): ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-chart-line text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Performance Records</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">No academic grade records exist for the selected criteria. Ensure that grades have been entered for this term and class.</p>
                </div>
                <?php else: ?>
                <!-- Filters Instruction -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-indigo-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-filter text-indigo-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Generate Progress Standings</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">Select a class from the filter dropdown menu above to retrieve student performance details and class rankings.</p>
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
<!-- PRINT REPORT TEMPLATE (Hidden on screen, shown during print) -->
<!-- ============================================================ -->
<?php if (!empty($performance_data)): ?>
<div id="print-report" class="print-report-container">
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
            <h2>Academic Progress Report</h2>
        </div>

        <!-- Report Meta Information -->
        <div class="print-meta-grid">
            <div class="print-meta-item">
                <span class="print-meta-label">Class:</span>
                <span class="print-meta-value">
                    <?php
                    $print_class_name = '';
                    foreach ($classes as $class) {
                        if ($class['id'] == $selected_class) {
                            $print_class_name = $class['name'];
                            break;
                        }
                    }
                    echo htmlspecialchars($print_class_name);
                    ?>
                </span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Subject:</span>
                <span class="print-meta-value">
                    <?php
                    if ($selected_subject) {
                        foreach ($subjects as $subject) {
                            if ($subject['id'] == $selected_subject) {
                                echo htmlspecialchars($subject['name']);
                                break;
                            }
                        }
                    } else {
                        echo 'All Subjects';
                    }
                    ?>
                </span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Term:</span>
                <span class="print-meta-value"><?php echo ucfirst($selected_term); ?> Term</span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Date Generated:</span>
                <span class="print-meta-value"><?php echo date('F j, Y'); ?></span>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="print-section-title">Performance Summary</div>
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
                <div class="print-summary-label">Honors (80%+)</div>
            </div>
            <div class="print-summary-card print-summary-red">
                <div class="print-summary-value"><?php echo $needs_improvement; ?></div>
                <div class="print-summary-label">Needs Support (&lt;50%)</div>
            </div>
        </div>

        <!-- Performance Distribution -->
        <div class="print-section-title">Performance Distribution</div>
        <div class="print-distribution-bar">
            <?php if ($total_students > 0): ?>
            <?php $pct_excellent = ($excellent_count / $total_students) * 100; ?>
            <?php $pct_good = ($good_count / $total_students) * 100; ?>
            <?php $pct_average = ($average_count / $total_students) * 100; ?>
            <?php $pct_needs = ($needs_improvement / $total_students) * 100; ?>
            <?php if ($pct_excellent > 0): ?><div class="dist-segment dist-excellent" style="width:<?php echo $pct_excellent; ?>%"><span><?php echo $excellent_count; ?></span></div><?php endif; ?>
            <?php if ($pct_good > 0): ?><div class="dist-segment dist-good" style="width:<?php echo $pct_good; ?>%"><span><?php echo $good_count; ?></span></div><?php endif; ?>
            <?php if ($pct_average > 0): ?><div class="dist-segment dist-average" style="width:<?php echo $pct_average; ?>%"><span><?php echo $average_count; ?></span></div><?php endif; ?>
            <?php if ($pct_needs > 0): ?><div class="dist-segment dist-needs" style="width:<?php echo $pct_needs; ?>%"><span><?php echo $needs_improvement; ?></span></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="print-dist-legend">
            <span class="legend-item"><span class="legend-dot" style="background:#059669"></span>Excellent (80%+)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#2563eb"></span>Good (65–79%)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#d97706"></span>Average (50–64%)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#dc2626"></span>Needs Support (&lt;50%)</span>
        </div>

        <!-- Academic Performance Standings Table -->
        <div class="print-section-title">Academic Performance Standings</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:50px">Rank</th>
                    <th style="text-align:left">Student Name</th>
                    <th>Student ID</th>
                    <th>Records</th>
                    <th>Avg. Marks</th>
                    <th>Percentage</th>
                    <th>Grade</th>
                    <th>Performance</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $print_rank = 1;
                foreach ($performance_data as $student):
                    $pct = (float)($student['percentage'] ?? 0);
                    // Display grade follows the configured grading style; the colour
                    // class and rating remain tied to the numeric percentage band.
                    $grade = formatGrade($pct);
                    $grade_class = '';
                    $rating = '';
                    if ($pct >= 80) {
                        $grade_class = 'grade-a'; $rating = 'Excellent';
                    } elseif ($pct >= 70) {
                        $grade_class = 'grade-b'; $rating = 'Very Good';
                    } elseif ($pct >= 60) {
                        $grade_class = 'grade-c'; $rating = 'Good';
                    } elseif ($pct >= 50) {
                        $grade_class = 'grade-d'; $rating = 'Average';
                    } else {
                        $grade_class = 'grade-f'; $rating = 'Needs Help';
                    }
                ?>
                <tr<?php echo $print_rank <= 3 ? ' class="top-three"' : ''; ?>>
                    <td class="rank-cell">
                        <?php if ($print_rank <= 3): ?>
                        <span class="rank-badge rank-<?php echo $print_rank; ?>"><?php echo $print_rank; ?></span>
                        <?php else: ?>
                        <?php echo $print_rank; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($student['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?></td>
                    <td><?php echo $student['total_exams'] ?? 0; ?></td>
                    <td><?php echo number_format($student['average_marks'] ?? 0, 1); ?></td>
                    <td class="pct-cell"><?php echo number_format($pct, 1); ?>%</td>
                    <td><span class="print-grade-badge <?php echo $grade_class; ?>"><?php echo htmlspecialchars($grade); ?></span></td>
                    <td class="rating-cell rating-<?php echo strtolower(str_replace(' ', '-', $rating)); ?>"><?php echo $rating; ?></td>
                </tr>
                <?php $print_rank++; endforeach; ?>
            </tbody>
        </table>

        <!-- Grading Key -->
        <div class="print-grading-key">
            <div class="print-grading-key-title">Grading Key</div>
            <table class="grading-key-table">
                <tr>
                    <th>Band</th><th>A</th><th>B</th><th>C</th><th>D</th><th>F</th>
                </tr>
                <tr>
                    <td class="gk-label">Range</td>
                    <td>80 – 100%</td>
                    <td>70 – 79%</td>
                    <td>60 – 69%</td>
                    <td>50 – 59%</td>
                    <td>0 – 49%</td>
                </tr>
                <tr>
                    <td class="gk-label">Interpretation</td>
                    <td>Excellent</td>
                    <td>Very Good</td>
                    <td>Good</td>
                    <td>Average</td>
                    <td>Needs Help</td>
                </tr>
            </table>
        </div>

        <!-- Signatures Section -->
        <?php echo signatureRow(['Class Teacher', 'Head of Department', 'Headmaster/Headmistress']); ?>

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
    .dist-average { background: #d97706; }
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
    .print-table tbody tr.top-three {
        background: #fffbeb;
    }
    .print-table tbody tr.top-three:nth-child(1) {
        background: #fefce8;
    }
    .rank-cell {
        font-weight: 700;
    }
    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        font-size: 10px;
        font-weight: 800;
        color: white;
    }
    .rank-1 { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .rank-2 { background: linear-gradient(135deg, #9ca3af, #6b7280); }
    .rank-3 { background: linear-gradient(135deg, #b45309, #92400e); }
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
    .grade-c { background: #e0e7ff; color: #3730a3; }
    .grade-d { background: #fef3c7; color: #92400e; }
    .grade-f { background: #fecaca; color: #991b1b; }
    .rating-cell {
        font-weight: 600;
        font-size: 9px;
    }
    .rating-excellent { color: #059669; }
    .rating-very-good { color: #2563eb; }
    .rating-good { color: #4f46e5; }
    .rating-average { color: #d97706; }
    .rating-needs-help { color: #dc2626; }

    /* ===== Grading Key ===== */
    .print-grading-key {
        margin-bottom: 20px;
    }
    .print-grading-key-title {
        font-size: 9px;
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 5px;
    }
    .grading-key-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 9px;
    }
    .grading-key-table th,
    .grading-key-table td {
        padding: 3px 8px;
        border: 1px solid #e5e7eb;
        text-align: center;
    }
    .grading-key-table th {
        background: #f0f4f8;
        font-weight: 600;
        color: #1e3a5f;
    }
    .gk-label {
        font-weight: 600;
        background: #f8fafc;
        text-align: left !important;
        color: #374151;
    }

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

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
function exportReport() {
    // Add a class for print preparation
    document.body.classList.add('printing-report');
    
    // Slight delay to let styles apply
    setTimeout(function() {
        window.print();
        // Clean up after print dialog closes
        document.body.classList.remove('printing-report');
    }, 100);
}

<?php if (!empty($performance_data)): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDarkMode = document.documentElement.classList.contains('dark');
    const labelColor = isDarkMode ? '#9ca3af' : '#4b5563';
    const gridColor = isDarkMode ? '#374151' : '#f3f4f6';

    // 1. Performance Tiers Chart
    const breakdownCtx = document.getElementById('performanceBreakdownChart').getContext('2d');
    new Chart(breakdownCtx, {
        type: 'doughnut',
        data: {
            labels: ['Excellent (80%+)', 'Good (65-79%)', 'Average (50-64%)', 'Needs Support (<50%)'],
            datasets: [{
                data: [
                    <?php echo $excellent_count; ?>,
                    <?php echo $good_count; ?>,
                    <?php echo $average_count; ?>,
                    <?php echo $needs_improvement; ?>
                ],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.85)',
                    'rgba(59, 130, 246, 0.85)',
                    'rgba(249, 115, 22, 0.85)',
                    'rgba(239, 68, 68, 0.85)'
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
                        color: labelColor,
                        padding: 15,
                        font: {
                            size: 11
                        }
                    }
                }
            }
        }
    });

    // 2. Top Performers Chart
    const topCtx = document.getElementById('topPerformersChart').getContext('2d');
    
    // Sort and slice top 10
    <?php
    $top_10 = array_slice($performance_data, 0, 10);
    $top_names = [];
    $top_pcts = [];
    foreach ($top_10 as $s) {
        $top_names[] = $s['student_name'];
        $top_pcts[] = (float)$s['percentage'];
    }
    ?>

    new Chart(topCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($top_names); ?>,
            datasets: [{
                label: 'Percentage Score',
                data: <?php echo json_encode($top_pcts); ?>,
                backgroundColor: 'rgba(99, 102, 241, 0.85)',
                borderColor: 'rgb(99, 102, 241)',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            indexAxis: 'y', // Makes the chart horizontal
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                x: {
                    max: 100,
                    beginAtZero: true,
                    ticks: {
                        color: labelColor
                    },
                    grid: {
                        color: gridColor
                    }
                },
                y: {
                    ticks: {
                        color: labelColor,
                        font: {
                            size: 10
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
<?php endif; ?>
</script>
