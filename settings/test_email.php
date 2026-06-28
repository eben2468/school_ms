<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/email_helper.php';

$database = new Database();
$db = $database->getConnection();

$result = null;
$error = null;
$smtp_log = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'])) {
    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $subject = filter_input(INPUT_POST, 'subject', FILTER_SANITIZE_STRING);
    $message = $_POST['message'] ?? '';
    
    if (empty($email) || empty($subject) || empty($message)) {
        $error = "Recipient email, subject, and message are required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid recipient email address format";
    } else {
        // Fetch current school settings
        $settings_query = "SELECT * FROM school_settings LIMIT 1";
        $settings_stmt = $db->query($settings_query);
        $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            $error = "School settings could not be retrieved.";
        } else {
            // Force sending via SMTP to capture full diagnostics, then log the attempt
            $smtp_res = sendEmailSMTP($email, $subject, $message, $settings, $smtp_log);
            $result = $smtp_res;
            
            logEmail($email, $subject, $message, $smtp_res['success'] ? 'success' : 'failed', $smtp_res['success'] ? 'Test email sent via SMTP' : $smtp_res['message'], $db);
        }
    }
}

$title = "Test Email";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-4xl mx-auto">
                <!-- Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Test Email Configuration</h1>
                                <p class="text-blue-100 text-lg">Send a test email to verify your SMTP server configuration</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-envelope text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Result Messages -->
                <?php if ($result): ?>
                    <?php if ($result['success']): ?>
                        <div class="bg-green-100 border border-green-400 text-green-700 px-6 py-4 rounded-lg mb-6 flex items-start">
                            <i class="fas fa-check-circle text-2xl mr-3 mt-1"></i>
                            <div class="w-full">
                                <p class="font-semibold text-lg">Email Sent Successfully!</p>
                                <p class="text-sm mt-1"><?php echo htmlspecialchars($result['message']); ?></p>
                                <details class="mt-2" open>
                                    <summary class="cursor-pointer text-sm font-medium text-green-800">View Connection Logs</summary>
                                    <pre class="mt-2 p-3 bg-green-50 rounded text-xs overflow-auto max-h-60 font-mono"><?php echo htmlspecialchars($smtp_log); ?></pre>
                                </details>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-start">
                            <i class="fas fa-exclamation-circle text-2xl mr-3 mt-1"></i>
                            <div class="w-full">
                                <p class="font-semibold text-lg">SMTP Connection Failed</p>
                                <p class="text-sm mt-1"><?php echo htmlspecialchars($result['message']); ?></p>
                                <details class="mt-2" open>
                                    <summary class="cursor-pointer text-sm font-medium text-red-800">View Error & Debug Logs</summary>
                                    <pre class="mt-2 p-3 bg-red-50 rounded text-xs overflow-auto max-h-60 font-mono"><?php echo htmlspecialchars($smtp_log); ?></pre>
                                </details>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-center">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Test Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Send Test Email</h2>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Enter a recipient email address and draft a test message to run diagnostics</p>
                    </div>

                    <form method="POST" class="p-6 space-y-6">
                        <div>
                            <label for="email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Recipient Email <span class="text-red-500">*</span>
                            </label>
                            <input type="email" id="email" name="email" required
                                placeholder="recipient@example.com"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Subject <span class="text-red-500">*</span>
                            </label>
                            <input type="text" id="subject" name="subject" required
                                value="SMTP Diagnostics Test - Greenwood Academy"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Message Body (HTML supported) <span class="text-red-500">*</span>
                            </label>
                            <textarea id="message" name="message" rows="5" required
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"><h3>SMTP Integration Test</h3><p>This email has been sent dynamically to verify the SMTP connection settings of the School Management System.</p><p>Time sent: <b><?php echo date('Y-m-d H:i:s'); ?></b></p></textarea>
                        </div>

                        <!-- Current SMTP Configuration Info -->
                        <?php
                        $settings_query = "SELECT smtp_host, smtp_port, smtp_username, smtp_encryption, email_notifications FROM school_settings LIMIT 1";
                        $settings_stmt = $db->query($settings_query);
                        $smtp_settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Current SMTP Configurations</h3>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Host:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo htmlspecialchars($smtp_settings['smtp_host'] ?? 'Not configured'); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Port:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo htmlspecialchars($smtp_settings['smtp_port'] ?? '587'); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Encryption:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo strtoupper(htmlspecialchars($smtp_settings['smtp_encryption'] ?? 'None')); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Username:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo htmlspecialchars($smtp_settings['smtp_username'] ?? 'Not set'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="school.php?tab=email" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to Email Settings
                            </a>
                            <button type="submit" name="send_test_email" 
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-8 rounded-lg transition-colors duration-200 flex items-center">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Send Test Email
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Email Logs -->
                <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Recent Email Activity</h2>
                    </div>
                    <div class="p-6">
                        <?php
                        try {
                            $logs_query = "SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 10";
                            $logs_stmt = $db->query($logs_query);
                            $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (count($logs) > 0):
                        ?>
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-900">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Time</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Recipient</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Subject</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Error Details</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($logs as $log): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900 text-gray-700 dark:text-gray-300">
                                            <td class="px-4 py-3 whitespace-nowrap text-xs">
                                                <?php echo date('M d, H:i', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td class="px-4 py-3 font-medium">
                                                <?php echo htmlspecialchars($log['recipients']); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php echo htmlspecialchars($log['subject']); ?>
                                            </td>
                                            <td class="px-4 py-3 whitespace-nowrap">
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">Success</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">Failed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="px-4 py-3 text-xs max-w-xs truncate text-gray-500">
                                                <?php echo htmlspecialchars($log['error_message'] ?? 'None'); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400 text-center py-8">No email activity logged yet</p>
                        <?php endif; ?>
                        <?php } catch (PDOException $e) { ?>
                            <p class="text-gray-500 dark:text-gray-400 text-center py-8">Email logs table not yet created. Send your first test email to initialize.</p>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php include '../includes/footer.php'; ?>
