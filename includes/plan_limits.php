<?php
/**
 * plan_limits.php
 * ---------------
 * Enforces the student / staff capacity defined by a school's subscription plan.
 *
 * Plans live in the CENTRAL database (school_subscriptions -> subscription_plans),
 * while the actual student/staff accounts live in each school's isolated TENANT
 * database. So these helpers look the plan up centrally and count usage on the
 * tenant connection that the caller already holds.
 *
 * A limit greater than 9000 is treated as "unlimited" (matches the super-admin
 * UI, e.g. the Enterprise plan uses 999999 / 9999).
 */

require_once __DIR__ . '/../config/database.php';

if (!function_exists('planLimitIsUnlimited')) {
    function planLimitIsUnlimited($limit) {
        return (int)$limit > 9000;
    }
}

if (!function_exists('getSchoolPlanLimits')) {
    /**
     * Fetch the current plan name + limits for a school from the central DB.
     * Returns null when there is no school context (e.g. super admin) or no
     * subscription on record — callers treat null as "no limit".
     */
    function getSchoolPlanLimits($school_id) {
        $school_id = (int)$school_id;
        if ($school_id <= 0) {
            return null;
        }
        try {
            $central = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
            $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $stmt = $central->prepare(
                "SELECT sp.name, sp.student_limit, sp.staff_limit
                 FROM school_subscriptions ss
                 JOIN subscription_plans sp ON sp.id = ss.plan_id
                 WHERE ss.school_id = :id
                 ORDER BY ss.id DESC LIMIT 1"
            );
            $stmt->execute([':id' => $school_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Exception $e) {
            error_log('getSchoolPlanLimits failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('countTenantStudents')) {
    function countTenantStudents($tenantDb) {
        try {
            return (int)$tenantDb->query("SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active'")->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('countTenantStaff')) {
    // Staff = every active account that is not a student or a parent.
    function countTenantStaff($tenantDb) {
        try {
            return (int)$tenantDb->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('student','parent') AND status = 'active'")->fetchColumn();
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('checkPlanCapacity')) {
    /**
     * Generic capacity check.
     * @return array{allowed:bool,unlimited:bool,limit:int,current:int,remaining:int,plan:?string}
     */
    function checkPlanCapacity($type, $tenantDb, $school_id, $adding = 1) {
        $limits = getSchoolPlanLimits($school_id);
        if (!$limits) {
            // No school context / no subscription on record -> do not block.
            return ['allowed' => true, 'unlimited' => true, 'limit' => 0, 'current' => 0, 'remaining' => PHP_INT_MAX, 'plan' => null];
        }
        $limit = (int)($type === 'staff' ? $limits['staff_limit'] : $limits['student_limit']);
        if (planLimitIsUnlimited($limit)) {
            return ['allowed' => true, 'unlimited' => true, 'limit' => $limit, 'current' => 0, 'remaining' => PHP_INT_MAX, 'plan' => $limits['name']];
        }
        $current = ($type === 'staff') ? countTenantStaff($tenantDb) : countTenantStudents($tenantDb);
        $remaining = max(0, $limit - $current);
        return [
            'allowed'   => ($current + $adding) <= $limit,
            'unlimited' => false,
            'limit'     => $limit,
            'current'   => $current,
            'remaining' => $remaining,
            'plan'      => $limits['name'],
        ];
    }
}

if (!function_exists('checkStudentCapacity')) {
    function checkStudentCapacity($tenantDb, $school_id, $adding = 1) {
        return checkPlanCapacity('student', $tenantDb, $school_id, $adding);
    }
}

if (!function_exists('checkStaffCapacity')) {
    function checkStaffCapacity($tenantDb, $school_id, $adding = 1) {
        return checkPlanCapacity('staff', $tenantDb, $school_id, $adding);
    }
}

if (!function_exists('planCapacityMessage')) {
    /**
     * Friendly "limit reached" sentence for the given capacity result.
     */
    function planCapacityMessage($type, $cap) {
        $noun = ($type === 'staff') ? 'staff members' : 'students';
        $plan = $cap['plan'] ? "'{$cap['plan']}'" : 'current';
        return "Your {$plan} subscription plan allows up to {$cap['limit']} {$noun} "
             . "(currently {$cap['current']}). Please upgrade the plan to add more.";
    }
}
