<?php
/**
 * Central user directory sync.
 * --------------------------------------------------------------------------
 * The login page resolves an account against the CENTRAL directory database
 * (school_ms.users, joined to `schools` via school_id) and then verifies the
 * password against the tenant database. School registration writes the admin
 * to both places; user accounts created inside a tenant (students, guardians,
 * staff, parents) must do the same or they cannot log in.
 *
 * syncUserToCentralDirectory() mirrors a tenant user into that central
 * directory. It is a no-op when not operating inside a tenant (e.g. a
 * super-admin working directly on the central database, where the row already
 * lives in the same table).
 */
require_once __DIR__ . '/../config/database.php';

if (!function_exists('syncUserToCentralDirectory')) {
    /**
     * @param array $user keys: school_id, name, email, password (already hashed),
     *                    role, status (default 'active'), student_id (optional),
     *                    employee_id (optional — staff), joining_date (optional)
     * @return bool true on success / no-op, false on failure
     */
    function syncUserToCentralDirectory(array $user) {
        // Only relevant when the active connection is a tenant database.
        if (empty($_SESSION['school_db_name']) || $_SESSION['school_db_name'] === DB_NAME) {
            return true; // already in the central DB; nothing to mirror
        }

        $school_id = $user['school_id'] ?? ($_SESSION['school_id'] ?? null);
        $email     = trim($user['email'] ?? '');
        if (empty($school_id) || $email === '') {
            return false;
        }

        try {
            $central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            $find = $central->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $find->execute([':email' => $email]);
            $existing = $find->fetchColumn();

            $params = [
                ':school_id'  => $school_id,
                ':name'       => $user['name'] ?? '',
                ':password'   => $user['password'] ?? '',
                ':role'       => $user['role'] ?? 'student',
                ':status'     => $user['status'] ?? 'active',
                ':student_id' => $user['student_id'] ?? null,
            ];

            if ($existing) {
                // Email is globally unique in the directory, so a match is the
                // same person — keep their directory record in sync.
                $params[':id'] = $existing;
                $sql = "UPDATE users SET school_id = :school_id, name = :name, password = :password,
                            role = :role, status = :status, student_id = :student_id
                        WHERE id = :id";
                $central->prepare($sql)->execute($params);
                $central_user_id = (int)$existing;
            } else {
                $params[':email'] = $email;
                $sql = "INSERT INTO users (school_id, name, email, password, role, status, student_id)
                        VALUES (:school_id, :name, :email, :password, :role, :status, :student_id)";
                $central->prepare($sql)->execute($params);
                $central_user_id = (int)$central->lastInsertId();
            }

            // Staff: also mirror a minimal teacher_profiles row so login by
            // employee ID resolves (login.php phase 2 joins teacher_profiles).
            if (!empty($user['employee_id'])) {
                syncStaffProfileToCentral($central, $central_user_id, $user['employee_id'], $user['joining_date'] ?? null);
            }

            return true;
        } catch (PDOException $e) {
            error_log("syncUserToCentralDirectory failed for {$email}: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('syncStaffProfileToCentral')) {
    /**
     * Upsert the minimal central teacher_profiles row (user_id, employee_id,
     * joining_date) needed for employee-ID login. Failures here are non-fatal
     * (email login still works), so they are logged and swallowed.
     */
    function syncStaffProfileToCentral(PDO $central, $centralUserId, $employeeId, $joiningDate = null) {
        if (empty($centralUserId) || empty($employeeId)) {
            return;
        }
        try {
            $joining = !empty($joiningDate) ? $joiningDate : date('Y-m-d');
            $find = $central->prepare("SELECT id FROM teacher_profiles WHERE employee_id = :emp OR user_id = :uid LIMIT 1");
            $find->execute([':emp' => $employeeId, ':uid' => $centralUserId]);
            $tpId = $find->fetchColumn();

            if ($tpId) {
                $central->prepare("UPDATE teacher_profiles SET user_id = :uid, employee_id = :emp WHERE id = :id")
                        ->execute([':uid' => $centralUserId, ':emp' => $employeeId, ':id' => $tpId]);
            } else {
                $central->prepare("INSERT INTO teacher_profiles (user_id, employee_id, joining_date)
                                   VALUES (:uid, :emp, :joining)")
                        ->execute([':uid' => $centralUserId, ':emp' => $employeeId, ':joining' => $joining]);
            }
        } catch (PDOException $e) {
            error_log("syncStaffProfileToCentral failed for employee {$employeeId}: " . $e->getMessage());
        }
    }
}
