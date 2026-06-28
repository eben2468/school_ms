<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$messages = [];
$action = $_GET['action'] ?? '';

if ($action === 'create_parent_students_table') {
    try {
        // Check if table exists
        $check_query = "SHOW TABLES LIKE 'parent_students'";
        $result = $db->query($check_query);
        
        if ($result->rowCount() == 0) {
            $create_sql = "
            CREATE TABLE parent_students (
                id INT PRIMARY KEY AUTO_INCREMENT,
                parent_id INT NOT NULL,
                student_id INT NOT NULL,
                relationship ENUM('father', 'mother', 'guardian', 'other') NOT NULL DEFAULT 'parent',
                is_primary BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_parent_student (parent_id, student_id)
            )";
            
            $db->exec($create_sql);
            $messages[] = ['success', '✅ parent_students table created successfully!'];
        } else {
            $messages[] = ['info', 'ℹ️ parent_students table already exists'];
        }
        
    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Error creating table: ' . $e->getMessage()];
    }
}

if ($action === 'create_sample_relationships') {
    try {
        // Get parent and student users
        $parent_query = "SELECT id, name FROM users WHERE role = 'parent' LIMIT 5";
        $parent_result = $db->query($parent_query);
        $parents = $parent_result->fetchAll(PDO::FETCH_ASSOC);
        
        $student_query = "SELECT id, name FROM users WHERE role = 'student' LIMIT 10";
        $student_result = $db->query($student_query);
        $students = $student_result->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($parents)) {
            $messages[] = ['warning', '⚠️ No parent users found. Please create parent users first.'];
        } elseif (empty($students)) {
            $messages[] = ['warning', '⚠️ No student users found. Please create student users first.'];
        } else {
            $relationships_created = 0;
            $relationships = ['father', 'mother', 'guardian'];
            
            foreach ($parents as $index => $parent) {
                // Assign 1-2 children to each parent
                $num_children = rand(1, min(2, count($students)));
                $assigned_students = array_slice($students, $index * 2, $num_children);
                
                foreach ($assigned_students as $student_index => $student) {
                    $relationship = $relationships[array_rand($relationships)];
                    $is_primary = $student_index === 0; // First relationship is primary
                    
                    $insert_query = "INSERT IGNORE INTO parent_students (parent_id, student_id, relationship, is_primary) VALUES (:parent_id, :student_id, :relationship, :is_primary)";
                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':parent_id', $parent['id']);
                    $insert_stmt->bindParam(':student_id', $student['id']);
                    $insert_stmt->bindParam(':relationship', $relationship);
                    $insert_stmt->bindParam(':is_primary', $is_primary);
                    $insert_stmt->execute();
                    
                    if ($insert_stmt->rowCount() > 0) {
                        $relationships_created++;
                        $messages[] = ['success', "✅ Linked {$parent['name']} to {$student['name']} as $relationship"];
                    }
                }
            }
            
            if ($relationships_created > 0) {
                $messages[] = ['success', "🎉 Created $relationships_created parent-student relationships!"];
            } else {
                $messages[] = ['info', 'ℹ️ All relationships already exist'];
            }
        }
        
    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Error creating relationships: ' . $e->getMessage()];
    }
}

// Get current status
$table_exists = false;
$relationship_count = 0;
$parent_count = 0;
$student_count = 0;

try {
    $check_query = "SHOW TABLES LIKE 'parent_students'";
    $result = $db->query($check_query);
    $table_exists = $result->rowCount() > 0;
    
    if ($table_exists) {
        $count_query = "SELECT COUNT(*) as count FROM parent_students";
        $count_result = $db->query($count_query);
        $relationship_count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
    }
    
    $parent_count_query = "SELECT COUNT(*) as count FROM users WHERE role = 'parent'";
    $parent_count_result = $db->query($parent_count_query);
    $parent_count = $parent_count_result->fetch(PDO::FETCH_ASSOC)['count'];
    
    $student_count_query = "SELECT COUNT(*) as count FROM users WHERE role = 'student'";
    $student_count_result = $db->query($student_count_query);
    $student_count = $student_count_result->fetch(PDO::FETCH_ASSOC)['count'];
    
} catch (PDOException $e) {
    $messages[] = ['error', '❌ Error checking status: ' . $e->getMessage()];
}

$title = "Create Parent-Students Table";
include 'includes/header.php';
include 'includes/sidebar.php';
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Create Parent-Students Table</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Fix the critical missing table for parent portal</p>
                    </div>
                    <a href="parent/diagnostic.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Diagnostic
                    </a>
                </div>

                <!-- Messages -->
                <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $message[0] === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : ($message[0] === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : ($message[0] === 'warning' ? 'bg-yellow-100 border border-yellow-400 text-yellow-700' : 'bg-blue-100 border border-blue-400 text-blue-700')); ?>">
                    <?php echo htmlspecialchars($message[1]); ?>
                </div>
                <?php endforeach; ?>

                <!-- Current Status -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 <?php echo $table_exists ? 'bg-green-100 dark:bg-green-900' : 'bg-red-100 dark:bg-red-900'; ?> rounded-lg flex items-center justify-center">
                                <i class="fas fa-table <?php echo $table_exists ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?> text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Table Status</h3>
                                <p class="text-2xl font-bold <?php echo $table_exists ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                                    <?php echo $table_exists ? 'Exists' : 'Missing'; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-link text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Relationships</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $relationship_count; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Parents</h3>
                                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo $parent_count; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-graduate text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Students</h3>
                                <p class="text-2xl font-bold text-orange-600 dark:text-orange-400"><?php echo $student_count; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Critical Issue Alert -->
                <?php if (!$table_exists): ?>
                <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-2">🚨 Critical Issue</h4>
                            <p class="text-sm text-red-700 dark:text-red-300 mb-3">
                                The <code>parent_students</code> table is missing. This is the root cause of the parent portal errors.
                            </p>
                            <div class="bg-red-100 dark:bg-red-800/30 border border-red-300 dark:border-red-700 rounded p-3">
                                <p class="text-sm font-medium text-red-800 dark:text-red-200">
                                    <strong>Error:</strong> Table 'school_ms.parent_students' doesn't exist
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Action Steps -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-database text-blue-500 mr-2"></i>
                            Step 1: Create Table
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Create the parent_students table to link parents with their children.
                        </p>
                        <a href="?action=create_parent_students_table" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i>Create Table
                        </a>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-link text-green-500 mr-2"></i>
                            Step 2: Create Relationships
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Link existing parent users to student users for testing.
                        </p>
                        <a href="?action=create_sample_relationships" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users mr-2"></i>Create Relationships
                        </a>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="mt-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-6">
                    <h4 class="text-lg font-semibold text-green-900 dark:text-green-200 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>
                        After Creating the Table
                    </h4>
                    <p class="text-sm text-green-700 dark:text-green-300 mb-3">
                        Once the parent_students table is created:
                    </p>
                    <ol class="text-sm text-green-700 dark:text-green-300 list-decimal list-inside space-y-1">
                        <li>Test the parent portal pages (attendance, grades, fees)</li>
                        <li>Login as a parent user to verify access</li>
                        <li>Create additional parent-student relationships as needed</li>
                        <li>The "table doesn't exist" errors should be resolved</li>
                    </ol>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>
