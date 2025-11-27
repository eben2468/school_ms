<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.student_id, c.name as class_name,
           COALESCE(c.section, '') as section
    FROM users u
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.role = 'student'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $user_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

$student_id = $_GET['student_id'] ?? null;

// Validate student belongs to parent
if ($student_id) {
    $valid_student = false;
    foreach ($children as $child) {
        if ($child['id'] == $student_id) {
            $valid_student = true;
            $selected_student = $child;
            break;
        }
    }
    if (!$valid_student) {
        $student_id = null;
    }
}

// Get assignments for selected student
$assignments = [];
$assignment_summary = [];
if ($student_id) {
    // Get assignments for the student's class
    $assignments_query = "
        SELECT a.*, s.name as subject_name, u.name as teacher_name,
               sub.status as submission_status, sub.submitted_at, sub.grade, sub.feedback
        FROM assignments a
        LEFT JOIN subjects s ON a.subject_id = s.id
        LEFT JOIN users u ON a.teacher_id = u.id
        LEFT JOIN assignment_submissions sub ON a.id = sub.assignment_id AND sub.student_id = :student_id
        WHERE a.class_id = (SELECT class_id FROM users WHERE id = :student_id)
        AND a.status = 'active'
        ORDER BY a.due_date DESC, a.created_at DESC
    ";
    $assignments_stmt = $db->prepare($assignments_query);
    $assignments_stmt->bindParam(':student_id', $student_id);
    $assignments_stmt->execute();
    $assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate assignment summary
    $total_assignments = count($assignments);
    $submitted = 0;
    $pending = 0;
    $overdue = 0;
    $graded = 0;
    
    foreach ($assignments as $assignment) {
        if ($assignment['submission_status']) {
            $submitted++;
            if ($assignment['grade'] !== null) {
                $graded++;
            }
        } else {
            if (strtotime($assignment['due_date']) < time()) {
                $overdue++;
            } else {
                $pending++;
            }
        }
    }
    
    $assignment_summary = [
        'total' => $total_assignments,
        'submitted' => $submitted,
        'pending' => $pending,
        'overdue' => $overdue,
        'graded' => $graded
    ];
}

$title = "Assignments";
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Assignments</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Track your child's assignments and submissions</p>
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
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white block"><?php echo htmlspecialchars($child['name']); ?></span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($child['class_name']); ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_id && isset($selected_student)): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($selected_student['name']); ?></h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($selected_student['class_name']); ?>
                                <?php if ($selected_student['student_id']): ?>
                                • ID: <?php echo htmlspecialchars($selected_student['student_id']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Assignment Summary -->
                <?php if (!empty($assignment_summary)): ?>
                <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-tasks text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $assignment_summary['total']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Submitted</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $assignment_summary['submitted']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pending</h3>
                                <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo $assignment_summary['pending']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Overdue</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400"><?php echo $assignment_summary['overdue']; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-star text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Graded</h3>
                                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo $assignment_summary['graded']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Assignments List -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Assignment Details</h3>
                    </div>

                    <?php if (empty($assignments)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-clipboard-list text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Assignments</h3>
                        <p class="text-gray-500 dark:text-gray-400">No assignments found for this student.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($assignments as $assignment): ?>
                        <?php 
                        $is_overdue = !$assignment['submission_status'] && strtotime($assignment['due_date']) < time();
                        $days_until_due = ceil((strtotime($assignment['due_date']) - time()) / (60 * 60 * 24));
                        ?>
                        <div class="p-6">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mr-3">
                                            <?php echo htmlspecialchars($assignment['title']); ?>
                                        </h4>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            if ($assignment['submission_status']) {
                                                if ($assignment['grade'] !== null) {
                                                    echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200';
                                                } else {
                                                    echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                                }
                                            } elseif ($is_overdue) {
                                                echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                            } else {
                                                echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                            }
                                            ?>">
                                            <?php
                                            if ($assignment['submission_status']) {
                                                echo $assignment['grade'] !== null ? 'Graded' : 'Submitted';
                                            } elseif ($is_overdue) {
                                                echo 'Overdue';
                                            } else {
                                                echo 'Pending';
                                            }
                                            ?>
                                        </span>
                                    </div>
                                    
                                    <div class="flex items-center text-sm text-gray-600 dark:text-gray-400 mb-3">
                                        <i class="fas fa-book mr-2"></i>
                                        <span class="mr-4"><?php echo htmlspecialchars($assignment['subject_name']); ?></span>
                                        <i class="fas fa-user mr-2"></i>
                                        <span class="mr-4"><?php echo htmlspecialchars($assignment['teacher_name']); ?></span>
                                        <i class="fas fa-calendar mr-2"></i>
                                        <span>Due: <?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></span>
                                        <?php if (!$assignment['submission_status'] && !$is_overdue): ?>
                                        <span class="ml-2 text-blue-600 dark:text-blue-400">
                                            (<?php echo $days_until_due; ?> day<?php echo $days_until_due != 1 ? 's' : ''; ?> left)
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <p class="text-gray-700 dark:text-gray-300 mb-3">
                                        <?php echo nl2br(htmlspecialchars($assignment['description'])); ?>
                                    </p>
                                    
                                    <?php if ($assignment['submission_status']): ?>
                                    <div class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
                                        <h5 class="font-medium text-gray-900 dark:text-white mb-2">Submission Details</h5>
                                        <div class="text-sm text-gray-600 dark:text-gray-400">
                                            <p>Submitted: <?php echo date('M j, Y g:i A', strtotime($assignment['submitted_at'])); ?></p>
                                            <?php if ($assignment['grade'] !== null): ?>
                                            <p class="mt-1">Grade: <span class="font-medium text-purple-600 dark:text-purple-400"><?php echo $assignment['grade']; ?></span></p>
                                            <?php endif; ?>
                                            <?php if ($assignment['feedback']): ?>
                                            <p class="mt-2">
                                                <span class="font-medium">Teacher Feedback:</span><br>
                                                <?php echo nl2br(htmlspecialchars($assignment['feedback'])); ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="ml-6 flex-shrink-0">
                                    <?php if ($assignment['attachment_path']): ?>
                                    <a href="../<?php echo htmlspecialchars($assignment['attachment_path']); ?>" 
                                       class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 mb-2">
                                        <i class="fas fa-download mr-2"></i>
                                        Download
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Children Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No student records are associated with your account.</p>
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
