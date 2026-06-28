<?php
/**
 * Super Admin → School Impersonation ("Enter School")
 * ---------------------------------------------------
 * Lets a super admin step into any registered school's admin context WITHOUT
 * needing that school's password. It works by swapping the session onto the
 * target tenant database and assuming the school's existing school_admin
 * identity — the exact same session mechanism auth/login.php uses.
 *
 * The original super-admin identity is stashed in $_SESSION['impersonator']
 * so exit_impersonation.php can restore it in one click.
 *
 * Per design decision: actions performed while impersonating are recorded
 * under the SCHOOL's admin user (the assumed tenant identity), while the
 * crossing itself is audit-logged against the real super admin.
 */

session_start();
require_once '../includes/access_control.php';

// Only a genuine super admin may impersonate, and never while already impersonating.
requireRole(['super_admin']);
if (!empty($_SESSION['impersonator'])) {
    header('Location: ../dashboard.php');
    exit();
}

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: super_admin.php?tab=schools');
    exit();
}

// CSRF guard
$token = $_POST['impersonate_token'] ?? '';
if (empty($_SESSION['impersonate_token']) || !hash_equals($_SESSION['impersonate_token'], $token)) {
    $_SESSION['error_message'] = 'Security token mismatch. Please try again.';
    $_SESSION['admin_tab'] = 'schools';
    header('Location: super_admin.php');
    exit();
}

$school_id = (int)($_POST['school_id'] ?? 0);

// Connect to the central directory database explicitly (current session has no
// tenant context yet, so getConnection() returns central — but be explicit).
$central = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $stmt = $central->prepare('SELECT * FROM schools WHERE id = :id');
    $stmt->execute([':id' => $school_id]);
    $school = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$school) {
        throw new Exception('School not found.');
    }

    // Connect to the target tenant DB and find its school_admin identity.
    $tenant = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $school['db_name'], DB_USER, DB_PASS);
    $tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $admin_stmt = $tenant->prepare(
        "SELECT id, name, email, role, profile_picture
         FROM users
         WHERE role = 'school_admin' AND status = 'active'
         ORDER BY id ASC LIMIT 1"
    );
    $admin_stmt->execute();
    $tenant_admin = $admin_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tenant_admin) {
        throw new Exception("No active school admin account exists in '{$school['name']}'.");
    }

    // ── Audit the crossing against the REAL super admin (central DB) ──
    $audit = $central->prepare(
        "INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
         VALUES (:school_id, :user_id, 'super_admin_impersonate', :details, :ip)"
    );
    $audit->execute([
        ':school_id' => $school_id,
        ':user_id'   => $_SESSION['user_id'],
        ':details'   => "Super admin '" . ($_SESSION['user_name'] ?? '') . "' entered school '{$school['name']}' as '{$tenant_admin['name']}' ({$tenant_admin['email']}).",
        ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);

    // ── Stash the original super-admin identity so we can return ──
    $_SESSION['impersonator'] = [
        'user_id'         => $_SESSION['user_id'],
        'user_name'       => $_SESSION['user_name'] ?? null,
        'name'            => $_SESSION['name'] ?? null,
        'user_email'      => $_SESSION['user_email'] ?? null,
        'email'           => $_SESSION['email'] ?? null,
        'role'            => $_SESSION['role'] ?? null,
        'profile_picture' => $_SESSION['profile_picture'] ?? null,
        'started_at'      => time(),
        'school_name'     => $school['name'],
    ];

    // ── Swap the session into the tenant's school_admin context ──
    $_SESSION['school_db_name'] = $school['db_name'];
    $_SESSION['school_id']      = $school_id;
    $_SESSION['school_name']    = $school['name'];

    $_SESSION['user_id']         = $tenant_admin['id'];
    $_SESSION['user_name']       = $tenant_admin['name'];
    $_SESSION['name']            = $tenant_admin['name'];
    $_SESSION['user_email']      = $tenant_admin['email'];
    $_SESSION['email']           = $tenant_admin['email'];
    $_SESSION['role']            = $tenant_admin['role'];
    $_SESSION['profile_picture'] = $tenant_admin['profile_picture'];

    header('Location: ../dashboard.php');
    exit();
} catch (Exception $ex) {
    $_SESSION['error_message'] = 'Could not enter school: ' . $ex->getMessage();
    $_SESSION['admin_tab'] = 'schools';
    header('Location: super_admin.php');
    exit();
}
