<?php
/**
 * Role-Based Access Control (RBAC) — Single Source of Truth
 * ---------------------------------------------------------
 * Defines which roles may access each functional module/area of the system.
 * BOTH the shared sidebar (navigation visibility) and the per-page guards read
 * from this same matrix, so the menu a user sees can never drift out of sync
 * with the pages they are actually allowed to open.
 *
 * Usage on a page (place immediately after session_start()):
 *     require_once __DIR__ . '/../includes/access_control.php';
 *     requireModuleRole('finance');            // module-level guard
 *  or requireRole(['super_admin','teacher']);  // explicit per-page guard
 *
 * Usage in the sidebar:
 *     <?php if (canAccessModule('finance')): ?> ... <?php endif; ?>
 *
 * NOTE: This is distinct from includes/module_access.php, which gates modules
 * by a school's *subscription* (super-admin toggle). Both can apply: a role may
 * be permitted here yet the module disabled for the school there.
 */

if (!defined('SCHOOL_MS_ALL_ROLES')) {
    define('SCHOOL_MS_ALL_ROLES', [
        'super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent',
        'librarian', 'accountant', 'transport_officer', 'hostel_warden',
        'canteen_manager', 'nurse', 'counselor', 'hr',
    ]);
}

if (!function_exists('getAccessMatrix')) {
    /**
     * Canonical map of module key => roles allowed to access that module.
     * Derived from (and kept consistent with) the navigation in includes/sidebar.php.
     * Use ['*'] to mean "every authenticated role".
     *
     * @return array<string, string[]>
     */
    function getAccessMatrix() {
        return [
            // Core academic / admin
            'students'        => ['super_admin', 'school_admin', 'principal', 'teacher'],
            'users'           => ['super_admin', 'school_admin'],
            'academic'        => ['super_admin', 'school_admin', 'principal', 'teacher'],
            'attendance'      => ['super_admin', 'school_admin', 'principal', 'teacher'],
            'reports'         => ['super_admin', 'school_admin', 'principal', 'teacher'],
            'staff'           => ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'],
            'allocations'     => ['super_admin', 'school_admin'],
            'settings'        => ['super_admin', 'school_admin'],
            'settings_super'  => ['super_admin'],

            // Operational modules (also subject to subscription gating)
            'library'         => ['super_admin', 'school_admin', 'librarian', 'student', 'teacher'],
            'transport'       => ['super_admin', 'school_admin', 'transport_officer'],
            'hostel'          => ['super_admin', 'school_admin', 'hostel_warden'],
            // Student-facing hostel portal (own room/roommates + report repairs).
            'hostel_student'  => ['student'],
            'canteen'         => ['super_admin', 'school_admin', 'canteen_manager'],
            'finance'         => ['super_admin', 'school_admin', 'principal', 'accountant'],
            'health'          => ['super_admin', 'school_admin', 'nurse', 'counselor'],
            'inventory'       => ['super_admin', 'school_admin'],
            'online_learning' => ['super_admin', 'school_admin', 'principal', 'teacher', 'student'],
            'documents'       => ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'],
            'communication'   => ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'],

            // Role portals
            'parent'          => ['parent', 'super_admin', 'school_admin'],

            // Available to every authenticated user
            'dashboard'       => ['*'],
            'help'            => ['*'],
        ];
    }
}

if (!function_exists('getModuleAllowedRoles')) {
    /**
     * Roles permitted for a module key, or null if the key is unknown.
     * @return string[]|null
     */
    function getModuleAllowedRoles($module) {
        $matrix = getAccessMatrix();
        return $matrix[$module] ?? null;
    }
}

if (!function_exists('roleCanAccessModule')) {
    /**
     * Whether a specific role may access a module key.
     * Unknown module keys are denied (fail closed) so typos never silently
     * open access.
     */
    function roleCanAccessModule($role, $module) {
        $allowed = getModuleAllowedRoles($module);
        if ($allowed === null) {
            return false;
        }
        return in_array('*', $allowed, true) || in_array($role, $allowed, true);
    }
}

if (!function_exists('canAccessModule')) {
    /**
     * Convenience wrapper using the currently logged-in user's role.
     * Intended for the sidebar to decide whether to render a section.
     */
    function canAccessModule($module) {
        $role = $_SESSION['role'] ?? '';
        return roleCanAccessModule($role, $module);
    }
}

if (!function_exists('redirectUnauthorized')) {
    /**
     * Shared redirect logic for the page guards.
     * - Not logged in            -> login page
     * - Logged in but wrong role  -> own dashboard with an explanatory message
     */
    function redirectUnauthorized($loggedIn) {
        if (!headers_sent()) {
            if (!$loggedIn) {
                header('Location: /auth/login.php');
            } else {
                // Send users to their own dashboard (parents have a dedicated one).
                $role = $_SESSION['role'] ?? '';
                $dash = ($role === 'parent') ? '/parent/dashboard.php' : '/dashboard.php';
                $msg = 'You do not have permission to access that page.';
                header('Location: ' . $dash . '?error=' . urlencode($msg));
            }
        }
        exit();
    }
}

if (!function_exists('requireRole')) {
    /**
     * Page guard: require the current user to be logged in AND hold one of the
     * given roles. Call immediately after session_start().
     *
     * @param string[] $roles Roles allowed on this page.
     */
    function requireRole(array $roles) {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $loggedIn = !empty($_SESSION['user_id']);
        $role = $_SESSION['role'] ?? '';
        if (!$loggedIn || !in_array($role, $roles, true)) {
            redirectUnauthorized($loggedIn);
        }
    }
}

if (!function_exists('requireModuleRole')) {
    /**
     * Page guard driven by the canonical matrix. Equivalent to
     * requireRole(getModuleAllowedRoles($module)) but guarantees the page and
     * the sidebar share exactly one definition of who may enter.
     */
    function requireModuleRole($module) {
        $allowed = getModuleAllowedRoles($module);
        if ($allowed === null) {
            // Unknown module key -> fail closed.
            requireRole([]);
            return;
        }
        if (in_array('*', $allowed, true)) {
            // Any authenticated user.
            requireRole(SCHOOL_MS_ALL_ROLES);
            return;
        }
        requireRole($allowed);
    }
}
