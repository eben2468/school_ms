<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Available tabs: inbox, sent, email_logs, sms_logs
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'inbox';
$allowed_tabs = ['inbox', 'sent'];

// Staff roles can also view broadcast logs
$is_staff = in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher']);
if ($is_staff) {
    $allowed_tabs[] = 'email_logs';
    $allowed_tabs[] = 'sms_logs';
}

if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'inbox';
}

$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING);

// Fetch data based on active tab
$inbox_messages = [];
$sent_messages = [];
$email_logs = [];
$sms_logs = [];

try {
    if ($active_tab === 'inbox') {
        $search_cond = $search ? "AND (m.subject LIKE :search OR m.content LIKE :search OR u.name LIKE :search)" : "";
        $query = "SELECT m.*, u.name as sender_name, u.email as sender_email, u.role as sender_role
                  FROM messages m 
                  JOIN users u ON m.sender_id = u.id 
                  WHERE m.recipient_id = :user_id $search_cond
                  ORDER BY m.sent_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        if ($search) {
            $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        }
        $stmt->execute();
        $inbox_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    
    elseif ($active_tab === 'sent') {
        $search_cond = $search ? "AND (m.subject LIKE :search OR m.content LIKE :search OR u.name LIKE :search)" : "";
        $query = "SELECT m.*, u.name as recipient_name, u.email as recipient_email, u.role as recipient_role
                  FROM messages m 
                  JOIN users u ON m.recipient_id = u.id 
                  WHERE m.sender_id = :user_id $search_cond
                  ORDER BY m.sent_at DESC";
        $stmt = $db->prepare($query);
        $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
        if ($search) {
            $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        }
        $stmt->execute();
        $sent_messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    
    elseif ($active_tab === 'email_logs' && $is_staff) {
        $search_cond = $search ? "WHERE (recipients LIKE :search OR subject LIKE :search OR message LIKE :search)" : "";
        $query = "SELECT * FROM email_logs $search_cond ORDER BY created_at DESC LIMIT 100";
        $stmt = $db->prepare($query);
        if ($search) {
            $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        }
        $stmt->execute();
        $email_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 
    
    elseif ($active_tab === 'sms_logs' && $is_staff) {
        $search_cond = $search ? "WHERE (recipients LIKE :search OR message LIKE :search)" : "";
        $query = "SELECT * FROM sms_logs $search_cond ORDER BY created_at DESC LIMIT 100";
        $stmt = $db->prepare($query);
        if ($search) {
            $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
        }
        $stmt->execute();
        $sms_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Database error in message center index: " . $e->getMessage());
}

$title = "Messages Hub";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Communication', 'url' => '../index.php'],
    ['title' => 'Message Center']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-2xl p-8 text-white shadow-xl flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Message Center</h1>
                            <p class="text-blue-100 text-lg">Read, compose, and monitor school communication channels</p>
                        </div>
                        <?php if ($is_staff): ?>
                        <a href="compose.php" class="bg-white hover:bg-gray-100 text-indigo-700 font-bold px-6 py-3 rounded-xl shadow-lg transition-transform duration-150 hover:-translate-y-0.5 flex items-center">
                            <i class="fas fa-edit mr-2"></i>Compose Broadcast
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tabs & Search Toolbar -->
                <div class="mb-6 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <!-- Navigation Tabs -->
                    <div class="border-b border-gray-200 dark:border-gray-700 flex overflow-x-auto whitespace-nowrap">
                        <a href="?tab=inbox" class="px-5 py-3 border-b-2 font-medium text-sm transition-colors duration-150 flex items-center <?php echo $active_tab === 'inbox' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white'; ?>">
                            <i class="fas fa-inbox mr-2"></i>Inbox
                        </a>
                        <a href="?tab=sent" class="px-5 py-3 border-b-2 font-medium text-sm transition-colors duration-150 flex items-center <?php echo $active_tab === 'sent' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white'; ?>">
                            <i class="fas fa-paper-plane mr-2"></i>Sent Messages
                        </a>
                        <?php if ($is_staff): ?>
                        <a href="?tab=email_logs" class="px-5 py-3 border-b-2 font-medium text-sm transition-colors duration-150 flex items-center <?php echo $active_tab === 'email_logs' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white'; ?>">
                            <i class="fas fa-envelope mr-2"></i>Email Logs
                        </a>
                        <a href="?tab=sms_logs" class="px-5 py-3 border-b-2 font-medium text-sm transition-colors duration-150 flex items-center <?php echo $active_tab === 'sms_logs' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400 font-bold' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-white'; ?>">
                            <i class="fas fa-sms mr-2"></i>SMS Logs
                        </a>
                        <?php endif; ?>
                    </div>

                    <!-- Search Input -->
                    <form method="GET" class="relative max-w-sm w-full">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search ?? ''); ?>"
                            placeholder="Search messages/logs..."
                            class="w-full pl-10 pr-4 py-2 border border-gray-300 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 text-gray-800 dark:text-white text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-search absolute left-3.5 top-1/2 transform -translate-y-1/2 text-gray-400 text-sm"></i>
                    </form>
                </div>

                <!-- Tab Panels -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-200 dark:border-gray-700 overflow-hidden">
                    
                    <!-- TAB 1: INBOX -->
                    <?php if ($active_tab === 'inbox'): ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!empty($inbox_messages)): ?>
                                <?php foreach ($inbox_messages as $msg): ?>
                                <div class="p-5 hover:bg-gray-50 dark:hover:bg-gray-900/40 cursor-pointer flex items-start gap-4 transition-colors duration-100 <?php echo !$msg['is_read'] ? 'bg-blue-50/40 dark:bg-blue-950/10' : ''; ?>"
                                    onclick="openMessageDetail(<?php echo $msg['id']; ?>, 'inbox')">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center text-blue-600 dark:text-blue-400 flex-shrink-0">
                                        <i class="fas fa-user"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between gap-2 mb-1">
                                            <span class="font-bold text-gray-900 dark:text-white text-sm truncate">
                                                <?php echo htmlspecialchars($msg['sender_name']); ?>
                                                <span class="text-xs font-normal text-gray-400 ml-1">(<?php echo htmlspecialchars(formatRoleName($msg['sender_role'])); ?>)</span>
                                            </span>
                                            <span class="text-xs text-gray-400 whitespace-nowrap"><?php echo date('M d, Y h:i A', strtotime($msg['sent_at'])); ?></span>
                                        </div>
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate mb-1 <?php echo !$msg['is_read'] ? 'font-black' : ''; ?>">
                                            <?php if (!$msg['is_read']): ?>
                                                <span class="inline-block w-2.5 h-2.5 bg-blue-600 rounded-full mr-2"></span>
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($msg['subject']); ?>
                                        </h4>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2"><?php echo htmlspecialchars($msg['content']); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-20 text-gray-400">
                                    <i class="fas fa-inbox text-5xl mb-4"></i>
                                    <p class="text-sm">Your Inbox is empty.</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    
                    <!-- TAB 2: SENT MESSAGES -->
                    <?php elseif ($active_tab === 'sent'): ?>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700">
                            <?php if (!empty($sent_messages)): ?>
                                <?php foreach ($sent_messages as $msg): ?>
                                <div class="p-5 hover:bg-gray-50 dark:hover:bg-gray-900/40 cursor-pointer flex items-start gap-4 transition-colors duration-100"
                                    onclick="openMessageDetail(<?php echo $msg['id']; ?>, 'sent')">
                                    <div class="w-10 h-10 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 dark:text-indigo-400 flex-shrink-0">
                                        <i class="fas fa-paper-plane"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center justify-between gap-2 mb-1">
                                            <span class="font-bold text-gray-900 dark:text-white text-sm truncate">
                                                To: <?php echo htmlspecialchars($msg['recipient_name']); ?>
                                                <span class="text-xs font-normal text-gray-400 ml-1">(<?php echo htmlspecialchars(formatRoleName($msg['recipient_role'])); ?>)</span>
                                            </span>
                                            <span class="text-xs text-gray-400 whitespace-nowrap"><?php echo date('M d, Y h:i A', strtotime($msg['sent_at'])); ?></span>
                                        </div>
                                        <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate mb-1">
                                            <?php echo htmlspecialchars($msg['subject']); ?>
                                        </h4>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2"><?php echo htmlspecialchars($msg['content']); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="text-center py-20 text-gray-400">
                                    <i class="fas fa-paper-plane text-5xl mb-4"></i>
                                    <p class="text-sm">No sent messages found.</p>
                                </div>
                            <?php endif; ?>
                        </div>

                    <!-- TAB 3: EMAIL LOGS -->
                    <?php elseif ($active_tab === 'email_logs' && $is_staff): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 text-gray-500 uppercase text-xs">
                                    <tr>
                                        <th class="px-6 py-4">Time</th>
                                        <th class="px-6 py-4">Recipient Email</th>
                                        <th class="px-6 py-4">Subject</th>
                                        <th class="px-6 py-4">Status</th>
                                        <th class="px-6 py-4">Diagnostics / Fallback Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-gray-700 dark:text-gray-300">
                                    <?php if (!empty($email_logs)): ?>
                                        <?php foreach ($email_logs as $log): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                            <td class="px-6 py-4 text-xs whitespace-nowrap"><?php echo date('M d, h:i A', strtotime($log['created_at'])); ?></td>
                                            <td class="px-6 py-4 font-semibold"><?php echo htmlspecialchars($log['recipients']); ?></td>
                                            <td class="px-6 py-4 truncate max-w-xs"><?php echo htmlspecialchars($log['subject']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded-full">Sent</span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded-full">Failed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-6 py-4 text-xs max-w-sm truncate text-gray-400"><?php echo htmlspecialchars($log['error_message'] ?? 'None'); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-20 text-gray-400">No email delivery logs available.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                    <!-- TAB 4: SMS LOGS -->
                    <?php elseif ($active_tab === 'sms_logs' && $is_staff): ?>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left">
                                <thead class="bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 text-gray-500 uppercase text-xs">
                                    <tr>
                                        <th class="px-6 py-4">Time</th>
                                        <th class="px-6 py-4">Recipient Phone</th>
                                        <th class="px-6 py-4">Message</th>
                                        <th class="px-6 py-4">Gateway</th>
                                        <th class="px-6 py-4">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-gray-700 dark:text-gray-300">
                                    <?php if (!empty($sms_logs)): ?>
                                        <?php foreach ($sms_logs as $log): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900/40">
                                            <td class="px-6 py-4 text-xs whitespace-nowrap"><?php echo date('M d, h:i A', strtotime($log['created_at'])); ?></td>
                                            <td class="px-6 py-4 font-semibold"><?php echo htmlspecialchars($log['recipients']); ?></td>
                                            <td class="px-6 py-4 max-w-sm truncate"><?php echo htmlspecialchars($log['message']); ?></td>
                                            <td class="px-6 py-4 capitalize whitespace-nowrap"><?php echo htmlspecialchars($log['gateway']); ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 rounded-full">Sent</span>
                                                <?php else: ?>
                                                    <span class="px-2.5 py-1 text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 rounded-full">Failed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center py-20 text-gray-400">No SMS delivery logs available.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <?php include '../../includes/footer.php'; ?>
    </div>
</div>

<!-- Glassmorphism Modal details view -->
<div id="msgModal" class="fixed inset-0 bg-gray-900/50 dark:bg-black/70 overflow-y-auto h-full w-full hidden z-50 flex items-center justify-center backdrop-blur-sm">
    <div class="relative bg-white dark:bg-gray-800 w-11/12 md:w-3/4 lg:w-1/2 rounded-2xl shadow-2xl overflow-hidden border border-gray-200 dark:border-gray-700 transform scale-95 opacity-0 transition-all duration-300" id="msgModalCard">
        <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center bg-gray-50 dark:bg-gray-900/30">
            <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white" id="modal-title">Subject Line</h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1" id="modal-meta">Sender name & date</p>
            </div>
            <button onclick="closeMessageDetail()" class="text-gray-400 hover:text-gray-600 dark:hover:text-white p-2 rounded-lg transition-colors">
                <i class="fas fa-times text-lg"></i>
            </button>
        </div>
        
        <div class="p-6 overflow-y-auto max-h-96">
            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap leading-relaxed" id="modal-content">Message content body</p>
        </div>
        
        <div class="p-6 border-t border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/30 flex justify-end gap-3">
            <button onclick="deleteMessage()" class="px-4 py-2 border border-red-200 text-red-500 rounded-lg text-sm font-semibold hover:bg-red-50 dark:hover:bg-red-950/20 transition-colors">
                <i class="fas fa-trash mr-1"></i> Delete
            </button>
            <button onclick="closeMessageDetail()" class="px-4 py-2 bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded-lg text-sm font-semibold hover:bg-gray-300 dark:hover:bg-gray-600 transition-colors">
                Close
            </button>
            <a id="modal-reply-btn" href="#" class="px-6 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition-colors flex items-center">
                <i class="fas fa-reply mr-1"></i> Reply
            </a>
        </div>
    </div>
</div>

<script>
let currentOpenMessageId = null;
let currentMessageType = null; // inbox, sent

function openMessageDetail(id, type) {
    currentOpenMessageId = id;
    currentMessageType = type;
    
    // Fetch details via API
    fetch(`message_api.php?action=get&id=${id}&type=${type}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const modal = document.getElementById('msgModal');
                const card = document.getElementById('msgModalCard');
                
                document.getElementById('modal-title').textContent = data.message.subject;
                
                const dateStr = new Date(data.message.sent_at).toLocaleString();
                if (type === 'inbox') {
                    document.getElementById('modal-meta').innerHTML = `<i class="fas fa-user-circle mr-1 text-gray-400"></i> From: <b>${escapeHtml(data.message.sender_name)}</b> (${escapeHtml(data.message.sender_email)}) | ${dateStr}`;
                    document.getElementById('modal-reply-btn').style.display = 'inline-flex';
                    // Reply link goes to compose page pre-selecting this sender as individual
                    document.getElementById('modal-reply-btn').href = `compose.php?reply_to=${data.message.sender_id}`;
                } else {
                    document.getElementById('modal-meta').innerHTML = `<i class="fas fa-user-circle mr-1 text-gray-400"></i> To: <b>${escapeHtml(data.message.recipient_name)}</b> (${escapeHtml(data.message.recipient_email)}) | ${dateStr}`;
                    document.getElementById('modal-reply-btn').style.display = 'none';
                }
                
                document.getElementById('modal-content').textContent = data.message.content;
                
                // Show modal with animation
                modal.classList.remove('hidden');
                setTimeout(() => {
                    card.classList.remove('scale-95', 'opacity-0');
                    card.classList.add('scale-100', 'opacity-100');
                }, 10);
                
                // If it was unread in Inbox, reload list after short delay to update count
                if (type === 'inbox' && !data.message.is_read) {
                    markAsRead(id);
                }
            } else {
                alert("Error fetching message details: " + data.error);
            }
        });
}

function closeMessageDetail() {
    const modal = document.getElementById('msgModal');
    const card = document.getElementById('msgModalCard');
    
    card.classList.remove('scale-100', 'opacity-100');
    card.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        modal.classList.add('hidden');
    }, 300);
}

function markAsRead(id) {
    fetch(`message_api.php?action=read&id=${id}`, { method: 'POST' })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Optionally reload dashboard view to clear red dot or update sidebar unread counts
            }
        });
}

function deleteMessage() {
    if (confirm("Are you sure you want to delete this message?")) {
        fetch(`message_api.php?action=delete&id=${currentOpenMessageId}&type=${currentMessageType}`, { method: 'POST' })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeMessageDetail();
                    // Reload window to reflect list change
                    window.location.reload();
                } else {
                    alert("Failed to delete message: " + data.error);
                }
            });
    }
}

function escapeHtml(text) {
    return text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
</script>
