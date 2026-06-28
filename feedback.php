<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle feedback submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $feedback_type = filter_input(INPUT_POST, 'feedback_type', FILTER_SANITIZE_STRING);
    $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
    $rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);

    if ($feedback_type && $subject && $message) {
        try {
            $query = "INSERT INTO feedback (user_id, feedback_type, subject, message, rating) VALUES (:user_id, :feedback_type, :subject, :message, :rating)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':feedback_type', $feedback_type);
            $stmt->bindParam(':subject', $subject);
            $stmt->bindParam(':message', $message);
            $stmt->bindParam(':rating', $rating);

            if ($stmt->execute()) {
                $success_message = "Thank you for your feedback! We appreciate your input.";
            } else {
                $error_message = "Error submitting feedback. Please try again.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get user's feedback history
$feedback_query = "SELECT * FROM feedback WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 10";
$feedback_stmt = $db->prepare($feedback_query);
$feedback_stmt->bindParam(':user_id', $user_id);
$feedback_stmt->execute();
$user_feedback = $feedback_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Feedback Center";
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Feedback Center</h1>
                                <p class="text-blue-100 text-lg">Share your thoughts and help us improve</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-green-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-comments mr-2"></i>
                                        Your voice matters to us
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-comment-dots text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Submit Feedback Form -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Share Your Feedback</h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Help us improve by sharing your experience</p>
                        </div>

                        <form method="POST" class="p-6 space-y-6">
                            <!-- Feedback Type -->
                            <div>
                                <label for="feedback_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Feedback Type <span class="text-red-500">*</span>
                                </label>
                                <select id="feedback_type" name="feedback_type" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select feedback type</option>
                                    <option value="suggestion">💡 Suggestion</option>
                                    <option value="complaint">😞 Complaint</option>
                                    <option value="compliment">😊 Compliment</option>
                                    <option value="bug_report">🐛 Bug Report</option>
                                    <option value="other">📝 Other</option>
                                </select>
                            </div>

                            <!-- Subject -->
                            <div>
                                <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Subject <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="subject" name="subject" required
                                    placeholder="Brief summary of your feedback"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Rating -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Overall Rating (Optional)
                                </label>
                                <div class="flex items-center space-x-2">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="rating" value="<?php echo $i; ?>" class="sr-only rating-input">
                                        <i class="fas fa-star text-2xl text-gray-300 hover:text-yellow-400 transition-colors duration-200 rating-star" data-rating="<?php echo $i; ?>"></i>
                                    </label>
                                    <?php endfor; ?>
                                    <span class="ml-2 text-sm text-gray-600 dark:text-gray-400" id="rating-text">Click to rate</span>
                                </div>
                            </div>

                            <!-- Message -->
                            <div>
                                <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Message <span class="text-red-500">*</span>
                                </label>
                                <textarea id="message" name="message" rows="6" required
                                    placeholder="Please share your detailed feedback..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white"></textarea>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" name="submit_feedback"
                                class="w-full bg-green-500 hover:bg-green-600 text-white font-medium py-3 px-4 rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Submit Feedback
                            </button>
                        </form>
                    </div>

                    <!-- Feedback History -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Your Feedback History</h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Track your previous feedback submissions</p>
                        </div>

                        <div class="p-6">
                            <?php if (!empty($user_feedback)): ?>
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($user_feedback as $feedback): ?>
                                <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <span class="text-lg">
                                                    <?php 
                                                    switch($feedback['feedback_type']) {
                                                        case 'suggestion': echo '💡'; break;
                                                        case 'complaint': echo '😞'; break;
                                                        case 'compliment': echo '😊'; break;
                                                        case 'bug_report': echo '🐛'; break;
                                                        default: echo '📝';
                                                    }
                                                    ?>
                                                </span>
                                                <h3 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($feedback['subject']); ?></h3>
                                            </div>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-2"><?php echo htmlspecialchars(substr($feedback['message'], 0, 100)) . (strlen($feedback['message']) > 100 ? '...' : ''); ?></p>
                                            <div class="flex items-center space-x-4 text-xs text-gray-500 dark:text-gray-400">
                                                <span><i class="fas fa-calendar mr-1"></i><?php echo date('M j, Y', strtotime($feedback['created_at'])); ?></span>
                                                <?php if ($feedback['rating']): ?>
                                                <div class="flex items-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <i class="fas fa-star text-xs <?php echo $i <= $feedback['rating'] ? 'text-yellow-400' : 'text-gray-300'; ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ml-4
                                            <?php 
                                            switch($feedback['status']) {
                                                case 'pending': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                case 'reviewed': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'addressed': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($feedback['status']); ?>
                                        </span>
                                    </div>
                                    <?php if ($feedback['admin_response']): ?>
                                    <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border-l-4 border-blue-400">
                                        <p class="text-sm text-blue-800 dark:text-blue-200">
                                            <strong>Response:</strong> <?php echo htmlspecialchars($feedback['admin_response']); ?>
                                        </p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-comment-dots text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No feedback yet</h3>
                                <p class="text-gray-500 dark:text-gray-400">Share your first feedback to help us improve</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Feedback Guidelines -->
                <div class="mt-8 bg-blue-50 dark:bg-blue-900/20 rounded-xl p-6 border border-blue-200 dark:border-blue-800">
                    <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-3">
                        <i class="fas fa-info-circle mr-2"></i>Feedback Guidelines
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <h4 class="font-medium text-blue-800 dark:text-blue-200 mb-2">What to include:</h4>
                            <ul class="space-y-1 text-blue-700 dark:text-blue-300 text-sm">
                                <li class="flex items-start">
                                    <i class="fas fa-check text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Specific details about your experience</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Suggestions for improvement</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-check text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Steps to reproduce issues</span>
                                </li>
                            </ul>
                        </div>
                        <div>
                            <h4 class="font-medium text-blue-800 dark:text-blue-200 mb-2">Response time:</h4>
                            <ul class="space-y-1 text-blue-700 dark:text-blue-300 text-sm">
                                <li class="flex items-start">
                                    <i class="fas fa-clock text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Suggestions: 3-5 business days</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-clock text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Bug reports: 1-2 business days</span>
                                </li>
                                <li class="flex items-start">
                                    <i class="fas fa-clock text-blue-600 mr-2 mt-1 text-xs"></i>
                                    <span>Complaints: 1-3 business days</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Rating system
document.addEventListener('DOMContentLoaded', function() {
    const ratingStars = document.querySelectorAll('.rating-star');
    const ratingInputs = document.querySelectorAll('.rating-input');
    const ratingText = document.getElementById('rating-text');
    
    const ratingLabels = {
        1: 'Poor',
        2: 'Fair', 
        3: 'Good',
        4: 'Very Good',
        5: 'Excellent'
    };

    ratingStars.forEach((star, index) => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.dataset.rating);
            
            // Update radio button
            ratingInputs[index].checked = true;
            
            // Update star colors
            ratingStars.forEach((s, i) => {
                if (i < rating) {
                    s.classList.remove('text-gray-300');
                    s.classList.add('text-yellow-400');
                } else {
                    s.classList.remove('text-yellow-400');
                    s.classList.add('text-gray-300');
                }
            });
            
            // Update text
            ratingText.textContent = ratingLabels[rating];
        });
        
        star.addEventListener('mouseenter', function() {
            const rating = parseInt(this.dataset.rating);
            ratingStars.forEach((s, i) => {
                if (i < rating) {
                    s.classList.add('text-yellow-400');
                } else {
                    s.classList.remove('text-yellow-400');
                }
            });
        });
    });
    
    // Reset on mouse leave
    document.querySelector('.flex.items-center.space-x-2').addEventListener('mouseleave', function() {
        const checkedInput = document.querySelector('.rating-input:checked');
        if (checkedInput) {
            const rating = parseInt(checkedInput.value);
            ratingStars.forEach((s, i) => {
                if (i < rating) {
                    s.classList.add('text-yellow-400');
                    s.classList.remove('text-gray-300');
                } else {
                    s.classList.remove('text-yellow-400');
                    s.classList.add('text-gray-300');
                }
            });
        } else {
            ratingStars.forEach(s => {
                s.classList.remove('text-yellow-400');
                s.classList.add('text-gray-300');
            });
        }
    });

    // Auto-focus on feedback type
    document.getElementById('feedback_type').focus();
});
</script>
