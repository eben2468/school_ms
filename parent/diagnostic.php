<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'parent'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$messages = [];
$diagnostics = [];

// Check if parent_students table exists
try {
    $check_parent_students = "SHOW TABLES LIKE 'parent_students'";
    $result = $db->query($check_parent_students);
    if ($result->rowCount() > 0) {
        $diagnostics['parent_students'] = ['exists' => true, 'status' => 'success'];
        
        // Count relationships
        $count_query = "SELECT COUNT(*) as count FROM parent_students";
        $count_result = $db->query($count_query);
        $count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
        $diagnostics['parent_students']['count'] = $count;
        
        $messages[] = ['success', "✅ parent_students table exists with $count relationships"];
    } else {
        $diagnostics['parent_students'] = ['exists' => false, 'status' => 'error'];
        $messages[] = ['error', '❌ parent_students table is missing - this is the main issue!'];
    }
} catch (PDOException $e) {
    $diagnostics['parent_students'] = ['exists' => false, 'status' => 'error'];
    $messages[] = ['error', '❌ Error checking parent_students table: ' . $e->getMessage()];
}

// Check other required tables
$required_tables = ['announcements', 'notifications', 'fees', 'attendance', 'grades', 'exams'];
foreach ($required_tables as $table) {
    try {
        $check_query = "SHOW TABLES LIKE '$table'";
        $result = $db->query($check_query);
        if ($result->rowCount() > 0) {
            $diagnostics[$table] = ['exists' => true, 'status' => 'success'];
            
            // Count records
            $count_query = "SELECT COUNT(*) as count FROM $table";
            $count_result = $db->query($count_query);
            $count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
            $diagnostics[$table]['count'] = $count;
            
            $messages[] = ['success', "✅ $table table exists with $count records"];
        } else {
            $diagnostics[$table] = ['exists' => false, 'status' => 'warning'];
            $messages[] = ['warning', "⚠️ $table table is missing"];
        }
    } catch (PDOException $e) {
        $diagnostics[$table] = ['exists' => false, 'status' => 'error'];
        $messages[] = ['error', "❌ Error checking $table table: " . $e->getMessage()];
    }
}

// Check for parent and student users
try {
    $parent_count_query = "SELECT COUNT(*) as count FROM users WHERE role = 'parent'";
    $parent_result = $db->query($parent_count_query);
    $parent_count = $parent_result->fetch(PDO::FETCH_ASSOC)['count'];
    
    $student_count_query = "SELECT COUNT(*) as count FROM users WHERE role = 'student'";
    $student_result = $db->query($student_count_query);
    $student_count = $student_result->fetch(PDO::FETCH_ASSOC)['count'];
    
    $messages[] = ['info', "📊 Found $parent_count parent users and $student_count student users"];
    
    $diagnostics['users'] = [
        'parent_count' => $parent_count,
        'student_count' => $student_count
    ];
} catch (PDOException $e) {
    $messages[] = ['error', '❌ Error checking user counts: ' . $e->getMessage()];
}

$title = "Parent Portal Diagnostic";
include '../includes/header.php';
include '../includes/sidebar.php';
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
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Parent Portal Diagnostic</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Check the status of parent portal functionality</p>
                    </div>
                    <div class="flex space-x-3">
                        <a href="../fix_parent_tables.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-tools mr-2"></i>Fix Tables
                        </a>
                        <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                        </a>
                    </div>
                </div>

                <!-- Messages -->
                <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $message[0] === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : ($message[0] === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : ($message[0] === 'warning' ? 'bg-yellow-100 border border-yellow-400 text-yellow-700' : 'bg-blue-100 border border-blue-400 text-blue-700')); ?>">
                    <?php echo htmlspecialchars($message[1]); ?>
                </div>
                <?php endforeach; ?>

                <!-- Main Issue Alert -->
                <?php if (!$diagnostics['parent_students']['exists']): ?>
                <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-2">🚨 Critical Issue Found</h4>
                            <p class="text-sm text-red-700 dark:text-red-300 mb-3">
                                The <code>parent_students</code> table is missing from your database. This table is essential for linking parents to their children and is the root cause of the error you're experiencing.
                            </p>
                            <div class="bg-red-100 dark:bg-red-800/30 border border-red-300 dark:border-red-700 rounded p-3 mb-3">
                                <p class="text-sm font-medium text-red-800 dark:text-red-200">
                                    <strong>Error:</strong> Table 'school_ms.parent_students' doesn't exist
                                </p>
                            </div>
                            <a href="../fix_parent_tables.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                <i class="fas fa-wrench mr-2"></i>Fix This Issue Now
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Table Status -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Database Table Status</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($diagnostics as $table => $info): ?>
                                <?php if ($table !== 'users'): ?>
                                <div class="flex items-center justify-between p-3 border border-gray-200 dark:border-gray-600 rounded-lg">
                                    <div class="flex items-center">
                                        <i class="fas fa-<?php echo $info['exists'] ? 'check-circle text-green-500' : 'times-circle text-red-500'; ?> mr-3"></i>
                                        <div>
                                            <span class="font-medium text-gray-900 dark:text-white"><?php echo $table; ?></span>
                                            <?php if (isset($info['count'])): ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo $info['count']; ?> records</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 rounded-full text-xs font-medium
                                        <?php echo $info['status'] === 'success' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 
                                                   ($info['status'] === 'warning' ? 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200' : 
                                                   'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'); ?>">
                                        <?php echo $info['exists'] ? 'Exists' : 'Missing'; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- User Statistics -->
                <?php if (isset($diagnostics['users'])): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">User Statistics</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white">Parent Users</h4>
                                    <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $diagnostics['users']['parent_count']; ?></p>
                                </div>
                            </div>
                            
                            <div class="flex items-center">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mr-4">
                                    <i class="fas fa-user-graduate text-green-600 dark:text-green-400 text-xl"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-semibold text-gray-900 dark:text-white">Student Users</h4>
                                    <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $diagnostics['users']['student_count']; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Quick Actions -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Actions</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <a href="../fix_parent_tables.php" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-tools text-blue-500 mr-3"></i>
                                    <span class="font-medium text-gray-900 dark:text-white">Fix Database Tables</span>
                                </div>
                            </a>
                            
                            <a href="index.php" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-home text-green-500 mr-3"></i>
                                    <span class="font-medium text-gray-900 dark:text-white">Parent Portal Home</span>
                                </div>
                            </a>
                            
                            <a href="/school_ms/parent/dashboard.php" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                                <div class="flex items-center">
                                    <i class="fas fa-tachometer-alt text-purple-500 mr-3"></i>
                                    <span class="font-medium text-gray-900 dark:text-white">Main Dashboard</span>
                                </div>
                            </a>
                        </div>
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
