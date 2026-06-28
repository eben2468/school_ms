<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();
ensureChatTables($db); // heal tenants that predate the chat module

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Handle conversation assignment
if ($_POST && isset($_POST['assign_conversation'])) {
    $conversation_id = $_POST['conversation_id'];
    $agent_id = $_POST['agent_id'];
    
    try {
        $assign_query = "
            UPDATE chat_conversations 
            SET support_agent_id = :agent_id, status = 'in_progress', updated_at = NOW()
            WHERE id = :conversation_id
        ";
        $assign_stmt = $db->prepare($assign_query);
        $assign_stmt->bindParam(':agent_id', $agent_id);
        $assign_stmt->bindParam(':conversation_id', $conversation_id);
        $assign_stmt->execute();
        
        $success_message = "Conversation assigned successfully!";
    } catch (PDOException $e) {
        $error_message = "Failed to assign conversation: " . $e->getMessage();
    }
}

// Get all conversations
$conversations = [];
try {
    $conv_query = "
        SELECT cc.*, u.name as user_name, u.role as user_role,
               sa.name as support_agent_name,
               COUNT(cm.id) as message_count,
               MAX(cm.created_at) as last_message_time,
               (SELECT COUNT(*) FROM chat_messages WHERE conversation_id = cc.id AND sender_id != cc.support_agent_id AND is_read = FALSE) as unread_count
        FROM chat_conversations cc
        LEFT JOIN users u ON cc.user_id = u.id
        LEFT JOIN users sa ON cc.support_agent_id = sa.id
        LEFT JOIN chat_messages cm ON cc.id = cm.conversation_id
        GROUP BY cc.id
        ORDER BY cc.priority DESC, cc.updated_at DESC
    ";
    $conv_stmt = $db->prepare($conv_query);
    $conv_stmt->execute();
    $conversations = $conv_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conversations = [];
}

// Get available support agents
$agents = [];
try {
    $agents_query = "SELECT id, name FROM users WHERE role IN ('super_admin', 'school_admin', 'principal') ORDER BY name";
    $agents_stmt = $db->prepare($agents_query);
    $agents_stmt->execute();
    $agents = $agents_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $agents = [];
}

$title = "Chat Support Dashboard";
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
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Chat Support Dashboard</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Manage support conversations and assist users</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="../help.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>Back to Help
                            </a>
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
                    <?php
                    $total_conversations = count($conversations);
                    $open_conversations = count(array_filter($conversations, function($c) { return $c['status'] === 'open'; }));
                    $in_progress_conversations = count(array_filter($conversations, function($c) { return $c['status'] === 'in_progress'; }));
                    $unassigned_conversations = count(array_filter($conversations, function($c) { return $c['support_agent_id'] === null; }));
                    ?>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Conversations</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $total_conversations; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comments text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Open</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $open_conversations; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">In Progress</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo $in_progress_conversations; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-cog text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Unassigned</p>
                                <p class="text-3xl font-bold text-red-600 dark:text-red-400"><?php echo $unassigned_conversations; ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Conversations Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Support Conversations</h2>
                    </div>
                    
                    <?php if (empty($conversations)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-comments text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Conversations</h3>
                        <p class="text-gray-500 dark:text-gray-400">No support conversations have been started yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Priority</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Agent</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Messages</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Activity</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($conversations as $conv): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($conv['user_name']); ?></div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars(formatRoleName($conv['user_role'])); ?></div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 dark:text-white max-w-xs truncate"><?php echo htmlspecialchars($conv['subject']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($conv['priority']) {
                                                case 'urgent': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                case 'high': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'; break;
                                                case 'medium': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                case 'low': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($conv['priority']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($conv['status']) {
                                                case 'open': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'in_progress': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'resolved': echo 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'; break;
                                                case 'closed': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $conv['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo $conv['support_agent_name'] ? htmlspecialchars($conv['support_agent_name']) : 'Unassigned'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <span class="text-sm text-gray-900 dark:text-white"><?php echo $conv['message_count']; ?></span>
                                            <?php if ($conv['unread_count'] > 0): ?>
                                            <span class="ml-2 bg-red-500 text-white text-xs rounded-full px-2 py-1">
                                                <?php echo $conv['unread_count']; ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo $conv['last_message_time'] ? date('M j, g:i A', strtotime($conv['last_message_time'])) : date('M j, g:i A', strtotime($conv['created_at'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex space-x-2">
                                            <a href="../help.php?open_chat=<?php echo $conv['id']; ?>" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium">
                                                View
                                            </a>
                                            <?php if (!$conv['support_agent_id']): ?>
                                            <button onclick="assignConversation(<?php echo $conv['id']; ?>)" class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium">
                                                Assign
                                            </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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

<!-- Assignment Modal -->
<div id="assignModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Assign Conversation</h3>
                <form method="POST" class="space-y-4">
                    <input type="hidden" id="assignConversationId" name="conversation_id">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Support Agent</label>
                        <select name="agent_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select an agent</option>
                            <?php foreach ($agents as $agent): ?>
                            <option value="<?php echo $agent['id']; ?>"><?php echo htmlspecialchars($agent['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeAssignModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="assign_conversation" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Assign
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function assignConversation(conversationId) {
    document.getElementById('assignConversationId').value = conversationId;
    document.getElementById('assignModal').classList.remove('hidden');
}

function closeAssignModal() {
    document.getElementById('assignModal').classList.add('hidden');
}

// Auto-refresh every 30 seconds
setInterval(() => {
    window.location.reload();
}, 30000);
</script>
