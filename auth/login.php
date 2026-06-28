<?php
// Harden the session cookie before the session starts.
$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') == 443);
if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true, 'secure' => $__https, 'samesite' => 'Lax',
    ]);
} else {
    session_set_cookie_params(0, '/', '', $__https, true);
}
session_start();

// Always clear any existing school dynamic DB context when initiating a new login lookup
unset($_SESSION['school_db_name']);
unset($_SESSION['school_id']);
unset($_SESSION['school_name']);

require_once '../config/database.php';
require_once '../includes/audit_log.php';
require_once '../includes/login_throttle.php';
require_once '../includes/csrf.php';


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Reject forged cross-site submissions before doing any work.
    csrf_require('../index.php');

    // Login identifier may be an email address, a student ID, or a staff (employee) ID.
    $identifier = trim((string)($_POST['email'] ?? ''));
    $password = $_POST['password'];
    $remember = isset($_POST['remember']) ? true : false;
    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    $database = new Database();
    $db = $database->getConnection();

    // Block the attempt up-front if this identifier/IP is currently locked out.
    $lock_remaining = getLoginLockRemaining($db, $identifier, $client_ip);
    if ($lock_remaining > 0) {
        $mins = (int)ceil($lock_remaining / 60);
        $safe_id = preg_replace('/[^\w@.\-]/', '', (string)$identifier);
        logAudit($db, 'login_locked', "Blocked login for locked identifier '{$safe_id}' ({$mins} min remaining)");
        header("Location: ../index.php?error=" . urlencode("Too many failed attempts. This account is temporarily locked. Please try again in {$mins} minute(s)."));
        exit();
    }

    try {
        // 1) Match by email or student ID directly on the users directory.
        // A login identifier (email or student ID) is NOT globally unique: legacy
        // rows in the central directory (school_id NULL) can collide with a tenant
        // student's freshly generated ID. Always prefer the row bound to a live
        // school so imported tenant users resolve to their own database instead of
        // an orphan directory row.
        $query = "SELECT u.*, s.db_name as school_db, s.name as school_name, s.status as school_status
                  FROM users u
                  LEFT JOIN schools s ON u.school_id = s.id
                  WHERE (u.email = :id OR u.student_id = :id) AND u.status = 'active'
                  ORDER BY (u.school_id IS NOT NULL AND s.status = 'active') DESC, u.id DESC
                  LIMIT 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $identifier);
        $stmt->execute();
        $central_user = $stmt->fetch(PDO::FETCH_ASSOC);

        // 2) If not found, try matching a staff/employee ID via teacher_profiles.
        if (!$central_user) {
            try {
                $staff_query = "SELECT u.*, s.db_name as school_db, s.name as school_name, s.status as school_status
                                FROM teacher_profiles tp
                                JOIN users u ON u.id = tp.user_id
                                LEFT JOIN schools s ON u.school_id = s.id
                                WHERE tp.employee_id = :id AND u.status = 'active'
                                ORDER BY (u.school_id IS NOT NULL AND s.status = 'active') DESC, u.id DESC
                                LIMIT 1";
                $staff_stmt = $db->prepare($staff_query);
                $staff_stmt->bindParam(':id', $identifier);
                $staff_stmt->execute();
                $central_user = $staff_stmt->fetch(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // teacher_profiles not present in this directory DB — ignore and continue.
            }
        }

        if ($central_user) {
            // Resolved email used for any tenant-side re-lookup.
            $email = $central_user['email'];

            // If the user belongs to a school, dynamically switch databases
            if ($central_user['school_id'] !== null) {
                if ($central_user['school_status'] !== 'active') {
                    header("Location: ../index.php?error=" . urlencode("Your school's subscription is suspended or inactive."));
                    exit();
                }
                
                $_SESSION['school_db_name'] = $central_user['school_db'];
                $_SESSION['school_id'] = $central_user['school_id'];
                $_SESSION['school_name'] = $central_user['school_name'];
                
                // Reconnect to database. getConnection() will now bind to the tenant database
                $tenant_database = new Database();
                $tenant_db = $tenant_database->getConnection();
                
                $tenant_query = "SELECT id, name, email, password, role, profile_picture FROM users WHERE email = :email AND status = 'active'";
                $tenant_stmt = $tenant_db->prepare($tenant_query);
                $tenant_stmt->bindParam(':email', $email);
                $tenant_stmt->execute();
                
                if ($tenant_stmt->rowCount() > 0) {
                    $user = $tenant_stmt->fetch(PDO::FETCH_ASSOC);
                } else {
                    $user = $central_user;
                }
            } else {
                // System level user (Super Admin)
                $user = $central_user;
                unset($_SESSION['school_db_name']);
                unset($_SESSION['school_id']);
                unset($_SESSION['school_name']);
            }

            if (password_verify($password, $user['password'])) {
                // Prevent session fixation: issue a fresh session ID on auth.
                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['name'] = $user['name'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['profile_picture'] = $user['profile_picture'];

                // Reset the failed-attempt counter for this identifier/IP.
                clearLoginAttempts($db, $identifier, $client_ip);

                // Record the successful login in the user's active database
                // (tenant DB for school users, central for system users).
                $login_db = isset($tenant_db) ? $tenant_db : $db;
                logAudit($login_db, 'login', "Successful login: {$user['email']} (" . ($user['role'] ?? 'user') . ")");

                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    // Store only a hash of the token; the raw value lives in the
                    // cookie. A DB leak then yields no usable tokens.
                    $token_hash = hash('sha256', $token);
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));

                    $active_db = isset($tenant_db) ? $tenant_db : $db;
                    $query = "INSERT INTO remember_tokens (user_id, token, expires_at) VALUES (:user_id, :token, :expires)";
                    $stmt = $active_db->prepare($query);
                    $stmt->bindParam(':user_id', $user['id']);
                    $stmt->bindParam(':token', $token_hash);
                    $stmt->bindParam(':expires', $expires);
                    $stmt->execute();

                    setcookie('remember_token', $token, [
                        'expires'  => strtotime('+30 days'),
                        'path'     => '/',
                        'secure'   => $__https,
                        'httponly' => true,
                        'samesite' => 'Lax',
                    ]);
                }

                // Parents have a dedicated dashboard; everyone else uses the shared one.
                if ($user['role'] === 'parent') {
                    header("Location: ../parent/dashboard.php");
                } else {
                    header("Location: ../dashboard.php");
                }
                exit();
            }
        }
        
        // Count this failure against the lockout threshold (tracked centrally).
        $locked_secs = recordFailedLogin($db, $identifier, $client_ip);

        // Record the failed attempt for security monitoring. Attribute it to the
        // resolved school's DB when the identifier matched a school user,
        // otherwise to the central directory.
        $fail_db = isset($tenant_db) ? $tenant_db : $db;
        $safe_identifier = preg_replace('/[^\w@.\-]/', '', (string)$identifier);
        logAudit($fail_db, 'login_failed', "Failed login attempt for identifier '{$safe_identifier}'");

        if ($locked_secs > 0) {
            $mins = (int)ceil($locked_secs / 60);
            header("Location: ../index.php?error=" . urlencode("Too many failed attempts. This account has been locked for {$mins} minute(s)."));
            exit();
        }

        header("Location: ../index.php?error=" . urlencode("Invalid credentials. Use your email, student ID, or staff ID with your password."));
        exit();
    } catch (PDOException $e) {
        header("Location: ../index.php?error=An error occurred. Please try again later.");
        exit();
    }
} else {
    header("Location: ../index.php");
    exit();
}
?>