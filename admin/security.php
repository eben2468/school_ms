<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/audit_log.php';
$database = new Database();
$db = $database->getConnection();

$role = $_SESSION['role'];
$is_super = $role === 'super_admin';
$current_school = $_SESSION['school_id'] ?? null;

$message = '';
$error = '';

// Schema awareness: the main multi-tenant DB has school_id columns and an
// audit_logs table; isolated per-school tenant DBs may have neither.
$has_school_col  = dbHasColumn($db, 'users', 'school_id');
$scope_by_school = !$is_super && $has_school_col;          // scope users by school_id?
$has_audit       = dbHasTable($db, 'audit_logs');
$audit_has_sid   = $has_audit && dbHasColumn($db, 'audit_logs', 'school_id');
$scope_audit     = !$is_super && $audit_has_sid;

$user_scope  = $scope_by_school ? ' AND school_id = :sid ' : '';
$scope_param = $scope_by_school ? [':sid' => $current_school] : [];

// ---------------------------------------------------------------------------
// Toggle account status (enable / disable a user account)
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['toggle_status'])) {
    $tid = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $cols = "id, name, role, status" . ($has_school_col ? ", school_id" : "");
    $stmt = $db->prepare("SELECT $cols FROM users WHERE id = :id");
    $stmt->execute([':id' => $tid]);
    $target = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$target) {
        $error = "User not found.";
    } elseif ((int)$target['id'] === (int)$_SESSION['user_id']) {
        $error = "You cannot change the status of your own account.";
    } elseif (!$is_super && ((($has_school_col && (int)$target['school_id'] !== (int)$current_school)) || $target['role'] === 'super_admin')) {
        $error = "You are not authorized to manage that account.";
    } else {
        $new_status = $target['status'] === 'active' ? 'inactive' : 'active';
        $upd = $db->prepare("UPDATE users SET status = :s WHERE id = :id");
        $upd->execute([':s' => $new_status, ':id' => $target['id']]);
        logAudit($db, 'account_status_changed', "Set {$target['name']} ({$target['role']}) account to {$new_status}.");
        $message = htmlspecialchars($target['name']) . " is now " . $new_status . ".";
    }
}

// ---------------------------------------------------------------------------
// Security metrics
// ---------------------------------------------------------------------------
if (!function_exists('scalar')) {
    function scalar($db, $sql, $params = []) {
        $st = $db->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn();
    }
}

$total_users    = scalar($db, "SELECT COUNT(*) FROM users WHERE 1=1 $user_scope", $scope_param);
$admin_users    = scalar($db, "SELECT COUNT(*) FROM users WHERE role IN ('super_admin','school_admin','principal') $user_scope", $scope_param);
$inactive_users = scalar($db, "SELECT COUNT(*) FROM users WHERE status = 'inactive' $user_scope", $scope_param);

// Security-relevant audit events in the last 30 days (only if audit log exists)
$sec_actions = "'password_reset','account_status_changed','module_access_changed','school_deleted','school_registered','manual_backup','backup_restored'";
$security_events = 0;
if ($has_audit) {
    $a_scope = $scope_audit ? ' AND school_id = :sid' : '';
    $a_param = $scope_audit ? [':sid' => $current_school] : [];
    $security_events = scalar($db, "SELECT COUNT(*) FROM audit_logs WHERE action IN ($sec_actions) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)$a_scope", $a_param);
}

// ---------------------------------------------------------------------------
// Account search (for status management)
// ---------------------------------------------------------------------------
$sq = trim(filter_input(INPUT_GET, 'sq', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?? '');
$accounts = [];
if ($sq !== '') {
    $where = ["(name LIKE :q OR email LIKE :q OR role LIKE :q)"];
    $params = [':q' => "%$sq%"];
    if (!$is_super) {
        if ($scope_by_school) { $where[] = "school_id = :sid"; $params[':sid'] = $current_school; }
        $where[] = "role <> 'super_admin'";
    }
    $where[] = "id <> :self";
    $params[':self'] = $_SESSION['user_id'];
    $st = $db->prepare("SELECT id, name, email, role, status FROM users WHERE " . implode(' AND ', $where) . " ORDER BY name ASC LIMIT 20");
    $st->execute($params);
    $accounts = $st->fetchAll(PDO::FETCH_ASSOC);
}

// ---------------------------------------------------------------------------
// Recent security activity (only if audit log exists)
// ---------------------------------------------------------------------------
$recent_activity = [];
if ($has_audit) {
    $a_scope = $scope_audit ? ' AND al.school_id = :sid' : '';
    $a_param = $scope_audit ? [':sid' => $current_school] : [];
    $act_sql = "SELECT al.action, al.details, al.created_at, u.name AS user_name
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.action IN ($sec_actions)$a_scope
                ORDER BY al.created_at DESC LIMIT 8";
    $act_stmt = $db->prepare($act_sql);
    $act_stmt->execute($a_param);
    $recent_activity = $act_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Security Center";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="bg-gradient-to-r from-orange-600 via-amber-600 to-yellow-600 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <h1 class="text-3xl font-bold mb-2"><i class="fas fa-shield-halved mr-3"></i>Security Center</h1>
                    <p class="text-orange-100"><?php echo $is_super ? 'System-wide account security and access controls' : 'Account security and access controls for your school'; ?></p>
                </div>

                <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-start"><i class="fas fa-check-circle mr-2 mt-0.5"></i><div><?php echo $message; ?></div></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start"><i class="fas fa-exclamation-circle mr-2 mt-0.5"></i><div><?php echo htmlspecialchars($error); ?></div></div>
                <?php endif; ?>

                <!-- Metric cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
                    <?php
                    $cards = [
                        ['label' => 'Total Accounts', 'value' => $total_users, 'icon' => 'fa-users', 'color' => 'blue'],
                        ['label' => 'Administrators', 'value' => $admin_users, 'icon' => 'fa-user-shield', 'color' => 'purple'],
                        ['label' => 'Disabled Accounts', 'value' => $inactive_users, 'icon' => 'fa-user-lock', 'color' => 'red'],
                        ['label' => 'Security Events (30d)', 'value' => $security_events, 'icon' => 'fa-shield-halved', 'color' => 'amber'],
                    ];
                    foreach ($cards as $c): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-5 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-<?php echo $c['color']; ?>-100 dark:bg-<?php echo $c['color']; ?>-900/40 text-<?php echo $c['color']; ?>-600 dark:text-<?php echo $c['color']; ?>-400">
                                <i class="fas <?php echo $c['icon']; ?> text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo number_format($c['value']); ?></p>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo $c['label']; ?></p>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Quick actions -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 lg:gap-6 mb-8">
                    <a href="password_reset.php" class="group bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:border-rose-300 dark:hover:border-rose-700 hover:shadow-xl transition-all">
                        <div class="w-12 h-12 rounded-xl bg-rose-100 dark:bg-rose-900/40 text-rose-600 dark:text-rose-400 flex items-center justify-center mb-3 group-hover:scale-105 transition-transform"><i class="fas fa-key text-xl"></i></div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Reset User Password</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Set a new password for any user account.</p>
                    </a>
                    <a href="logs.php" class="group bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:border-green-300 dark:hover:border-green-700 hover:shadow-xl transition-all">
                        <div class="w-12 h-12 rounded-xl bg-green-100 dark:bg-green-900/40 text-green-600 dark:text-green-400 flex items-center justify-center mb-3 group-hover:scale-105 transition-transform"><i class="fas fa-clipboard-list text-xl"></i></div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Activity Logs</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review the full audit trail of system activity.</p>
                    </a>
                    <a href="backup.php" class="group bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700 hover:shadow-xl transition-all">
                        <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center mb-3 group-hover:scale-105 transition-transform"><i class="fas fa-database text-xl"></i></div>
                        <h3 class="font-semibold text-gray-900 dark:text-white">Backup &amp; Restore</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Safeguard data with full database backups.</p>
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Account access control -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h2 class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-user-gear mr-2 text-gray-400"></i>Account Access Control</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-0.5">Enable or disable login access for an account.</p>
                        </div>
                        <div class="p-6">
                            <form method="GET" class="flex gap-2 mb-4">
                                <div class="relative flex-1">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                    <input type="text" name="sq" value="<?php echo htmlspecialchars($sq); ?>" placeholder="Search user to enable/disable..."
                                           class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-orange-500 focus:outline-none">
                                </div>
                                <button type="submit" class="bg-orange-600 hover:bg-orange-700 text-white px-4 py-2 rounded-lg text-sm">Search</button>
                            </form>

                            <?php if ($sq !== '' && empty($accounts)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">No matching accounts.</p>
                            <?php elseif (!empty($accounts)): ?>
                            <div class="space-y-2 max-h-80 overflow-y-auto">
                                <?php foreach ($accounts as $a): ?>
                                <div class="flex items-center justify-between p-3 rounded-lg bg-gray-50 dark:bg-gray-700/50">
                                    <div class="min-w-0">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($a['name']); ?></div>
                                        <div class="text-xs text-gray-400 truncate"><?php echo htmlspecialchars(function_exists('formatRoleName') ? formatRoleName($a['role']) : $a['role']); ?> · <?php echo htmlspecialchars($a['email']); ?></div>
                                    </div>
                                    <div class="flex items-center gap-3 flex-shrink-0">
                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full <?php echo $a['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'; ?>"><?php echo ucfirst($a['status']); ?></span>
                                        <form method="POST" onsubmit="return confirm('<?php echo $a['status'] === 'active' ? 'Disable' : 'Enable'; ?> this account?');">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$a['id']; ?>">
                                            <button type="submit" name="toggle_status" class="px-3 py-1.5 rounded-md text-xs font-medium text-white <?php echo $a['status'] === 'active' ? 'bg-red-500 hover:bg-red-600' : 'bg-green-600 hover:bg-green-700'; ?>">
                                                <?php echo $a['status'] === 'active' ? 'Disable' : 'Enable'; ?>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-sm text-gray-400 text-center py-4">Search for a user to manage their access.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent security activity -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                            <h2 class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-2 text-gray-400"></i>Recent Security Activity</h2>
                            <a href="logs.php" class="text-sm text-blue-600 dark:text-blue-400 hover:underline">View all</a>
                        </div>
                        <div class="p-4">
                            <?php if (empty($recent_activity)): ?>
                            <p class="text-sm text-gray-400 text-center py-6">No recent security activity.</p>
                            <?php else: ?>
                            <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php foreach ($recent_activity as $ev): ?>
                                <li class="py-3 flex items-start gap-3">
                                    <div class="w-8 h-8 rounded-full bg-orange-100 dark:bg-orange-900/40 text-orange-600 dark:text-orange-400 flex items-center justify-center flex-shrink-0 mt-0.5">
                                        <i class="fas fa-shield-halved text-xs"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $ev['action']))); ?></p>
                                        <?php if ($ev['details']): ?><p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?php echo htmlspecialchars($ev['details']); ?></p><?php endif; ?>
                                        <p class="text-xs text-gray-400 mt-0.5"><?php echo ($ev['user_name'] ? htmlspecialchars($ev['user_name']) . ' · ' : ''); ?><?php echo date('M j, g:i A', strtotime($ev['created_at'])); ?></p>
                                    </div>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <div class="lg:ml-0"><?php include '../includes/footer.php'; ?></div>
    </div>
</div>
