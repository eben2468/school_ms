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

$sources = report_engine_sources_for_role($role);

// Option lists for filter inputs
$classes = $db->query("SELECT id, name FROM classes WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$years = $db->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// ---- Determine current builder state ----
$flash = '';
$flash_type = 'success';
$source = '';
$selected_cols = [];
$filter_values = [];
$report_name = '';
$report_desc = '';
$edit_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $source = $_POST['source'] ?? '';
    $selected_cols = isset($_POST['cols']) && is_array($_POST['cols']) ? $_POST['cols'] : [];
    $filter_values = isset($_POST['f']) && is_array($_POST['f']) ? $_POST['f'] : [];
    $report_name = trim($_POST['report_name'] ?? '');
    $report_desc = trim($_POST['report_desc'] ?? '');
    $edit_id = (int)($_POST['edit_id'] ?? 0);
} elseif (isset($_GET['load'])) {
    $edit_id = (int)$_GET['load'];
    $sql = "SELECT * FROM custom_reports WHERE id = :id" . ($is_admin ? "" : " AND user_id = :uid");
    $stmt = $db->prepare($sql);
    $bind = [':id' => $edit_id];
    if (!$is_admin) { $bind[':uid'] = $user_id; }
    $stmt->execute($bind);
    if ($loaded = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $source = $loaded['source'];
        $cfg = json_decode($loaded['config'], true) ?: [];
        $selected_cols = $cfg['columns'] ?? [];
        $filter_values = $cfg['filters'] ?? [];
        $report_name = $loaded['name'];
        $report_desc = $loaded['description'] ?? '';
    } else {
        $edit_id = 0;
    }
} elseif (isset($_GET['source'])) {
    $source = $_GET['source'];
}

// Validate source against this role's allowed set
if ($source !== '' && !isset($sources[$source])) { $source = ''; }

// Clean filter values (drop empties + unknown keys) against the source definition
$clean_filters = [];
if ($source !== '') {
    foreach (($sources[$source]['filters'] ?? []) as $fk => $fdef) {
        if (isset($filter_values[$fk]) && $filter_values[$fk] !== '') {
            $clean_filters[$fk] = $filter_values[$fk];
        }
    }
}
$config = ['columns' => array_values($selected_cols), 'filters' => $clean_filters];

// ---- Handle actions ----
$action = $_POST['action'] ?? '';

if ($action === 'export' && $source !== '') {
    $result = report_engine_run($db, $source, $config);
    report_engine_csv('custom_report_' . $source . '_' . date('Ymd_His') . '.csv', $result['labels'], $result['keys'], $result['rows']);
}

if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($source === '') {
        $flash = 'Please choose a data source before saving.'; $flash_type = 'error';
    } elseif ($report_name === '') {
        $flash = 'Please give the report a name before saving.'; $flash_type = 'error';
    } elseif (empty($config['columns'])) {
        $flash = 'Select at least one column before saving.'; $flash_type = 'error';
    } else {
        $config_json = json_encode($config);
        if ($edit_id) {
            $sql = "UPDATE custom_reports SET name=:name, description=:descr, source=:source, config=:config WHERE id=:id" . ($is_admin ? "" : " AND user_id=:uid");
            $bind = [':name' => $report_name, ':descr' => $report_desc, ':source' => $source, ':config' => $config_json, ':id' => $edit_id];
            if (!$is_admin) { $bind[':uid'] = $user_id; }
            $db->prepare($sql)->execute($bind);
            $flash = 'Report "' . htmlspecialchars($report_name) . '" updated successfully.';
        } else {
            $stmt = $db->prepare("INSERT INTO custom_reports (user_id, name, description, source, config) VALUES (:uid, :name, :descr, :source, :config)");
            $stmt->execute([':uid' => $user_id, ':name' => $report_name, ':descr' => $report_desc, ':source' => $source, ':config' => $config_json]);
            $edit_id = (int)$db->lastInsertId();
            $flash = 'Report "' . htmlspecialchars($report_name) . '" saved. Find it under Saved Reports.';
        }
    }
}

// Run a preview whenever a source is selected
$result = null;
if ($source !== '') {
    $result = report_engine_run($db, $source, $config);
}

$title = "Custom Report Builder";
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Custom Report Builder</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Pick a data source, choose columns and filters, then preview, export, or save your report.</p>
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
                    <i class="fas <?php echo $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle'; ?> mr-2"></i><?php echo $flash; ?>
                </div>
                <?php endif; ?>

                <!-- Step 1: Source -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4"><span class="text-indigo-500">1.</span> Choose a Data Source</h2>
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
                        <?php foreach ($sources as $skey => $sdef): ?>
                        <a href="?source=<?php echo urlencode($skey); ?>"
                           class="flex flex-col items-center gap-2 p-4 rounded-xl border transition-all duration-200 <?php echo $source === $skey ? 'border-indigo-500 bg-indigo-50 dark:bg-indigo-900/30 ring-2 ring-indigo-500/30' : 'border-gray-200 dark:border-gray-700 hover:border-indigo-300 hover:bg-gray-50 dark:hover:bg-gray-700/40'; ?>">
                            <i class="fas <?php echo $sdef['icon']; ?> text-2xl <?php echo $source === $skey ? 'text-indigo-600 dark:text-indigo-400' : 'text-gray-400'; ?>"></i>
                            <span class="text-sm font-medium text-center text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($sdef['label']); ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php if ($source !== ''): $sdef = $sources[$source]; ?>
                <form method="POST" action="custom_report_builder.php">
                    <input type="hidden" name="source" value="<?php echo htmlspecialchars($source); ?>">
                    <input type="hidden" name="edit_id" value="<?php echo $edit_id; ?>">

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                        <!-- Step 2: Columns -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4"><span class="text-indigo-500">2.</span> Select Columns</h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($sdef['columns'] as $ck => $cdef):
                                    $checked = empty($selected_cols) ? in_array($ck, $sdef['default_columns']) : in_array($ck, $selected_cols);
                                ?>
                                <label class="flex items-center gap-2 px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-700/40 cursor-pointer">
                                    <input type="checkbox" name="cols[]" value="<?php echo htmlspecialchars($ck); ?>" <?php echo $checked ? 'checked' : ''; ?>
                                           class="rounded text-indigo-600 focus:ring-indigo-500">
                                    <span class="text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($cdef['label']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Step 3: Filters -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4"><span class="text-indigo-500">3.</span> Apply Filters <span class="text-xs font-normal text-gray-400">(optional)</span></h2>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <?php foreach ($sdef['filters'] as $fk => $fdef): $val = $filter_values[$fk] ?? ''; ?>
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1"><?php echo htmlspecialchars($fdef['label']); ?></label>
                                    <?php if ($fdef['type'] === 'class'): ?>
                                        <select name="f[<?php echo $fk; ?>]" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                            <option value="">Any</option>
                                            <?php foreach ($classes as $cl): ?>
                                            <option value="<?php echo $cl['id']; ?>" <?php echo (string)$val === (string)$cl['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cl['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fdef['type'] === 'year'): ?>
                                        <select name="f[<?php echo $fk; ?>]" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                            <option value="">Any</option>
                                            <?php foreach ($years as $yr): ?>
                                            <option value="<?php echo $yr['id']; ?>" <?php echo (string)$val === (string)$yr['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($yr['year_name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fdef['type'] === 'select'): ?>
                                        <select name="f[<?php echo $fk; ?>]" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                            <option value="">Any</option>
                                            <?php foreach ($fdef['options'] as $ov => $ol): ?>
                                            <option value="<?php echo htmlspecialchars($ov); ?>" <?php echo (string)$val === (string)$ov ? 'selected' : ''; ?>><?php echo htmlspecialchars($ol); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php elseif ($fdef['type'] === 'date'): ?>
                                        <input type="date" name="f[<?php echo $fk; ?>]" value="<?php echo htmlspecialchars($val); ?>" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Save + Actions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4"><span class="text-indigo-500">4.</span> Preview, Export or Save</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                            <div class="md:col-span-2">
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Report Name</label>
                                <input type="text" name="report_name" value="<?php echo htmlspecialchars($report_name); ?>" placeholder="e.g. Grade 6 Active Students"
                                       class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Description</label>
                                <input type="text" name="report_desc" value="<?php echo htmlspecialchars($report_desc); ?>" placeholder="Optional"
                                       class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white text-sm">
                            </div>
                        </div>
                        <div class="flex flex-wrap gap-3">
                            <button type="submit" name="action" value="preview" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                                <i class="fas fa-eye mr-2"></i>Preview
                            </button>
                            <button type="submit" name="action" value="save" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                                <i class="fas fa-save mr-2"></i><?php echo $edit_id ? 'Update Report' : 'Save Report'; ?>
                            </button>
                            <button type="submit" name="action" value="export" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center">
                                <i class="fas fa-download mr-2"></i>Export CSV
                            </button>
                        </div>
                    </div>
                </form>

                <!-- Preview Results -->
                <?php if ($result !== null): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700 flex items-center justify-between">
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white">Preview</h2>
                            <p class="text-xs text-gray-550 dark:text-gray-400"><?php echo count($result['rows']); ?> row(s) &bull; <?php echo htmlspecialchars($sdef['label']); ?><?php echo count($result['rows']) >= 2000 ? ' (capped at 2000)' : ''; ?></p>
                        </div>
                        <span class="text-xs text-gray-400"><i class="fas fa-table"></i></span>
                    </div>
                    <?php echo report_engine_render_table($result['labels'], $result['keys'], $result['rows']); ?>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-indigo-50 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-tools text-indigo-500 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">Start Building</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">Choose a data source above to select columns, apply filters, and generate a custom report.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
