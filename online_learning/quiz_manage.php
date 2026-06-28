<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

$quiz_id = filter_input(INPUT_GET, 'quiz_id', FILTER_SANITIZE_NUMBER_INT);
if (!$quiz_id) {
    header("Location: quizzes.php");
    exit();
}

// Fetch quiz details
$quiz_query = "SELECT * FROM online_quizzes WHERE id = :id";
$quiz_stmt = $db->prepare($quiz_query);
$quiz_stmt->execute([':id' => $quiz_id]);
$quiz = $quiz_stmt->fetch(PDO::FETCH_ASSOC);

if (!$quiz) {
    header("Location: quizzes.php");
    exit();
}

// Ensure teacher owns this quiz (or is admin)
if (!in_array($role, ['super_admin', 'school_admin']) && $quiz['teacher_id'] != $user_id) {
    header("Location: quizzes.php");
    exit();
}

$success_message = '';
$error_message = '';

// Helper function to update total questions and marks on the parent quiz
function updateQuizTotals($db, $quiz_id) {
    $totals_query = "SELECT COUNT(*) as total_q, SUM(marks) as total_m FROM quiz_questions WHERE quiz_id = :quiz_id";
    $totals_stmt = $db->prepare($totals_query);
    $totals_stmt->execute([':quiz_id' => $quiz_id]);
    $totals = $totals_stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_questions = $totals['total_q'] ?: 0;
    $total_marks = $totals['total_m'] ?: 0;
    
    $update_query = "UPDATE online_quizzes SET total_questions = :total_questions, total_marks = :total_marks WHERE id = :quiz_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->execute([
        ':total_questions' => $total_questions,
        ':total_marks' => $total_marks,
        ':quiz_id' => $quiz_id
    ]);
}

// Handle question actions (Add, Edit, Delete)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'add_question' || $action === 'edit_question') {
        $question_id = filter_input(INPUT_POST, 'question_id', FILTER_SANITIZE_NUMBER_INT);
        $question_text = filter_input(INPUT_POST, 'question_text', FILTER_DEFAULT);
        $question_type = filter_input(INPUT_POST, 'question_type', FILTER_SANITIZE_STRING);
        $marks = filter_input(INPUT_POST, 'marks', FILTER_SANITIZE_NUMBER_INT) ?: 1;
        $explanation = filter_input(INPUT_POST, 'explanation', FILTER_DEFAULT);
        
        $option_a = filter_input(INPUT_POST, 'option_a', FILTER_SANITIZE_STRING);
        $option_b = filter_input(INPUT_POST, 'option_b', FILTER_SANITIZE_STRING);
        $option_c = filter_input(INPUT_POST, 'option_c', FILTER_SANITIZE_STRING);
        $option_d = filter_input(INPUT_POST, 'option_d', FILTER_SANITIZE_STRING);
        
        // Correct answer processing based on question type
        $correct_answer = '';
        if ($question_type === 'multiple_choice') {
            $correct_answer = filter_input(INPUT_POST, 'correct_multiple', FILTER_SANITIZE_STRING);
        } elseif ($question_type === 'true_false') {
            $correct_answer = filter_input(INPUT_POST, 'correct_boolean', FILTER_SANITIZE_STRING); // 'true' or 'false'
        } elseif ($question_type === 'short_answer') {
            $correct_answer = trim(filter_input(INPUT_POST, 'correct_short', FILTER_DEFAULT));
        } elseif ($question_type === 'essay') {
            $correct_answer = trim(filter_input(INPUT_POST, 'essay_guidelines', FILTER_DEFAULT));
        }

        if (empty($question_text)) {
            $error_message = "Question text cannot be empty.";
        } else {
            try {
                if ($action === 'add_question') {
                    // Get next order
                    $order_query = "SELECT COALESCE(MAX(question_order), 0) + 1 FROM quiz_questions WHERE quiz_id = :quiz_id";
                    $order_stmt = $db->prepare($order_query);
                    $order_stmt->execute([':quiz_id' => $quiz_id]);
                    $next_order = $order_stmt->fetchColumn();

                    $insert_query = "INSERT INTO quiz_questions 
                                     (quiz_id, question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_answer, explanation, question_order, created_at)
                                     VALUES (:quiz_id, :question_text, :question_type, :marks, :option_a, :option_b, :option_c, :option_d, :correct_answer, :explanation, :question_order, NOW())";
                    $stmt = $db->prepare($insert_query);
                    $stmt->execute([
                        ':quiz_id' => $quiz_id,
                        ':question_text' => $question_text,
                        ':question_type' => $question_type,
                        ':marks' => $marks,
                        ':option_a' => $option_a ?: null,
                        ':option_b' => $option_b ?: null,
                        ':option_c' => $option_c ?: null,
                        ':option_d' => $option_d ?: null,
                        ':correct_answer' => $correct_answer,
                        ':explanation' => $explanation ?: null,
                        ':question_order' => $next_order
                    ]);
                    $success_message = "Question added successfully!";
                } else {
                    // edit
                    $update_query = "UPDATE quiz_questions SET 
                                     question_text = :question_text, 
                                     question_type = :question_type, 
                                     marks = :marks, 
                                     option_a = :option_a, 
                                     option_b = :option_b, 
                                     option_c = :option_c, 
                                     option_d = :option_d, 
                                     correct_answer = :correct_answer, 
                                     explanation = :explanation
                                     WHERE id = :id AND quiz_id = :quiz_id";
                    $stmt = $db->prepare($update_query);
                    $stmt->execute([
                        ':question_text' => $question_text,
                        ':question_type' => $question_type,
                        ':marks' => $marks,
                        ':option_a' => $option_a ?: null,
                        ':option_b' => $option_b ?: null,
                        ':option_c' => $option_c ?: null,
                        ':option_d' => $option_d ?: null,
                        ':correct_answer' => $correct_answer,
                        ':explanation' => $explanation ?: null,
                        ':id' => $question_id,
                        ':quiz_id' => $quiz_id
                    ]);
                    $success_message = "Question updated successfully!";
                }
                updateQuizTotals($db, $quiz_id);
            } catch (PDOException $e) {
                $error_message = "Database Error: " . $e->getMessage();
            }
        }
    }
    
    if ($action === 'delete_question') {
        $question_id = filter_input(INPUT_POST, 'question_id', FILTER_SANITIZE_NUMBER_INT);
        if ($question_id) {
            try {
                $del_query = "DELETE FROM quiz_questions WHERE id = :id AND quiz_id = :quiz_id";
                $del_stmt = $db->prepare($del_query);
                $del_stmt->execute([
                    ':id' => $question_id,
                    ':quiz_id' => $quiz_id
                ]);
                $success_message = "Question deleted successfully.";
                updateQuizTotals($db, $quiz_id);
            } catch (PDOException $e) {
                $error_message = "Error deleting question: " . $e->getMessage();
            }
        }
    }
}

// Fetch all questions for this quiz
$questions_query = "SELECT * FROM quiz_questions WHERE quiz_id = :quiz_id ORDER BY question_order ASC";
$questions_stmt = $db->prepare($questions_query);
$questions_stmt->execute([':quiz_id' => $quiz_id]);
$questions = $questions_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Manage Questions - " . htmlspecialchars($quiz['title']);
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
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center space-x-2 text-xs font-semibold text-gray-500 dark:text-gray-400 mb-2">
                                <a href="quizzes.php" class="hover:underline">Quizzes</a>
                                <span>/</span>
                                <span class="text-gray-900 dark:text-white">Manage Questions</span>
                            </div>
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Questions Manager</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Quiz: <span class="font-bold text-gray-950 dark:text-white"><?php echo htmlspecialchars($quiz['title']); ?></span> | Totals: <?php echo $quiz['total_marks']; ?> Marks (<?php echo $quiz['total_questions']; ?> Qs)</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="quizzes.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Quizzes
                            </a>
                            <button onclick="openQuestionModal('add')" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i>Add Question
                            </button>
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

                <!-- Questions List -->
                <div class="space-y-6">
                    <?php if (empty($questions)): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-12 text-center">
                            <i class="fas fa-list-ol text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No Questions Added</h3>
                            <p class="text-gray-500 dark:text-gray-400 mb-6">Create questions to publish the quiz for students.</p>
                            <button onclick="openQuestionModal('add')" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-lg transition-colors">
                                Add First Question
                            </button>
                        </div>
                    <?php else: ?>
                        <?php foreach ($questions as $index => $q): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6 relative">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-2">
                                            <span class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 text-xs font-semibold px-2.5 py-1 rounded">
                                                Question <?php echo $index + 1; ?>
                                            </span>
                                            <span class="bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 text-xs font-semibold px-2.5 py-1 rounded capitalize">
                                                <?php echo str_replace('_', ' ', $q['question_type']); ?>
                                            </span>
                                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                                <?php echo $q['marks']; ?> Mark(s)
                                            </span>
                                        </div>
                                        
                                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4"><?php echo nl2br(htmlspecialchars($q['question_text'])); ?></h3>
                                        
                                        <!-- Question Choices Display -->
                                        <?php if ($q['question_type'] === 'multiple_choice'): ?>
                                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4 max-w-2xl">
                                                <div class="p-3 border rounded-lg <?php echo $q['correct_answer'] === 'A' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <span class="font-bold mr-2">A.</span><?php echo htmlspecialchars($q['option_a']); ?>
                                                </div>
                                                <div class="p-3 border rounded-lg <?php echo $q['correct_answer'] === 'B' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <span class="font-bold mr-2">B.</span><?php echo htmlspecialchars($q['option_b']); ?>
                                                </div>
                                                <div class="p-3 border rounded-lg <?php echo $q['correct_answer'] === 'C' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <span class="font-bold mr-2">C.</span><?php echo htmlspecialchars($q['option_c']); ?>
                                                </div>
                                                <div class="p-3 border rounded-lg <?php echo $q['correct_answer'] === 'D' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <span class="font-bold mr-2">D.</span><?php echo htmlspecialchars($q['option_d']); ?>
                                                </div>
                                            </div>
                                        <?php elseif ($q['question_type'] === 'true_false'): ?>
                                            <div class="flex space-x-4 mb-4">
                                                <div class="px-4 py-2 border rounded-lg <?php echo $q['correct_answer'] === 'true' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <i class="fas fa-check mr-2"></i>True
                                                </div>
                                                <div class="px-4 py-2 border rounded-lg <?php echo $q['correct_answer'] === 'false' ? 'bg-green-50 border-green-300 text-green-900 dark:bg-green-950 dark:border-green-800 dark:text-green-200' : 'bg-gray-50 border-gray-200 dark:bg-gray-900 dark:border-gray-700 text-gray-700 dark:text-gray-300'; ?>">
                                                    <i class="fas fa-times mr-2"></i>False
                                                </div>
                                            </div>
                                        <?php elseif ($q['question_type'] === 'short_answer'): ?>
                                            <div class="mb-4">
                                                <span class="text-sm font-semibold text-gray-600 dark:text-gray-400">Correct Phrase/Answer:</span>
                                                <code class="ml-2 px-2.5 py-1 bg-green-50 border border-green-200 text-green-800 dark:bg-green-950 dark:border-green-900 dark:text-green-200 rounded font-semibold text-sm">
                                                    <?php echo htmlspecialchars($q['correct_answer']); ?>
                                                </code>
                                            </div>
                                        <?php elseif ($q['question_type'] === 'essay'): ?>
                                            <div class="mb-4">
                                                <span class="text-sm font-semibold text-gray-600 dark:text-gray-400 block mb-1">Grading Guidelines / Explanations:</span>
                                                <div class="p-3 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 text-sm text-gray-700 dark:text-gray-300 rounded-lg">
                                                    <?php echo nl2br(htmlspecialchars($q['correct_answer'] ?: 'None')); ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($q['explanation']): ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900/50 p-2.5 rounded-lg border border-dashed border-gray-200 dark:border-gray-700/60 mt-3">
                                                <span class="font-bold text-gray-600 dark:text-gray-300">Explanation:</span> <?php echo htmlspecialchars($q['explanation']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="flex items-center space-x-2">
                                        <button onclick="openQuestionModal('edit', <?php echo htmlspecialchars(json_encode($q)); ?>)" 
                                                class="text-blue-600 hover:text-blue-800 dark:hover:text-blue-400 bg-blue-50 dark:bg-blue-900/30 p-2 rounded-lg transition-colors">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="inline" onsubmit="return confirm('Delete this question?');">
                                            <input type="hidden" name="action" value="delete_question">
                                            <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                            <button type="submit" class="text-red-600 hover:text-red-800 dark:hover:text-red-400 bg-red-50 dark:bg-red-900/30 p-2 rounded-lg transition-colors">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Add/Edit Question Modal -->
<div id="question-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50 overflow-y-auto">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full border border-gray-200 dark:border-gray-700">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                <h3 id="modal-title" class="text-xl font-bold text-gray-900 dark:text-white">Add Question</h3>
                <button onclick="closeQuestionModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-lg"></i>
                </button>
            </div>
            
            <form method="POST" id="question-form">
                <input type="hidden" name="action" id="form-action" value="add_question">
                <input type="hidden" name="question_id" id="form-question-id" value="">
                
                <div class="p-6 space-y-6" style="max-height: 60vh; overflow-y: auto;">
                    <!-- Question Type -->
                    <div>
                        <label for="question_type_select" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Question Type *</label>
                        <select id="question_type_select" name="question_type" onchange="toggleTypeFields()" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="multiple_choice">Multiple Choice (MCQ)</option>
                            <option value="true_false">True / False</option>
                            <option value="short_answer">Short Answer</option>
                            <option value="essay">Essay / Long Answer</option>
                        </select>
                    </div>

                    <!-- Question Text -->
                    <div>
                        <label for="question_text_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Question Prompt *</label>
                        <textarea id="question_text_input" name="question_text" rows="3" required placeholder="Type the question prompt here..."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <!-- Question Marks -->
                    <div>
                        <label for="marks_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Marks / Weight *</label>
                        <input type="number" id="marks_input" name="marks" value="1" min="1" required
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <!-- MCQ OPTIONS SECTION -->
                    <div id="mcq-options-container" class="space-y-4">
                        <h4 class="font-semibold text-sm text-gray-600 dark:text-gray-400 border-b pb-1">MCQ Options</h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="opt_a_input" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Option A *</label>
                                <input type="text" id="opt_a_input" name="option_a" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="opt_b_input" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Option B *</label>
                                <input type="text" id="opt_b_input" name="option_b" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="opt_c_input" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Option C *</label>
                                <input type="text" id="opt_c_input" name="option_c" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="opt_d_input" class="block text-xs font-semibold text-gray-500 dark:text-gray-400 mb-1">Option D *</label>
                                <input type="text" id="opt_d_input" name="option_d" 
                                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                        <div>
                            <label for="correct_multiple_select" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Select Correct Option *</label>
                            <select id="correct_multiple_select" name="correct_multiple"
                                    class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="A">Option A</option>
                                <option value="B">Option B</option>
                                <option value="C">Option C</option>
                                <option value="D">Option D</option>
                            </select>
                        </div>
                    </div>

                    <!-- T/F ANSWER SECTION -->
                    <div id="true-false-container" class="hidden">
                        <label for="correct_boolean_select" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Correct Answer *</label>
                        <select id="correct_boolean_select" name="correct_boolean"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="true">True</option>
                            <option value="false">False</option>
                        </select>
                    </div>

                    <!-- SHORT ANSWER SECTION -->
                    <div id="short-answer-container" class="hidden">
                        <label for="correct_short_input" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Correct Short Answer *</label>
                        <input type="text" id="correct_short_input" name="correct_short" placeholder="e.g. Gravity (case insensitive match)"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        <p class="text-xs text-gray-400 mt-1">Students will be auto-graded correct if they enter this string (ignoring capitalization).</p>
                    </div>

                    <!-- ESSAY GRADING CRITERIA -->
                    <div id="essay-container" class="hidden">
                        <label for="essay_guidelines_textarea" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Grading Key / Student Guidelines</label>
                        <textarea id="essay_guidelines_textarea" name="essay_guidelines" rows="3" placeholder="Provide points students must highlight (will require manual grading by teacher)."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>

                    <!-- Explanation -->
                    <div>
                        <label for="explanation_textarea" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Explanation (Shown after submission)</label>
                        <textarea id="explanation_textarea" name="explanation" rows="2" placeholder="Explain the rationale behind the correct answer."
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                    </div>
                </div>

                <div class="p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end space-x-3 bg-gray-50 dark:bg-gray-900 rounded-b-xl">
                    <button type="button" onclick="closeQuestionModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                        <span id="submit-btn-text">Add Question</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleTypeFields() {
    const type = document.getElementById('question_type_select').value;
    
    // Hide all
    document.getElementById('mcq-options-container').classList.add('hidden');
    document.getElementById('true-false-container').classList.add('hidden');
    document.getElementById('short-answer-container').classList.add('hidden');
    document.getElementById('essay-container').classList.add('hidden');
    
    // Disable required for inputs that are hidden to prevent validation issues
    setRequired('opt_a_input', false);
    setRequired('opt_b_input', false);
    setRequired('opt_c_input', false);
    setRequired('opt_d_input', false);
    setRequired('correct_short_input', false);
    
    if (type === 'multiple_choice') {
        document.getElementById('mcq-options-container').classList.remove('hidden');
        setRequired('opt_a_input', true);
        setRequired('opt_b_input', true);
        setRequired('opt_c_input', true);
        setRequired('opt_d_input', true);
    } else if (type === 'true_false') {
        document.getElementById('true-false-container').classList.remove('hidden');
    } else if (type === 'short_answer') {
        document.getElementById('short-answer-container').classList.remove('hidden');
        setRequired('correct_short_input', true);
    } else if (type === 'essay') {
        document.getElementById('essay-container').classList.remove('hidden');
    }
}

function setRequired(id, isRequired) {
    const el = document.getElementById(id);
    if (el) {
        if (isRequired) {
            el.setAttribute('required', 'required');
        } else {
            el.removeAttribute('required');
        }
    }
}

function openQuestionModal(mode, qData = null) {
    const modal = document.getElementById('question-modal');
    const form = document.getElementById('question-form');
    const modalTitle = document.getElementById('modal-title');
    const submitText = document.getElementById('submit-btn-text');
    
    form.reset();
    
    if (mode === 'add') {
        modalTitle.textContent = "Add Question";
        submitText.textContent = "Add Question";
        document.getElementById('form-action').value = 'add_question';
        document.getElementById('form-question-id').value = '';
        document.getElementById('question_type_select').value = 'multiple_choice';
        document.getElementById('question_type_select').removeAttribute('disabled');
    } else {
        modalTitle.textContent = "Edit Question";
        submitText.textContent = "Update Question";
        document.getElementById('form-action').value = 'edit_question';
        document.getElementById('form-question-id').value = qData.id;
        document.getElementById('question_text_input').value = qData.question_text;
        document.getElementById('marks_input').value = qData.marks;
        document.getElementById('question_type_select').value = qData.question_type;
        document.getElementById('explanation_textarea').value = qData.explanation || '';
        
        // Fill data based on type
        if (qData.question_type === 'multiple_choice') {
            document.getElementById('opt_a_input').value = qData.option_a || '';
            document.getElementById('opt_b_input').value = qData.option_b || '';
            document.getElementById('opt_c_input').value = qData.option_c || '';
            document.getElementById('opt_d_input').value = qData.option_d || '';
            document.getElementById('correct_multiple_select').value = qData.correct_answer;
        } else if (qData.question_type === 'true_false') {
            document.getElementById('correct_boolean_select').value = qData.correct_answer;
        } else if (qData.question_type === 'short_answer') {
            document.getElementById('correct_short_input').value = qData.correct_answer;
        } else if (qData.question_type === 'essay') {
            document.getElementById('essay_guidelines_textarea').value = qData.correct_answer;
        }
    }
    
    toggleTypeFields();
    modal.classList.remove('hidden');
}

function closeQuestionModal() {
    document.getElementById('question-modal').classList.add('hidden');
}
</script>
