<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Determine a student's own active class so their view is scoped to it.
$student_class_id = null;
if ($role === 'student') {
    try {
        $class_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $class_stmt->execute([':student_id' => $user_id]);
        $student_class_id = $class_stmt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        // Ignore
    }
}

// Handle form submission for creating new discussion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_discussion') {
    $disc_title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);

    if ($disc_title && $description) {
        try {
            $query = "INSERT INTO discussion_boards (title, description, class_id, subject_id, created_by, created_at)
                     VALUES (:title, :description, :class_id, :subject_id, :created_by, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':title', $disc_title);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->bindParam(':subject_id', $subject_id);
            $stmt->bindParam(':created_by', $_SESSION['user_id']);
            $stmt->execute();

            $success = "Discussion created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating discussion: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Handle posting to discussion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'post_message') {
    $discussion_id = filter_input(INPUT_POST, 'discussion_id', FILTER_SANITIZE_NUMBER_INT);
    $content = filter_input(INPUT_POST, 'content', FILTER_SANITIZE_STRING);
    $parent_post_id = filter_input(INPUT_POST, 'parent_post_id', FILTER_SANITIZE_NUMBER_INT);

    if ($discussion_id && $content) {
        try {
            $query = "INSERT INTO discussion_posts (board_id, user_id, content, parent_post_id, created_at)
                     VALUES (:board_id, :user_id, :content, :parent_post_id, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':board_id', $discussion_id);
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':content', $content);
            $stmt->bindParam(':parent_post_id', $parent_post_id);
            $stmt->execute();

            $success = "Message posted successfully!";
        } catch (PDOException $e) {
            $error = "Error posting message: " . $e->getMessage();
        }
    }
}

// Get discussions with filters
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$subject_filter = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);

$where_conditions = ["1=1"]; // Remove status filter since discussion_boards doesn't have status column
$params = [];

if ($class_filter) {
    $where_conditions[] = "d.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($subject_filter) {
    $where_conditions[] = "d.subject_id = :subject_id";
    $params[':subject_id'] = $subject_filter;
}

if ($search) {
    $where_conditions[] = "(d.title LIKE :search OR d.description LIKE :search)";
    $params[':search'] = "%$search%";
}

// Students only see discussions for their own active class, plus
// general discussions that are not tied to any class.
if ($role === 'student') {
    if ($student_class_id) {
        $where_conditions[] = "(d.class_id = :student_class_id OR d.class_id IS NULL)";
        $params[':student_class_id'] = $student_class_id;
    } else {
        $where_conditions[] = "d.class_id IS NULL";
    }
}

$where_clause = implode(' AND ', $where_conditions);

// Get discussions
$discussions_query = "SELECT d.*, u.name as created_by_name, c.name as class_name, s.name as subject_name,
                             d.post_count,
                             d.last_activity
                      FROM discussion_boards d
                      LEFT JOIN users u ON d.created_by = u.id
                      LEFT JOIN classes c ON d.class_id = c.id
                      LEFT JOIN subjects s ON d.subject_id = s.id
                      WHERE $where_clause
                      ORDER BY d.created_at DESC";
$discussions_stmt = $db->prepare($discussions_query);
foreach ($params as $key => $value) {
    $discussions_stmt->bindValue($key, $value);
}
$discussions_stmt->execute();
$discussions = $discussions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes and subjects for filter/modal dropdowns
$classes = [];
$subjects = [];
try {
    if ($role === 'student') {
        // Students only get their own active class (and its subjects) in the
        // dropdowns, so no other class names are exposed in the UI.
        $cls_stmt = $db->prepare("SELECT id, name FROM classes WHERE id = :cid ORDER BY name");
        $cls_stmt->execute([':cid' => $student_class_id ?: 0]);
        $classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);

        $subj_stmt = $db->prepare("SELECT id, name, class_id FROM subjects WHERE class_id = :cid ORDER BY name");
        $subj_stmt->execute([':cid' => $student_class_id ?: 0]);
        $subjects = $subj_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $classes = $db->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = $db->query("SELECT id, name, class_id FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Ignore
}

$title = "Online Discussions";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Online Learning', 'url' => 'index.php'],
    ['title' => 'Discussions']
];

include '../includes/header.php';
include '../includes/sidebar.php';
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
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Online Discussions</h1>
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher'])): ?>
                    <button onclick="showCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>New Discussion
                    </button>
                    <?php endif; ?>
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

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-4">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label for="search" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Search</label>
                                <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                       class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                       placeholder="Search discussions...">
                            </div>
                            <div>
                                <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class</label>
                                <select id="class_id" name="class_id"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value=""><?php echo $role === 'student' ? 'My Class' : 'All Classes'; ?></option>
                                    <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($class['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
                                <select id="subject_id" name="subject_id"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value=""><?php echo $role === 'student' ? 'My Subjects' : 'All Subjects'; ?></option>
                                    <?php foreach ($subjects as $subject): ?>
                                        <option value="<?php echo $subject['id']; ?>" <?php echo $subject_filter == $subject['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($subject['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-search mr-2"></i>Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Discussions List -->
                <div class="space-y-4">
                    <?php if (!empty($discussions)): ?>
                        <?php foreach ($discussions as $discussion): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                            <div class="p-6">
                                <div class="flex justify-between items-start mb-4">
                                    <div class="flex-1">
                                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">
                                            <a href="discussion_view.php?id=<?php echo $discussion['id']; ?>" class="hover:text-blue-600">
                                                <?php echo htmlspecialchars($discussion['title']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-gray-600 dark:text-gray-400 mb-3"><?php echo htmlspecialchars($discussion['description']); ?></p>
                                        <div class="flex items-center space-x-4 text-sm text-gray-500 dark:text-gray-400">
                                            <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($discussion['created_by_name']); ?></span>
                                            <?php if ($discussion['class_name']): ?>
                                                <span><i class="fas fa-users mr-1"></i><?php echo htmlspecialchars($discussion['class_name']); ?></span>
                                            <?php endif; ?>
                                            <?php if ($discussion['subject_name']): ?>
                                                <span><i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($discussion['subject_name']); ?></span>
                                            <?php endif; ?>
                                            <span><i class="fas fa-clock mr-1"></i><?php echo date('M j, Y', strtotime($discussion['created_at'])); ?></span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            <div><?php echo $discussion['post_count']; ?> posts</div>
                                            <?php if ($discussion['last_activity']): ?>
                                                <div>Last: <?php echo date('M j', strtotime($discussion['last_activity'])); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex justify-between items-center">
                                    <div class="flex space-x-2">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                            <?php echo $discussion['is_pinned'] ? 'Pinned' : 'Active'; ?>
                                        </span>
                                        <?php if ($discussion['is_locked']): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                            Locked
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                    <a href="discussion_view.php?id=<?php echo $discussion['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                        View Discussion <i class="fas fa-arrow-right ml-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                            <div class="p-12 text-center">
                                <i class="fas fa-comments text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Discussions Found</h3>
                                <p class="text-gray-500 dark:text-gray-400 mb-4">There are no discussions matching your criteria.</p>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher'])): ?>
                                <button onclick="showCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i>Create First Discussion
                                </button>
                                <?php endif; ?>
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

<!-- Create Discussion Modal -->
<div id="create-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create New Discussion</h3>
                    <button onclick="hideCreateModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="create_discussion">
                    <div class="space-y-4">
                        <div>
                            <label for="modal_title" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Title *</label>
                            <input type="text" id="modal_title" name="title" required
                                   class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label for="modal_class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class</label>
                            <select id="modal_class_id" name="class_id"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="modal_subject_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Subject</label>
                            <select id="modal_subject_id" name="subject_id"
                                    class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">All Subjects</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"><?php echo htmlspecialchars($subject['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="modal_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description *</label>
                            <textarea id="modal_description" name="description" rows="3" required
                                      class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                        </div>
                    </div>
                    <div class="flex justify-end space-x-3 mt-6">
                        <button type="button" onclick="hideCreateModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg">
                            Cancel
                        </button>
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>Create Discussion
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function showCreateModal() {
    document.getElementById('create-modal').classList.remove('hidden');
}

function hideCreateModal() {
    document.getElementById('create-modal').classList.add('hidden');
}
</script>
