<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get all users with their roles
$query = "SELECT id, name, email, role, status, created_at FROM users ORDER BY role, name";
$stmt = $db->prepare($query);
$stmt->execute();
$all_users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group users by role
$users_by_role = [];
foreach ($all_users as $user) {
    $users_by_role[$user['role']][] = $user;
}

// Get parent count specifically
$parent_query = "SELECT COUNT(*) as count FROM users WHERE role = 'parent'";
$parent_stmt = $db->prepare($parent_query);
$parent_stmt->execute();
$parent_count = $parent_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Get users without roles (NULL or empty)
$no_role_query = "SELECT id, name, email, created_at FROM users WHERE role IS NULL OR role = '' ORDER BY created_at DESC LIMIT 10";
$no_role_stmt = $db->prepare($no_role_query);
$no_role_stmt->execute();
$users_without_roles = $no_role_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count users without roles
$no_role_count_query = "SELECT COUNT(*) as count FROM users WHERE role IS NULL OR role = ''";
$no_role_count_stmt = $db->prepare($no_role_count_query);
$no_role_count_stmt->execute();
$no_role_total = $no_role_count_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$title = "User Role Diagnostic";
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">User Role Diagnostic</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Check all users and their roles in the system</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Users
                    </a>
                </div>

                <!-- Summary Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Users</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo count($all_users); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-friends text-indigo-600 dark:text-indigo-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Parent Users</h3>
                                <p class="text-2xl font-bold text-indigo-600 dark:text-indigo-400"><?php echo $parent_count; ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-layer-group text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Roles</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo count($users_by_role); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-slash text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">No Role Assigned</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400"><?php echo $no_role_total; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Users by Role -->
                <div class="space-y-6">
                    <?php foreach ($users_by_role as $role => $users): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    <i class="fas fa-<?php
                                    switch($role) {
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
                                    ?> mr-2"></i>
                                    <?php echo htmlspecialchars(formatRoleName($role)); ?>
                                </h3>
                                <span class="bg-gray-100 dark:bg-gray-700 text-gray-800 dark:text-gray-200 px-3 py-1 rounded-full text-sm font-medium">
                                    <?php echo count($users); ?> user<?php echo count($users) !== 1 ? 's' : ''; ?>
                                </span>
                            </div>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                <?php foreach ($users as $user): ?>
                                <div class="flex items-center p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div class="w-8 h-8 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold text-sm">
                                        <?php echo strtoupper(substr($user['name'], 0, 1)); ?>
                                    </div>
                                    <div class="ml-3 min-w-0 flex-1">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white truncate">
                                            <?php echo htmlspecialchars($user['name']); ?>
                                        </div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400 truncate">
                                            <?php echo htmlspecialchars($user['email']); ?>
                                        </div>
                                    </div>
                                    <div class="ml-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium
                                            <?php echo $user['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                            <?php echo $user['status']; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Users Without Roles -->
                <?php if ($no_role_total > 0): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <h3 class="text-lg font-semibold text-red-600 dark:text-red-400">
                                <i class="fas fa-user-slash mr-2"></i>
                                Users Without Roles
                            </h3>
                            <span class="bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 px-3 py-1 rounded-full text-sm font-medium">
                                <?php echo $no_role_total; ?> user<?php echo $no_role_total !== 1 ? 's' : ''; ?>
                            </span>
                        </div>
                    </div>
                    <div class="p-6">
                        <div class="mb-4 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
                            <p class="text-sm text-red-700 dark:text-red-300">
                                <strong>Issue:</strong> These users don't have any role assigned. This typically happens when:
                            </p>
                            <ul class="text-sm text-red-700 dark:text-red-300 mt-2 list-disc list-inside">
                                <li>CSV import had incorrect role names or empty role columns</li>
                                <li>Manual user creation without role selection</li>
                                <li>Database corruption or migration issues</li>
                            </ul>
                        </div>

                        <?php if (!empty($users_without_roles)): ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">ID</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Name</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Email</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Created</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($users_without_roles as $user): ?>
                                    <tr>
                                        <td class="px-4 py-2 text-sm text-gray-900 dark:text-white"><?php echo $user['id']; ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($user['name']); ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td class="px-4 py-2 text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                                        <td class="px-4 py-2 text-sm">
                                            <a href="edit.php?id=<?php echo $user['id']; ?>" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($no_role_total > 10): ?>
                        <div class="mt-4 text-sm text-gray-600 dark:text-gray-400">
                            Showing first 10 of <?php echo $no_role_total; ?> users without roles.
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>

                        <div class="mt-4 flex space-x-3">
                            <a href="fix_roles.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm">
                                <i class="fas fa-tools mr-2"></i>Fix All Role Issues
                            </a>
                            <a href="index.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">
                                <i class="fas fa-users mr-2"></i>Manage Users
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($parent_count === 0): ?>
                <!-- No Parents Found -->
                <div class="mt-8 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-yellow-500 mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-sm font-medium text-yellow-900 dark:text-yellow-200">No Parent Users Found</h4>
                            <p class="text-sm text-yellow-700 dark:text-yellow-300 mt-1">
                                There are currently no users with the "parent" role in the system. You can:
                            </p>
                            <ul class="text-sm text-yellow-700 dark:text-yellow-300 mt-2 list-disc list-inside">
                                <li><a href="create.php" class="underline">Create a new parent user manually</a></li>
                                <li><a href="bulk_import.php" class="underline">Import parent users via CSV</a></li>
                                <li><a href="edit.php?id=<?php echo $all_users[0]['id'] ?? ''; ?>" class="underline">Edit an existing user to change their role to "parent"</a></li>
                            </ul>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
