<?php
/**
 * activity_logger.php
 * -------------------
 * Automatic, system-wide activity capture for admin/logs.php.
 *
 * Wired in once from config/database.php (which virtually every page includes),
 * it registers a shutdown hook that records every *state-changing* request made
 * by a logged-in user into the active (tenant-aware) audit_logs table. Because
 * it runs on shutdown it never interferes with redirects/exit() in POST
 * handlers, and it attributes the action to whatever school/user the session
 * resolves to at the end of the request.
 *
 * Design choices:
 *  - Only logged-in users are recorded (anonymous traffic is ignored; failed
 *    logins are logged explicitly in auth/login.php).
 *  - Only "important" requests are logged: every POST/PUT/PATCH/DELETE, plus
 *    GET requests that clearly mutate data (delete links, action=/operation=).
 *    Plain page views and read-only polling/AJAX endpoints are skipped to keep
 *    the trail high-signal.
 *  - Field VALUES are never logged except a tiny safe whitelist (action,
 *    operation, id, status, type), so passwords/tokens can never leak.
 *  - Fully fail-safe: any error here is swallowed so it can never break a page.
 */

require_once __DIR__ . '/audit_log.php';

if (!function_exists('activityLoggerShouldSkipPath')) {
    /**
     * Read-only / high-volume / developer endpoints that should never be logged
     * even when they are POSTed to. Matched as substrings against the normalised
     * request path (e.g. "communication/notifications/get_notifications.php").
     */
    function activityLoggerSkipSubstrings() {
        return [
            // Polling / read AJAX
            'get_', 'fetch_', 'search', 'typing', 'heartbeat', 'ping', 'poll',
            'live_chat_api', 'get_messages', 'get_conversation', 'mark_read',
            'mark_all_read', 'dismiss', 'ensure_persistence', 'check_requests',
            'get_agent_notifications', 'notifications/get', 'api/status',
            // Assets / rendering helpers
            'serve_image', 'dynamic-theme', 'download', 'print', 'export',
            'invoice_print', 'payslip', 'certificate_generator',
            // The audit viewer itself (avoid self-noise / recursion)
            'admin/logs.php', 'export_audit_trail', 'finance/audit_logs',
            // Auth is logged explicitly with richer detail
            'auth/login.php', 'auth/logout.php',
            // Impersonation endpoints log themselves with full context
            'settings/impersonate.php', 'settings/exit_impersonation.php',
            // Developer / maintenance scripts
            'scratch/', 'test_', 'debug_', 'fix_', 'setup_', 'migrate_',
            'check_', 'seed_', 'diagnostic',
        ];
    }

    function activityLoggerShouldSkipPath($path) {
        foreach (activityLoggerSkipSubstrings() as $needle) {
            if (strpos($path, $needle) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('activityLoggerNormalizePath')) {
    /** Turn the script path into a stable "module/.../file.php" string. */
    function activityLoggerNormalizePath() {
        $script = $_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '');
        $script = str_replace('\\', '/', $script);
        // Strip the app base prefix if present.
        $pos = strpos($script, '/school_ms/');
        if ($pos !== false) {
            $script = substr($script, $pos + strlen('/school_ms/'));
        } else {
            $script = ltrim($script, '/');
        }
        return $script;
    }
}

if (!function_exists('activityLoggerBuildAction')) {
    /** Derive a readable snake_case action key from the request path. */
    function activityLoggerBuildAction($path) {
        $clean = preg_replace('/\.php$/', '', $path);
        $parts = array_filter(explode('/', $clean), function ($p) {
            return $p !== '' && $p !== 'index';
        });
        $key = implode('_', $parts);
        $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
        $key = trim(preg_replace('/_+/', '_', $key), '_');
        return $key !== '' ? substr($key, 0, 90) : 'request';
    }
}

if (!function_exists('activityLoggerIsMutatingGet')) {
    /**
     * Decide whether a GET request is a real mutation worth logging
     * (delete-by-link, or an action/operation parameter that changes state).
     */
    function activityLoggerIsMutatingGet($path) {
        $base = basename($path);
        foreach (['delete', 'remove', 'revoke', 'restore', 'approve', 'reject',
                  'suspend', 'activate', 'cancel'] as $verb) {
            if (strpos($base, $verb) !== false) {
                return true;
            }
        }
        $signal = strtolower((string)(($_GET['action'] ?? '') . ' ' . ($_GET['operation'] ?? '')));
        foreach (['delete', 'remove', 'revoke', 'restore', 'create', 'update',
                  'edit', 'save', 'approve', 'reject', 'suspend', 'activate',
                  'assign', 'collect', 'pay', 'promote', 'import', 'backup',
                  'reset', 'send', 'cancel', 'enable', 'disable'] as $verb) {
            if (strpos($signal, $verb) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('activityLoggerBuildDetails')) {
    /** Build a concise, non-sensitive description of the request. */
    function activityLoggerBuildDetails($method, $path) {
        $bits = [$method . ' /' . $path];

        // Only echo back a small whitelist of safe scalar fields.
        $safe = ['action', 'operation', 'id', 'status', 'type', 'student_id', 'user_id', 'class_id'];
        $src = array_merge($_GET, $_POST);
        $kv = [];
        foreach ($safe as $field) {
            if (isset($src[$field]) && is_scalar($src[$field]) && $src[$field] !== '') {
                $kv[] = $field . '=' . substr((string)$src[$field], 0, 40);
            }
        }
        if ($kv) {
            $bits[] = implode(', ', $kv);
        }

        // Note how many fields were submitted (without their values).
        if ($method !== 'GET' && !empty($_POST)) {
            $bits[] = count($_POST) . ' field(s)';
        }
        return substr(implode(' • ', $bits), 0, 500);
    }
}

if (!function_exists('recordRequestActivity')) {
    /**
     * The shutdown worker. Builds a fresh tenant-aware connection so the entry
     * lands in the database the session currently points at.
     */
    function recordRequestActivity() {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            // Only attribute activity to authenticated users.
            if (empty($_SESSION['user_id'])) {
                return;
            }

            $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
            $path = activityLoggerNormalizePath();
            if ($path === '' || activityLoggerShouldSkipPath($path)) {
                return;
            }

            $isMutation = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            if (!$isMutation) {
                if ($method !== 'GET' || !activityLoggerIsMutatingGet($path)) {
                    return; // plain page view — skip
                }
            }

            if (!class_exists('Database')) {
                return;
            }
            $db = (new Database())->getConnection();
            if (!$db) {
                return;
            }

            $action  = activityLoggerBuildAction($path);
            $details = activityLoggerBuildDetails($method, $path);
            logAudit($db, $action, $details);
        } catch (Throwable $e) {
            // Never allow logging to affect the response.
            error_log('recordRequestActivity failed: ' . $e->getMessage());
        }
    }
}

// Register exactly once per request.
if (!defined('ACTIVITY_LOGGER_REGISTERED')) {
    define('ACTIVITY_LOGGER_REGISTERED', true);
    register_shutdown_function('recordRequestActivity');
}
