<?php
/**
 * auto_backup.php
 * ---------------
 * Opportunistic scheduled-backup runner used by includes/system_guard.php.
 * When Automatic Backup is enabled and a backup is due per the configured
 * frequency, it launches a (detached) mysqldump of the active database. This
 * replaces an OS cron job, which XAMPP installs don't have by default.
 *
 * Backups are written with the same filename convention as admin/backup.php so
 * they appear in (and are managed by) the Backup Manager.
 */

if (!function_exists('runScheduledBackupIfDue')) {

    function autoBackupIntervalSeconds($frequency) {
        switch ($frequency) {
            case 'daily':   return 86400;       // 1 day
            case 'weekly':  return 604800;      // 7 days
            case 'monthly': return 2592000;     // ~30 days
            default:        return 0;           // 'manual' or unknown -> never
        }
    }

    function runScheduledBackupIfDue() {
        try {
            if (!defined('DB_HOST')) {
                return; // DB layer not loaded
            }
            if (getSchoolSetting('auto_backup', 'enabled') !== 'enabled') {
                return;
            }
            $interval = autoBackupIntervalSeconds(getSchoolSetting('backup_frequency', 'weekly'));
            if ($interval <= 0) {
                return; // manual only
            }

            $target_db = !empty($_SESSION['school_db_name']) ? $_SESSION['school_db_name'] : DB_NAME;
            if (!preg_match('/^[A-Za-z0-9_]+$/', $target_db)) {
                return;
            }

            $backup_dir = __DIR__ . '/../backups/';
            if (!is_dir($backup_dir)) {
                @mkdir($backup_dir, 0755, true);
            }

            // Per-database "last run" marker. Written BEFORE dumping so a slow
            // dump or concurrent request can't trigger a second run.
            $marker = $backup_dir . '.auto_' . $target_db . '.last';
            if (is_file($marker) && (time() - filemtime($marker)) < $interval) {
                return; // not due yet
            }
            @file_put_contents($marker, (string) time());

            $mysqldump_bin = file_exists('C:\\xampp\\mysql\\bin\\mysqldump.exe')
                ? 'C:\\xampp\\mysql\\bin\\mysqldump.exe' : 'mysqldump';

            $timestamp = date('Y-m-d_H-i-s');
            $prefix    = preg_replace('/[^A-Za-z0-9_]/', '', $target_db) . '_backup_';
            $filepath  = $backup_dir . $prefix . $timestamp . '.sql';

            $auth = ' --host=' . escapeshellarg(DB_HOST) . ' --user=' . escapeshellarg(DB_USER);
            if (DB_PASS !== '') {
                $auth .= ' --password=' . escapeshellarg(DB_PASS);
            }
            $cmd = escapeshellarg($mysqldump_bin) . $auth
                 . ' --single-transaction --routines --events ' . escapeshellarg($target_db)
                 . ' > ' . escapeshellarg($filepath) . ' 2>&1';

            // Launch detached so the page render is not blocked by the dump.
            if (stripos(PHP_OS, 'WIN') === 0) {
                @pclose(@popen('start /B "" cmd /c ' . $cmd, 'r'));
            } else {
                @exec($cmd . ' &');
            }

            if (function_exists('logAudit') && isset($GLOBALS['pdo']) && $GLOBALS['pdo']) {
                logAudit($GLOBALS['pdo'], 'auto_backup', "Scheduled automatic backup started for database '{$target_db}'.");
            }
        } catch (Throwable $e) {
            error_log('runScheduledBackupIfDue failed: ' . $e->getMessage());
        }
    }
}
