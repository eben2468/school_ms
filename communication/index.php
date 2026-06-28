<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('communication');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('communication'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get recent announcements
$announcements_query = "SELECT a.*, u.name as author_name
    FROM announcements a
    JOIN users u ON a.author_id = u.id
    WHERE a.status = 'published' 
    AND (a.target_audience = 'all' OR a.target_audience = :user_role)
    AND (a.publish_date IS NULL OR a.publish_date <= NOW())
    AND (a.expiry_date IS NULL OR a.expiry_date >= NOW())
    ORDER BY a.priority DESC, a.created_at DESC
    LIMIT 10";
$announcements_stmt = $db->prepare($announcements_query);
$announcements_stmt->bindParam(':user_role', $user_role);
$announcements_stmt->execute();
$announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread messages count
$unread_messages_query = "SELECT COUNT(*) as count FROM messages WHERE recipient_id = :user_id AND is_read = FALSE";
$unread_messages_stmt = $db->prepare($unread_messages_query);
$unread_messages_stmt->bindParam(':user_id', $user_id);
$unread_messages_stmt->execute();
$unread_count = $unread_messages_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent messages
$messages_query = "SELECT m.*, u.name as sender_name
    FROM messages m
    JOIN users u ON m.sender_id = u.id
    WHERE m.recipient_id = :user_id
    ORDER BY m.sent_at DESC
    LIMIT 5";
$messages_stmt = $db->prepare($messages_query);
$messages_stmt->bindParam(':user_id', $user_id);
$messages_stmt->execute();
$recent_messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unread notifications count
$unread_notifications_query = "SELECT COUNT(*) as count FROM notifications WHERE user_id = :user_id AND is_read = FALSE";
$unread_notifications_stmt = $db->prepare($unread_notifications_query);
$unread_notifications_stmt->bindParam(':user_id', $user_id);
$unread_notifications_stmt->execute();
$unread_notifications_count = $unread_notifications_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get recent notifications
$notifications_query = "SELECT * FROM notifications 
    WHERE user_id = :user_id 
    ORDER BY created_at DESC 
    LIMIT 5";
$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->bindParam(':user_id', $user_id);
$notifications_stmt->execute();
$recent_notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
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
                                <h1 class="text-3xl font-bold mb-2">Communication Center</h1>
                                <p class="text-blue-100 text-lg">Connect, collaborate, and stay informed</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-envelope mr-2"></i>
                                        Messages & Announcements
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-comments text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end items-center mb-6">
                    <div class="flex space-x-3">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <a href="announcements.php?create=1" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-bullhorn mr-2"></i>New Announcement
                        </a>
                        <?php endif; ?>
                        <?php if ($user_role !== 'parent'): ?>
                        <a href="messages/compose.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-envelope mr-2"></i>Compose Message
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

            <!-- Quick Stats -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100">
                            <i class="fas fa-envelope text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Unread Messages</p>
                            <p class="text-2xl font-semibold text-blue-600"><?php echo $unread_count; ?></p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="messages/" class="text-blue-600 hover:text-blue-800 text-sm font-medium">View All Messages</a>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100">
                            <i class="fas fa-bell text-yellow-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Unread Notifications</p>
                            <p class="text-2xl font-semibold text-yellow-600"><?php echo $unread_notifications_count; ?></p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="../notifications.php" class="text-yellow-600 hover:text-yellow-800 text-sm font-medium">View All Notifications</a>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100">
                            <i class="fas fa-bullhorn text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Active Announcements</p>
                            <p class="text-2xl font-semibold text-green-600"><?php echo count($announcements); ?></p>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="announcements.php" class="text-green-600 hover:text-green-800 text-sm font-medium">View All Announcements</a>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Recent Announcements -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Announcements</h2>
                        <a href="announcements.php" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($announcements)): ?>
                        <div class="space-y-4">
                            <?php foreach ($announcements as $announcement): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <h3 class="font-medium text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?php 
                                        switch($announcement['priority']) {
                                            case 'urgent': echo 'bg-red-100 text-red-800'; break;
                                            case 'high': echo 'bg-orange-100 text-orange-800'; break;
                                            case 'medium': echo 'bg-yellow-100 text-yellow-800'; break;
                                            default: echo 'bg-gray-100 text-gray-800';
                                        }
                                        ?>">
                                        <?php echo ucfirst($announcement['priority']); ?>
                                    </span>
                                </div>
                                <p class="text-sm text-gray-600 mb-2 line-clamp-2"><?php echo htmlspecialchars($announcement['content']); ?></p>
                                <div class="flex justify-between items-center text-xs text-gray-500">
                                    <span>by <?php echo htmlspecialchars($announcement['author_name']); ?></span>
                                    <span><?php echo date('M j, Y', strtotime($announcement['created_at'])); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-bullhorn text-gray-400 text-3xl mb-2"></i>
                            <p class="text-gray-500">No announcements available</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Messages -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Messages</h2>
                        <a href="messages/" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_messages)): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_messages as $message): ?>
                            <div class="flex items-start space-x-3 p-3 border border-gray-200 rounded-lg <?php echo !$message['is_read'] ? 'bg-blue-50 border-blue-200' : ''; ?>">
                                <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-gray-600 text-sm"></i>
                                </div>
                                <div class="flex-grow min-w-0">
                                    <div class="flex justify-between items-start">
                                        <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($message['sender_name']); ?></p>
                                        <p class="text-xs text-gray-500"><?php echo date('M j', strtotime($message['sent_at'])); ?></p>
                                    </div>
                                    <p class="text-sm text-gray-600 font-medium"><?php echo htmlspecialchars($message['subject']); ?></p>
                                    <p class="text-sm text-gray-500 truncate"><?php echo htmlspecialchars(substr($message['content'], 0, 60)) . '...'; ?></p>
                                </div>
                                <?php if (!$message['is_read']): ?>
                                <div class="w-2 h-2 bg-blue-600 rounded-full"></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-envelope text-gray-400 text-3xl mb-2"></i>
                            <p class="text-gray-500">No messages yet</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Recent Notifications -->
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                    <h2 class="text-lg font-semibold text-gray-800">Recent Notifications</h2>
                    <a href="../notifications.php" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                </div>
                <div class="p-6">
                    <?php if (!empty($recent_notifications)): ?>
                    <div class="space-y-3">
                        <?php foreach ($recent_notifications as $notification): ?>
                        <div class="flex items-start space-x-3 p-3 border border-gray-200 rounded-lg <?php echo !$notification['is_read'] ? 'bg-blue-50 border-blue-200' : ''; ?>">
                            <div class="p-2 rounded-full 
                                <?php 
                                switch($notification['type']) {
                                    case 'success': echo 'bg-green-100'; break;
                                    case 'warning': echo 'bg-yellow-100'; break;
                                    case 'error': echo 'bg-red-100'; break;
                                    default: echo 'bg-blue-100';
                                }
                                ?>">
                                <i class="fas fa-
                                    <?php 
                                    switch($notification['type']) {
                                        case 'success': echo 'check text-green-600'; break;
                                        case 'warning': echo 'exclamation-triangle text-yellow-600'; break;
                                        case 'error': echo 'times text-red-600'; break;
                                        default: echo 'info text-blue-600';
                                    }
                                    ?> text-sm"></i>
                            </div>
                            <div class="flex-grow">
                                <div class="flex justify-between items-start">
                                    <p class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($notification['title']); ?></p>
                                    <p class="text-xs text-gray-500"><?php echo date('M j, g:i A', strtotime($notification['created_at'])); ?></p>
                                </div>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($notification['message']); ?></p>
                            </div>
                            <?php if (!$notification['is_read']): ?>
                            <div class="w-2 h-2 bg-blue-600 rounded-full"></div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-bell text-gray-400 text-3xl mb-2"></i>
                        <p class="text-gray-500">No notifications yet</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Quick Actions</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <?php if ($user_role !== 'parent'): ?>
                        <a href="messages/compose.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-envelope text-blue-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Send Message</div>
                                <div class="text-sm text-gray-500">Compose a new message</div>
                            </div>
                        </a>
                        <?php endif; ?>

                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                        <a href="announcements.php?create=1" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-bullhorn text-green-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Create Announcement</div>
                                <div class="text-sm text-gray-500">Broadcast to school community</div>
                            </div>
                        </a>
                        <?php endif; ?>
                        
                        <a href="javascript:void(0)" onclick="markAllNotificationsAsRead()" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-check-double text-yellow-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Mark All Read</div>
                                <div class="text-sm text-gray-500">Clear all notifications</div>
                            </div>
                        </a>
                    </div>
                </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function markAllNotificationsAsRead() {
    if (confirm('Mark all notifications as read?')) {
        fetch('notifications/mark_all_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('All notifications marked as read.');
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred.');
        });
    }
}
</script>
