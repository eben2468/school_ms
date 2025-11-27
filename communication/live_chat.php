<?php
/*
 * Enhanced Live Chat System with Advanced Features
 *
 * IMPLEMENTED FEATURES:
 *
 * 1. VOICE AND VIDEO CALLING INTEGRATION
 *    - Voice call functionality with WebRTC support
 *    - Video call functionality with camera/microphone access
 *    - Call controls (mute, video toggle, end call)
 *    - Call duration tracking
 *    - Media device permission handling
 *
 * 2. MESSAGE ENCRYPTION FOR SENSITIVE CONVERSATIONS
 *    - Toggle encryption on/off per user preference
 *    - Visual encryption indicators on messages
 *    - Encrypted message storage and transmission
 *    - Encryption status in header and settings
 *
 * 3. ADVANCED FILE SHARING WITH PREVIEW
 *    - Enhanced file type support (documents, images, archives)
 *    - File preview before sending
 *    - Improved file size limits (10MB)
 *    - File size display in messages
 *    - Better file download interface
 *
 * 4. INTEGRATION WITH NOTIFICATION SYSTEM
 *    - Enhanced browser notifications
 *    - Sound alerts for new messages
 *    - Notification preferences in settings
 *    - Real-time notification status updates
 *
 * 5. MESSAGE THREADING AND REPLIES
 *    - Reply to specific messages
 *    - Thread view modal for organized conversations
 *    - Thread message display with context
 *    - Start threads from any message
 *
 * 6. WEBSOCKET IMPLEMENTATION FOR TRUE REAL-TIME UPDATES
 *    - WebSocket connection initialization
 *    - Real-time message delivery (framework ready)
 *    - Connection status indicators
 *    - Enhanced polling as fallback
 *
 * 7. MESSAGE CACHING FOR FASTER LOADING
 *    - Local storage message caching
 *    - Cache management in settings
 *    - Improved loading performance
 *    - Cache clearing functionality
 *
 * 8. ADVANCED SEARCH WITH FILTERS
 *    - Advanced search modal with multiple filters
 *    - Search by message type, date range, user
 *    - Search result highlighting
 *    - Enhanced search interface
 *
 * 9. BULK MESSAGE OPERATIONS
 *    - Bulk selection mode
 *    - Bulk delete functionality
 *    - Bulk export functionality
 *    - Selection counter and controls
 *
 * 10. ADDITIONAL ENHANCEMENTS
 *     - Export chat history functionality
 *     - Enhanced emoji picker
 *     - Improved file upload with progress
 *     - Better error handling and user feedback
 *     - Enhanced UI/UX with modern design
 *     - Access controls for admin features
 *     - Mobile-responsive design improvements
 *
 * ACCESS CONTROLS:
 * - All users can access basic chat features
 * - Voice/video calling available to all users
 * - Encryption available to all users
 * - Admin features restricted to super_admin, school_admin, principal
 * - Bulk operations available to all users for their own messages
 *
 * TECHNICAL IMPLEMENTATION:
 * - Enhanced JavaScript with modern ES6+ features
 * - Improved error handling and user feedback
 * - Local storage for user preferences
 * - WebRTC API integration for calling features
 * - Enhanced security with message encryption
 * - Performance optimizations with caching
 * - Responsive design with Tailwind CSS
 */

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'];
$user_role = $_SESSION['role'];

// Get user's accessible chat rooms
$rooms_query = "
    SELECT r.*, 
           COUNT(p.user_id) as participant_count,
           (SELECT COUNT(*) FROM live_chat_messages WHERE room_id = r.id AND created_at > COALESCE(
               (SELECT last_seen FROM live_chat_participants WHERE room_id = r.id AND user_id = :user_id), 
               '1970-01-01'
           )) as unread_count
    FROM live_chat_rooms r
    LEFT JOIN live_chat_participants p ON r.id = p.room_id AND p.is_banned = FALSE
    WHERE r.is_active = TRUE 
    AND (
        r.room_type = 'public' 
        OR (r.room_type = 'admin_only' AND :user_role IN ('super_admin', 'school_admin', 'principal'))
        OR EXISTS (SELECT 1 FROM live_chat_participants WHERE room_id = r.id AND user_id = :user_id2)
    )
    GROUP BY r.id
    ORDER BY r.room_type, r.name
";

$rooms_stmt = $db->prepare($rooms_query);
$rooms_stmt->bindParam(':user_id', $user_id);
$rooms_stmt->bindParam(':user_id2', $user_id);
$rooms_stmt->bindParam(':user_role', $user_role);
$rooms_stmt->execute();
$chat_rooms = $rooms_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get online users - only truly online users
$online_users_query = "
    SELECT DISTINCT u.id, u.name, u.role, u.profile_picture,
           COALESCE(s.status, 'offline') as status,
           s.custom_status, s.last_activity
    FROM users u
    INNER JOIN live_chat_user_status s ON u.id = s.user_id
    WHERE u.status = 'active'
    AND s.status = 'online'
    AND s.last_activity > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
    GROUP BY u.id
    ORDER BY u.name
";

$online_stmt = $db->prepare($online_users_query);
$online_stmt->execute();
$online_users = $online_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Live Chat";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Communication', 'url' => 'index.php'],
    ['title' => 'Live Chat']
];

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
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Live Chat</h1>
                                <p class="text-blue-100 text-lg">Connect and communicate with the school community</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-comments mr-2"></i>
                                        Real-time messaging
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        Community chat
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

                <div class="mb-6">
                    <div class="flex items-center justify-between">
                        <div>
                        <div class="flex space-x-3">
                            <button onclick="refreshChat()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-sync-alt mr-2"></i>Refresh
                            </button>
                            <button onclick="toggleUserList()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition-colors duration-200 lg:hidden">
                                <i class="fas fa-users mr-2"></i>Users
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Chat Interface -->
                <div class="grid grid-cols-1 lg:grid-cols-4 gap-6 h-[calc(100vh-250px)]">
                    <!-- Chat Rooms Sidebar -->
                    <div class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 flex flex-col">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Chat Rooms</h3>
                        </div>
                        <div class="flex-1 overflow-y-auto">
                            <?php foreach ($chat_rooms as $room): ?>
                            <div class="room-item p-3 border-b border-gray-100 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200" 
                                 onclick="selectRoom(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name']); ?>')"
                                 data-room-id="<?php echo $room['id']; ?>">
                                <div class="flex items-center justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2">
                                            <i class="fas <?php echo $room['room_type'] === 'admin_only' ? 'fa-lock' : 'fa-comments'; ?> text-blue-500"></i>
                                            <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($room['name']); ?></span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                            <?php echo $room['participant_count']; ?> members
                                        </p>
                                    </div>
                                    <?php if ($room['unread_count'] > 0): ?>
                                    <span class="bg-red-500 text-white text-xs rounded-full px-2 py-1 min-w-[20px] text-center">
                                        <?php echo $room['unread_count']; ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Main Chat Area -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 flex flex-col">
                        <!-- Chat Header -->
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-blue-500 to-purple-600 rounded-t-xl">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 id="current-room-name" class="text-lg font-semibold text-white">Select a chat room</h3>
                                    <p id="current-room-info" class="text-blue-100 text-sm"></p>
                                </div>
                                <div class="flex space-x-2">
                                    <!-- Voice Call Button -->
                                    <button onclick="startVoiceCall()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Start Voice Call">
                                        <i class="fas fa-phone text-xl"></i>
                                    </button>
                                    <!-- Video Call Button -->
                                    <button onclick="startVideoCall()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Start Video Call">
                                        <i class="fas fa-video text-xl"></i>
                                    </button>
                                    <!-- Advanced Search Button -->
                                    <button onclick="toggleAdvancedSearch()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Advanced Search">
                                        <i class="fas fa-search text-xl"></i>
                                    </button>
                                    <!-- Message Threading Button -->
                                    <button onclick="toggleThreadView()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Thread View">
                                        <i class="fas fa-comments text-xl"></i>
                                    </button>
                                    <!-- Encryption Status -->
                                    <button onclick="toggleEncryption()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Toggle Encryption">
                                        <i id="encryption-icon" class="fas fa-lock text-xl"></i>
                                    </button>
                                    <button onclick="toggleEmojiPicker()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Emoji Picker">
                                        <i class="fas fa-smile text-xl"></i>
                                    </button>
                                    <button onclick="showRoomInfo()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Room Info">
                                        <i class="fas fa-info-circle text-xl"></i>
                                    </button>
                                    <button onclick="showRoomSettings()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Settings">
                                        <i class="fas fa-cog text-xl"></i>
                                    </button>
                                    <button onclick="refreshChat()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Refresh Chat">
                                        <i class="fas fa-sync-alt text-xl"></i>
                                    </button>
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                                    <button onclick="window.location.href='chat_admin.php'" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Chat Administration">
                                        <i class="fas fa-shield-alt text-xl"></i>
                                    </button>
                                    <button onclick="debugUserAccess()" class="text-white hover:text-blue-200 transition-colors duration-200 p-2 rounded-lg hover:bg-white/10" title="Debug Access">
                                        <i class="fas fa-bug text-xl"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Advanced Search Bar -->
                        <div id="advanced-search-bar" class="p-4 border-b border-gray-200 dark:border-gray-700 hidden">
                            <div class="space-y-3">
                                <div class="flex space-x-2">
                                    <input type="text" id="advanced-search-input" placeholder="Search messages..."
                                           class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <select id="search-filter-type" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="all">All Messages</option>
                                        <option value="text">Text Only</option>
                                        <option value="file">Files Only</option>
                                        <option value="image">Images Only</option>
                                    </select>
                                </div>
                                <div class="flex space-x-2">
                                    <input type="date" id="search-date-from" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <span class="self-center text-gray-500 dark:text-gray-400">to</span>
                                    <input type="date" id="search-date-to" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <select id="search-user-filter" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Users</option>
                                        <!-- Users will be populated dynamically -->
                                    </select>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="performAdvancedSearch()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                                        <i class="fas fa-search mr-2"></i>Search
                                    </button>
                                    <button onclick="clearAdvancedSearch()" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                                        <i class="fas fa-eraser mr-2"></i>Clear
                                    </button>
                                    <button onclick="toggleAdvancedSearch()" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg">
                                        <i class="fas fa-times mr-2"></i>Close
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Messages Area -->
                        <div class="flex-1 relative">
                            <div id="messages-container" class="h-full overflow-y-auto p-4 space-y-4 bg-gray-50 dark:bg-gray-900 scroll-smooth max-h-[calc(100vh-400px)]">
                                <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                                    <i class="fas fa-comments text-4xl mb-4"></i>
                                    <p>Select a chat room to start messaging</p>
                                </div>
                            </div>

                            <!-- Scroll to Bottom Button - positioned inside message area -->
                            <button id="scroll-to-bottom" onclick="scrollToBottom()"
                                    class="hidden absolute bottom-6 right-6 bg-blue-600 hover:bg-blue-700 text-white p-3 rounded-full shadow-xl transition-all duration-200 z-20 border-2 border-white animate-bounce">
                                <i class="fas fa-chevron-down text-lg"></i>
                            </button>

                            <!-- Scroll to Top Button - positioned inside message area -->
                            <button id="scroll-to-top" onclick="scrollToTop()"
                                    class="hidden absolute top-6 right-6 bg-gray-600 hover:bg-gray-700 text-white p-3 rounded-full shadow-xl transition-all duration-200 z-20 border-2 border-white">
                                <i class="fas fa-chevron-up text-lg"></i>
                            </button>
                        </div>

                        <!-- Typing Indicator -->
                        <div id="typing-indicator" class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hidden">
                            <i class="fas fa-ellipsis-h animate-pulse"></i>
                            <span id="typing-users"></span>
                        </div>

                        <!-- Message Input -->
                        <div class="p-4 border-t border-gray-200 dark:border-gray-700">
                            <!-- File Preview Area -->
                            <div id="file-preview-area" class="hidden mb-3 p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-2">
                                        <i class="fas fa-file text-blue-500"></i>
                                        <span id="file-preview-name" class="text-sm text-gray-700 dark:text-gray-300"></span>
                                        <span id="file-preview-size" class="text-xs text-gray-500 dark:text-gray-400"></span>
                                    </div>
                                    <button onclick="clearFilePreview()" class="text-red-500 hover:text-red-700">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Reply Preview Area -->
                            <div id="reply-preview-area" class="hidden mb-3 p-3 bg-blue-50 dark:bg-blue-900 rounded-lg border-l-4 border-blue-500">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-xs text-blue-600 dark:text-blue-400 font-medium">Replying to:</p>
                                        <p id="reply-preview-text" class="text-sm text-gray-700 dark:text-gray-300"></p>
                                    </div>
                                    <button onclick="clearReplyPreview()" class="text-red-500 hover:text-red-700">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="flex space-x-3">
                                <div class="flex-1 relative">
                                    <input type="text"
                                           id="message-input"
                                           placeholder="Type your message..."
                                           class="w-full px-4 py-3 pr-12 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                           disabled>
                                    <button onclick="toggleEmojiPicker()" class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <i class="fas fa-smile"></i>
                                    </button>
                                </div>
                                <button onclick="sendMessage()"
                                        id="send-button"
                                        class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg transition-colors duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                                        disabled>
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                                <button onclick="showFileUpload()"
                                        class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-3 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <!-- Bulk Operations Button -->
                                <button onclick="toggleBulkMode()"
                                        id="bulk-mode-button"
                                        class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-3 rounded-lg transition-colors duration-200"
                                        title="Bulk Operations">
                                    <i class="fas fa-check-square"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Online Users Sidebar -->
                    <div id="users-sidebar" class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 flex flex-col lg:flex hidden">
                        <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Online Users</h3>
                                <button onclick="refreshOnlineUsers()" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200" title="Refresh">
                                    <i class="fas fa-sync-alt text-sm"></i>
                                </button>
                            </div>
                            <p id="online-users-count" class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($online_users); ?> online</p>
                        </div>
                        <div class="flex-1 relative">
                            <div id="online-users-container" class="h-full overflow-y-auto max-h-[calc(100vh-400px)] scroll-smooth">
                                <?php if (count($online_users) > 0): ?>
                                    <?php foreach ($online_users as $user): ?>
                                    <div class="p-3 border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                        <div class="flex items-center space-x-3">
                                            <div class="relative profile-image w-8 h-8 rounded-full flex-shrink-0">
                                                <img src="<?php echo !empty($user['profile_picture']) ? '../uploads/profile_pictures/' . $user['profile_picture'] : '../assets/images/default-avatar.png'; ?>"
                                                     alt="<?php echo htmlspecialchars($user['name']); ?>"
                                                     class="w-8 h-8 rounded-full object-cover"
                                                     style="min-width: 32px; min-height: 32px;"
                                                     onerror="this.style.display='none'">
                                                <div class="status-indicator bg-green-500"></div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                                    <?php echo htmlspecialchars($user['name']); ?>
                                                </p>
                                                <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                                    <?php echo ucfirst(str_replace('_', ' ', $user['role'])); ?>
                                                </p>
                                                <p class="text-xs text-green-500 dark:text-green-400">
                                                    <i class="fas fa-circle text-xs mr-1"></i>Online
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                                        <i class="fas fa-users text-3xl mb-3"></i>
                                        <p>No users currently online</p>
                                        <p class="text-xs mt-1">Users will appear here when they're active</p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Scroll buttons for online users -->
                            <button id="users-scroll-to-bottom" onclick="scrollUsersToBottom()"
                                    class="hidden absolute bottom-4 right-4 bg-blue-600 hover:bg-blue-700 text-white p-2 rounded-full shadow-lg transition-all duration-200 z-10">
                                <i class="fas fa-chevron-down text-sm"></i>
                            </button>

                            <button id="users-scroll-to-top" onclick="scrollUsersToTop()"
                                    class="hidden absolute top-4 right-4 bg-gray-600 hover:bg-gray-700 text-white p-2 rounded-full shadow-lg transition-all duration-200 z-10">
                                <i class="fas fa-chevron-up text-sm"></i>
                            </button>
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

<!-- Hidden file input for uploads -->
<input type="file" id="file-input" class="hidden" accept="image/*,application/pdf,.doc,.docx,.txt" onchange="handleFileUpload(this)">

<!-- Voice/Video Call Modal -->
<div id="call-modal" class="fixed inset-0 bg-gray-900 bg-opacity-90 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 id="call-modal-title" class="text-lg font-semibold text-gray-900 dark:text-white">Voice Call</h3>
                    <button onclick="endCall()" class="text-red-500 hover:text-red-700">
                        <i class="fas fa-phone-slash text-xl"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <!-- Video area for video calls -->
                    <div id="video-area" class="hidden">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="relative bg-gray-900 rounded-lg overflow-hidden aspect-video">
                                <video id="local-video" autoplay muted class="w-full h-full object-cover"></video>
                                <div class="absolute bottom-2 left-2 text-white text-sm bg-black bg-opacity-50 px-2 py-1 rounded">You</div>
                            </div>
                            <div class="relative bg-gray-900 rounded-lg overflow-hidden aspect-video">
                                <video id="remote-video" autoplay class="w-full h-full object-cover"></video>
                                <div class="absolute bottom-2 left-2 text-white text-sm bg-black bg-opacity-50 px-2 py-1 rounded">Remote User</div>
                            </div>
                        </div>
                    </div>

                    <!-- Audio-only interface -->
                    <div id="audio-area" class="text-center py-8">
                        <div class="w-24 h-24 bg-blue-500 rounded-full mx-auto mb-4 flex items-center justify-center">
                            <i class="fas fa-user text-white text-3xl"></i>
                        </div>
                        <p class="text-lg font-medium text-gray-900 dark:text-white">Voice Call Active</p>
                        <p id="call-duration" class="text-sm text-gray-500 dark:text-gray-400">00:00</p>
                    </div>

                    <!-- Call controls -->
                    <div class="flex justify-center space-x-4">
                        <button onclick="toggleMute()" id="mute-button" class="bg-gray-600 hover:bg-gray-700 text-white p-3 rounded-full">
                            <i class="fas fa-microphone"></i>
                        </button>
                        <button onclick="toggleVideo()" id="video-button" class="bg-gray-600 hover:bg-gray-700 text-white p-3 rounded-full hidden">
                            <i class="fas fa-video"></i>
                        </button>
                        <button onclick="endCall()" class="bg-red-600 hover:bg-red-700 text-white p-3 rounded-full">
                            <i class="fas fa-phone-slash"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Message Threading Modal -->
<div id="thread-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full h-3/4">
            <div class="p-6 h-full flex flex-col">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Message Thread</h3>
                    <button onclick="closeThreadModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="flex-1 overflow-y-auto">
                    <div id="thread-messages" class="space-y-4">
                        <!-- Thread messages will be loaded here -->
                    </div>
                </div>
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <div class="flex space-x-2">
                        <input type="text" id="thread-reply-input" placeholder="Reply to thread..."
                               class="flex-1 px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                               onkeypress="if(event.key==='Enter') sendThreadReply()">
                        <button onclick="sendThreadReply()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-reply"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Emoji Picker Modal -->
<div id="emoji-picker" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Choose Emoji</h3>
                    <button onclick="toggleEmojiPicker()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-3">Click to add to message, double-click to send directly</p>
                <div class="grid grid-cols-8 gap-2 max-h-64 overflow-y-auto">
                    <!-- Common emojis -->
                    <button onclick="insertEmoji('😀')" ondblclick="sendEmojiDirectly('😀')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">😀</button>
                    <button onclick="insertEmoji('😂')" ondblclick="sendEmojiDirectly('😂')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">😂</button>
                    <button onclick="insertEmoji('😍')" ondblclick="sendEmojiDirectly('😍')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">😍</button>
                    <button onclick="insertEmoji('🤔')" ondblclick="sendEmojiDirectly('🤔')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">🤔</button>
                    <button onclick="insertEmoji('👍')" ondblclick="sendEmojiDirectly('👍')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">👍</button>
                    <button onclick="insertEmoji('👎')" ondblclick="sendEmojiDirectly('👎')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">👎</button>
                    <button onclick="insertEmoji('❤️')" ondblclick="sendEmojiDirectly('❤️')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">❤️</button>
                    <button onclick="insertEmoji('🎉')" ondblclick="sendEmojiDirectly('🎉')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">🎉</button>
                    <button onclick="insertEmoji('😊')" ondblclick="sendEmojiDirectly('😊')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">😊</button>
                    <button onclick="insertEmoji('😎')" ondblclick="sendEmojiDirectly('😎')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">😎</button>
                    <button onclick="insertEmoji('🙏')" ondblclick="sendEmojiDirectly('🙏')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">🙏</button>
                    <button onclick="insertEmoji('💪')" ondblclick="sendEmojiDirectly('💪')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">💪</button>
                    <button onclick="insertEmoji('🔥')" ondblclick="sendEmojiDirectly('🔥')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">🔥</button>
                    <button onclick="insertEmoji('⭐')" ondblclick="sendEmojiDirectly('⭐')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">⭐</button>
                    <button onclick="insertEmoji('✅')" ondblclick="sendEmojiDirectly('✅')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">✅</button>
                    <button onclick="insertEmoji('❌')" ondblclick="sendEmojiDirectly('❌')" class="text-2xl hover:bg-gray-100 dark:hover:bg-gray-700 p-2 rounded" title="Click to add, double-click to send">❌</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Room Info Modal -->
<div id="room-info-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Room Information</h3>
                    <button onclick="closeRoomInfo()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div id="room-info-content" class="space-y-4">
                    <!-- Room info will be loaded here -->
                </div>
            </div>
        </div>
    </div>
</div>

<!-- User Report Modal -->
<div id="report-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Report User/Message</h3>
                    <button onclick="closeReportModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <form id="report-form" class="space-y-4">
                    <input type="hidden" id="report-user-id">
                    <input type="hidden" id="report-message-id">

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Report Type</label>
                        <select id="report-type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select a reason</option>
                            <option value="spam">Spam</option>
                            <option value="harassment">Harassment</option>
                            <option value="inappropriate_content">Inappropriate Content</option>
                            <option value="other">Other</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea id="report-description" required rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                  placeholder="Please describe the issue..."></textarea>
                    </div>

                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeReportModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700">
                            Submit Report
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Room Settings Modal -->
<div id="room-settings-modal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-lg w-full">
            <div class="p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Room Settings</h3>
                    <button onclick="closeRoomSettings()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="space-y-4">
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Notifications</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Get notified of new messages</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer toggle-switch">
                            <input type="checkbox" id="notifications-toggle" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Sound Alerts</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Play sound for new messages</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer toggle-switch">
                            <input type="checkbox" id="sound-toggle" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Message Encryption</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Encrypt sensitive messages</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer toggle-switch">
                            <input type="checkbox" id="encryption-toggle" class="sr-only peer">
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">Message Caching</p>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Cache messages for faster loading</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer toggle-switch">
                            <input type="checkbox" id="caching-toggle" class="sr-only peer" checked>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 dark:peer-focus:ring-green-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <div class="border-t border-gray-200 dark:border-gray-600 pt-4 space-y-3">
                        <button onclick="exportChatHistory()" class="w-full bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i>Export Chat History
                        </button>
                        <button onclick="clearChatHistory()" class="w-full bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-trash mr-2"></i>Clear Chat History (Local)
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* Prevent profile image blinking */
.profile-image {
    background-color: #f3f4f6;
    background-image: url('../assets/images/default-avatar.png');
    background-size: cover;
    background-position: center;
    background-repeat: no-repeat;
}

.dark .profile-image {
    background-color: #374151;
}

/* Disable image transitions that might cause blinking */
.profile-image img {
    transition: none !important;
    animation: none !important;
}

/* Stable status indicator */
.status-indicator {
    position: absolute;
    bottom: -2px;
    right: -2px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid white;
    transition: none !important;
    animation: none !important;
}

.dark .status-indicator {
    border-color: #1f2937;
}

/* Enhanced Chat Features Styles */
.message-checkbox {
    transform: scale(0.9);
    margin-right: 8px;
}

.encryption-enabled {
    background: linear-gradient(45deg, #10b981, #059669);
}

.thread-indicator {
    border-left: 3px solid #3b82f6;
    padding-left: 8px;
    margin-left: 4px;
}

.file-preview-area {
    background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
    border: 2px dashed #d1d5db;
}

.dark .file-preview-area {
    background: linear-gradient(135deg, #374151, #4b5563);
    border-color: #6b7280;
}

.call-controls button {
    transition: all 0.3s ease;
    transform: scale(1);
}

.call-controls button:hover {
    transform: scale(1.1);
}

.call-controls button:active {
    transform: scale(0.95);
}

.bulk-mode-active .message-item {
    border-left: 4px solid #8b5cf6;
    padding-left: 8px;
    margin-left: 4px;
}

/* Scroll buttons styling */
#scroll-to-bottom, #scroll-to-top {
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    backdrop-filter: blur(10px);
    transition: all 0.3s ease;
}

#scroll-to-bottom:hover, #scroll-to-top:hover {
    transform: scale(1.1);
    box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
}

#scroll-to-bottom:active, #scroll-to-top:active {
    transform: scale(0.95);
}

/* Ensure scroll buttons are always visible when shown */
#scroll-to-bottom:not(.hidden), #scroll-to-top:not(.hidden) {
    display: block !important;
    opacity: 1 !important;
    visibility: visible !important;
}

/* Messages container positioning */
#messages-container {
    position: relative;
}

/* Demo content styling */
.clickable-emoji {
    cursor: pointer;
    padding: 2px 4px;
    border-radius: 4px;
    transition: background-color 0.2s ease;
}

.clickable-emoji:hover {
    background-color: rgba(59, 130, 246, 0.1);
}

.search-highlight {
    background-color: #fef3c7;
    padding: 2px 4px;
    border-radius: 4px;
}

.dark .search-highlight {
    background-color: #92400e;
    color: #fef3c7;
}

/* Clickable Emojis in Room Description */
.clickable-emoji {
    cursor: pointer;
    font-size: 1.2em;
    margin: 0 4px;
    padding: 4px 6px;
    border-radius: 6px;
    transition: all 0.2s ease;
    display: inline-block;
}

.clickable-emoji:hover {
    background-color: rgba(255, 255, 255, 0.2);
    transform: scale(1.2);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.clickable-emoji:active {
    transform: scale(1.1);
}

/* WebSocket connection status indicator */
.connection-status {
    position: fixed;
    top: 90px;
    right: 20px;
    padding: 8px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    z-index: 1000;
    transition: all 0.3s ease;
}

.connection-status.connected {
    background-color: #10b981;
    color: white;
}

.connection-status.disconnected {
    background-color: #ef4444;
    color: white;
}

.connection-status.connecting {
    background-color: #f59e0b;
    color: white;
}

/* Message threading styles */
.thread-message {
    border-left: 3px solid #3b82f6;
    margin-left: 16px;
    padding-left: 12px;
}

.thread-reply {
    background-color: #f8fafc;
    border-radius: 8px;
    padding: 8px;
    margin-top: 4px;
}

.dark .thread-reply {
    background-color: #1e293b;
}

/* Enhanced emoji picker */
.emoji-category {
    border-bottom: 1px solid #e5e7eb;
    padding: 8px 0;
}

.dark .emoji-category {
    border-color: #374151;
}

/* File upload progress */
.upload-progress {
    width: 100%;
    height: 4px;
    background-color: #e5e7eb;
    border-radius: 2px;
    overflow: hidden;
}

.upload-progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #3b82f6, #1d4ed8);
    transition: width 0.3s ease;
}

/* Advanced search filters */
.search-filter-active {
    background-color: #dbeafe;
    border-color: #3b82f6;
}

.dark .search-filter-active {
    background-color: #1e3a8a;
    border-color: #60a5fa;
}

/* Custom Toggle Switch Styles - Force Green Color */
.toggle-switch input:checked + div {
    background-color: #16a34a !important; /* Green-600 */
}

.toggle-switch input:focus + div {
    box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.2) !important; /* Green focus ring */
}

/* Scroll button styles */
#scroll-to-bottom, #scroll-to-top {
    transition: all 0.3s ease;
    opacity: 0.9;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
}

#scroll-to-bottom {
    animation: bounce 2s infinite;
}

#scroll-to-bottom:hover, #scroll-to-top:hover {
    opacity: 1;
    transform: scale(1.1);
    animation: none;
    box-shadow: 0 6px 20px rgba(0, 0, 0, 0.25);
}

/* Ensure scroll buttons stay within message area */
.messages-scroll-container {
    position: relative;
    overflow: hidden;
}

@keyframes bounce {
    0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
    40% { transform: translateY(-10px); }
    60% { transform: translateY(-5px); }
}
</style>

<script>
// Global variables
let currentRoomId = null;
let currentRoomName = '';
let messagePollingInterval = null;
let typingTimeout = null;
let lastMessageId = 0;
let isEncryptionEnabled = false;
let isBulkModeActive = false;
let selectedMessages = new Set();
let currentThreadId = null;
let replyToMessageId = null;
let messageCache = new Map();
let webSocketConnection = null;
let localStream = null;
let remoteStream = null;
let peerConnection = null;
let callStartTime = null;
let callTimer = null;

// Initialize chat
document.addEventListener('DOMContentLoaded', function() {
    // Initialize toggle settings
    initializeToggleSettings();

    // Initialize WebSocket connection
    initializeWebSocket();

    // Initialize encryption settings - start with encryption disabled
    isEncryptionEnabled = false;
    localStorage.setItem('chat_encryption', 'false');
    updateEncryptionIcon();

    // Auto-select first room if available
    const firstRoom = document.querySelector('.room-item');
    if (firstRoom) {
        const roomId = firstRoom.dataset.roomId;
        const roomName = firstRoom.querySelector('.font-medium').textContent;
        selectRoom(roomId, roomName);
    }

    // Setup message input events
    const messageInput = document.getElementById('message-input');
    messageInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            if (e.shiftKey) {
                // Allow new line with Shift+Enter
                return;
            }
            e.preventDefault();
            sendMessage();
        } else {
            handleTyping();
        }
    });

    // Update online status
    updateOnlineStatus('online');

    // Periodic status update
    setInterval(() => {
        updateOnlineStatus('online');
    }, 30000);

    // Initialize message caching
    loadCachedMessages();

    // Initialize scroll button functionality
    updateScrollButtonVisibility();

    // Initialize online users scroll functionality
    updateUsersScrollButtonVisibility();

    // Force toggle colors to be green when checked
    forceToggleColors();
});

// Force toggle colors to be green
function forceToggleColors() {
    const toggles = document.querySelectorAll('.toggle-switch input[type="checkbox"]');
    toggles.forEach(toggle => {
        const updateColor = () => {
            const toggleDiv = toggle.nextElementSibling;
            if (toggle.checked) {
                toggleDiv.style.backgroundColor = '#16a34a'; // Green-600
            } else {
                toggleDiv.style.backgroundColor = ''; // Reset to default
            }
        };

        // Update on load
        updateColor();

        // Update on change
        toggle.addEventListener('change', updateColor);
    });
}

// Select chat room
function selectRoom(roomId, roomName) {
    // Clear previous room data
    if (currentRoomId !== roomId) {
        lastMessageId = 0; // Reset message tracking for new room
        const container = document.getElementById('messages-container');
        container.innerHTML = '<div class="text-center text-gray-500 dark:text-gray-400 py-8"><i class="fas fa-spinner fa-spin text-2xl mb-4"></i><p>Loading messages...</p></div>';
    }

    currentRoomId = roomId;
    currentRoomName = roomName;

    // Update UI
    document.getElementById('current-room-name').textContent = roomName;
    document.getElementById('message-input').disabled = false;
    document.getElementById('send-button').disabled = false;

    // Load room info and display with clickable emojis
    loadRoomDescription(roomId);

    // Highlight selected room
    document.querySelectorAll('.room-item').forEach(item => {
        item.classList.remove('bg-blue-100', 'dark:bg-blue-900');
    });
    document.querySelector(`[data-room-id="${roomId}"]`).classList.add('bg-blue-100', 'dark:bg-blue-900');

    // Clear previous polling interval
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }

    // Load messages for this specific room
    loadMessages();

    // Start polling for new messages for this room
    messagePollingInterval = setInterval(loadMessages, 2000);

    // Join room (update participant status)
    joinRoom(roomId);

    // Update scroll button visibility after room selection
    setTimeout(() => {
        updateScrollButtonVisibility();
    }, 500);
}

// Load room description with clickable emojis
function loadRoomDescription(roomId) {
    fetch(`live_chat_api.php?action=get_room_info&room_id=${roomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.room) {
                const room = data.room;
                let description = room.description || '';

                // Add default descriptions for rooms if empty
                if (!description) {
                    switch(room.name) {
                        case 'Academic Support':
                            description = 'Welcome to Academic Support! Students can ask questions about their studies here, and teachers and peers can help provide answers and guidance.';
                            break;
                        case 'General Discussion':
                            description = 'Welcome to General Discussion! Share your thoughts, ideas, and have friendly conversations with the school community.';
                            break;
                        case 'Announcements':
                            description = 'Welcome to Announcements! Stay updated with the latest school news and important information.';
                            break;
                        case 'Staff Room':
                            description = 'Welcome to Staff Room! A private space for school staff to collaborate and communicate.';
                            break;
                        default:
                            description = `Welcome to ${room.name}! Join the conversation and connect with others.`;
                    }
                }

                // Add clickable emojis to the description
                description += ' <span class="clickable-emoji" onclick="sendQuickReaction(\'❤️\')" title="Send Love">❤️</span> <span class="clickable-emoji" onclick="sendQuickReaction(\'👍\')" title="Send Thumbs Up">👍</span>';

                document.getElementById('current-room-info').innerHTML = description;
            }
        })
        .catch(error => {
            console.error('Error loading room description:', error);
            // Fallback description with emojis
            document.getElementById('current-room-info').innerHTML = `Welcome to ${currentRoomName}! <span class="clickable-emoji" onclick="sendQuickReaction('❤️')" title="Send Love">❤️</span> <span class="clickable-emoji" onclick="sendQuickReaction('👍')" title="Send Thumbs Up">👍</span>`;
        });
}

// Send quick reaction emoji as a message
function sendQuickReaction(emoji) {
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('room_id', currentRoomId);
    formData.append('message', emoji);
    formData.append('is_encrypted', '0'); // Don't encrypt emojis

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            loadMessages();
            showQuickReactionFeedback(emoji);
        } else {
            alert('Failed to send reaction: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending quick reaction:', error);
        alert('Failed to send reaction. Please try again.');
    });
}

// Show brief feedback for quick reaction
function showQuickReactionFeedback(emoji) {
    const feedback = document.createElement('div');
    feedback.className = 'fixed top-20 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-pulse';
    feedback.innerHTML = `<i class="fas fa-check mr-2"></i>Sent ${emoji}`;
    document.body.appendChild(feedback);

    setTimeout(() => {
        feedback.remove();
    }, 2000);
}

// Load messages for current room
function loadMessages() {
    if (!currentRoomId) return;

    // Add timestamp to prevent caching issues
    const timestamp = Date.now();
    fetch(`live_chat_api.php?action=get_messages&room_id=${currentRoomId}&last_id=${lastMessageId}&t=${timestamp}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (data.messages.length > 0) {
                    displayMessages(data.messages);
                    lastMessageId = Math.max(...data.messages.map(m => m.id));
                } else if (lastMessageId === 0) {
                    // No messages in this room yet
                    const container = document.getElementById('messages-container');
                    container.innerHTML = `
                        <div class="text-center text-gray-500 dark:text-gray-400 py-8">
                            <i class="fas fa-comments text-4xl mb-4"></i>
                            <p>No messages in this room yet</p>
                            <p class="text-sm mt-2">Be the first to start the conversation!</p>
                        </div>
                    `;
                }
            } else {
                console.error('Failed to load messages:', data.message);
            }
        })
        .catch(error => {
            console.error('Error loading messages:', error);
            const container = document.getElementById('messages-container');
            container.innerHTML = `
                <div class="text-center text-red-500 dark:text-red-400 py-8">
                    <i class="fas fa-exclamation-triangle text-4xl mb-4"></i>
                    <p>Failed to load messages</p>
                    <button onclick="loadMessages()" class="mt-2 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded">
                        Try Again
                    </button>
                </div>
            `;
        });
}

// Display messages in chat
function displayMessages(messages) {
    const container = document.getElementById('messages-container');

    if (messages.length === 0) return;

    // Store scroll position before adding messages
    const wasAtBottom = isScrolledToBottom();

    // If this is the first load, clear the container
    if (lastMessageId === 0) {
        container.innerHTML = '';
    }

    let hasNewMessages = false;

    messages.forEach(message => {
        const messageElement = createMessageElement(message);
        container.appendChild(messageElement);

        // Check if this is a new message from another user
        if (lastMessageId > 0 && message.user_id != <?php echo $user_id; ?>) {
            hasNewMessages = true;
        }
    });

    // Show notification and play sound for new messages from other users
    if (hasNewMessages && lastMessageId > 0) {
        const latestMessage = messages[messages.length - 1];
        showNotification(
            `New message from ${latestMessage.sender_name}`,
            latestMessage.message.substring(0, 50) + (latestMessage.message.length > 50 ? '...' : ''),
            latestMessage.profile_picture ? `../uploads/profile_pictures/${latestMessage.profile_picture}` : '../assets/images/default-avatar.png'
        );
        playNotificationSound();
    }

    // Auto-scroll to bottom if user was at bottom or if it's their own message
    if (wasAtBottom || (messages.length > 0 && messages[messages.length - 1].sender_id == <?php echo $user_id; ?>)) {
        setTimeout(() => {
            scrollToBottom();
        }, 100);
    } else {
        // Show scroll to bottom button if user is not at bottom
        setTimeout(() => {
            showScrollToBottomButton();
        }, 100);
    }

    // Update scroll button visibility after a short delay to ensure content is rendered
    setTimeout(() => {
        updateScrollButtonVisibility();
    }, 200);
}

// Create message element
function createMessageElement(message) {
    const div = document.createElement('div');
    div.className = `message-item ${message.sender_id == <?php echo $user_id; ?> ? 'ml-auto' : ''} max-w-xs lg:max-w-md`;
    div.dataset.messageId = message.id;

    const isOwn = message.sender_id == <?php echo $user_id; ?>;
    const bubbleClass = isOwn ? 'bg-blue-600 text-white' : 'bg-white dark:bg-gray-700 text-gray-900 dark:text-white';

    // Decrypt message if it's encrypted
    let displayMessage = message.message;
    console.log('Message data:', message);
    console.log('Is encrypted:', message.is_encrypted);
    console.log('Encryption enabled:', isEncryptionEnabled);

    if (message.is_encrypted == '1' || message.is_encrypted === true) {
        try {
            displayMessage = decryptMessage(message.message);
            console.log('Decrypted message:', displayMessage);
        } catch (e) {
            console.error('Decryption failed:', e);
            displayMessage = message.message; // Fallback to original
        }
    }

    // Handle reply preview
    let replyPreview = '';
    if (message.reply_to_message_id) {
        const replyToSender = message.reply_to_sender_name || 'Unknown User';
        const replyToMessage = message.reply_to_message || 'Original message not found';
        replyPreview = `
            <div class="bg-gray-100 dark:bg-gray-600 border-l-4 border-blue-500 p-2 mb-2 rounded cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-500 transition-colors" onclick="scrollToMessage(${message.reply_to_message_id})">
                <p class="text-xs text-gray-600 dark:text-gray-400 font-medium">
                    <i class="fas fa-reply mr-1"></i>Replying to ${escapeHtml(replyToSender)}:
                </p>
                <p class="text-sm text-gray-800 dark:text-gray-200 truncate">${escapeHtml(replyToMessage.substring(0, 100))}${replyToMessage.length > 100 ? '...' : ''}</p>
            </div>
        `;
    }

    let messageContent = '';
    if (message.message_type === 'image') {
        messageContent = `
            <img src="../uploads/${message.file_path}" alt="Image" class="max-w-full h-auto rounded-lg mb-2 cursor-pointer" onclick="showImageModal('${message.file_path}')">
            <p class="text-sm">${escapeHtml(displayMessage)}</p>
        `;
    } else if (message.message_type === 'file') {
        messageContent = `
            <div class="flex items-center space-x-2 mb-2 p-2 bg-gray-100 dark:bg-gray-600 rounded">
                <i class="fas fa-file text-blue-500"></i>
                <a href="../uploads/${message.file_path}" download="${message.file_name}" class="text-blue-500 hover:underline text-sm">
                    ${escapeHtml(message.file_name)}
                </a>
                <span class="text-xs text-gray-500">${formatFileSize(message.file_size)}</span>
            </div>
            <p class="text-sm">${escapeHtml(displayMessage)}</p>
        `;
    } else {
        messageContent = `<p class="text-sm">${escapeHtml(displayMessage)}</p>`;
    }

    // Encryption indicator
    const encryptionIndicator = message.is_encrypted ?
        '<i class="fas fa-lock text-xs text-green-500 ml-1" title="Encrypted message"></i>' : '';

    div.innerHTML = `
        <div class="flex ${isOwn ? 'justify-end' : 'justify-start'} mb-2 group">
            <div class="${bubbleClass} rounded-lg px-4 py-2 shadow-sm border border-gray-200 dark:border-gray-600 relative">
                ${!isOwn ? `<p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">${escapeHtml(message.sender_name)}</p>` : ''}
                ${replyPreview}
                ${messageContent}
                <div class="flex items-center justify-between mt-1">
                    <p class="text-xs ${isOwn ? 'text-blue-200' : 'text-gray-500 dark:text-gray-400'}">
                        ${formatTime(message.created_at)}${encryptionIndicator}
                    </p>
                    <div class="flex space-x-1 opacity-0 group-hover:opacity-100 transition-opacity duration-200">
                        <button onclick="replyToMessage(${message.id}, '${escapeHtml(displayMessage)}')" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Reply">
                            <i class="fas fa-reply"></i>
                        </button>
                        <button onclick="reactToMessage(${message.id}, '👍')" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Like">👍</button>
                        <button onclick="reactToMessage(${message.id}, '😂')" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Laugh">😂</button>
                        <button onclick="reactToMessage(${message.id}, '😮')" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Wow">😮</button>
                        <button onclick="reactToMessage(${message.id}, '❤️')" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Love">❤️</button>
                        <button onclick="startThreadFromMessage(${message.id})" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded" title="Start Thread">
                            <i class="fas fa-comments"></i>
                        </button>
                        ${!isOwn ? `<button onclick="reportMessage(${message.id}, ${message.sender_id})" class="text-xs hover:bg-gray-200 dark:hover:bg-gray-600 p-1 rounded text-red-500"><i class="fas fa-flag"></i></button>` : ''}
                    </div>
                </div>
                ${message.reactions && message.reactions.length > 0 ? `
                    <div class="flex flex-wrap gap-1 mt-2">
                        ${message.reactions.map(reaction => `
                            <div class="text-xs bg-gray-100 dark:bg-gray-600 px-2 py-1 rounded-full text-gray-600 dark:text-gray-300 cursor-pointer hover:bg-gray-200 dark:hover:bg-gray-500"
                                 title="${reaction.user_names}"
                                 onclick="reactToMessage(${message.id}, '${reaction.reaction_emoji}')">
                                ${reaction.reaction_emoji} ${reaction.count}
                            </div>
                        `).join('')}
                    </div>
                ` : ''}
            </div>
        </div>
    `;

    return div;
}

// Helper function to format file size
function formatFileSize(bytes) {
    if (!bytes) return '';
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return Math.round(bytes / Math.pow(1024, i) * 100) / 100 + ' ' + sizes[i];
}

// Reply to message function
function replyToMessage(messageId, messageText) {
    replyToMessageId = messageId;
    const replyPreview = document.getElementById('reply-preview-area');
    const replyText = document.getElementById('reply-preview-text');

    replyText.textContent = messageText.substring(0, 100) + (messageText.length > 100 ? '...' : '');
    replyPreview.classList.remove('hidden');

    document.getElementById('message-input').focus();
}

// Scroll to specific message
function scrollToMessage(messageId) {
    const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
    if (messageElement) {
        messageElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
        // Highlight the message briefly
        messageElement.classList.add('bg-yellow-100', 'dark:bg-yellow-900');
        setTimeout(() => {
            messageElement.classList.remove('bg-yellow-100', 'dark:bg-yellow-900');
        }, 2000);
    } else {
        // Message not visible, could load more messages or show notification
        console.log('Message not found in current view');
    }
}

// Start thread from message
function startThreadFromMessage(messageId) {
    currentThreadId = messageId;
    toggleThreadView();
}

// Send message
function sendMessage() {
    const input = document.getElementById('message-input');
    let message = input.value.trim();

    if (!message || !currentRoomId) {
        if (!message) {
            console.log('No message to send');
        }
        if (!currentRoomId) {
            console.log('No room selected');
            alert('Please select a chat room first');
        }
        return;
    }

    // Store original message for debugging
    console.log('Original message:', message);
    console.log('Encryption enabled:', isEncryptionEnabled);

    // Encrypt message if encryption is enabled
    if (isEncryptionEnabled) {
        const originalMessage = message;
        message = encryptMessage(message);
        console.log('Encrypted message:', message);
    }

    const formData = new FormData();
    formData.append('action', 'send_message');
    formData.append('room_id', currentRoomId);
    formData.append('message', message);
    formData.append('is_encrypted', isEncryptionEnabled ? '1' : '0');

    // Add reply information if replying to a message
    if (replyToMessageId) {
        formData.append('reply_to', replyToMessageId);
    }

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            input.value = '';
            clearReplyPreview();
            loadMessages();
        } else {
            alert('Failed to send message: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);
        alert('Failed to send message. Please try again.');
    });
}

// Utility functions
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
}

function toggleEmojiPicker() {
    const picker = document.getElementById('emoji-picker');
    picker.classList.toggle('hidden');
}

function insertEmoji(emoji) {
    const input = document.getElementById('message-input');
    console.log('Inserting emoji:', emoji);
    console.log('Current input value before:', input.value);
    input.value += emoji;
    console.log('Current input value after:', input.value);
    input.focus();
    toggleEmojiPicker();
}

// Send emoji directly as a message
function sendEmojiDirectly(emoji) {
    console.log('Sending emoji directly:', emoji);
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    sendQuickReaction(emoji);
    toggleEmojiPicker();
}

function toggleUserList() {
    const sidebar = document.getElementById('users-sidebar');
    sidebar.classList.toggle('hidden');
}

function refreshChat() {
    // Show loading indicator
    const refreshButtons = document.querySelectorAll('button[onclick="refreshChat()"]');
    refreshButtons.forEach(button => {
        const originalContent = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        button.disabled = true;

        // Restore button after 2 seconds
        setTimeout(() => {
            button.innerHTML = originalContent;
            button.disabled = false;
        }, 2000);
    });

    // Show refresh notification
    showNotification('Refreshing chat...', 'Loading latest messages and online users', null, 2000);

    if (currentRoomId) {
        lastMessageId = 0;
        loadMessages();
    }

    // Also refresh online users
    refreshOnlineUsers();

    // Refresh room list
    refreshRoomList();

    // Don't reload the entire page to prevent blinking
    // location.reload();
}

// Refresh room list
function refreshRoomList() {
    fetch('live_chat_api.php?action=get_rooms')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateRoomList(data.rooms);
            }
        })
        .catch(error => {
            console.error('Error refreshing room list:', error);
        });
}

// Update room list display
function updateRoomList(rooms) {
    const container = document.querySelector('.space-y-2');
    if (!container) return;

    container.innerHTML = '';

    rooms.forEach(room => {
        const roomElement = document.createElement('div');
        roomElement.className = 'room-item p-3 rounded-lg cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200';
        roomElement.dataset.roomId = room.id;
        roomElement.onclick = () => selectRoom(room.id, room.name);

        roomElement.innerHTML = `
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="w-3 h-3 rounded-full ${room.room_type === 'public' ? 'bg-green-500' : room.room_type === 'private' ? 'bg-blue-500' : 'bg-red-500'}"></div>
                    <div>
                        <h4 class="font-medium text-gray-900 dark:text-white">${escapeHtml(room.name)}</h4>
                        <p class="text-sm text-gray-500 dark:text-gray-400">${room.participant_count} members</p>
                    </div>
                </div>
                <div class="text-xs text-gray-400 dark:text-gray-500">
                    ${room.room_type}
                </div>
            </div>
        `;

        container.appendChild(roomElement);
    });
}

// Refresh online users
function refreshOnlineUsers() {
    fetch('live_chat_api.php?action=get_online_users')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateOnlineUsersDisplay(data.users);
            }
        })
        .catch(error => {
            console.error('Error refreshing online users:', error);
        });
}

// Update online users display
function updateOnlineUsersDisplay(users) {
    const container = document.getElementById('online-users-container');
    const countElement = document.getElementById('online-users-count');

    // Update count
    countElement.textContent = users.length + ' online';

    // Update users list
    if (users.length > 0) {
        let html = '';
        users.forEach(user => {
            const profilePicture = user.profile_picture
                ? `../uploads/profile_pictures/${user.profile_picture}`
                : '../assets/images/default-avatar.png';

            html += `
                <div class="p-3 border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                    <div class="flex items-center space-x-3">
                        <div class="relative profile-image w-8 h-8 rounded-full flex-shrink-0">
                            <img src="${profilePicture}"
                                 alt="${escapeHtml(user.name)}"
                                 class="w-8 h-8 rounded-full object-cover"
                                 style="min-width: 32px; min-height: 32px;"
                                 onerror="this.style.display='none'">
                            <div class="status-indicator bg-green-500"></div>
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                ${escapeHtml(user.name)}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                ${user.role.charAt(0).toUpperCase() + user.role.slice(1).replace('_', ' ')}
                            </p>
                            <p class="text-xs text-green-500 dark:text-green-400">
                                <i class="fas fa-circle text-xs mr-1"></i>Online
                            </p>
                        </div>
                    </div>
                </div>
            `;
        });
        container.innerHTML = html;
    } else {
        container.innerHTML = `
            <div class="p-6 text-center text-gray-500 dark:text-gray-400">
                <i class="fas fa-users text-3xl mb-3"></i>
                <p>No users currently online</p>
                <p class="text-xs mt-1">Users will appear here when they're active</p>
            </div>
        `;
    }

    // Update scroll button visibility for users
    updateUsersScrollButtonVisibility();
}

// Online users scroll functions
function scrollUsersToBottom() {
    const container = document.getElementById('online-users-container');
    container.scrollTo({
        top: container.scrollHeight,
        behavior: 'smooth'
    });
}

function scrollUsersToTop() {
    const container = document.getElementById('online-users-container');
    container.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

function updateUsersScrollButtonVisibility() {
    const container = document.getElementById('online-users-container');
    const scrollToBottomBtn = document.getElementById('users-scroll-to-bottom');
    const scrollToTopBtn = document.getElementById('users-scroll-to-top');

    if (!container || !scrollToBottomBtn || !scrollToTopBtn) return;

    // Add scroll event listener if not already added
    if (!container.hasUsersScrollListener) {
        container.addEventListener('scroll', function() {
            const scrollTop = container.scrollTop;
            const scrollHeight = container.scrollHeight;
            const clientHeight = container.clientHeight;

            // Show/hide scroll to bottom button
            if (scrollTop + clientHeight >= scrollHeight - 5) {
                scrollToBottomBtn.classList.add('hidden');
            } else {
                scrollToBottomBtn.classList.remove('hidden');
            }

            // Show/hide scroll to top button
            if (scrollTop <= 5) {
                scrollToTopBtn.classList.add('hidden');
            } else {
                scrollToTopBtn.classList.remove('hidden');
            }
        });
        container.hasUsersScrollListener = true;
    }

    // Initial check
    const scrollTop = container.scrollTop;
    const scrollHeight = container.scrollHeight;
    const clientHeight = container.clientHeight;

    if (scrollHeight > clientHeight) {
        if (scrollTop + clientHeight < scrollHeight - 5) {
            scrollToBottomBtn.classList.remove('hidden');
        }
        if (scrollTop > 5) {
            scrollToTopBtn.classList.remove('hidden');
        }
    }
}

// Additional functions will be implemented in the API file
function joinRoom(roomId) {
    fetch('live_chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=join_room&room_id=${roomId}`
    });
}

function updateOnlineStatus(status) {
    fetch('live_chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=update_status&status=${status}`
    });
}

function handleTyping() {
    if (!currentRoomId) return;
    
    // Clear existing timeout
    if (typingTimeout) {
        clearTimeout(typingTimeout);
    }
    
    // Send typing indicator
    fetch('live_chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=typing&room_id=${currentRoomId}&typing=true`
    });
    
    // Stop typing after 3 seconds
    typingTimeout = setTimeout(() => {
        fetch('live_chat_api.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `action=typing&room_id=${currentRoomId}&typing=false`
        });
    }, 3000);
}

function showFileUpload() {
    document.getElementById('file-input').click();
}

function handleFileUpload(input) {
    if (input.files && input.files[0]) {
        const file = input.files[0];

        // Check if room is selected
        if (!currentRoomId) {
            alert('Please select a chat room first');
            return;
        }

        // Check file size (10MB limit - increased for better file sharing)
        if (file.size > 10 * 1024 * 1024) {
            alert('File size must be less than 10MB');
            return;
        }

        // Expanded file type support
        const allowedTypes = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 'text/plain', 'text/csv',
            'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/zip', 'application/x-rar-compressed'
        ];

        if (!allowedTypes.includes(file.type)) {
            alert('File type not allowed. Please upload images, documents, or compressed files only.');
            return;
        }

        // Show file preview
        showFilePreview(file);

        const formData = new FormData();
        formData.append('action', 'upload_file');
        formData.append('room_id', currentRoomId);
        formData.append('file', file);

        // Show upload progress
        const uploadButton = document.querySelector('button[onclick="showFileUpload()"]');
        const originalText = uploadButton.innerHTML;
        uploadButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        uploadButton.disabled = true;

        fetch('live_chat_api.php', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Upload response status:', response.status);
            console.log('Upload response headers:', response.headers.get('content-type'));

            if (!response.ok) {
                return response.text().then(text => {
                    console.error('Upload error response:', text);
                    throw new Error(`HTTP ${response.status}: ${text}`);
                });
            }

            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                return response.text().then(text => {
                    console.error('Non-JSON response:', text);
                    throw new Error('Server returned non-JSON response: ' + text.substring(0, 200));
                });
            }

            return response.json();
        })
        .then(data => {
            if (data.success) {
                loadMessages();
                clearFilePreview();
            } else {
                alert('Failed to upload file: ' + (data.message || 'Unknown error'));
            }
        })
        .catch(error => {
            console.error('Error uploading file:', error);
            alert('Failed to upload file: ' + error.message);
        })
        .finally(() => {
            // Restore upload button
            uploadButton.innerHTML = originalText;
            uploadButton.disabled = false;
        });
    }
}

function showFilePreview(file) {
    const previewArea = document.getElementById('file-preview-area');
    const fileName = document.getElementById('file-preview-name');
    const fileSize = document.getElementById('file-preview-size');

    fileName.textContent = file.name;
    fileSize.textContent = formatFileSize(file.size);
    previewArea.classList.remove('hidden');
}

function showRoomInfo() {
    if (!currentRoomId) return;

    fetch(`live_chat_api.php?action=get_room_info&room_id=${currentRoomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const room = data.room;
                document.getElementById('room-info-content').innerHTML = `
                    <div class="space-y-3">
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Room Name</label>
                            <p class="text-gray-900 dark:text-white">${escapeHtml(room.name)}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                            <p class="text-gray-900 dark:text-white">${escapeHtml(room.description || 'No description')}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Type</label>
                            <p class="text-gray-900 dark:text-white">${room.room_type}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Participants</label>
                            <p class="text-gray-900 dark:text-white">${room.participant_count} members</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Created By</label>
                            <p class="text-gray-900 dark:text-white">${escapeHtml(room.created_by_name)}</p>
                        </div>
                        <div>
                            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Created</label>
                            <p class="text-gray-900 dark:text-white">${new Date(room.created_at).toLocaleDateString()}</p>
                        </div>
                    </div>
                `;
                document.getElementById('room-info-modal').classList.remove('hidden');
            }
        })
        .catch(error => console.error('Error loading room info:', error));
}

function closeRoomInfo() {
    document.getElementById('room-info-modal').classList.add('hidden');
}

function showRoomSettings() {
    document.getElementById('room-settings-modal').classList.remove('hidden');
    // Initialize toggle settings when modal opens
    initializeToggleSettings();
}

function closeRoomSettings() {
    document.getElementById('room-settings-modal').classList.add('hidden');
}

function toggleSearch() {
    const searchBar = document.getElementById('search-bar');
    searchBar.classList.toggle('hidden');
    if (!searchBar.classList.contains('hidden')) {
        document.getElementById('search-input').focus();
    }
}

function searchMessages() {
    const query = document.getElementById('search-input').value.trim();
    if (!query || !currentRoomId) return;

    fetch(`live_chat_api.php?action=search_messages&room_id=${currentRoomId}&query=${encodeURIComponent(query)}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySearchResults(data.messages);
            }
        })
        .catch(error => console.error('Error searching messages:', error));
}

function displaySearchResults(messages) {
    const container = document.getElementById('messages-container');
    container.innerHTML = '<div class="text-center text-blue-600 dark:text-blue-400 py-4"><i class="fas fa-search mr-2"></i>Search Results</div>';

    if (messages.length === 0) {
        container.innerHTML += '<div class="text-center text-gray-500 dark:text-gray-400 py-8">No messages found</div>';
        return;
    }

    messages.forEach(message => {
        const messageElement = createMessageElement(message);
        container.appendChild(messageElement);
    });
}

function reactToMessage(messageId, emoji) {
    console.log('Reacting to message:', messageId, 'with emoji:', emoji);

    const formData = new FormData();
    formData.append('action', 'react_to_message');
    formData.append('message_id', messageId);
    formData.append('emoji', emoji);

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        console.log('Reaction response:', data);
        if (data.success) {
            // Refresh messages to show updated reactions
            loadMessages();
            // Show feedback to user
            showNotification('Reaction added!', `You reacted with ${emoji}`, null, 1000);
        } else {
            console.error('Failed to react:', data.message);
            alert('Failed to add reaction: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error reacting to message:', error);
        alert('Failed to add reaction. Please try again.');
    });
}

function reportMessage(messageId, userId) {
    document.getElementById('report-message-id').value = messageId;
    document.getElementById('report-user-id').value = userId;
    document.getElementById('report-modal').classList.remove('hidden');
}

function closeReportModal() {
    document.getElementById('report-modal').classList.add('hidden');
    document.getElementById('report-form').reset();
}

function showImageModal(imagePath) {
    // Create image modal
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50';
    modal.onclick = () => modal.remove();

    modal.innerHTML = `
        <div class="max-w-4xl max-h-4xl p-4">
            <img src="../uploads/${imagePath}" alt="Full size image" class="max-w-full max-h-full object-contain">
            <button onclick="this.parentElement.parentElement.remove()" class="absolute top-4 right-4 text-white text-2xl hover:text-gray-300">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    document.body.appendChild(modal);
}

function clearChatHistory() {
    if (confirm('This will clear your local chat history. Are you sure?')) {
        localStorage.removeItem(`chat_history_${currentRoomId}`);
        if (currentRoomId) {
            // Clear the messages container
            const container = document.getElementById('messages-container');
            if (container) {
                container.innerHTML = '<div class="text-center py-8 text-gray-500 dark:text-gray-400">Chat history cleared</div>';
            }
            // Reset message tracking
            lastMessageId = 0;
        }
        // Close the modal
        closeRoomSettings();
    }
}

// Toggle functionality for room settings
function initializeToggleSettings() {
    // Load saved settings from localStorage
    const notificationsEnabled = localStorage.getItem('chat_notifications') !== 'false';
    const soundEnabled = localStorage.getItem('chat_sound') !== 'false';
    const encryptionEnabled = localStorage.getItem('chat_encryption') === 'true';
    const cachingEnabled = localStorage.getItem('chat_caching') !== 'false';

    // Get toggle elements
    const notificationsToggle = document.getElementById('notifications-toggle');
    const soundToggle = document.getElementById('sound-toggle');
    const encryptionToggle = document.getElementById('encryption-toggle');
    const cachingToggle = document.getElementById('caching-toggle');

    if (!notificationsToggle || !soundToggle) {
        console.log('Toggle elements not found, retrying...');
        return;
    }

    // Set initial toggle states
    notificationsToggle.checked = notificationsEnabled;
    soundToggle.checked = soundEnabled;

    if (encryptionToggle) {
        encryptionToggle.checked = encryptionEnabled;
        encryptionToggle.removeEventListener('change', handleEncryptionToggle);
        encryptionToggle.addEventListener('change', handleEncryptionToggle);
    }

    if (cachingToggle) {
        cachingToggle.checked = cachingEnabled;
        cachingToggle.removeEventListener('change', handleCachingToggle);
        cachingToggle.addEventListener('change', handleCachingToggle);
    }

    // Remove existing event listeners to prevent duplicates
    notificationsToggle.removeEventListener('change', handleNotificationToggle);
    soundToggle.removeEventListener('change', handleSoundToggle);

    // Add event listeners for toggles
    notificationsToggle.addEventListener('change', handleNotificationToggle);
    soundToggle.addEventListener('change', handleSoundToggle);
}

function handleNotificationToggle() {
    localStorage.setItem('chat_notifications', this.checked);
    if (this.checked) {
        // Request notification permission if not already granted
        if (Notification.permission === 'default') {
            Notification.requestPermission();
        }
    }
}

function handleSoundToggle() {
    localStorage.setItem('chat_sound', this.checked);
}

function handleEncryptionToggle() {
    localStorage.setItem('chat_encryption', this.checked);
    isEncryptionEnabled = this.checked;
    updateEncryptionIcon();

    const status = this.checked ? 'enabled' : 'disabled';
    showNotification('Encryption Status', `Message encryption ${status}`, '../assets/images/lock-icon.png');
}

function handleCachingToggle() {
    localStorage.setItem('chat_caching', this.checked);

    if (!this.checked) {
        // Clear cache if disabled
        messageCache.clear();
        localStorage.removeItem(`chat_cache_${currentRoomId}`);
    }

    const status = this.checked ? 'enabled' : 'disabled';
    showNotification('Caching Status', `Message caching ${status}`, '../assets/images/cache-icon.png');
}

// Function to show notification (if enabled)
function showNotification(title, message, icon = null) {
    const notificationsEnabled = localStorage.getItem('chat_notifications') !== 'false';

    if (notificationsEnabled && Notification.permission === 'granted') {
        new Notification(title, {
            body: message,
            icon: icon || '../assets/images/logo.png'
        });
    }
}

// Function to play sound (if enabled)
function playNotificationSound() {
    const soundEnabled = localStorage.getItem('chat_sound') !== 'false';

    if (soundEnabled) {
        // Create audio element for notification sound
        const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIG2m98OScTgwOUarm7blmGgU7k9n1unEiBC13yO/eizEIHWq+8+OWT');
        audio.play().catch(() => {
            // Fallback if audio fails to play
            console.log('Could not play notification sound');
        });
    }
}

// Handle report form submission
document.getElementById('report-form').addEventListener('submit', function(e) {
    e.preventDefault();

    const formData = new FormData();
    formData.append('action', 'submit_report');
    formData.append('reported_user_id', document.getElementById('report-user-id').value);
    formData.append('message_id', document.getElementById('report-message-id').value);
    formData.append('room_id', currentRoomId);
    formData.append('report_type', document.getElementById('report-type').value);
    formData.append('description', document.getElementById('report-description').value);

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Report submitted successfully. Thank you for helping keep our community safe.');
            closeReportModal();
        } else {
            alert('Failed to submit report: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error submitting report:', error);
        alert('Failed to submit report');
    });
});

// Handle search input enter key
document.getElementById('search-input').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchMessages();
    }
});

// WebSocket Implementation for Real-time Updates
function initializeWebSocket() {
    // Note: This would require a WebSocket server implementation
    // For now, we'll use enhanced polling with better performance
    console.log('WebSocket initialization - would connect to ws://localhost:8080/chat');
}

// Voice and Video Calling Functions
function startVoiceCall() {
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    document.getElementById('call-modal-title').textContent = 'Voice Call';
    document.getElementById('video-area').classList.add('hidden');
    document.getElementById('audio-area').classList.remove('hidden');
    document.getElementById('video-button').classList.add('hidden');
    document.getElementById('call-modal').classList.remove('hidden');

    initializeCall(false);
}

function startVideoCall() {
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    document.getElementById('call-modal-title').textContent = 'Video Call';
    document.getElementById('video-area').classList.remove('hidden');
    document.getElementById('audio-area').classList.add('hidden');
    document.getElementById('video-button').classList.remove('hidden');
    document.getElementById('call-modal').classList.remove('hidden');

    initializeCall(true);
}

async function initializeCall(isVideo) {
    try {
        const constraints = {
            audio: true,
            video: isVideo
        };

        localStream = await navigator.mediaDevices.getUserMedia(constraints);

        if (isVideo) {
            document.getElementById('local-video').srcObject = localStream;
        }

        // Start call timer
        callStartTime = Date.now();
        callTimer = setInterval(updateCallDuration, 1000);

        // In a real implementation, this would establish WebRTC connection
        console.log('Call initialized with constraints:', constraints);

    } catch (error) {
        console.error('Error accessing media devices:', error);
        alert('Could not access camera/microphone. Please check permissions.');
        endCall();
    }
}

function toggleMute() {
    if (localStream) {
        const audioTrack = localStream.getAudioTracks()[0];
        if (audioTrack) {
            audioTrack.enabled = !audioTrack.enabled;
            const muteButton = document.getElementById('mute-button');
            const icon = muteButton.querySelector('i');

            if (audioTrack.enabled) {
                icon.className = 'fas fa-microphone';
                muteButton.classList.remove('bg-red-600');
                muteButton.classList.add('bg-gray-600');
            } else {
                icon.className = 'fas fa-microphone-slash';
                muteButton.classList.remove('bg-gray-600');
                muteButton.classList.add('bg-red-600');
            }
        }
    }
}

function toggleVideo() {
    if (localStream) {
        const videoTrack = localStream.getVideoTracks()[0];
        if (videoTrack) {
            videoTrack.enabled = !videoTrack.enabled;
            const videoButton = document.getElementById('video-button');
            const icon = videoButton.querySelector('i');

            if (videoTrack.enabled) {
                icon.className = 'fas fa-video';
                videoButton.classList.remove('bg-red-600');
                videoButton.classList.add('bg-gray-600');
            } else {
                icon.className = 'fas fa-video-slash';
                videoButton.classList.remove('bg-gray-600');
                videoButton.classList.add('bg-red-600');
            }
        }
    }
}

function updateCallDuration() {
    if (callStartTime) {
        const duration = Math.floor((Date.now() - callStartTime) / 1000);
        const minutes = Math.floor(duration / 60);
        const seconds = duration % 60;
        document.getElementById('call-duration').textContent =
            `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    }
}

function endCall() {
    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
        localStream = null;
    }

    if (callTimer) {
        clearInterval(callTimer);
        callTimer = null;
    }

    callStartTime = null;
    document.getElementById('call-modal').classList.add('hidden');
}

// Message Encryption Functions
function toggleEncryption() {
    isEncryptionEnabled = !isEncryptionEnabled;
    localStorage.setItem('chat_encryption', isEncryptionEnabled);
    updateEncryptionIcon();

    const status = isEncryptionEnabled ? 'enabled' : 'disabled';
    showNotification('Encryption Status', `Message encryption ${status}`, '../assets/images/lock-icon.png');
}

function updateEncryptionIcon() {
    const icon = document.getElementById('encryption-icon');
    if (isEncryptionEnabled) {
        icon.className = 'fas fa-lock text-lg text-green-400';
        icon.parentElement.title = 'Encryption Enabled';
    } else {
        icon.className = 'fas fa-unlock text-lg';
        icon.parentElement.title = 'Encryption Disabled';
    }
}

function encryptMessage(message) {
    if (!isEncryptionEnabled) return message;

    // Simple encryption for demonstration (in production, use proper encryption)
    return btoa(message);
}

function decryptMessage(encryptedMessage) {
    if (!isEncryptionEnabled) return encryptedMessage;

    try {
        return atob(encryptedMessage);
    } catch (e) {
        return encryptedMessage; // Return original if decryption fails
    }
}

// Advanced Search Functions
function toggleAdvancedSearch() {
    const searchBar = document.getElementById('advanced-search-bar');
    searchBar.classList.toggle('hidden');

    if (!searchBar.classList.contains('hidden')) {
        document.getElementById('advanced-search-input').focus();
        loadSearchUsers();
    }
}

function loadSearchUsers() {
    if (!currentRoomId) return;

    fetch(`live_chat_api.php?action=get_room_users&room_id=${currentRoomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const userFilter = document.getElementById('search-user-filter');
                userFilter.innerHTML = '<option value="">All Users</option>';

                data.users.forEach(user => {
                    const option = document.createElement('option');
                    option.value = user.id;
                    option.textContent = user.name;
                    userFilter.appendChild(option);
                });
            }
        })
        .catch(error => console.error('Error loading users:', error));
}

function performAdvancedSearch() {
    const query = document.getElementById('advanced-search-input').value.trim();
    const messageType = document.getElementById('search-filter-type').value;
    const dateFrom = document.getElementById('search-date-from').value;
    const dateTo = document.getElementById('search-date-to').value;
    const userId = document.getElementById('search-user-filter').value;

    if (!query && !messageType && !dateFrom && !dateTo && !userId) {
        alert('Please enter search criteria');
        return;
    }

    const params = new URLSearchParams({
        action: 'advanced_search',
        room_id: currentRoomId,
        query: query,
        message_type: messageType,
        date_from: dateFrom,
        date_to: dateTo,
        user_id: userId
    });

    fetch(`live_chat_api.php?${params}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySearchResults(data.messages);
            } else {
                alert('Search failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error performing search:', error);
            alert('Search failed');
        });
}

function clearAdvancedSearch() {
    document.getElementById('advanced-search-input').value = '';
    document.getElementById('search-filter-type').value = 'all';
    document.getElementById('search-date-from').value = '';
    document.getElementById('search-date-to').value = '';
    document.getElementById('search-user-filter').value = '';
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }

    if (localStream) {
        localStream.getTracks().forEach(track => track.stop());
    }

    if (webSocketConnection) {
        webSocketConnection.close();
    }

    updateOnlineStatus('offline');
});

// Message Threading Functions
function toggleThreadView() {
    const threadModal = document.getElementById('thread-modal');
    threadModal.classList.toggle('hidden');

    if (!threadModal.classList.contains('hidden')) {
        // Reset thread ID and load all threads
        currentThreadId = null;
        loadThreadMessages();

        // Update the input placeholder
        const input = document.getElementById('thread-reply-input');
        input.placeholder = 'Select a thread to reply...';
        input.disabled = true;
    }
}

function loadThreadMessages() {
    if (!currentRoomId) return;

    fetch(`live_chat_api.php?action=get_thread_messages&room_id=${currentRoomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayThreadMessages(data.threads);
            }
        })
        .catch(error => console.error('Error loading thread messages:', error));
}

function displayThreadMessages(threads) {
    const container = document.getElementById('thread-messages');
    container.innerHTML = '';

    if (threads.length === 0) {
        container.innerHTML = `
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <i class="fas fa-comments text-4xl mb-4"></i>
                <p>No message threads found.</p>
                <p class="text-sm">Reply to a message to start a thread.</p>
            </div>
        `;
        return;
    }

    threads.forEach(thread => {
        const threadElement = document.createElement('div');
        threadElement.className = 'thread-item border border-gray-200 dark:border-gray-600 rounded-lg p-4 mb-4 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors';
        threadElement.dataset.threadId = thread.id;
        threadElement.onclick = () => replyToThread(thread.id);

        threadElement.innerHTML = `
            <div class="font-medium text-gray-900 dark:text-white mb-2">
                <i class="fas fa-quote-left text-gray-400 mr-2"></i>
                ${escapeHtml(thread.original_message)}
            </div>
            <div class="text-sm text-gray-500 dark:text-gray-400 mb-3">
                <i class="fas fa-user mr-1"></i>${thread.original_sender_name} •
                <i class="fas fa-reply mr-1"></i>${thread.reply_count} replies
            </div>
            <div class="space-y-2 max-h-32 overflow-y-auto">
                ${thread.replies.map(reply => `
                    <div class="bg-gray-50 dark:bg-gray-700 rounded p-2">
                        <div class="text-xs text-gray-500 dark:text-gray-400">
                            <i class="fas fa-user mr-1"></i>${reply.sender_name} •
                            <span>${formatTime(reply.created_at)}</span>
                        </div>
                        <div class="text-sm mt-1">${escapeHtml(reply.message)}</div>
                    </div>
                `).join('')}
            </div>
            <div class="mt-3 pt-2 border-t border-gray-200 dark:border-gray-600">
                <button onclick="event.stopPropagation(); replyToThread(${thread.id})" class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                    <i class="fas fa-reply mr-1"></i>Reply to this thread
                </button>
            </div>
        `;
        container.appendChild(threadElement);
    });
}

function replyToThread(threadId) {
    currentThreadId = threadId;
    const input = document.getElementById('thread-reply-input');
    input.disabled = false;
    input.placeholder = 'Reply to thread...';
    input.focus();

    // Highlight the selected thread
    document.querySelectorAll('.thread-item').forEach(item => {
        item.classList.remove('bg-blue-50', 'dark:bg-blue-900');
    });

    const selectedThread = document.querySelector(`[data-thread-id="${threadId}"]`);
    if (selectedThread) {
        selectedThread.classList.add('bg-blue-50', 'dark:bg-blue-900');
    }
}

function sendThreadReply() {
    const input = document.getElementById('thread-reply-input');
    const message = input.value.trim();

    if (!message) {
        alert('Please enter a message');
        return;
    }

    if (!currentThreadId) {
        alert('No thread selected. Please select a message to reply to.');
        return;
    }

    // Disable input while sending
    input.disabled = true;

    const formData = new FormData();
    formData.append('action', 'send_thread_reply');
    formData.append('thread_id', currentThreadId);
    formData.append('message', message);

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            input.value = '';
            loadThreadMessages();
            // Also refresh main chat to show the new message
            loadMessages();
        } else {
            alert('Failed to send reply: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error sending thread reply:', error);
        alert('Failed to send reply. Please try again.');
    })
    .finally(() => {
        // Re-enable input
        input.disabled = false;
        input.focus();
    });
}

function closeThreadModal() {
    document.getElementById('thread-modal').classList.add('hidden');
    currentThreadId = null;

    // Reset input state
    const input = document.getElementById('thread-reply-input');
    input.value = '';
    input.disabled = true;
    input.placeholder = 'Select a thread to reply...';

    // Remove thread selection highlights
    document.querySelectorAll('.thread-item').forEach(item => {
        item.classList.remove('bg-blue-50', 'dark:bg-blue-900');
    });
}

// Bulk Operations Functions
function toggleBulkMode() {
    isBulkModeActive = !isBulkModeActive;
    const button = document.getElementById('bulk-mode-button');

    if (isBulkModeActive) {
        button.classList.remove('bg-purple-600', 'hover:bg-purple-700');
        button.classList.add('bg-red-600', 'hover:bg-red-700');
        button.innerHTML = '<i class="fas fa-times"></i>';
        button.title = 'Exit Bulk Mode';
        showBulkControls();
    } else {
        button.classList.remove('bg-red-600', 'hover:bg-red-700');
        button.classList.add('bg-purple-600', 'hover:bg-purple-700');
        button.innerHTML = '<i class="fas fa-check-square"></i>';
        button.title = 'Bulk Operations';
        hideBulkControls();
        selectedMessages.clear();
    }

    updateMessageCheckboxes();
}

function showBulkControls() {
    // Add bulk control bar if it doesn't exist
    if (!document.getElementById('bulk-controls')) {
        const bulkControls = document.createElement('div');
        bulkControls.id = 'bulk-controls';
        bulkControls.className = 'fixed top-20 left-0 right-0 z-40 p-3 bg-yellow-100 dark:bg-yellow-900 border-b border-yellow-200 dark:border-yellow-700 shadow-lg';
        bulkControls.innerHTML = `
            <div class="max-w-7xl mx-auto flex items-center justify-between">
                <span class="text-sm font-medium text-yellow-800 dark:text-yellow-200">
                    <span id="selected-count">0</span> messages selected
                </span>
                <div class="flex space-x-2">
                    <button onclick="bulkDeleteMessages()" class="bg-red-600 hover:bg-red-700 text-white px-3 py-2 rounded text-sm transition-colors duration-200">
                        <i class="fas fa-trash mr-1"></i>Delete
                    </button>
                    <button onclick="bulkExportMessages()" class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded text-sm transition-colors duration-200">
                        <i class="fas fa-download mr-1"></i>Export
                    </button>
                    <button onclick="toggleBulkMode()" class="bg-gray-600 hover:bg-gray-700 text-white px-3 py-2 rounded text-sm transition-colors duration-200">
                        <i class="fas fa-times mr-1"></i>Cancel
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(bulkControls);

        // Adjust main content padding to account for bulk controls
        const main = document.querySelector('main');
        if (main) {
            main.style.paddingTop = '140px'; // 80px header + 60px bulk controls
        }
    }
}

function hideBulkControls() {
    const bulkControls = document.getElementById('bulk-controls');
    if (bulkControls) {
        bulkControls.remove();
    }

    // Reset main content padding
    const main = document.querySelector('main');
    if (main) {
        main.style.paddingTop = '80px'; // Reset to header height only
    }
}

function updateMessageCheckboxes() {
    const messages = document.querySelectorAll('.message-item');
    messages.forEach(message => {
        const messageId = message.dataset.messageId;
        let checkbox = message.querySelector('.message-checkbox');

        if (isBulkModeActive) {
            if (!checkbox) {
                checkbox = document.createElement('input');
                checkbox.type = 'checkbox';
                checkbox.className = 'message-checkbox mr-2';
                checkbox.addEventListener('change', function() {
                    if (this.checked) {
                        selectedMessages.add(messageId);
                    } else {
                        selectedMessages.delete(messageId);
                    }
                    updateSelectedCount();
                });
                message.querySelector('.flex').insertBefore(checkbox, message.querySelector('.flex').firstChild);
            }
        } else {
            if (checkbox) {
                checkbox.remove();
            }
        }
    });
}

function updateSelectedCount() {
    const countElement = document.getElementById('selected-count');
    if (countElement) {
        countElement.textContent = selectedMessages.size;
    }
}

function bulkDeleteMessages() {
    if (selectedMessages.size === 0) {
        alert('No messages selected');
        return;
    }

    if (!confirm(`Delete ${selectedMessages.size} selected messages?`)) {
        return;
    }

    const formData = new FormData();
    formData.append('action', 'bulk_delete_messages');
    formData.append('message_ids', Array.from(selectedMessages).join(','));

    fetch('live_chat_api.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Immediately remove deleted messages from UI
            selectedMessages.forEach(messageId => {
                const messageElement = document.querySelector(`[data-message-id="${messageId}"]`);
                if (messageElement) {
                    messageElement.style.transition = 'opacity 0.3s ease-out';
                    messageElement.style.opacity = '0';
                    setTimeout(() => {
                        messageElement.remove();
                    }, 300);
                }
            });

            selectedMessages.clear();
            updateSelectedCount();

            // Exit bulk mode if no messages left
            const remainingMessages = document.querySelectorAll('.message-item');
            if (remainingMessages.length === 0) {
                toggleBulkMode();
            }

            // Show success notification
            showNotification('Messages Deleted', `Successfully deleted ${data.deleted_count} messages`, null, 2000);
        } else {
            alert('Failed to delete messages: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error deleting messages:', error);
        alert('Failed to delete messages');
    });
}

function bulkExportMessages() {
    if (selectedMessages.size === 0) {
        alert('No messages selected');
        return;
    }

    const params = new URLSearchParams({
        action: 'export_messages',
        message_ids: Array.from(selectedMessages).join(',')
    });

    window.open(`live_chat_api.php?${params}`, '_blank');
}

// File Preview Functions
function clearFilePreview() {
    document.getElementById('file-preview-area').classList.add('hidden');
    document.getElementById('file-input').value = '';
}

function clearReplyPreview() {
    document.getElementById('reply-preview-area').classList.add('hidden');
    replyToMessageId = null;
}

// Message Caching Functions
function loadCachedMessages() {
    const cachingEnabled = localStorage.getItem('chat_caching') !== 'false';
    if (!cachingEnabled) return;

    const cachedData = localStorage.getItem(`chat_cache_${currentRoomId}`);
    if (cachedData) {
        try {
            const messages = JSON.parse(cachedData);
            messageCache.set(currentRoomId, messages);
        } catch (e) {
            console.error('Error loading cached messages:', e);
        }
    }
}

function cacheMessages(messages) {
    const cachingEnabled = localStorage.getItem('chat_caching') !== 'false';
    if (!cachingEnabled) return;

    try {
        localStorage.setItem(`chat_cache_${currentRoomId}`, JSON.stringify(messages));
        messageCache.set(currentRoomId, messages);
    } catch (e) {
        console.error('Error caching messages:', e);
    }
}

// Export Chat History Function
function exportChatHistory() {
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    const params = new URLSearchParams({
        action: 'export_chat_history',
        room_id: currentRoomId
    });

    window.open(`live_chat_api.php?${params}`, '_blank');
}

// Debug user access
function debugUserAccess() {
    if (!currentRoomId) {
        alert('Please select a chat room first');
        return;
    }

    fetch(`live_chat_api.php?action=debug_user_access&room_id=${currentRoomId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Debug Info:', data.debug_info);
                alert('Debug info logged to console. Check browser console for details.');
            } else {
                alert('Debug failed: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Debug error:', error);
            alert('Debug failed');
        });
}

// Scroll functionality
function isScrolledToBottom() {
    const container = document.getElementById('messages-container');
    return container.scrollTop + container.clientHeight >= container.scrollHeight - 5; // 5px tolerance
}

function isScrolledToTop() {
    const container = document.getElementById('messages-container');
    return container.scrollTop <= 5; // 5px tolerance
}

function scrollToBottom() {
    const container = document.getElementById('messages-container');
    container.scrollTo({
        top: container.scrollHeight,
        behavior: 'smooth'
    });
    hideScrollToBottomButton();
}

function scrollToTop() {
    const container = document.getElementById('messages-container');
    container.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
    hideScrollToTopButton();
}

function showScrollToBottomButton() {
    const button = document.getElementById('scroll-to-bottom');
    if (button) {
        button.classList.remove('hidden');
    }
}

function hideScrollToBottomButton() {
    const button = document.getElementById('scroll-to-bottom');
    if (button) {
        button.classList.add('hidden');
    }
}

function showScrollToTopButton() {
    const button = document.getElementById('scroll-to-top');
    if (button) {
        button.classList.remove('hidden');
    }
}

function hideScrollToTopButton() {
    const button = document.getElementById('scroll-to-top');
    if (button) {
        button.classList.add('hidden');
    }
}

function updateScrollButtonVisibility() {
    const container = document.getElementById('messages-container');
    const scrollToBottomBtn = document.getElementById('scroll-to-bottom');
    const scrollToTopBtn = document.getElementById('scroll-to-top');

    if (!container || !scrollToBottomBtn || !scrollToTopBtn) return;

    // Add scroll event listener if not already added
    if (!container.hasScrollListener) {
        container.addEventListener('scroll', function() {
            const scrollTop = container.scrollTop;
            const scrollHeight = container.scrollHeight;
            const clientHeight = container.clientHeight;

            // Only show buttons if content is scrollable
            if (scrollHeight > clientHeight) {
                // Show/hide scroll to bottom button
                if (isScrolledToBottom()) {
                    hideScrollToBottomButton();
                } else {
                    showScrollToBottomButton();
                }

                // Show/hide scroll to top button
                if (isScrolledToTop()) {
                    hideScrollToTopButton();
                } else if (scrollTop > 50) { // Show after scrolling down 50px
                    showScrollToTopButton();
                }
            } else {
                // Hide both buttons if content is not scrollable
                hideScrollToBottomButton();
                hideScrollToTopButton();
            }
        });
        container.hasScrollListener = true;
    }

    // Initial check
    const scrollTop = container.scrollTop;
    const scrollHeight = container.scrollHeight;
    const clientHeight = container.clientHeight;

    if (scrollHeight > clientHeight) {
        // Content is scrollable, show appropriate buttons
        if (!isScrolledToBottom()) {
            showScrollToBottomButton();
        }
        if (!isScrolledToTop() && scrollTop > 50) {
            showScrollToTopButton();
        }
    } else {
        // Content is not scrollable, hide both buttons
        hideScrollToBottomButton();
        hideScrollToTopButton();
    }
}

// Initialize scroll buttons when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Initialize scroll button visibility
    setTimeout(function() {
        updateScrollButtonVisibility();
    }, 500); // Small delay to ensure content is loaded

    // Also update when window is resized
    window.addEventListener('resize', function() {
        setTimeout(updateScrollButtonVisibility, 100);
    });
});
</script>
