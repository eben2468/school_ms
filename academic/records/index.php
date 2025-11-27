<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get current academic context
$academic_context = $database->getCurrentAcademicContext();

// Get filters
$selected_year_id = $_GET['year_id'] ?? $academic_context['year_id'];
$selected_term_id = $_GET['term_id'] ?? $academic_context['term_id'];
$selected_class_id = $_GET['class_id'] ?? '';
$selected_student_id = $_GET['student_id'] ?? '';

// Get academic years
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get terms for selected year
$terms = [];
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
if ($selected_class_id) {
    $students_sql = "SELECT u.id, u.name, sp.student_id as profile_student_id
                    FROM users u
                    JOIN student_profiles sp ON u.id = sp.user_id
                    JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                    WHERE u.role = 'student' AND u.status = 'active' AND sc.class_id = :class_id
                    ORDER BY u.name";
    $stmt = $db->prepare($students_sql);
    $stmt->bindParam(':class_id', $selected_class_id);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get academic records based on filters
$records = [];
$where_conditions = [];
$params = [];

if ($selected_year_id) {
    $where_conditions[] = "sar.academic_year_id = :year_id";
    $params[':year_id'] = $selected_year_id;
}

if ($selected_term_id) {
    $where_conditions[] = "sar.academic_term_id = :term_id";
    $params[':term_id'] = $selected_term_id;
}

if ($selected_class_id) {
    $where_conditions[] = "sar.class_id = :class_id";
    $params[':class_id'] = $selected_class_id;
}

if ($selected_student_id) {
    $where_conditions[] = "sar.student_id = :student_id";
    $params[':student_id'] = $selected_student_id;
}

if (!empty($where_conditions)) {
    $records_sql = "SELECT 
        sar.*,
        u.name as student_name,
        sp.student_id as profile_student_id,
        s.name as subject_name, s.code as subject_code,
        c.name as class_name, c.grade_level,
        ay.year_name,
        at.term_name,
        teacher.name as teacher_name
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN subjects s ON sar.subject_id = s.id
    JOIN classes c ON sar.class_id = c.id
    JOIN academic_years ay ON sar.academic_year_id = ay.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY u.name, s.name";
    
    $stmt = $db->prepare($records_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Calculate summary statistics
$summary = [
    'total_records' => count($records),
    'average_score' => 0,
    'subjects_count' => 0,
    'students_count' => 0
];

if (!empty($records)) {
    $total_score = array_sum(array_column($records, 'total_score'));
    $summary['average_score'] = $total_score / count($records);
    $summary['subjects_count'] = count(array_unique(array_column($records, 'subject_id')));
    $summary['students_count'] = count(array_unique(array_column($records, 'student_id')));
}

$title = "Academic Records";
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
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Academic Records</h1>
                                <p class="text-blue-100 text-lg">View and manage student academic history and progress</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-chart-line text-6xl text-white/80"></i>
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
                    <span class="text-gray-900 dark:text-white font-medium">Records</span>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Filter Records</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Select criteria to view academic records</p>
                    </div>
                    <div class="p-6">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            <!-- Academic Year -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                                <select name="year_id" onchange="this.form.submit()" 
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Years</option>
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
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Terms</option>
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
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $selected_class_id ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Student -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student</label>
                                <select name="student_id" onchange="this.form.submit()" 
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Students</option>
                                    <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>" <?php echo $student['id'] == $selected_student_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($student['name']); ?> (<?php echo htmlspecialchars($student['profile_student_id']); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Actions -->
                            <div class="flex items-end">
                                <button type="submit" 
                                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-search mr-2"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Summary Statistics -->
                <?php if (!empty($records)): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-alt text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Records</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $summary['total_records']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-chart-line text-green-600 dark:text-green-400"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Average Score</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($summary['average_score'], 1); ?>%</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-purple-600 dark:text-purple-400"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Students</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $summary['students_count']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-book text-orange-600 dark:text-orange-400"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Subjects</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $summary['subjects_count']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Records Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Academic Records</h3>
                                <p class="text-gray-600 dark:text-gray-400 mt-1">Detailed academic performance records</p>
                            </div>
                            <div class="flex space-x-2">
                                <button onclick="window.print()"
                                    class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                    <i class="fas fa-print mr-2"></i>Print
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Academic Context</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">CA Score</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Exam Score</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total Score</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Teacher</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($records as $record): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600 dark:text-blue-400"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($record['student_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                                    ID: <?php echo htmlspecialchars($record['profile_student_id']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['subject_name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo htmlspecialchars($record['subject_code']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['year_name']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo htmlspecialchars($record['term_name']); ?>
                                        </div>
                                        <div class="text-xs text-gray-400">
                                            Grade <?php echo htmlspecialchars($record['grade_level']); ?> - <?php echo htmlspecialchars($record['class_name']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo number_format($record['continuous_assessment'], 1); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo number_format($record['exam_score'], 1); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo number_format($record['total_score'], 1); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                                            <?php
                                            $score = $record['total_score'];
                                            if ($score >= 80) echo 'bg-green-100 text-green-800';
                                            elseif ($score >= 70) echo 'bg-blue-100 text-blue-800';
                                            elseif ($score >= 60) echo 'bg-yellow-100 text-yellow-800';
                                            elseif ($score >= 50) echo 'bg-orange-100 text-orange-800';
                                            else echo 'bg-red-100 text-red-800';
                                            ?>">
                                            <?php echo htmlspecialchars($record['grade'] ?: 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($record['teacher_name'] ?: 'N/A'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-chart-line text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Records Found</h3>
                        <p class="text-gray-600 dark:text-gray-400">No academic records match the selected criteria. Try adjusting your filters.</p>
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
