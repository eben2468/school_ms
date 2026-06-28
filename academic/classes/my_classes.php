<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Safe defaults so a query failure can never leave these undefined (which would
// otherwise fatal on count()/foreach further down the page).
$student_info       = null;
$class_subjects     = [];
$classmates         = [];
$recent_assignments = [];
$upcoming_exams     = [];
$total_subjects     = 0;
$pending_assignments  = 0;
$overdue_assignments  = 0;

try {
    // Get student information (including the student's active class id + class teacher)
    $student_query = "SELECT u.name, sp.student_id, c.id as class_id, c.name as class_name, c.grade_level, c.academic_year,
                            ct_user.name as class_teacher_name
                     FROM users u
                     LEFT JOIN student_profiles sp ON u.id = sp.user_id
                     LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                     LEFT JOIN classes c ON sc.class_id = c.id
                     LEFT JOIN users ct_user ON c.main_teacher_id = ct_user.id
                     WHERE u.id = :user_id";
    $student_stmt = $db->prepare($student_query);
    $student_stmt->bindParam(':user_id', $user_id);
    $student_stmt->execute();
    $student_info = $student_stmt->fetch(PDO::FETCH_ASSOC);

    // Get classmates — other active students in the SAME class only. The class_id
    // comes from the student's own active enrollment, so no other class roster is
    // ever exposed.
    $classmates = [];
    if (!empty($student_info['class_id'])) {
        $mates_stmt = $db->prepare("SELECT u.name, u.profile_picture
            FROM student_classes sc
            JOIN users u ON sc.student_id = u.id
            WHERE sc.class_id = :class_id AND sc.status = 'active'
              AND u.role = 'student' AND u.status = 'active' AND u.id != :user_id
            ORDER BY u.name ASC");
        $mates_stmt->execute([':class_id' => $student_info['class_id'], ':user_id' => $user_id]);
        $classmates = $mates_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Get enrolled classes with subjects and teachers
    $classes_query = "SELECT DISTINCT
        c.id as class_id,
        c.name as class_name,
        c.grade_level,
        c.academic_year,
        s.id as subject_id,
        s.name as subject_name,
        s.code as subject_code,
        s.description as subject_description,
        t.name as teacher_name,
        t.email as teacher_email,
        ct.id as class_teacher_id
    FROM student_classes sc
    JOIN classes c ON sc.class_id = c.id
    LEFT JOIN class_teachers ct ON c.id = ct.class_id
    LEFT JOIN subjects s ON ct.subject_id = s.id
    LEFT JOIN users t ON ct.teacher_id = t.id
    WHERE sc.student_id = :user_id AND sc.status = 'active'
    ORDER BY s.name";
    
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':user_id', $user_id);
    $classes_stmt->execute();
    $class_subjects = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get recent assignments for student's classes
    $assignments_query = "SELECT 
        a.id,
        a.title,
        a.description,
        a.due_date,
        a.total_marks,
        s.name as subject_name,
        c.name as class_name,
        sa.grade,
        sa.submitted_at,
        CASE 
            WHEN sa.id IS NOT NULL THEN 'submitted'
            WHEN a.due_date < NOW() THEN 'overdue'
            ELSE 'pending'
        END as status
    FROM assignments a
    JOIN student_classes sc ON a.class_id = sc.class_id
    JOIN classes c ON a.class_id = c.id
    LEFT JOIN subjects s ON a.subject_id = s.id
    LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :user_id
    WHERE sc.student_id = :user_id AND sc.status = 'active'
    ORDER BY a.due_date ASC
    LIMIT 5";
    
    $assignments_stmt = $db->prepare($assignments_query);
    $assignments_stmt->bindParam(':user_id', $user_id);
    $assignments_stmt->execute();
    $recent_assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming exams
    $exams_query = "SELECT 
        e.id,
        e.title,
        e.exam_date,
        e.start_time,
        e.end_time,
        e.total_marks,
        s.name as subject_name,
        c.name as class_name
    FROM exams e
    JOIN student_classes sc ON e.class_id = sc.class_id
    JOIN classes c ON e.class_id = c.id
    LEFT JOIN subjects s ON e.subject_id = s.id
    WHERE sc.student_id = :user_id 
    AND sc.status = 'active'
    AND e.exam_date >= CURDATE()
    ORDER BY e.exam_date ASC
    LIMIT 5";
    
    $exams_stmt = $db->prepare($exams_query);
    $exams_stmt->bindParam(':user_id', $user_id);
    $exams_stmt->execute();
    $upcoming_exams = $exams_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate statistics
    $total_subjects = count(array_unique(array_column($class_subjects, 'subject_id')));
    $pending_assignments = count(array_filter($recent_assignments, function($assignment) {
        return $assignment['status'] === 'pending';
    }));
    $overdue_assignments = count(array_filter($recent_assignments, function($assignment) {
        return $assignment['status'] === 'overdue';
    }));

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Helper function to get assignment status color
function getAssignmentStatusColor($status) {
    switch ($status) {
        case 'submitted': return 'text-green-600 bg-green-100';
        case 'pending': return 'text-blue-600 bg-blue-100';
        case 'overdue': return 'text-red-600 bg-red-100';
        default: return 'text-gray-600 bg-gray-100';
    }
}

// Helper function to get assignment status icon
function getAssignmentStatusIcon($status) {
    switch ($status) {
        case 'submitted': return 'fa-check-circle';
        case 'pending': return 'fa-clock';
        case 'overdue': return 'fa-exclamation-triangle';
        default: return 'fa-question-circle';
    }
}

$title = "My Classes";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Title -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-4 mb-8 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-chalkboard mr-3"></i>
                                My Classes
                            </h1>
                            <p class="text-blue-100">View your enrolled classes, subjects, and academic information</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold"><?= $total_subjects ?></div>
                            <div class="text-sm text-blue-100">Total Subjects</div>
                        </div>
                    </div>
                </div>

                    <!-- Student Info Card -->
                    <?php if ($student_info): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-2xl"></i>
                            </div>
                            <div class="ml-6">
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($student_info['name']) ?></h2>
                                <p class="text-gray-600 dark:text-gray-400">Student ID: <?= htmlspecialchars($student_info['student_id'] ?? 'N/A') ?></p>
                                <p class="text-gray-600 dark:text-gray-400">Class: <?= htmlspecialchars($student_info['grade_level'] . ' - ' . $student_info['class_name']) ?></p>
                                <p class="text-gray-600 dark:text-gray-400">Class Teacher: <?= htmlspecialchars($student_info['class_teacher_name'] ?? 'Not assigned') ?></p>
                                <p class="text-gray-600 dark:text-gray-400">Academic Year: <?= htmlspecialchars($student_info['academic_year'] ?? 'N/A') ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Statistics Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-book text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Subjects</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $total_subjects ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-tasks text-yellow-600 dark:text-yellow-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending Assignments</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $pending_assignments ?></p>
                                </div>
                            </div>
                        </div>

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
                                </div>
                                <div class="ml-4">
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Overdue Assignments</p>
                                    <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= $overdue_assignments ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- My Subjects -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-book-open mr-2"></i>
                                My Subjects
                            </h2>
                        </div>

                        <?php if (empty($class_subjects)): ?>
                            <div class="p-8 text-center">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-book text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Subjects Found</h3>
                                <p class="text-gray-600 dark:text-gray-400">You are not enrolled in any subjects yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 p-6">
                                <?php
                                $unique_subjects = [];
                                foreach ($class_subjects as $subject) {
                                    if ($subject['subject_id'] && !isset($unique_subjects[$subject['subject_id']])) {
                                        $unique_subjects[$subject['subject_id']] = $subject;
                                    }
                                }
                                foreach ($unique_subjects as $subject): ?>
                                    <div class="bg-gradient-to-br from-blue-50 to-purple-50 dark:from-blue-900/20 dark:to-purple-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-700 hover:shadow-lg transition-shadow">
                                        <div class="flex items-start justify-between mb-4">
                                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                                <i class="fas fa-book text-blue-600 dark:text-blue-400 text-xl"></i>
                                            </div>
                                            <span class="px-2 py-1 text-xs font-medium bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 rounded-full">
                                                <?= htmlspecialchars($subject['subject_code']) ?>
                                            </span>
                                        </div>

                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
                                            <?= htmlspecialchars($subject['subject_name']) ?>
                                        </h3>

                                        <?php if ($subject['subject_description']): ?>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                                <?= htmlspecialchars(substr($subject['subject_description'], 0, 100)) ?>
                                                <?= strlen($subject['subject_description']) > 100 ? '...' : '' ?>
                                            </p>
                                        <?php endif; ?>

                                        <div class="flex items-center text-sm text-gray-600 dark:text-gray-400">
                                            <i class="fas fa-user-tie mr-2"></i>
                                            <span><?= htmlspecialchars($subject['teacher_name'] ?? 'No teacher assigned') ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- My Classmates -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-users mr-2"></i>
                                My Classmates
                            </h2>
                            <span class="px-3 py-1 text-sm font-medium bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-400 rounded-full">
                                <?= count($classmates) ?>
                            </span>
                        </div>

                        <?php if (empty($classmates)): ?>
                            <div class="p-8 text-center">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-users text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Classmates Found</h3>
                                <p class="text-gray-600 dark:text-gray-400">There are no other students enrolled in your class yet.</p>
                            </div>
                        <?php else: ?>
                            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4 p-6">
                                <?php foreach ($classmates as $mate): ?>
                                    <div class="flex flex-col items-center text-center p-4 rounded-xl bg-gray-50 dark:bg-gray-700/40 border border-gray-100 dark:border-gray-700">
                                        <div class="w-14 h-14 rounded-full overflow-hidden bg-blue-100 dark:bg-blue-900 flex items-center justify-center mb-2">
                                            <?php if (!empty($mate['profile_picture'])): ?>
                                                <img src="/serve_image.php?path=profile_pictures/<?= htmlspecialchars($mate['profile_picture']) ?>"
                                                     alt="<?= htmlspecialchars($mate['name']) ?>" class="w-full h-full object-cover">
                                            <?php else: ?>
                                                <span class="text-blue-600 dark:text-blue-400 font-semibold text-lg">
                                                    <?= htmlspecialchars(strtoupper(substr($mate['name'], 0, 1))) ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate w-full" title="<?= htmlspecialchars($mate['name']) ?>">
                                            <?= htmlspecialchars($mate['name']) ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Recent Assignments and Upcoming Exams -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- Recent Assignments -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    <i class="fas fa-tasks mr-2"></i>
                                    Recent Assignments
                                </h2>
                            </div>

                            <?php if (empty($recent_assignments)): ?>
                                <div class="p-8 text-center">
                                    <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-tasks text-gray-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Assignments</h3>
                                    <p class="text-gray-600 dark:text-gray-400">No assignments found for your classes.</p>
                                </div>
                            <?php else: ?>
                                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($recent_assignments as $assignment): ?>
                                        <?php
                                            $status_color = getAssignmentStatusColor($assignment['status']);
                                            $status_icon = getAssignmentStatusIcon($assignment['status']);
                                        ?>
                                        <div class="p-6 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <div class="flex items-start justify-between">
                                                <div class="flex-1">
                                                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= htmlspecialchars($assignment['title']) ?>
                                                    </h3>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                                        <?= htmlspecialchars($assignment['subject_name']) ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                                        Due: <?= date('M j, Y g:i A', strtotime($assignment['due_date'])) ?>
                                                    </p>
                                                </div>
                                                <span class="px-2 py-1 text-xs font-medium rounded-full <?= $status_color ?>">
                                                    <i class="fas <?= $status_icon ?> mr-1"></i>
                                                    <?= ucfirst($assignment['status']) ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Upcoming Exams -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                    <i class="fas fa-clipboard-list mr-2"></i>
                                    Upcoming Exams
                                </h2>
                            </div>

                            <?php if (empty($upcoming_exams)): ?>
                                <div class="p-8 text-center">
                                    <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-clipboard-list text-gray-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Upcoming Exams</h3>
                                    <p class="text-gray-600 dark:text-gray-400">No exams scheduled for your classes.</p>
                                </div>
                            <?php else: ?>
                                <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($upcoming_exams as $exam): ?>
                                        <div class="p-6 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                            <div class="flex items-start justify-between">
                                                <div class="flex-1">
                                                    <h3 class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?= htmlspecialchars($exam['title']) ?>
                                                    </h3>
                                                    <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                                                        <?= htmlspecialchars($exam['subject_name']) ?>
                                                    </p>
                                                    <p class="text-xs text-gray-500 dark:text-gray-500 mt-1">
                                                        <?= date('M j, Y', strtotime($exam['exam_date'])) ?> •
                                                        <?= date('g:i A', strtotime($exam['start_time'])) ?> -
                                                        <?= date('g:i A', strtotime($exam['end_time'])) ?>
                                                    </p>
                                                </div>
                                                <span class="px-2 py-1 text-xs font-medium bg-purple-100 dark:bg-purple-900 text-purple-600 dark:text-purple-400 rounded-full">
                                                    <?= $exam['total_marks'] ?> marks
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
