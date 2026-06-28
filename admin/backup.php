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

// The database currently in use (tenant-aware) — default/own backup target
$current_db = !empty($_SESSION['school_db_name']) ? $_SESSION['school_db_name'] : DB_NAME;

// Resolve MySQL client binaries (XAMPP default, with PATH fallback)
$mysqldump_bin = file_exists('C:\\xampp\\mysql\\bin\\mysqldump.exe') ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump';
$mysql_bin     = file_exists('C:\\xampp\\mysql\\bin\\mysql.exe') ? 'C:\\xampp\\mysql\\bin\\mysql.exe' : 'mysql';

$backup_dir = $_SERVER['DOCUMENT_ROOT'] . '/backups/';
if (!is_dir($backup_dir)) { @mkdir($backup_dir, 0755, true); }

$message = '';
$error = '';

// Databases this admin may back up / manage:
//   Super admin  -> the main system DB + every registered school's DB.
//   School admin -> only their own school's DB.
$db_options = []; // db_name => display label
if ($is_super) {
    $db_options[DB_NAME] = 'Main System Database';
    try {
        $sstmt = $db->query("SELECT name, code, db_name FROM schools ORDER BY name ASC");
        foreach ($sstmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
            $db_options[$s['db_name']] = $s['name'] . ' (' . $s['code'] . ')';
        }
    } catch (PDOException $e) {
        // schools table unavailable on this connection — fall back to current DB only
    }
} else {
    $label = 'Your School Database';
    try {
        $st = $db->prepare("SELECT name FROM schools WHERE db_name = :d LIMIT 1");
        $st->execute([':d' => $current_db]);
        if ($n = $st->fetchColumn()) { $label = $n; }
    } catch (PDOException $e) {}
    $db_options[$current_db] = $label;
}

// Only allow operating on well-formed .sql filenames inside the backup dir
function safeBackupName($name) {
    return preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name) === 1;
}

function buildAuthArgs() {
    $args = ' --host=' . escapeshellarg(DB_HOST) . ' --user=' . escapeshellarg(DB_USER);
    if (DB_PASS !== '') { $args .= ' --password=' . escapeshellarg(DB_PASS); }
    return $args;
}

// Sanitised db name used as a backup filename prefix
function dbFilePrefix($db_name) {
    return preg_replace('/[^A-Za-z0-9_]/', '', $db_name) . '_backup_';
}

// Which registered database does this backup file belong to? (null if none in the set)
function parseBackupDb($name, $candidate_dbs) {
    $match = null;
    foreach (array_keys($candidate_dbs) as $db_name) {
        $prefix = dbFilePrefix($db_name);
        // Longest matching prefix wins (handles db names that are prefixes of others)
        if (strpos($name, $prefix) === 0 && (!$match || strlen($prefix) > strlen(dbFilePrefix($match)))) {
            $match = $db_name;
        }
    }
    return $match;
}

// May the current user act on this backup file? Super admins manage all files;
// school admins only files belonging to their own database.
function canAccessFile($name, $db_options, $is_super) {
    if (!safeBackupName($name)) return false;
    if ($is_super) return true;
    return parseBackupDb($name, $db_options) !== null;
}

// ---------------------------------------------------------------------------
// Download (stream a backup file) — handled before any HTML output
// ---------------------------------------------------------------------------
if (isset($_GET['download'])) {
    $name = basename($_GET['download']);
    $path = $backup_dir . $name;
    if (canAccessFile($name, $db_options, $is_super) && is_file($path)) {
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit();
    }
    header('Location: backup.php?err=notfound');
    exit();
}

// ---------------------------------------------------------------------------
// POST actions
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {

    // Create backup — super admins choose the target school DB; school admins use their own
    if (isset($_POST['create_backup'])) {
        $target_db = $is_super ? ($_POST['backup_db'] ?? DB_NAME) : $current_db;

        if (!isset($db_options[$target_db])) {
            $error = "Invalid backup target selected.";
        } else {
            $timestamp = date('Y-m-d_H-i-s');
            $filename = dbFilePrefix($target_db) . "$timestamp.sql";
            $filepath = $backup_dir . $filename;

            $cmd = escapeshellarg($mysqldump_bin) . buildAuthArgs()
                 . ' --single-transaction --routines --events ' . escapeshellarg($target_db)
                 . ' > ' . escapeshellarg($filepath) . ' 2>&1';
            @exec($cmd, $out, $ret);

            if ($ret === 0 && is_file($filepath) && filesize($filepath) > 0) {
                $message = "Backup of " . htmlspecialchars($db_options[$target_db]) . " created successfully (" . number_format(filesize($filepath) / 1024, 1) . " KB).";
                logAudit($db, 'manual_backup', "Created backup '$filename' for '{$db_options[$target_db]}' [$target_db].");
            } else {
                @unlink($filepath);
                $error = "Failed to create backup. Ensure the MySQL tools are available. " . (isset($out) ? htmlspecialchars(implode(' ', array_slice($out, 0, 3))) : '');
            }
        }
    }

    // Restore from an existing backup — restores into the DB the backup came from
    if (isset($_POST['restore_backup'])) {
        $name = basename($_POST['backup_file'] ?? '');
        $path = $backup_dir . $name;
        $target_db = parseBackupDb($name, $db_options);

        if (!canAccessFile($name, $db_options, $is_super) || !is_file($path)) {
            $error = "You are not authorized to restore that backup, or it does not exist.";
        } elseif ($target_db === null) {
            $error = "Could not determine which registered database this backup belongs to, so it cannot be restored.";
        } else {
            $cmd = escapeshellarg($mysql_bin) . buildAuthArgs() . ' ' . escapeshellarg($target_db)
                 . ' < ' . escapeshellarg($path) . ' 2>&1';
            @exec($cmd, $out, $ret);
            if ($ret === 0) {
                $message = htmlspecialchars($db_options[$target_db]) . " restored successfully from $name.";
                logAudit($db, 'backup_restored', "Restored '{$db_options[$target_db]}' [$target_db] from backup '$name'.");
            } else {
                $error = "Restore failed. " . (isset($out) ? htmlspecialchars(implode(' ', array_slice($out, 0, 3))) : '');
            }
        }
    }

    // Delete a backup
    if (isset($_POST['delete_backup'])) {
        $name = basename($_POST['backup_file'] ?? '');
        $path = $backup_dir . $name;
        if (canAccessFile($name, $db_options, $is_super) && is_file($path) && @unlink($path)) {
            $message = "Backup '$name' deleted.";
            logAudit($db, 'backup_deleted', "Deleted backup '$name'.");
        } else {
            $error = "Failed to delete backup file, or you are not authorized to.";
        }
    }
}

if (isset($_GET['err']) && $_GET['err'] === 'notfound') { $error = "Backup file not found or not accessible."; }

// Gather existing backups (only those the current user may see)
$backups = [];
if (is_dir($backup_dir)) {
    foreach (scandir($backup_dir) as $file) {
        if (!safeBackupName($file)) continue;
        $fdb = parseBackupDb($file, $db_options);
        // School admins only see their own DB's backups
        if (!$is_super && $fdb === null) continue;
        $backups[] = [
            'name'  => $file,
            'size'  => filesize($backup_dir . $file),
            'date'  => filemtime($backup_dir . $file),
            'db'    => $fdb,
            'label' => $fdb !== null ? $db_options[$fdb] : 'Unknown database',
        ];
    }
    usort($backups, fn($a, $b) => $b['date'] - $a['date']);
}
$total_size = array_sum(array_column($backups, 'size'));

$title = "Backup & Restore";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="bg-gradient-to-r from-blue-600 via-cyan-600 to-sky-700 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2"><i class="fas fa-database mr-3"></i>Backup &amp; Restore</h1>
                            <p class="text-blue-100">Create, download and restore backups of your database</p>
                        </div>
                        <div class="hidden md:flex items-center gap-6 text-right">
                            <div>
                                <div class="text-2xl font-bold"><?php echo count($backups); ?></div>
                                <div class="text-xs text-blue-100">Backups</div>
                            </div>
                            <div>
                                <div class="text-2xl font-bold"><?php echo number_format($total_size / 1048576, 1); ?> MB</div>
                                <div class="text-xs text-blue-100">Total size</div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-start"><i class="fas fa-check-circle mr-2 mt-0.5"></i><div><?php echo htmlspecialchars($message); ?></div></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-start"><i class="fas fa-exclamation-circle mr-2 mt-0.5"></i><div><?php echo $error; ?></div></div>
                <?php endif; ?>

                <!-- Create backup -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-6">
                        <div class="flex items-start gap-4 mb-4">
                            <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-400 flex items-center justify-center flex-shrink-0">
                                <i class="fas fa-cloud-arrow-down text-xl"></i>
                            </div>
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Create a New Backup</h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo $is_super
                                        ? 'Choose a registered school (or the main system) and create a full SQL snapshot of its database.'
                                        : 'Full SQL snapshot of your school database including all tables and data.'; ?>
                                </p>
                            </div>
                        </div>
                        <form method="POST" class="flex flex-col sm:flex-row sm:items-end gap-4">
                            <div class="flex-1">
                                <label class="block text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Database to back up</label>
                                <?php if ($is_super): ?>
                                <select name="backup_db" class="w-full px-3 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                    <?php foreach ($db_options as $dbname => $dblabel): ?>
                                    <option value="<?php echo htmlspecialchars($dbname); ?>" <?php echo $dbname === $current_db ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dblabel); ?> &nbsp;—&nbsp; <?php echo htmlspecialchars($dbname); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php else: ?>
                                <div class="w-full px-3 py-2.5 border border-gray-200 dark:border-gray-700 rounded-lg bg-gray-50 dark:bg-gray-700/50 text-sm text-gray-700 dark:text-gray-200">
                                    <i class="fas fa-school mr-2 text-gray-400"></i><?php echo htmlspecialchars($db_options[$current_db]); ?>
                                    <span class="text-gray-400 font-mono ml-1">(<?php echo htmlspecialchars($current_db); ?>)</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <button type="submit" name="create_backup" class="w-full sm:w-auto px-6 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-lg font-medium flex items-center justify-center">
                                <i class="fas fa-plus mr-2"></i>Create Backup
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Restore warning -->
                <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-xl p-4 mb-6 flex items-start gap-3 text-sm text-amber-800 dark:text-amber-200">
                    <i class="fas fa-triangle-exclamation mt-0.5"></i>
                    <div><strong>Restoring</strong> overwrites current data with the contents of the selected backup. This cannot be undone — create a fresh backup first if unsure.</div>
                </div>

                <!-- Backups list -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                        <h2 class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-box-archive mr-2 text-gray-400"></i>Available Backups</h2>
                        <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo count($backups); ?> file<?php echo count($backups) === 1 ? '' : 's'; ?></span>
                    </div>

                    <?php if (empty($backups)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-database text-gray-300 dark:text-gray-600 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No backups yet</h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">Create your first backup to protect your data.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700/50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Backup File</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">School / Database</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Size</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Created</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($backups as $b): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                                    <td class="px-6 py-4">
                                        <div class="flex items-center">
                                            <i class="fas fa-file-code text-blue-500 mr-3"></i>
                                            <span class="text-sm font-medium text-gray-900 dark:text-white font-mono break-all"><?php echo htmlspecialchars($b['name']); ?></span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full <?php echo $b['db'] !== null ? 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' : 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'; ?>">
                                            <i class="fas fa-<?php echo $b['db'] !== null ? 'school' : 'circle-question'; ?> mr-1.5 mt-0.5"></i><?php echo htmlspecialchars($b['label']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300"><?php echo number_format($b['size'] / 1024, 1); ?> KB</td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300"><?php echo date('M j, Y g:i A', $b['date']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                        <div class="flex justify-end gap-2">
                                            <a href="?download=<?php echo urlencode($b['name']); ?>" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 rounded-md" title="Download"><i class="fas fa-download"></i></a>
                                            <form method="POST" onsubmit="return confirm('Restore the database from this backup? Current data will be OVERWRITTEN.');">
                                                <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($b['name']); ?>">
                                                <button type="submit" name="restore_backup" class="px-3 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded-md" title="Restore"><i class="fas fa-rotate-left"></i></button>
                                            </form>
                                            <form method="POST" onsubmit="return confirm('Permanently delete this backup file?');">
                                                <input type="hidden" name="backup_file" value="<?php echo htmlspecialchars($b['name']); ?>">
                                                <button type="submit" name="delete_backup" class="px-3 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded-md" title="Delete"><i class="fas fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <div class="lg:ml-0"><?php include '../includes/footer.php'; ?></div>
    </div>
</div>
