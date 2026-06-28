<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle ticket actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_ticket') {
        $ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_SANITIZE_NUMBER_INT);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING);
        $assigned_to = filter_input(INPUT_POST, 'assigned_to', FILTER_SANITIZE_NUMBER_INT);
        $admin_notes = filter_input(INPUT_POST, 'admin_notes', FILTER_SANITIZE_STRING);

        if ($ticket_id) {
            try {
                $query = "UPDATE support_tickets SET status = :status, priority = :priority, assigned_to = :assigned_to, admin_notes = :admin_notes, updated_at = NOW() WHERE id = :ticket_id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':priority', $priority);
                $stmt->bindParam(':assigned_to', $assigned_to);
                $stmt->bindParam(':admin_notes', $admin_notes);
                $stmt->bindParam(':ticket_id', $ticket_id);
                
                if ($stmt->execute()) {
                    $success = "Ticket updated successfully!";
                } else {
                    $error = "Error updating ticket.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }

    if ($_POST['action'] === 'add_response') {
        $ticket_id = filter_input(INPUT_POST, 'ticket_id', FILTER_SANITIZE_NUMBER_INT);
        $response = filter_input(INPUT_POST, 'response', FILTER_SANITIZE_STRING);

        if ($ticket_id && $response) {
            try {
                $query = "INSERT INTO ticket_responses (ticket_id, user_id, response, is_admin_response, created_at) VALUES (:ticket_id, :user_id, :response, 1, NOW())";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':ticket_id', $ticket_id);
                $stmt->bindParam(':user_id', $_SESSION['user_id']);
                $stmt->bindParam(':response', $response);
                
                if ($stmt->execute()) {
                    // Update ticket status to in_progress if it was open
                    $update_query = "UPDATE support_tickets SET status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END, updated_at = NOW() WHERE id = :ticket_id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':ticket_id', $ticket_id);
                    $update_stmt->execute();
                    
                    $success = "Response added successfully!";
                } else {
                    $error = "Error adding response.";
                }
            } catch (PDOException $e) {
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Get filter parameters
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: 'all';
$priority_filter = filter_input(INPUT_GET, 'priority', FILTER_SANITIZE_STRING) ?: 'all';
$assigned_filter = filter_input(INPUT_GET, 'assigned', FILTER_SANITIZE_STRING) ?: 'all';
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';

// Build query conditions
$where_conditions = ["1=1"];
$params = [];

if ($status_filter !== 'all') {
    $where_conditions[] = "st.status = :status";
    $params[':status'] = $status_filter;
}

if ($priority_filter !== 'all') {
    $where_conditions[] = "st.priority = :priority";
    $params[':priority'] = $priority_filter;
}

if ($assigned_filter === 'unassigned') {
    $where_conditions[] = "st.assigned_to IS NULL";
} elseif ($assigned_filter === 'assigned') {
    $where_conditions[] = "st.assigned_to IS NOT NULL";
}

if ($search) {
    $where_conditions[] = "(st.subject LIKE :search OR st.description LIKE :search OR u.name LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = implode(' AND ', $where_conditions);

// Get tickets with pagination
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$tickets_query = "SELECT st.*, u.name as user_name, u.email as user_email, u.role as user_role,
                         admin.name as assigned_to_name,
                         COUNT(tr.id) as response_count
                  FROM support_tickets st
                  LEFT JOIN users u ON st.user_id = u.id
                  LEFT JOIN users admin ON st.assigned_to = admin.id
                  LEFT JOIN ticket_responses tr ON st.id = tr.ticket_id
                  WHERE $where_clause
                  GROUP BY st.id, u.name, u.email, u.role, admin.name
                  ORDER BY st.created_at DESC
                  LIMIT :offset, :per_page";

$stmt = $db->prepare($tickets_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$stmt->execute();
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total count for pagination
$count_query = "SELECT COUNT(*) as total FROM support_tickets st LEFT JOIN users u ON st.user_id = u.id WHERE $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_tickets = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_tickets / $per_page);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN status = 'open' THEN 1 END) as open,
    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved,
    COUNT(CASE WHEN priority = 'urgent' THEN 1 END) as urgent,
    COUNT(CASE WHEN assigned_to IS NULL THEN 1 END) as unassigned
    FROM support_tickets";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get admin users for assignment
$admin_query = "SELECT id, name FROM users WHERE role IN ('super_admin', 'school_admin', 'principal') ORDER BY name";
$admin_stmt = $db->prepare($admin_query);
$admin_stmt->execute();
$admin_users = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Support Ticket Management";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-xl p-6 mb-8 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-headset mr-3"></i>
                                Support Ticket Management
                            </h1>
                            <p class="text-red-100">Manage and respond to user support tickets</p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold"><?= number_format($stats['unassigned']) ?></div>
                            <div class="text-sm text-red-100">Unassigned Tickets</div>
                        </div>
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

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                                <i class="fas fa-ticket-alt text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Tickets</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['total']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Open</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['open']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-orange-100 text-orange-600">
                                <i class="fas fa-exclamation-triangle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Urgent</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['urgent']) ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100 text-green-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Resolved</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?= number_format($stats['resolved']) ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters and Search -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div class="flex-1 min-w-64">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search</label>
                            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                                   placeholder="Search tickets..."
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select name="status" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                                <option value="open" <?= $status_filter === 'open' ? 'selected' : '' ?>>Open</option>
                                <option value="in_progress" <?= $status_filter === 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                                <option value="resolved" <?= $status_filter === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                <option value="closed" <?= $status_filter === 'closed' ? 'selected' : '' ?>>Closed</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority</label>
                            <select name="priority" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="all" <?= $priority_filter === 'all' ? 'selected' : '' ?>>All Priority</option>
                                <option value="low" <?= $priority_filter === 'low' ? 'selected' : '' ?>>Low</option>
                                <option value="medium" <?= $priority_filter === 'medium' ? 'selected' : '' ?>>Medium</option>
                                <option value="high" <?= $priority_filter === 'high' ? 'selected' : '' ?>>High</option>
                                <option value="urgent" <?= $priority_filter === 'urgent' ? 'selected' : '' ?>>Urgent</option>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assignment</label>
                            <select name="assigned" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="all" <?= $assigned_filter === 'all' ? 'selected' : '' ?>>All Tickets</option>
                                <option value="unassigned" <?= $assigned_filter === 'unassigned' ? 'selected' : '' ?>>Unassigned</option>
                                <option value="assigned" <?= $assigned_filter === 'assigned' ? 'selected' : '' ?>>Assigned</option>
                            </select>
                        </div>

                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                            <i class="fas fa-search mr-2"></i>Filter
                        </button>
                    </form>
                </div>

                <!-- Tickets List -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Support Tickets</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage and respond to user support requests</p>
                    </div>

                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php if (!empty($tickets)): ?>
                            <?php foreach ($tickets as $ticket): ?>
                            <div class="p-6 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-3 mb-3">
                                            <div class="w-10 h-10 bg-red-500 rounded-full flex items-center justify-center text-white font-semibold">
                                                <?= strtoupper(substr($ticket['user_name'], 0, 1)) ?>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($ticket['user_name']) ?></h3>
                                                <p class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($ticket['user_email']) ?> • <?= htmlspecialchars(formatRoleName($ticket['user_role'])) ?></p>
                                            </div>
                                        </div>

                                        <div class="mb-3">
                                            <h4 class="font-medium text-gray-900 dark:text-white mb-2"><?= htmlspecialchars($ticket['subject']) ?></h4>
                                            <p class="text-gray-700 dark:text-gray-300"><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
                                        </div>

                                        <?php if ($ticket['admin_notes']): ?>
                                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-3">
                                            <div class="flex items-center space-x-2 mb-2">
                                                <i class="fas fa-sticky-note text-blue-600"></i>
                                                <span class="font-medium text-blue-900 dark:text-blue-100">Admin Notes</span>
                                            </div>
                                            <p class="text-blue-800 dark:text-blue-200"><?= nl2br(htmlspecialchars($ticket['admin_notes'])) ?></p>
                                        </div>
                                        <?php endif; ?>

                                        <div class="flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
                                            <div class="flex items-center space-x-4">
                                                <span><?= date('M j, Y g:i A', strtotime($ticket['created_at'])) ?></span>
                                                <?php if ($ticket['response_count'] > 0): ?>
                                                <span><i class="fas fa-comments mr-1"></i><?= $ticket['response_count'] ?> responses</span>
                                                <?php endif; ?>
                                                <?php if ($ticket['assigned_to_name']): ?>
                                                <span><i class="fas fa-user mr-1"></i>Assigned to <?= htmlspecialchars($ticket['assigned_to_name']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                            <span>Updated: <?= date('M j, Y g:i A', strtotime($ticket['updated_at'])) ?></span>
                                        </div>
                                    </div>

                                    <div class="ml-6 flex flex-col items-end space-y-2">
                                        <div class="flex space-x-2">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                <?php
                                                switch($ticket['priority']) {
                                                    case 'low': echo 'bg-gray-100 text-gray-800'; break;
                                                    case 'medium': echo 'bg-blue-100 text-blue-800'; break;
                                                    case 'high': echo 'bg-yellow-100 text-yellow-800'; break;
                                                    case 'urgent': echo 'bg-red-100 text-red-800'; break;
                                                }
                                                ?>">
                                                <?= ucfirst($ticket['priority']) ?>
                                            </span>

                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                                <?php
                                                switch($ticket['status']) {
                                                    case 'open': echo 'bg-green-100 text-green-800'; break;
                                                    case 'in_progress': echo 'bg-blue-100 text-blue-800'; break;
                                                    case 'resolved': echo 'bg-purple-100 text-purple-800'; break;
                                                    case 'closed': echo 'bg-gray-100 text-gray-800'; break;
                                                }
                                                ?>">
                                                <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                            </span>
                                        </div>

                                        <div class="flex space-x-2">
                                            <button onclick="openTicketModal(<?= $ticket['id'] ?>, '<?= htmlspecialchars($ticket['subject'], ENT_QUOTES) ?>', '<?= $ticket['status'] ?>', '<?= $ticket['priority'] ?>', '<?= $ticket['assigned_to'] ?>', '<?= htmlspecialchars($ticket['admin_notes'] ?? '', ENT_QUOTES) ?>')"
                                                    class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-sm">
                                                <i class="fas fa-edit mr-1"></i>Manage
                                            </button>
                                            <button onclick="openResponseModal(<?= $ticket['id'] ?>, '<?= htmlspecialchars($ticket['subject'], ENT_QUOTES) ?>')"
                                                    class="bg-green-600 hover:bg-green-700 text-white px-3 py-1 rounded text-sm">
                                                <i class="fas fa-reply mr-1"></i>Respond
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="p-12 text-center">
                                <i class="fas fa-ticket-alt text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Tickets Found</h3>
                                <p class="text-gray-500 dark:text-gray-400">No support tickets match your current filters.</p>
                            </div>
                        <?php endif; ?>
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

<!-- Ticket Management Modal -->
<div id="ticketModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white" id="ticketModalTitle">Manage Ticket</h3>
                <button onclick="closeTicketModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="ticketForm">
                <input type="hidden" name="action" value="update_ticket">
                <input type="hidden" name="ticket_id" id="ticketId">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                        <select name="status" id="ticketStatus" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Priority</label>
                        <select name="priority" id="ticketPriority" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="low">Low</option>
                            <option value="medium">Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assign To</label>
                    <select name="assigned_to" id="ticketAssignedTo"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="">Unassigned</option>
                        <?php foreach ($admin_users as $admin): ?>
                        <option value="<?= $admin['id'] ?>"><?= htmlspecialchars($admin['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Admin Notes</label>
                    <textarea name="admin_notes" id="ticketAdminNotes" rows="4"
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                              placeholder="Internal notes for this ticket..."></textarea>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeTicketModal()"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-md text-sm font-medium">
                        <i class="fas fa-save mr-2"></i>Update Ticket
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Response Modal -->
<div id="responseModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full hidden z-50">
    <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-3/4 lg:w-1/2 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="mt-3">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white" id="responseModalTitle">Add Response</h3>
                <button onclick="closeResponseModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>

            <form method="POST" id="responseForm">
                <input type="hidden" name="action" value="add_response">
                <input type="hidden" name="ticket_id" id="responseTicketId">

                <div class="mb-6">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Response</label>
                    <textarea name="response" id="responseText" rows="6" required
                              class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                              placeholder="Write your response to the user..."></textarea>
                </div>

                <div class="flex justify-end space-x-3">
                    <button type="button" onclick="closeResponseModal()"
                            class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                        Cancel
                    </button>
                    <button type="submit"
                            class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-md text-sm font-medium">
                        <i class="fas fa-paper-plane mr-2"></i>Send Response
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openTicketModal(ticketId, subject, status, priority, assignedTo, adminNotes) {
    document.getElementById('ticketId').value = ticketId;
    document.getElementById('ticketStatus').value = status;
    document.getElementById('ticketPriority').value = priority;
    document.getElementById('ticketAssignedTo').value = assignedTo || '';
    document.getElementById('ticketAdminNotes').value = adminNotes || '';
    document.getElementById('ticketModalTitle').textContent = 'Manage Ticket: ' + subject;
    document.getElementById('ticketModal').classList.remove('hidden');
}

function closeTicketModal() {
    document.getElementById('ticketModal').classList.add('hidden');
    document.getElementById('ticketForm').reset();
}

function openResponseModal(ticketId, subject) {
    document.getElementById('responseTicketId').value = ticketId;
    document.getElementById('responseText').value = '';
    document.getElementById('responseModalTitle').textContent = 'Respond to: ' + subject;
    document.getElementById('responseModal').classList.remove('hidden');
    document.getElementById('responseText').focus();
}

function closeResponseModal() {
    document.getElementById('responseModal').classList.add('hidden');
    document.getElementById('responseForm').reset();
}

// Close modals when clicking outside
document.getElementById('ticketModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeTicketModal();
    }
});

document.getElementById('responseModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeResponseModal();
    }
});

// Close modals with Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeTicketModal();
        closeResponseModal();
    }
});
</script>
