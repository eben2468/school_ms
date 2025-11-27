<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$discussion_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$discussion_id) {
    header("Location: discussions.php");
    exit();
}

// Handle new post submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_message') {
    $content = filter_input(INPUT_POST, 'content', FILTER_SANITIZE_STRING);
    $parent_post_id = filter_input(INPUT_POST, 'parent_post_id', FILTER_SANITIZE_NUMBER_INT);

    if ($content) {
        try {
            $query = "INSERT INTO discussion_posts (board_id, user_id, content, parent_post_id, created_at)
                     VALUES (:board_id, :user_id, :content, :parent_post_id, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':board_id', $discussion_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':parent_post_id', $parent_post_id);
            $stmt->execute();

            // Update post count in discussion board
            $update_query = "UPDATE discussion_boards SET post_count = post_count + 1, last_activity = NOW() WHERE id = :id";
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':id', $discussion_id);
            $update_stmt->execute();

            $success = "Message posted successfully!";
        } catch (PDOException $e) {
            $error = "Error posting message: " . $e->getMessage();
        }
    }
}

// Get discussion details
$discussion_query = "SELECT d.*, u.name as created_by_name, c.name as class_name, s.name as subject_name
                    FROM discussion_boards d
                    LEFT JOIN users u ON d.created_by = u.id
                    LEFT JOIN classes c ON d.class_id = c.id
                    LEFT JOIN subjects s ON d.subject_id = s.id
                    WHERE d.id = :id";
$discussion_stmt = $db->prepare($discussion_query);
$discussion_stmt->bindParam(':id', $discussion_id);
$discussion_stmt->execute();
$discussion = $discussion_stmt->fetch(PDO::FETCH_ASSOC);

if (!$discussion) {
    header("Location: discussions.php");
    exit();
}

// Get discussion posts
$posts_query = "SELECT p.*, u.name as user_name, u.profile_picture
               FROM discussion_posts p
               LEFT JOIN users u ON p.user_id = u.id
               WHERE p.board_id = :discussion_id
               ORDER BY p.created_at ASC";
$posts_stmt = $db->prepare($posts_query);
$posts_stmt->bindParam(':discussion_id', $discussion_id);
$posts_stmt->execute();
$posts = $posts_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Discussion: " . $discussion['title'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Back Button -->
                <div class="mb-6">
                    <a href="discussions.php" class="inline-flex items-center text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Discussions
                    </a>
                </div>

                <!-- Discussion Header -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-start justify-between mb-4">
                        <div class="flex-1">
                            <h1 class="text-2xl font-bold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($discussion['title']); ?></h1>
                            <p class="text-gray-600 dark:text-gray-400 mb-4"><?php echo htmlspecialchars($discussion['description']); ?></p>
                            <div class="flex items-center space-x-4 text-sm text-gray-500 dark:text-gray-400">
                                <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($discussion['created_by_name']); ?></span>
                                <?php if ($discussion['class_name']): ?>
                                    <span><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($discussion['class_name']); ?></span>
                                <?php endif; ?>
                                <?php if ($discussion['subject_name']): ?>
                                    <span><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($discussion['subject_name']); ?></span>
                                <?php endif; ?>
                                <span><i class="fas fa-clock mr-1"></i><?php echo date('M j, Y g:i A', strtotime($discussion['created_at'])); ?></span>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <?php if ($discussion['is_pinned']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                <i class="fas fa-thumbtack mr-1"></i>Pinned
                            </span>
                            <?php endif; ?>
                            <?php if ($discussion['is_locked']): ?>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                <i class="fas fa-lock mr-1"></i>Locked
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <?php echo $discussion['post_count']; ?> posts • Last activity: <?php echo date('M j, Y g:i A', strtotime($discussion['last_activity'])); ?>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- Discussion Posts -->
                <div class="space-y-4 mb-8">
                    <?php if (!empty($posts)): ?>
                        <?php foreach ($posts as $post): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6">
                            <div class="flex items-start space-x-4">
                                <div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-semibold">
                                    <?php echo strtoupper(substr($post['user_name'], 0, 1)); ?>
                                </div>
                                <div class="flex-1">
                                    <div class="flex items-center space-x-2 mb-2">
                                        <h4 class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($post['user_name']); ?></h4>
                                        <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo date('M j, Y g:i A', strtotime($post['created_at'])); ?></span>
                                    </div>
                                    <div class="text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?php echo htmlspecialchars($post['content']); ?></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-12 text-center">
                            <i class="fas fa-comments text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Posts Yet</h3>
                            <p class="text-gray-500 dark:text-gray-400">Be the first to start the conversation!</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Post Reply Form -->
                <?php if (!$discussion['is_locked']): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Post a Reply</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="post_message">
                        <div class="mb-4">
                            <textarea name="content" rows="4" required
                                      class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                      placeholder="Write your reply..."></textarea>
                        </div>
                        <div class="flex justify-end">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                <i class="fas fa-reply mr-2"></i>Post Reply
                            </button>
                        </div>
                    </form>
                </div>
                <?php else: ?>
                <div class="bg-gray-100 dark:bg-gray-700 rounded-lg p-6 text-center">
                    <i class="fas fa-lock text-gray-400 text-2xl mb-2"></i>
                    <p class="text-gray-600 dark:text-gray-400">This discussion is locked. No new posts can be added.</p>
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
