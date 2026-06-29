<?php
/**
 * Nadics AI — live data tools
 * --------------------------------------------------------------------------
 * Turns certain natural-language questions ("list all super admins", "how many
 * books are in the library", "what is my fee balance") into safe, whitelisted,
 * ROLE-CHECKED database lookups. The results are returned as labelled strings
 * and injected into the assistant's context so it can answer with real data.
 *
 * Safety model:
 *  - Every tool declares which roles may use it; a student can never trigger an
 *    admin-only tool, etc.
 *  - Queries are fixed and parameterised — the user's text only selects which
 *    tool runs and (for user listings) which role to filter by. No free-form SQL.
 *  - Each tool is wrapped so a missing table/column yields no data rather than
 *    an error.
 *  - Personal tools (fees) are scoped to the requesting user's own id only.
 */

if (!function_exists('nadicsGatherLiveData')) {
    /**
     * Inspect the user's message and run any matching, permitted data tools.
     *
     * @param PDO    $db
     * @param string $message Raw user message.
     * @param string $role    The requesting user's role slug.
     * @param int    $userId  The requesting user's id.
     * @return array Labelled results: ['Label' => 'value', ...] (may be empty).
     */
    function nadicsGatherLiveData($db, $message, $role, $userId) {
        $msg  = ' ' . strtolower(trim($message)) . ' ';
        $out  = [];
        $adminRoles = ['super_admin', 'school_admin', 'principal'];
        $isAdmin = in_array($role, $adminRoles, true);

        $hasVerb = function ($verbs) use ($msg) {
            foreach ($verbs as $v) {
                if (preg_match('/\b' . preg_quote($v, '/') . '\b/', $msg)) { return true; }
            }
            return false;
        };
        $safe = function (callable $fn) {
            try { return $fn(); } catch (Throwable $e) {
                error_log('Nadics tool failed: ' . $e->getMessage());
                return null;
            }
        };

        // ---- Tool 1: list users by role (admins only) -----------------------
        $listVerb  = $hasVerb(['list', 'show', 'who are', 'who is', 'names', 'name of', 'give me', 'display', 'all the', 'all']);
        if ($isAdmin && $listVerb) {
            // Role keywords, longest phrases first so "super admin" beats "admin".
            $roleMap = [
                'super admin' => 'super_admin', 'superadmin' => 'super_admin', 'super_admin' => 'super_admin',
                'school admin' => 'school_admin', 'school administrator' => 'school_admin',
                'headmaster' => 'principal', 'headmistress' => 'principal', 'head of school' => 'principal', 'principal' => 'principal',
                'transport officer' => 'transport_officer', 'hostel warden' => 'hostel_warden', 'canteen manager' => 'canteen_manager',
                'human resource' => 'hr', 'counsellor' => 'counselor', 'counselor' => 'counselor',
                'accountant' => 'accountant', 'bursar' => 'accountant', 'librarian' => 'librarian', 'nurse' => 'nurse',
                'teachers' => 'teacher', 'teacher' => 'teacher', 'tutor' => 'teacher',
                'students' => 'student', 'student' => 'student', 'pupils' => 'student', 'pupil' => 'student',
                'parents' => 'parent', 'parent' => 'parent', 'guardian' => 'parent',
                'admin' => 'school_admin', 'hr' => 'hr',
            ];
            $matchedRole = null; $matchedWord = null;
            $keys = array_keys($roleMap);
            usort($keys, function ($a, $b) { return strlen($b) - strlen($a); });
            foreach ($keys as $kw) {
                // Allow an optional trailing "s" so plurals match ("super admins").
                if (preg_match('/\b' . preg_quote($kw, '/') . 's?\b/', $msg)) {
                    $matchedRole = $roleMap[$kw]; $matchedWord = $kw; break;
                }
            }
            if ($matchedRole) {
                $res = $safe(function () use ($db, $matchedRole) {
                    $stmt = $db->prepare("SELECT name, email FROM users
                                          WHERE role = :r AND status = 'active'
                                          ORDER BY name ASC LIMIT 100");
                    $stmt->execute([':r' => $matchedRole]);
                    return $stmt->fetchAll(PDO::FETCH_ASSOC);
                });
                if (is_array($res)) {
                    $label = ucwords(str_replace('_', ' ', $matchedRole)) . ' users';
                    if (function_exists('formatRoleName')) {
                        $label = formatRoleName($matchedRole) . ' users';
                    }
                    if (count($res) === 0) {
                        $out[$label] = "No active users with this role were found.";
                    } else {
                        $lines = [];
                        foreach ($res as $u) {
                            $lines[] = '- ' . $u['name'] . (!empty($u['email']) ? ' (' . $u['email'] . ')' : '');
                        }
                        $count = count($res);
                        $head = "Total: {$count}" . ($count >= 100 ? '+ (showing first 100)' : '') . "\n";
                        $out[$label] = $head . implode("\n", $lines);
                    }
                }
            }
        }

        // ---- Tool 2: system counts (admins only) ----------------------------
        if ($isAdmin && $hasVerb(['how many', 'number of', 'total', 'count'])) {
            if (preg_match('/\b(student|students|teacher|teachers|staff|user|users|class|classes|enrol|enroll)\w*/', $msg)) {
                $res = $safe(function () use ($db) {
                    return $db->query("SELECT
                        (SELECT COUNT(*) FROM users WHERE role='student' AND status='active') AS students,
                        (SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active') AS teachers,
                        (SELECT COUNT(*) FROM users WHERE status='active' AND role NOT IN ('student','teacher','parent')) AS staff,
                        (SELECT COUNT(*) FROM users WHERE role='parent' AND status='active') AS parents,
                        (SELECT COUNT(*) FROM classes WHERE status='active') AS classes")->fetch(PDO::FETCH_ASSOC);
                });
                if (is_array($res)) {
                    $out['School totals (active)'] =
                        "- Students: {$res['students']}\n- Teachers: {$res['teachers']}\n" .
                        "- Other staff: {$res['staff']}\n- Parents: {$res['parents']}\n- Classes: {$res['classes']}";
                }
            }
        }

        // ---- Tool 3: library statistics (any signed-in user) ----------------
        if ($hasVerb(['how many', 'number of', 'total', 'count', 'available']) &&
            preg_match('/\b(book|books|library|titles?)\b/', $msg)) {
            $res = $safe(function () use ($db) {
                return $db->query("SELECT
                    COUNT(*) AS titles,
                    COALESCE(SUM(total_copies), 0) AS copies,
                    COALESCE(SUM(copies_available), 0) AS available
                    FROM library_books")->fetch(PDO::FETCH_ASSOC);
            });
            if (is_array($res)) {
                $out['Library catalogue'] =
                    "- Distinct titles: {$res['titles']}\n- Total copies: {$res['copies']}\n- Copies available now: {$res['available']}";
            }
        }

        // ---- Tool 4: my fee balance (student, own data only) ----------------
        if ($role === 'student' && preg_match('/\b(fee|fees|balance|owe|outstanding|tuition|bill)\b/', $msg)) {
            $res = $safe(function () use ($db, $userId) {
                $stmt = $db->prepare("SELECT
                    COALESCE(SUM(total_amount + penalty_amount - discount_amount), 0) AS billed,
                    COALESCE(SUM(amount_paid), 0) AS paid,
                    COALESCE(SUM(total_amount + penalty_amount - discount_amount - amount_paid), 0) AS balance
                    FROM finance_invoices WHERE student_id = :id");
                $stmt->execute([':id' => $userId]);
                return $stmt->fetch(PDO::FETCH_ASSOC);
            });
            if (is_array($res)) {
                $sym = function_exists('getSchoolSetting') ? getSchoolSetting('currency_symbol', '') : '';
                $fmt = function ($n) use ($sym) { return $sym . number_format((float)$n, 2); };
                $out['My fees (your account only)'] =
                    "- Total billed: {$fmt($res['billed'])}\n- Total paid: {$fmt($res['paid'])}\n- Outstanding balance: {$fmt($res['balance'])}";
            }
        }

        return $out;
    }
}
