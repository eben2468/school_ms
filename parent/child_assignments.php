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

// Get assignments
$assignments_sql = "SELECT 
    a.id,
    a.title,
    a.description,
    a.due_date,
    a.total_marks,
    a.status as assignment_status,
    sa.grade as student_grade,
    sa.feedback,
    sa.submitted_at,
    s.name as subject_name,
    CASE 
        WHEN sa.submitted_at IS NOT NULL THEN 'submitted'
        WHEN a.due_date < CURDATE() THEN 'overdue'
        ELSE 'pending'
    END as submission_status
FROM assignments a
JOIN subjects s ON a.subject_id = s.id
LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :student_id
JOIN classes c ON a.class_id = c.id
JOIN student_classes sc ON c.id = sc.class_id AND sc.student_id = :student_id AND sc.status = 'active'
WHERE a.status = 'active'
ORDER BY a.due_date DESC";

$stmt = $db->prepare($assignments_sql);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate assignment statistics
$total_assignments = count($assignments);
$submitted_assignments = count(array_filter($assignments, function($a) { return $a['submission_status'] === 'submitted'; }));
$pending_assignments = count(array_filter($assignments, function($a) { return $a['submission_status'] === 'pending'; }));
$overdue_assignments = count(array_filter($assignments, function($a) { return $a['submission_status'] === 'overdue'; }));

$title = "Assignments - " . htmlspecialchars($student['name']);
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
                                <h1 class="text-3xl font-bold mb-2">Assignments</h1>
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
                                    <i class="fas fa-tasks text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Parent Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Assignments</span>
                </div>

                <!-- Assignment Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-list text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Assignments</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $total_assignments; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Submitted</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $submitted_assignments; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $pending_assignments; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Overdue</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo $overdue_assignments; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Assignments List -->
                <?php if (!empty($assignments)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Assignment List</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">All assignments and their submission status</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-6">
                            <?php foreach ($assignments as $assignment): ?>
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-6">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <h4 class="text-lg font-semibold text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($assignment['title']); ?>
                                            </h4>
                                            <?php
                                            $status_classes = [
                                                'submitted' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
                                                'pending' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
                                                'overdue' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'
                                            ];
                                            $class = $status_classes[$assignment['submission_status']] ?? 'bg-gray-100 text-gray-800';
                                            ?>
                                            <span class="px-3 py-1 text-xs font-medium rounded-full <?php echo $class; ?>">
                                                <?php echo ucfirst($assignment['submission_status']); ?>
                                            </span>
                                        </div>
                                        
                                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm text-gray-600 dark:text-gray-400 mb-3">
                                            <div>
                                                <span class="font-medium">Subject:</span> <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                            </div>
                                            <div>
                                                <span class="font-medium">Due Date:</span> 
                                                <span class="<?php echo $assignment['submission_status'] === 'overdue' ? 'text-red-600 dark:text-red-400 font-medium' : ''; ?>">
                                                    <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <span class="font-medium">Total Marks:</span> <?php echo $assignment['total_marks']; ?>
                                            </div>
                                        </div>

                                        <?php if ($assignment['description']): ?>
                                        <p class="text-gray-700 dark:text-gray-300 mb-3">
                                            <?php echo htmlspecialchars($assignment['description']); ?>
                                        </p>
                                        <?php endif; ?>

                                        <?php if ($assignment['submission_status'] === 'submitted'): ?>
                                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="text-sm font-medium text-green-800 dark:text-green-200">
                                                    <i class="fas fa-check-circle mr-2"></i>Submitted
                                                </span>
                                                <span class="text-sm text-green-600 dark:text-green-400">
                                                    <?php echo date('M j, Y g:i A', strtotime($assignment['submitted_at'])); ?>
                                                </span>
                                            </div>
                                            
                                            <?php if ($assignment['student_grade'] !== null): ?>
                                            <div class="flex items-center justify-between">
                                                <span class="text-lg font-semibold text-green-800 dark:text-green-200">
                                                    Grade: <?php echo $assignment['student_grade']; ?>/<?php echo $assignment['total_marks']; ?>
                                                </span>
                                                <span class="text-sm text-green-600 dark:text-green-400">
                                                    <?php echo round(($assignment['student_grade'] / $assignment['total_marks']) * 100, 1); ?>%
                                                </span>
                                            </div>
                                            
                                            <?php if ($assignment['feedback']): ?>
                                            <div class="mt-3 p-3 bg-white dark:bg-gray-800 rounded border">
                                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                                    <strong>Teacher Feedback:</strong> <?php echo htmlspecialchars($assignment['feedback']); ?>
                                                </p>
                                            </div>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <p class="text-sm text-green-600 dark:text-green-400">Awaiting grading</p>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-tasks text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Assignments</h3>
                        <p class="text-gray-600 dark:text-gray-400">
                            No assignments have been assigned yet.
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
