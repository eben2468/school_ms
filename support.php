<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

// Self-heal: ensure the support_tickets table exists in this tenant DB
// (older tenant databases were provisioned without it).
try {
    $db->exec("CREATE TABLE IF NOT EXISTS support_tickets (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('low','medium','high','urgent') DEFAULT 'medium',
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        assigned_to INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_support_user (user_id)
    )");
} catch (PDOException $e) {
    error_log("support_tickets ensure failed: " . $e->getMessage());
}

// Redirect admins to management page if they want to manage tickets
if (isset($_GET['manage']) && in_array($user_role, ['super_admin', 'school_admin', 'principal'])) {
    header("Location: admin/support_management.php");
    exit();
}

// Handle ticket submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ticket'])) {
    $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $priority = filter_input(INPUT_POST, 'priority', FILTER_SANITIZE_STRING);

    if ($subject && $description && $priority) {
        try {
            $query = "INSERT INTO support_tickets (user_id, subject, description, priority) VALUES (:user_id, :subject, :description, :priority)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':subject', $subject);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':priority', $priority);

            if ($stmt->execute()) {
                $success_message = "Support ticket submitted successfully! We'll get back to you soon.";
            } else {
                $error_message = "Error submitting ticket. Please try again.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get user's tickets
$tickets_query = "SELECT st.*, u.name as assigned_to_name 
                  FROM support_tickets st 
                  LEFT JOIN users u ON st.assigned_to = u.id 
                  WHERE st.user_id = :user_id 
                  ORDER BY st.created_at DESC";
$user_tickets = [];
$stats = ['total_tickets' => 0, 'open_tickets' => 0, 'in_progress_tickets' => 0, 'resolved_tickets' => 0];
try {
    $tickets_stmt = $db->prepare($tickets_query);
    $tickets_stmt->bindParam(':user_id', $user_id);
    $tickets_stmt->execute();
    $user_tickets = $tickets_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get ticket statistics
    $stats_query = "SELECT
        COUNT(*) as total_tickets,
        COUNT(CASE WHEN status = 'open' THEN 1 END) as open_tickets,
        COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress_tickets,
        COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_tickets
        FROM support_tickets WHERE user_id = :user_id";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':user_id', $user_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC) ?: $stats;
} catch (PDOException $e) {
    error_log("support tickets query failed: " . $e->getMessage());
}

$title = "Support Center";
include 'includes/header.php';
include 'includes/sidebar.php';
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
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Support Center</h1>
                                <p class="text-blue-100 text-lg">Get help and submit support tickets</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-ticket-alt mr-2"></i>
                                        <?php echo number_format($stats['total_tickets']); ?> Total Tickets
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo number_format($stats['open_tickets']); ?> Open
                                    </div>
                                </div>

                                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                                <div class="mt-4">
                                    <a href="admin/support_management.php"
                                       class="inline-flex items-center px-4 py-2 bg-white/20 hover:bg-white/30 rounded-lg text-sm font-medium transition-colors duration-200 backdrop-blur-sm">
                                        <i class="fas fa-cogs mr-2"></i>
                                        Manage All Tickets
                                    </a>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-headset text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Tickets</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_tickets']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-ticket-alt text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Open</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['open_tickets']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">In Progress</p>
                                <p class="text-3xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo number_format($stats['in_progress_tickets']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-cog text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Resolved</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['resolved_tickets']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Submit New Ticket -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Submit New Ticket</h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Describe your issue and we'll help you resolve it</p>
                        </div>

                        <form method="POST" class="p-6 space-y-6">
                            <!-- Subject -->
                            <div>
                                <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Subject <span class="text-red-500">*</span>
                                </label>
                                <input type="text" id="subject" name="subject" required
                                    placeholder="Brief description of your issue"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Priority -->
                            <div>
                                <label for="priority" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Priority <span class="text-red-500">*</span>
                                </label>
                                <select id="priority" name="priority" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select priority</option>
                                    <option value="low">Low - General inquiry</option>
                                    <option value="medium">Medium - Non-urgent issue</option>
                                    <option value="high">High - Urgent issue</option>
                                    <option value="urgent">Urgent - Critical issue</option>
                                </select>
                            </div>

                            <!-- Description -->
                            <div>
                                <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Description <span class="text-red-500">*</span>
                                </label>
                                <textarea id="description" name="description" rows="5" required
                                    placeholder="Please provide detailed information about your issue..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                            </div>

                            <!-- Submit Button -->
                            <button type="submit" name="submit_ticket"
                                class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-3 px-4 rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Submit Ticket
                            </button>
                        </form>
                    </div>

                    <!-- My Tickets -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">My Tickets</h2>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Track the status of your support requests</p>
                        </div>

                        <div class="p-6">
                            <?php if (!empty($user_tickets)): ?>
                            <div class="space-y-4 max-h-96 overflow-y-auto">
                                <?php foreach ($user_tickets as $ticket): ?>
                                <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <div class="flex items-start justify-between">
                                        <div class="flex-1">
                                            <h3 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($ticket['subject']); ?></h3>
                                            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1"><?php echo htmlspecialchars(substr($ticket['description'], 0, 100)) . (strlen($ticket['description']) > 100 ? '...' : ''); ?></p>
                                            <div class="flex items-center space-x-4 mt-2 text-xs text-gray-500 dark:text-gray-400">
                                                <span><i class="fas fa-calendar mr-1"></i><?php echo date('M j, Y', strtotime($ticket['created_at'])); ?></span>
                                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                                    <?php 
                                                    switch($ticket['priority']) {
                                                        case 'low': echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; break;
                                                        case 'medium': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                        case 'high': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'; break;
                                                        case 'urgent': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                    }
                                                    ?>">
                                                    <?php echo ucfirst($ticket['priority']); ?>
                                                </span>
                                            </div>
                                        </div>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ml-4
                                            <?php 
                                            switch($ticket['status']) {
                                                case 'open': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'; break;
                                                case 'in_progress': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'resolved': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'closed': echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $ticket['status'])); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-8">
                                <div class="w-16 h-16 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                                    <i class="fas fa-ticket-alt text-gray-400 text-2xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No tickets yet</h3>
                                <p class="text-gray-500 dark:text-gray-400">Submit your first support ticket to get help</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- FAQ Section -->
                <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Frequently Asked Questions</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Common questions and answers</p>
                    </div>

                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                                <h3 class="font-medium text-gray-900 dark:text-white mb-2">How do I reset my password?</h3>
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Contact your system administrator or submit a support ticket with your request.</p>
                            </div>
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                                <h3 class="font-medium text-gray-900 dark:text-white mb-2">How can I update my profile information?</h3>
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Go to your profile page and click the edit button to update your information.</p>
                            </div>
                            <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
                                <h3 class="font-medium text-gray-900 dark:text-white mb-2">Who can I contact for technical issues?</h3>
                                <p class="text-gray-600 dark:text-gray-400 text-sm">Submit a support ticket with priority "High" or "Urgent" for technical issues that need immediate attention.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Auto-focus on subject field
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('subject').focus();
});
</script>
