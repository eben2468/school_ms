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
    SELECT u.id, u.name, u.email, sp.student_id, sp.admission_date, 
           c.name as class_name, c.grade_level
    FROM users u
    JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.status = 'active'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $parent_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent announcements
$announcements_query = "
    SELECT title, content, priority, publish_date
    FROM announcements 
    WHERE status = 'published' 
    AND (target_audience = 'all' OR target_audience = 'parents')
    AND (publish_date <= NOW() AND (expiry_date IS NULL OR expiry_date >= NOW()))
    ORDER BY priority DESC, publish_date DESC 
    LIMIT 5
";
$announcements_stmt = $db->prepare($announcements_query);
$announcements_stmt->execute();
$announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get notifications for parent
$notifications_query = "
    SELECT title, message, type, created_at, is_read
    FROM notifications 
    WHERE user_id = :parent_id 
    ORDER BY created_at DESC 
    LIMIT 5
";
$notifications_stmt = $db->prepare($notifications_query);
$notifications_stmt->bindParam(':parent_id', $parent_id);
$notifications_stmt->execute();
$notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Parent Portal";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Parent Portal</h1>
                    <p class="text-gray-600 dark:text-gray-400 mt-1">Welcome to your parent dashboard</p>
                </div>
                <div class="flex space-x-3">
                    <a href="messages.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-envelope mr-2"></i>Messages
                    </a>
                    <a href="calendar.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-calendar mr-2"></i>Calendar
                    </a>
                    <a href="profile.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-user mr-2"></i>Profile
                    </a>
                </div>
            </div>

            <!-- Children Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <?php foreach ($children as $child): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mr-4">
                                    <i class="fas fa-user-graduate text-blue-600 text-xl"></i>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($child['name']); ?></h3>
                                    <p class="text-sm text-gray-600">
                                        <?php echo htmlspecialchars($child['class_name'] ?? 'No Class Assigned'); ?>
                                        <?php if ($child['student_id']): ?>
                                        • ID: <?php echo htmlspecialchars($child['student_id']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <a href="student_details.php?id=<?php echo $child['id']; ?>" class="text-blue-600 hover:text-blue-800">
                                <i class="fas fa-arrow-right"></i>
                            </a>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <a href="attendance.php?student_id=<?php echo $child['id']; ?>" class="p-3 bg-green-50 rounded-lg hover:bg-green-100 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-check text-green-600 mr-2"></i>
                                    <span class="text-sm font-medium text-green-800">Attendance</span>
                                </div>
                            </a>

                            <a href="grades.php?student_id=<?php echo $child['id']; ?>" class="p-3 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-star text-blue-600 mr-2"></i>
                                    <span class="text-sm font-medium text-blue-800">Grades</span>
                                </div>
                            </a>

                            <a href="assignments.php?student_id=<?php echo $child['id']; ?>" class="p-3 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-tasks text-purple-600 mr-2"></i>
                                    <span class="text-sm font-medium text-purple-800">Assignments</span>
                                </div>
                            </a>

                            <a href="fees.php?student_id=<?php echo $child['id']; ?>" class="p-3 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-wallet text-yellow-600 mr-2"></i>
                                    <span class="text-sm font-medium text-yellow-800">Fees</span>
                                </div>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($children)): ?>
                <div class="col-span-2 bg-white rounded-lg shadow p-6 text-center">
                    <div class="text-gray-500">
                        <i class="fas fa-user-plus text-4xl mb-4"></i>
                        <p class="text-lg">No children found in your account.</p>
                        <p class="text-sm">Please contact the school administration to link your children to your account.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Recent Announcements -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Announcements</h2>
                    </div>
                    <div class="divide-y divide-gray-200">
                        <?php if (!empty($announcements)): ?>
                            <?php foreach ($announcements as $announcement): ?>
                            <div class="p-6">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center mb-2">
                                            <h3 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                                            <?php if ($announcement['priority'] === 'urgent'): ?>
                                            <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">
                                                Urgent
                                            </span>
                                            <?php elseif ($announcement['priority'] === 'high'): ?>
                                            <span class="ml-2 inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-orange-100 text-orange-800">
                                                High
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                        <p class="text-sm text-gray-600 mb-2"><?php echo nl2br(htmlspecialchars(substr($announcement['content'], 0, 150))); ?>...</p>
                                        <p class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($announcement['publish_date'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-bullhorn text-2xl mb-2"></i>
                            <p>No recent announcements</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200">
                        <a href="announcements.php" class="text-sm text-blue-600 hover:text-blue-800">View all announcements →</a>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Notifications</h2>
                    </div>
                    <div class="divide-y divide-gray-200">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $notification): ?>
                            <div class="p-6 <?php echo !$notification['is_read'] ? 'bg-blue-50' : ''; ?>">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <?php
                                        $icon_class = 'fas fa-info-circle text-blue-500';
                                        if ($notification['type'] === 'warning') $icon_class = 'fas fa-exclamation-triangle text-yellow-500';
                                        if ($notification['type'] === 'error') $icon_class = 'fas fa-times-circle text-red-500';
                                        if ($notification['type'] === 'success') $icon_class = 'fas fa-check-circle text-green-500';
                                        ?>
                                        <i class="<?php echo $icon_class; ?>"></i>
                                    </div>
                                    <div class="ml-3 flex-1">
                                        <h3 class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($notification['title']); ?></h3>
                                        <p class="text-sm text-gray-600 mt-1"><?php echo htmlspecialchars($notification['message']); ?></p>
                                        <p class="text-xs text-gray-500 mt-2"><?php echo date('M j, Y g:i A', strtotime($notification['created_at'])); ?></p>
                                    </div>
                                    <?php if (!$notification['is_read']): ?>
                                    <div class="flex-shrink-0">
                                        <div class="w-2 h-2 bg-blue-500 rounded-full"></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                        <div class="p-6 text-center text-gray-500">
                            <i class="fas fa-bell text-2xl mb-2"></i>
                            <p>No notifications</p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="px-6 py-3 bg-gray-50 border-t border-gray-200">
                        <a href="../notifications.php" class="text-sm text-blue-600 hover:text-blue-800">View all notifications →</a>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Quick Actions</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="teacher_communication.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-comments text-blue-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Contact Teachers</div>
                                <div class="text-sm text-gray-500">Send messages to teachers</div>
                            </div>
                        </a>

                        <a href="fee_payment.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-credit-card text-green-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Pay Fees</div>
                                <div class="text-sm text-gray-500">Make online payments</div>
                            </div>
                        </a>


                    </div>
                </div>
            </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
