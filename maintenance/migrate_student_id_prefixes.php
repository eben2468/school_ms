<?php
/**
 * One-time / on-demand migration: re-prefix tenant student IDs.
 * --------------------------------------------------------------------------
 * Student IDs historically used the global 'STU' prefix in every database, so
 * the same numeric tail could appear in more than one school. They are now
 * school-scoped: the prefix is the first three letters of the school's name
 * (Dream Academy -> DRE, Cambridge -> CAM, ...). Central keeps 'STU'.
 *
 * For every active school this:
 *   1. Rewrites the 3-letter prefix of each student ID in the tenant
 *      (student_profiles.student_id and users.student_id), keeping the
 *      year+sequence tail intact (STU20250012 -> DRE20250012).
 *   2. Re-syncs the CENTRAL login directory row (matched by email + school_id)
 *      to the exact tenant value, so login by the new ID resolves — this also
 *      repairs any pre-existing tenant/central divergence.
 *
 * Idempotent: rows already on the correct prefix are skipped. No IDs are
 * renumbered; only the leading 3 letters change.
 *
 * Run from CLI:   php maintenance/migrate_student_id_prefixes.php
 * Or in browser:  /maintenance/migrate_student_id_prefixes.php   (super_admin only)
 */

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/school_prefix.php';

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    if (($_SESSION['role'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden: super administrators only.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function out($msg) { echo $msg . "\n"; @ob_flush(); @flush(); }

$central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

out("== Migration: school-scoped student ID prefixes ==");
out("");

$schools = $central->query("SELECT id, name, db_name FROM schools WHERE status <> 'inactive' OR status IS NULL ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

foreach ($schools as $s) {
    $sid    = (int)$s['id'];
    $dbName = $s['db_name'];
    // Reserve (or reuse) this school's guaranteed-unique prefix and persist it
    // on central schools.student_id_prefix.
    $prefix = assignSchoolPrefix($central, $sid, $s['name']); // e.g. DRE
    out("[school #{$sid}] {$s['name']}  ({$dbName})  prefix => {$prefix}");
    if (empty($dbName)) { out("  skipped: no db_name"); out(""); continue; }

    try {
        $tenant = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e) {
        out("  ERROR: cannot connect ({$e->getMessage()})");
        out("");
        continue;
    }

    // Students whose ID is "3 letters + digits" (the format we re-prefix).
    $rows = $tenant->query(
        "SELECT sp.user_id, sp.student_id, u.email
         FROM student_profiles sp
         JOIN users u ON u.id = sp.user_id
         WHERE sp.student_id REGEXP '^[A-Za-z]{3}[0-9]'"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Pre-compute target IDs and detect collisions before writing anything.
    $existing = [];
    foreach ($rows as $r) { $existing[$r['student_id']] = true; }

    $changed = 0; $skipped = 0; $central_synced = 0; $collisions = 0;

    $upTenantSp = $tenant->prepare("UPDATE student_profiles SET student_id = :new WHERE user_id = :uid");
    $upTenantU  = $tenant->prepare("UPDATE users SET student_id = :new WHERE id = :uid");
    $upCentral  = $central->prepare("UPDATE users SET student_id = :new WHERE school_id = :sid AND email = :email");

    foreach ($rows as $r) {
        $old  = $r['student_id'];
        $tail = substr($old, 3);            // keep year + sequence
        $new  = $prefix . $tail;
        if ($new === $old) { $skipped++; continue; }

        // Guard against producing a duplicate within this tenant.
        if (isset($existing[$new])) {
            out("  COLLISION: {$old} -> {$new} already exists; left unchanged");
            $collisions++;
            continue;
        }

        try {
            $tenant->beginTransaction();
            $upTenantSp->execute([':new' => $new, ':uid' => $r['user_id']]);
            $upTenantU->execute([':new' => $new, ':uid' => $r['user_id']]);
            $tenant->commit();
        } catch (PDOException $e) {
            if ($tenant->inTransaction()) $tenant->rollBack();
            out("  ERROR updating {$old}: {$e->getMessage()}");
            continue;
        }
        unset($existing[$old]); $existing[$new] = true;
        $changed++;

        // Mirror to the central directory so login by the new ID resolves.
        $email = trim((string)$r['email']);
        if ($email !== '') {
            try {
                $upCentral->execute([':new' => $new, ':sid' => $sid, ':email' => $email]);
                if ($upCentral->rowCount() > 0) { $central_synced++; }
            } catch (PDOException $e) {
                out("  WARN central sync {$new} ({$email}): {$e->getMessage()}");
            }
        }
    }

    out("  changed: {$changed} | already correct: {$skipped} | central rows synced: {$central_synced}" . ($collisions ? " | collisions skipped: {$collisions}" : ""));
    out("");
}

out("== Done ==");
