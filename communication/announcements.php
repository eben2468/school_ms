<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Handle announcement creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_announcement'])) {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $content = filter_input(INPUT_POST, 'content', FILTER_SANITIZE_STRING);
    $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING);
    $target_audience = filter_input(INPUT_POST, 'target_audience', FILTER_SANITIZE_STRING);
    $publish_date = filter_input(INPUT_POST, 'publish_date', FILTER_SANITIZE_STRING);
    $expiry_date = filter_input(INPUT_POST, 'expiry_date', FILTER_SANITIZE_STRING);

    try {
        $query = "INSERT INTO announcements (title, content, priority, target_audience, author_id, publish_date, expiry_date, status) 
                  VALUES (:title, :content, :priority, :target_audience, :author_id, :publish_date, :expiry_date, 'published')";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':title', $title);
        $stmt->bindParam(':content', $content);
        $stmt->bindParam(':priority', $priority);
        $stmt->bindParam(':target_audience', $target_audience);
        $stmt->bindParam(':author_id', $user_id);
        $stmt->bindParam(':publish_date', $publish_date);
        $stmt->bindParam(':expiry_date', $expiry_date);
        $stmt->execute();
        
        $success = "Announcement created successfully!";
    } catch (PDOException $e) {
        $error = "Error creating announcement: " . $e->getMessage();
    }
}

// Get announcements with filters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);
$priority_filter = filter_input(INPUT_GET, 'priority', FILTER_SANITIZE_STRING);
$audience_filter = filter_input(INPUT_GET, 'audience', FILTER_SANITIZE_STRING);

$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(a.title LIKE :search OR a.content LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($priority_filter && $priority_filter !== 'all') {
    $where_conditions[] = "a.priority = :priority";
    $params[':priority'] = $priority_filter;
}

if ($audience_filter && $audience_filter !== 'all') {
    $where_conditions[] = "a.target_audience = :audience";
    $params[':audience'] = $audience_filter;
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Fetch announcements
$query = "SELECT a.*, u.name as author_name
          FROM announcements a
          JOIN users u ON a.author_id = u.id
          $where_clause
          ORDER BY a.priority DESC, a.created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Announcements";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8" style="margin-top: 30px;">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Announcements</h1>
                                <p class="text-blue-100 text-lg">Stay informed with the latest school announcements</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-bullhorn mr-2"></i>
                                        School Updates
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-bullhorn text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="flex justify-between items-center mb-6">
                    <div></div>
                    <div class="flex space-x-3">
                        <a href="index.php" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Communication
                        </a>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <button onclick="openCreateModal()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-plus mr-2"></i>New Announcement
                        </button>
                        <?php endif; ?>
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

                <!-- Filters -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label for="search" class="block text-sm font-medium text-gray-700">Search</label>
                            <input type="text" id="search" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                                placeholder="Search announcements..."
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label for="priority" class="block text-sm font-medium text-gray-700">Priority</label>
                            <select id="priority" name="priority" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="all">All Priorities</option>
                                <option value="urgent" <?php echo $priority_filter === 'urgent' ? 'selected' : ''; ?>>Urgent</option>
                                <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                                <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                                <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            </select>
                        </div>
                        <div>
                            <label for="audience" class="block text-sm font-medium text-gray-700">Audience</label>
                            <select id="audience" name="audience" class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                                <option value="all">All Audiences</option>
                                <option value="all" <?php echo $audience_filter === 'all' ? 'selected' : ''; ?>>Everyone</option>
                                <option value="students" <?php echo $audience_filter === 'students' ? 'selected' : ''; ?>>Students</option>
                                <option value="teachers" <?php echo $audience_filter === 'teachers' ? 'selected' : ''; ?>>Teachers</option>
                                <option value="parents" <?php echo $audience_filter === 'parents' ? 'selected' : ''; ?>>Parents</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Announcements List -->
                <div class="space-y-4">
                    <?php if (!empty($announcements)): ?>
                        <?php foreach ($announcements as $announcement): ?>
                        <div class="bg-white rounded-lg shadow p-6 border-l-4 
                            <?php 
                            switch($announcement['priority']) {
                                case 'urgent': echo 'border-red-500'; break;
                                case 'high': echo 'border-orange-500'; break;
                                case 'medium': echo 'border-yellow-500'; break;
                                default: echo 'border-blue-500';
                            }
                            ?>">
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1">
                                    <div class="flex items-center space-x-3 mb-2">
                                        <h3 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                                            <?php 
                                            switch($announcement['priority']) {
                                                case 'urgent': echo 'bg-red-100 text-red-800'; break;
                                                case 'high': echo 'bg-orange-100 text-orange-800'; break;
                                                case 'medium': echo 'bg-yellow-100 text-yellow-800'; break;
                                                default: echo 'bg-blue-100 text-blue-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($announcement['priority']); ?>
                                        </span>
                                        <span class="px-2 py-1 text-xs bg-gray-100 text-gray-800 rounded-full">
                                            <?php echo ucfirst($announcement['target_audience']); ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-600 mb-3"><?php echo nl2br(htmlspecialchars($announcement['content'])); ?></p>
                                    <div class="flex items-center space-x-4 text-sm text-gray-500">
                                        <span><i class="fas fa-user mr-1"></i><?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                        <span><i class="fas fa-calendar mr-1"></i><?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?></span>
                                        <?php if ($announcement['expiry_date']): ?>
                                        <span><i class="fas fa-clock mr-1"></i>Expires: <?php echo date('M j, Y', strtotime($announcement['expiry_date'])); ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (in_array($user_role, ['super_admin', 'school_admin']) || $announcement['author_id'] == $user_id): ?>
                                <div class="flex space-x-2">
                                    <button class="text-blue-600 hover:text-blue-800">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bullhorn text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">No announcements found</h3>
                        <p class="text-gray-500 mb-4">
                            <?php if ($search || $priority_filter || $audience_filter): ?>
                                Try adjusting your search criteria.
                            <?php else: ?>
                                No announcements have been posted yet.
                            <?php endif; ?>
                        </p>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <button onclick="openCreateModal()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            Create First Announcement
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
<!-- Create Announcement Modal -->
<div id="createModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-medium text-gray-900">Create New Announcement</h3>
                <button onclick="closeCreateModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form method="POST" class="space-y-4">
                <div>
                    <label for="title" class="block text-sm font-medium text-gray-700">Title</label>
                    <input type="text" id="title" name="title" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                </div>
                <div>
                    <label for="content" class="block text-sm font-medium text-gray-700">Content</label>
                    <textarea id="content" name="content" rows="4" required
                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md"></textarea>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="priority" class="block text-sm font-medium text-gray-700">Priority</label>
                        <select id="priority" name="priority" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                    <div>
                        <label for="target_audience" class="block text-sm font-medium text-gray-700">Target Audience</label>
                        <select id="target_audience" name="target_audience" required class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                            <option value="all">Everyone</option>
                            <option value="students">Students</option>
                            <option value="teachers">Teachers</option>
                            <option value="parents">Parents</option>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label for="publish_date" class="block text-sm font-medium text-gray-700">Publish Date</label>
                        <input type="datetime-local" id="publish_date" name="publish_date"
                            value="<?php echo date('Y-m-d\TH:i'); ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                    </div>
                    <div>
                        <label for="expiry_date" class="block text-sm font-medium text-gray-700">Expiry Date (Optional)</label>
                        <input type="date" id="expiry_date" name="expiry_date"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 pt-4">
                    <button type="button" onclick="closeCreateModal()" 
                        class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                        Cancel
                    </button>
                    <button type="submit" name="create_announcement"
                        class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Create Announcement
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function openCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}

function closeCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}
</script>
