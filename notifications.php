<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get filter parameters
$filter_type = $_GET['type'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query based on filter
$where_conditions = [];
$params = [];

if ($filter_type !== 'all') {
    $where_conditions[] = "type = :type";
    $params[':type'] = $filter_type;
}

// Get notifications for current user or global notifications
$where_conditions[] = "(user_id = :user_id OR user_id IS NULL)";
$params[':user_id'] = $user_id;

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination (exclude dismissed and expired)
$count_query = "SELECT COUNT(*) as total FROM notifications $where_clause AND is_dismissed = FALSE AND (expires_at IS NULL OR expires_at > NOW())";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_notifications = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Get notifications with enhanced query
$notifications_query = "
    SELECT n.*,
           u.name as created_by_name,
           CASE
               WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 'just now'
               WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN CONCAT(TIMESTAMPDIFF(MINUTE, n.created_at, NOW()), ' min ago')
               WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN CONCAT(TIMESTAMPDIFF(HOUR, n.created_at, NOW()), ' hr ago')
               WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN CONCAT(TIMESTAMPDIFF(DAY, n.created_at, NOW()), ' days ago')
               ELSE DATE_FORMAT(n.created_at, '%M %d, %Y')
           END as time_ago
    FROM notifications n
    LEFT JOIN users u ON n.created_by = u.id
    $where_clause
    AND n.is_dismissed = FALSE
    AND (n.expires_at IS NULL OR n.expires_at > NOW())
    ORDER BY n.priority = 'urgent' DESC, n.priority = 'high' DESC, n.created_at DESC
    LIMIT :limit OFFSET :offset
";
$notifications_stmt = $db->prepare($notifications_query);
foreach ($params as $key => $value) {
    $notifications_stmt->bindValue($key, $value);
}
$notifications_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$notifications_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$notifications_stmt->execute();
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notification counts by type (exclude dismissed and expired)
$counts_query = "
    SELECT
        type,
        COUNT(*) as count,
        SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count
    FROM notifications
    WHERE (user_id = :user_id OR user_id IS NULL)
    AND is_dismissed = FALSE
    AND (expires_at IS NULL OR expires_at > NOW())
    GROUP BY type
";
$counts_stmt = $db->prepare($counts_query);
$counts_stmt->bindParam(':user_id', $user_id);
$counts_stmt->execute();
$type_counts = $counts_stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert to associative array for easier access
$counts = [];
foreach ($type_counts as $count) {
    $counts[$count['type']] = $count;
}

// Calculate total counts
$total_count = array_sum(array_column($type_counts, 'count'));
$total_unread = array_sum(array_column($type_counts, 'unread_count'));

$title = "Notifications";
$breadcrumbs = [
    ['title' => 'Notifications']
];

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1 transition-colors duration-300">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Notifications</h1>
                                <p class="text-blue-100 text-lg">Stay updated with the latest activities</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-bell mr-2"></i>
                                        Real-time updates
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-bell text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-end items-center mb-6">
                    <div class="flex space-x-3">
                        <button id="markAllReadBtn" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                            <i class="fas fa-check-double mr-2"></i>Mark All Read
                        </button>
                        <button id="settingsBtn" class="px-4 py-2 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-cog mr-2"></i>Settings
                        </button>
                    </div>
                </div>

                <!-- Notification Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Filter Notifications</h3>
                    <div class="flex flex-wrap gap-3">
                    <a href="?type=all" class="px-4 py-2 <?php echo $filter_type === 'all' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        All (<?php echo $total_count; ?>)
                    </a>
                    <a href="?type=academic" class="px-4 py-2 <?php echo $filter_type === 'academic' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Academic (<?php echo $counts['academic']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=finance" class="px-4 py-2 <?php echo $filter_type === 'finance' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Finance (<?php echo $counts['finance']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=system" class="px-4 py-2 <?php echo $filter_type === 'system' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        System (<?php echo $counts['system']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=announcement" class="px-4 py-2 <?php echo $filter_type === 'announcement' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Announcements (<?php echo $counts['announcement']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=attendance" class="px-4 py-2 <?php echo $filter_type === 'attendance' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Attendance (<?php echo $counts['attendance']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=grades" class="px-4 py-2 <?php echo $filter_type === 'grades' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Grades (<?php echo $counts['grades']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=events" class="px-4 py-2 <?php echo $filter_type === 'events' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Events (<?php echo $counts['events']['count'] ?? 0; ?>)
                    </a>
                    <a href="?type=library" class="px-4 py-2 <?php echo $filter_type === 'library' ? 'bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200' : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-600'; ?> rounded-lg text-sm font-medium transition-colors duration-200">
                        Library (<?php echo $counts['library']['count'] ?? 0; ?>)
                    </a>
                    </div>
                </div>

                <!-- Notifications List -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Your Notifications</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Stay updated with the latest activities and announcements</p>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <?php if (empty($notifications)): ?>
                            <div class="text-center py-12">
                                <i class="fas fa-bell text-gray-400 text-6xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Notifications</h3>
                                <p class="text-gray-500 dark:text-gray-400">You're all caught up! No new notifications at this time.</p>
                            </div>
                            <?php else: ?>
                            <?php foreach ($notifications as $notification): ?>
                            <?php
                // Get type color with enhanced mapping
                $type_colors = [
                    'academic' => 'blue',
                    'finance' => 'green',
                    'system' => 'yellow',
                    'announcement' => 'purple',
                    'attendance' => 'orange',
                    'grades' => 'indigo',
                    'events' => 'pink',
                    'library' => 'teal',
                    'transport' => 'cyan',
                    'hostel' => 'lime',
                    'canteen' => 'amber',
                    'health' => 'red',
                    'general' => 'gray'
                ];
                $color = $type_colors[$notification['type']] ?? 'gray';

                // Priority styling
                $priority_colors = [
                    'urgent' => 'red',
                    'high' => 'orange',
                    'medium' => 'yellow',
                    'low' => 'green'
                ];
                            $priority_color = $priority_colors[$notification['priority']] ?? 'gray';
                            ?>
                            <div class="bg-gray-50 dark:bg-gray-700 rounded-xl shadow-sm border border-gray-200 dark:border-gray-600 p-6 border-l-4 border-l-<?php echo $color; ?>-500 <?php echo !$notification['is_read'] ? '' : 'opacity-75'; ?>" data-notification-id="<?php echo $notification['id']; ?>">
                                <div class="flex items-start justify-between">
                                    <div class="flex items-start space-x-4">
                                        <div class="w-12 h-12 bg-<?php echo $color; ?>-100 dark:bg-<?php echo $color; ?>-900 rounded-full flex items-center justify-center">
                                            <i class="<?php echo htmlspecialchars($notification['icon']); ?> text-<?php echo $color; ?>-600 dark:text-<?php echo $color; ?>-400"></i>
                                        </div>
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <h3 class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($notification['title']); ?></h3>
                                                <span class="px-2 py-1 bg-<?php echo $color; ?>-100 dark:bg-<?php echo $color; ?>-900 text-<?php echo $color; ?>-800 dark:text-<?php echo $color; ?>-200 text-xs rounded-full"><?php echo ucfirst($notification['type']); ?></span>
                                                <?php if (!$notification['is_read']): ?>
                                                <span class="w-2 h-2 bg-<?php echo $color; ?>-500 rounded-full"></span>
                                                <?php endif; ?>
                                                <?php if ($notification['priority'] === 'high' || $notification['priority'] === 'urgent'): ?>
                                                <span class="px-2 py-1 bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 text-xs rounded-full"><?php echo ucfirst($notification['priority']); ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <p class="text-gray-600 dark:text-gray-400 mb-2"><?php echo nl2br(htmlspecialchars($notification['message'])); ?></p>
                                            <div class="flex items-center space-x-4 text-sm text-gray-500 dark:text-gray-400">
                                                <span><i class="fas fa-clock mr-1"></i><?php echo $notification['time_ago']; ?></span>
                                                <?php if ($notification['created_by_name']): ?>
                                                <span><i class="fas fa-user mr-1"></i>by <?php echo htmlspecialchars($notification['created_by_name']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($notification['action_url'] && $notification['action_text']): ?>
                                                <a href="<?php echo htmlspecialchars($notification['action_url']); ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
                                                    <i class="fas fa-external-link-alt mr-1"></i><?php echo htmlspecialchars($notification['action_text']); ?>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="flex space-x-2">
                                        <?php if (!$notification['is_read']): ?>
                                        <button onclick="markAsRead(<?php echo $notification['id']; ?>)" class="text-blue-400 hover:text-blue-600 dark:hover:text-blue-300" title="Mark as read">
                                            <i class="fas fa-check"></i>
                                        </button>
                                        <?php endif; ?>
                                        <button onclick="dismissNotification(<?php echo $notification['id']; ?>)" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300" title="Dismiss">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>

                            <!-- Pagination -->
                            <?php if ($total_notifications > $per_page): ?>
                            <div class="flex justify-center items-center space-x-4 py-8 border-t border-gray-200 dark:border-gray-600 mt-6">
                                <?php if ($page > 1): ?>
                                <a href="?type=<?php echo $filter_type; ?>&page=<?php echo $page - 1; ?>" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-200">
                                    <i class="fas fa-chevron-left mr-2"></i>Previous
                                </a>
                                <?php endif; ?>

                                <span class="text-gray-600 dark:text-gray-400">
                                    Page <?php echo $page; ?> of <?php echo ceil($total_notifications / $per_page); ?>
                                </span>

                                <?php if ($page < ceil($total_notifications / $per_page)): ?>
                                <a href="?type=<?php echo $filter_type; ?>&page=<?php echo $page + 1; ?>" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors duration-200">
                                    Next<i class="fas fa-chevron-right ml-2"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Settings Modal -->
<div id="settingsModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Notification Settings</h3>
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Email Notifications</label>
                        <input type="checkbox" class="toggle-switch" checked>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Push Notifications</label>
                        <input type="checkbox" class="toggle-switch" checked>
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">SMS Notifications</label>
                        <input type="checkbox" class="toggle-switch">
                    </div>
                    <div class="flex items-center justify-between">
                        <label class="text-sm font-medium text-gray-700 dark:text-gray-300">High Priority Only</label>
                        <input type="checkbox" class="toggle-switch">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button onclick="closeSettingsModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancel
                    </button>
                    <button onclick="saveSettings()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                        Save Settings
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Mark all notifications as read
document.getElementById('markAllReadBtn').addEventListener('click', function() {
    if (confirm('Mark all notifications as read?')) {
        fetch('communication/notifications/mark_all_read.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while marking notifications as read.');
        });
    }
});

// Mark single notification as read
function markAsRead(notificationId) {
    fetch('communication/notifications/mark_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ notification_id: notificationId })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const notification = document.querySelector(`[data-notification-id="${notificationId}"]`);
            if (notification) {
                notification.classList.add('opacity-75');
                const unreadIndicator = notification.querySelector('.w-2.h-2.bg-blue-500');
                if (unreadIndicator) {
                    unreadIndicator.remove();
                }
                const markReadBtn = notification.querySelector('button[onclick*="markAsRead"]');
                if (markReadBtn) {
                    markReadBtn.remove();
                }
            }
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while marking notification as read.');
    });
}

// Dismiss notification
function dismissNotification(notificationId) {
    if (confirm('Dismiss this notification?')) {
        fetch('communication/notifications/dismiss.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ notification_id: notificationId })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notification = document.querySelector(`[data-notification-id="${notificationId}"]`);
                if (notification) {
                    notification.style.transition = 'opacity 0.3s ease';
                    notification.style.opacity = '0';
                    setTimeout(() => {
                        notification.remove();
                    }, 300);
                }
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('An error occurred while dismissing notification.');
        });
    }
}

// Settings modal functions
document.getElementById('settingsBtn').addEventListener('click', function() {
    document.getElementById('settingsModal').classList.remove('hidden');
});

function closeSettingsModal() {
    document.getElementById('settingsModal').classList.add('hidden');
}

function saveSettings() {
    // Here you would save the settings to the database
    alert('Settings saved successfully!');
    closeSettingsModal();
}
</script>
