<?php
/**
 * Module Access Control Helper (Multi-School Subscription Gating)
 * --------------------------------------------------------------
 * Super administrators can enable/disable major functional modules on a
 * per-school basis from settings/module_access.php. The toggle state is stored
 * centrally (school_ms.school_module_access) so that every tenant database can
 * be gated from a single source of truth.
 *
 * Tenant pages and the shared sidebar call isModuleEnabled()/requireModule()
 * to hide navigation and block access to disabled modules.
 */

require_once __DIR__ . '/../config/database.php'; // require_once -> safe even if already loaded

if (!function_exists('getModuleDefinitions')) {
    /**
     * Canonical list of toggleable modules.
     * `min_plan` documents the subscription tier the module is typically bundled
     * with (trial < basic < standard) and is used only for the recommendation UI.
     */
    function getModuleDefinitions() {
        return [
            'library' => [
                'label' => 'Library Management',
                'icon' => 'fa-book',
                'description' => 'Books, loans & catalogue resources',
                'min_plan' => 'basic',
            ],
            'finance' => [
                'label' => 'Finance Management',
                'icon' => 'fa-money-bill-wave',
                'description' => 'Fees, invoices, payments & expenses',
                'min_plan' => 'basic',
            ],
            'communication' => [
                'label' => 'Communication',
                'icon' => 'fa-comments',
                'description' => 'Messaging, live chat & announcements',
                'min_plan' => 'basic',
            ],
            'documents' => [
                'label' => 'Document & File Management',
                'icon' => 'fa-folder-open',
                'description' => 'File storage, certificates & transcripts',
                'min_plan' => 'basic',
            ],
            'online_learning' => [
                'label' => 'Online Learning Tools',
                'icon' => 'fa-laptop',
                'description' => 'Virtual classrooms, quizzes & e-materials',
                'min_plan' => 'standard',
            ],
            'transport' => [
                'label' => 'Transport Management',
                'icon' => 'fa-bus',
                'description' => 'Routes, vehicles, drivers & tracking',
                'min_plan' => 'standard',
            ],
            'hostel' => [
                'label' => 'Hostel Management',
                'icon' => 'fa-bed',
                'description' => 'Dormitories, rooms & boarding allocations',
                'min_plan' => 'standard',
            ],
            'canteen' => [
                'label' => 'Canteen Management',
                'icon' => 'fa-utensils',
                'description' => 'Menus, orders & canteen inventory',
                'min_plan' => 'standard',
            ],
            'health' => [
                'label' => 'Health & Counseling',
                'icon' => 'fa-heartbeat',
                'description' => 'Clinic visits & counseling records',
                'min_plan' => 'standard',
            ],
            'inventory' => [
                'label' => 'Inventory Management',
                'icon' => 'fa-boxes',
                'description' => 'Assets, stock & supply requests',
                'min_plan' => 'standard',
            ],
        ];
    }
}

if (!function_exists('ensureModuleAccessTable')) {
    /**
     * Create the central storage table on demand. Idempotent.
     * @param PDO $centralDb A connection bound to the central (school_ms) DB.
     */
    function ensureModuleAccessTable(PDO $centralDb) {
        $centralDb->exec("CREATE TABLE IF NOT EXISTS school_module_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            school_id INT NOT NULL,
            module_key VARCHAR(50) NOT NULL,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_school_module (school_id, module_key),
            FOREIGN KEY (school_id) REFERENCES schools(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

if (!function_exists('getCentralConnection')) {
    /**
     * Connect explicitly to the central control database regardless of the
     * tenant DB currently bound to the session. Returns null on failure.
     * @return PDO|null
     */
    function getCentralConnection() {
        static $central = null;
        if ($central instanceof PDO) {
            return $central;
        }
        try {
            $central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
            $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch (PDOException $e) {
            error_log("module_access: central connection failed - " . $e->getMessage());
            $central = null;
        }
        return $central;
    }
}

if (!function_exists('getSchoolModuleAccess')) {
    /**
     * Return [module_key => bool] for a school. Modules without an explicit
     * record default to enabled so existing schools keep full access until a
     * super admin deliberately disables something.
     * @param int $school_id
     * @return array
     */
    function getSchoolModuleAccess($school_id) {
        static $cache = [];
        $school_id = (int) $school_id;
        if (isset($cache[$school_id])) {
            return $cache[$school_id];
        }

        $access = [];
        $central = getCentralConnection();
        if ($central) {
            try {
                $stmt = $central->prepare("SELECT module_key, is_enabled FROM school_module_access WHERE school_id = ?");
                $stmt->execute([$school_id]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $access[$row['module_key']] = ((int) $row['is_enabled'] === 1);
                }
            } catch (PDOException $e) {
                // Table not provisioned yet -> treat everything as enabled.
            }
        }

        $cache[$school_id] = $access;
        return $access;
    }
}

if (!function_exists('isModuleEnabled')) {
    /**
     * Whether the currently logged-in user's school may use a module.
     * System-level users (super admin, no school context) always see everything.
     * @param string $module_key
     * @return bool
     */
    function isModuleEnabled($module_key) {
        if (empty($_SESSION['school_id'])) {
            return true; // central / super-admin context
        }
        $access = getSchoolModuleAccess($_SESSION['school_id']);
        // Default to enabled when no explicit toggle has been stored.
        return !array_key_exists($module_key, $access) || $access[$module_key] === true;
    }
}

if (!function_exists('requireModule')) {
    /**
     * Page guard: redirect to the dashboard if the module is disabled for the
     * current school. Call immediately after the auth check on a module's
     * entry page.
     * @param string $module_key
     */
    function requireModule($module_key) {
        if (!isModuleEnabled($module_key)) {
            $msg = "This module is not available on your school's current subscription plan.";
            if (!headers_sent()) {
                $role = $_SESSION['role'] ?? '';
                $dash = ($role === 'parent') ? '/parent/dashboard.php' : '/dashboard.php';
                header("Location: " . $dash . "?error=" . urlencode($msg));
            }
            exit();
        }
    }
}
