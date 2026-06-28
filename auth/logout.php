<?php
session_start();

// Record the logout in the user's active database BEFORE the session is torn
// down (we need the session's school/user context to attribute it correctly).
if (!empty($_SESSION['user_id'])) {
    require_once '../config/database.php';
    require_once '../includes/audit_log.php';
    $logout_db = (new Database())->getConnection();
    logAudit($logout_db, 'logout', 'User logged out: ' . ($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
}

// Destroy all session data
session_unset();
session_destroy();

// Clear any remember me cookies
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/');
}

// Redirect to login page
header("Location: ../index.php");
exit();
?>
