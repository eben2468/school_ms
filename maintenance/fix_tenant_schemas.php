<?php
/**
 * One-time / on-demand maintenance: bring every database up to standard.
 * --------------------------------------------------------------------------
 * For the central directory and EVERY registered school it:
 *   1. Replicates any application tables the tenant is still missing
 *      (replicateCentralSchemaToTenant) — fixes "Base table not found" 500s.
 *   2. Normalizes the database + all tables onto a single collation
 *      (normalizeDatabaseCollation) — fixes "Illegal mix of collations" 500s.
 *
 * Safe to re-run: every step is idempotent and only touches what is out of
 * standard. No tenant data is dropped or altered destructively.
 *
 * Run from CLI:   php maintenance/fix_tenant_schemas.php
 * Or in browser:  /maintenance/fix_tenant_schemas.php   (super_admin only)
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/tenant_provisioning.php';
require_once __DIR__ . '/../includes/db_collation.php';

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden: super administrators only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$nl = "\n";
function out($msg) { echo $msg . "\n"; @ob_flush(); @flush(); }

$central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

out("== Maintenance: schema + collation reconciliation ==");
out("Standard collation: " . DB_STANDARD_COLLATION);
out("");

// ---- 1. Central directory: collation only (it is the replication source) ----
out("[central] {" . DB_NAME . "}");
$conv = normalizeDatabaseCollation($central);
out("  collation: " . (count($conv) ? "converted " . count($conv) . " table(s): " . implode(', ', $conv) : "already uniform"));
out("");

// ---- 2. Every registered school ----
$schools = $central->query("SELECT id, name, db_name FROM schools ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if (!$schools) {
    out("No schools registered.");
}

foreach ($schools as $s) {
    $dbName = $s['db_name'];
    out("[school #{$s['id']}] {$s['name']}  ({$dbName})");
    if (empty($dbName)) { out("  skipped: no db_name"); out(""); continue; }

    try {
        $tenant = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        out("  ERROR: cannot connect ({$e->getMessage()})");
        out("");
        continue;
    }

    // 2a. Create any missing tables from the central schema.
    try {
        $created = replicateCentralSchemaToTenant($tenant, $central);
        out("  tables: " . (count($created) ? "created " . count($created) . ": " . implode(', ', $created) : "none missing"));
    } catch (PDOException $e) {
        out("  tables: ERROR " . $e->getMessage());
    }

    // 2b. Unify collation.
    try {
        $conv = normalizeDatabaseCollation($tenant);
        out("  collation: " . (count($conv) ? "converted " . count($conv) . " table(s)" : "already uniform"));
    } catch (PDOException $e) {
        out("  collation: ERROR " . $e->getMessage());
    }

    out("");
}

out("== Done ==");
