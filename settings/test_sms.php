<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/sms_helper.php';

$database = new Database();
$db = $database->getConnection();

$result = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_sms'])) {
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $message = filter_input(INPUT_POST, 'message', FILTER_SANITIZE_STRING);
    
    if (empty($phone) || empty($message)) {
        $error = "Phone number and message are required";
    } else {
        // Send SMS
        $result = sendSMS($phone, $message);
        
        // Log the attempt
        $settings_query = "SELECT sms_gateway FROM school_settings LIMIT 1";
        $settings_stmt = $db->query($settings_query);
        $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
        $gateway = $settings['sms_gateway'] ?? 'unknown';
        
        logSMS($phone, $message, $result['success'] ? 'success' : 'failed', $gateway, $result);
    }
}

$title = "Test SMS";
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
                                <h1 class="text-3xl font-bold mb-2">Test SMS Configuration</h1>
                                <p class="text-blue-100 text-lg">Send a test SMS to verify your gateway configuration</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-sms text-6xl text-white/80"></i>
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
                            <div>
                                <p class="font-semibold text-lg">SMS Sent Successfully!</p>
                                <p class="text-sm mt-1"><?php echo htmlspecialchars($result['message']); ?></p>
                                <?php if (isset($result['response'])): ?>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-sm font-medium">View Response Details</summary>
                                        <pre class="mt-2 p-3 bg-green-50 rounded text-xs overflow-auto"><?php echo htmlspecialchars(json_encode($result['response'], JSON_PRETTY_PRINT)); ?></pre>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-6 py-4 rounded-lg mb-6 flex items-start">
                            <i class="fas fa-exclamation-circle text-2xl mr-3 mt-1"></i>
                            <div>
                                <p class="font-semibold text-lg">SMS Failed</p>
                                <p class="text-sm mt-1"><?php echo htmlspecialchars($result['message']); ?></p>
                                <?php if (isset($result['response'])): ?>
                                    <details class="mt-2">
                                        <summary class="cursor-pointer text-sm font-medium">View Error Details</summary>
                                        <pre class="mt-2 p-3 bg-red-50 rounded text-xs overflow-auto"><?php echo htmlspecialchars(json_encode($result['response'], JSON_PRETTY_PRINT)); ?></pre>
                                    </details>
                                <?php endif; ?>
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
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Send Test SMS</h2>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Enter a phone number and message to test your SMS configuration</p>
                    </div>

                    <form method="POST" class="p-6 space-y-6">
                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Phone Number <span class="text-red-500">*</span>
                            </label>
                            <input type="tel" id="phone" name="phone" required
                                placeholder="+233XXXXXXXXX or 0XXXXXXXXX"
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                <i class="fas fa-info-circle mr-1"></i>
                                Include country code (e.g., +233 for Ghana)
                            </p>
                        </div>

                        <div>
                            <label for="message" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                Message <span class="text-red-500">*</span>
                            </label>
                            <textarea id="message" name="message" rows="4" required
                                placeholder="Enter your test message here..."
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                            <div class="flex justify-between items-center mt-1">
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-info-circle mr-1"></i>
                                    Keep messages under 160 characters for single SMS
                                </p>
                                <span id="char-count" class="text-xs text-gray-500">0 / 160</span>
                            </div>
                        </div>

                        <!-- Current Gateway Info -->
                        <?php
                        $settings_query = "SELECT sms_gateway, sms_sender_id FROM school_settings LIMIT 1";
                        $settings_stmt = $db->query($settings_query);
                        $sms_settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 border border-blue-200 dark:border-blue-800">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-2">Current Configuration</h3>
                            <div class="grid grid-cols-2 gap-4 text-sm">
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Gateway:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo ucfirst($sms_settings['sms_gateway'] ?? 'Not configured'); ?>
                                    </span>
                                </div>
                                <div>
                                    <span class="text-gray-600 dark:text-gray-400">Sender ID:</span>
                                    <span class="font-medium text-gray-900 dark:text-white ml-2">
                                        <?php echo htmlspecialchars($sms_settings['sms_sender_id'] ?? 'Not set'); ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-between items-center pt-4 border-t border-gray-200 dark:border-gray-700">
                            <a href="school.php?tab=sms" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 text-sm font-medium">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Back to SMS Settings
                            </a>
                            <button type="submit" name="send_test_sms" 
                                class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-3 px-8 rounded-lg transition-colors duration-200 flex items-center">
                                <i class="fas fa-paper-plane mr-2"></i>
                                Send Test SMS
                            </button>
                        </div>
                    </form>
                </div>

                <!-- SMS Logs -->
                <div class="mt-8 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Recent SMS Activity</h2>
                    </div>
                    <div class="p-6">
                        <?php
                        try {
                            $logs_query = "SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 10";
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
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Message</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Gateway</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($logs as $log): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-900">
                                            <td class="px-4 py-3 text-gray-900 dark:text-white whitespace-nowrap">
                                                <?php echo date('M d, H:i', strtotime($log['created_at'])); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars(substr($log['recipients'], 0, 20)) . (strlen($log['recipients']) > 20 ? '...' : ''); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-600 dark:text-gray-400">
                                                <?php echo htmlspecialchars(substr($log['message'], 0, 40)) . (strlen($log['message']) > 40 ? '...' : ''); ?>
                                            </td>
                                            <td class="px-4 py-3 text-gray-900 dark:text-white">
                                                <?php echo ucfirst($log['gateway']); ?>
                                            </td>
                                            <td class="px-4 py-3">
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="px-2 py-1 text-xs font-medium bg-green-100 text-green-800 rounded-full">Success</span>
                                                <?php else: ?>
                                                    <span class="px-2 py-1 text-xs font-medium bg-red-100 text-red-800 rounded-full">Failed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400 text-center py-8">No SMS activity yet</p>
                        <?php endif; ?>
                        <?php } catch (PDOException $e) { ?>
                            <p class="text-gray-500 dark:text-gray-400 text-center py-8">SMS logs table not yet created. Send your first SMS to initialize.</p>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
// Character counter
document.getElementById('message').addEventListener('input', function() {
    const count = this.value.length;
    document.getElementById('char-count').textContent = count + ' / 160';
    
    if (count > 160) {
        document.getElementById('char-count').classList.add('text-red-500');
        document.getElementById('char-count').classList.remove('text-gray-500');
    } else {
        document.getElementById('char-count').classList.remove('text-red-500');
        document.getElementById('char-count').classList.add('text-gray-500');
    }
});
</script>

<?php include '../includes/footer.php'; ?>
