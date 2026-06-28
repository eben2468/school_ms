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
$user_name = $_SESSION['name'] ?? 'User';

$quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_SANITIZE_NUMBER_INT);
$attempt_id = filter_input(INPUT_GET, 'attempt_id', FILTER_SANITIZE_NUMBER_INT);

$success_message = '';
$error_message = '';

// Check if user came from redirection
if (isset($_GET['submitted'])) {
    $success_message = "Quiz submitted successfully!";
}
if (isset($_GET['timed_out'])) {
    $success_message = "Quiz time limit reached. Your attempt was automatically submitted.";
}

// POST Handle Essay Grading (Teacher / Admin only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'grade_attempt') {
    if (!in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
        $error_message = "Unauthorized action.";
    } else {
        $target_attempt_id = filter_input(INPUT_POST, 'attempt_id', FILTER_SANITIZE_NUMBER_INT);
        $marks_input = $_POST['marks'] ?? []; // Array mapping answer_id to marks obtained

        if ($target_attempt_id && !empty($marks_input)) {
            try {
                $db->beginTransaction();

                foreach ($marks_input as $ans_id => $marks) {
                    $marks = floatval($marks);
                    
                    // Get question info to cap the grade
                    $q_info_query = "SELECT q.marks 
                                     FROM quiz_answers qa 
                                     JOIN quiz_questions q ON qa.question_id = q.id 
                                     WHERE qa.id = :ans_id";
                    $q_stmt = $db->prepare($q_info_query);
                    $q_stmt->execute([':ans_id' => $ans_id]);
                    $max_marks = floatval($q_stmt->fetchColumn() ?: 1);

                    if ($marks > $max_marks) {
                        $marks = $max_marks;
                    }
                    if ($marks < 0) {
                        $marks = 0;
                    }

                    $is_correct = ($marks >= ($max_marks / 2)) ? 1 : 0;

                    $update_ans_query = "UPDATE quiz_answers SET marks_obtained = :marks, is_correct = :is_correct WHERE id = :ans_id";
                    $ans_stmt = $db->prepare($update_ans_query);
                    $ans_stmt->execute([
                        ':marks' => $marks,
                        ':is_correct' => $is_correct,
                        ':ans_id' => $ans_id
                    ]);
                }

                // Recalculate overall attempt marks obtained
                $sum_query = "SELECT SUM(marks_obtained) FROM quiz_answers WHERE attempt_id = :attempt_id";
                $sum_stmt = $db->prepare($sum_query);
                $sum_stmt->execute([':attempt_id' => $target_attempt_id]);
                $new_obtained = floatval($sum_stmt->fetchColumn() ?: 0);

                // Get attempt's quiz id to fetch total possible marks
                $quiz_info_query = "SELECT q.total_marks, q.id as quiz_id, att.student_id 
                                    FROM quiz_attempts att 
                                    JOIN online_quizzes q ON att.quiz_id = q.id 
                                    WHERE att.id = :attempt_id";
                $quiz_info_stmt = $db->prepare($quiz_info_query);
                $quiz_info_stmt->execute([':attempt_id' => $target_attempt_id]);
                $quiz_info = $quiz_info_stmt->fetch(PDO::FETCH_ASSOC);

                $total_possible = floatval($quiz_info['total_marks'] ?: 1);
                if ($total_possible <= 0) $total_possible = 1;
                $new_percentage = ($new_obtained / $total_possible) * 100;

                // Update attempt
                $update_att_query = "UPDATE quiz_attempts SET total_marks_obtained = :obtained, percentage = :percentage WHERE id = :attempt_id";
                $att_stmt = $db->prepare($update_att_query);
                $att_stmt->execute([
                    ':obtained' => $new_obtained,
                    ':percentage' => $new_percentage,
                    ':attempt_id' => $target_attempt_id
                ]);

                // Notify Student
                $notif_title = "Quiz Graded";
                $notif_msg = "Your quiz attempt has been graded. New Score: " . round($new_percentage, 1) . "%";
                $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                                            VALUES (:user_id, :title, :message, 'info', 0, NOW())");
                $notif_stmt->execute([
                    ':user_id' => $quiz_info['student_id'],
                    ':title' => $notif_title,
                    ':message' => $notif_msg
                ]);

                $db->commit();
                $success_message = "Attempt graded successfully!";
                $attempt_id = $target_attempt_id; // Show details of graded attempt
            } catch (Exception $e) {
                $db->rollBack();
                $error_message = "Error saving grades: " . $e->getMessage();
            }
        }
    }
}

// DETAIL VIEW
if ($attempt_id) {
    try {
        // Fetch attempt details
        $attempt_query = "SELECT att.*, q.title as quiz_title, q.description as quiz_desc, q.show_results, q.total_marks as quiz_total_marks,
                                 u.name as student_name, s.name as subject_name
                          FROM quiz_attempts att
                          JOIN online_quizzes q ON att.quiz_id = q.id
                          JOIN users u ON att.student_id = u.id
                          LEFT JOIN subjects s ON q.subject_id = s.id
                          WHERE att.id = :id";
        $stmt = $db->prepare($attempt_query);
        $stmt->execute([':id' => $attempt_id]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$attempt) {
            header("Location: quizzes.php");
            exit();
        }

        // Restrict Access: Students can only view their own attempts, and only if show_results is enabled
        if ($role === 'student') {
            if ($attempt['student_id'] != $user_id) {
                header("Location: quizzes.php?error=unauthorized_attempt");
                exit();
            }
            if (!$attempt['show_results']) {
                $error_message = "Results are hidden for this quiz by the instructor.";
            }
        }

        // Fetch question responses for this attempt
        $answers_query = "SELECT qa.*, q.question_text, q.question_type, q.marks as question_max_marks, q.explanation,
                                 q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer
                          FROM quiz_answers qa
                          JOIN quiz_questions q ON qa.question_id = q.id
                          WHERE qa.attempt_id = :attempt_id
                          ORDER BY q.question_order ASC";
        $stmt = $db->prepare($answers_query);
        $stmt->execute([':attempt_id' => $attempt_id]);
        $answers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        die("Error fetching details: " . $e->getMessage());
    }
} elseif ($quiz_id) {
    // ATTEMPTS LIST VIEW FOR A SPECIFIC QUIZ
    try {
        $quiz_query = "SELECT q.*, s.name as subject_name FROM online_quizzes q LEFT JOIN subjects s ON q.subject_id = s.id WHERE q.id = :id";
        $stmt = $db->prepare($quiz_query);
        $stmt->execute([':id' => $quiz_id]);
        $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$quiz) {
            header("Location: quizzes.php");
            exit();
        }

        // A student may only view the attempts page for a quiz assigned to their
        // own active class, so quiz metadata for other classes is not exposed.
        if ($role === 'student') {
            $own_chk = $db->prepare("SELECT 1 FROM student_classes WHERE student_id = :sid AND class_id = :cid AND status = 'active'");
            $own_chk->execute([':sid' => $user_id, ':cid' => $quiz['class_id']]);
            if (!$own_chk->fetchColumn()) {
                header("Location: quizzes.php?error=unauthorized_quiz");
                exit();
            }
        }

        // Load attempts
        if ($role === 'student') {
            // Student list
            $list_query = "SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id ORDER BY attempt_number DESC";
            $stmt = $db->prepare($list_query);
            $stmt->execute([':quiz_id' => $quiz_id, ':student_id' => $user_id]);
        } else {
            // Teacher / Admin list
            $list_query = "SELECT att.*, u.name as student_name 
                           FROM quiz_attempts att
                           JOIN users u ON att.student_id = u.id
                           WHERE att.quiz_id = :quiz_id 
                           ORDER BY att.percentage DESC, att.start_time DESC";
            $stmt = $db->prepare($list_query);
            $stmt->execute([':quiz_id' => $quiz_id]);
        }
        $attempts_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        die("Error loading attempts list: " . $e->getMessage());
    }
} else {
    // Redirect if no parameters provided
    header("Location: quizzes.php");
    exit();
}

$title = $attempt_id ? "Attempt Review - " . htmlspecialchars($attempt['quiz_title']) : "Attempts Summary";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-4xl mx-auto">
                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-green-100 border border-green-200 text-green-800 dark:bg-green-900 dark:border-green-800 dark:text-green-200">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-100 border border-red-200 text-red-800 dark:bg-red-900/30 dark:border-red-800 dark:text-red-200">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($attempt_id && (!$error_message || $role !== 'student')): ?>
                    <!-- SINGLE ATTEMPT REVIEW DETAIL VIEW -->
                    <div class="mb-6">
                        <a href="quiz_attempts.php?quiz_id=<?php echo $attempt['quiz_id']; ?>" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:underline">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Attempts List
                        </a>
                    </div>

                    <!-- Attempt Header Information Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 mb-6">
                        <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                            <div>
                                <span class="bg-indigo-50 dark:bg-indigo-900/30 text-indigo-800 dark:text-indigo-200 text-xs font-semibold px-2.5 py-1 rounded">
                                    <?php echo htmlspecialchars($attempt['subject_name']); ?>
                                </span>
                                <h1 class="text-2xl font-bold text-gray-900 dark:text-white mt-2"><?php echo htmlspecialchars($attempt['quiz_title']); ?> Review</h1>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Student: <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($attempt['student_name']); ?></span> (Attempt #<?php echo $attempt['attempt_number']; ?>)</p>
                                <p class="text-xs text-gray-400 mt-1">Started: <?php echo date('M d, Y g:i A', strtotime($attempt['start_time'])); ?> • Completed: <?php echo $attempt['end_time'] ? date('M d, Y g:i A', strtotime($attempt['end_time'])) : 'N/A'; ?></p>
                            </div>
                            
                            <!-- Attempt Score badge -->
                            <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-center min-w-[150px] shadow-sm">
                                <span class="text-xs text-blue-600 dark:text-blue-400 font-bold block mb-1">Score Obtained</span>
                                <span class="text-3xl font-bold text-blue-700 dark:text-blue-300">
                                    <?php echo round($attempt['percentage'], 1); ?>%
                                </span>
                                <span class="text-xs text-gray-500 dark:text-gray-400 block mt-1"><?php echo $attempt['total_marks_obtained']; ?> / <?php echo $attempt['quiz_total_marks']; ?> Marks</span>
                            </div>
                        </div>
                    </div>

                    <!-- Responses & Grading form -->
                    <form method="POST">
                        <input type="hidden" name="action" value="grade_attempt">
                        <input type="hidden" name="attempt_id" value="<?php echo $attempt_id; ?>">
                        
                        <div class="space-y-6 mb-8">
                            <?php foreach ($answers as $index => $ans): 
                                $is_essay = ($ans['question_type'] === 'essay');
                                $bg_class = 'border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800';
                                $badge_text = '';
                                $badge_class = '';

                                if (!$is_essay) {
                                    if ($ans['is_correct']) {
                                        $bg_class = 'border-green-400 dark:border-green-900/50 bg-green-50/30 dark:bg-green-950/10';
                                        $badge_text = 'Correct';
                                        $badge_class = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                    } else {
                                        $bg_class = 'border-red-400 dark:border-red-900/50 bg-red-50/30 dark:bg-red-950/10';
                                        $badge_text = 'Incorrect';
                                        $badge_class = 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
                                    }
                                } else {
                                    $bg_class = 'border-yellow-400 dark:border-yellow-900/50 bg-yellow-50/30 dark:bg-yellow-950/10';
                                    $badge_text = 'Essay - Manual Grading';
                                    $badge_class = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
                                }
                            ?>
                                <div class="border-2 <?php echo $bg_class; ?> rounded-xl p-6 shadow-sm">
                                    <div class="flex items-center justify-between mb-4">
                                        <span class="text-xs font-bold text-gray-500 dark:text-gray-400">
                                            Question <?php echo $index + 1; ?> (<?php echo $ans['question_max_marks']; ?> Max Marks)
                                        </span>
                                        <?php if ($badge_text): ?>
                                            <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $badge_class; ?>">
                                                <?php echo $badge_text; ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4"><?php echo nl2br(htmlspecialchars($ans['question_text'])); ?></h3>

                                    <!-- Student Answer Display -->
                                    <div class="mb-4">
                                        <span class="text-xs text-gray-500 dark:text-gray-400 font-bold block mb-1">Student Answer:</span>
                                        <div class="p-3 bg-gray-50 dark:bg-gray-900 rounded-lg text-sm text-gray-800 dark:text-gray-200 font-medium">
                                            <?php echo $ans['student_answer'] !== null ? nl2br(htmlspecialchars($ans['student_answer'])) : '<span class="italic text-gray-400">No response provided</span>'; ?>
                                        </div>
                                    </div>

                                    <!-- Feedback/Answers -->
                                    <?php if (!$is_essay): ?>
                                        <!-- Correct Answer Display -->
                                        <div class="mb-4 text-xs font-semibold text-gray-700 dark:text-gray-300">
                                            <span class="text-gray-500 dark:text-gray-400 block mb-1">Correct Answer:</span>
                                            <?php if ($ans['question_type'] === 'multiple_choice'): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200 rounded">
                                                    Option <?php echo htmlspecialchars($ans['correct_answer']); ?>
                                                </span>
                                            <?php elseif ($ans['question_type'] === 'true_false'): ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200 rounded capitalize">
                                                    <?php echo htmlspecialchars($ans['correct_answer']); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-200 rounded">
                                                    <?php echo htmlspecialchars($ans['correct_answer']); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Explanations -->
                                    <?php if ($ans['explanation'] && $role === 'student'): ?>
                                        <div class="p-3 bg-blue-50/50 dark:bg-blue-950/20 border border-blue-200 dark:border-blue-800 rounded-lg text-xs text-gray-600 dark:text-gray-400">
                                            <span class="font-bold text-blue-700 dark:text-blue-300">Explanation:</span> <?php echo htmlspecialchars($ans['explanation']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- Grading input (Teacher only) -->
                                    <?php if ($is_essay && in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                                        <div class="mt-4 pt-4 border-t border-gray-150 dark:border-gray-700 flex flex-col md:flex-row items-start md:items-center gap-4">
                                            <div class="flex-1">
                                                <span class="text-xs text-gray-500 dark:text-gray-400 font-bold block mb-1">Grading Criteria:</span>
                                                <p class="text-xs text-gray-600 dark:text-gray-400 italic">"<?php echo htmlspecialchars($ans['correct_answer'] ?: 'None'); ?>"</p>
                                            </div>
                                            <div class="flex items-center space-x-2 self-stretch md:self-auto justify-end">
                                                <label for="marks_input_<?php echo $ans['id']; ?>" class="text-sm font-semibold text-gray-700 dark:text-gray-300">Marks Awarded:</label>
                                                <input type="number" id="marks_input_<?php echo $ans['id']; ?>" name="marks[<?php echo $ans['id']; ?>]" value="<?php echo floatval($ans['marks_obtained']); ?>" min="0" max="<?php echo $ans['question_max_marks']; ?>" step="0.5"
                                                       class="w-20 px-2.5 py-1.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white font-bold text-center">
                                                <span class="text-sm text-gray-500">/ <?php echo $ans['question_max_marks']; ?></span>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <!-- Marks Display (For student or auto-graded) -->
                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-2 font-semibold text-right">
                                            Marks Obtained: <span class="text-gray-900 dark:text-white font-bold"><?php echo $ans['marks_obtained']; ?></span> / <?php echo $ans['question_max_marks']; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Grading submit (Teacher only) -->
                        <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                            <div class="flex justify-end space-x-3 mb-12">
                                <a href="quiz_attempts.php?quiz_id=<?php echo $attempt['quiz_id']; ?>" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-5 py-2.5 rounded-lg transition font-medium text-sm">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg transition font-semibold text-sm">
                                    Save Grades & Update Attempt
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>

                <?php elseif ($quiz_id): ?>
                    <!-- ATTEMPTS LIST VIEW FOR A SPECIFIC quiz -->
                    <div class="mb-6">
                        <a href="quizzes.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:underline">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Quizzes List
                        </a>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50">
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                                <?php echo $role === 'student' ? 'My Quiz Attempts' : 'Student Quiz Attempts'; ?>
                            </h2>
                            <p class="text-sm text-gray-500 mt-1">Quiz: <span class="font-bold text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($quiz['title']); ?></span> | Subject: <?php echo htmlspecialchars($quiz['subject_name']); ?></p>
                        </div>
                        
                        <div class="p-6">
                            <?php if (empty($attempts_list)): ?>
                                <div class="text-center py-12">
                                    <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-history text-blue-600 dark:text-blue-400 text-2xl"></i>
                                    </div>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No Attempts Yet</h3>
                                    <p class="text-gray-500 dark:text-gray-400">No attempts have been recorded for this quiz.</p>
                                </div>
                            <?php else: ?>
                                <div class="overflow-x-auto">
                                    <table class="w-full text-left border-collapse text-sm">
                                        <thead>
                                            <tr class="border-b border-gray-200 dark:border-gray-700 text-gray-500 font-semibold uppercase text-xs">
                                                <th class="py-3 px-4">Attempt #</th>
                                                <?php if ($role !== 'student'): ?>
                                                    <th class="py-3 px-4">Student</th>
                                                <?php endif; ?>
                                                <th class="py-3 px-4">Date Completed</th>
                                                <th class="py-3 px-4">Duration</th>
                                                <th class="py-3 px-4">Score</th>
                                                <th class="py-3 px-4 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-150 dark:divide-gray-700">
                                            <?php foreach ($attempts_list as $att): ?>
                                                <tr>
                                                    <td class="py-3 px-4 font-semibold text-gray-950 dark:text-white">
                                                        Attempt #<?php echo $att['attempt_number']; ?>
                                                        <?php if ($att['status'] === 'in_progress'): ?>
                                                            <span class="ml-1.5 px-2 py-0.5 text-[10px] font-bold bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300 rounded animate-pulse">In Progress</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($role !== 'student'): ?>
                                                        <td class="py-3 px-4 font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($att['student_name']); ?></td>
                                                    <?php endif; ?>
                                                    <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
                                                        <?php echo $att['end_time'] ? date('M d, Y h:i A', strtotime($att['end_time'])) : 'Started: ' . date('M d, Y h:i A', strtotime($att['start_time'])); ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-gray-600 dark:text-gray-400">
                                                        <?php echo $att['time_taken_minutes'] !== null ? $att['time_taken_minutes'] . " mins" : "Ongoing"; ?>
                                                    </td>
                                                    <td class="py-3 px-4 font-bold text-gray-900 dark:text-white">
                                                        <?php if ($att['status'] === 'in_progress'): ?>
                                                            <span class="text-gray-400 font-medium">N/A</span>
                                                        <?php else: ?>
                                                            <span class="<?php echo $att['percentage'] >= 50 ? 'text-green-600 dark:text-green-400' : 'text-red-500 dark:text-red-400'; ?>">
                                                                <?php echo round($att['percentage'], 1); ?>%
                                                            </span>
                                                            <span class="text-xs text-gray-500 dark:text-gray-400 block font-normal">(<?php echo $att['total_marks_obtained']; ?>/<?php echo $quiz['total_marks']; ?>)</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-3 px-4 text-right">
                                                        <?php if ($att['status'] === 'in_progress'): ?>
                                                            <?php if ($role === 'student'): ?>
                                                                <a href="quiz_take.php?quiz_id=<?php echo $quiz_id; ?>" class="text-xs bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg transition font-medium inline-block">
                                                                    Resume Quiz
                                                                </a>
                                                            <?php else: ?>
                                                                <span class="text-gray-400 text-xs italic">Attempt in progress</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if ($role === 'student' && !$quiz['show_results']): ?>
                                                                <span class="text-gray-400 text-xs italic">Results Hidden</span>
                                                            <?php else: ?>
                                                                <a href="quiz_attempts.php?attempt_id=<?php echo $att['id']; ?>" class="text-xs bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 text-blue-700 dark:text-blue-300 px-3 py-1.5 rounded-lg transition font-medium inline-block">
                                                                    <?php echo in_array($role, ['super_admin', 'school_admin', 'teacher']) ? 'Review / Grade' : 'View Details'; ?>
                                                                </a>
                                                            <?php endif; ?>
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
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
