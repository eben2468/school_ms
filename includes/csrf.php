<?php
/**
 * CSRF protection helpers.
 * --------------------------------------------------------------------------
 * Usage:
 *   - Output a token inside any POST <form>:   echo csrf_field();
 *   - Verify on the server in a POST handler:  csrf_require($redirectUrl);
 *     (or use csrf_verify() for a boolean and handle the failure yourself)
 *
 * The token is also exposed via a <meta name="csrf-token"> tag and attached to
 * same-origin fetch/XHR POSTs automatically (see footer.php), so AJAX callers
 * send it through the X-CSRF-Token header without per-call changes.
 */

if (!function_exists('csrf_token')) {
    function csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        return '<input type="hidden" name="csrf_token" value="'
            . htmlspecialchars(csrf_token(), ENT_QUOTES) . '">';
    }
}

if (!function_exists('csrf_verify')) {
    /**
     * @return bool true when the request carries a valid token.
     */
    function csrf_verify() {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $real = $_SESSION['csrf_token'] ?? '';
        if ($real === '') {
            return false;
        }
        // Accept the token from a form field or the AJAX header.
        $sent = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        return is_string($sent) && $sent !== '' && hash_equals($real, $sent);
    }
}

if (!function_exists('csrf_require')) {
    /**
     * Verify the token or stop the request. On failure, redirect back with an
     * error message when a URL is given, otherwise emit a 419 response.
     *
     * @param string|null $redirect Where to send the user on failure.
     */
    function csrf_require($redirect = null) {
        if (csrf_verify()) {
            return true;
        }
        http_response_code(419);
        if ($redirect) {
            $sep = (strpos($redirect, '?') !== false) ? '&' : '?';
            header('Location: ' . $redirect . $sep . 'error='
                . urlencode('Your session has expired. Please refresh the page and try again.'));
        } else {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid or missing security token.']);
        }
        exit();
    }
}
