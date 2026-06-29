<?php
/**
 * Migration: Nadics AI assistant schema.
 * --------------------------------------------------------------------------
 * Applies the database additions introduced with the Nadics AI assistant to the
 * central directory AND every registered school:
 *   1. school_settings: ai_provider, ai_api_key, ai_model (shared AI config)
 *      and nadics_enabled, nadics_name, nadics_persona (assistant behaviour).
 *   2. nadics_ai_logs: per-tenant interaction log table.
 *
 * The application also self-heals these on demand, so running this is optional —
 * but it pre-applies everything cleanly across all schools in one pass.
 *
 * Safe to re-run: every step is idempotent (only adds what is missing).
 *
 * Run from CLI:   php maintenance/migrate_nadics_ai.php
 * Or in browser:  /maintenance/migrate_nadics_ai.php   (super_admin only)
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/schema_helpers.php';

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden: super administrators only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function out($msg) { echo $msg . "\n"; @ob_flush(); @flush(); }

// The school_settings columns this feature needs (name => column definition).
$settingsColumns = [
    'ai_provider'    => "VARCHAR(50) DEFAULT 'builtin'",
    'ai_api_key'     => "VARCHAR(255) DEFAULT ''",
    'ai_model'       => "VARCHAR(100) DEFAULT 'gemini-2.5-flash'",
    'nadics_enabled' => "ENUM('0','1') DEFAULT '1'",
    'nadics_name'    => "VARCHAR(100) DEFAULT 'Nadics AI'",
    'nadics_persona' => "TEXT NULL",
];

/**
 * Apply the migration to a single database connection.
 */
function migrateNadicsOnDb(PDO $db, $settingsColumns) {
    // 1. school_settings columns (only if the table exists in this DB).
    $hasSettings = false;
    try {
        $hasSettings = (bool)$db->query("SHOW TABLES LIKE 'school_settings'")->fetchColumn();
    } catch (PDOException $e) { /* ignore */ }

    if ($hasSettings) {
        ensureColumns($db, 'school_settings', $settingsColumns);
        out("    school_settings: columns ensured (" . implode(', ', array_keys($settingsColumns)) . ")");
    } else {
        out("    school_settings: table not present — skipped");
    }

    // 2. nadics_ai_logs table.
    ensureNadicsAiTable($db);
    $hasLogs = false;
    try {
        $hasLogs = (bool)$db->query("SHOW TABLES LIKE 'nadics_ai_logs'")->fetchColumn();
    } catch (PDOException $e) { /* ignore */ }
    out("    nadics_ai_logs: " . ($hasLogs ? 'present' : 'MISSING (check error log)'));
}

out("== Migration: Nadics AI assistant schema ==");
out("");

// ---- Central directory ----
try {
    $central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    out("FATAL: cannot connect to central DB (" . DB_NAME . "): " . $e->getMessage());
    exit(1);
}

out("[central] " . DB_NAME);
migrateNadicsOnDb($central, $settingsColumns);
out("");

// ---- Every registered school (multi-tenant) ----
$schools = [];
try {
    $schools = $central->query("SELECT id, name, db_name FROM schools ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    out("NOTE: no 'schools' table in central DB — single-database install, central done above.");
}

foreach ($schools as $s) {
    $dbName = $s['db_name'];
    out("[school #{$s['id']}] {$s['name']}  ({$dbName})");
    if (empty($dbName)) { out("    skipped: no db_name"); out(""); continue; }
    try {
        $tenant = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        out("    ERROR: cannot connect ({$e->getMessage()})");
        out("");
        continue;
    }
    migrateNadicsOnDb($tenant, $settingsColumns);
    out("");
}

out("== Done ==");
