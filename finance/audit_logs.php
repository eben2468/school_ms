<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Fetch audit trail
$query = "SELECT al.*, u.name as user_name, u.role as user_role
          FROM finance_audit_log al
          JOIN users u ON al.user_id = u.id
          ORDER BY al.id DESC LIMIT 100";
$logs = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Finance Audit Trail</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review security-critical financial updates, waiver approvals, and payments</p>
                    </div>
                </div>

                <!-- Logs List -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Time</th>
                                    <th class="p-4">Staff User</th>
                                    <th class="p-4">Module</th>
                                    <th class="p-4">Action</th>
                                    <th class="p-4">IP Address</th>
                                    <th class="p-4">Description/Details</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($logs as $l): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                    <td class="p-4 text-gray-500 font-medium"><?php echo date('M d, Y H:i:s', strtotime($l['created_at'])); ?></td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($l['user_name']); ?></div>
                                        <div class="text-xs text-gray-450 uppercase font-bold"><?php echo str_replace('_', ' ', $l['user_role']); ?></div>
                                    </td>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($l['module']); ?></td>
                                    <td class="p-4 font-bold text-blue-600 dark:text-blue-400"><?php echo htmlspecialchars($l['action']); ?></td>
                                    <td class="p-4 text-gray-500 font-mono"><?php echo htmlspecialchars($l['ip_address']); ?></td>
                                    <td class="p-4 text-gray-650 dark:text-gray-300 font-medium max-w-xs truncate"><?php echo htmlspecialchars($l['details']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (empty($logs)): ?>
                <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 mt-8">
                    <i class="fas fa-shield-alt text-gray-300 dark:text-gray-600 text-6xl mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-1">No audit trail entries recorded yet</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Perform financial actions, invoice runs, or payments to verify tracking log.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
