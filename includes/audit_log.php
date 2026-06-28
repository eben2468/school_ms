<?php
/**
 * audit_log.php
 * Tiny helper to record an entry in the audit_logs table.
 * Safe to include multiple times.
 */
/**
 * Does the given column exist on a table in the CURRENT database?
 * Used to stay compatible with both the main multi-tenant DB (which has
 * school_id columns) and isolated per-school tenant DBs (which do not).
 */
if (!function_exists('dbHasColumn')) {
    function dbHasColumn($db, $table, $column) {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
            $st->execute([':t' => $table, ':c' => $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('dbHasTable')) {
    function dbHasTable($db, $table) {
        try {
            $st = $db->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
            $st->execute([':t' => $table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('ensureAuditLogsTable')) {
    /**
     * Lazily create the audit_logs table in the CURRENT database if it is
     * missing. Tenant (per-school) databases are provisioned without it, so the
     * first attempt to log there must self-heal. Runs at most once per DB handle
     * per request thanks to the static cache.
     */
    function ensureAuditLogsTable($db) {
        static $ensured = [];
        $key = spl_object_id($db);
        if (isset($ensured[$key])) {
            return;
        }
        $ensured[$key] = true;
        try {
            $db->exec(
                "CREATE TABLE IF NOT EXISTS audit_logs (
                    id INT(11) NOT NULL AUTO_INCREMENT,
                    school_id INT(11) DEFAULT NULL,
                    user_id INT(11) DEFAULT NULL,
                    action VARCHAR(100) NOT NULL,
                    details TEXT DEFAULT NULL,
                    ip_address VARCHAR(45) DEFAULT NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    KEY idx_user (user_id),
                    KEY idx_action (action),
                    KEY idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        } catch (Exception $e) {
            // Best effort only — never break the calling page.
        }
    }
}

if (!function_exists('logAudit')) {
    /**
     * Record one entry in the audit_logs table of the given connection.
     * $db should be the active (tenant-aware) PDO handle so school-level
     * activity lands in that school's isolated database.
     */
    function logAudit($db, $action, $details = null) {
        if (!$db) {
            return;
        }
        try {
            ensureAuditLogsTable($db);
            $stmt = $db->prepare("INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
                                  VALUES (:sid, :uid, :action, :details, :ip)");
            $stmt->execute([
                ':sid'     => $_SESSION['school_id'] ?? null,
                ':uid'     => $_SESSION['user_id'] ?? null,
                ':action'  => substr((string)$action, 0, 100),
                ':details' => $details !== null ? substr((string)$details, 0, 2000) : null,
                ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
            ]);
        } catch (Exception $e) {
            // Logging must never break the calling page.
            error_log('logAudit failed: ' . $e->getMessage());
        }
    }
}
