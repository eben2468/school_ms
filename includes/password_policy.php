<?php
/**
 * Centralised password strength policy.
 * Use validatePasswordStrength() wherever a user sets or changes a password.
 *
 * Policy: at least 8 characters, containing at least one letter and one number.
 * Kept deliberately moderate so it is enforceable without frustrating users;
 * tighten here in one place if a stricter rule is ever required.
 */
if (!function_exists('validatePasswordStrength')) {
    /**
     * @param string $password
     * @return array { valid:bool, message:string }
     */
    function validatePasswordStrength($password) {
        $password = (string)$password;

        if (strlen($password) < 8) {
            return ['valid' => false, 'message' => 'Password must be at least 8 characters long.'];
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one letter.'];
        }
        if (!preg_match('/[0-9]/', $password)) {
            return ['valid' => false, 'message' => 'Password must contain at least one number.'];
        }

        // Reject a few obviously weak passwords regardless of the rules above.
        $weak = ['password', 'password1', '12345678', 'qwerty123', 'admin123'];
        if (in_array(strtolower($password), $weak, true)) {
            return ['valid' => false, 'message' => 'This password is too common. Please choose a stronger one.'];
        }

        return ['valid' => true, 'message' => ''];
    }
}

if (!function_exists('passwordPolicyError')) {
    /**
     * Convenience wrapper for elseif-style validation chains.
     * @return string '' when valid, otherwise the failure message.
     */
    function passwordPolicyError($password) {
        $r = validatePasswordStrength($password);
        return $r['valid'] ? '' : $r['message'];
    }
}
