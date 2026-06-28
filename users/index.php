<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('users');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle user status toggle
if (isset($_POST['toggle_status']) && isset($_POST['user_id'])) {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    header("Location: index.php");
    exit();
}

// Handle user deletion
if (isset($_POST['delete_user']) && isset($_POST['user_id'])) {
    $user_id = filter_input(INPUT_POST, 'user_id', FILTER_SANITIZE_NUMBER_INT);

    // Prevent deletion of current user
    if ($user_id != $_SESSION['user_id']) {
        try {
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
            $query = "DELETE FROM users WHERE id = :user_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            $db->commit();
            $_SESSION['delete_success'] = "User deleted successfully.";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $_SESSION['delete_error'] = "Error deleting user: " . $e->getMessage();
        }
    } else {
        $_SESSION['delete_error'] = "You cannot delete your own account.";
    }

    header("Location: index.php");
    exit();
}

// Fetch users with pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit = isset($_GET['per_page']) ? max(5, min(50, intval($_GET['per_page']))) : 15; // Allow 5-50 per page, default 15
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? $_GET['search'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(name LIKE :search OR email LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($role_filter) {
    $where_conditions[] = "role = :role";
    $params[':role'] = $role_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total users
$count_query = "SELECT COUNT(*) as total FROM users $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_users = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_users / $limit);

// Fetch users
$query = "SELECT id, name, email, role, status, created_at, profile_picture FROM users $where_clause 
          ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php
$title = "User Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'User Management']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">User Management</h1>
                                <p class="text-blue-100 text-lg">Manage system users, roles, and permissions</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        Total Users: <?php echo $total_users; ?>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-alt mr-2"></i>
                                        <?php echo date('F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-users text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Result Messages -->
                <?php if (isset($_GET['success'])): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($_GET['success']); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Delete Messages -->
                <?php if (isset($_SESSION['delete_success'])): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($_SESSION['delete_success']); ?>
                    </div>
                </div>
                <?php unset($_SESSION['delete_success']); ?>
                <?php endif; ?>

                <?php if (isset($_SESSION['delete_error'])): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                    <div class="flex items-center">
                        <i class="fas fa-times-circle mr-2"></i>
                        <?php echo htmlspecialchars($_SESSION['delete_error']); ?>
                    </div>
                </div>
                <?php unset($_SESSION['delete_error']); ?>
                <?php endif; ?>

                <!-- Action Buttons -->
                <div class="grid grid-cols-2 gap-3 mb-6 md:flex md:items-center md:space-x-3">
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin'], true)): ?>
                    <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center text-center">
                        <i class="fas fa-user-plus mr-2"></i>Create User
                    </a>
                    <?php endif; ?>
                    <a href="bulk_import.php" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center text-center">
                        <i class="fas fa-upload mr-2"></i>Bulk Import
                    </a>
                    <a href="check_parents.php" class="bg-purple-500 hover:bg-purple-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center text-center">
                        <i class="fas fa-users-cog mr-2"></i>Check User Roles
                    </a>
                    <a href="delete.php" class="bg-red-600 hover:bg-red-700 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center text-center">
                        <i class="fas fa-trash-alt mr-2"></i>Delete Users
                    </a>
                    <div class="relative w-full md:w-auto md:ml-auto">
                        <button onclick="toggleExportDropdown()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg flex items-center justify-center w-full" id="exportButton">
                            <i class="fas fa-download mr-2"></i>Export All Users
                            <i class="fas fa-chevron-down ml-2"></i>
                        </button>
                        <div id="exportDropdown" class="hidden absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-md shadow-lg z-50 border border-gray-200 dark:border-gray-700">
                            <div class="py-1">
                                <button onclick="exportAllUsers('csv')" class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center">
                                    <i class="fas fa-file-csv mr-2 text-green-500"></i>Export as CSV
                                </button>
                                <button onclick="exportAllUsers('excel')" class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center">
                                    <i class="fas fa-file-excel mr-2 text-green-600"></i>Export as Excel
                                </button>
                                <button onclick="exportAllUsers('json')" class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 flex items-center">
                                    <i class="fas fa-file-code mr-2 text-blue-500"></i>Export as JSON
                                </button>
                                <hr class="my-1 border-gray-200 dark:border-gray-600">
                                <div class="px-4 py-2 text-xs text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-info-circle mr-1"></i>Exports all users (<?php echo $total_users; ?> total)
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Search Users</label>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                    placeholder="Search by name or email..."
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Filter by Role</label>
                                <select name="role" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Roles</option>
                                    <?php
                                    $roles = ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor', 'hr'];
                                    foreach ($roles as $role) {
                                        $selected = $role_filter === $role ? 'selected' : '';
                                        echo "<option value=\"$role\" $selected>" . formatRoleName($role) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Per Page</label>
                                <select name="per_page" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php
                                    $per_page_options = [5, 10, 15, 25, 50];
                                    $current_per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 15;
                                    foreach ($per_page_options as $option) {
                                        $selected = $current_per_page === $option ? 'selected' : '';
                                        echo "<option value=\"$option\" $selected>$option per page</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="flex items-end space-x-2">
                                <button type="submit" class="flex-1 bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center">
                                    <i class="fas fa-search mr-2"></i>Filter
                                </button>
                                <?php if ($search || $role_filter): ?>
                                <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 flex items-center justify-center" title="Clear Filters">
                                    <i class="fas fa-times"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex flex-col sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Users</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Manage all users in the system</p>
                        </div>
                        <div class="mt-3 sm:mt-0 flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400">
                            <i class="fas fa-users"></i>
                            <span>Showing <?php echo min($limit, $total_users - $offset); ?> of <?php echo $total_users; ?> users</span>
                        </div>
                    </div>

                    <?php if (empty($users)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-users text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No users found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-4">No users match your filters.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto table-container">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700 sticky top-0 z-10">
                                <tr>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider min-w-[200px]">User</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider min-w-[120px]">Role</th>
                                    <th class="px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider min-w-[80px]">Status</th>
                                    <th class="hidden md:table-cell px-3 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider min-w-[100px]">Created</th>
                                    <th class="px-3 sm:px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider min-w-[120px]">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($users as $user): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-3 sm:px-6 py-4">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 sm:w-10 sm:h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm overflow-hidden">
                                                <?php if(!empty($user['profile_picture'])): ?>
                                                    <img src="/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($user['profile_picture']); ?>" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                    <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                                <?php endif; ?>
                                            </div>
                                            <div class="ml-3 sm:ml-4 min-w-0 flex-1">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($user['name']); ?></div>
                                                <div class="text-xs sm:text-sm text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($user['email']); ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                            <?php
                                            switch($user['role']) {
                                                case 'super_admin': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                case 'school_admin': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'; break;
                                                case 'principal': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'; break;
                                                case 'teacher': echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                                case 'student': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'parent': echo 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200'; break;
                                                case 'librarian': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                case 'accountant': echo 'bg-pink-100 text-pink-800 dark:bg-pink-900 dark:text-pink-200'; break;
                                                case 'transport_officer': echo 'bg-cyan-100 text-cyan-800 dark:bg-cyan-900 dark:text-cyan-200'; break;
                                                case 'hostel_warden': echo 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-200'; break;
                                                case 'canteen_manager': echo 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200'; break;
                                                case 'nurse': echo 'bg-rose-100 text-rose-800 dark:bg-rose-900 dark:text-rose-200'; break;
                                                case 'counselor': echo 'bg-violet-100 text-violet-800 dark:bg-violet-900 dark:text-violet-200'; break;
                                                case 'hr': echo 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200'; break;
                                                default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'; break;
                                            }
                                            ?>">
                                            <i class="fas fa-<?php
                                            switch($user['role']) {
                                                case 'super_admin': echo 'crown'; break;
                                                case 'school_admin': echo 'user-shield'; break;
                                                case 'principal': echo 'user-tie'; break;
                                                case 'teacher': echo 'chalkboard-teacher'; break;
                                                case 'student': echo 'user-graduate'; break;
                                                case 'parent': echo 'users'; break;
                                                case 'librarian': echo 'book'; break;
                                                case 'accountant': echo 'calculator'; break;
                                                case 'transport_officer': echo 'bus'; break;
                                                case 'hostel_warden': echo 'building'; break;
                                                case 'canteen_manager': echo 'utensils'; break;
                                                case 'nurse': echo 'user-md'; break;
                                                case 'counselor': echo 'comments'; break;
                                                case 'hr': echo 'id-card'; break;
                                                default: echo 'user'; break;
                                            }
                                            ?> mr-1 hidden sm:inline"></i>
                                            <span class="hidden sm:inline"><?php echo formatRoleName($user['role']); ?></span>
                                            <span class="sm:hidden"><?php echo htmlspecialchars(substr(formatRoleName($user['role']), 0, 8)); ?></span>
                                        </span>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                            <?php echo $user['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                            <div class="w-2 h-2 rounded-full mr-1 <?php echo $user['status'] === 'active' ? 'bg-green-400' : 'bg-red-400'; ?>"></div>
                                            <span class="hidden sm:inline"><?php echo ucfirst($user['status']); ?></span>
                                            <span class="sm:hidden"><?php echo $user['status'] === 'active' ? '✓' : '✗'; ?></span>
                                        </span>
                                    </td>
                                    <td class="hidden md:table-cell px-3 sm:px-6 py-4 text-sm text-gray-500 dark:text-gray-400">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-alt mr-2 hidden sm:inline"></i>
                                            <span class="hidden lg:inline"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></span>
                                            <span class="lg:hidden"><?php echo date('m/d/y', strtotime($user['created_at'])); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-3 sm:px-6 py-4 text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-1 sm:space-x-2">
                                            <a href="edit.php?id=<?php echo $user['id']; ?>"
                                                class="inline-flex items-center px-2 sm:px-3 py-1 border border-transparent text-xs sm:text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800 transition-colors duration-200"
                                                title="Edit User">
                                                <i class="fas fa-edit sm:mr-1"></i>
                                                <span class="hidden sm:inline">Edit</span>
                                            </a>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="toggle_status"
                                                    class="inline-flex items-center px-2 sm:px-3 py-1 border border-transparent text-xs sm:text-sm leading-4 font-medium rounded-md
                                                    <?php echo $user['status'] === 'active' ? 'text-red-700 bg-red-100 hover:bg-red-200 dark:bg-red-900 dark:text-red-200 dark:hover:bg-red-800' : 'text-green-700 bg-green-100 hover:bg-green-200 dark:bg-green-900 dark:text-green-200 dark:hover:bg-green-800'; ?>
                                                    transition-colors duration-200"
                                                    title="<?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> User">
                                                    <i class="fas fa-<?php echo $user['status'] === 'active' ? 'ban' : 'check'; ?> sm:mr-1"></i>
                                                    <span class="hidden sm:inline"><?php echo $user['status'] === 'active' ? 'Deactivate' : 'Activate'; ?></span>
                                                </button>
                                            </form>
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                            <form action="" method="POST" class="inline" onsubmit="return confirmDelete('<?php echo htmlspecialchars($user['name']); ?>')">
                                                <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                <button type="submit" name="delete_user"
                                                    class="inline-flex items-center px-2 sm:px-3 py-1 border border-transparent text-xs sm:text-sm leading-4 font-medium rounded-md text-red-700 bg-red-100 hover:bg-red-200 dark:bg-red-900 dark:text-red-200 dark:hover:bg-red-800 transition-colors duration-200 ml-1"
                                                    title="Delete User">
                                                    <i class="fas fa-trash sm:mr-1"></i>
                                                    <span class="hidden sm:inline">Delete</span>
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="bg-white dark:bg-gray-800 px-6 py-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-700 pagination-container">
                        <div class="flex-1 flex justify-between sm:hidden">
                            <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $role_filter ? "&role=$role_filter" : ''; ?><?php echo isset($_GET['per_page']) ? "&per_page=".$_GET['per_page'] : ''; ?>"
                                class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                <i class="fas fa-chevron-left mr-2"></i>Previous
                            </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $role_filter ? "&role=$role_filter" : ''; ?><?php echo isset($_GET['per_page']) ? "&per_page=".$_GET['per_page'] : ''; ?>"
                                class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                                Next<i class="fas fa-chevron-right ml-2"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                            <div class="pagination-info">
                                <p class="text-sm text-gray-700 dark:text-gray-300">
                                    Showing
                                    <span class="font-medium"><?php echo $offset + 1; ?></span>
                                    to
                                    <span class="font-medium"><?php echo min($offset + $limit, $total_users); ?></span>
                                    of
                                    <span class="font-medium"><?php echo $total_users; ?></span>
                                    results
                                </p>
                            </div>
                            <div>
                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                                    <?php
                                    // Smart pagination - show limited page numbers
                                    $start_page = max(1, $page - 2);
                                    $end_page = min($total_pages, $page + 2);

                                    if ($start_page > 1): ?>
                                        <a href="?page=1<?php echo $search ? "&search=$search" : ''; ?><?php echo $role_filter ? "&role=$role_filter" : ''; ?><?php echo isset($_GET['per_page']) ? "&per_page=".$_GET['per_page'] : ''; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                            1
                                        </a>
                                        <?php if ($start_page > 2): ?>
                                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $role_filter ? "&role=$role_filter" : ''; ?><?php echo isset($_GET['per_page']) ? "&per_page=".$_GET['per_page'] : ''; ?>"
                                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200
                                        <?php echo $i === $page ? 'z-10 bg-blue-50 dark:bg-blue-900 border-blue-500 text-blue-600 dark:text-blue-200' : ''; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                    <?php endfor; ?>

                                    <?php if ($end_page < $total_pages): ?>
                                        <?php if ($end_page < $total_pages - 1): ?>
                                            <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300">...</span>
                                        <?php endif; ?>
                                        <a href="?page=<?php echo $total_pages; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $role_filter ? "&role=$role_filter" : ''; ?><?php echo isset($_GET['per_page']) ? "&per_page=".$_GET['per_page'] : ''; ?>"
                                            class="relative inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                            <?php echo $total_pages; ?>
                                        </a>
                                    <?php endif; ?>
                                </nav>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function toggleExportDropdown() {
    const dropdown = document.getElementById('exportDropdown');
    dropdown.classList.toggle('hidden');

    // Close dropdown when clicking outside
    document.addEventListener('click', function closeDropdown(e) {
        if (!e.target.closest('#exportButton') && !e.target.closest('#exportDropdown')) {
            dropdown.classList.add('hidden');
            document.removeEventListener('click', closeDropdown);
        }
    });
}

function exportAllUsers(format) {
    // Hide dropdown
    document.getElementById('exportDropdown').classList.add('hidden');

    // Show loading state
    const exportButton = document.getElementById('exportButton');
    const originalText = exportButton.innerHTML;
    exportButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Exporting...';
    exportButton.disabled = true;

    // Get current filters
    const urlParams = new URLSearchParams(window.location.search);
    const search = urlParams.get('search') || '';
    const role = urlParams.get('role') || '';

    // Build export URL with current filters
    let exportUrl = `export.php?format=${format}`;
    if (search) exportUrl += `&search=${encodeURIComponent(search)}`;
    if (role) exportUrl += `&role=${encodeURIComponent(role)}`;

    // Create temporary link and trigger download
    const link = document.createElement('a');
    link.href = exportUrl;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);

    // Reset button state after a short delay
    setTimeout(() => {
        exportButton.innerHTML = originalText;
        exportButton.disabled = false;

        // Show success message
        showNotification(`All users exported successfully as ${format.toUpperCase()}!`, 'success');
    }, 1500);
}

function showNotification(message, type = 'info') {
    // Create notification element
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 px-6 py-3 rounded-lg shadow-lg transition-all duration-300 transform translate-x-full`;

    // Set notification style based on type
    switch(type) {
        case 'success':
            notification.className += ' bg-green-500 text-white';
            break;
        case 'error':
            notification.className += ' bg-red-500 text-white';
            break;
        case 'warning':
            notification.className += ' bg-yellow-500 text-black';
            break;
        default:
            notification.className += ' bg-blue-500 text-white';
    }

    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas fa-${type === 'success' ? 'check' : type === 'error' ? 'times' : type === 'warning' ? 'exclamation' : 'info'}-circle mr-2"></i>
            <span>${message}</span>
        </div>
    `;

    document.body.appendChild(notification);

    // Animate in
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);

    // Auto remove after 3 seconds
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 300);
    }, 3000);
}

// Auto-submit form when per_page changes
document.addEventListener('DOMContentLoaded', function() {
    const perPageSelect = document.querySelector('select[name="per_page"]');
    if (perPageSelect) {
        perPageSelect.addEventListener('change', function() {
            this.form.submit();
        });
    }
});

// Confirm user deletion
function confirmDelete(userName) {
    return confirm(`Are you sure you want to delete the user "${userName}"?\n\nThis action cannot be undone and will permanently remove this user from the system.`);
}
</script>

<style>
/* Custom styles for better table responsiveness */
@media (max-width: 768px) {
    .table-container {
        font-size: 0.875rem;
    }

    .table-container table {
        min-width: 600px;
    }

    .table-container .truncate {
        max-width: 120px;
    }
}

@media (max-width: 640px) {
    .table-container {
        font-size: 0.8rem;
    }

    .table-container .truncate {
        max-width: 100px;
    }

    .pagination-container {
        flex-direction: column;
        gap: 1rem;
    }

    .pagination-container .pagination-info {
        text-align: center;
    }
}

/* Improved scrollbar for table */
.table-container::-webkit-scrollbar {
    height: 8px;
}

.table-container::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 4px;
}

.table-container::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Loading state for better UX */
.table-loading {
    opacity: 0.6;
    pointer-events: none;
}
</style>