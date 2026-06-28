<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
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

// Schema awareness for multi-tenant DBs (tenant DBs may lack audit_logs / schools / school_id)
$has_audit     = dbHasTable($db, 'audit_logs');
$audit_has_sid = $has_audit && dbHasColumn($db, 'audit_logs', 'school_id');
$has_schools   = dbHasTable($db, 'schools');
$scope_audit   = !$is_super && $audit_has_sid;

// SQL fragments that adapt to the available schema
$schools_join  = $has_schools ? "LEFT JOIN schools s ON al.school_id = s.id" : "";
$school_select = $has_schools ? ", s.name AS school_name" : ", NULL AS school_name";

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------
$action_filter = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: 'all';
$search        = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
$date_from     = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';
$date_to       = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: '';

// Build shared WHERE clause + params (reused by count, stats, list and export)
$where = ['1=1'];
$params = [];

// Non super-admins only see their own school's activity (when audit logs carry school_id)
if ($scope_audit) {
    $where[] = 'al.school_id = :school_id';
    $params[':school_id'] = $current_school;
}

if ($action_filter !== 'all') {
    $where[] = 'al.action = :action';
    $params[':action'] = $action_filter;
}

if ($search !== '') {
    $where[] = '(al.details LIKE :search OR al.action LIKE :search OR u.name LIKE :search OR al.ip_address LIKE :search)';
    $params[':search'] = "%$search%";
}

if ($date_from !== '' && strtotime($date_from)) {
    $where[] = 'al.created_at >= :date_from';
    $params[':date_from'] = date('Y-m-d 00:00:00', strtotime($date_from));
}

if ($date_to !== '' && strtotime($date_to)) {
    $where[] = 'al.created_at <= :date_to';
    $params[':date_to'] = date('Y-m-d 23:59:59', strtotime($date_to));
}

$where_clause = implode(' AND ', $where);

// ---------------------------------------------------------------------------
// Audit sources
// Super admins aggregate the central directory PLUS every school's isolated
// tenant database (cross-schema queries on the same MySQL server). Everyone
// else reads only their single active connection.
// ---------------------------------------------------------------------------
function getAuditSources($db) {
    $sources = [['db' => DB_NAME, 'is_central' => true, 'school_id' => null, 'school_name' => null]];
    try {
        $rows = $db->query("SELECT id, name, db_name FROM schools WHERE db_name IS NOT NULL AND db_name <> '' ORDER BY name")
                   ->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $sources[] = ['db' => $r['db_name'], 'is_central' => false, 'school_id' => (int)$r['id'], 'school_name' => $r['name']];
        }
    } catch (PDOException $e) { /* schools table absent — central only */ }
    return $sources;
}

// Fetch audit rows from one source DB, tagging each with its school name.
function fetchAuditRows($db, $source, $where_clause, $params, $limit = null) {
    $dbn = $source['db'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbn)) { return []; }
    if ($source['is_central']) {
        $select_school = 's.name AS school_name';
        $join_school   = "LEFT JOIN `$dbn`.schools s ON al.school_id = s.id";
    } else {
        $select_school = $db->quote($source['school_name']) . ' AS school_name';
        $join_school   = '';
    }
    $sql = "SELECT al.created_at, al.action, al.details, al.ip_address,
                   u.name AS user_name, u.role AS user_role, $select_school
            FROM `$dbn`.audit_logs al
            LEFT JOIN `$dbn`.users u ON al.user_id = u.id
            $join_school
            WHERE $where_clause
            ORDER BY al.created_at DESC" . ($limit !== null ? ' LIMIT ' . (int)$limit : '');
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return []; // tenant DB without audit_logs/users — skip silently
    }
}

// Count audit rows in one source DB (respecting the active filters).
function countAuditRows($db, $source, $where_clause, $params) {
    $dbn = $source['db'];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $dbn)) { return 0; }
    $sql = "SELECT COUNT(*) FROM `$dbn`.audit_logs al
            LEFT JOIN `$dbn`.users u ON al.user_id = u.id
            WHERE $where_clause";
    try {
        $stmt = $db->prepare($sql);
        foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

$sources = $is_super ? getAuditSources($db) : [];

// Distinct actions for the filter dropdown
$action_options = [];
if ($is_super) {
    $action_set = [];
    foreach ($sources as $src) {
        $dbn = $src['db'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbn)) { continue; }
        try {
            foreach ($db->query("SELECT DISTINCT action FROM `$dbn`.audit_logs")->fetchAll(PDO::FETCH_COLUMN) as $a) {
                $action_set[$a] = true;
            }
        } catch (PDOException $e) { /* skip source */ }
    }
    $action_options = array_keys($action_set);
    sort($action_options);
} elseif ($has_audit) {
    try {
        $opt_sql = "SELECT DISTINCT al.action FROM audit_logs al WHERE " . ($scope_audit ? 'al.school_id = :school_id' : '1=1') . " ORDER BY al.action";
        $opt_stmt = $db->prepare($opt_sql);
        if ($scope_audit) { $opt_stmt->bindValue(':school_id', $current_school); }
        $opt_stmt->execute();
        $action_options = $opt_stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {
        $action_options = [];
    }
}

// Human-friendly label + colour for an action key
function actionLabel($action) {
    return ucwords(str_replace('_', ' ', $action));
}
function actionColor($action) {
    $a = strtolower($action);
    if (strpos($a, 'delete') !== false || strpos($a, 'remove') !== false || strpos($a, 'fail') !== false) return 'red';
    if (strpos($a, 'register') !== false || strpos($a, 'create') !== false || strpos($a, 'add') !== false || strpos($a, 'enabled') !== false) return 'green';
    if (strpos($a, 'payment') !== false || strpos($a, 'backup') !== false || strpos($a, 'finance') !== false) return 'blue';
    if (strpos($a, 'access') !== false || strpos($a, 'change') !== false || strpos($a, 'update') !== false || strpos($a, 'edit') !== false) return 'amber';
    if (strpos($a, 'login') !== false || strpos($a, 'logout') !== false || strpos($a, 'auth') !== false) return 'purple';
    return 'gray';
}

// ---------------------------------------------------------------------------
// CSV export (handled before any HTML output)
// ---------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv' && ($has_audit || $is_super)) {
    $export_rows = [];
    if ($is_super) {
        foreach ($sources as $src) {
            $export_rows = array_merge($export_rows, fetchAuditRows($db, $src, $where_clause, $params));
        }
        usort($export_rows, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    } else {
        $export_sql = "SELECT al.created_at, al.action, al.details, al.ip_address,
                              u.name AS user_name, u.role AS user_role $school_select
                       FROM audit_logs al
                       LEFT JOIN users u ON al.user_id = u.id
                       $schools_join
                       WHERE $where_clause
                       ORDER BY al.created_at DESC";
        $export_stmt = $db->prepare($export_sql);
        foreach ($params as $k => $v) { $export_stmt->bindValue($k, $v); }
        $export_stmt->execute();
        $export_rows = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity_logs_' . date('Y-m-d_His') . '.csv"');
    $out = fopen('php://output', 'w');
    $cols = ['Date/Time', 'User', 'Role', 'Action', 'Details', 'IP Address'];
    if ($is_super) { $cols[] = 'School'; }
    fputcsv($out, $cols);
    foreach ($export_rows as $r) {
        $row = [
            $r['created_at'],
            $r['user_name'] ?? 'System',
            $r['user_role'] ?? '',
            actionLabel($r['action']),
            $r['details'],
            $r['ip_address'],
        ];
        if ($is_super) { $row[] = $r['school_name'] ?? ''; }
        fputcsv($out, $row);
    }
    fclose($out);
    exit();
}

// ---------------------------------------------------------------------------
// Pagination
// ---------------------------------------------------------------------------
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$page = max(1, $page);
$per_page = 20;
$offset = ($page - 1) * $per_page;

$total_logs = 0;
$total_pages = 1;
$stats = ['total' => 0, 'today' => 0, 'users' => 0, 'actions' => 0];
$logs = [];

if ($is_super) {
    // ----- Aggregate across the central directory + every tenant DB -----
    // Filtered total (for pagination): sum of per-source counts.
    foreach ($sources as $src) {
        $total_logs += countAuditRows($db, $src, $where_clause, $params);
    }
    $total_pages = max(1, (int)ceil($total_logs / $per_page));

    // Overall stats (ignore action/search/date filters for a system snapshot).
    $stats = ['total' => 0, 'today' => 0, 'users' => 0, 'actions' => 0];
    foreach ($sources as $src) {
        $dbn = $src['db'];
        if (!preg_match('/^[A-Za-z0-9_]+$/', $dbn)) { continue; }
        try {
            $r = $db->query("SELECT COUNT(*) AS total,
                                    COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) AS today,
                                    COUNT(DISTINCT user_id) AS users
                             FROM `$dbn`.audit_logs")->fetch(PDO::FETCH_ASSOC);
            $stats['total'] += (int)$r['total'];
            $stats['today'] += (int)$r['today'];
            $stats['users'] += (int)$r['users'];
        } catch (PDOException $e) { /* skip source */ }
    }
    $stats['actions'] = count($action_options);

    // Listing: distributed top-k — pull the newest (offset+per_page) from each
    // source, merge, sort by time desc, then slice the current page.
    $fetch_limit = $offset + $per_page;
    $merged = [];
    foreach ($sources as $src) {
        $merged = array_merge($merged, fetchAuditRows($db, $src, $where_clause, $params, $fetch_limit));
    }
    usort($merged, function ($a, $b) { return strcmp($b['created_at'], $a['created_at']); });
    $logs = array_slice($merged, $offset, $per_page);
} elseif ($has_audit) {
    // Total count
    $count_sql = "SELECT COUNT(*) AS total
                  FROM audit_logs al
                  LEFT JOIN users u ON al.user_id = u.id
                  WHERE $where_clause";
    $count_stmt = $db->prepare($count_sql);
    foreach ($params as $k => $v) { $count_stmt->bindValue($k, $v); }
    $count_stmt->execute();
    $total_logs = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_pages = max(1, (int)ceil($total_logs / $per_page));

    // Statistics (scoped, but ignore action/search/date filters so the cards
    // give an overall picture of activity for the school/system)
    $stats_where = $scope_audit ? 'al.school_id = :school_id' : '1=1';
    $stats_sql = "SELECT
            COUNT(*) AS total,
            COUNT(CASE WHEN DATE(al.created_at) = CURDATE() THEN 1 END) AS today,
            COUNT(DISTINCT al.user_id) AS users,
            COUNT(DISTINCT al.action) AS actions
        FROM audit_logs al
        WHERE $stats_where";
    $stats_stmt = $db->prepare($stats_sql);
    if ($scope_audit) { $stats_stmt->bindValue(':school_id', $current_school); }
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    // Log list
    $list_sql = "SELECT al.*, u.name AS user_name, u.role AS user_role $school_select
                 FROM audit_logs al
                 LEFT JOIN users u ON al.user_id = u.id
                 $schools_join
                 WHERE $where_clause
                 ORDER BY al.created_at DESC
                 LIMIT :limit OFFSET :offset";
    $list_stmt = $db->prepare($list_sql);
    foreach ($params as $k => $v) { $list_stmt->bindValue($k, $v); }
    $list_stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $list_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $list_stmt->execute();
    $logs = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Preserve current filters when building pagination/export links
$query_base = array_filter([
    'action'    => $action_filter !== 'all' ? $action_filter : null,
    'search'    => $search !== '' ? $search : null,
    'date_from' => $date_from !== '' ? $date_from : null,
    'date_to'   => $date_to !== '' ? $date_to : null,
]);

$title = "Activity Logs";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="bg-gradient-to-r from-slate-700 via-gray-800 to-slate-900 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-clipboard-list mr-3"></i>Activity Logs
                            </h1>
                            <p class="text-gray-300">
                                <?php echo $is_super ? 'System-wide audit trail across all schools' : 'Audit trail of administrative activity for your school'; ?>
                            </p>
                        </div>
                        <a href="?export=csv<?php echo $query_base ? '&' . http_build_query($query_base) : ''; ?>"
                           class="bg-white/10 hover:bg-white/20 border border-white/20 text-white px-4 py-2 rounded-lg text-sm flex items-center transition-colors">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 lg:gap-6 mb-8">
                    <?php
                    $cards = [
                        ['label' => 'Total Events', 'value' => (int)$stats['total'], 'icon' => 'fa-list-ul', 'color' => 'blue'],
                        ['label' => 'Today', 'value' => (int)$stats['today'], 'icon' => 'fa-calendar-day', 'color' => 'green'],
                        ['label' => 'Active Users', 'value' => (int)$stats['users'], 'icon' => 'fa-user-shield', 'color' => 'purple'],
                        ['label' => 'Action Types', 'value' => (int)$stats['actions'], 'icon' => 'fa-tags', 'color' => 'amber'],
                    ];
                    foreach ($cards as $c):
                    ?>
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

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-5 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                        <div class="lg:col-span-2">
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Search</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                       placeholder="Details, user, IP..."
                                       class="w-full pl-9 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Action</label>
                            <select name="action" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                <option value="all">All actions</option>
                                <?php foreach ($action_options as $opt): ?>
                                <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo $action_filter === $opt ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(actionLabel($opt)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"
                                   class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                        </div>
                        <div class="md:col-span-2 lg:col-span-5 flex gap-2">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg text-sm flex items-center">
                                <i class="fas fa-filter mr-2"></i>Apply Filters
                            </button>
                            <a href="logs.php" class="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-5 py-2 rounded-lg text-sm flex items-center">
                                <i class="fas fa-times mr-2"></i>Reset
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Logs Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <h2 class="font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-history mr-2 text-gray-400"></i>Audit Trail
                        </h2>
                        <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo number_format($total_logs); ?> result<?php echo $total_logs === 1 ? '' : 's'; ?></span>
                    </div>

                    <?php if (empty($logs)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-clipboard-list text-gray-300 dark:text-gray-600 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No activity found</h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">No log entries match your current filters.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date / Time</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Details</th>
                                    <?php if ($is_super): ?>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">School</th>
                                    <?php endif; ?>
                                    <th class="px-5 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($logs as $log): $color = actionColor($log['action']); ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors">
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-700 dark:text-gray-300">
                                        <div class="font-medium"><?php echo date('M j, Y', strtotime($log['created_at'])); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo date('g:i:s A', strtotime($log['created_at'])); ?></div>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <?php if ($log['user_name']): ?>
                                        <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($log['user_name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars(function_exists('formatRoleName') ? formatRoleName($log['user_role']) : $log['user_role']); ?></div>
                                        <?php else: ?>
                                        <span class="inline-flex items-center text-xs text-gray-400"><i class="fas fa-robot mr-1"></i>System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 whitespace-nowrap">
                                        <span class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full bg-<?php echo $color; ?>-100 text-<?php echo $color; ?>-800 dark:bg-<?php echo $color; ?>-900/40 dark:text-<?php echo $color; ?>-300">
                                            <?php echo htmlspecialchars(actionLabel($log['action'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-5 py-4 text-sm text-gray-600 dark:text-gray-300 max-w-md">
                                        <?php echo $log['details'] ? htmlspecialchars($log['details']) : '<span class="text-gray-400">—</span>'; ?>
                                    </td>
                                    <?php if ($is_super): ?>
                                    <td class="px-5 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        <?php echo $log['school_name'] ? htmlspecialchars($log['school_name']) : '<span class="text-gray-400">—</span>'; ?>
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-5 py-4 whitespace-nowrap text-xs font-mono text-gray-500 dark:text-gray-400">
                                        <?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="px-5 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between flex-wrap gap-3">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        </span>
                        <div class="flex gap-2">
                            <?php
                            $prev_q = http_build_query(array_merge($query_base, ['page' => $page - 1]));
                            $next_q = http_build_query(array_merge($query_base, ['page' => $page + 1]));
                            ?>
                            <?php if ($page > 1): ?>
                            <a href="?<?php echo $prev_q; ?>" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm">
                                <i class="fas fa-chevron-left mr-1"></i>Previous
                            </a>
                            <?php endif; ?>
                            <?php if ($page < $total_pages): ?>
                            <a href="?<?php echo $next_q; ?>" class="px-4 py-2 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded-lg text-sm">
                                Next<i class="fas fa-chevron-right ml-1"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
