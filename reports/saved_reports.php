<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/report_engine.php';
$database = new Database();
$db = $database->getConnection();
report_engine_install($db);

$role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$is_admin = in_array($role, ['super_admin', 'school_admin']);
$source_defs = report_engine_sources();

// Fetch a single report respecting ownership
function fetch_report(PDO $db, int $id, int $user_id, bool $is_admin)
{
    $sql = "SELECT r.*, u.name AS owner_name FROM custom_reports r LEFT JOIN users u ON r.user_id = u.id WHERE r.id = :id" . ($is_admin ? "" : " AND r.user_id = :uid");
    $bind = [':id' => $id];
    if (!$is_admin) { $bind[':uid'] = $user_id; }
    $stmt = $db->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $del = (int)$_POST['delete_id'];
    $sql = "DELETE FROM custom_reports WHERE id = :id" . ($is_admin ? "" : " AND user_id = :uid");
    $bind = [':id' => $del];
    if (!$is_admin) { $bind[':uid'] = $user_id; }
    $db->prepare($sql)->execute($bind);
    // Clean up any schedules pointing at it
    $db->prepare("DELETE FROM report_schedules WHERE report_id = :id")->execute([':id' => $del]);
    header("Location: saved_reports.php?deleted=1");
    exit();
}

// ---- Run a report (view results / export) ----
$run_report = null;
$run_result = null;
if (isset($_GET['run'])) {
    $run_report = fetch_report($db, (int)$_GET['run'], $user_id, $is_admin);
    if ($run_report && report_engine_can_use($role, $run_report['source'])) {
        $cfg = json_decode($run_report['config'], true) ?: [];
        $run_result = report_engine_run($db, $run_report['source'], $cfg);

        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $fname = preg_replace('/[^A-Za-z0-9]+/', '_', $run_report['name']) . '_' . date('Ymd_His') . '.csv';
            report_engine_csv($fname, $run_result['labels'], $run_result['keys'], $run_result['rows']);
        }
    }
}

// ---- List ----
$list_sql = "SELECT r.*, u.name AS owner_name,
        (SELECT COUNT(*) FROM report_schedules s WHERE s.report_id = r.id) AS schedule_count
     FROM custom_reports r LEFT JOIN users u ON r.user_id = u.id";
if (!$is_admin) { $list_sql .= " WHERE r.user_id = :uid"; }
$list_sql .= " ORDER BY r.updated_at DESC";
$list_stmt = $db->prepare($list_sql);
$list_stmt->execute($is_admin ? [] : [':uid' => $user_id]);
$reports = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Saved Reports";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Saved Reports</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Run, export, edit, or schedule your saved custom reports.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="custom_report_builder.php" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                            <i class="fas fa-plus mr-2"></i>New Report
                        </a>
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                    </div>
                </div>

                <?php if (isset($_GET['deleted'])): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:text-green-300">
                    <i class="fas fa-check-circle mr-2"></i>Report deleted successfully.
                </div>
                <?php endif; ?>

                <?php if ($run_report && $run_result !== null): ?>
                <!-- Run result view -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($run_report['name']); ?></h2>
                            <p class="text-xs text-gray-550 dark:text-gray-400">
                                <?php echo htmlspecialchars($source_defs[$run_report['source']]['label'] ?? $run_report['source']); ?>
                                &bull; <?php echo count($run_result['rows']); ?> row(s)
                                <?php if ($run_report['description']): ?>&bull; <?php echo htmlspecialchars($run_report['description']); ?><?php endif; ?>
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <a href="?run=<?php echo (int)$run_report['id']; ?>&export=csv" class="bg-green-600 hover:bg-green-700 text-white text-sm font-semibold px-3 py-2 rounded-lg transition flex items-center"><i class="fas fa-download mr-2"></i>Export CSV</a>
                            <a href="custom_report_builder.php?load=<?php echo (int)$run_report['id']; ?>" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold px-3 py-2 rounded-lg transition flex items-center"><i class="fas fa-edit mr-2"></i>Edit</a>
                            <a href="saved_reports.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 text-sm font-semibold px-3 py-2 rounded-lg transition flex items-center"><i class="fas fa-list mr-2"></i>All Reports</a>
                        </div>
                    </div>
                    <?php echo report_engine_render_table($run_result['labels'], $run_result['keys'], $run_result['rows']); ?>
                </div>
                <?php elseif (isset($_GET['run'])): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:text-red-300">
                    <i class="fas fa-exclamation-circle mr-2"></i>That report could not be found or you don't have access to it.
                </div>
                <?php endif; ?>

                <!-- Reports List -->
                <?php if (!empty($reports)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">My Saved Reports</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400"><?php echo count($reports); ?> report(s)<?php echo $is_admin ? ' (all users)' : ''; ?></p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Report</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Source</th>
                                    <?php if ($is_admin): ?><th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Owner</th><?php endif; ?>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Schedules</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Updated</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($reports as $r): $sdef = $source_defs[$r['source']] ?? null; ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($r['name']); ?></div>
                                        <?php if ($r['description']): ?><div class="text-xs text-gray-400"><?php echo htmlspecialchars($r['description']); ?></div><?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        <i class="fas <?php echo $sdef['icon'] ?? 'fa-table'; ?> text-gray-400 mr-1"></i>
                                        <?php echo htmlspecialchars($sdef['label'] ?? $r['source']); ?>
                                    </td>
                                    <?php if ($is_admin): ?><td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($r['owner_name'] ?? '—'); ?></td><?php endif; ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <?php if ($r['schedule_count'] > 0): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-bold rounded-full bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300"><?php echo (int)$r['schedule_count']; ?></span>
                                        <?php else: ?><span class="text-gray-300 dark:text-gray-600">—</span><?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo date('M j, Y', strtotime($r['updated_at'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <div class="flex justify-end gap-1">
                                            <a href="?run=<?php echo (int)$r['id']; ?>" class="p-2 rounded-lg text-indigo-600 hover:bg-indigo-50 dark:hover:bg-indigo-900/20" title="Run"><i class="fas fa-play"></i></a>
                                            <a href="?run=<?php echo (int)$r['id']; ?>&export=csv" class="p-2 rounded-lg text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20" title="Export CSV"><i class="fas fa-download"></i></a>
                                            <a href="custom_report_builder.php?load=<?php echo (int)$r['id']; ?>" class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 dark:hover:bg-gray-700" title="Edit"><i class="fas fa-edit"></i></a>
                                            <a href="scheduled_reports.php?report=<?php echo (int)$r['id']; ?>" class="p-2 rounded-lg text-purple-600 hover:bg-purple-50 dark:hover:bg-purple-900/20" title="Schedule"><i class="fas fa-clock"></i></a>
                                            <form method="POST" action="saved_reports.php" class="inline" onsubmit="return confirm('Delete this report and its schedules?');">
                                                <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                                                <button type="submit" class="p-2 rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-indigo-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-save text-indigo-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Saved Reports Yet</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">Build a custom report and save it to reuse, export, or schedule it later.</p>
                    <a href="custom_report_builder.php" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition">
                        <i class="fas fa-plus mr-2"></i>Create Your First Report
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
