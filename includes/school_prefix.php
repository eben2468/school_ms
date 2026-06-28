<?php
/**
 * Guaranteed-unique, persistent student-ID prefixes per school.
 * --------------------------------------------------------------------------
 * Each school gets a stable 3-character prefix (e.g. Dream Academy -> DRE) used
 * for its student IDs so IDs never collide across tenants. The prefix is the
 * single source of truth in the CENTRAL `schools.student_id_prefix` column with
 * a UNIQUE index, so two schools can never share one — even if their names
 * start with the same letters (the generator falls back to other candidates).
 *
 * 'STU' is reserved for the central directory and never handed to a school.
 */
require_once __DIR__ . '/../config/database.php';

if (!function_exists('ensureSchoolPrefixColumn')) {
    /** Add schools.student_id_prefix (+ unique index) on the central DB if missing. */
    function ensureSchoolPrefixColumn(PDO $central) {
        try {
            $chk = $central->query("SHOW COLUMNS FROM schools LIKE 'student_id_prefix'");
            if ($chk && $chk->rowCount() === 0) {
                $central->exec("ALTER TABLE schools ADD COLUMN student_id_prefix VARCHAR(3) NULL");
            }
        } catch (PDOException $e) {
            error_log("ensureSchoolPrefixColumn (add column): " . $e->getMessage());
            return;
        }
        // Unique index (MySQL allows multiple NULLs under a UNIQUE key).
        try {
            $idx = $central->query("SHOW INDEX FROM schools WHERE Key_name = 'uniq_student_id_prefix'");
            if ($idx && $idx->rowCount() === 0) {
                $central->exec("ALTER TABLE schools ADD UNIQUE KEY uniq_student_id_prefix (student_id_prefix)");
            }
        } catch (PDOException $e) {
            // Non-fatal: duplicates would have to be cleared first; assignment still works.
            error_log("ensureSchoolPrefixColumn (unique index): " . $e->getMessage());
        }
    }
}

if (!function_exists('schoolPrefixCandidates')) {
    /**
     * Ordered list of human-friendly 3-letter prefix candidates for a name,
     * most preferred first (e.g. "Dream Academy" -> DRE, DA?, DRA, ...).
     */
    function schoolPrefixCandidates($name) {
        $clean = strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$name));
        $words = array_values(array_filter(array_map(
            function ($w) { return strtoupper(preg_replace('/[^A-Za-z]/', '', $w)); },
            preg_split('/\s+/', (string)$name)
        )));

        $raw = [];
        if (strlen($clean) >= 3)  $raw[] = substr($clean, 0, 3);                 // DRE
        if (count($words) >= 3)   $raw[] = $words[0][0] . $words[1][0] . $words[2][0];
        if (count($words) >= 2 && strlen($words[1]) >= 2) $raw[] = $words[0][0] . substr($words[1], 0, 2); // D + AC
        if (count($words) >= 2 && strlen($words[0]) >= 2) $raw[] = substr($words[0], 0, 2) . $words[1][0]; // DR + A
        if (strlen($clean) >= 3)  $raw[] = substr($clean, 0, 2) . substr($clean, -1); // DR + last
        if (strlen($clean) >= 3)  $raw[] = $clean[0] . substr($clean, -2);            // D + last 2
        if (strlen($clean) >= 4)  $raw[] = $clean[0] . $clean[2] . $clean[3];         // skip-letter

        $out = [];
        foreach ($raw as $c) {
            $c = substr(preg_replace('/[^A-Z]/', '', $c) . 'XX', 0, 3);
            if (strlen($c) === 3) $out[$c] = true;
        }
        return array_keys($out);
    }
}

if (!function_exists('generateUniqueSchoolPrefix')) {
    /**
     * A 3-char prefix not already used by any school (or reserved). Tries the
     * name-based candidates first, then deterministically brute-forces the
     * 3-character space so a unique value is always found.
     */
    function generateUniqueSchoolPrefix(PDO $central, $name) {
        ensureSchoolPrefixColumn($central);

        $taken = ['STU' => true]; // reserved for the central directory
        try {
            foreach ($central->query("SELECT student_id_prefix FROM schools WHERE student_id_prefix IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN) as $p) {
                $taken[strtoupper($p)] = true;
            }
        } catch (PDOException $e) {
            error_log("generateUniqueSchoolPrefix (load taken): " . $e->getMessage());
        }

        foreach (schoolPrefixCandidates($name) as $cand) {
            if (!isset($taken[$cand])) return $cand;
        }

        // Fallback: keep the school's first letter, roll the last two chars over
        // A-Z then 0-9 (e.g. DA0, DA1...). Then fully exhaust the space.
        $first = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)$name) . 'X', 0, 1));
        $alnum = str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');
        foreach ($alnum as $a) {
            foreach ($alnum as $b) {
                $c = $first . $a . $b;
                if (!isset($taken[$c])) return $c;
            }
        }
        foreach ($alnum as $x) {
            foreach ($alnum as $a) {
                foreach ($alnum as $b) {
                    $c = $x . $a . $b;
                    if (!isset($taken[$c])) return $c;
                }
            }
        }
        return 'STU'; // unreachable in practice
    }
}

if (!function_exists('validateSchoolPrefix')) {
    /**
     * Validate an admin-supplied prefix. Returns [normalized, error]:
     *   - ['', null]        blank input -> caller should auto-generate
     *   - ['ABC', null]     valid, normalized to uppercase
     *   - [null, 'msg']     invalid, with a human-readable reason
     */
    function validateSchoolPrefix($raw) {
        $p = strtoupper(trim((string)$raw));
        if ($p === '') return ['', null];
        if (!preg_match('/^[A-Z0-9]{3}$/', $p)) {
            return [null, 'Student ID prefix must be exactly 3 letters or digits (e.g. DRE).'];
        }
        if ($p === 'STU') {
            return [null, "'STU' is reserved for the system directory and cannot be used."];
        }
        return [$p, null];
    }
}

if (!function_exists('schoolPrefixTaken')) {
    /** True when another school already uses this prefix. */
    function schoolPrefixTaken(PDO $central, $prefix, $excludeId = null) {
        ensureSchoolPrefixColumn($central);
        $sql = "SELECT COUNT(*) FROM schools WHERE UPPER(student_id_prefix) = :p";
        $params = [':p' => strtoupper($prefix)];
        if ($excludeId !== null) { $sql .= " AND id <> :id"; $params[':id'] = $excludeId; }
        $st = $central->prepare($sql);
        $st->execute($params);
        return (int)$st->fetchColumn() > 0;
    }
}

if (!function_exists('setSchoolPrefix')) {
    /**
     * Store an explicit (already validated + uniqueness-checked) prefix for a
     * school. Throws PDOException if it collides at the DB level (unique index).
     * @return string the stored uppercase prefix
     */
    function setSchoolPrefix(PDO $central, $schoolId, $prefix) {
        ensureSchoolPrefixColumn($central);
        $central->prepare("UPDATE schools SET student_id_prefix = :p WHERE id = :id")
                ->execute([':p' => strtoupper($prefix), ':id' => $schoolId]);
        return strtoupper($prefix);
    }
}

if (!function_exists('assignSchoolPrefix')) {
    /**
     * Return the school's persistent prefix, generating + storing a unique one
     * on first use. Idempotent: once set, the same prefix is always returned
     * (renaming a school never changes already-issued IDs).
     *
     * @return string 3-char uppercase prefix
     */
    function assignSchoolPrefix(PDO $central, $schoolId, $name = null) {
        ensureSchoolPrefixColumn($central);

        try {
            $s = $central->prepare("SELECT name, student_id_prefix FROM schools WHERE id = :id");
            $s->execute([':id' => $schoolId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            $row = null;
        }
        if ($row && !empty($row['student_id_prefix'])) {
            return strtoupper($row['student_id_prefix']);
        }

        $nm = ($name !== null && $name !== '') ? $name : ($row['name'] ?? '');
        $prefix = generateUniqueSchoolPrefix($central, $nm);

        // Persist; retry once on the rare race where another request grabbed it.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $central->prepare("UPDATE schools SET student_id_prefix = :p WHERE id = :id")
                        ->execute([':p' => $prefix, ':id' => $schoolId]);
                return $prefix;
            } catch (PDOException $e) {
                $prefix = generateUniqueSchoolPrefix($central, $nm);
            }
        }
        return $prefix;
    }
}
