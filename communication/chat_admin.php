<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Handle actions
if ($_POST) {
    try {
        if (isset($_POST['create_room'])) {
            $name = trim($_POST['room_name']);
            $description = trim($_POST['room_description']);
            $room_type = $_POST['room_type'];
            $max_participants = (int)$_POST['max_participants'];
            
            $query = "
                INSERT INTO live_chat_rooms (name, description, room_type, created_by, max_participants, created_at)
                VALUES (:name, :description, :room_type, :created_by, :max_participants, NOW())
            ";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':room_type', $room_type);
            $stmt->bindParam(':created_by', $user_id);
            $stmt->bindParam(':max_participants', $max_participants);
            $stmt->execute();
            
            $success_message = "Chat room created successfully!";
        }
        
        if (isset($_POST['delete_room'])) {
            $room_id = $_POST['room_id'];
            $query = "UPDATE live_chat_rooms SET is_active = FALSE WHERE id = :room_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':room_id', $room_id);
            $stmt->execute();
            
            $success_message = "Chat room deactivated successfully!";
        }
        
        if (isset($_POST['resolve_report'])) {
            $report_id = $_POST['report_id'];
            $query = "UPDATE live_chat_reports SET status = 'resolved', reviewed_by = :user_id, reviewed_at = NOW() WHERE id = :report_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':report_id', $report_id);
            $stmt->execute();
            
            $success_message = "Report resolved successfully!";
        }
        
    } catch (PDOException $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get chat statistics
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM live_chat_rooms WHERE is_active = TRUE) as total_rooms,
        (SELECT COUNT(*) FROM live_chat_messages WHERE created_at >= CURDATE()) as messages_today,
        (SELECT COUNT(*) FROM live_chat_user_status WHERE status = 'online') as users_online,
        (SELECT COUNT(*) FROM live_chat_reports WHERE status = 'pending') as pending_reports
";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get chat rooms
$rooms_query = "
    SELECT r.*, u.name as created_by_name,
           COUNT(DISTINCT p.user_id) as participant_count,
           COUNT(DISTINCT m.id) as message_count
    FROM live_chat_rooms r
    LEFT JOIN users u ON r.created_by = u.id
    LEFT JOIN live_chat_participants p ON r.id = p.room_id
    LEFT JOIN live_chat_messages m ON r.id = m.room_id
    GROUP BY r.id
    ORDER BY r.created_at DESC
";
$rooms_stmt = $db->prepare($rooms_query);
$rooms_stmt->execute();
$rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending reports
$reports_query = "
    SELECT r.*, ru.name as reported_user_name, rep.name as reporter_name, room.name as room_name
    FROM live_chat_reports r
    LEFT JOIN users ru ON r.reported_user_id = ru.id
    LEFT JOIN users rep ON r.reporter_id = rep.id
    LEFT JOIN live_chat_rooms room ON r.room_id = room.id
    WHERE r.status = 'pending'
    ORDER BY r.created_at DESC
    LIMIT 10
";
$reports_stmt = $db->prepare($reports_query);
$reports_stmt->execute();
$reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Chat Administration";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Communication', 'url' => 'index.php'],
    ['title' => 'Chat Admin']
];

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
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Chat Administration</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Manage chat rooms, moderate conversations, and view analytics</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="live_chat.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-comments mr-2"></i>Go to Chat
                            </a>
                            <button onclick="showCreateRoomModal()" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i>Create Room
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?php echo $success_message; ?>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Rooms</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_rooms']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comments text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Messages Today</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['messages_today']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comment text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Users Online</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo $stats['users_online']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending Reports</p>
                                <p class="text-3xl font-bold text-red-600 dark:text-red-400"><?php echo $stats['pending_reports']; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-flag text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chat Rooms Management -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- Chat Rooms -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Chat Rooms</h2>
                        </div>
                        <div class="p-6">
                            <?php if (empty($rooms)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-comments text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-500 dark:text-gray-400">No chat rooms found</p>
                            </div>
                            <?php else: ?>
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($rooms as $room): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2">
                                            <i class="fas <?php echo $room['room_type'] === 'admin_only' ? 'fa-lock' : 'fa-comments'; ?> text-blue-500"></i>
                                            <h3 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($room['name']); ?></h3>
                                            <span class="px-2 py-1 text-xs rounded-full <?php echo $room['is_active'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $room['is_active'] ? 'Active' : 'Inactive'; ?>
                                            </span>
                                        </div>
                                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                            <?php echo $room['participant_count']; ?> members • <?php echo $room['message_count']; ?> messages
                                        </p>
                                    </div>
                                    <div class="flex space-x-2">
                                        <a href="live_chat.php?room=<?php echo $room['id']; ?>" class="text-blue-600 hover:text-blue-800 text-sm">
                                            View
                                        </a>
                                        <?php if ($room['is_active']): ?>
                                        <button onclick="deleteRoom(<?php echo $room['id']; ?>)" class="text-red-600 hover:text-red-800 text-sm">
                                            Deactivate
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Pending Reports -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Pending Reports</h2>
                        </div>
                        <div class="p-6">
                            <?php if (empty($reports)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-flag text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-500 dark:text-gray-400">No pending reports</p>
                            </div>
                            <?php else: ?>
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($reports as $report): ?>
                                <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <span class="px-2 py-1 text-xs rounded-full bg-red-100 text-red-800">
                                                    <?php echo ucfirst(str_replace('_', ' ', $report['report_type'])); ?>
                                                </span>
                                                <span class="text-sm text-gray-500 dark:text-gray-400">
                                                    in <?php echo htmlspecialchars($report['room_name']); ?>
                                                </span>
                                            </div>
                                            <p class="text-sm text-gray-900 dark:text-white mb-2">
                                                <?php echo htmlspecialchars($report['description']); ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                Reported by <?php echo htmlspecialchars($report['reporter_name']); ?>
                                                <?php if ($report['reported_user_name']): ?>
                                                    against <?php echo htmlspecialchars($report['reported_user_name']); ?>
                                                <?php endif; ?>
                                                • <?php echo date('M j, g:i A', strtotime($report['created_at'])); ?>
                                            </p>
                                        </div>
                                        <button onclick="resolveReport(<?php echo $report['id']; ?>)" 
                                                class="ml-4 bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                            Resolve
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
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

<!-- Create Room Modal -->
<div id="create-room-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Create New Chat Room</h3>
                    <button onclick="closeCreateRoomModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Room Name</label>
                        <input type="text" name="room_name" required
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                               placeholder="Enter room name">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea name="room_description" rows="3"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                  placeholder="Enter room description"></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Room Type</label>
                        <select name="room_type" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="public">Public</option>
                            <option value="private">Private</option>
                            <option value="class">Class</option>
                            <option value="department">Department</option>
                            <option value="admin_only">Admin Only</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Max Participants</label>
                        <input type="number" name="max_participants" value="100" min="2" max="1000"
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeCreateRoomModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="create_room" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700">
                            Create Room
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Delete Room Form (Hidden) -->
<form id="delete-room-form" method="POST" style="display: none;">
    <input type="hidden" name="room_id" id="delete-room-id">
    <input type="hidden" name="delete_room" value="1">
</form>

<!-- Resolve Report Form (Hidden) -->
<form id="resolve-report-form" method="POST" style="display: none;">
    <input type="hidden" name="report_id" id="resolve-report-id">
    <input type="hidden" name="resolve_report" value="1">
</form>

<script>
function showCreateRoomModal() {
    document.getElementById('create-room-modal').classList.remove('hidden');
}

function closeCreateRoomModal() {
    document.getElementById('create-room-modal').classList.add('hidden');
}

function deleteRoom(roomId) {
    if (confirm('Are you sure you want to deactivate this chat room? This action cannot be undone.')) {
        document.getElementById('delete-room-id').value = roomId;
        document.getElementById('delete-room-form').submit();
    }
}

function resolveReport(reportId) {
    if (confirm('Mark this report as resolved?')) {
        document.getElementById('resolve-report-id').value = reportId;
        document.getElementById('resolve-report-form').submit();
    }
}

// Auto-refresh every 30 seconds
setInterval(() => {
    window.location.reload();
}, 30000);
</script>
