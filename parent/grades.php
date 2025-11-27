<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$parent_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? null;

// Verify parent has access to this student
if ($student_id) {
    $access_query = "SELECT COUNT(*) as count FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':parent_id', $parent_id);
    $access_stmt->bindParam(':student_id', $student_id);
    $access_stmt->execute();
    $access = $access_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($access['count'] == 0) {
        header("Location: index.php");
        exit();
    }
}

// Get parent's children if no specific student selected
if (!$student_id) {
    $children_query = "
        SELECT u.id, u.name 
        FROM users u
        JOIN parent_students ps ON u.id = ps.student_id
        WHERE ps.parent_id = :parent_id AND u.status = 'active'
        ORDER BY u.name
    ";
    $children_stmt = $db->prepare($children_query);
    $children_stmt->bindParam(':parent_id', $parent_id);
    $children_stmt->execute();
    $children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($children) == 1) {
        $student_id = $children[0]['id'];
    }
}

$student_info = null;
$grades = [];
$grade_summary = null;

if ($student_id) {
    // Get student information
    $student_query = "
        SELECT u.name, sp.student_id, c.name as class_name, c.grade_level, c.academic_year
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE u.id = :student_id
    ";
    $student_stmt = $db->prepare($student_query);
    $student_stmt->bindParam(':student_id', $student_id);
    $student_stmt->execute();
    $student_info = $student_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get grades for the student
    $grades_query = "
        SELECT g.*, s.name as subject_name, s.code as subject_code,
               e.title as exam_title, e.exam_date, e.total_marks,
               t.name as teacher_name
        FROM grades g
        LEFT JOIN exams e ON g.exam_id = e.id
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN users t ON e.teacher_id = t.id
        WHERE g.student_id = :student_id
        ORDER BY e.exam_date DESC, s.name
    ";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':student_id', $student_id);
    $grades_stmt->execute();
    $grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate grade summary
    if (!empty($grades)) {
        $total_marks = array_sum(array_column($grades, 'marks_obtained'));
        $total_possible = array_sum(array_column($grades, 'total_marks'));
        $average_percentage = $total_possible > 0 ? ($total_marks / $total_possible) * 100 : 0;
        
        $grade_summary = [
            'total_exams' => count($grades),
            'total_marks' => $total_marks,
            'total_possible' => $total_possible,
            'average_percentage' => $average_percentage,
            'grade_letter' => getGradeLetter($average_percentage)
        ];
    }
}

function getGradeLetter($percentage) {
    if ($percentage >= 90) return 'A+';
    if ($percentage >= 80) return 'A';
    if ($percentage >= 70) return 'B+';
    if ($percentage >= 60) return 'B';
    if ($percentage >= 50) return 'C+';
    if ($percentage >= 40) return 'C';
    if ($percentage >= 30) return 'D';
    return 'F';
}

function getGradeColor($percentage) {
    if ($percentage >= 80) return 'text-green-600 dark:text-green-400';
    if ($percentage >= 60) return 'text-blue-600 dark:text-blue-400';
    if ($percentage >= 40) return 'text-yellow-600 dark:text-yellow-400';
    return 'text-red-600 dark:text-red-400';
}

$title = "Student Grades";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Student Grades</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">View your child's academic performance</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <a href="?student_id=<?php echo $child['id']; ?>" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($child['name']); ?></span>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_info): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student_info['name']); ?></h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($student_info['class_name'] ?? 'No Class Assigned'); ?>
                                <?php if ($student_info['academic_year']): ?>
                                • <?php echo htmlspecialchars($student_info['academic_year']); ?>
                                <?php endif; ?>
                                <?php if ($student_info['student_id']): ?>
                                • ID: <?php echo htmlspecialchars($student_info['student_id']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Grade Summary -->
                <?php if ($grade_summary): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clipboard-list text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Exams</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $grade_summary['total_exams']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-trophy text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Marks</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400">
                                    <?php echo $grade_summary['total_marks']; ?>/<?php echo $grade_summary['total_possible']; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-percentage text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Average</h3>
                                <p class="text-2xl font-bold <?php echo getGradeColor($grade_summary['average_percentage']); ?>">
                                    <?php echo number_format($grade_summary['average_percentage'], 1); ?>%
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-medal text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Grade</h3>
                                <p class="text-2xl font-bold <?php echo getGradeColor($grade_summary['average_percentage']); ?>">
                                    <?php echo $grade_summary['grade_letter']; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Grades Table -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Exam Results</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Detailed breakdown of all exam scores</p>
                    </div>

                    <?php if (empty($grades)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-chart-line text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Grades Available</h3>
                        <p class="text-gray-500 dark:text-gray-400">No exam results found for this student.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Exam</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Marks</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Percentage</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Teacher</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($grades as $grade): ?>
                                <?php 
                                $percentage = $grade['total_marks'] > 0 ? ($grade['marks_obtained'] / $grade['total_marks']) * 100 : 0;
                                $grade_letter = getGradeLetter($percentage);
                                ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3">
                                                <span class="text-xs font-medium text-blue-600 dark:text-blue-400">
                                                    <?php echo strtoupper(substr($grade['subject_code'] ?? $grade['subject_name'], 0, 2)); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($grade['subject_name'] ?? 'Unknown Subject'); ?>
                                                </div>
                                                <?php if ($grade['subject_code']): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?php echo htmlspecialchars($grade['subject_code']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($grade['exam_title'] ?? 'Exam'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo $grade['exam_date'] ? date('M j, Y', strtotime($grade['exam_date'])) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo $grade['marks_obtained']; ?>/<?php echo $grade['total_marks']; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="text-sm font-medium <?php echo getGradeColor($percentage); ?>">
                                            <?php echo number_format($percentage, 1); ?>%
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            if ($percentage >= 80) echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                            elseif ($percentage >= 60) echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                            elseif ($percentage >= 40) echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                            else echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                            ?>">
                                            <?php echo $grade_letter; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($grade['teacher_name'] ?? '-'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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
