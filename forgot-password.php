<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// Pre-login page — always operate on the central directory database.
unset($_SESSION['school_db_name']);
unset($_SESSION['school_id']);
unset($_SESSION['school_name']);

require_once 'config/database.php';
require_once 'includes/settings_helper.php';
require_once 'includes/password_policy.php';

$school_name    = getSchoolSetting('school_name', 'School Management System');
$theme_gradient = getThemeGradient();

$database = new Database();
$db = $database->getConnection();

$error = '';
$view  = 'choose';          // choose | set_password | request_sent | reset_done
$tab   = 'verify';          // which tab is active on the chooser

// ---- helpers -------------------------------------------------------------
function normPhone($p) { return preg_replace('/\D+/', '', (string)$p); }
function phoneMatches($a, $b) {
    $a = normPhone($a); $b = normPhone($b);
    if ($a === '' || $b === '') return false;
    if ($a === $b) return true;
    return strlen($a) >= 9 && strlen($b) >= 9 && substr($a, -9) === substr($b, -9);
}
function updatePasswordInDb($dbName, $email, $hash) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("UPDATE users SET password = :p WHERE email = :e");
        $stmt->execute([':p' => $hash, ':e' => $email]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("forgot-password: failed updating '$dbName': " . $e->getMessage());
        return 0;
    }
}

// Insert one in-app notification into a given DB's notifications table for a given user id.
function insertResetNotification($pdo, $user_id, $title, $message) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications
            (user_id, title, message, type, priority, icon, action_url, action_text)
            VALUES (:uid, :t, :m, 'system', 'high', 'fas fa-user-clock', '/admin/reset_requests.php', 'View Requests')");
        $stmt->execute([':uid' => $user_id, ':t' => $title, ':m' => $message]);
    } catch (PDOException $e) {
        error_log('insertResetNotification failed: ' . $e->getMessage());
    }
}

/**
 * Notify the admins who can act on a reset request. Super admins read notifications
 * from the central DB; school admins read from their own tenant DB (where their user
 * id differs), so we map by email and write into the DB each admin actually reads from.
 */
function notifyAdminsOfResetRequest($central, $req_email, $req_name) {
    $title = 'Password Reset Request';
    $msg   = ($req_name !== '' ? $req_name : $req_email) . ' has requested a password reset.';

    // Which school (if any) does the requester belong to?
    $r = $central->prepare("SELECT school_id FROM users WHERE email = :e LIMIT 1");
    $r->execute([':e' => $req_email]);
    $req_school_id = $r->fetchColumn();
    $req_school_id = $req_school_id !== false ? $req_school_id : null;

    // 1) All super admins — they read the central notifications table
    try {
        $supers = $central->query("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($supers as $sid) {
            insertResetNotification($central, $sid, $title, $msg);
        }
    } catch (PDOException $e) { error_log('notify supers failed: ' . $e->getMessage()); }

    // 2) School admins of the requester's school — read from their own tenant DB
    if ($req_school_id !== null) {
        try {
            $as = $central->prepare("SELECT u.email, s.db_name
                                     FROM users u LEFT JOIN schools s ON u.school_id = s.id
                                     WHERE u.role = 'school_admin' AND u.school_id = :sid AND u.status = 'active'");
            $as->execute([':sid' => $req_school_id]);
            foreach ($as->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $targetDb = !empty($a['db_name']) ? $a['db_name'] : DB_NAME;
                try {
                    $pdo = ($targetDb === DB_NAME)
                        ? $central
                        : new PDO("mysql:host=" . DB_HOST . ";dbname=" . $targetDb, DB_USER, DB_PASS);
                    if ($targetDb !== DB_NAME) { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); }
                    $idstmt = $pdo->prepare("SELECT id FROM users WHERE email = :e LIMIT 1");
                    $idstmt->execute([':e' => $a['email']]);
                    if ($admin_uid = $idstmt->fetchColumn()) {
                        insertResetNotification($pdo, $admin_uid, $title, $msg);
                    }
                } catch (PDOException $e) { error_log('notify school admin failed: ' . $e->getMessage()); }
            }
        } catch (PDOException $e) { error_log('notify school admins query failed: ' . $e->getMessage()); }
    }
}

$action = $_POST['action'] ?? '';

// ==========================================================================
// 1. IDENTITY VERIFICATION CHALLENGE (instant self-service)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'verify_identity') {
    $tab = 'verify';

    // Simple throttle to discourage guessing
    $now = time();
    if (!isset($_SESSION['fp_attempts'])) { $_SESSION['fp_attempts'] = ['count' => 0, 'first' => $now]; }
    if ($now - $_SESSION['fp_attempts']['first'] > 900) { $_SESSION['fp_attempts'] = ['count' => 0, 'first' => $now]; }

    $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $dob   = trim($_POST['dob'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($_SESSION['fp_attempts']['count'] >= 6) {
        $error = 'Too many attempts. Please wait a while, or use the "Request Admin Reset" option.';
    } elseif (!$email || !$dob || !$phone) {
        $error = 'Please fill in all fields.';
    } else {
        $_SESSION['fp_attempts']['count']++;

        // Find the account in the central directory
        $stmt = $db->prepare("SELECT u.id, u.name, u.email, u.school_id, s.db_name AS school_db, s.status AS school_status
                              FROM users u
                              LEFT JOIN schools s ON u.school_id = s.id
                              WHERE u.email = :email AND u.status = 'active' LIMIT 1");
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        $verified = false;
        if ($user && ($user['school_id'] === null || $user['school_status'] === 'active')) {
            // Pull date_of_birth + phone from whichever profile table holds this user
            $prof = null;
            foreach (['student_profiles', 'teacher_profiles'] as $pt) {
                try {
                    $ps = $db->prepare("SELECT date_of_birth, phone FROM $pt WHERE user_id = :uid LIMIT 1");
                    $ps->execute([':uid' => $user['id']]);
                    if ($row = $ps->fetch(PDO::FETCH_ASSOC)) { $prof = $row; break; }
                } catch (PDOException $e) { /* table may not exist */ }
            }
            if ($prof && !empty($prof['date_of_birth']) && $prof['date_of_birth'] === $dob && phoneMatches($prof['phone'], $phone)) {
                $verified = true;
            }
        }

        if ($verified) {
            // Hold a short-lived verification in the session and move to the set-password step
            $_SESSION['fp_verified'] = [
                'email'     => $user['email'],
                'school_db' => $user['school_db'],
                'name'      => $user['name'],
                'ts'        => $now,
            ];
            unset($_SESSION['fp_attempts']);
            $view = 'set_password';
        } else {
            $error = "We couldn't verify your identity with the details provided. Please check them, or use the \"Request Admin Reset\" option.";
        }
    }
}

// ==========================================================================
// 2. SET NEW PASSWORD (after successful identity verification)
// ==========================================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'set_password') {
    $v = $_SESSION['fp_verified'] ?? null;
    if (!$v || (time() - $v['ts']) > 600) {
        unset($_SESSION['fp_verified']);
        $error = 'Your verification session expired. Please verify your identity again.';
        $view = 'choose';
    } else {
        $new_password = $_POST['new_password'] ?? '';
        $confirm      = $_POST['confirm_password'] ?? '';
        if (($pw_err = passwordPolicyError($new_password)) !== '') {
            $error = $pw_err;
            $view = 'set_password';
        } elseif ($new_password !== $confirm) {
            $error = 'Passwords do not match.';
            $view = 'set_password';
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            updatePasswordInDb(DB_NAME, $v['email'], $hash);
            if (!empty($v['school_db']) && $v['school_db'] !== DB_NAME) {
                updatePasswordInDb($v['school_db'], $v['email'], $hash);
            }
            unset($_SESSION['fp_verified']);
            $view = 'reset_done';
        }
    }
}

// ==========================================================================
// 3. ADMIN-APPROVED RESET REQUEST
// ==========================================================================
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'request_reset') {
    $tab = 'request';
    $email  = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
    $name   = trim($_POST['name'] ?? '');
    $reason = trim($_POST['reason'] ?? '');

    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            // Avoid stacking duplicate pending requests for the same email
            $chk = $db->prepare("SELECT id FROM password_reset_requests WHERE email = :e AND status = 'pending' LIMIT 1");
            $chk->execute([':e' => $email]);
            if (!$chk->fetch()) {
                $ins = $db->prepare("INSERT INTO password_reset_requests (email, name, reason, requested_ip)
                                     VALUES (:e, :n, :r, :ip)");
                $ins->execute([
                    ':e'  => $email,
                    ':n'  => $name !== '' ? $name : null,
                    ':r'  => $reason !== '' ? $reason : null,
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);
                // Notify the relevant admins (best effort — never blocks the user)
                notifyAdminsOfResetRequest($db, $email, $name);
            }
            $view = 'request_sent';
        } catch (PDOException $e) {
            error_log('forgot-password request error: ' . $e->getMessage());
            $view = 'request_sent'; // never reveal internals
        }
    }
}

// Returning to the set-password form should only work with a live verification
if ($view === 'set_password' && empty($_SESSION['fp_verified'])) {
    $view = 'choose';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <link href="assets/css/dynamic-theme.php" rel="stylesheet">
    <link href="assets/css/responsive.css" rel="stylesheet">
    <link href="assets/css/app.css" rel="stylesheet">
    <style>
        html { font-size: 13px !important; }
        .login-gradient { background: <?php echo $theme_gradient; ?>; }
        .login-card { backdrop-filter: blur(10px); background: rgba(255,255,255,0.95); border: 1px solid rgba(255,255,255,0.2); }
        .theme-button { background: <?php echo $theme_gradient; ?>; }
        .theme-focus:focus { box-shadow: 0 0 0 3px rgba(59,130,246,0.1); }
        .floating-shapes { position: absolute; width: 100%; height: 100%; overflow: hidden; z-index: 0; }
        .shape { position: absolute; background: rgba(255,255,255,0.1); border-radius: 50%; animation: float 6s ease-in-out infinite; }
        .shape:nth-child(1) { width: 80px; height: 80px; top: 20%; left: 10%; animation-delay: 0s; }
        .shape:nth-child(2) { width: 120px; height: 120px; top: 60%; right: 10%; animation-delay: 2s; }
        .shape:nth-child(3) { width: 60px; height: 60px; bottom: 20%; left: 20%; animation-delay: 4s; }
        @keyframes float { 0%,100% { transform: translateY(0) rotate(0deg); } 50% { transform: translateY(-20px) rotate(180deg); } }
        .tab-btn.active { color: #fff; }
    </style>
</head>
<body class="login-gradient min-h-screen flex items-center justify-center relative p-4">

    <div class="floating-shapes">
        <div class="shape"></div><div class="shape"></div><div class="shape"></div>
    </div>

    <div class="login-card p-8 rounded-2xl shadow-2xl w-full max-w-md relative z-10">

        <?php if ($view === 'reset_done'): ?>
            <!-- SUCCESS: password changed -->
            <div class="text-center">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-green-100 flex items-center justify-center">
                    <i class="fas fa-check-circle text-4xl text-green-600"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Password Updated!</h1>
                <p class="text-gray-600 text-sm mb-6">Your password has been changed successfully. You can now sign in with your new password.</p>
                <a href="index.php" class="inline-flex items-center justify-center w-full py-3 px-4 rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 transition-all duration-200">
                    <i class="fas fa-sign-in-alt mr-2"></i>Sign In
                </a>
            </div>

        <?php elseif ($view === 'request_sent'): ?>
            <!-- Admin request acknowledged -->
            <div class="text-center">
                <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-blue-100 flex items-center justify-center">
                    <i class="fas fa-user-clock text-3xl text-blue-600"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800 mb-2">Request Submitted</h1>
                <p class="text-gray-600 text-sm mb-6">Your password reset request has been sent to an administrator. They will verify your identity and reset your password. Please check back or contact your school office.</p>
                <a href="index.php" class="inline-flex items-center justify-center w-full py-3 px-4 rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 transition-all duration-200">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Sign In
                </a>
            </div>

        <?php elseif ($view === 'set_password'): ?>
            <!-- Identity verified -> choose new password -->
            <div class="text-center mb-6">
                <div class="w-20 h-20 mx-auto mb-4 rounded-2xl theme-button flex items-center justify-center shadow-lg">
                    <i class="fas fa-lock-open text-3xl text-white"></i>
                </div>
                <h1 class="text-2xl font-bold text-gray-800 mb-1">Identity Verified</h1>
                <p class="text-gray-600 text-sm">Hello <?php echo htmlspecialchars($_SESSION['fp_verified']['name'] ?? ''); ?>, choose a new password.</p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-5" novalidate>
                <input type="hidden" name="action" value="set_password">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-lock mr-2 text-gray-500"></i>New Password</label>
                    <div class="relative">
                        <input type="password" id="new_password" name="new_password" required minlength="6"
                            class="w-full px-4 py-3 pr-12 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="At least 6 characters">
                        <button type="button" onclick="togglePw('new_password', this)" class="absolute inset-y-0 right-0 flex items-center px-4 text-gray-400 hover:text-gray-600"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2"><i class="fas fa-lock mr-2 text-gray-500"></i>Confirm Password</label>
                    <input type="password" name="confirm_password" required minlength="6"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="Re-enter your new password">
                </div>
                <button type="submit" class="w-full flex justify-center items-center py-3 px-4 rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 transition-all duration-200 transform hover:scale-105">
                    <i class="fas fa-check mr-2"></i>Update Password
                </button>
            </form>

        <?php else: ?>
            <!-- CHOOSER: two methods -->
            <div class="text-center mb-6">
                <div class="w-20 h-20 mx-auto mb-4 rounded-2xl theme-button flex items-center justify-center shadow-lg">
                    <i class="fas fa-key text-3xl text-white"></i>
                </div>
                <h1 class="text-3xl font-bold text-gray-800 mb-2">Forgot Password?</h1>
                <p class="text-gray-600">Choose how you'd like to recover your account</p>
            </div>

            <!-- Tabs -->
            <div class="flex p-1 mb-6 bg-gray-100 rounded-xl">
                <button type="button" id="tab-verify" onclick="switchTab('verify')"
                    class="tab-btn flex-1 py-2.5 px-3 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $tab==='verify' ? 'active theme-button shadow' : 'text-gray-600'; ?>">
                    <i class="fas fa-id-card mr-1"></i> Verify Identity
                </button>
                <button type="button" id="tab-request" onclick="switchTab('request')"
                    class="tab-btn flex-1 py-2.5 px-3 rounded-lg text-sm font-medium transition-all duration-200 <?php echo $tab==='request' ? 'active theme-button shadow' : 'text-gray-600'; ?>">
                    <i class="fas fa-user-shield mr-1"></i> Request Admin
                </button>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 text-sm"><i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Method 1: Verify identity -->
            <div id="panel-verify" class="<?php echo $tab==='verify' ? '' : 'hidden'; ?>">
                <p class="text-sm text-gray-600 mb-4">Confirm your identity with the details on file to reset your password instantly.</p>
                <form method="POST" class="space-y-4" novalidate>
                    <input type="hidden" name="action" value="verify_identity">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-envelope mr-2 text-gray-500"></i>Email Address</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="you@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-calendar mr-2 text-gray-500"></i>Date of Birth</label>
                        <input type="date" name="dob" required value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-phone mr-2 text-gray-500"></i>Phone Number on File</label>
                        <input type="tel" name="phone" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="e.g. 024 123 4567">
                    </div>
                    <button type="submit" class="w-full flex justify-center items-center py-3 px-4 rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 transition-all duration-200 transform hover:scale-105">
                        <i class="fas fa-shield-alt mr-2"></i>Verify &amp; Continue
                    </button>
                </form>
            </div>

            <!-- Method 2: Request admin reset -->
            <div id="panel-request" class="<?php echo $tab==='request' ? '' : 'hidden'; ?>">
                <p class="text-sm text-gray-600 mb-4">Can't verify your details? Send a reset request and an administrator will help you.</p>
                <form method="POST" class="space-y-4" novalidate>
                    <input type="hidden" name="action" value="request_reset">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-envelope mr-2 text-gray-500"></i>Email Address</label>
                        <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="you@example.com">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-user mr-2 text-gray-500"></i>Full Name <span class="text-gray-400">(optional)</span></label>
                        <input type="text" name="name"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="Your full name">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1"><i class="fas fa-comment mr-2 text-gray-500"></i>Message <span class="text-gray-400">(optional)</span></label>
                        <textarea name="reason" rows="2"
                            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg shadow-sm focus:outline-none theme-focus focus:border-blue-500" placeholder="Anything that helps verify you"></textarea>
                    </div>
                    <button type="submit" class="w-full flex justify-center items-center py-3 px-4 rounded-lg shadow-lg text-sm font-medium text-white theme-button hover:opacity-90 transition-all duration-200 transform hover:scale-105">
                        <i class="fas fa-paper-plane mr-2"></i>Send Request
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <a href="index.php" class="text-sm text-blue-600 hover:text-blue-800 transition-colors duration-200">
                <i class="fas fa-arrow-left mr-1"></i>Back to Sign In
            </a>
        </div>
    </div>

    <script>
        function switchTab(which) {
            ['verify','request'].forEach(function(t){
                document.getElementById('panel-'+t).classList.toggle('hidden', t !== which);
                var btn = document.getElementById('tab-'+t);
                if (t === which) { btn.classList.add('active','theme-button','shadow'); btn.classList.remove('text-gray-600'); }
                else { btn.classList.remove('active','theme-button','shadow'); btn.classList.add('text-gray-600'); }
            });
        }
        function togglePw(id, btn) {
            const input = document.getElementById(id), icon = btn.querySelector('i');
            if (input.type === 'password') { input.type = 'text'; icon.className = 'fas fa-eye-slash'; }
            else { input.type = 'password'; icon.className = 'fas fa-eye'; }
        }
    </script>
</body>
</html>
