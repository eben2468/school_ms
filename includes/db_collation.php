<?php
/**
 * Database collation normalization.
 * --------------------------------------------------------------------------
 * Tenant databases were created with a default collation (utf8mb4_unicode_ci)
 * that differs from the collation of many tables copied/migrated into them
 * (utf8mb4_general_ci). MySQL refuses to compare two string columns of
 * different collations, so any query that JOINs or compares string columns
 * across a unicode_ci table and a general_ci table dies with:
 *
 *     SQLSTATE[HY000] 1267 Illegal mix of collations
 *     (utf8mb4_unicode_ci,IMPLICIT) and (utf8mb4_general_ci,IMPLICIT)
 *
 * That single mismatch is what 500s many tenant pages (e.g. staff/departments.php
 * joins teacher_profiles to staff_departments on a string column).
 *
 * The cure is to make every table — and the database default — use ONE
 * collation. We standardize on utf8mb4_general_ci because that is the central
 * database's default and what the app's own CREATE TABLE statements already use
 * (so newly created tables match without extra work).
 */

if (!defined('DB_STANDARD_CHARSET'))   { define('DB_STANDARD_CHARSET', 'utf8mb4'); }
if (!defined('DB_STANDARD_COLLATION')) { define('DB_STANDARD_COLLATION', 'utf8mb4_general_ci'); }

if (!function_exists('databaseCollationOffenders')) {
    /**
     * Names of base tables in the active database that have a table-level or
     * column-level collation different from the standard. Cheap: a single
     * information_schema scan, no DDL.
     */
    function databaseCollationOffenders(PDO $db, $collation = DB_STANDARD_COLLATION) {
        $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
        if (!$dbName) return [];

        $sql = "
            SELECT DISTINCT t.table_name
            FROM information_schema.tables t
            WHERE t.table_schema = :db
              AND t.table_type = 'BASE TABLE'
              AND t.table_collation IS NOT NULL
              AND t.table_collation <> :coll
            UNION
            SELECT DISTINCT c.table_name
            FROM information_schema.columns c
            JOIN information_schema.tables bt
              ON bt.table_schema = c.table_schema
             AND bt.table_name = c.table_name
             AND bt.table_type = 'BASE TABLE'
            WHERE c.table_schema = :db2
              AND c.collation_name IS NOT NULL
              AND c.collation_name <> :coll2
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([
            ':db' => $dbName, ':coll' => $collation,
            ':db2' => $dbName, ':coll2' => $collation,
        ]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }
}

if (!function_exists('normalizeDatabaseCollation')) {
    /**
     * Force the active database and every one of its tables onto a single
     * charset/collation, eliminating "Illegal mix of collations" errors.
     *
     * Idempotent and safe to call repeatedly: it only touches the database
     * default and the specific tables that are out of standard, and converts
     * data in place (utf8mb4 -> utf8mb4, so no character data is lost).
     *
     * @return array names of tables that were converted (empty when already clean)
     */
    function normalizeDatabaseCollation(PDO $db, $charset = DB_STANDARD_CHARSET, $collation = DB_STANDARD_COLLATION) {
        $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
        if (!$dbName) return [];

        // Align the database default so tables created later inherit the standard.
        try {
            $db->exec("ALTER DATABASE `" . str_replace('`', '', $dbName) . "` CHARACTER SET $charset COLLATE $collation");
        } catch (PDOException $e) {
            error_log("normalizeDatabaseCollation: ALTER DATABASE {$dbName} failed: " . $e->getMessage());
        }

        $offenders = databaseCollationOffenders($db, $collation);
        if (!$offenders) return [];

        $converted = [];
        $db->exec("SET FOREIGN_KEY_CHECKS = 0");
        try {
            foreach ($offenders as $table) {
                $safe = str_replace('`', '', $table);
                try {
                    $db->exec("ALTER TABLE `$safe` CONVERT TO CHARACTER SET $charset COLLATE $collation");
                    $converted[] = $table;
                } catch (PDOException $e) {
                    error_log("normalizeDatabaseCollation: convert `{$dbName}`.`{$table}` failed: " . $e->getMessage());
                }
            }
        } finally {
            $db->exec("SET FOREIGN_KEY_CHECKS = 1");
        }
        return $converted;
    }
}
