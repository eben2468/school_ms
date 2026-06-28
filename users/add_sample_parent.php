<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_sample_parent'])) {
    try {
        // Check if parent already exists
        $check_query = "SELECT id FROM users WHERE email = 'sample.parent@school.com'";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $message = "Sample parent user already exists with email: sample.parent@school.com";
            $message_type = "warning";
        } else {
            // Create sample parent user
            $name = "Sample Parent";
            $email = "sample.parent@school.com";
            $password = password_hash("parent123", PASSWORD_DEFAULT);
            $role = "parent";
            
            $insert_query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, 'active')";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':name', $name);
            $insert_stmt->bindParam(':email', $email);
            $insert_stmt->bindParam(':password', $password);
            $insert_stmt->bindParam(':role', $role);
            $insert_stmt->execute();
            
            $message = "Sample parent user created successfully! Email: sample.parent@school.com, Password: parent123";
            $message_type = "success";
        }
    } catch (Exception $e) {
        $message = "Error creating sample parent: " . $e->getMessage();
        $message_type = "error";
    }
}

// Get current parent count
$parent_query = "SELECT COUNT(*) as count FROM users WHERE role = 'parent'";
$parent_stmt = $db->prepare($parent_query);
$parent_stmt->execute();
$parent_count = $parent_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$title = "Add Sample Parent";
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
            <div class="w-full max-w-2xl mx-auto">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Add Sample Parent</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Create a sample parent user for testing</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <!-- Message -->
                <?php if ($message): ?>
                <div class="mb-6 p-4 rounded-lg <?php 
                    echo $message_type === 'success' ? 'bg-green-50 border border-green-200 text-green-800 dark:bg-green-900/20 dark:border-green-800 dark:text-green-200' : 
                        ($message_type === 'warning' ? 'bg-yellow-50 border border-yellow-200 text-yellow-800 dark:bg-yellow-900/20 dark:border-yellow-800 dark:text-yellow-200' : 
                        'bg-red-50 border border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800 dark:text-red-200'); 
                ?>">
                    <div class="flex items-start">
                        <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'warning' ? 'exclamation-triangle' : 'times-circle'); ?> mt-1 mr-3"></i>
                        <div>
                            <p class="font-medium"><?php echo htmlspecialchars($message); ?></p>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Current Status -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Current Status</h3>
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm text-gray-600 dark:text-gray-400">Parent Users in System</p>
                            <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400"><?php echo $parent_count; ?></p>
                        </div>
                    </div>
                </div>

                <!-- Add Sample Parent Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Create Sample Parent User</h3>
                    
                    <div class="mb-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                        <div class="flex items-start">
                            <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                            <div>
                                <h4 class="text-sm font-medium text-blue-900 dark:text-blue-200">Sample Parent Details</h4>
                                <ul class="text-sm text-blue-700 dark:text-blue-300 mt-2 space-y-1">
                                    <li><strong>Name:</strong> Sample Parent</li>
                                    <li><strong>Email:</strong> sample.parent@school.com</li>
                                    <li><strong>Password:</strong> parent123</li>
                                    <li><strong>Role:</strong> Parent</li>
                                    <li><strong>Status:</strong> Active</li>
                                </ul>
                            </div>
                        </div>
                    </div>

                    <form method="POST">
                        <div class="flex justify-between items-center">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    This will create a sample parent user that you can use for testing the parent role functionality.
                                </p>
                            </div>
                            <button type="submit" name="add_sample_parent" 
                                class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                                <i class="fas fa-plus mr-2"></i>Create Sample Parent
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Additional Actions -->
                <div class="mt-6 bg-gray-50 dark:bg-gray-700 rounded-lg p-6">
                    <h4 class="text-md font-semibold text-gray-900 dark:text-white mb-3">Other Options</h4>
                    <div class="space-y-3">
                        <a href="create.php" class="inline-flex items-center text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                            <i class="fas fa-user-plus mr-2"></i>Create a new parent user manually
                        </a>
                        <br>
                        <a href="bulk_import.php" class="inline-flex items-center text-green-600 hover:text-green-800 dark:text-green-400 dark:hover:text-green-300">
                            <i class="fas fa-upload mr-2"></i>Import parent users via CSV
                        </a>
                        <br>
                        <a href="check_parents.php" class="inline-flex items-center text-purple-600 hover:text-purple-800 dark:text-purple-400 dark:hover:text-purple-300">
                            <i class="fas fa-search mr-2"></i>Check all users and their roles
                        </a>
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
