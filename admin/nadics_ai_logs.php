<?php
/**
 * Nadics AI — interaction log viewer (admin)
 * --------------------------------------------------------------------------
 * Review what users are asking the assistant and how it responded, for quality
 * improvement. Filter by source/role, search the text, and clear old entries.
 */
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'], true)) {
    header('Location: ../index.php');
    exit();
}

require_once '../config/database.php';
require_once '../includes/csrf.php';
require_once '../includes/schema_helpers.php';

$database = new Database();
$db = $database->getConnection();
ensureNadicsAiTable($db);

$notice = '';

// --- Clear logs (older than N days, or all) -------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    csrf_require('nadics_ai_logs.php');
    $days = (int)($_POST['older_than_days'] ?? 0);
    try {
        if ($days > 0) {
            $stmt = $db->prepare("DELETE FROM nadics_ai_logs WHERE created_at < (NOW() - INTERVAL :d DAY)");
            $stmt->bindValue(':d', $days, PDO::PARAM_INT);
            $stmt->execute();
            $notice = "Deleted {$stmt->rowCount()} log(s) older than {$days} day(s).";
        } else {
            $n = $db->exec("DELETE FROM nadics_ai_logs");
            $notice = "Cleared all {$n} log(s).";
        }
    } catch (Throwable $e) {
        $notice = 'Could not clear logs: ' . $e->getMessage();
    }
}

// --- Filters --------------------------------------------------------------
$f_source = $_GET['source'] ?? '';
$f_role   = trim($_GET['role'] ?? '');
$f_search = trim($_GET['q'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 25;
$offset   = ($page - 1) * $perPage;

$where = [];
$params = [];
if ($f_source !== '' && in_array($f_source, ['ai', 'builtin', 'builtin-data', 'builtin-fallback', 'error'], true)) {
    $where[] = 'source = :source';
    $params[':source'] = $f_source;
}
if ($f_role !== '') {
    $where[] = 'user_role = :role';
    $params[':role'] = $f_role;
}
if ($f_search !== '') {
    $where[] = '(user_message LIKE :q OR ai_reply LIKE :q)';
    $params[':q'] = '%' . $f_search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// --- Stats ----------------------------------------------------------------
$stats = ['total' => 0, 'ai' => 0, 'builtin' => 0, 'users' => 0, 'today' => 0];
try {
    $stats['total']   = (int)$db->query("SELECT COUNT(*) FROM nadics_ai_logs")->fetchColumn();
    $stats['ai']      = (int)$db->query("SELECT COUNT(*) FROM nadics_ai_logs WHERE source = 'ai'")->fetchColumn();
    $stats['builtin'] = (int)$db->query("SELECT COUNT(*) FROM nadics_ai_logs WHERE source LIKE 'builtin%'")->fetchColumn();
    $stats['users']   = (int)$db->query("SELECT COUNT(DISTINCT user_id) FROM nadics_ai_logs")->fetchColumn();
    $stats['today']   = (int)$db->query("SELECT COUNT(*) FROM nadics_ai_logs WHERE DATE(created_at) = CURDATE()")->fetchColumn();
} catch (Throwable $e) { /* table just created */ }

// --- Page of results ------------------------------------------------------
$total = 0; $logs = [];
try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM nadics_ai_logs $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = "SELECT l.*, u.name AS user_name
            FROM nadics_ai_logs l
            LEFT JOIN users u ON u.id = l.user_id
            $whereSql
            ORDER BY l.id DESC
            LIMIT :lim OFFSET :off";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $notice = $notice ?: ('Could not load logs: ' . $e->getMessage());
}
$totalPages = max(1, (int)ceil($total / $perPage));

$sourceBadge = function ($s) {
    switch ($s) {
        case 'ai': return ['AI', 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'];
        case 'builtin': return ['Built-in', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'];
        case 'builtin-data': return ['Built-in (data)', 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'];
        case 'builtin-fallback': return ['Fallback', 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'];
        case 'error': return ['Error', 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'];
        default: return [$s ?: '—', 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'];
    }
};

$title = "Nadics AI Logs";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">

                <!-- Header -->
                <div class="bg-gradient-to-r from-indigo-700 via-purple-700 to-violet-800 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2"><i class="fas fa-robot mr-3"></i>Nadics AI Logs</h1>
                            <p class="text-indigo-100">Review assistant conversations to improve quality and spot gaps.</p>
                        </div>
                        <a href="../settings/school.php?tab=ai" class="bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2 rounded-lg text-sm flex items-center transition-colors">
                            <i class="fas fa-cog mr-2"></i>AI Settings
                        </a>
                    </div>
                </div>

                <?php if ($notice): ?>
                <div class="mb-6 p-4 rounded-lg bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 text-blue-800 dark:text-blue-200 text-sm">
                    <i class="fas fa-info-circle mr-2"></i><?php echo htmlspecialchars($notice); ?>
                </div>
                <?php endif; ?>

                <!-- Stats -->
                <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
                    <?php
                    $cards = [
                        ['Total', $stats['total'], 'fa-list-ul', 'indigo'],
                        ['AI answers', $stats['ai'], 'fa-microchip', 'green'],
                        ['Built-in', $stats['builtin'], 'fa-gear', 'gray'],
                        ['Unique users', $stats['users'], 'fa-users', 'purple'],
                        ['Today', $stats['today'], 'fa-calendar-day', 'blue'],
                    ];
                    foreach ($cards as $c): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow border border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide"><?php echo $c[0]; ?></p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($c[1]); ?></p>
                            </div>
                            <div class="w-10 h-10 rounded-lg bg-<?php echo $c[3]; ?>-100 dark:bg-<?php echo $c[3]; ?>-900/40 flex items-center justify-center">
                                <i class="fas <?php echo $c[2]; ?> text-<?php echo $c[3]; ?>-600 dark:text-<?php echo $c[3]; ?>-300"></i>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 shadow border border-gray-100 dark:border-gray-700">
                    <form method="GET" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search</label>
                            <input type="text" name="q" value="<?php echo htmlspecialchars($f_search); ?>" placeholder="Search question or reply…"
                                class="w-full px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Source</label>
                            <select name="source" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                                <option value="">All</option>
                                <?php foreach (['ai'=>'AI','builtin'=>'Built-in','builtin-data'=>'Built-in (data)','builtin-fallback'=>'Fallback','error'=>'Error'] as $sv=>$sl): ?>
                                <option value="<?php echo $sv; ?>" <?php echo $f_source===$sv?'selected':''; ?>><?php echo $sl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Role</label>
                            <input type="text" name="role" value="<?php echo htmlspecialchars($f_role); ?>" placeholder="e.g. student"
                                class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm w-36">
                        </div>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-filter mr-1"></i>Filter
                        </button>
                        <a href="nadics_ai_logs.php" class="px-4 py-2 rounded-lg text-sm border border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-300">Reset</a>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-900/50 text-gray-500 dark:text-gray-400 text-xs uppercase">
                                <tr>
                                    <th class="text-left px-4 py-3">When</th>
                                    <th class="text-left px-4 py-3">User</th>
                                    <th class="text-left px-4 py-3">Question</th>
                                    <th class="text-left px-4 py-3">Reply</th>
                                    <th class="text-left px-4 py-3">Source</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                                <?php if (empty($logs)): ?>
                                <tr><td colspan="5" class="px-4 py-10 text-center text-gray-400">No interactions logged yet.</td></tr>
                                <?php else: foreach ($logs as $log): [$bLabel,$bClass] = $sourceBadge($log['source']); ?>
                                <tr class="align-top hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400 text-xs">
                                        <?php echo date('M j, Y', strtotime($log['created_at'])); ?><br>
                                        <?php echo date('g:i A', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <div class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($log['user_name'] ?? '—'); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars(function_exists('formatRoleName') ? formatRoleName($log['user_role']) : $log['user_role']); ?></div>
                                    </td>
                                    <td class="px-4 py-3 max-w-xs text-gray-800 dark:text-gray-200"><?php echo nl2br(htmlspecialchars($log['user_message'])); ?></td>
                                    <td class="px-4 py-3 max-w-md text-gray-600 dark:text-gray-300">
                                        <div class="line-clamp-4"><?php echo nl2br(htmlspecialchars(mb_strimwidth((string)$log['ai_reply'], 0, 320, '…'))); ?></div>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap">
                                        <span class="px-2 py-1 rounded-full text-xs font-medium <?php echo $bClass; ?>"><?php echo $bLabel; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="flex items-center justify-between px-4 py-3 border-t border-gray-100 dark:border-gray-700 text-sm">
                        <span class="text-gray-500 dark:text-gray-400">Page <?php echo $page; ?> of <?php echo $totalPages; ?> · <?php echo number_format($total); ?> result(s)</span>
                        <div class="flex gap-2">
                            <?php $qs = function($p){ $q=$_GET; $q['page']=$p; return '?'.http_build_query($q); }; ?>
                            <?php if ($page > 1): ?><a href="<?php echo $qs($page-1); ?>" class="px-3 py-1 rounded border border-gray-300 dark:border-gray-600">Prev</a><?php endif; ?>
                            <?php if ($page < $totalPages): ?><a href="<?php echo $qs($page+1); ?>" class="px-3 py-1 rounded border border-gray-300 dark:border-gray-600">Next</a><?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Maintenance -->
                <div class="mt-6 bg-white dark:bg-gray-800 rounded-xl p-4 shadow border border-gray-100 dark:border-gray-700">
                    <form method="POST" class="flex flex-wrap items-center gap-3" onsubmit="return confirm('Delete the selected logs? This cannot be undone.');">
                        <?php echo csrf_field(); ?>
                        <input type="hidden" name="action" value="clear_logs">
                        <span class="text-sm text-gray-600 dark:text-gray-300">Housekeeping:</span>
                        <select name="older_than_days" class="px-3 py-2 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-sm">
                            <option value="90">Older than 90 days</option>
                            <option value="30">Older than 30 days</option>
                            <option value="7">Older than 7 days</option>
                            <option value="0">All logs</option>
                        </select>
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm">
                            <i class="fas fa-trash-alt mr-1"></i>Delete
                        </button>
                    </form>
                </div>

            </div>
        </main>
        <div class="lg:ml-0"><?php include '../includes/footer.php'; ?></div>
    </div>
</div>
</body>
</html>
