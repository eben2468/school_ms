<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and has admin privileges
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_link':
            $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_SANITIZE_NUMBER_INT);
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
            $relationship = filter_input(INPUT_POST, 'relationship', FILTER_SANITIZE_STRING);
            $is_primary = isset($_POST['is_primary']) ? 1 : 0;
            
            try {
                // Check if relationship already exists
                $check_sql = "SELECT id FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
                $stmt = $db->prepare($check_sql);
                $stmt->bindParam(':parent_id', $parent_id);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->execute();
                
                if ($stmt->rowCount() > 0) {
                    $error_message = "This parent-student relationship already exists.";
                } else {
                    // If setting as primary, remove primary status from other relationships for this student
                    if ($is_primary) {
                        $update_primary = "UPDATE parent_students SET is_primary = FALSE WHERE student_id = :student_id";
                        $stmt = $db->prepare($update_primary);
                        $stmt->bindParam(':student_id', $student_id);
                        $stmt->execute();
                    }
                    
                    // Create new relationship
                    $insert_sql = "INSERT INTO parent_students (parent_id, student_id, relationship, is_primary) 
                                  VALUES (:parent_id, :student_id, :relationship, :is_primary)";
                    $stmt = $db->prepare($insert_sql);
                    $stmt->bindParam(':parent_id', $parent_id);
                    $stmt->bindParam(':student_id', $student_id);
                    $stmt->bindParam(':relationship', $relationship);
                    $stmt->bindParam(':is_primary', $is_primary);
                    $stmt->execute();
                    
                    $success_message = "Parent-student relationship created successfully!";
                }
            } catch (PDOException $e) {
                $error_message = "Error creating relationship: " . $e->getMessage();
            }
            break;
            
        case 'delete_link':
            $link_id = filter_input(INPUT_POST, 'link_id', FILTER_SANITIZE_NUMBER_INT);
            
            try {
                $delete_sql = "DELETE FROM parent_students WHERE id = :link_id";
                $stmt = $db->prepare($delete_sql);
                $stmt->bindParam(':link_id', $link_id);
                $stmt->execute();
                
                $success_message = "Parent-student relationship deleted successfully!";
            } catch (PDOException $e) {
                $error_message = "Error deleting relationship: " . $e->getMessage();
            }
            break;
            
        case 'update_primary':
            $link_id = filter_input(INPUT_POST, 'link_id', FILTER_SANITIZE_NUMBER_INT);
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
            
            try {
                $db->beginTransaction();
                
                // Remove primary status from all relationships for this student
                $update_all = "UPDATE parent_students SET is_primary = FALSE WHERE student_id = :student_id";
                $stmt = $db->prepare($update_all);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->execute();
                
                // Set this relationship as primary
                $update_primary = "UPDATE parent_students SET is_primary = TRUE WHERE id = :link_id";
                $stmt = $db->prepare($update_primary);
                $stmt->bindParam(':link_id', $link_id);
                $stmt->execute();
                
                $db->commit();
                $success_message = "Primary parent updated successfully!";
            } catch (PDOException $e) {
                $db->rollBack();
                $error_message = "Error updating primary parent: " . $e->getMessage();
            }
            break;
    }
}

// Get all parents
$parents_sql = "SELECT id, name, email FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name";
$parents = $db->query($parents_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get all students
$students_sql = "SELECT u.id, u.name, u.email, sp.student_id, c.name as class_name, c.grade_level
FROM users u
JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
WHERE u.role = 'student' AND u.status = 'active'
ORDER BY u.name";
$students = $db->query($students_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get existing relationships
$relationships_sql = "SELECT 
    ps.id,
    ps.relationship,
    ps.is_primary,
    p.name as parent_name,
    p.email as parent_email,
    s.name as student_name,
    sp.student_id,
    c.name as class_name,
    c.grade_level
FROM parent_students ps
JOIN users p ON ps.parent_id = p.id
JOIN users s ON ps.student_id = s.id
JOIN student_profiles sp ON s.id = sp.user_id
LEFT JOIN student_classes sc ON s.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
ORDER BY s.name, ps.is_primary DESC";
$relationships = $db->query($relationships_sql)->fetchAll(PDO::FETCH_ASSOC);

$title = "Parent-Student Relationships";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Parent-Student Relationships</h1>
                                <p class="text-blue-100 text-lg">Manage connections between parents and students</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-link text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../admin/" class="hover:text-blue-600 dark:hover:text-blue-400">Admin</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Parent-Student Links</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create New Relationship -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Create New Parent-Student Relationship</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Link an existing parent to an existing student</p>
                    </div>
                    <div class="p-6">
                        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <input type="hidden" name="action" value="create_link">
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Parent</label>
                                <select name="parent_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Choose Parent</option>
                                    <?php foreach ($parents as $parent): ?>
                                    <option value="<?php echo $parent['id']; ?>">
                                        <?php echo htmlspecialchars($parent['name'] . ' (' . $parent['email'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Student</label>
                                <select name="student_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Choose Student</option>
                                    <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name'] . ' (' . $student['student_id'] . ')'); ?>
                                        <?php if ($student['class_name']): ?>
                                        - Grade <?php echo $student['grade_level']; ?>
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Relationship</label>
                                <select name="relationship" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="guardian">Guardian</option>
                                    <option value="father">Father</option>
                                    <option value="mother">Mother</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="flex flex-col justify-end">
                                <div class="flex items-center mb-4">
                                    <input type="checkbox" name="is_primary" id="is_primary" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                    <label for="is_primary" class="ml-2 text-sm text-gray-700 dark:text-gray-300">Primary Parent</label>
                                </div>
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-link mr-2"></i>Create Link
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Existing Relationships -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Existing Parent-Student Relationships</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage current relationships between parents and students</p>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($relationships)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Parent</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Relationship</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Primary</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($relationships as $rel): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?php echo htmlspecialchars($rel['student_name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        ID: <?php echo htmlspecialchars($rel['student_id']); ?>
                                                        <?php if ($rel['class_name']): ?>
                                                        | Grade <?php echo htmlspecialchars($rel['grade_level']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($rel['parent_name']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                                <?php echo htmlspecialchars($rel['parent_email']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                <?php echo ucfirst($rel['relationship']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($rel['is_primary']): ?>
                                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                                <i class="fas fa-star mr-1"></i>Primary
                                            </span>
                                            <?php else: ?>
                                            <form method="POST" class="inline">
                                                <input type="hidden" name="action" value="update_primary">
                                                <input type="hidden" name="link_id" value="<?php echo $rel['id']; ?>">
                                                <input type="hidden" name="student_id" value="<?php echo $rel['student_id']; ?>">
                                                <button type="submit" class="text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                                    Make Primary
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                            <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this relationship?')">
                                                <input type="hidden" name="action" value="delete_link">
                                                <input type="hidden" name="link_id" value="<?php echo $rel['id']; ?>">
                                                <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300">
                                                    <i class="fas fa-trash mr-1"></i>Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-link text-4xl text-gray-400 mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Relationships Found</h3>
                            <p class="text-gray-600 dark:text-gray-400">Create the first parent-student relationship using the form above.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
