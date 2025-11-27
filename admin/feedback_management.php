<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle feedback response
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'respond_feedback') {
        $feedback_id = filter_input(INPUT_POST, 'feedback_id', FILTER_SANITIZE_NUMBER_INT);
        $response = filter_input(INPUT_POST, 'response', FILTER_SANITIZE_STRING);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);

        if ($feedback_id && $response) {
            try {
                $query = "UPDATE feedback SET admin_response = :response, status = :status, responded_by = :admin_id, responded_at = NOW() WHERE id = :feedback_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':response', $response);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':admin_id', $_SESSION['user_id']);
                $stmt->bindParam(':feedback_id', $feedback_id);
                
                if ($stmt->execute()) {
                    $success = "Response sent successfully!";
                } else {
                    $error = "Error sending response.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: 'all';
$type_filter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'all';
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "f.status = :status";
    $params[':status'] = $status_filter;
}

if ($type_filter !== 'all') {
    $where_conditions[] = "f.feedback_type = :type";
    $params[':type'] = $type_filter;
}

if ($search) {
    $where_conditions[] = "(f.subject LIKE :search OR f.message LIKE :search OR u.name LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get feedback with pagination
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$feedback_query = "SELECT f.*, u.name as user_name, u.email as user_email, u.role as user_role,
                          admin.name as responded_by_name
                   FROM feedback f
                   LEFT JOIN users u ON f.user_id = u.id
                   LEFT JOIN users admin ON f.responded_by = admin.id
                   WHERE $where_clause
                   ORDER BY f.created_at DESC
                   LIMIT :offset, :per_page";

$stmt = $db->prepare($feedback_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$feedback_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM feedback f LEFT JOIN users u ON f.user_id = u.id WHERE $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_feedback = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_feedback / $per_page);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'reviewed' THEN 1 END) as reviewed,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved,
    COUNT(CASE WHEN feedback_type = 'complaint' THEN 1 END) as complaints,
    COUNT(CASE WHEN feedback_type = 'suggestion' THEN 1 END) as suggestions,
    AVG(rating) as avg_rating
    FROM feedback";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Feedback Management";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-xl p-6 mb-8 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-comments mr-3"></i>
                                Feedback Management
                            </h1>
                            <p class="text-purple-100">Review and respond to user feedback</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold"><?= number_format($stats['avg_rating'], 1) ?>/5</div>
                            <div class="text-sm text-purple-100">Average Rating</div>
                        </div>
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

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                                <i class="fas fa-comments text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Feedback</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['pending']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100 text-red-600">
                                <i class="fas fa-exclamation-triangle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Complaints</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['complaints']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-600">
                                <i class="fas fa-lightbulb text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Suggestions</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['suggestions']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-64">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Search feedback..."
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                                <option value="reviewed" <?= $status_filter === 'reviewed' ? 'selected' : '' ?>>Reviewed</option>
                                <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Type</label>
                            <select name="type" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="all" <?= $type_filter === 'all' ? 'selected' : '' ?>>All Types</option>
                                <option value="suggestion" <?= $type_filter === 'suggestion' ? 'selected' : '' ?>>Suggestion</option>
                                <option value="complaint" <?= $type_filter === 'complaint' ? 'selected' : '' ?>>Complaint</option>
                                <option value="compliment" <?= $type_filter === 'compliment' ? 'selected' : '' ?>>Compliment</option>
                                <option value="bug_report" <?= $type_filter === 'bug_report' ? 'selected' : '' ?>>Bug Report</option>
                            </select>
                        </div>

                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                    </form>
                </div>

                <!-- Feedback List -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Feedback List</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Review and respond to user feedback</p>
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (!empty($feedback_list)): ?>
                            <?php foreach ($feedback_list as $feedback): ?>
                            <div class="p-6 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-3">
                                            <div class="w-10 h-10 bg-blue-500 rounded-full flex items-center justify-center text-white font-semibold">
                                                <?= strtoupper(substr($feedback['user_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($feedback['user_name']) ?></h3>
                                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($feedback['user_email']) ?> • <?= ucfirst($feedback['user_role']) ?></p>
                                            </div>
                                        </div>

                                        <div class="mb-3">
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
                                                <h4 class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($feedback['subject']) ?></h4>
                                                <?php if ($feedback['rating']): ?>
                                                <div class="flex items-center">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <i class="fas fa-star text-sm <?= $i <= $feedback['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                                                    <?php endfor; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-gray-700 dark:text-gray-300"><?= nl2br(htmlspecialchars($feedback['message'])) ?></p>
                                        </div>

                                        <?php if ($feedback['admin_response']): ?>
                                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-3">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <i class="fas fa-reply text-blue-600"></i>
                                                <span class="font-medium text-blue-900 dark:text-blue-100">Admin Response</span>
                                                <span class="text-sm text-blue-600 dark:text-blue-300">by <?= htmlspecialchars($feedback['responded_by_name']) ?></span>
                                            </div>
                                            <p class="text-blue-800 dark:text-blue-200"><?= nl2br(htmlspecialchars($feedback['admin_response'])) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                                            <span><?= date('M j, Y g:i A', strtotime($feedback['created_at'])) ?></span>
                                            <?php if ($feedback['responded_at']): ?>
                                            <span>Responded: <?= date('M j, Y g:i A', strtotime($feedback['responded_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="ml-6 flex flex-col items-end space-y-2">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($feedback['status']) {
                                                case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'reviewed': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'resolved': echo 'bg-green-100 text-green-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?= ucfirst($feedback['status']) ?>
                                        </span>

                                        <button onclick="openResponseModal(<?= $feedback['id'] ?>, '<?= htmlspecialchars($feedback['subject'], ENT_QUOTES) ?>', '<?= htmlspecialchars($feedback['admin_response'] ?? '', ENT_QUOTES) ?>', '<?= $feedback['status'] ?>')"
                                                class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm">
                                            <i class="fas fa-reply mr-1"></i>
                                            <?= $feedback['admin_response'] ? 'Update Response' : 'Respond' ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-12 text-center">
                                <i class="fas fa-comments text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Feedback Found</h3>
                                <p class="text-gray-500 dark:text-gray-400">No feedback matches your current filters.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-700 dark:text-gray-300">
                                Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $total_feedback) ?> of <?= $total_feedback ?> results
                            </div>
                            <div class="flex space-x-2">
                                <?php if ($page > 1): ?>
                                <a href="?page=<?= $page - 1 ?>&status=<?= $status_filter ?>&type=<?= $type_filter ?>&search=<?= urlencode($search) ?>"
                                   class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    Previous
                                </a>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?= $i ?>&status=<?= $status_filter ?>&type=<?= $type_filter ?>&search=<?= urlencode($search) ?>"
                                   class="px-3 py-2 border rounded-md text-sm font-medium <?= $i === $page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                                    <?= $i ?>
                                </a>
                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>
                                <a href="?page=<?= $page + 1 ?>&status=<?= $status_filter ?>&type=<?= $type_filter ?>&search=<?= urlencode($search) ?>"
                                   class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                                    Next
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
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

<!-- Response Modal -->
<div id="responseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white" id="modalTitle">Respond to Feedback</h3>
                <button onclick="closeResponseModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="responseForm">
                <input type="hidden" name="action" value="respond_feedback">
                <input type="hidden" name="feedback_id" id="feedbackId">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Response</label>
                    <textarea name="response" id="responseText" rows="4" required
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                              placeholder="Write your response to the user..."></textarea>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                    <select name="status" id="statusSelect" required
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="reviewed">Reviewed</option>
                        <option value="resolved">Resolved</option>
                        <option value="pending">Pending</option>
                    </select>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeResponseModal()"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium">
                        <i class="fas fa-paper-plane mr-2"></i>Send Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openResponseModal(feedbackId, subject, currentResponse, currentStatus) {
    document.getElementById('feedbackId').value = feedbackId;
    document.getElementById('responseText').value = currentResponse || '';
    document.getElementById('statusSelect').value = currentStatus || 'reviewed';
    document.getElementById('modalTitle').textContent = currentResponse ? 'Update Response to: ' + subject : 'Respond to: ' + subject;
    document.getElementById('responseModal').classList.remove('hidden');
    document.getElementById('responseText').focus();
}

function closeResponseModal() {
    document.getElementById('responseModal').classList.add('hidden');
    document.getElementById('responseForm').reset();
}

// Close modal when clicking outside
document.getElementById('responseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeResponseModal();
    }
});

// Close modal with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeResponseModal();
    }
});
</script>
