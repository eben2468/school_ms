<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$messages = [];
$action = $_GET['action'] ?? '';
$user_id = $_GET['id'] ?? '';

// Handle individual user deletion
if ($action === 'delete_user' && $user_id) {
    try {
        // Prevent deletion of current user
        if ($user_id == $_SESSION['user_id']) {
            $messages[] = ['error', 'You cannot delete your own account!'];
        } else {
            // Get user details before deletion
            $user_query = "SELECT name, email, role FROM users WHERE id = :id";
            $user_stmt = $db->prepare($user_query);
            $user_stmt->bindParam(':id', $user_id);
            $user_stmt->execute();
            $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                $db->beginTransaction();

                // Handle foreign key constraints manually
                // Delete parent-student relationships
                $delete_parent_students = "DELETE FROM parent_students WHERE parent_id = :id OR student_id = :id";
                $stmt = $db->prepare($delete_parent_students);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student-class relationships
                $delete_student_classes = "DELETE FROM student_classes WHERE student_id = :id";
                $stmt = $db->prepare($delete_student_classes);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete class-teacher relationships
                $delete_class_teachers = "DELETE FROM class_teachers WHERE teacher_id = :id";
                $stmt = $db->prepare($delete_class_teachers);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student profiles
                $delete_student_profiles = "DELETE FROM student_profiles WHERE user_id = :id";
                $stmt = $db->prepare($delete_student_profiles);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete attendance records
                $delete_attendance = "DELETE FROM attendance WHERE student_id = :id OR created_by = :id";
                $stmt = $db->prepare($delete_attendance);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete the user
                $delete_query = "DELETE FROM users WHERE id = :id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':id', $user_id);
                $delete_stmt->execute();

                $db->commit();
                $messages[] = ['success', "User '{$user['name']}' ({$user['email']}) has been deleted successfully."];
            } else {
                $messages[] = ['error', 'User not found.'];
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messages[] = ['error', 'Error deleting user: ' . $e->getMessage()];
    }
}

// Handle bulk deletion by role
if ($action === 'delete_by_role' && isset($_POST['role']) && isset($_POST['confirm_delete'])) {
    try {
        $role = $_POST['role'];

        // Prevent deletion of super_admin and current user
        if ($role === 'super_admin') {
            $messages[] = ['error', 'Cannot delete super admin users for security reasons.'];
        } else {
            // Get users to be deleted (excluding current user)
            $users_query = "SELECT id FROM users WHERE role = :role AND id != :current_user_id";
            $users_stmt = $db->prepare($users_query);
            $users_stmt->bindParam(':role', $role);
            $users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
            $users_stmt->execute();
            $users_to_delete = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

            if (count($users_to_delete) > 0) {
                $db->beginTransaction();

                // Delete related records for each user
                foreach ($users_to_delete as $user_id) {
                    // Delete parent-student relationships
                    $delete_parent_students = "DELETE FROM parent_students WHERE parent_id = :id OR student_id = :id";
                    $stmt = $db->prepare($delete_parent_students);
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();

                    // Delete student-class relationships
                    $delete_student_classes = "DELETE FROM student_classes WHERE student_id = :id";
                    $stmt = $db->prepare($delete_student_classes);
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();

                    // Delete class-teacher relationships
                    $delete_class_teachers = "DELETE FROM class_teachers WHERE teacher_id = :id";
                    $stmt = $db->prepare($delete_class_teachers);
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();

                    // Delete student profiles
                    $delete_student_profiles = "DELETE FROM student_profiles WHERE user_id = :id";
                    $stmt = $db->prepare($delete_student_profiles);
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();

                    // Delete attendance records
                    $delete_attendance = "DELETE FROM attendance WHERE student_id = :id OR created_by = :id";
                    $stmt = $db->prepare($delete_attendance);
                    $stmt->bindParam(':id', $user_id);
                    $stmt->execute();
                }

                // Delete users with the specified role (excluding current user)
                $delete_query = "DELETE FROM users WHERE role = :role AND id != :current_user_id";
                $delete_stmt = $db->prepare($delete_query);
                $delete_stmt->bindParam(':role', $role);
                $delete_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
                $delete_stmt->execute();

                $deleted_count = $delete_stmt->rowCount();
                $db->commit();
                $messages[] = ['success', "Successfully deleted $deleted_count users with role '$role'."];
            } else {
                $messages[] = ['info', "No users found with role '$role' to delete."];
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messages[] = ['error', 'Error deleting users: ' . $e->getMessage()];
    }
}

// Handle deletion of test users
if ($action === 'delete_test_users' && isset($_POST['confirm_delete'])) {
    try {
        // Get test users to be deleted (excluding current user)
        $users_query = "SELECT id FROM users WHERE (name LIKE '%test%' OR email LIKE '%test%') AND id != :current_user_id";
        $users_stmt = $db->prepare($users_query);
        $users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $users_stmt->execute();
        $users_to_delete = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($users_to_delete) > 0) {
            $db->beginTransaction();

            // Delete related records for each user
            foreach ($users_to_delete as $user_id) {
                // Delete parent-student relationships
                $delete_parent_students = "DELETE FROM parent_students WHERE parent_id = :id OR student_id = :id";
                $stmt = $db->prepare($delete_parent_students);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student-class relationships
                $delete_student_classes = "DELETE FROM student_classes WHERE student_id = :id";
                $stmt = $db->prepare($delete_student_classes);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete class-teacher relationships
                $delete_class_teachers = "DELETE FROM class_teachers WHERE teacher_id = :id";
                $stmt = $db->prepare($delete_class_teachers);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student profiles
                $delete_student_profiles = "DELETE FROM student_profiles WHERE user_id = :id";
                $stmt = $db->prepare($delete_student_profiles);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete attendance records
                $delete_attendance = "DELETE FROM attendance WHERE student_id = :id OR created_by = :id";
                $stmt = $db->prepare($delete_attendance);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
            }

            $delete_query = "DELETE FROM users WHERE (name LIKE '%test%' OR email LIKE '%test%') AND id != :current_user_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
            $delete_stmt->execute();

            $deleted_count = $delete_stmt->rowCount();
            $db->commit();
            $messages[] = ['success', "Successfully deleted $deleted_count test users."];
        } else {
            $messages[] = ['info', "No test users found to delete."];
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messages[] = ['error', 'Error deleting test users: ' . $e->getMessage()];
    }
}

// Handle deletion of inactive users
if ($action === 'delete_inactive_users' && isset($_POST['confirm_delete'])) {
    try {
        // Get inactive users to be deleted (excluding current user)
        $users_query = "SELECT id FROM users WHERE status = 'inactive' AND id != :current_user_id";
        $users_stmt = $db->prepare($users_query);
        $users_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
        $users_stmt->execute();
        $users_to_delete = $users_stmt->fetchAll(PDO::FETCH_COLUMN);

        if (count($users_to_delete) > 0) {
            $db->beginTransaction();

            // Delete related records for each user
            foreach ($users_to_delete as $user_id) {
                // Delete parent-student relationships
                $delete_parent_students = "DELETE FROM parent_students WHERE parent_id = :id OR student_id = :id";
                $stmt = $db->prepare($delete_parent_students);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student-class relationships
                $delete_student_classes = "DELETE FROM student_classes WHERE student_id = :id";
                $stmt = $db->prepare($delete_student_classes);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete class-teacher relationships
                $delete_class_teachers = "DELETE FROM class_teachers WHERE teacher_id = :id";
                $stmt = $db->prepare($delete_class_teachers);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete student profiles
                $delete_student_profiles = "DELETE FROM student_profiles WHERE user_id = :id";
                $stmt = $db->prepare($delete_student_profiles);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();

                // Delete attendance records
                $delete_attendance = "DELETE FROM attendance WHERE student_id = :id OR created_by = :id";
                $stmt = $db->prepare($delete_attendance);
                $stmt->bindParam(':id', $user_id);
                $stmt->execute();
            }

            $delete_query = "DELETE FROM users WHERE status = 'inactive' AND id != :current_user_id";
            $delete_stmt = $db->prepare($delete_query);
            $delete_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
            $delete_stmt->execute();

            $deleted_count = $delete_stmt->rowCount();
            $db->commit();
            $messages[] = ['success', "Successfully deleted $deleted_count inactive users."];
        } else {
            $messages[] = ['info', "No inactive users found to delete."];
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messages[] = ['error', 'Error deleting inactive users: ' . $e->getMessage()];
    }
}

// Handle deletion of selected users
if ($action === 'delete_selected_users' && isset($_POST['confirm_delete']) && isset($_POST['selected_users'])) {
    try {
        $selected_users = $_POST['selected_users'];
        $user_ids = array_filter(explode(',', $selected_users), 'is_numeric');

        if (empty($user_ids)) {
            $messages[] = ['error', 'No valid users selected for deletion.'];
        } else {
            // Remove current user from selection if somehow included
            $user_ids = array_filter($user_ids, function($id) {
                return $id != $_SESSION['user_id'];
            });

            if (empty($user_ids)) {
                $messages[] = ['error', 'No valid users to delete (cannot delete your own account).'];
            } else {
                $db->beginTransaction();

                $deleted_count = 0;
                foreach ($user_ids as $user_id) {
                    // Get user details for logging
                    $user_query = "SELECT name, email, role FROM users WHERE id = :id";
                    $user_stmt = $db->prepare($user_query);
                    $user_stmt->bindParam(':id', $user_id);
                    $user_stmt->execute();
                    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user) {
                        // Delete related records for this user
                        // Delete parent-student relationships
                        $delete_parent_students = "DELETE FROM parent_students WHERE parent_id = :id OR student_id = :id";
                        $stmt = $db->prepare($delete_parent_students);
                        $stmt->bindParam(':id', $user_id);
                        $stmt->execute();

                        // Delete student-class relationships
                        $delete_student_classes = "DELETE FROM student_classes WHERE student_id = :id";
                        $stmt = $db->prepare($delete_student_classes);
                        $stmt->bindParam(':id', $user_id);
                        $stmt->execute();

                        // Delete class-teacher relationships
                        $delete_class_teachers = "DELETE FROM class_teachers WHERE teacher_id = :id";
                        $stmt = $db->prepare($delete_class_teachers);
                        $stmt->bindParam(':id', $user_id);
                        $stmt->execute();

                        // Delete student profiles
                        $delete_student_profiles = "DELETE FROM student_profiles WHERE user_id = :id";
                        $stmt = $db->prepare($delete_student_profiles);
                        $stmt->bindParam(':id', $user_id);
                        $stmt->execute();

                        // Delete attendance records
                        $delete_attendance = "DELETE FROM attendance WHERE student_id = :id OR created_by = :id";
                        $stmt = $db->prepare($delete_attendance);
                        $stmt->bindParam(':id', $user_id);
                        $stmt->execute();

                        // Delete the user
                        $delete_query = "DELETE FROM users WHERE id = :id";
                        $delete_stmt = $db->prepare($delete_query);
                        $delete_stmt->bindParam(':id', $user_id);
                        $delete_stmt->execute();

                        $deleted_count++;
                    }
                }

                $db->commit();
                $messages[] = ['success', "Successfully deleted {$deleted_count} selected users."];
            }
        }
    } catch (Exception $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $messages[] = ['error', 'Error deleting selected users: ' . $e->getMessage()];
    }
}

// Get role statistics
$role_stats_query = "SELECT role, COUNT(*) as count FROM users WHERE id != :current_user_id GROUP BY role ORDER BY count DESC";
$role_stats_stmt = $db->prepare($role_stats_query);
$role_stats_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$role_stats_stmt->execute();
$role_stats = $role_stats_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total users (excluding current user)
$total_query = "SELECT COUNT(*) as count FROM users WHERE id != :current_user_id";
$total_stmt = $db->prepare($total_query);
$total_stmt->bindParam(':current_user_id', $_SESSION['user_id']);
$total_stmt->execute();
$total_users = $total_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$title = "Delete Users";
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Delete Users</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage user deletion by role or individual users</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <!-- Messages -->
                <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $message[0] === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : ($message[0] === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : 'bg-blue-100 border border-blue-400 text-blue-700'); ?>">
                    <div class="flex items-start">
                        <i class="fas fa-<?php echo $message[0] === 'success' ? 'check-circle' : ($message[0] === 'error' ? 'times-circle' : 'info-circle'); ?> mt-1 mr-3"></i>
                        <?php echo htmlspecialchars($message[1]); ?>
                    </div>
                </div>
                <?php endforeach; ?>

                <!-- Warning -->
                <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-500 text-xl mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-2">⚠️ Danger Zone</h4>
                            <p class="text-sm text-red-700 dark:text-red-300 mb-3">
                                User deletion is permanent and cannot be undone. Please be very careful when using these tools.
                            </p>
                            <ul class="text-sm text-red-700 dark:text-red-300 list-disc list-inside space-y-1">
                                <li>Deleted users cannot be recovered</li>
                                <li>All associated data (profiles, assignments, etc.) may be affected</li>
                                <li>Super admin users cannot be deleted for security</li>
                                <li>You cannot delete your own account</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Current Statistics -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current User Statistics</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Total Users (excluding yourself): <?php echo $total_users; ?></p>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($role_stats as $stat): ?>
                            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="font-medium text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars(formatRoleName($stat['role'])); ?>
                                </span>
                                <span class="bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full text-sm">
                                    <?php echo $stat['count']; ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Enhanced Role-Based User Management -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-users-slash text-red-500 mr-2"></i>
                            Delete Users by Role
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Select a role to view and delete specific users</p>
                    </div>
                    <div class="p-6">
                        <!-- Role Selection -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Role to View Users</label>
                            <select id="roleSelector" class="w-full md:w-1/2 px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Choose a role...</option>
                                <?php foreach ($role_stats as $stat): ?>
                                    <?php if ($stat['role'] !== 'super_admin'): ?>
                                    <option value="<?php echo $stat['role']; ?>">
                                        <?php echo htmlspecialchars(formatRoleName($stat['role'])); ?> (<?php echo $stat['count']; ?> users)
                                    </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Users List Container -->
                        <div id="usersContainer" style="display: none;">
                            <div class="flex justify-between items-center mb-4">
                                <h4 class="text-lg font-semibold text-gray-900 dark:text-white">Users in Selected Role</h4>
                                <div class="flex space-x-3">
                                    <button type="button" id="selectAllBtn" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">
                                        <i class="fas fa-check-square mr-2"></i>Select All
                                    </button>
                                    <button type="button" id="deselectAllBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">
                                        <i class="fas fa-square mr-2"></i>Deselect All
                                    </button>
                                </div>
                            </div>

                            <div id="usersList" class="space-y-2 mb-6 max-h-96 overflow-y-auto border border-gray-200 dark:border-gray-600 rounded-lg p-4 bg-gray-50 dark:bg-gray-700">
                                <!-- Users will be loaded here via JavaScript -->
                            </div>

                            <!-- Delete Selected Users Form -->
                            <form method="POST" action="?action=delete_selected_users" onsubmit="return confirmSelectedDelete()">
                                <div class="flex flex-col md:flex-row gap-4 items-start md:items-end">
                                    <div class="flex-1">
                                        <label class="flex items-center">
                                            <input type="checkbox" name="confirm_delete" required class="mr-2 text-red-600 focus:ring-red-500">
                                            <span class="text-sm text-gray-700 dark:text-gray-300">I understand this action cannot be undone</span>
                                        </label>
                                    </div>
                                    <div>
                                        <button type="submit" id="deleteSelectedBtn" class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center" disabled>
                                            <i class="fas fa-trash-alt mr-2"></i>Delete Selected Users (<span id="selectedCount">0</span>)
                                        </button>
                                    </div>
                                </div>
                                <input type="hidden" id="selectedUsers" name="selected_users" value="">
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- Delete Test Users -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-flask text-purple-500 mr-2"></i>
                                Delete Test Users
                            </h3>
                        </div>
                        <div class="p-6">
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                Remove users with "test" in their name or email address.
                            </p>
                            <form method="POST" action="?action=delete_test_users" onsubmit="return confirm('Delete all test users?')">
                                <input type="hidden" name="confirm_delete" value="1">
                                <button type="submit" class="w-full bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-trash mr-2"></i>Delete Test Users
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Delete Inactive Users -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-user-slash text-orange-500 mr-2"></i>
                                Delete Inactive Users
                            </h3>
                        </div>
                        <div class="p-6">
                            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                                Remove all users with inactive status.
                            </p>
                            <form method="POST" action="?action=delete_inactive_users" onsubmit="return confirm('Delete all inactive users?')">
                                <input type="hidden" name="confirm_delete" value="1">
                                <button type="submit" class="w-full bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-trash mr-2"></i>Delete Inactive Users
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Individual User Management -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-user-minus text-blue-500 mr-2"></i>
                            Individual User Deletion
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">For individual user deletion, use the delete buttons in the main user list</p>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    Individual users can be deleted from the main users page using the red delete button next to each user.
                                </p>
                            </div>
                            <a href="index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center">
                                <i class="fas fa-users mr-2"></i>Go to User List
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

<script>
// Enhanced user deletion functionality
document.addEventListener('DOMContentLoaded', function() {
    const roleSelector = document.getElementById('roleSelector');
    const usersContainer = document.getElementById('usersContainer');
    const usersList = document.getElementById('usersList');
    const selectAllBtn = document.getElementById('selectAllBtn');
    const deselectAllBtn = document.getElementById('deselectAllBtn');
    const deleteSelectedBtn = document.getElementById('deleteSelectedBtn');
    const selectedUsersInput = document.getElementById('selectedUsers');
    const selectedCountSpan = document.getElementById('selectedCount');

    let selectedUsers = new Set();

    // Handle role selection
    roleSelector.addEventListener('change', function() {
        const selectedRole = this.value;

        if (!selectedRole) {
            usersContainer.style.display = 'none';
            return;
        }

        // Show loading
        usersList.innerHTML = '<div class="text-center py-8"><div class="inline-flex items-center px-4 py-2 bg-blue-100 dark:bg-blue-900 rounded-lg"><i class="fas fa-spinner fa-spin mr-2 text-blue-600 dark:text-blue-400"></i><span class="text-blue-800 dark:text-blue-200">Loading users...</span></div></div>';
        usersContainer.style.display = 'block';

        // Fetch users for the selected role
        fetch(`get_users_by_role.php?role=${encodeURIComponent(selectedRole)}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayUsers(data.users, data.role);
                } else {
                    usersList.innerHTML = '<div class="text-center py-4 text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Error loading users: ' + (data.error || 'Unknown error') + '</div>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                usersList.innerHTML = '<div class="text-center py-4 text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Error loading users</div>';
            });
    });

    // Display users in the list
    function displayUsers(users, role) {
        selectedUsers.clear();
        updateSelectedCount();

        if (users.length === 0) {
            usersList.innerHTML = '<div class="text-center py-4 text-gray-500"><i class="fas fa-info-circle mr-2"></i>No users found with this role</div>';
            return;
        }

        let html = '';
        users.forEach(user => {
            const statusBadge = user.status === 'active'
                ? '<span class="bg-green-100 text-green-800 px-2 py-1 rounded-full text-xs">Active</span>'
                : '<span class="bg-red-100 text-red-800 px-2 py-1 rounded-full text-xs">Inactive</span>';

            const createdDate = new Date(user.created_at).toLocaleDateString();

            html += `
                <div class="flex items-center justify-between p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-600 hover:bg-gray-50 dark:hover:bg-gray-700 transition-all duration-200 shadow-sm hover:shadow-md">
                    <div class="flex items-center space-x-4">
                        <input type="checkbox" class="user-checkbox w-4 h-4 text-red-600 focus:ring-red-500 border-gray-300 rounded" data-user-id="${user.id}" data-user-name="${user.name}" data-user-email="${user.email}">
                        <div class="flex-1">
                            <div class="flex items-center space-x-3 mb-1">
                                <span class="font-semibold text-gray-900 dark:text-white">${user.name}</span>
                                ${statusBadge}
                            </div>
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                <i class="fas fa-envelope mr-1"></i>${user.email}
                            </div>
                            <div class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                <i class="fas fa-calendar-plus mr-1"></i>Created: ${createdDate}
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-user text-gray-400 dark:text-gray-500"></i>
                    </div>
                </div>
            `;
        });

        usersList.innerHTML = html;

        // Add event listeners to checkboxes
        document.querySelectorAll('.user-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                const userId = this.dataset.userId;
                if (this.checked) {
                    selectedUsers.add(userId);
                } else {
                    selectedUsers.delete(userId);
                }
                updateSelectedCount();
            });
        });
    }

    // Select all users
    selectAllBtn.addEventListener('click', function() {
        document.querySelectorAll('.user-checkbox').forEach(checkbox => {
            checkbox.checked = true;
            selectedUsers.add(checkbox.dataset.userId);
        });
        updateSelectedCount();
    });

    // Deselect all users
    deselectAllBtn.addEventListener('click', function() {
        document.querySelectorAll('.user-checkbox').forEach(checkbox => {
            checkbox.checked = false;
        });
        selectedUsers.clear();
        updateSelectedCount();
    });

    // Update selected count and button state
    function updateSelectedCount() {
        const count = selectedUsers.size;
        selectedCountSpan.textContent = count;
        selectedUsersInput.value = Array.from(selectedUsers).join(',');
        deleteSelectedBtn.disabled = count === 0;

        if (count === 0) {
            deleteSelectedBtn.classList.add('opacity-50', 'cursor-not-allowed');
        } else {
            deleteSelectedBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }
});

// Confirmation function for selected users deletion
function confirmSelectedDelete() {
    const selectedCount = document.getElementById('selectedCount').textContent;
    const selectedUsersValue = document.getElementById('selectedUsers').value;

    if (!selectedUsersValue || selectedCount === '0') {
        alert('Please select at least one user to delete.');
        return false;
    }

    return confirm(`Are you absolutely sure you want to delete ${selectedCount} selected user(s)?\n\nThis action cannot be undone and will permanently remove these users from the system.`);
}

function confirmBulkDelete() {
    const role = document.querySelector('select[name="role"]').value;
    const roleText = document.querySelector('select[name="role"] option:checked').textContent;

    if (!role) {
        alert('Please select a role first.');
        return false;
    }

    return confirm(`Are you absolutely sure you want to delete ALL users with the role "${roleText}"?\n\nThis action cannot be undone and will permanently remove these users from the system.`);
}
</script>
