<?php
/**
 * End a super-admin impersonation session and restore the original super admin.
 * Reverses settings/impersonate.php. Safe to call from any page (the banner).
 */

session_start();
require_once '../config/database.php';

// Nothing to exit — just bounce home.
if (empty($_SESSION['impersonator'])) {
    header('Location: ../dashboard.php');
    exit();
}

$imp = $_SESSION['impersonator'];

// Audit the return against the real super admin, in the central directory DB.
try {
    $central = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
    $central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $audit = $central->prepare(
        "INSERT INTO audit_logs (school_id, user_id, action, details, ip_address)
         VALUES (:school_id, :user_id, 'super_admin_impersonate_end', :details, :ip)"
    );
    $audit->execute([
        ':school_id' => $_SESSION['school_id'] ?? null,
        ':user_id'   => $imp['user_id'],
        ':details'   => "Super admin '" . ($imp['user_name'] ?? '') . "' exited school '" . ($imp['school_name'] ?? '') . "'.",
        ':ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
} catch (Exception $e) {
    // Auditing must never block the return to the super-admin context.
}

// Drop the tenant context entirely.
unset($_SESSION['school_db_name'], $_SESSION['school_id'], $_SESSION['school_name']);

// Restore the original super-admin identity.
$_SESSION['user_id']         = $imp['user_id'];
$_SESSION['user_name']       = $imp['user_name'];
$_SESSION['name']            = $imp['name'];
$_SESSION['user_email']      = $imp['user_email'];
$_SESSION['email']           = $imp['email'];
$_SESSION['role']            = $imp['role'];
$_SESSION['profile_picture'] = $imp['profile_picture'];

unset($_SESSION['impersonator']);

header('Location: super_admin.php?tab=schools');
exit();
