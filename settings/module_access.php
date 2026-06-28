<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('settings_super');

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/module_access.php';

// Super admins have no tenant context, so this connects to the central control DB.
$database = new Database();
$db = $database->getConnection();

// Provision the storage table on first visit.
try {
    ensureModuleAccessTable($db);
} catch (PDOException $e) {
    error_log("module_access: failed to ensure table - " . $e->getMessage());
}

// Flash messages
$success_message = $_SESSION['ma_success'] ?? null;
$error_message   = $_SESSION['ma_error'] ?? null;
unset($_SESSION['ma_success'], $_SESSION['ma_error']);

$modules = getModuleDefinitions();

// Preserve the active filter across the post/redirect/get cycle.
$active_level = isset($_GET['level']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_GET['level'])) : 'all';

// ----------------------------------------------------
// PROCESS TOGGLE ACTIONS (POST -> Redirect -> Get)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect_level = isset($_POST['level']) ? preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['level'])) : 'all';
    $redirect_to = "module_access.php" . ($redirect_level !== 'all' && $redirect_level !== '' ? "?level=" . urlencode($redirect_level) : "");

    try {
        if ($action === 'toggle_module') {
            $school_id  = (int) ($_POST['school_id'] ?? 0);
            $module_key = $_POST['module_key'] ?? '';
            $new_state  = (int) ($_POST['new_state'] ?? 1) === 1 ? 1 : 0;

            if (!array_key_exists($module_key, $modules)) {
                throw new Exception("Unknown module reference.");
            }

            // Confirm the school exists (and grab its name for the audit trail).
            $sch = $db->prepare("SELECT id, name FROM schools WHERE id = :id");
            $sch->execute([':id' => $school_id]);
            $school = $sch->fetch(PDO::FETCH_ASSOC);
            if (!$school) {
                throw new Exception("School record not found.");
            }

            $stmt = $db->prepare("INSERT INTO school_module_access (school_id, module_key, is_enabled)
                                  VALUES (:school_id, :module_key, :is_enabled)
                                  ON DUPLICATE KEY UPDATE is_enabled = :is_enabled2");
            $stmt->execute([
                ':school_id'  => $school_id,
                ':module_key' => $module_key,
                ':is_enabled' => $new_state,
                ':is_enabled2'=> $new_state,
            ]);

            // Audit log (best effort).
            try {
                $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
                              VALUES (:school_id, :user_id, 'module_access_changed', :details, :ip)")
                   ->execute([
                       ':school_id' => $school_id,
                       ':user_id'   => $_SESSION['user_id'],
                       ':details'   => sprintf("Module '%s' %s for school '%s'.",
                                        $modules[$module_key]['label'],
                                        $new_state ? 'ENABLED' : 'DISABLED',
                                        $school['name']),
                       ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
                   ]);
            } catch (PDOException $auditEx) { /* non-fatal */ }

            $_SESSION['ma_success'] = sprintf("✅ %s has been %s for %s.",
                $modules[$module_key]['label'],
                $new_state ? 'enabled' : 'disabled',
                htmlspecialchars($school['name']));
        }
        elseif ($action === 'toggle_all') {
            $school_id = (int) ($_POST['school_id'] ?? 0);
            $new_state = (int) ($_POST['new_state'] ?? 1) === 1 ? 1 : 0;

            $sch = $db->prepare("SELECT id, name FROM schools WHERE id = :id");
            $sch->execute([':id' => $school_id]);
            $school = $sch->fetch(PDO::FETCH_ASSOC);
            if (!$school) {
                throw new Exception("School record not found.");
            }

            $stmt = $db->prepare("INSERT INTO school_module_access (school_id, module_key, is_enabled)
                                  VALUES (:school_id, :module_key, :is_enabled)
                                  ON DUPLICATE KEY UPDATE is_enabled = :is_enabled2");
            foreach (array_keys($modules) as $mkey) {
                $stmt->execute([
                    ':school_id'  => $school_id,
                    ':module_key' => $mkey,
                    ':is_enabled' => $new_state,
                    ':is_enabled2'=> $new_state,
                ]);
            }

            try {
                $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
                              VALUES (:school_id, :user_id, 'module_access_bulk', :details, :ip)")
                   ->execute([
                       ':school_id' => $school_id,
                       ':user_id'   => $_SESSION['user_id'],
                       ':details'   => sprintf("ALL modules %s for school '%s'.",
                                        $new_state ? 'ENABLED' : 'DISABLED', $school['name']),
                       ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
                   ]);
            } catch (PDOException $auditEx) { /* non-fatal */ }

            $_SESSION['ma_success'] = sprintf("✅ All modules %s for %s.",
                $new_state ? 'enabled' : 'disabled', htmlspecialchars($school['name']));
        }
    } catch (Exception $ex) {
        $_SESSION['ma_error'] = "❌ " . $ex->getMessage();
    }

    header("Location: " . $redirect_to);
    exit();
}

// ----------------------------------------------------
// READ SCHOOLS + SUBSCRIPTION + MODULE ACCESS
// ----------------------------------------------------
$schools_stmt = $db->query("SELECT s.id, s.name, s.code, s.status,
                                   ss.status AS sub_status,
                                   sp.name AS plan_name
                            FROM schools s
                            LEFT JOIN school_subscriptions ss ON ss.school_id = s.id
                            LEFT JOIN subscription_plans sp ON ss.plan_id = sp.id
                            ORDER BY s.name ASC");
$schools = $schools_stmt->fetchAll(PDO::FETCH_ASSOC);

// Build the [school_id][module_key] => is_enabled map in one query.
$access_map = [];
try {
    foreach ($db->query("SELECT school_id, module_key, is_enabled FROM school_module_access")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $access_map[(int)$row['school_id']][$row['module_key']] = (int)$row['is_enabled'];
    }
} catch (PDOException $e) { /* table may be empty */ }

/**
 * Resolve a school's subscription "level" used for filtering.
 * Trial subscriptions are flagged regardless of the plan they trial.
 */
function resolveLevel($school) {
    if (($school['sub_status'] ?? '') === 'trial') {
        return 'trial';
    }
    $plan = strtolower(trim($school['plan_name'] ?? ''));
    return $plan !== '' ? preg_replace('/[^a-z0-9_]/', '_', $plan) : 'unassigned';
}

// Pre-compute per-school metadata and the distinct set of levels for filter chips.
$levels_present = [];
foreach ($schools as &$sch) {
    $sch['level'] = resolveLevel($sch);
    $enabled_count = 0;
    foreach (array_keys($modules) as $mkey) {
        $is_on = !isset($access_map[$sch['id']][$mkey]) || $access_map[$sch['id']][$mkey] === 1;
        if ($is_on) { $enabled_count++; }
    }
    $sch['enabled_count'] = $enabled_count;
    $levels_present[$sch['level']] = true;
}
unset($sch);

// Friendly labels & badge colours for subscription levels.
function levelLabel($level) {
    $map = ['trial' => 'Trial', 'basic' => 'Basic', 'standard' => 'Standard',
            'premium' => 'Premium', 'unassigned' => 'Unassigned'];
    return $map[$level] ?? ucwords(str_replace('_', ' ', $level));
}
function levelBadgeClasses($level) {
    switch ($level) {
        case 'trial':    return 'bg-amber-100 text-amber-700 border-amber-200 dark:bg-amber-900/30 dark:text-amber-300';
        case 'basic':    return 'bg-blue-100 text-blue-700 border-blue-200 dark:bg-blue-900/30 dark:text-blue-300';
        case 'standard': return 'bg-emerald-100 text-emerald-700 border-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300';
        case 'premium':  return 'bg-purple-100 text-purple-700 border-purple-200 dark:bg-purple-900/30 dark:text-purple-300';
        default:         return 'bg-gray-100 text-gray-600 border-gray-200 dark:bg-gray-700 dark:text-gray-300';
    }
}

// Order filter chips sensibly.
$level_order = ['trial', 'basic', 'standard', 'premium', 'unassigned'];
$filter_levels = [];
foreach ($level_order as $lv) {
    if (isset($levels_present[$lv])) { $filter_levels[] = $lv; }
}
foreach (array_keys($levels_present) as $lv) {
    if (!in_array($lv, $filter_levels)) { $filter_levels[] = $lv; }
}

$title = "Module Access Control";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div x-data="moduleAccess('<?php echo htmlspecialchars($active_level, ENT_QUOTES); ?>')"
     class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden"
     style="margin-top: 80px;">

    <!-- Sidebar Spacer -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content -->
    <div class="flex-1 flex flex-col">
        <main class="p-4 sm:p-6 lg:p-8 flex-1">
            <div class="w-full max-w-7xl mx-auto">

                <!-- Header Banner -->
                <div class="mb-6">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-6 sm:p-8 text-white shadow-xl relative overflow-hidden">
                        <div class="relative flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                            <div>
                                <div class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-white/20 text-white backdrop-blur-sm mb-3">
                                    <i class="fas fa-toggle-on mr-2"></i> Multi-School Management
                                </div>
                                <h1 class="text-2xl sm:text-3xl md:text-4xl font-extrabold tracking-tight mb-2">Module Access Control</h1>
                                <p class="text-blue-100 text-sm sm:text-lg max-w-2xl">Enable or disable individual features per school based on their subscription level. Disabled modules disappear from the school's sidebar and become inaccessible to their users.</p>
                            </div>
                            <a href="super_admin.php" class="bg-white/15 hover:bg-white/25 text-white font-semibold px-5 py-3 rounded-xl shadow-lg transition-all duration-200 flex items-center whitespace-nowrap backdrop-blur-sm">
                                <i class="fas fa-arrow-left mr-2"></i> Back to Control Panel
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Flash Alerts -->
                <?php if ($success_message): ?>
                <div class="mb-6 bg-emerald-50 border-l-4 border-emerald-500 rounded-xl p-4 shadow-md text-emerald-800 flex items-center">
                    <i class="fas fa-check-circle text-xl mr-3 text-emerald-500"></i>
                    <div><p class="font-bold">Saved</p><p class="text-sm"><?php echo $success_message; ?></p></div>
                </div>
                <?php endif; ?>
                <?php if ($error_message): ?>
                <div class="mb-6 bg-rose-50 border-l-4 border-rose-500 rounded-xl p-4 shadow-md text-rose-800 flex items-center">
                    <i class="fas fa-exclamation-circle text-xl mr-3 text-rose-500"></i>
                    <div><p class="font-bold">Error</p><p class="text-sm"><?php echo $error_message; ?></p></div>
                </div>
                <?php endif; ?>

                <!-- Summary + Filter Bar -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 p-4 sm:p-5 mb-6">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                        <!-- Filter chips -->
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mr-1">
                                <i class="fas fa-filter mr-1"></i> Plan:
                            </span>
                            <button @click="setLevel('all')"
                                    :class="level === 'all' ? 'bg-indigo-600 text-white shadow' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200'"
                                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-all duration-200">
                                All (<?php echo count($schools); ?>)
                            </button>
                            <?php foreach ($filter_levels as $lv):
                                $count = count(array_filter($schools, fn($s) => $s['level'] === $lv)); ?>
                            <button @click="setLevel('<?php echo htmlspecialchars($lv, ENT_QUOTES); ?>')"
                                    :class="level === '<?php echo htmlspecialchars($lv, ENT_QUOTES); ?>' ? 'bg-indigo-600 text-white shadow' : 'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 hover:bg-gray-200'"
                                    class="px-4 py-1.5 rounded-full text-sm font-medium transition-all duration-200">
                                <?php echo htmlspecialchars(levelLabel($lv)); ?> (<?php echo $count; ?>)
                            </button>
                            <?php endforeach; ?>
                        </div>
                        <!-- Search -->
                        <div class="relative w-full lg:w-72">
                            <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                            <input type="text" x-model="search" placeholder="Search schools..."
                                   class="w-full pl-10 pr-4 py-2.5 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-xl text-sm text-gray-900 dark:text-gray-100 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                        </div>
                    </div>
                </div>

                <!-- Empty state -->
                <?php if (count($schools) === 0): ?>
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <i class="fas fa-school text-5xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No schools registered yet.</p>
                    <a href="super_admin.php?tab=register" class="inline-block mt-4 text-indigo-600 dark:text-indigo-400 font-semibold hover:underline">Register a school</a>
                </div>
                <?php endif; ?>

                <!-- School Cards -->
                <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                    <?php foreach ($schools as $school):
                        $sid = (int) $school['id'];
                        $level = $school['level'];
                    ?>
                    <div class="school-card bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden"
                         data-level="<?php echo htmlspecialchars($level, ENT_QUOTES); ?>"
                         data-name="<?php echo htmlspecialchars(strtolower($school['name']), ENT_QUOTES); ?>"
                         x-show="matchesFilter('<?php echo htmlspecialchars($level, ENT_QUOTES); ?>', '<?php echo htmlspecialchars(strtolower(addslashes($school['name'])), ENT_QUOTES); ?>')"
                         x-transition>

                        <!-- Card Header -->
                        <div class="p-5 border-b border-gray-100 dark:border-gray-700"
                             style="background-image: linear-gradient(to right, #1e293b, #334155);">
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-center gap-3 min-w-0">
                                    <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-lg flex-shrink-0">
                                        <?php echo strtoupper(substr($school['name'], 0, 1)); ?>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="font-bold text-white truncate" style="color:#ffffff;"><?php echo htmlspecialchars($school['name']); ?></h3>
                                        <p class="text-xs font-mono uppercase" style="color:#cbd5e1;"><?php echo htmlspecialchars($school['code']); ?></p>
                                    </div>
                                </div>
                                <div class="flex flex-col items-end gap-1.5 flex-shrink-0">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold border <?php echo levelBadgeClasses($level); ?>">
                                        <i class="fas fa-crown mr-1 text-[10px]"></i><?php echo htmlspecialchars(levelLabel($level)); ?>
                                    </span>
                                    <?php if (($school['status'] ?? '') !== 'active'): ?>
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-rose-100 text-rose-700 border border-rose-200">
                                        <i class="fas fa-ban mr-1 text-[10px]"></i>Suspended
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- At-a-glance counter + bulk actions -->
                            <div class="flex items-center justify-between mt-4">
                                <div class="text-xs" style="color:#cbd5e1;">
                                    <span class="font-bold" style="color:#34d399;"><?php echo $school['enabled_count']; ?></span>
                                    of <?php echo count($modules); ?> modules active
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button"
                                            @click="confirmBulk(<?php echo $sid; ?>, '<?php echo htmlspecialchars(addslashes($school['name']), ENT_QUOTES); ?>', 1)"
                                            class="text-xs font-semibold hover:underline" style="color:#34d399;">
                                        <i class="fas fa-check-double mr-1"></i>Enable all
                                    </button>
                                    <span style="color:#64748b;">|</span>
                                    <button type="button"
                                            @click="confirmBulk(<?php echo $sid; ?>, '<?php echo htmlspecialchars(addslashes($school['name']), ENT_QUOTES); ?>', 0)"
                                            class="text-xs font-semibold hover:underline" style="color:#fb7185;">
                                        <i class="fas fa-ban mr-1"></i>Disable all
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Module Toggles -->
                        <div class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <?php foreach ($modules as $mkey => $mod):
                                $is_on = !isset($access_map[$sid][$mkey]) || $access_map[$sid][$mkey] === 1;
                            ?>
                            <div class="flex items-center justify-between gap-2 p-3 rounded-xl border <?php echo $is_on ? 'border-emerald-200 dark:border-emerald-800' : 'border-gray-100 dark:border-gray-700'; ?>"
                                 style="background-color: <?php echo $is_on ? '#ecfdf5' : '#f9fafb'; ?>;">
                                <div class="flex items-center gap-2.5 min-w-0">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0"
                                         style="background-color: <?php echo $is_on ? '#d1fae5' : '#e5e7eb'; ?>; color: <?php echo $is_on ? '#059669' : '#9ca3af'; ?>;">
                                        <i class="fas <?php echo htmlspecialchars($mod['icon']); ?> text-sm"></i>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-gray-800 dark:text-gray-100 truncate"><?php echo htmlspecialchars($mod['label']); ?></p>
                                        <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo htmlspecialchars($mod['description']); ?></p>
                                    </div>
                                </div>
                                <!-- Toggle switch -->
                                <button type="button" role="switch" aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
                                        @click="confirmToggle(<?php echo $sid; ?>, '<?php echo htmlspecialchars(addslashes($school['name']), ENT_QUOTES); ?>', '<?php echo $mkey; ?>', '<?php echo htmlspecialchars(addslashes($mod['label']), ENT_QUOTES); ?>', <?php echo $is_on ? 'true' : 'false'; ?>)"
                                        class="relative inline-flex flex-shrink-0 h-6 w-11 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                                        style="background-color: <?php echo $is_on ? '#10b981' : '#cbd5e1'; ?>;"
                                        title="<?php echo $is_on ? 'Click to disable' : 'Click to enable'; ?>">
                                    <span class="inline-block h-5 w-5 mt-0.5 transform rounded-full shadow transition-transform duration-200 <?php echo $is_on ? 'translate-x-5' : 'translate-x-0.5'; ?>"
                                          style="background-color:#ffffff;"></span>
                                </button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- "No matches" message controlled by Alpine -->
                <div x-show="visibleCount() === 0 && <?php echo count($schools); ?> > 0" x-cloak
                     class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700 p-12 text-center mt-6">
                    <i class="fas fa-search text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="text-gray-500 dark:text-gray-400 font-medium">No schools match your filter.</p>
                </div>

            </div>
        </main>
    </div>

    <!-- ============ Confirmation Modal ============ -->
    <div x-show="modalOpen" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center p-4"
         x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="modalOpen = false"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-md p-6"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100">
            <div class="flex items-start gap-4">
                <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0"
                     :class="pending.state ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'">
                    <i class="fas text-xl" :class="pending.state ? 'fa-toggle-on' : 'fa-toggle-off'"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white" x-text="pending.title"></h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1" x-html="pending.message"></p>
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button @click="modalOpen = false"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 transition-colors">
                    Cancel
                </button>
                <button @click="submitPending()"
                        class="px-4 py-2 rounded-xl text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        style="background-color:#059669;">
                    <i class="fas fa-check mr-1"></i> <span x-text="pending.confirmLabel"></span>
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden form used to persist a confirmed change (PRG pattern) -->
    <form method="POST" action="module_access.php" x-ref="actionForm" class="hidden">
        <input type="hidden" name="action"     x-model="form.action">
        <input type="hidden" name="school_id"  x-model="form.school_id">
        <input type="hidden" name="module_key" x-model="form.module_key">
        <input type="hidden" name="new_state"  x-model="form.new_state">
        <input type="hidden" name="level"      value="<?php echo htmlspecialchars($active_level, ENT_QUOTES); ?>">
    </form>
</div>

<style>[x-cloak]{display:none!important;}</style>

<script>
function moduleAccess(initialLevel) {
    return {
        level: initialLevel || 'all',
        search: '',
        modalOpen: false,
        pending: { title: '', message: '', confirmLabel: 'Confirm', state: true },
        form: { action: '', school_id: '', module_key: '', new_state: '' },

        setLevel(lv) {
            this.level = lv;
            // Persist filter selection in the URL without reloading.
            const url = new URL(window.location);
            if (lv === 'all') { url.searchParams.delete('level'); }
            else { url.searchParams.set('level', lv); }
            window.history.replaceState({}, '', url);
        },

        matchesFilter(cardLevel, cardName) {
            const levelOk = (this.level === 'all') || (cardLevel === this.level);
            const searchOk = this.search.trim() === '' || cardName.includes(this.search.trim().toLowerCase());
            return levelOk && searchOk;
        },

        visibleCount() {
            let count = 0;
            document.querySelectorAll('.school-card').forEach(card => {
                if (this.matchesFilter(card.dataset.level, card.dataset.name)) count++;
            });
            return count;
        },

        confirmToggle(schoolId, schoolName, moduleKey, moduleLabel, currentlyOn) {
            const newState = currentlyOn ? 0 : 1;
            this.pending = {
                title: (newState ? 'Enable' : 'Disable') + ' Module',
                message: 'Are you sure you want to <strong>' + (newState ? 'enable' : 'disable') +
                         '</strong> <strong>' + moduleLabel + '</strong> for <strong>' + schoolName + '</strong>?' +
                         (newState ? '' : '<br><span class="text-xs text-rose-500">Their users will immediately lose access to this section.</span>'),
                confirmLabel: newState ? 'Enable' : 'Disable',
                state: newState === 1,
            };
            this.form = { action: 'toggle_module', school_id: schoolId, module_key: moduleKey, new_state: newState };
            this.modalOpen = true;
        },

        confirmBulk(schoolId, schoolName, newState) {
            this.pending = {
                title: (newState ? 'Enable' : 'Disable') + ' All Modules',
                message: 'Are you sure you want to ' + (newState ? 'enable' : 'disable') +
                         ' <strong>all modules</strong> for <strong>' + schoolName + '</strong>?',
                confirmLabel: newState ? 'Enable All' : 'Disable All',
                state: newState === 1,
            };
            this.form = { action: 'toggle_all', school_id: schoolId, module_key: '', new_state: newState };
            this.modalOpen = true;
        },

        submitPending() {
            this.modalOpen = false;
            this.$nextTick(() => this.$refs.actionForm.submit());
        },
    };
}
</script>
