<?php
session_start();

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
