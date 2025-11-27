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
$user_name = $_SESSION['name'];
$user_role = $_SESSION['role'];

// Get user's active chat conversations
$conversations = [];
try {
    $conv_query = "
        SELECT cc.*,
               COUNT(cm.id) as message_count,
               MAX(cm.created_at) as last_message_time,
               (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id AND sender_id != :user_id AND is_read = FALSE) as unread_count
        FROM chat_conversations cc
        LEFT JOIN chat_messages cm ON cc.id = cm.conversation_id
        WHERE cc.user_id = :user_id OR cc.support_agent_id = :user_id
        GROUP BY cc.id
        ORDER BY cc.updated_at DESC
    ";
    $conv_stmt = $db->prepare($conv_query);
    $conv_stmt->bindParam(':user_id', $user_id);
    $conv_stmt->execute();
    $conversations = $conv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conversations = [];
}

$title = "Help & Support";
$breadcrumbs = [
    ['title' => 'Help & Support']
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
                                <h1 class="text-3xl font-bold mb-2">Help & Support Center</h1>
                                <p class="text-blue-100 text-lg">Find answers to your questions and get the help you need</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-question-circle mr-2"></i>
                                        24/7 Support Available
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-book mr-2"></i>
                                        Comprehensive Guides
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-life-ring text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <!-- Search Help -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-8 mb-8">
                <div class="max-w-2xl mx-auto">
                    <div class="relative">
                        <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input type="text" placeholder="Search for help articles, tutorials, or FAQs..." class="w-full pl-12 pr-4 py-4 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </div>
                </div>
            </div>

            <!-- Quick Help Categories -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-12">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Student Management</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Learn how to manage student records, enrollment, and profiles</p>
                    <a href="students/index.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">View Students →</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-graduation-cap text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Academic Management</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Manage classes, subjects, assignments, and academic records</p>
                    <a href="academic/classes/index.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">View Classes →</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-money-bill-wave text-purple-600 dark:text-purple-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Finance Management</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Handle fee structures, payments, and financial reporting</p>
                    <a href="reports/fee_collection.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">View Reports →</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-calendar-check text-yellow-600 dark:text-yellow-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Attendance Tracking</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Track and manage student and staff attendance</p>
                    <a href="attendance/take.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">Take Attendance →</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-cog text-red-600 dark:text-red-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">System Settings</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Configure system preferences and user permissions</p>
                    <a href="settings.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">View Settings →</a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-md transition-shadow duration-200">
                    <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-4">
                        <i class="fas fa-question-circle text-indigo-600 dark:text-indigo-400 text-xl"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Troubleshooting</h3>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Common issues and their solutions</p>
                    <a href="reports/index.php" class="text-blue-600 dark:text-blue-400 text-sm font-medium hover:text-blue-800 dark:hover:text-blue-300">View Reports →</a>
                </div>
            </div>

            <!-- Live Chat Section -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <!-- Chat Conversations List -->
                <div class="lg:col-span-1">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Support Conversations</h2>
                                <button onclick="startNewChat()" class="px-3 py-1 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 transition-colors duration-200">
                                    <i class="fas fa-plus mr-1"></i>New Chat
                                </button>
                            </div>
                        </div>
                        <div class="max-h-96 overflow-y-auto">
                            <?php if (empty($conversations)): ?>
                            <div class="p-6 text-center">
                                <i class="fas fa-comments text-gray-400 text-3xl mb-3"></i>
                                <p class="text-gray-500 dark:text-gray-400 text-sm">No conversations yet</p>
                                <button onclick="startNewChat()" class="mt-3 px-4 py-2 bg-blue-600 text-white rounded-lg text-sm hover:bg-blue-700 transition-colors duration-200">
                                    Start Your First Chat
                                </button>
                            </div>
                            <?php else: ?>
                            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($conversations as $conv): ?>
                                <div class="p-4 hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer conversation-item"
                                     data-conversation-id="<?php echo $conv['id']; ?>"
                                     onclick="openConversation(<?php echo $conv['id']; ?>)">
                                    <div class="flex items-start space-x-3">
                                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                            <i class="fas fa-headset text-blue-600 dark:text-blue-400"></i>
                                        </div>
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center justify-between">
                                                <h4 class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                                    <?php echo htmlspecialchars($conv['subject']); ?>
                                                </h4>
                                                <?php if ($conv['unread_count'] > 0): ?>
                                                <span class="bg-red-500 text-white text-xs rounded-full px-2 py-1">
                                                    <?php echo $conv['unread_count']; ?>
                                                </span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="flex items-center justify-between mt-1">
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                                    <?php
                                                    switch($conv['status']) {
                                                        case 'open': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                        case 'in_progress': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                        case 'resolved': echo 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'; break;
                                                        case 'closed': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                    }
                                                    ?>">
                                                    <?php echo ucfirst($conv['status']); ?>
                                                </span>
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?php echo $conv['last_message_time'] ? date('M j, g:i A', strtotime($conv['last_message_time'])) : date('M j, g:i A', strtotime($conv['created_at'])); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Chat Interface -->
                <div class="lg:col-span-2">
                    <div id="chatInterface" class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 h-96 flex flex-col">
                        <!-- Chat Header -->
                        <div id="chatHeader" class="p-4 border-b border-gray-200 dark:border-gray-700 hidden">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center">
                                        <i class="fas fa-headset text-green-600 dark:text-green-400 text-sm"></i>
                                    </div>
                                    <div>
                                        <h3 id="chatTitle" class="text-sm font-medium text-gray-900 dark:text-white"></h3>
                                        <p id="chatStatus" class="text-xs text-gray-500 dark:text-gray-400"></p>
                                    </div>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span id="typingIndicator" class="text-xs text-blue-600 dark:text-blue-400 hidden">
                                        <i class="fas fa-circle animate-pulse"></i> Support is typing...
                                    </span>
                                    <button onclick="closeChat()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Chat Messages -->
                        <div id="chatMessages" class="flex-1 p-4 overflow-y-auto space-y-4">
                            <div class="text-center py-8">
                                <i class="fas fa-comments text-gray-400 text-4xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Welcome to Live Support</h3>
                                <p class="text-gray-500 dark:text-gray-400 mb-4">Select a conversation or start a new chat to get help</p>
                            </div>
                        </div>

                        <!-- Chat Input -->
                        <div id="chatInput" class="p-4 border-t border-gray-200 dark:border-gray-700 hidden">
                            <div class="flex items-center space-x-3">
                                <div class="flex-1">
                                    <input type="text" id="messageInput" placeholder="Type your message..."
                                           class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"
                                           onkeypress="handleKeyPress(event)">
                                </div>
                                <button onclick="sendMessage()" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contact Support -->
            <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-8 text-white text-center">
                <h2 class="text-2xl font-bold mb-4">Need Immediate Help?</h2>
                <p class="text-blue-100 mb-6">Our support team is here to help you with any questions or issues</p>
                <div class="flex flex-col sm:flex-row gap-4 justify-center">
                    <a href="mailto:support@greenwoodacademy.edu" class="px-6 py-3 bg-white text-blue-600 rounded-lg font-medium hover:bg-gray-100 transition-colors duration-200 inline-flex items-center justify-center">
                        <i class="fas fa-envelope mr-2"></i>Email Support
                    </a>
                    <button onclick="startNewChat()" class="px-6 py-3 bg-blue-700 text-white rounded-lg font-medium hover:bg-blue-800 transition-colors duration-200">
                        <i class="fas fa-comments mr-2"></i>Start Live Chat
                    </button>
                    <a href="tel:+1234567890" class="px-6 py-3 bg-blue-700 text-white rounded-lg font-medium hover:bg-blue-800 transition-colors duration-200 inline-flex items-center justify-center">
                        <i class="fas fa-phone mr-2"></i>Call Support
                    </a>
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

<!-- New Chat Modal -->
<div id="newChatModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50" onclick="closeModalOnBackdrop(event)">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full" onclick="event.stopPropagation()">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Start New Support Chat</h3>
                <form id="newChatForm" class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                        <input type="text" id="chatSubject" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="What do you need help with?">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority</label>
                        <select id="chatPriority" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="low">Low - General inquiry</option>
                            <option value="medium" selected>Medium - Need assistance</option>
                            <option value="high">High - Important issue</option>
                            <option value="urgent">Urgent - Critical problem</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Initial Message</label>
                        <textarea id="initialMessage" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Describe your issue or question..."></textarea>
                    </div>
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeNewChatModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            <i class="fas fa-comments mr-2"></i>Start Chat
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Global variables
let currentConversationId = null;
let messagePollingInterval = null;
let typingTimeout = null;

// Initialize chat functionality
document.addEventListener('DOMContentLoaded', function() {
    // Register service worker for offline support
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/school_ms/chat/chat-sw.js')
            .then(registration => {
                console.log('Chat service worker registered:', registration);
            })
            .catch(error => {
                console.log('Chat service worker registration failed:', error);
            });
    }

    // Auto-refresh conversations every 30 seconds
    setInterval(refreshConversations, 30000);

    // Handle new chat form submission
    document.getElementById('newChatForm').addEventListener('submit', function(e) {
        e.preventDefault();
        createNewChat();
    });

    // Handle message input typing
    document.getElementById('messageInput').addEventListener('input', function() {
        handleTyping();
    });

    // Check for offline messages to sync
    if (navigator.onLine) {
        syncOfflineMessages();
    }

    // Listen for online/offline events
    window.addEventListener('online', syncOfflineMessages);
    window.addEventListener('offline', () => {
        showNotification('You are offline. Messages will be saved and sent when connection is restored.', 'warning');
    });
});

// Start new chat
function startNewChat() {
    document.getElementById('newChatModal').classList.remove('hidden');
    document.getElementById('chatSubject').focus();
}

function closeNewChatModal() {
    document.getElementById('newChatModal').classList.add('hidden');
    document.getElementById('newChatForm').reset();
}

// Close modal only when clicking on backdrop, not inside modal
function closeModalOnBackdrop(event) {
    if (event.target === event.currentTarget) {
        closeNewChatModal();
    }
}

// Create new chat conversation
function createNewChat() {
    const subject = document.getElementById('chatSubject').value;
    const priority = document.getElementById('chatPriority').value;
    const initialMessage = document.getElementById('initialMessage').value;

    if (!subject.trim()) {
        showNotification('Please enter a subject for your chat', 'error');
        return;
    }

    fetch('chat/create_conversation.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            subject: subject,
            priority: priority,
            initial_message: initialMessage
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            closeNewChatModal();
            showNotification('Chat started successfully!', 'success');
            // Refresh the page to show new conversation
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification('Error: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('Failed to start chat. Please try again.', 'error');
    });
}

// Open conversation
function openConversation(conversationId) {
    currentConversationId = conversationId;

    // Update UI
    document.getElementById('chatHeader').classList.remove('hidden');
    document.getElementById('chatInput').classList.remove('hidden');

    // Load conversation details
    loadConversationDetails(conversationId);
    loadMessages(conversationId);

    // Start polling for new messages
    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }
    messagePollingInterval = setInterval(() => {
        loadMessages(conversationId);
    }, 3000);

    // Mark conversation as active
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('bg-blue-50', 'dark:bg-blue-900');
    });
    document.querySelector(`[data-conversation-id="${conversationId}"]`).classList.add('bg-blue-50', 'dark:bg-blue-900');
}

// Load conversation details
function loadConversationDetails(conversationId) {
    fetch(`chat/get_conversation.php?id=${conversationId}`)
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('chatTitle').textContent = data.conversation.subject;
            document.getElementById('chatStatus').textContent = `Status: ${data.conversation.status.charAt(0).toUpperCase() + data.conversation.status.slice(1)} • Priority: ${data.conversation.priority.charAt(0).toUpperCase() + data.conversation.priority.slice(1)}`;
        }
    })
    .catch(error => {
        console.error('Error loading conversation details:', error);
    });
}

// Load messages with improved persistence
function loadMessages(conversationId) {
    fetch(`chat/get_messages.php?conversation_id=${conversationId}`)
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            displayMessages(data.messages);
            markMessagesAsRead(conversationId);

            // Store messages locally for persistence
            localStorage.setItem(`chat_messages_${conversationId}`, JSON.stringify(data.messages));
        } else {
            console.error('Error loading messages:', data.message);

            // Try to load from local storage if server fails
            const cachedMessages = localStorage.getItem(`chat_messages_${conversationId}`);
            if (cachedMessages) {
                console.log('Loading messages from cache');
                displayMessages(JSON.parse(cachedMessages));
            }
        }
    })
    .catch(error => {
        console.error('Error loading messages:', error);

        // Try to load from local storage if network fails
        const cachedMessages = localStorage.getItem(`chat_messages_${conversationId}`);
        if (cachedMessages) {
            console.log('Loading messages from cache due to network error');
            displayMessages(JSON.parse(cachedMessages));
        } else {
            showNotification('Failed to load messages. Please check your connection.', 'error');
        }
    });
}

// Display messages in chat interface
function displayMessages(messages) {
    const chatMessages = document.getElementById('chatMessages');
    chatMessages.innerHTML = '';

    messages.forEach(message => {
        const messageDiv = document.createElement('div');
        const isOwnMessage = message.sender_id == <?php echo $user_id; ?>;

        messageDiv.className = `flex ${isOwnMessage ? 'justify-end' : 'justify-start'} mb-4`;

        messageDiv.innerHTML = `
            <div class="max-w-xs lg:max-w-md px-4 py-2 rounded-lg ${isOwnMessage ? 'bg-blue-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-900 dark:text-white'}">
                <div class="text-sm">${escapeHtml(message.message)}</div>
                <div class="text-xs mt-1 ${isOwnMessage ? 'text-blue-100' : 'text-gray-500 dark:text-gray-400'}">
                    ${formatTime(message.created_at)}
                </div>
            </div>
        `;

        chatMessages.appendChild(messageDiv);
    });

    // Scroll to bottom
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

// Send message with offline support
function sendMessage() {
    const messageInput = document.getElementById('messageInput');
    const message = messageInput.value.trim();

    if (!message || !currentConversationId) {
        return;
    }

    // Clear input immediately for better UX
    messageInput.value = '';

    // Add message to UI optimistically
    const tempMessage = {
        id: 'temp_' + Date.now(),
        conversation_id: currentConversationId,
        sender_id: <?php echo $user_id; ?>,
        message: message,
        created_at: new Date().toISOString(),
        sending: true
    };

    // Get current messages and add temp message
    const cachedMessages = JSON.parse(localStorage.getItem(`chat_messages_${currentConversationId}`) || '[]');
    cachedMessages.push(tempMessage);
    localStorage.setItem(`chat_messages_${currentConversationId}`, JSON.stringify(cachedMessages));
    displayMessages(cachedMessages);

    fetch('chat/send_message.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            conversation_id: currentConversationId,
            message: message
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Remove temp message and reload actual messages
            loadMessages(currentConversationId);
        } else {
            // Store offline and show error
            storeOfflineMessage(currentConversationId, message);
            showNotification('Error sending message: ' + data.message, 'error');
        }
    })
    .catch(error => {
        console.error('Error sending message:', error);

        // Store message offline if network fails
        if (!navigator.onLine) {
            storeOfflineMessage(currentConversationId, message);
        } else {
            showNotification('Failed to send message. Please try again.', 'error');
            // Restore message to input
            messageInput.value = message;
        }

        // Remove temp message from display
        loadMessages(currentConversationId);
    });
}

// Handle key press in message input
function handleKeyPress(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        sendMessage();
    }
}

// Handle typing indicator
function handleTyping() {
    if (!currentConversationId) return;

    // Clear existing timeout
    if (typingTimeout) {
        clearTimeout(typingTimeout);
    }

    // Send typing indicator
    fetch('chat/typing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            conversation_id: currentConversationId,
            is_typing: true
        })
    });

    // Stop typing after 3 seconds
    typingTimeout = setTimeout(() => {
        fetch('chat/typing.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                conversation_id: currentConversationId,
                is_typing: false
            })
        });
    }, 3000);
}

// Mark messages as read
function markMessagesAsRead(conversationId) {
    fetch('chat/mark_read.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            conversation_id: conversationId
        })
    });
}

// Close chat
function closeChat() {
    currentConversationId = null;
    document.getElementById('chatHeader').classList.add('hidden');
    document.getElementById('chatInput').classList.add('hidden');
    document.getElementById('chatMessages').innerHTML = `
        <div class="text-center py-8">
            <i class="fas fa-comments text-gray-400 text-4xl mb-4"></i>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Welcome to Live Support</h3>
            <p class="text-gray-500 dark:text-gray-400 mb-4">Select a conversation or start a new chat to get help</p>
        </div>
    `;

    if (messagePollingInterval) {
        clearInterval(messagePollingInterval);
    }

    // Remove active state from conversations
    document.querySelectorAll('.conversation-item').forEach(item => {
        item.classList.remove('bg-blue-50', 'dark:bg-blue-900');
    });
}

// Refresh conversations list with persistence
function refreshConversations() {
    // Only refresh if not in an active chat and modal is not open to avoid disruption
    const modalOpen = !document.getElementById('newChatModal').classList.contains('hidden');
    if (!currentConversationId && !modalOpen) {
        // Instead of full page reload, fetch conversations via AJAX to maintain state
        fetch('chat/get_conversations.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    updateConversationsList(data.conversations);
                } else {
                    console.error('Failed to refresh conversations:', data.message);
                }
            })
            .catch(error => {
                console.error('Error refreshing conversations:', error);
                // Fallback to page reload only if absolutely necessary
                setTimeout(() => window.location.reload(), 5000);
            });
    }
}

// Update conversations list without page reload
function updateConversationsList(conversations) {
    const conversationsList = document.querySelector('.conversation-item')?.parentElement;
    if (!conversationsList) return;

    // Store current scroll position
    const scrollPosition = conversationsList.scrollTop;

    // Update conversations while preserving current selection
    // This would need to be implemented based on your specific HTML structure
    console.log('Conversations updated:', conversations.length);

    // Restore scroll position
    conversationsList.scrollTop = scrollPosition;
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

function showNotification(message, type = 'info') {
    // Remove existing notifications
    const existingNotifications = document.querySelectorAll('.notification');
    existingNotifications.forEach(notification => notification.remove());

    // Create notification element
    const notification = document.createElement('div');
    notification.className = `notification fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;

    // Set colors based on type
    switch(type) {
        case 'success':
            notification.classList.add('bg-green-500', 'text-white');
            break;
        case 'error':
            notification.classList.add('bg-red-500', 'text-white');
            break;
        case 'warning':
            notification.classList.add('bg-yellow-500', 'text-white');
            break;
        default:
            notification.classList.add('bg-blue-500', 'text-white');
    }

    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'exclamation-triangle' : 'info'} mr-2"></i>
            <span>${message}</span>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);

    // Auto remove after 5 seconds
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentElement) {
                notification.remove();
            }
        }, 300);
    }, 5000);
}

// Sync offline messages when connection is restored
function syncOfflineMessages() {
    const offlineMessages = JSON.parse(localStorage.getItem('offline_messages') || '[]');

    if (offlineMessages.length > 0) {
        console.log('Syncing', offlineMessages.length, 'offline messages');

        offlineMessages.forEach((message, index) => {
            fetch('chat/send_message.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(message)
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Remove synced message from offline storage
                    const remaining = JSON.parse(localStorage.getItem('offline_messages') || '[]');
                    const updated = remaining.filter(m => m.timestamp !== message.timestamp);
                    localStorage.setItem('offline_messages', JSON.stringify(updated));

                    if (currentConversationId === message.conversation_id) {
                        loadMessages(currentConversationId);
                    }
                }
            })
            .catch(error => {
                console.error('Failed to sync offline message:', error);
            });
        });

        if (offlineMessages.length > 0) {
            showNotification(`Synced ${offlineMessages.length} offline messages`, 'success');
        }
    }
}

// Store message offline if network fails
function storeOfflineMessage(conversationId, message) {
    const offlineMessages = JSON.parse(localStorage.getItem('offline_messages') || '[]');
    offlineMessages.push({
        conversation_id: conversationId,
        message: message,
        timestamp: Date.now()
    });
    localStorage.setItem('offline_messages', JSON.stringify(offlineMessages));
    showNotification('Message saved offline. Will send when connection is restored.', 'warning');
}

// Add keyboard shortcuts for help
document.addEventListener('keydown', function(e) {
    // Alt + H for Help
    if (e.altKey && e.key === 'h') {
        e.preventDefault();
        window.location.href = '/school_ms/help.php';
    }

    // Escape to close chat
    if (e.key === 'Escape') {
        if (currentConversationId) {
            closeChat();
        } else {
            closeNewChatModal();
        }
    }
});
</script>
