<?php
session_start();
// Only super admins and school admins may reset other users' passwords.
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/audit_log.php';
require_once '../includes/password_policy.php';
$database = new Database();
$db = $database->getConnection();

$role = $_SESSION['role'];
$is_super = $role === 'super_admin';
$current_school = $_SESSION['school_id'] ?? null;

// In the main multi-tenant DB, users carry a school_id; in an isolated per-school
// tenant DB they do not (the database itself is the school boundary).
$has_school_col = dbHasColumn($db, 'users', 'school_id');
$scope_by_school = !$is_super && $has_school_col;

$success = '';
$error = '';
$generated_note = '';

/**
 * Fetch a target user, enforcing that the current admin is allowed to act on them.
 * Returns the row or null.
 */
if (!function_exists('fetchTargetUser')):
function fetchTargetUser($db, $user_id, $is_super, $current_school, $has_school_col) {
    $cols = "id, name, email, role" . ($has_school_col ? ", school_id" : "");
    $stmt = $db->prepare("SELECT $cols FROM users WHERE id = :id");
    $stmt->execute([':id' => $user_id]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    // School admins may only touch users in their own school and never super admins.
    if (!$is_super) {
        if ($has_school_col && (int)$u['school_id'] !== (int)$current_school) return null;
        if ($u['role'] === 'super_admin') return null;
    }
    return $u;
}
endif;

// ---------------------------------------------------------------------------
// Handle password reset submission
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['reset_password'])) {
    $target_id = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $target = $target_id ? fetchTargetUser($db, $target_id, $is_super, $current_school, $has_school_col) : null;

    if (!$target) {
        $error = "You are not authorized to reset that user's password, or the user does not exist.";
    } elseif ((int)$target['id'] === (int)$_SESSION['user_id']) {
        $error = "Use your profile page to change your own password.";
    } elseif (($pw_err = passwordPolicyError($new_password)) !== '') {
        $error = $pw_err;
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            $upd = $db->prepare("UPDATE users SET password = :pw WHERE id = :id");
            $upd->execute([':pw' => $hash, ':id' => $target['id']]);

            logAudit($db, 'password_reset', "Reset password for {$target['name']} ({$target['email']}, role: {$target['role']}).");

            $success = "Password for <strong>" . htmlspecialchars($target['name']) . "</strong> has been reset successfully.";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------------------------
// User search
// ---------------------------------------------------------------------------
$search = trim(filter_input(INPUT_GET, 'q', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$results = [];
if ($search !== '') {
    $where = ["(u.name LIKE :q OR u.email LIKE :q OR u.role LIKE :q)"];
    $params = [':q' => "%$search%"];
    if (!$is_super) {
        if ($scope_by_school) {
            $where[] = "u.school_id = :sid";
            $params[':sid'] = $current_school;
        }
        $where[] = "u.role <> 'super_admin'";
    }
    $where[] = "u.id <> :self";
    $params[':self'] = $_SESSION['user_id'];

    $sql = "SELECT u.id, u.name, u.email, u.role, u.status
            FROM users u
            WHERE " . implode(' AND ', $where) . "
            ORDER BY u.name ASC
            LIMIT 30";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Reset User Password";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-5xl mx-auto">
                <!-- Header -->
                <div class="bg-gradient-to-r from-red-600 via-red-700 to-orange-600 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2"><i class="fas fa-key mr-3"></i>Reset User Password</h1>
                            <p class="text-red-100">Search for a user and set a new password for their account</p>
                        </div>
                        <?php if ($is_super): ?>
                        <a href="security.php" class="bg-white/10 hover:bg-white/20 border border-white/20 px-4 py-2 rounded-lg text-sm flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i>Security Center
                        </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-start">
                    <i class="fas fa-check-circle mr-2 mt-0.5"></i><div><?php echo $success; ?></div>
                </div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start">
                    <i class="fas fa-exclamation-circle mr-2 mt-0.5"></i><div><?php echo htmlspecialchars($error); ?></div>
                </div>
                <?php endif; ?>

                <!-- Search -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-1">Find a User</h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Search by name, email, or role<?php echo $is_super ? '' : ' (limited to your school)'; ?>.</p>
                    <form method="GET" class="flex gap-3">
                        <div class="relative flex-1">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($search); ?>" autofocus
                                   placeholder="Ebenezer Owusu, ebenezer@example.com, teacher"
                                   class="w-full pl-9 pr-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-red-500 focus:outline-none">
                        </div>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-6 py-2.5 rounded-lg text-sm font-medium flex items-center">
                            <i class="fas fa-search mr-2"></i>Search
                        </button>
                    </form>
                </div>

                <!-- Results -->
                <?php if ($search !== ''): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white"><?php echo count($results); ?> result<?php echo count($results) === 1 ? '' : 's'; ?> for "<?php echo htmlspecialchars($search); ?>"</h2>
                    </div>
                    <?php if (empty($results)): ?>
                    <div class="p-10 text-center text-gray-500 dark:text-gray-400">
                        <i class="fas fa-user-slash text-4xl mb-3 text-gray-300 dark:text-gray-600"></i>
                        <p>No matching users found.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">User</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Role</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($results as $u): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($u['name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($u['email']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars(function_exists('formatRoleName') ? formatRoleName($u['role']) : $u['role']); ?></td>
                                    <td class="px-6 py-4">
                                        <span class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full <?php echo $u['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'; ?>">
                                            <?php echo ucfirst($u['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <button type="button"
                                            onclick="openResetModal(<?php echo (int)$u['id']; ?>, '<?php echo htmlspecialchars(addslashes($u['name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($u['email']), ENT_QUOTES); ?>')"
                                            class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                                            <i class="fas fa-key mr-1"></i>Reset Password
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-xl p-6 text-sm text-blue-800 dark:text-blue-200 flex items-start">
                    <i class="fas fa-info-circle mr-3 mt-0.5"></i>
                    <div>Start by searching for the user whose password you want to reset. For security, every reset is recorded in the activity logs.</div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0"><?php include '../includes/footer.php'; ?></div>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetModal" class="fixed inset-0 bg-black/50 hidden z-50 items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-md">
        <form method="POST" id="resetForm" class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><i class="fas fa-key mr-2 text-red-600"></i>Reset Password</h3>
                <button type="button" onclick="closeResetModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" name="user_id" id="modalUserId">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 mb-4 text-sm">
                <div class="text-gray-500 dark:text-gray-400">Resetting password for</div>
                <div class="font-semibold text-gray-900 dark:text-white" id="modalUserName"></div>
                <div class="text-xs text-gray-400" id="modalUserEmail"></div>
            </div>
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Password</label>
                    <div class="relative">
                        <input type="text" name="new_password" id="newPassword" minlength="6" required
                               class="w-full px-3 py-2 pr-24 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-red-500 focus:outline-none"
                               placeholder="At least 6 characters">
                        <button type="button" onclick="generatePassword()" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 dark:hover:bg-gray-500 text-gray-700 dark:text-gray-200 px-2 py-1 rounded">
                            <i class="fas fa-dice mr-1"></i>Generate
                        </button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm Password</label>
                    <input type="text" name="confirm_password" id="confirmPassword" minlength="6" required
                           class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-red-500 focus:outline-none"
                           placeholder="Re-enter the password">
                </div>
                <p class="text-xs text-gray-400">Share the new password with the user securely. They should change it after logging in.</p>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button type="button" onclick="closeResetModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">Cancel</button>
                <button type="submit" name="reset_password" class="px-5 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-2"></i>Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, name, email) {
    document.getElementById('modalUserId').value = id;
    document.getElementById('modalUserName').textContent = name;
    document.getElementById('modalUserEmail').textContent = email;
    document.getElementById('newPassword').value = '';
    document.getElementById('confirmPassword').value = '';
    const m = document.getElementById('resetModal');
    m.classList.remove('hidden');
    m.classList.add('flex');
}
function closeResetModal() {
    const m = document.getElementById('resetModal');
    m.classList.add('hidden');
    m.classList.remove('flex');
}
function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%';
    let pw = '';
    const arr = new Uint32Array(12);
    (window.crypto || window.msCrypto).getRandomValues(arr);
    for (let i = 0; i < 12; i++) { pw += chars[arr[i] % chars.length]; }
    document.getElementById('newPassword').value = pw;
    document.getElementById('confirmPassword').value = pw;
}
// Close on backdrop click
document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) closeResetModal();
});
</script>
