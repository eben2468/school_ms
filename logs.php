<?php
session_start();
require_once 'config/database.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header('Location: auth/login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Create logs directory if it doesn't exist
$logs_dir = 'logs/';
if (!file_exists($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

// Handle log operations
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_log'])) {
        $log_file = $_POST['log_file'];
        $full_path = $logs_dir . basename($log_file); // Security: prevent directory traversal
        
        if (file_exists($full_path)) {
            file_put_contents($full_path, '');
            $message = "Log file cleared successfully.";
        } else {
            $error = "Log file not found.";
        }
    }
    
    if (isset($_POST['delete_log'])) {
        $log_file = $_POST['log_file'];
        $full_path = $logs_dir . basename($log_file); // Security: prevent directory traversal
        
        if (file_exists($full_path) && unlink($full_path)) {
            $message = "Log file deleted successfully.";
        } else {
            $error = "Failed to delete log file.";
        }
    }
}

// Get log files
$log_files = [];
if (file_exists($logs_dir)) {
    $files = scandir($logs_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && is_file($logs_dir . $file)) {
            $log_files[] = [
                'name' => $file,
                'path' => $logs_dir . $file,
                'size' => filesize($logs_dir . $file),
                'modified' => filemtime($logs_dir . $file),
                'lines' => count(file($logs_dir . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ];
        }
    }
    // Sort by modification time (newest first)
    usort($log_files, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
}

// Get selected log content
$selected_log = $_GET['view'] ?? '';
$log_content = '';
$log_lines = [];

if ($selected_log && file_exists($logs_dir . basename($selected_log))) {
    $log_content = file_get_contents($logs_dir . basename($selected_log));
    $log_lines = array_reverse(file($logs_dir . basename($selected_log), FILE_IGNORE_NEW_LINES));
    // Limit to last 1000 lines for performance
    $log_lines = array_slice($log_lines, 0, 1000);
}

// Create sample log entries if no logs exist
if (empty($log_files)) {
    $sample_logs = [
        'system.log' => date('Y-m-d H:i:s') . " [INFO] System started successfully\n" .
                      date('Y-m-d H:i:s') . " [INFO] Database connection established\n",
        'error.log' => '',
        'access.log' => date('Y-m-d H:i:s') . " [ACCESS] User login: admin\n" .
                       date('Y-m-d H:i:s') . " [ACCESS] Page accessed: dashboard.php\n"
    ];
    
    foreach ($sample_logs as $filename => $content) {
        file_put_contents($logs_dir . $filename, $content);
    }
    
    // Refresh log files list
    $log_files = [];
    $files = scandir($logs_dir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..' && is_file($logs_dir . $file)) {
            $log_files[] = [
                'name' => $file,
                'path' => $logs_dir . $file,
                'size' => filesize($logs_dir . $file),
                'modified' => filemtime($logs_dir . $file),
                'lines' => count(file($logs_dir . $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
            ];
        }
    }
}

include 'includes/header.php';
include 'includes/sidebar.php';
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
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">System Logs</h1>
                                <p class="text-blue-100 text-lg">Monitor system activity and troubleshoot issues</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-file-alt mr-2"></i>
                                        System Administration
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-file-alt text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Log Files List -->
                    <div class="lg:col-span-1">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Log Files</h2>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Available system log files</p>
                            </div>
                            
                            <?php if (empty($log_files)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-file-alt text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-600 dark:text-gray-400">No log files found</p>
                            </div>
                            <?php else: ?>
                            <div class="p-4">
                                <div class="space-y-2">
                                    <?php foreach ($log_files as $log): ?>
                                    <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-3 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                        <div class="flex items-center justify-between mb-2">
                                            <h3 class="font-medium text-gray-900 dark:text-white text-sm">
                                                <?php echo htmlspecialchars($log['name']); ?>
                                            </h3>
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo number_format($log['size']); ?> bytes
                                            </span>
                                        </div>
                                        <div class="text-xs text-gray-600 dark:text-gray-400 mb-3">
                                            <?php echo $log['lines']; ?> lines • Modified <?php echo date('M j, g:i A', $log['modified']); ?>
                                        </div>
                                        <div class="flex space-x-2">
                                            <a href="?view=<?php echo urlencode($log['name']); ?>" 
                                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-xs font-medium">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </a>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="log_file" value="<?php echo htmlspecialchars($log['name']); ?>">
                                                <button type="submit" name="clear_log" 
                                                        class="text-yellow-600 dark:text-yellow-400 hover:text-yellow-800 dark:hover:text-yellow-300 text-xs font-medium"
                                                        onclick="return confirm('Clear this log file?')">
                                                    <i class="fas fa-eraser mr-1"></i>Clear
                                                </button>
                                            </form>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="log_file" value="<?php echo htmlspecialchars($log['name']); ?>">
                                                <button type="submit" name="delete_log" 
                                                        class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 text-xs font-medium"
                                                        onclick="return confirm('Delete this log file?')">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Log Content Viewer -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                        <?php echo $selected_log ? 'Viewing: ' . htmlspecialchars($selected_log) : 'Log Content'; ?>
                                    </h2>
                                    <?php if ($selected_log): ?>
                                    <div class="flex space-x-2">
                                        <button onclick="refreshLog()" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm">
                                            <i class="fas fa-sync-alt mr-1"></i>Refresh
                                        </button>
                                        <a href="<?php echo htmlspecialchars($logs_dir . basename($selected_log)); ?>" 
                                           download class="text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 text-sm">
                                            <i class="fas fa-download mr-1"></i>Download
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="p-6">
                                <?php if (!$selected_log): ?>
                                <div class="text-center py-12">
                                    <i class="fas fa-file-alt text-gray-400 text-6xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Select a Log File</h3>
                                    <p class="text-gray-600 dark:text-gray-400">Choose a log file from the list to view its contents</p>
                                </div>
                                <?php elseif (empty($log_lines)): ?>
                                <div class="text-center py-12">
                                    <i class="fas fa-file text-gray-400 text-6xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Log File is Empty</h3>
                                    <p class="text-gray-600 dark:text-gray-400">This log file contains no entries</p>
                                </div>
                                <?php else: ?>
                                <div class="bg-gray-900 rounded-lg p-4 overflow-auto max-h-96">
                                    <div class="text-green-400 font-mono text-sm space-y-1">
                                        <?php foreach ($log_lines as $line): ?>
                                        <div class="hover:bg-gray-800 px-2 py-1 rounded">
                                            <?php echo htmlspecialchars($line); ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                                    Showing last <?php echo count($log_lines); ?> lines (most recent first)
                                </div>
                                <?php endif; ?>
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
function refreshLog() {
    location.reload();
}

// Auto-refresh every 30 seconds if viewing a log
<?php if ($selected_log): ?>
setInterval(function() {
    location.reload();
}, 30000);
<?php endif; ?>
</script>
