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

$flash = '';
$flash_type = 'success';
$day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

// Saved reports available to attach a schedule to
$rep_sql = "SELECT id, name FROM custom_reports" . ($is_admin ? "" : " WHERE user_id = :uid") . " ORDER BY name";
$rep_stmt = $db->prepare($rep_sql);
$rep_stmt->execute($is_admin ? [] : [':uid' => $user_id]);
$my_reports = $rep_stmt->fetchAll(PDO::FETCH_ASSOC);
$valid_report_ids = array_column($my_reports, 'id');

// ---- Actions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'create') {
        $report_id = (int)($_POST['report_id'] ?? 0);
        $frequency = in_array($_POST['frequency'] ?? '', ['daily', 'weekly', 'monthly']) ? $_POST['frequency'] : 'weekly';
        $dow = $frequency === 'weekly' ? (int)($_POST['day_of_week'] ?? 1) : null;
        $dom = $frequency === 'monthly' ? max(1, min(28, (int)($_POST['day_of_month'] ?? 1))) : null;
        $run_time = preg_match('/^\d{2}:\d{2}$/', $_POST['run_time'] ?? '') ? $_POST['run_time'] . ':00' : '08:00:00';
        $recipients = trim($_POST['recipients'] ?? '');

        if (!in_array($report_id, $valid_report_ids)) {
            $flash = 'Please choose a valid saved report.'; $flash_type = 'error';
        } else {
            $next = report_engine_next_run($frequency, $dow, $dom, substr($run_time, 0, 5));
            $stmt = $db->prepare("INSERT INTO report_schedules (report_id, frequency, day_of_week, day_of_month, run_time, recipients, format, next_run_at, created_by)
                VALUES (:rid, :freq, :dow, :dom, :rt, :rec, 'csv', :next, :uid)");
            $stmt->execute([
                ':rid' => $report_id, ':freq' => $frequency, ':dow' => $dow, ':dom' => $dom,
                ':rt' => $run_time, ':rec' => $recipients, ':next' => $next, ':uid' => $user_id,
            ]);
            $flash = 'Schedule created. Next run: ' . date('M j, Y g:i A', strtotime($next)) . '.';
        }
    } elseif ($act === 'toggle') {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        $sql = "UPDATE report_schedules SET is_active = 1 - is_active WHERE id = :id" . ($is_admin ? "" : " AND created_by = :uid");
        $bind = [':id' => $sid];
        if (!$is_admin) { $bind[':uid'] = $user_id; }
        $db->prepare($sql)->execute($bind);
        $flash = 'Schedule updated.';
    } elseif ($act === 'delete') {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        $sql = "DELETE FROM report_schedules WHERE id = :id" . ($is_admin ? "" : " AND created_by = :uid");
        $bind = [':id' => $sid];
        if (!$is_admin) { $bind[':uid'] = $user_id; }
        $db->prepare($sql)->execute($bind);
        $flash = 'Schedule deleted.';
    } elseif ($act === 'mark_run') {
        $sid = (int)($_POST['schedule_id'] ?? 0);
        // Fetch to recompute next run
        $s = $db->prepare("SELECT * FROM report_schedules WHERE id = :id" . ($is_admin ? "" : " AND created_by = :uid"));
        $bind = [':id' => $sid];
        if (!$is_admin) { $bind[':uid'] = $user_id; }
        $s->execute($bind);
        if ($row = $s->fetch(PDO::FETCH_ASSOC)) {
            $next = report_engine_next_run($row['frequency'], $row['day_of_week'] !== null ? (int)$row['day_of_week'] : null, $row['day_of_month'] !== null ? (int)$row['day_of_month'] : null, substr($row['run_time'], 0, 5));
            $db->prepare("UPDATE report_schedules SET last_run_at = NOW(), next_run_at = :next WHERE id = :id")->execute([':next' => $next, ':id' => $sid]);
            $flash = 'Marked as run. Next run: ' . date('M j, Y g:i A', strtotime($next)) . '.';
        }
    }
}

$preselect_report = isset($_GET['report']) ? (int)$_GET['report'] : 0;

// ---- List schedules ----
$sched_sql = "SELECT s.*, r.name AS report_name, r.source AS report_source, u.name AS creator_name
    FROM report_schedules s
    JOIN custom_reports r ON s.report_id = r.id
    LEFT JOIN users u ON s.created_by = u.id";
if (!$is_admin) { $sched_sql .= " WHERE s.created_by = :uid"; }
$sched_sql .= " ORDER BY s.is_active DESC, s.next_run_at ASC";
$sched_stmt = $db->prepare($sched_sql);
$sched_stmt->execute($is_admin ? [] : [':uid' => $user_id]);
$schedules = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

function freq_label($s, $day_names)
{
    if ($s['frequency'] === 'daily') return 'Daily';
    if ($s['frequency'] === 'weekly') return 'Weekly on ' . ($day_names[(int)$s['day_of_week']] ?? '—');
    return 'Monthly on day ' . (int)$s['day_of_month'];
}

$title = "Scheduled Reports";
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Scheduled Reports</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Automate delivery of your saved reports on a recurring schedule.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="saved_reports.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-save mr-2"></i>Saved Reports
                        </a>
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                    </div>
                </div>

                <?php if ($flash): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border <?php echo $flash_type === 'error' ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:text-red-300' : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:text-green-300'; ?>">
                    <i class="fas <?php echo $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?> mr-2"></i><?php echo htmlspecialchars($flash); ?>
                </div>
                <?php endif; ?>

                <!-- Info note -->
                <div class="mb-6 px-4 py-3 rounded-lg border bg-blue-50 border-blue-200 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300 text-sm">
                    <i class="fas fa-info-circle mr-2"></i>Schedules are stored with a computed next-run time. Automatic delivery requires the report dispatch cron worker to be enabled on the server; meanwhile you can use <strong>Run now</strong> to download any scheduled report on demand.
                </div>

                <?php if (empty($my_reports)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-amber-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-triangle-exclamation text-amber-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Saved Reports to Schedule</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">You need at least one saved report before you can schedule it.</p>
                    <a href="custom_report_builder.php" class="inline-flex items-center bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition"><i class="fas fa-plus mr-2"></i>Build a Report</a>
                </div>
                <?php else: ?>

                <!-- Create schedule -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6" x-data="{ freq: 'weekly' }">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">New Schedule</h2>
                    <form method="POST" action="scheduled_reports.php" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <input type="hidden" name="action" value="create">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Report</label>
                            <select name="report_id" required class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                <option value="">Select a report</option>
                                <?php foreach ($my_reports as $rp): ?>
                                <option value="<?php echo (int)$rp['id']; ?>" <?php echo $preselect_report === (int)$rp['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($rp['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Frequency</label>
                            <select name="frequency" x-model="freq" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                <option value="daily">Daily</option>
                                <option value="weekly" selected>Weekly</option>
                                <option value="monthly">Monthly</option>
                            </select>
                        </div>
                        <div x-show="freq === 'weekly'">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Day of Week</label>
                            <select name="day_of_week" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                <?php foreach ($day_names as $i => $dn): ?>
                                <option value="<?php echo $i; ?>" <?php echo $i === 1 ? 'selected' : ''; ?>><?php echo $dn; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div x-show="freq === 'monthly'">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Day of Month (1–28)</label>
                            <input type="number" name="day_of_month" min="1" max="28" value="1" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Run Time</label>
                            <input type="time" name="run_time" value="08:00" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Recipients <span class="text-xs font-normal text-gray-400">(comma-separated emails)</span></label>
                            <input type="text" name="recipients" placeholder="head@school.edu, admin@school.edu" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                        </div>
                        <div class="lg:col-span-3">
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition flex items-center">
                                <i class="fas fa-clock mr-2"></i>Create Schedule
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Schedules list -->
                <?php if (!empty($schedules)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Active &amp; Upcoming Schedules</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400"><?php echo count($schedules); ?> schedule(s)</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Report</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Frequency</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Recipients</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Next Run</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Last Run</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($schedules as $s): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150 <?php echo $s['is_active'] ? '' : 'opacity-60'; ?>">
                                    <td class="px-6 py-4">
                                        <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($s['report_name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($source_defs[$s['report_source']]['label'] ?? $s['report_source']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                        <?php echo htmlspecialchars(freq_label($s, $day_names)); ?>
                                        <div class="text-xs text-gray-400"><?php echo date('g:i A', strtotime($s['run_time'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 max-w-xs truncate" title="<?php echo htmlspecialchars($s['recipients'] ?? ''); ?>"><?php echo $s['recipients'] ? htmlspecialchars($s['recipients']) : '<span class="text-gray-300 dark:text-gray-600">—</span>'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300"><?php echo $s['next_run_at'] ? date('M j, Y g:i A', strtotime($s['next_run_at'])) : '—'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo $s['last_run_at'] ? date('M j, Y', strtotime($s['last_run_at'])) : 'Never'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-center">
                                        <?php if ($s['is_active']): ?>
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300">Active</span>
                                        <?php else: ?>
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300">Paused</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <div class="flex justify-end gap-1">
                                            <a href="saved_reports.php?run=<?php echo (int)$s['report_id']; ?>&export=csv" class="p-2 rounded-lg text-green-600 hover:bg-green-50 dark:hover:bg-green-900/20" title="Run now (download)"><i class="fas fa-download"></i></a>
                                            <form method="POST" action="scheduled_reports.php" class="inline" title="Mark as run">
                                                <input type="hidden" name="action" value="mark_run">
                                                <input type="hidden" name="schedule_id" value="<?php echo (int)$s['id']; ?>">
                                                <button type="submit" class="p-2 rounded-lg text-blue-600 hover:bg-blue-50 dark:hover:bg-blue-900/20"><i class="fas fa-check-double"></i></button>
                                            </form>
                                            <form method="POST" action="scheduled_reports.php" class="inline">
                                                <input type="hidden" name="action" value="toggle">
                                                <input type="hidden" name="schedule_id" value="<?php echo (int)$s['id']; ?>">
                                                <button type="submit" class="p-2 rounded-lg text-amber-600 hover:bg-amber-50 dark:hover:bg-amber-900/20" title="<?php echo $s['is_active'] ? 'Pause' : 'Resume'; ?>"><i class="fas <?php echo $s['is_active'] ? 'fa-pause' : 'fa-play'; ?>"></i></button>
                                            </form>
                                            <form method="POST" action="scheduled_reports.php" class="inline" onsubmit="return confirm('Delete this schedule?');">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="schedule_id" value="<?php echo (int)$s['id']; ?>">
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
                        <i class="fas fa-calendar-alt text-indigo-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Schedules Yet</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">Create a schedule above to automate one of your saved reports.</p>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
