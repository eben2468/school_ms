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

// Handle backup operations
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_backup'])) {
        try {
            $backup_dir = 'backups/';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0755, true);
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $backup_file = $backup_dir . "school_ms_backup_$timestamp.sql";
            
            // Database backup command
            $host = DB_HOST;
            $username = DB_USER;
            $password = DB_PASS;
            $database = DB_NAME;
            
            $command = "mysqldump --host=$host --user=$username --password=$password $database > $backup_file";
            
            if (exec($command) !== false) {
                $message = "Database backup created successfully: $backup_file";
            } else {
                $error = "Failed to create database backup.";
            }
        } catch (Exception $e) {
            $error = "Error creating backup: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_backup'])) {
        $backup_file = $_POST['backup_file'];
        if (file_exists($backup_file) && unlink($backup_file)) {
            $message = "Backup file deleted successfully.";
        } else {
            $error = "Failed to delete backup file.";
        }
    }
}

// Get existing backup files
$backup_files = [];
$backup_dir = 'backups/';
if (file_exists($backup_dir)) {
    $files = scandir($backup_dir);
    foreach ($files as $file) {
        if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $backup_files[] = [
                'name' => $file,
                'path' => $backup_dir . $file,
                'size' => filesize($backup_dir . $file),
                'date' => filemtime($backup_dir . $file)
            ];
        }
    }
    // Sort by date (newest first)
    usort($backup_files, function($a, $b) {
        return $b['date'] - $a['date'];
    });
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
                                <h1 class="text-3xl font-bold mb-2">Database Backup</h1>
                                <p class="text-blue-100 text-lg">Create and manage database backups for data protection</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-database mr-2"></i>
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
                                    <i class="fas fa-database text-6xl text-white/80"></i>
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

                <!-- Create Backup Section -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Create New Backup</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Create a complete backup of the school database</p>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="space-y-4">
                            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
                                <div class="flex items-start">
                                    <i class="fas fa-info-circle text-blue-600 dark:text-blue-400 mt-1 mr-3"></i>
                                    <div>
                                        <h3 class="text-sm font-medium text-blue-800 dark:text-blue-200">Backup Information</h3>
                                        <p class="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                            This will create a complete backup of all database tables including student records, 
                                            academic data, user accounts, and system settings.
                                        </p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end">
                                <button type="submit" name="create_backup" 
                                        class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                    <i class="fas fa-download mr-2"></i>Create Backup
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Backups -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Existing Backups</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage and download existing database backups</p>
                    </div>
                    
                    <?php if (empty($backup_files)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-database text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Backups Found</h3>
                        <p class="text-gray-600 dark:text-gray-400">Create your first backup to get started.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Backup File</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Size</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($backup_files as $backup): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-archive text-blue-600 dark:text-blue-400 mr-3"></i>
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($backup['name']); ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo number_format($backup['size'] / 1024, 2); ?> KB
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo date('M j, Y g:i A', $backup['date']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <div class="flex space-x-2">
                                            <a href="<?php echo htmlspecialchars($backup['path']); ?>" 
                                               class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium"
                                               download>
                                                <i class="fas fa-download mr-1"></i>Download
                                            </a>
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this backup?');">
                                                <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($backup['path']); ?>">
                                                <button type="submit" name="delete_backup" 
                                                        class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            </form>
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
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Auto-refresh backup list every 30 seconds
setInterval(function() {
    // Only refresh if no modals are open
    if (!document.querySelector('.modal:not(.hidden)')) {
        location.reload();
    }
}, 30000);
</script>
