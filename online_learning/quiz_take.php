<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once dirname(__DIR__) . '/includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Student';

$quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_SANITIZE_NUMBER_INT);
if (!$quiz_id) {
    header("Location: quizzes.php");
    exit();
}

// Fetch quiz details and verify assignment
try {
    $quiz_query = "SELECT q.*, s.name as subject_name, u.name as teacher_name
                   FROM online_quizzes q
                   LEFT JOIN subjects s ON q.subject_id = s.id
                   LEFT JOIN users u ON q.teacher_id = u.id
                   WHERE q.id = :quiz_id AND q.status = 'published'";
    $stmt = $db->prepare($quiz_query);
    $stmt->execute([':quiz_id' => $quiz_id]);
    $quiz = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$quiz) {
        header("Location: quizzes.php?error=quiz_not_found");
        exit();
    }

    // Verify student is in the target class
    $class_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
    $class_stmt->execute([':student_id' => $user_id]);
    $student_class_id = $class_stmt->fetchColumn();

    if ($student_class_id != $quiz['class_id']) {
        header("Location: quizzes.php?error=unauthorized_class");
        exit();
    }

    // Verify dates
    $now_ts = time();
    $start_ts = strtotime($quiz['start_date']);
    $end_ts = strtotime($quiz['end_date']);

    if ($now_ts < $start_ts) {
        header("Location: quizzes.php?error=quiz_not_started");
        exit();
    }
    if ($now_ts > $end_ts) {
        header("Location: quizzes.php?error=quiz_closed");
        exit();
    }

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Check or create attempt
try {
    $stmt = $db->prepare("SELECT * FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id AND status = 'in_progress' ORDER BY attempt_number DESC LIMIT 1");
    $stmt->execute([':quiz_id' => $quiz_id, ':student_id' => $user_id]);
    $attempt = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$attempt) {
        // Count previous attempts
        $stmt = $db->prepare("SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = :quiz_id AND student_id = :student_id");
        $stmt->execute([':quiz_id' => $quiz_id, ':student_id' => $user_id]);
        $prev_attempts_count = $stmt->fetchColumn();

        if ($quiz['attempts_allowed'] && $prev_attempts_count >= $quiz['attempts_allowed']) {
            header("Location: quizzes.php?error=max_attempts_reached");
            exit();
        }

        $attempt_number = $prev_attempts_count + 1;
        $stmt = $db->prepare("INSERT INTO quiz_attempts (quiz_id, student_id, attempt_number, start_time, status) 
                              VALUES (:quiz_id, :student_id, :attempt_number, NOW(), 'in_progress')");
        $stmt->execute([
            ':quiz_id' => $quiz_id,
            ':student_id' => $user_id,
            ':attempt_number' => $attempt_number
        ]);
        
        $attempt_id = $db->lastInsertId();
        $stmt = $db->prepare("SELECT * FROM quiz_attempts WHERE id = :id");
        $stmt->execute([':id' => $attempt_id]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $attempt_id = $attempt['id'];
    }
} catch (PDOException $e) {
    die("Database Error setting up attempt: " . $e->getMessage());
}

// Calculate remaining time
$start_time_ts = strtotime($attempt['start_time']);
$time_limit = $quiz['time_limit_minutes'];
$end_time_ts = $time_limit ? ($start_time_ts + $time_limit * 60) : null;
$quiz_end_ts = strtotime($quiz['end_date']);

if ($end_time_ts === null || $quiz_end_ts < $end_time_ts) {
    $end_time_ts = $quiz_end_ts;
}

$time_remaining = $end_time_ts - time();

// Auto-submit if time is already up
if ($time_remaining <= 0) {
    submitQuiz($db, $attempt_id, $quiz, $user_id);
    header("Location: quiz_attempts.php?quiz_id=" . $quiz_id . "&timed_out=1");
    exit();
}

// Handle POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_answers') {
    // Process submission
    submitQuiz($db, $attempt_id, $quiz, $user_id, $_POST['answers'] ?? []);
    header("Location: quiz_attempts.php?quiz_id=" . $quiz_id . "&submitted=1");
    exit();
}

// Fetch quiz questions
$questions = [];
try {
    $order_clause = $quiz['randomize_questions'] ? "ORDER BY RAND(" . $attempt_id . ")" : "ORDER BY question_order ASC";
    $q_query = "SELECT * FROM quiz_questions WHERE quiz_id = :quiz_id $order_clause";
    $stmt = $db->prepare($q_query);
    $stmt->execute([':quiz_id' => $quiz_id]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database Error fetching questions: " . $e->getMessage());
}

// Process submission helper function
function submitQuiz($db, $attempt_id, $quiz, $student_id, $submitted_answers = []) {
    try {
        $db->beginTransaction();

        // Retrieve all questions for correct values
        $q_stmt = $db->prepare("SELECT id, question_type, correct_answer, marks FROM quiz_questions WHERE quiz_id = :quiz_id");
        $q_stmt->execute([':quiz_id' => $quiz['id']]);
        $questions = $q_stmt->fetchAll(PDO::FETCH_ASSOC);

        $total_marks_obtained = 0.00;
        
        foreach ($questions as $q) {
            $q_id = $q['id'];
            $student_answer = isset($submitted_answers[$q_id]) ? $submitted_answers[$q_id] : '';
            
            $is_correct = 0;
            $marks_obtained = 0.00;

            if ($q['question_type'] === 'multiple_choice') {
                if (strtoupper(trim($student_answer)) === strtoupper(trim($q['correct_answer']))) {
                    $is_correct = 1;
                    $marks_obtained = $q['marks'];
                }
            } elseif ($q['question_type'] === 'true_false') {
                if (strtolower(trim($student_answer)) === strtolower(trim($q['correct_answer']))) {
                    $is_correct = 1;
                    $marks_obtained = $q['marks'];
                }
            } elseif ($q['question_type'] === 'short_answer') {
                if (strtolower(trim($student_answer)) === strtolower(trim($q['correct_answer']))) {
                    $is_correct = 1;
                    $marks_obtained = $q['marks'];
                }
            } elseif ($q['question_type'] === 'essay') {
                // Essays need manual grading; auto-graded to 0 marks initially
                $is_correct = 0;
                $marks_obtained = 0.00;
            }

            $total_marks_obtained += $marks_obtained;

            // Insert student answer
            $ans_stmt = $db->prepare("INSERT INTO quiz_answers (attempt_id, question_id, student_answer, marks_obtained, is_correct, answered_at)
                                      VALUES (:attempt_id, :question_id, :student_answer, :marks_obtained, :is_correct, NOW())");
            $ans_stmt->execute([
                ':attempt_id' => $attempt_id,
                ':question_id' => $q_id,
                ':student_answer' => $student_answer !== '' ? $student_answer : null,
                ':marks_obtained' => $marks_obtained,
                ':is_correct' => $is_correct
            ]);
        }

        // Calculate percentage
        $total_possible_marks = $quiz['total_marks'] ?: 1;
        if ($total_possible_marks <= 0) $total_possible_marks = 1;
        $percentage = ($total_marks_obtained / $total_possible_marks) * 100;

        // Calculate time taken
        $start_time = $db->query("SELECT start_time FROM quiz_attempts WHERE id = $attempt_id")->fetchColumn();
        $time_taken = round((time() - strtotime($start_time)) / 60);

        // Update attempt status
        $update_stmt = $db->prepare("UPDATE quiz_attempts SET 
                                     end_time = NOW(), 
                                     total_marks_obtained = :total_marks_obtained, 
                                     percentage = :percentage, 
                                     status = 'completed', 
                                     time_taken_minutes = :time_taken
                                     WHERE id = :attempt_id");
        $update_stmt->execute([
            ':total_marks_obtained' => $total_marks_obtained,
            ':percentage' => $percentage,
            ':time_taken' => $time_taken,
            ':attempt_id' => $attempt_id
        ]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        die("Error processing submission: " . $e->getMessage());
    }
}

$title = "Taking Quiz - " . htmlspecialchars($quiz['title']);
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
                <!-- Header Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                        <div>
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></h1>
                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($quiz['description'] ?? 'No instructions provided.'); ?></p>
                            <div class="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400 mt-3 font-semibold">
                                <span>Subject: <?php echo htmlspecialchars($quiz['subject_name']); ?></span>
                                <span>Teacher: <?php echo htmlspecialchars($quiz['teacher_name']); ?></span>
                                <span>Total Marks: <?php echo $quiz['total_marks']; ?></span>
                            </div>
                        </div>
                        
                        <!-- TIMER DISPLAY -->
                        <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-xl p-4 text-center min-w-[150px] shadow-sm">
                            <span class="text-xs text-blue-600 dark:text-blue-400 font-bold block mb-1">Time Remaining</span>
                            <span id="countdown-timer" class="text-2xl font-mono font-bold text-blue-700 dark:text-blue-300">
                                --:--
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Warnings/Alerts -->
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-xl p-4 mb-6 flex items-start space-x-3">
                    <i class="fas fa-exclamation-triangle text-yellow-600 mt-0.5"></i>
                    <div>
                        <h4 class="text-sm font-bold text-yellow-800 dark:text-yellow-200">Important Quiz Policy</h4>
                        <p class="text-xs text-yellow-700 dark:text-yellow-300 mt-1">Do not reload, navigate away, or close this window. Your progress is monitored. When the timer hits 0, your work will be submitted automatically.</p>
                    </div>
                </div>

                <!-- Questions Form -->
                <form id="quiz-form" method="POST">
                    <input type="hidden" name="action" value="submit_answers">
                    
                    <div class="space-y-6">
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6">
                                <div class="flex items-start justify-between mb-4">
                                    <span class="bg-blue-50 dark:bg-blue-900/30 text-blue-800 dark:text-blue-200 text-xs font-semibold px-2.5 py-1 rounded">
                                        Question <?php echo $index + 1; ?> (<?php echo $q['marks']; ?> Marks)
                                    </span>
                                </div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></h3>
                                
                                <div class="space-y-3">
                                    <?php if ($q['question_type'] === 'multiple_choice'): ?>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="A" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold mr-1">A.</span><?php echo htmlspecialchars($q['option_a']); ?></span>
                                        </label>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="B" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold mr-1">B.</span><?php echo htmlspecialchars($q['option_b']); ?></span>
                                        </label>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="C" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold mr-1">C.</span><?php echo htmlspecialchars($q['option_c']); ?></span>
                                        </label>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="D" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><span class="font-bold mr-1">D.</span><?php echo htmlspecialchars($q['option_d']); ?></span>
                                        </label>
                                        
                                    <?php elseif ($q['question_type'] === 'true_false'): ?>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="true" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><i class="fas fa-check mr-2 text-green-500"></i>True</span>
                                        </label>
                                        <label class="flex items-center space-x-3 p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer">
                                            <input type="radio" name="answers[<?php echo $q['id']; ?>]" value="false" class="form-radio text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300"><i class="fas fa-times mr-2 text-red-500"></i>False</span>
                                        </label>

                                    <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                        <input type="text" name="answers[<?php echo $q['id']; ?>]" placeholder="Type your answer here..."
                                               class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white text-sm">

                                    <?php elseif ($q['question_type'] === 'essay'): ?>
                                        <textarea name="answers[<?php echo $q['id']; ?>]" rows="5" placeholder="Write your long-form response here..."
                                                  class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none dark:bg-gray-700 dark:text-white text-sm"></textarea>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Submission buttons -->
                    <div class="flex justify-end space-x-3 mt-6 pb-12">
                        <a href="quizzes.php" onclick="return confirm('Exit quiz? Progress on this attempt will not be saved.');" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2.5 rounded-lg transition">
                            Exit Quiz
                        </a>
                        <button type="submit" onclick="return confirm('Are you sure you want to submit? This will end your attempt.');" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-6 py-2.5 rounded-lg transition">
                            Submit Quiz
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Timer Countdown logic
let timeRemaining = <?php echo $time_remaining; ?>;
const timerDisplay = document.getElementById('countdown-timer');
const form = document.getElementById('quiz-form');

function formatTime(seconds) {
    const mins = Math.floor(seconds / 60);
    const secs = seconds % 60;
    return (mins < 10 ? '0' : '') + mins + ':' + (secs < 10 ? '0' : '') + secs;
}

function updateTimer() {
    if (timeRemaining <= 0) {
        timerDisplay.textContent = "00:00";
        alert("Time is up! Your quiz will be submitted automatically.");
        // Submit form
        form.submit();
    } else {
        timerDisplay.textContent = formatTime(timeRemaining);
        timeRemaining--;
        setTimeout(updateTimer, 1000);
    }
}

// Start update
updateTimer();
</script>
