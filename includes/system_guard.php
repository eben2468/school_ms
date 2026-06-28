<?php
/**
 * system_guard.php
 * ----------------
 * Enforces the System Control + Backup settings configured under
 * Settings → System. Included once at the very TOP of includes/header.php
 * (before any output) so it covers every rendered page.
 *
 * Responsibilities:
 *   1. Maintenance Mode  — when enabled, only admin-level roles may use the
 *      system; everyone else gets a 503 maintenance page.
 *   2. Session Timeout   — idle users are logged out after the configured
 *      number of minutes.
 *   3. Automatic Backup  — opportunistically runs a scheduled mysqldump when one
 *      is due (no OS cron needed on XAMPP).
 *
 * Fail-safe: any unexpected error here is swallowed so it can never white-screen
 * a page.
 */

if (!defined('SYSTEM_GUARD_LOADED')) {
    define('SYSTEM_GUARD_LOADED', true);

    require_once __DIR__ . '/settings_helper.php';
    require_once __DIR__ . '/auto_backup.php';
    @require_once __DIR__ . '/audit_log.php';

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    // Roles that retain full access during maintenance / drive auto-backups.
    if (!defined('SYSTEM_ADMIN_ROLES')) {
        define('SYSTEM_ADMIN_ROLES', ['super_admin', 'school_admin', 'principal']);
    }

    if (!function_exists('renderMaintenancePage')) {
        function renderMaintenancePage() {
            $school = htmlspecialchars(getSchoolSetting('school_name', 'School Management System'));
            http_response_code(503);
            header('Retry-After: 3600');
            ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Under Maintenance &middot; <?php echo $school; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
        :root{
            --accent:#f59e0b; --accent2:#fbbf24;
            --card-border:rgba(255,255,255,.10);
            --heading:#f8fafc; --muted:#aeb8c9;
        }
        html,body{height:100%;width:100%;overflow:hidden;overscroll-behavior:none}
        body{
            font-family:'Inter',system-ui,-apple-system,Segoe UI,Roboto,sans-serif;
            position:fixed; inset:0; display:flex; align-items:center; justify-content:center;
            padding:24px; color:var(--heading); touch-action:none;
            background:radial-gradient(1000px 560px at 50% -10%,#1e2c4a 0%,transparent 60%),
                       linear-gradient(160deg,#0b1220 0%,#111c33 55%,#0a0f1c 100%);
        }
        /* soft floating glows */
        body::before,body::after{content:"";position:absolute;border-radius:50%;filter:blur(80px);opacity:.55;z-index:0}
        body::before{width:360px;height:360px;background:rgba(245,158,11,.22);top:-110px;right:-70px;animation:float 9s ease-in-out infinite}
        body::after{width:400px;height:400px;background:rgba(56,123,255,.24);bottom:-140px;left:-90px;animation:float 11s ease-in-out infinite reverse}
        @keyframes float{0%,100%{transform:translateY(0)}50%{transform:translateY(24px)}}

        .card{
            position:relative; z-index:1; width:min(90vw,400px); max-height:calc(100dvh - 32px);
            background:linear-gradient(160deg,rgba(38,52,84,.92) 0%,rgba(23,34,60,.92) 55%,rgba(15,23,42,.94) 100%);
            border:1px solid var(--card-border); border-radius:24px;
            padding:clamp(24px,4.5vw,38px); text-align:center;
            backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px);
            box-shadow:0 30px 60px -24px rgba(0,0,0,.7),inset 0 1px 0 rgba(255,255,255,.08);
            animation:rise .6s cubic-bezier(.21,1,.36,1) both;
        }
        @keyframes rise{from{opacity:0;transform:translateY(20px) scale(.98)}to{opacity:1;transform:none}}

        .badge{
            display:inline-flex;align-items:center;gap:7px;font-size:11px;font-weight:600;
            letter-spacing:.07em;text-transform:uppercase;color:#fcd34d;
            background:rgba(245,158,11,.14);border:1px solid rgba(245,158,11,.32);
            padding:6px 12px;border-radius:999px;margin-bottom:20px;
        }
        .badge .dot{width:7px;height:7px;border-radius:50%;background:var(--accent);animation:pulse 1.8s infinite}
        @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(245,158,11,.5)}70%{box-shadow:0 0 0 9px rgba(245,158,11,0)}100%{box-shadow:0 0 0 0 rgba(245,158,11,0)}}

        .icon{
            width:78px;height:78px;margin:0 auto 20px;border-radius:50%;
            display:flex;align-items:center;justify-content:center;
            background:radial-gradient(circle at 30% 30%,#fde68a,#f59e0b);
            box-shadow:0 0 0 8px rgba(245,158,11,.10),0 14px 30px -10px rgba(245,158,11,.6);
        }
        .icon i{font-size:30px;color:#fff;animation:spin 6s linear infinite}
        @keyframes spin{to{transform:rotate(360deg)}}

        h1{font-size:clamp(1.3rem,4vw,1.65rem);font-weight:800;letter-spacing:-.02em;margin-bottom:6px}
        h2{font-size:clamp(.92rem,2.4vw,1.05rem);font-weight:600;color:#fbbf24;margin-bottom:14px}
        p{font-size:clamp(.88rem,2.2vw,.96rem);line-height:1.65;color:var(--muted);max-width:34ch;margin:0 auto 24px}

        .btn{
            display:inline-flex;align-items:center;gap:9px;font-weight:600;font-size:.9rem;
            color:#fff;background:linear-gradient(135deg,var(--accent2),var(--accent));
            padding:12px 26px;border-radius:12px;text-decoration:none;
            box-shadow:0 12px 22px -10px rgba(245,158,11,.7);
            transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;
        }
        .btn:hover{transform:translateY(-2px);filter:brightness(1.04);box-shadow:0 16px 26px -10px rgba(245,158,11,.75)}
        .btn:active{transform:translateY(0)}

        .foot{margin-top:20px;font-size:.78rem;color:rgba(174,184,201,.65)}
        .divider{height:1px;width:56px;margin:20px auto;background:linear-gradient(90deg,transparent,rgba(255,255,255,.18),transparent)}
        @media (max-width:360px){.icon{width:68px;height:68px}.icon i{font-size:26px}}
    </style>
</head>
<body>
    <div class="card">
        <span class="badge"><span class="dot"></span> Scheduled Maintenance</span>
        <div class="icon"><i class="fas fa-gear"></i></div>
        <h1><?php echo $school; ?></h1>
        <h2>We&rsquo;ll be back soon!</h2>
        <p>We&rsquo;re doing a little tidying up behind the scenes to make things even better for you. Hang tight &mdash; we&rsquo;ll be ready in just a bit. Thanks so much for your patience!</p>
        <a class="btn" href="/school_ms/auth/logout.php"><i class="fas fa-arrow-right-from-bracket"></i> Sign out</a>
        <div class="divider"></div>
        <div class="foot">Need help sooner? Please reach out to your administrator.</div>
    </div>
</body>
</html><?php
            exit();
        }
    }

    try {
        $__role = $_SESSION['role'] ?? '';
        $__loggedIn = !empty($_SESSION['user_id']);
        $__isAdmin = in_array($__role, SYSTEM_ADMIN_ROLES, true);

        // ---- 2. Session timeout (idle auto-logout) -------------------------
        if ($__loggedIn) {
            $timeout_min = (int) getSchoolSetting('session_timeout', 30);
            if ($timeout_min > 0) {
                $now = time();
                $last = $_SESSION['last_activity'] ?? $now;
                if (($now - $last) > ($timeout_min * 60)) {
                    // Idle too long — tear down the session and bounce to login.
                    $_SESSION = [];
                    if (ini_get('session.use_cookies')) {
                        $p = session_get_cookie_params();
                        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                    }
                    @session_destroy();
                    if (!headers_sent()) {
                        header('Location: /school_ms/index.php?timeout=1');
                    }
                    exit();
                }
            }
            $_SESSION['last_activity'] = time();
        }

        // ---- 1. Maintenance mode ------------------------------------------
        if (getSchoolSetting('maintenance_mode', 'disabled') === 'enabled' && !$__isAdmin) {
            renderMaintenancePage();
        }

        // ---- 3. Automatic backup scheduler --------------------------------
        if ($__isAdmin && function_exists('runScheduledBackupIfDue')) {
            runScheduledBackupIfDue();
        }
    } catch (Throwable $e) {
        error_log('system_guard failed: ' . $e->getMessage());
    }
}
