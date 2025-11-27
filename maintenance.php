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

// Handle maintenance operations
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['optimize_database'])) {
            // Get all tables
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                $pdo->exec("OPTIMIZE TABLE `$table`");
            }
            $message = "Database optimization completed successfully.";
        }
        
        if (isset($_POST['clear_logs'])) {
            // Clear application logs (if you have a logs table)
            $log_files = ['error.log', 'access.log', 'debug.log'];
            $cleared = 0;
            
            foreach ($log_files as $log_file) {
                if (file_exists("logs/$log_file")) {
                    file_put_contents("logs/$log_file", '');
                    $cleared++;
                }
            }
            $message = "Cleared $cleared log files.";
        }
        
        if (isset($_POST['clear_cache'])) {
            // Clear cache directories
            $cache_dirs = ['cache/', 'tmp/', 'uploads/temp/'];
            $cleared = 0;
            
            foreach ($cache_dirs as $dir) {
                if (is_dir($dir)) {
                    $files = glob($dir . '*');
                    foreach ($files as $file) {
                        if (is_file($file)) {
                            unlink($file);
                            $cleared++;
                        }
                    }
                }
            }
            $message = "Cleared $cleared cache files.";
        }
        
        if (isset($_POST['check_integrity'])) {
            // Check database integrity
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $issues = [];
            
            foreach ($tables as $table) {
                $check = $pdo->query("CHECK TABLE `$table`");
                $result = $check->fetch(PDO::FETCH_ASSOC);
                if ($result['Msg_text'] !== 'OK') {
                    $issues[] = $table . ': ' . $result['Msg_text'];
                }
            }
            
            if (empty($issues)) {
                $message = "Database integrity check passed. No issues found.";
            } else {
                $error = "Database integrity issues found: " . implode(', ', $issues);
            }
        }
        
    } catch (Exception $e) {
        $error = "Error during maintenance operation: " . $e->getMessage();
    }
}

// Get system information
$system_info = [
    'php_version' => phpversion(),
    'mysql_version' => $pdo->query('SELECT VERSION()')->fetchColumn(),
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time')
];

// Get database statistics
try {
    $db_stats = [];
    $stmt = $pdo->query("SELECT COUNT(*) as total_users FROM users");
    $db_stats['total_users'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM users WHERE role = 'student'");
    $db_stats['total_students'] = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SELECT COUNT(*) as total_teachers FROM users WHERE role = 'teacher'");
    $db_stats['total_teachers'] = $stmt->fetchColumn();
    
    // Get database size
    $stmt = $pdo->query("SELECT ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS 'DB Size in MB' 
                        FROM information_schema.tables 
                        WHERE table_schema = '" . DB_NAME . "'");
    $db_stats['database_size'] = $stmt->fetchColumn() . ' MB';
    
} catch (Exception $e) {
    $db_stats = ['error' => 'Unable to fetch database statistics'];
}

include 'includes/header.php';
include 'includes/sidebar.php';
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
                                <h1 class="text-3xl font-bold mb-2">System Maintenance</h1>
                                <p class="text-blue-100 text-lg">Maintain and optimize system performance</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-tools mr-2"></i>
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
                                    <i class="fas fa-tools text-6xl text-white/80"></i>
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

                <!-- System Information -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
                    <!-- System Info -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">System Information</h2>
                        </div>
                        <div class="p-6">
                            <div class="space-y-3">
                                <?php foreach ($system_info as $key => $value): ?>
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-400"><?php echo ucwords(str_replace('_', ' ', $key)); ?>:</span>
                                    <span class="text-gray-900 dark:text-white font-medium"><?php echo htmlspecialchars($value); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Database Statistics -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Database Statistics</h2>
                        </div>
                        <div class="p-6">
                            <?php if (isset($db_stats['error'])): ?>
                            <p class="text-red-600 dark:text-red-400"><?php echo htmlspecialchars($db_stats['error']); ?></p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach ($db_stats as $key => $value): ?>
                                <div class="flex justify-between">
                                    <span class="text-gray-600 dark:text-gray-400"><?php echo ucwords(str_replace('_', ' ', $key)); ?>:</span>
                                    <span class="text-gray-900 dark:text-white font-medium"><?php echo htmlspecialchars($value); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Maintenance Operations -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Maintenance Operations</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Perform system maintenance tasks</p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Database Optimization -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-database text-blue-600 dark:text-blue-400 text-xl mr-3"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Database Optimization</h3>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">
                                    Optimize database tables to improve performance and reclaim unused space.
                                </p>
                                <form method="POST" class="inline">
                                    <button type="submit" name="optimize_database" 
                                            class="w-full px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200"
                                            onclick="return confirm('This may take a few minutes. Continue?')">
                                        <i class="fas fa-cog mr-2"></i>Optimize Database
                                    </button>
                                </form>
                            </div>

                            <!-- Clear Logs -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-file-alt text-yellow-600 dark:text-yellow-400 text-xl mr-3"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Clear System Logs</h3>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">
                                    Clear application log files to free up disk space.
                                </p>
                                <form method="POST" class="inline">
                                    <button type="submit" name="clear_logs" 
                                            class="w-full px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-colors duration-200"
                                            onclick="return confirm('This will clear all log files. Continue?')">
                                        <i class="fas fa-trash mr-2"></i>Clear Logs
                                    </button>
                                </form>
                            </div>

                            <!-- Clear Cache -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-broom text-green-600 dark:text-green-400 text-xl mr-3"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Clear Cache</h3>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">
                                    Clear temporary files and cache to improve system performance.
                                </p>
                                <form method="POST" class="inline">
                                    <button type="submit" name="clear_cache" 
                                            class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors duration-200">
                                        <i class="fas fa-broom mr-2"></i>Clear Cache
                                    </button>
                                </form>
                            </div>

                            <!-- Database Integrity Check -->
                            <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                <div class="flex items-center mb-3">
                                    <i class="fas fa-shield-alt text-purple-600 dark:text-purple-400 text-xl mr-3"></i>
                                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">Integrity Check</h3>
                                </div>
                                <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">
                                    Check database integrity and identify potential issues.
                                </p>
                                <form method="POST" class="inline">
                                    <button type="submit" name="check_integrity" 
                                            class="w-full px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors duration-200">
                                        <i class="fas fa-search mr-2"></i>Check Integrity
                                    </button>
                                </form>
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
