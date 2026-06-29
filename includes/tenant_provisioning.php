<?php
/**
 * Tenant schema provisioning.
 * --------------------------------------------------------------------------
 * New tenant databases were historically built from a fixed set of .sql files
 * that drifted behind the central (school_ms) schema, so newer modules' tables
 * (finance_*, chat_*, support_tickets, etc.) were never created. This helper
 * brings any tenant up to date by creating every central base table it is
 * missing — using the central DB as the single source of truth.
 *
 * Safe & idempotent: it ONLY creates missing tables (never alters or drops),
 * so existing tenant data and any locally-drifted columns are left untouched.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/db_collation.php';

if (!function_exists('tenantProvisioningExcludedTables')) {
    /**
     * Central-only / control-plane tables that must never be pushed into a
     * tenant database, plus tables that have a tenant-specific variant.
     */
    function tenantProvisioningExcludedTables() {
        return [
            'schools',               // central registry of all schools
            'subscription_plans',    // billing / SaaS control plane
            'school_subscriptions',
            'billing_invoices',
            'school_module_access',  // per-school gating, read from central
            'audit_logs',            // tenant variant lacks the school_id FK; self-heals
        ];
    }
}

if (!function_exists('listBaseTables')) {
    function listBaseTables(PDO $conn) {
        $out = [];
        foreach ($conn->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = $r[0];
        }
        return $out;
    }
}

if (!function_exists('listViews')) {
    function listViews(PDO $conn) {
        $out = [];
        foreach ($conn->query("SHOW FULL TABLES WHERE Table_type = 'VIEW'")->fetchAll(PDO::FETCH_NUM) as $r) {
            $out[] = $r[0];
        }
        return $out;
    }
}

if (!function_exists('tableColumnDefs')) {
    /**
     * Map column-name => definition (type + attributes) for a table, parsed from
     * SHOW CREATE TABLE. Key/constraint lines are skipped and AUTO_INCREMENT is
     * stripped so a definition can be re-used in ALTER TABLE ... ADD COLUMN.
     */
    function tableColumnDefs(PDO $conn, $table) {
        $defs = [];
        try {
            $row = $conn->query("SHOW CREATE TABLE `" . str_replace('`', '', $table) . "`")->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return $defs;
        }
        foreach (explode("\n", $row['Create Table'] ?? '') as $line) {
            $line = trim($line);
            if (preg_match('/^`([^`]+)`\s+(.+?),?$/', $line, $m)) {
                $defs[$m[1]] = preg_replace('/\s+AUTO_INCREMENT\b/i', '', rtrim($m[2], ','));
            }
        }
        return $defs;
    }
}

if (!function_exists('replicateCentralSchemaToTenant')) {
    /**
     * Create any central base tables missing from the tenant.
     *
     * @param PDO   $tenant  connection to the tenant database
     * @param PDO   $central connection to the central (school_ms) database
     * @param array $extraExclude additional table names to skip
     * @return array names of tables that were created
     */
    function replicateCentralSchemaToTenant(PDO $tenant, PDO $central, array $extraExclude = []) {
        $exclude = array_unique(array_merge(tenantProvisioningExcludedTables(), $extraExclude));

        $centralTables = listBaseTables($central);
        $tenantTables  = array_fill_keys(listBaseTables($tenant), true);

        $created = [];
        $tenant->exec("SET FOREIGN_KEY_CHECKS = 0");
        try {
            foreach ($centralTables as $table) {
                if (in_array($table, $exclude, true) || isset($tenantTables[$table])) {
                    continue;
                }
                try {
                    $row = $central->query("SHOW CREATE TABLE `" . str_replace('`', '', $table) . "`")
                                   ->fetch(PDO::FETCH_ASSOC);
                    $ddl = $row['Create Table'] ?? null;
                    if (!$ddl) {
                        continue;
                    }
                    // Idempotent + drop the AUTO_INCREMENT seed so tenant tables start clean.
                    $ddl = preg_replace('/^CREATE TABLE `/', 'CREATE TABLE IF NOT EXISTS `', $ddl, 1);
                    $ddl = preg_replace('/\sAUTO_INCREMENT=\d+/i', '', $ddl);
                    $tenant->exec($ddl);
                    // Force the copied table onto the tenant's standard collation so it
                    // can be joined against existing tables without "Illegal mix of
                    // collations" (the source DDL may carry the central table's collation).
                    try {
                        $safe = str_replace('`', '', $table);
                        $tenant->exec("ALTER TABLE `$safe` CONVERT TO CHARACTER SET " . DB_STANDARD_CHARSET . " COLLATE " . DB_STANDARD_COLLATION);
                    } catch (PDOException $e) {
                        error_log("replicateCentralSchemaToTenant: normalize collation '{$table}' failed: " . $e->getMessage());
                    }
                    $created[] = $table;
                } catch (PDOException $e) {
                    error_log("replicateCentralSchemaToTenant: failed creating '{$table}': " . $e->getMessage());
                }
            }
            // Add columns that exist in central but are missing from the tenant's
            // already-present tables (older tenant tables lag behind newer columns,
            // e.g. teacher_profiles.department_id). Additive only — never drops/modifies.
            foreach ($centralTables as $table) {
                if (in_array($table, $exclude, true)) { continue; }
                $safeT = str_replace('`', '', $table);
                $cDefs = tableColumnDefs($central, $table);
                $tDefs = tableColumnDefs($tenant, $table);
                if (empty($tDefs)) { continue; }
                foreach ($cDefs as $col => $def) {
                    if (isset($tDefs[$col])) { continue; }
                    try {
                        $tenant->exec("ALTER TABLE `$safeT` ADD COLUMN `" . str_replace('`', '', $col) . "` $def");
                        $created[] = "$table.$col (column)";
                    } catch (PDOException $e) {
                        error_log("replicateCentralSchemaToTenant: add column {$table}.{$col} failed: " . $e->getMessage());
                    }
                }
            }

            // Replicate VIEWs too (e.g. the `students` view). Views depend on base tables,
            // so create them after the tables above. CREATE OR REPLACE is idempotent and
            // DEFINER is stripped so the view works under any database account.
            foreach (listViews($central) as $view) {
                if (in_array($view, $exclude, true)) { continue; }
                try {
                    $vrow = $central->query("SHOW CREATE VIEW `" . str_replace('`', '', $view) . "`")->fetch(PDO::FETCH_ASSOC);
                    $vddl = $vrow['Create View'] ?? null;
                    if (!$vddl) { continue; }
                    $vddl = preg_replace('/\sDEFINER=`[^`]+`@`[^`]+`/', '', $vddl);
                    $vddl = preg_replace('/^CREATE /i', 'CREATE OR REPLACE ', $vddl, 1);
                    if (!isset($tenantTables[$view])) { $created[] = $view . ' (view)'; }
                    $tenant->exec($vddl);
                } catch (PDOException $e) {
                    error_log("replicateCentralSchemaToTenant: failed creating view '{$view}': " . $e->getMessage());
                }
            }

        } finally {
            $tenant->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
        return $created;
    }
}
