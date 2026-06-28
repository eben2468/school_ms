<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'User';

$title = "Quizzes & Tests";
$success_message = '';
$error_message = '';

// Handle creating new quiz (Teacher / Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_quiz' && in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
    $quiz_title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $time_limit = filter_input(INPUT_POST, 'time_limit', FILTER_SANITIZE_NUMBER_INT);
    $attempts_allowed = filter_input(INPUT_POST, 'attempts_allowed', FILTER_SANITIZE_NUMBER_INT);
    $start_date = filter_input(INPUT_POST, 'start_date', FILTER_DEFAULT);
    $end_date = filter_input(INPUT_POST, 'end_date', FILTER_DEFAULT);
    $show_results = isset($_POST['show_results']) ? 1 : 0;
    $randomize_questions = isset($_POST['randomize_questions']) ? 1 : 0;
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'draft';

    if ($quiz_title && $start_date && $end_date) {
        try {
            $query = "INSERT INTO online_quizzes (title, description, teacher_id, class_id, subject_id, total_questions, total_marks, time_limit_minutes, attempts_allowed, start_date, end_date, show_results, randomize_questions, status, created_at)
                      VALUES (:title, :description, :teacher_id, :class_id, :subject_id, 0, 0, :time_limit, :attempts_allowed, :start_date, :end_date, :show_results, :randomize_questions, :status, NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':title' => $quiz_title,
                ':description' => $description ?: null,
                ':teacher_id' => $user_id,
                ':class_id' => $class_id ?: null,
                ':subject_id' => $subject_id ?: null,
                ':time_limit' => $time_limit ?: null,
                ':attempts_allowed' => $attempts_allowed ?: 1,
                ':start_date' => $start_date,
                ':end_date' => $end_date,
                ':show_results' => $show_results,
                ':randomize_questions' => $randomize_questions,
                ':status' => $status
            ]);
            $success_message = "Quiz created successfully! You can now add questions to it.";
        } catch (PDOException $e) {
            $error_message = "Error creating quiz: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Handle deleting quiz (Teacher / Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_quiz' && in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
    $quiz_id = filter_input(INPUT_POST, 'quiz_id', FILTER_SANITIZE_NUMBER_INT);
    if ($quiz_id) {
        try {
            $db->beginTransaction();
            
            // Find attempts
            $att_query = "SELECT id FROM quiz_attempts WHERE quiz_id = :quiz_id";
            $att_stmt = $db->prepare($att_query);
            $att_stmt->execute([':quiz_id' => $quiz_id]);
            $attempts = $att_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (!empty($attempts)) {
                $placeholders = implode(',', array_fill(0, count($attempts), '?'));
                $del_ans = $db->prepare("DELETE FROM quiz_answers WHERE attempt_id IN ($placeholders)");
                $del_ans->execute($attempts);
            }
            
            $del_attempts = $db->prepare("DELETE FROM quiz_attempts WHERE quiz_id = :quiz_id");
            $del_attempts->execute([':quiz_id' => $quiz_id]);
            
            $del_questions = $db->prepare("DELETE FROM quiz_questions WHERE quiz_id = :quiz_id");
            $del_questions->execute([':quiz_id' => $quiz_id]);
            
            $del_quiz = $db->prepare("DELETE FROM online_quizzes WHERE id = :quiz_id");
            $del_quiz->execute([':quiz_id' => $quiz_id]);
            
            $db->commit();
            $success_message = "Quiz deleted successfully!";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = "Error deleting quiz: " . $e->getMessage();
        }
    }
}

// Fetch classes and subjects for creation modal
$classes = [];
$subjects = [];
if (in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
    $classes_query = "SELECT id, name FROM classes ORDER BY name";
    $classes_stmt = $db->query($classes_query);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

    $subjects_query = "SELECT id, name, class_id FROM subjects ORDER BY name";
    $subjects_stmt = $db->query($subjects_query);
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch quizzes list based on role
$quizzes = [];
$attempts_by_quiz = [];

if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    // Teacher / admin view
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        $quizzes_query = "SELECT q.*, c.name as class_name, s.name as subject_name, u.name as teacher_name
                          FROM online_quizzes q
                          LEFT JOIN classes c ON q.class_id = c.id
                          LEFT JOIN subjects s ON q.subject_id = s.id
                          LEFT JOIN users u ON q.teacher_id = u.id
                          ORDER BY q.created_at DESC";
        $quizzes_stmt = $db->prepare($quizzes_query);
        $quizzes_stmt->execute();
    } else {
        $quizzes_query = "SELECT q.*, c.name as class_name, s.name as subject_name
                          FROM online_quizzes q
                          LEFT JOIN classes c ON q.class_id = c.id
                          LEFT JOIN subjects s ON q.subject_id = s.id
                          WHERE q.teacher_id = :teacher_id
                          ORDER BY q.created_at DESC";
        $quizzes_stmt = $db->prepare($quizzes_query);
        $quizzes_stmt->execute([':teacher_id' => $user_id]);
    }
    $quizzes = $quizzes_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    // Student view
    $class_query = "SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->execute([':student_id' => $user_id]);
    $student_class_id = $class_stmt->fetchColumn();

    if ($student_class_id) {
        $quizzes_query = "SELECT q.*, s.name as subject_name, u.name as teacher_name
                          FROM online_quizzes q
                          LEFT JOIN subjects s ON q.subject_id = s.id
                          LEFT JOIN users u ON q.teacher_id = u.id
                          WHERE q.class_id = :class_id AND q.status = 'published'
                          ORDER BY q.start_date DESC";
        $quizzes_stmt = $db->prepare($quizzes_query);
        $quizzes_stmt->execute([':class_id' => $student_class_id]);
        $quizzes = $quizzes_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch user attempts
        if (!empty($quizzes)) {
            $quiz_ids = array_column($quizzes, 'id');
            $placeholders = implode(',', array_fill(0, count($quiz_ids), '?'));
            
            $attempts_query = "SELECT quiz_id, id, attempt_number, total_marks_obtained, percentage, status 
                               FROM quiz_attempts 
                               WHERE student_id = ? AND quiz_id IN ($placeholders) 
                               ORDER BY attempt_number DESC";
            
            $attempts_stmt = $db->prepare($attempts_query);
            $params = array_merge([$user_id], $quiz_ids);
            $attempts_stmt->execute($params);
            $all_attempts = $attempts_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($all_attempts as $att) {
                $attempts_by_quiz[$att['quiz_id']][] = $att;
            }
        }
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
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
                <!-- Header -->
                <div class="mb-8">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Quizzes & Tests</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Manage and complete timed online assessments.</p>
                        </div>
                        <div class="flex flex-row items-center gap-3">
                            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200 whitespace-nowrap flex-shrink-0 inline-flex items-center">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Learning
                            </a>
                            <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                            <button onclick="showCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 whitespace-nowrap flex-shrink-0 inline-flex items-center">
                                <i class="fas fa-plus mr-2"></i>Create Quiz
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-green-100 border border-green-200 text-green-800 dark:bg-green-900 dark:border-green-800 dark:text-green-200">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-100 border border-red-200 text-red-800 dark:bg-red-900 dark:border-red-800 dark:text-red-200">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- Quizzes List Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Active Quizzes</h2>
                    </div>

                    <div class="p-6">
                        <?php if (empty($quizzes)): ?>
                            <div class="text-center py-12">
                                <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-question-circle text-blue-600 dark:text-blue-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No Quizzes Available</h3>
                                <p class="text-gray-500 dark:text-gray-400">There are currently no quizzes assigned or created.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Quiz Details</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Class / Subject</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Time Limit / Marks</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Availability</th>
                                            <?php if ($role === 'student'): ?>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Your Progress</th>
                                            <?php else: ?>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                            <?php endif; ?>
                                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($quizzes as $quiz): ?>
                                            <tr>
                                                <td class="px-6 py-4">
                                                    <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></div>
                                                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 max-w-xs truncate"><?php echo htmlspecialchars($quiz['description'] ?? 'No description'); ?></div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                                    <?php if ($role === 'student'): ?>
                                                        <span class="px-2 py-1 bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200 rounded text-xs font-medium">
                                                            <?php echo htmlspecialchars($quiz['subject_name'] ?? 'General'); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <div class="text-xs font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($quiz['class_name'] ?? 'All Classes'); ?></div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5"><?php echo htmlspecialchars($quiz['subject_name'] ?? 'General'); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                                    <div class="text-xs"><i class="fas fa-clock mr-1 text-gray-400"></i><?php echo $quiz['time_limit_minutes'] ? $quiz['time_limit_minutes'] . " mins" : "No limit"; ?></div>
                                                    <div class="text-xs mt-1"><i class="fas fa-star mr-1 text-yellow-500"></i><?php echo $quiz['total_marks']; ?> Marks (<?php echo $quiz['total_questions']; ?> Qs)</div>
                                                </td>
                                                <td class="px-6 py-4 text-xs text-gray-700 dark:text-gray-300">
                                                    <div class="font-medium text-green-600 dark:text-green-400">Start: <?php echo date('M j, Y H:i', strtotime($quiz['start_date'])); ?></div>
                                                    <div class="text-red-500 dark:text-red-400 mt-1 font-medium">Due: <?php echo date('M j, Y H:i', strtotime($quiz['end_date'])); ?></div>
                                                </td>
                                                
                                                <?php if ($role === 'student'): ?>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php
                                                        $attempts = $attempts_by_quiz[$quiz['id']] ?? [];
                                                        $attempts_count = count($attempts);
                                                        $allowed = $quiz['attempts_allowed'] ?: 1;
                                                        
                                                        // Get highest percentage
                                                        $best_percentage = null;
                                                        $has_in_progress = false;
                                                        $in_progress_attempt_id = null;
                                                        
                                                        foreach ($attempts as $att) {
                                                            if ($att['status'] === 'in_progress') {
                                                                $has_in_progress = true;
                                                                $in_progress_attempt_id = $att['id'];
                                                            }
                                                            if ($att['percentage'] !== null && ($best_percentage === null || $att['percentage'] > $best_percentage)) {
                                                                $best_percentage = $att['percentage'];
                                                            }
                                                        }
                                                        ?>
                                                        <div class="text-xs text-gray-700 dark:text-gray-300">
                                                            Attempts: <span class="font-semibold"><?php echo $attempts_count; ?> / <?php echo $quiz['attempts_allowed'] ?: 'Unlimited'; ?></span>
                                                        </div>
                                                        <?php if ($best_percentage !== null): ?>
                                                            <div class="text-xs mt-1 text-green-600 dark:text-green-400 font-semibold">
                                                                Best Score: <?php echo round($best_percentage, 1); ?>%
                                                            </div>
                                                        <?php endif; ?>
                                                        <?php if ($has_in_progress): ?>
                                                            <span class="inline-flex items-center mt-1.5 px-2 py-0.5 rounded text-xs font-semibold bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200 animate-pulse">
                                                                In Progress
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php else: ?>
                                                    <td class="px-6 py-4 whitespace-nowrap">
                                                        <?php if ($quiz['status'] === 'published'): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200">
                                                                Published
                                                            </span>
                                                        <?php elseif ($quiz['status'] === 'draft'): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-950 dark:text-yellow-200">
                                                                Draft
                                                            </span>
                                                        <?php elseif ($quiz['status'] === 'completed'): ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-950 dark:text-blue-200">
                                                                Completed
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-400">
                                                                Archived
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                <?php endif; ?>

                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                    <?php if ($role === 'student'): ?>
                                                        <?php
                                                        $now = time();
                                                        $start_ts = strtotime($quiz['start_date']);
                                                        $end_ts = strtotime($quiz['end_date']);
                                                        $attempts = $attempts_by_quiz[$quiz['id']] ?? [];
                                                        $attempts_count = count($attempts);
                                                        $allowed = $quiz['attempts_allowed'] ?: 1;
                                                        
                                                        $has_in_progress = false;
                                                        foreach ($attempts as $att) {
                                                            if ($att['status'] === 'in_progress') {
                                                                $has_in_progress = true;
                                                            }
                                                        }
                                                        
                                                        if ($now < $start_ts): ?>
                                                            <button disabled class="text-xs bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-3 py-1.5 rounded-lg cursor-not-allowed">
                                                                Not Started
                                                            </button>
                                                        <?php elseif ($now > $end_ts && !$has_in_progress): ?>
                                                            <button disabled class="text-xs bg-red-100 dark:bg-red-950 text-red-500 dark:text-red-400 px-3 py-1.5 rounded-lg cursor-not-allowed">
                                                                Closed
                                                            </button>
                                                        <?php elseif ($has_in_progress): ?>
                                                            <a href="quiz_take.php?quiz_id=<?php echo $quiz['id']; ?>" class="text-xs bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-1.5 rounded-lg transition-colors inline-block">
                                                                Resume Quiz
                                                            </a>
                                                        <?php elseif ($quiz['attempts_allowed'] && $attempts_count >= $quiz['attempts_allowed']): ?>
                                                            <button disabled class="text-xs bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 px-3 py-1.5 rounded-lg cursor-not-allowed">
                                                                Max Attempts
                                                            </button>
                                                        <?php else: ?>
                                                            <a href="quiz_take.php?quiz_id=<?php echo $quiz['id']; ?>" onclick="return confirm('Start timed attempt? Do not refresh or exit once started.');" class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg transition-colors inline-block">
                                                                Start Quiz
                                                            </a>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($attempts_count > 0 && $quiz['show_results']): ?>
                                                            <a href="quiz_attempts.php?quiz_id=<?php echo $quiz['id']; ?>" class="text-xs bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-3 py-1.5 rounded-lg transition-colors inline-block ml-1">
                                                                View Results
                                                            </a>
                                                        <?php endif; ?>

                                                    <?php else: ?>
                                                        <!-- Teacher Actions -->
                                                        <div class="flex items-center justify-end space-x-2">
                                                            <a href="quiz_manage.php?quiz_id=<?php echo $quiz['id']; ?>" class="text-xs bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:hover:bg-blue-800 text-blue-800 dark:text-blue-200 px-2.5 py-1.5 rounded-lg transition-colors">
                                                                Questions (<?php echo $quiz['total_questions']; ?>)
                                                            </a>
                                                            <a href="quiz_attempts.php?quiz_id=<?php echo $quiz['id']; ?>" class="text-xs bg-green-100 hover:bg-green-200 dark:bg-green-900 dark:hover:bg-green-800 text-green-800 dark:text-green-200 px-2.5 py-1.5 rounded-lg transition-colors">
                                                                Attempts
                                                            </a>
                                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this quiz? This action will permanently remove all related questions, attempts, and grades.');">
                                                                <input type="hidden" name="action" value="delete_quiz">
                                                                <input type="hidden" name="quiz_id" value="<?php echo $quiz['id']; ?>">
                                                                <button type="submit" class="text-red-600 hover:text-red-900 dark:hover:text-red-400 p-1">
                                                                    <i class="fas fa-trash"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Create Quiz Modal (Teacher / Admin) -->
<?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
<div id="create-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Create New Quiz / Test</h3>
                <button onclick="hideCreateModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            
            <form method="POST">
                <input type="hidden" name="action" value="create_quiz">
                <div class="p-6 space-y-6" style="max-height: 60vh; overflow-y: auto;">
                    <!-- Title -->
                    <div>
                        <label for="quiz_title_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Quiz Title *</label>
                        <input type="text" id="quiz_title_input" name="title" required placeholder="e.g. Midterm Physics Exam"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea id="description_input" name="description" rows="3" placeholder="Provide exam instructions, material scope, etc."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <!-- Class & Subject -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="class_id_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Assign to Class *</label>
                            <select id="class_id_input" name="class_id" required
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select a Class</option>
                                <?php foreach ($classes as $cls): ?>
                                    <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="subject_id_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Subject *</label>
                            <select id="subject_id_input" name="subject_id" required
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select a Subject</option>
                                <?php foreach ($subjects as $sub): ?>
                                    <option value="<?php echo $sub['id']; ?>" data-class-id="<?php echo $sub['class_id']; ?>"><?php echo htmlspecialchars($sub['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Time Limit & Attempts -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="time_limit_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Time Limit (Minutes)</label>
                            <input type="number" id="time_limit_input" name="time_limit" min="1" placeholder="e.g. 45 (Leave empty for no limit)"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label for="attempts_allowed_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Attempts Allowed</label>
                            <input type="number" id="attempts_allowed_input" name="attempts_allowed" min="1" value="1" placeholder="e.g. 1 (Leave empty for unlimited)"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>

                    <!-- Start & End Date -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="start_date_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Start Date & Time *</label>
                            <input type="datetime-local" id="start_date_input" name="start_date" required
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label for="end_date_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">End Date & Time *</label>
                            <input type="datetime-local" id="end_date_input" name="end_date" required
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                    </div>

                    <!-- Settings Checkboxes -->
                    <div class="flex flex-col space-y-3 pt-2">
                        <label class="inline-flex items-center text-sm font-medium text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="show_results" checked value="1"
                                   class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                            Show Results & Correct Answers to Students Immediately After Submission
                        </label>
                        <label class="inline-flex items-center text-sm font-medium text-gray-700 dark:text-gray-300">
                            <input type="checkbox" name="randomize_questions" value="1"
                                   class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50 mr-2">
                            Randomize Question Order for Each Student Attempt
                        </label>
                    </div>

                    <!-- Status -->
                    <div>
                        <label for="status_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status</label>
                        <select id="status_input" name="status"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="draft">Draft (Hidden from students)</option>
                            <option value="published">Published (Visible and active based on dates)</option>
                        </select>
                    </div>
                </div>

                <div class="p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-3 bg-gray-50 dark:bg-gray-900 rounded-b-xl">
                    <button type="button" onclick="hideCreateModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <i class="fas fa-plus mr-2"></i>Create Quiz
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showCreateModal() {
    const modal = document.getElementById('create-modal');
    if (modal) modal.classList.remove('hidden');
}

function hideCreateModal() {
    const modal = document.getElementById('create-modal');
    if (modal) modal.classList.add('hidden');
}

function setupDynamicSubjectFilter(classSelectId, subjectSelectId) {
    const classSelect = document.getElementById(classSelectId);
    const subjectSelect = document.getElementById(subjectSelectId);
    if (!classSelect || !subjectSelect) return;

    const originalOptions = Array.from(subjectSelect.options).map(opt => ({
        value: opt.value,
        text: opt.text,
        classId: opt.getAttribute('data-class-id')
    }));

    function updateSubjects() {
        const selectedClassId = classSelect.value;
        const currentSelectedValue = subjectSelect.value;
        
        subjectSelect.innerHTML = '';
        
        originalOptions.forEach(opt => {
            if (!selectedClassId || !opt.classId || opt.classId == selectedClassId || opt.value === '') {
                const newOpt = document.createElement('option');
                newOpt.value = opt.value;
                newOpt.textContent = opt.text;
                if (opt.classId) {
                    newOpt.setAttribute('data-class-id', opt.classId);
                }
                if (opt.value === currentSelectedValue) {
                    newOpt.selected = true;
                }
                subjectSelect.appendChild(newOpt);
            }
        });
    }

    classSelect.addEventListener('change', updateSubjects);
    updateSubjects();
}

document.addEventListener('DOMContentLoaded', function() {
    setupDynamicSubjectFilter('class_id_input', 'subject_id_input');
});
</script>
