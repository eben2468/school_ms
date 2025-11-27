<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? 0;

// Verify this student belongs to this parent
$verify_sql = "SELECT COUNT(*) FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
$stmt = $db->prepare($verify_sql);
$stmt->bindParam(':parent_id', $user_id);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();

if ($stmt->fetchColumn() == 0) {
    header('Location: dashboard.php');
    exit();
}

// Get student information
$student_sql = "SELECT u.name, sp.student_id, c.name as class_name, c.grade_level
FROM users u
JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
WHERE u.id = :student_id";

$stmt = $db->prepare($student_sql);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

// Get academic records
$academic_context = $database->getCurrentAcademicContext();
$current_year_id = $academic_context['year_id'];
$current_term_id = $academic_context['term_id'];

$records_sql = "SELECT 
    s.name as subject_name,
    sar.continuous_assessment,
    sar.mid_term_exam,
    sar.final_exam,
    sar.total_score,
    sar.grade,
    sar.remarks,
    at.term_name
FROM student_academic_records sar
JOIN subjects s ON sar.subject_id = s.id
JOIN academic_terms at ON sar.academic_term_id = at.id
WHERE sar.student_id = :student_id 
AND sar.academic_year_id = :year_id
ORDER BY at.term_number, s.name";

$stmt = $db->prepare($records_sql);
$stmt->bindParam(':student_id', $student_id);
$stmt->bindParam(':year_id', $current_year_id);
$stmt->execute();
$academic_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get assignments
$assignments_sql = "SELECT 
    a.title,
    a.description,
    a.due_date,
    a.total_marks,
    sa.grade as student_grade,
    sa.feedback,
    sa.submitted_at,
    s.name as subject_name
FROM assignments a
JOIN subjects s ON a.subject_id = s.id
LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :student_id
JOIN classes c ON a.class_id = c.id
JOIN student_classes sc ON c.id = sc.class_id AND sc.student_id = :student_id AND sc.status = 'active'
WHERE a.status = 'active'
ORDER BY a.due_date DESC
LIMIT 10";

$stmt = $db->prepare($assignments_sql);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Academic Progress - " . htmlspecialchars($student['name']);
include '../includes/header.php';
include '../includes/sidebar.php';
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
                                <h1 class="text-3xl font-bold mb-2">Academic Progress</h1>
                                <p class="text-blue-100 text-lg"><?php echo htmlspecialchars($student['name']); ?></p>
                                <div class="mt-2 flex items-center space-x-4 text-sm text-blue-100">
                                    <span>Student ID: <?php echo htmlspecialchars($student['student_id']); ?></span>
                                    <?php if ($student['class_name']): ?>
                                    <span>Grade <?php echo htmlspecialchars($student['grade_level']); ?> - <?php echo htmlspecialchars($student['class_name']); ?></span>
                                    <?php endif; ?>
                                </div>
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
                    <a href="dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Parent Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Academic Progress</span>
                </div>

                <!-- Academic Records -->
                <?php if (!empty($academic_records)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Academic Records</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Current academic year performance</p>
                    </div>
                    <div class="p-6">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Term</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">CA</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Mid-Term</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Final</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($academic_records as $record): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['subject_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?php echo htmlspecialchars($record['term_name']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['continuous_assessment'] ?? '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['mid_term_exam'] ?? '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <?php echo $record['final_exam'] ?? '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo $record['total_score'] ?? '-'; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($record['grade']): ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full 
                                                <?php 
                                                $grade = strtoupper($record['grade']);
                                                if (in_array($grade, ['A', 'A+'])) echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                                elseif (in_array($grade, ['B', 'B+'])) echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                                elseif (in_array($grade, ['C', 'C+'])) echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                                else echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                                ?>">
                                                <?php echo htmlspecialchars($record['grade']); ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="text-gray-400">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Recent Assignments -->
                <?php if (!empty($assignments)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Recent Assignments</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Latest assignment submissions and grades</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php foreach ($assignments as $assignment): ?>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <h4 class="text-lg font-medium text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                        </p>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                            Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                        </p>
                                    </div>
                                    <div class="text-right">
                                        <?php if ($assignment['student_grade'] !== null): ?>
                                        <div class="text-lg font-semibold text-green-600 dark:text-green-400">
                                            <?php echo $assignment['student_grade']; ?>/<?php echo $assignment['total_marks']; ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            Submitted: <?php echo date('M j', strtotime($assignment['submitted_at'])); ?>
                                        </div>
                                        <?php else: ?>
                                        <div class="text-sm text-red-600 dark:text-red-400">
                                            Not Submitted
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ($assignment['feedback']): ?>
                                <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <p class="text-sm text-blue-800 dark:text-blue-200">
                                        <strong>Teacher Feedback:</strong> <?php echo htmlspecialchars($assignment['feedback']); ?>
                                    </p>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($academic_records) && empty($assignments)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-chart-line text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Academic Data Available</h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            Academic records and assignments will appear here once they are available.
                        </p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
