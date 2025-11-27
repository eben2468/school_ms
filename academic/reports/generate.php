<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_reports') {
    $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
    $academic_term_id = filter_input(INPUT_POST, 'academic_term_id', FILTER_SANITIZE_NUMBER_INT);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $student_ids = $_POST['student_ids'] ?? [];
    
    if (!empty($student_ids) && $academic_year_id && $academic_term_id) {
        try {
            $db->beginTransaction();
            $generated_count = 0;
            
            foreach ($student_ids as $student_id) {
                // Check if report already exists
                $existing_report_sql = "SELECT id FROM term_reports 
                                       WHERE student_id = :student_id 
                                       AND academic_year_id = :year_id 
                                       AND academic_term_id = :term_id";
                $stmt = $db->prepare($existing_report_sql);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->execute();
                
                if ($stmt->fetch()) {
                    continue; // Skip if report already exists
                }
                
                // Get student's academic records for the term
                $records_sql = "SELECT 
                    sar.*,
                    s.name as subject_name,
                    s.code as subject_code
                FROM student_academic_records sar
                JOIN subjects s ON sar.subject_id = s.id
                WHERE sar.student_id = :student_id 
                AND sar.academic_year_id = :year_id 
                AND sar.academic_term_id = :term_id";
                
                $stmt = $db->prepare($records_sql);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->execute();
                $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($records)) {
                    continue; // Skip if no academic records
                }
                
                // Calculate totals and averages
                $total_subjects = count($records);
                $total_score = array_sum(array_column($records, 'total_score'));
                $average_score = $total_score / $total_subjects;
                
                // Get class position (rank)
                $position_sql = "SELECT 
                    student_id,
                    AVG(total_score) as avg_score,
                    RANK() OVER (ORDER BY AVG(total_score) DESC) as position
                FROM student_academic_records sar
                WHERE sar.academic_year_id = :year_id 
                AND sar.academic_term_id = :term_id 
                AND sar.class_id = :class_id
                GROUP BY student_id";
                
                $stmt = $db->prepare($position_sql);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->bindParam(':class_id', $class_id);
                $stmt->execute();
                $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $position_in_class = 1;
                $class_size = count($positions);
                foreach ($positions as $pos) {
                    if ($pos['student_id'] == $student_id) {
                        $position_in_class = $pos['position'];
                        break;
                    }
                }
                
                // Get attendance data
                $attendance_sql = "SELECT 
                    COUNT(*) as total_days,
                    SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days
                FROM attendance 
                WHERE student_id = :student_id 
                AND academic_year_id = :year_id 
                AND academic_term_id = :term_id";
                
                $stmt = $db->prepare($attendance_sql);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->execute();
                $attendance = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Determine conduct grade based on average score
                $conduct_grade = 'A';
                if ($average_score >= 80) $conduct_grade = 'A';
                elseif ($average_score >= 70) $conduct_grade = 'B';
                elseif ($average_score >= 60) $conduct_grade = 'C';
                elseif ($average_score >= 50) $conduct_grade = 'D';
                else $conduct_grade = 'F';
                
                // Generate teacher remarks
                $teacher_remarks = '';
                if ($average_score >= 80) {
                    $teacher_remarks = 'Excellent performance. Keep up the good work!';
                } elseif ($average_score >= 70) {
                    $teacher_remarks = 'Good performance. Continue to strive for excellence.';
                } elseif ($average_score >= 60) {
                    $teacher_remarks = 'Satisfactory performance. There is room for improvement.';
                } elseif ($average_score >= 50) {
                    $teacher_remarks = 'Fair performance. More effort is needed.';
                } else {
                    $teacher_remarks = 'Poor performance. Significant improvement required.';
                }
                
                // Get next term start date
                $next_term_sql = "SELECT start_date FROM academic_terms 
                                 WHERE academic_year_id = :year_id 
                                 AND term_number > (SELECT term_number FROM academic_terms WHERE id = :term_id)
                                 ORDER BY term_number LIMIT 1";
                $stmt = $db->prepare($next_term_sql);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->execute();
                $next_term = $stmt->fetch(PDO::FETCH_ASSOC);
                $next_term_begins = $next_term ? $next_term['start_date'] : null;
                
                // Insert term report
                $report_sql = "INSERT INTO term_reports (
                    student_id, academic_year_id, academic_term_id, class_id,
                    total_subjects, total_score, average_score, position_in_class, class_size,
                    attendance_days, attendance_present, conduct_grade, teacher_remarks,
                    next_term_begins, generated_by
                ) VALUES (
                    :student_id, :year_id, :term_id, :class_id,
                    :total_subjects, :total_score, :average_score, :position_in_class, :class_size,
                    :attendance_days, :attendance_present, :conduct_grade, :teacher_remarks,
                    :next_term_begins, :generated_by
                )";
                
                $stmt = $db->prepare($report_sql);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->bindParam(':year_id', $academic_year_id);
                $stmt->bindParam(':term_id', $academic_term_id);
                $stmt->bindParam(':class_id', $class_id);
                $stmt->bindParam(':total_subjects', $total_subjects);
                $stmt->bindParam(':total_score', $total_score);
                $stmt->bindParam(':average_score', $average_score);
                $stmt->bindParam(':position_in_class', $position_in_class);
                $stmt->bindParam(':class_size', $class_size);
                $stmt->bindParam(':attendance_days', $attendance['total_days'] ?? 0);
                $stmt->bindParam(':attendance_present', $attendance['present_days'] ?? 0);
                $stmt->bindParam(':conduct_grade', $conduct_grade);
                $stmt->bindParam(':teacher_remarks', $teacher_remarks);
                $stmt->bindParam(':next_term_begins', $next_term_begins);
                $stmt->bindParam(':generated_by', $_SESSION['user_id']);
                $stmt->execute();
                
                $generated_count++;
            }
            
            $db->commit();
            $success_message = "Successfully generated $generated_count term reports!";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = "Error generating reports: " . $e->getMessage();
        }
    } else {
        $error_message = "Please select students and academic context.";
    }
}

// Get current academic context
$academic_context = $database->getCurrentAcademicContext();

// Get academic years
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get terms for current year
$terms = [];
$selected_year_id = $_GET['year_id'] ?? $academic_context['year_id'];
if ($selected_year_id) {
    $terms_sql = "SELECT * FROM academic_terms WHERE academic_year_id = :year_id ORDER BY term_number";
    $stmt = $db->prepare($terms_sql);
    $stmt->bindParam(':year_id', $selected_year_id);
    $stmt->execute();
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get classes
$classes_sql = "SELECT * FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes = $db->query($classes_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get students for selected class
$students = [];
$selected_class_id = $_GET['class_id'] ?? '';
$selected_term_id = $_GET['term_id'] ?? $academic_context['term_id'];

if ($selected_class_id && $selected_year_id && $selected_term_id) {
    $students_sql = "SELECT 
        u.id, u.name,
        sp.student_id as profile_student_id,
        COALESCE(AVG(sar.total_score), 0) as average_score,
        COUNT(sar.id) as subjects_count,
        tr.id as existing_report_id
    FROM users u
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN student_academic_records sar ON u.id = sar.student_id 
        AND sar.academic_year_id = :year_id 
        AND sar.academic_term_id = :term_id
    LEFT JOIN term_reports tr ON u.id = tr.student_id 
        AND tr.academic_year_id = :year_id 
        AND tr.academic_term_id = :term_id
    WHERE u.role = 'student' AND u.status = 'active' AND sc.class_id = :class_id
    GROUP BY u.id, sp.student_id, tr.id
    ORDER BY u.name";
    
    $stmt = $db->prepare($students_sql);
    $stmt->bindParam(':year_id', $selected_year_id);
    $stmt->bindParam(':term_id', $selected_term_id);
    $stmt->bindParam(':class_id', $selected_class_id);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Generate Term Reports";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Generate Term Reports</h1>
                                <p class="text-blue-100 text-lg">Create comprehensive report cards for students</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-alt text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Generate Reports</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Filter Section -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Select Academic Context</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Choose the academic year, term, and class for report generation</p>
                    </div>
                    <div class="p-6">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <!-- Academic Year -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                                <select name="year_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Academic Year</option>
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
                                <select name="term_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Term</option>
                                    <?php foreach ($terms as $term): ?>
                                    <option value="<?php echo $term['id']; ?>" <?php echo $term['id'] == $selected_term_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Class -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                                <select name="class_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Class</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $selected_class_id ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Students Selection -->
                <?php if (!empty($students)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Students for Report Generation</h3>
                                <p class="text-gray-600 dark:text-gray-400 mt-1">Select students to generate term reports</p>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Total Students: <?php echo count($students); ?>
                            </div>
                        </div>
                    </div>

                    <form method="POST" class="p-6">
                        <input type="hidden" name="action" value="generate_reports">
                        <input type="hidden" name="academic_year_id" value="<?php echo $selected_year_id; ?>">
                        <input type="hidden" name="academic_term_id" value="<?php echo $selected_term_id; ?>">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">

                        <!-- Select All Controls -->
                        <div class="flex items-center justify-between mb-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                            <div class="flex items-center space-x-4">
                                <label class="flex items-center">
                                    <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <span class="ml-2 text-sm font-medium text-gray-700 dark:text-gray-300">Select All Students</span>
                                </label>
                                <span class="text-sm text-gray-500 dark:text-gray-400">|</span>
                                <button type="button" id="selectEligible" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
                                    Select Only Eligible (with academic records)
                                </button>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                <span id="selectedCount">0</span> selected
                            </div>
                        </div>

                        <!-- Students Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Select</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Academic Performance</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Report Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox"
                                                name="student_ids[]"
                                                value="<?php echo $student['id']; ?>"
                                                class="student-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                data-has-records="<?php echo $student['subjects_count'] > 0 ? 'true' : 'false'; ?>"
                                                <?php echo $student['existing_report_id'] ? 'disabled' : ''; ?>>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user text-blue-600 dark:text-blue-400"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?php echo htmlspecialchars($student['name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        ID: <?php echo htmlspecialchars($student['profile_student_id']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($student['subjects_count'] > 0): ?>
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                Average: <?php echo number_format($student['average_score'], 1); ?>%
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                <?php echo $student['subjects_count']; ?> subjects
                                            </div>
                                            <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">
                                                No academic records
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($student['existing_report_id']): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">
                                                Report Generated
                                            </span>
                                            <?php elseif ($student['subjects_count'] > 0): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800">
                                                Ready for Report
                                            </span>
                                            <?php else: ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full bg-gray-100 text-gray-800">
                                                Not Eligible
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700 mt-6">
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Reports will be generated for selected students with academic records
                            </div>
                            <button type="submit" id="generateBtn" disabled
                                class="bg-emerald-500 hover:bg-emerald-600 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-medium py-2 px-6 rounded-lg transition-colors duration-200">
                                <i class="fas fa-file-alt mr-2"></i>Generate Reports
                            </button>
                        </div>
                    </form>
                </div>
                <?php elseif ($selected_class_id && $selected_year_id && $selected_term_id): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-users text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Students Found</h3>
                        <p class="text-gray-600 dark:text-gray-400">No students found for the selected class and academic context.</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-filter text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Select Academic Context</h3>
                        <p class="text-gray-600 dark:text-gray-400">Please select academic year, term, and class to view students for report generation.</p>
                    </div>
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
    const selectAllCheckbox = document.getElementById('selectAll');
    const selectEligibleBtn = document.getElementById('selectEligible');
    const studentCheckboxes = document.querySelectorAll('.student-checkbox');
    const selectedCountSpan = document.getElementById('selectedCount');
    const generateBtn = document.getElementById('generateBtn');

    function updateSelectedCount() {
        const checkedBoxes = document.querySelectorAll('.student-checkbox:checked:not(:disabled)');
        selectedCountSpan.textContent = checkedBoxes.length;
        generateBtn.disabled = checkedBoxes.length === 0;
    }

    selectAllCheckbox.addEventListener('change', function() {
        studentCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled) {
                checkbox.checked = this.checked;
            }
        });
        updateSelectedCount();
    });

    selectEligibleBtn.addEventListener('click', function() {
        studentCheckboxes.forEach(checkbox => {
            if (!checkbox.disabled && checkbox.dataset.hasRecords === 'true') {
                checkbox.checked = true;
            } else if (!checkbox.disabled) {
                checkbox.checked = false;
            }
        });
        updateSelectedCount();
    });

    studentCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
    });

    // Initial count
    updateSelectedCount();
});
</script>
