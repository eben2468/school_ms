<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$parent_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name 
    FROM users u
    JOIN parent_students ps ON u.id = ps.student_id
    WHERE ps.parent_id = :parent_id AND u.status = 'active'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $parent_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get announcements for parents
$announcements_query = "
    SELECT * FROM announcements 
    WHERE (target_audience = 'all' OR target_audience = 'parents') 
    AND status = 'published' 
    AND (publish_date IS NULL OR publish_date <= NOW())
    AND (expiry_date IS NULL OR expiry_date >= NOW())
    ORDER BY created_at DESC 
    LIMIT 10
";
$announcements_stmt = $db->prepare($announcements_query);
$announcements_stmt->execute();
$announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications for this parent
$notifications_query = "
    SELECT * FROM notifications 
    WHERE user_id = :parent_id 
    ORDER BY created_at DESC 
    LIMIT 20
";
$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->bindParam(':parent_id', $parent_id);
$notifications_stmt->execute();
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Mark notification as read if requested
if (isset($_GET['mark_read']) && is_numeric($_GET['mark_read'])) {
    $notification_id = $_GET['mark_read'];
    $mark_read_query = "UPDATE notifications SET is_read = 1 WHERE id = :id AND user_id = :parent_id";
    $mark_read_stmt = $db->prepare($mark_read_query);
    $mark_read_stmt->bindParam(':id', $notification_id);
    $mark_read_stmt->bindParam(':parent_id', $parent_id);
    $mark_read_stmt->execute();
    header("Location: messages.php");
    exit();
}

function getPriorityColor($priority) {
    switch($priority) {
        case 'urgent': return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        case 'high': return 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200';
        case 'medium': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        default: return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    }
}

function getPriorityIcon($priority) {
    switch($priority) {
        case 'urgent': return 'exclamation-triangle';
        case 'high': return 'exclamation-circle';
        case 'medium': return 'info-circle';
        default: return 'bell';
    }
}

$title = "Messages & Notifications";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Messages & Notifications</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Stay updated with school announcements and notifications</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bullhorn text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Announcements</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo count($announcements); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-bell text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Notifications</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo count($notifications); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-graduate text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">My Children</h3>
                                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo count($children); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- School Announcements -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-bullhorn text-blue-500 mr-2"></i>
                            School Announcements
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Latest announcements from the school</p>
                    </div>

                    <?php if (empty($announcements)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bullhorn text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Announcements</h3>
                        <p class="text-gray-500 dark:text-gray-400">There are no current announcements to display.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($announcements as $announcement): ?>
                        <div class="p-6">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mr-3">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                        </h4>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getPriorityColor($announcement['priority']); ?>">
                                            <i class="fas fa-<?php echo getPriorityIcon($announcement['priority']); ?> mr-1"></i>
                                            <?php echo ucfirst($announcement['priority']); ?>
                                        </span>
                                    </div>
                                    <p class="text-gray-700 dark:text-gray-300 mb-3">
                                        <?php echo nl2br(htmlspecialchars($announcement['content'])); ?>
                                    </p>
                                    <div class="flex items-center text-sm text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-calendar mr-2"></i>
                                        <?php echo date('M j, Y g:i A', strtotime($announcement['created_at'])); ?>
                                        <span class="mx-2">•</span>
                                        <i class="fas fa-users mr-2"></i>
                                        <?php echo ucfirst($announcement['target_audience']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Personal Notifications -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-bell text-green-500 mr-2"></i>
                            Personal Notifications
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Notifications specific to you and your children</p>
                    </div>

                    <?php if (empty($notifications)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-bell-slash text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Notifications</h3>
                        <p class="text-gray-500 dark:text-gray-400">You have no personal notifications at this time.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($notifications as $notification): ?>
                        <div class="p-6 <?php echo $notification['is_read'] ? 'opacity-75' : 'bg-blue-50 dark:bg-blue-900/20'; ?>">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center mb-2">
                                        <div class="w-8 h-8 rounded-full flex items-center justify-center mr-3
                                            <?php
                                            switch($notification['type']) {
                                                case 'success': echo 'bg-green-100 dark:bg-green-900'; break;
                                                case 'warning': echo 'bg-yellow-100 dark:bg-yellow-900'; break;
                                                case 'error': echo 'bg-red-100 dark:bg-red-900'; break;
                                                default: echo 'bg-blue-100 dark:bg-blue-900'; break;
                                            }
                                            ?>">
                                            <i class="fas fa-<?php
                                            switch($notification['type']) {
                                                case 'success': echo 'check text-green-600 dark:text-green-400'; break;
                                                case 'warning': echo 'exclamation-triangle text-yellow-600 dark:text-yellow-400'; break;
                                                case 'error': echo 'times text-red-600 dark:text-red-400'; break;
                                                default: echo 'info text-blue-600 dark:text-blue-400'; break;
                                            }
                                            ?> text-sm"></i>
                                        </div>
                                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($notification['title']); ?>
                                        </h4>
                                        <?php if (!$notification['is_read']): ?>
                                        <span class="ml-2 w-2 h-2 bg-blue-500 rounded-full"></span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-gray-700 dark:text-gray-300 mb-3">
                                        <?php echo nl2br(htmlspecialchars($notification['message'])); ?>
                                    </p>
                                    <div class="flex items-center justify-between">
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            <i class="fas fa-clock mr-2"></i>
                                            <?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?>
                                        </div>
                                        <?php if (!$notification['is_read']): ?>
                                        <a href="?mark_read=<?php echo $notification['id']; ?>" 
                                           class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-check mr-1"></i>Mark as Read
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
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
