<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/audit_log.php';
require_once '../includes/password_policy.php';

$role = $_SESSION['role'];
$is_super = $role === 'super_admin';
$current_school = $_SESSION['school_id'] ?? null;

// Password reset requests live in the CENTRAL directory database, so connect to it
// directly (a school admin's default connection points at their tenant DB).
$central = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
$central->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function updatePasswordInDb($dbName, $email, $hash) {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $dbName, DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare("UPDATE users SET password = :p WHERE email = :e");
        $stmt->execute([':p' => $hash, ':e' => $email]);
        return $stmt->rowCount();
    } catch (PDOException $e) {
        error_log("reset_requests: failed updating '$dbName': " . $e->getMessage());
        return 0;
    }
}

// Resolve a request's email to a central user, enforcing school-admin scope.
function resolveRequestUser($central, $email, $is_super, $current_school) {
    $st = $central->prepare("SELECT u.id, u.name, u.email, u.role, u.school_id, s.db_name AS school_db
                             FROM users u LEFT JOIN schools s ON u.school_id = s.id
                             WHERE u.email = :e LIMIT 1");
    $st->execute([':e' => $email]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return null;
    if (!$is_super && (int)$u['school_id'] !== (int)$current_school) return null;
    if (!$is_super && $u['role'] === 'super_admin') return null;
    return $u;
}

$message = '';
$error = '';

// ---------------------------------------------------------------------------
// Handle actions
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $req_id = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $rstmt = $central->prepare("SELECT * FROM password_reset_requests WHERE id = :id AND status = 'pending' LIMIT 1");
    $rstmt->execute([':id' => $req_id]);
    $req = $rstmt->fetch(PDO::FETCH_ASSOC);

    if (!$req) {
        $error = "That request no longer exists or has already been handled.";
    } elseif (isset($_POST['reject_request'])) {
        $u = resolveRequestUser($central, $req['email'], $is_super, $current_school);
        if (!$is_super && !$u) {
            $error = "You are not authorized to handle that request.";
        } else {
            $central->prepare("UPDATE password_reset_requests SET status='rejected', handled_by=:by, handled_at=NOW() WHERE id=:id")
                    ->execute([':by' => $_SESSION['user_id'], ':id' => $req['id']]);
            logAudit($central, 'reset_request_rejected', "Rejected password reset request from {$req['email']}.");
            $message = "Request from " . htmlspecialchars($req['email']) . " was rejected.";
        }
    } elseif (isset($_POST['complete_request'])) {
        $new_password = $_POST['new_password'] ?? '';
        $u = resolveRequestUser($central, $req['email'], $is_super, $current_school);
        if (!$u) {
            $error = "No matching active account was found for " . htmlspecialchars($req['email']) . ", or you're not authorized to reset it.";
        } elseif (($pw_err = passwordPolicyError($new_password)) !== '') {
            $error = $pw_err;
        } else {
            $hash = password_hash($new_password, PASSWORD_DEFAULT);
            updatePasswordInDb(DB_NAME, $u['email'], $hash);
            if (!empty($u['school_db']) && $u['school_db'] !== DB_NAME) {
                updatePasswordInDb($u['school_db'], $u['email'], $hash);
            }
            $central->prepare("UPDATE password_reset_requests SET status='completed', handled_by=:by, handled_at=NOW() WHERE id=:id")
                    ->execute([':by' => $_SESSION['user_id'], ':id' => $req['id']]);
            logAudit($central, 'reset_request_completed', "Reset password for {$u['name']} ({$u['email']}) via request.");
            $message = "Password for " . htmlspecialchars($u['name']) . " has been reset.";
        }
    }
}

// ---------------------------------------------------------------------------
// Load requests
// ---------------------------------------------------------------------------
$scope = $is_super ? '' : ' AND u.school_id = :sid ';
$params = $is_super ? [] : [':sid' => $current_school];

$pending_sql = "SELECT r.*, u.id AS uid, u.name AS user_name, u.role AS user_role, s.name AS school_name
                FROM password_reset_requests r
                LEFT JOIN users u ON u.email = r.email
                LEFT JOIN schools s ON u.school_id = s.id
                WHERE r.status = 'pending' $scope
                ORDER BY r.requested_at ASC";
$ps = $central->prepare($pending_sql);
$ps->execute($params);
$pending = $ps->fetchAll(PDO::FETCH_ASSOC);

$recent_sql = "SELECT r.*, u.name AS user_name, h.name AS handler_name
               FROM password_reset_requests r
               LEFT JOIN users u ON u.email = r.email
               LEFT JOIN users h ON h.id = r.handled_by
               WHERE r.status <> 'pending' " . ($is_super ? '' : ' AND u.school_id = :sid ') . "
               ORDER BY r.handled_at DESC LIMIT 10";
$rs = $central->prepare($recent_sql);
$rs->execute($params);
$recent = $rs->fetchAll(PDO::FETCH_ASSOC);

$title = "Password Reset Requests";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-6xl mx-auto">
                <!-- Header -->
                <div class="bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-700 rounded-xl p-6 mb-8 text-white shadow-xl">
                    <div class="flex items-center justify-between flex-wrap gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2"><i class="fas fa-user-clock mr-3"></i>Password Reset Requests</h1>
                            <p class="text-indigo-100">Review and fulfil password reset requests from users</p>
                        </div>
                        <div class="text-right">
                            <div class="text-3xl font-bold"><?php echo count($pending); ?></div>
                            <div class="text-xs text-indigo-100">Pending</div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6"><i class="fas fa-check-circle mr-2"></i><?php echo $message; ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6"><i class="fas fa-exclamation-circle mr-2"></i><?php echo $error; ?></div>
                <?php endif; ?>

                <!-- Pending requests -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-hourglass-half mr-2 text-amber-500"></i>Pending Requests</h2>
                    </div>
                    <?php if (empty($pending)): ?>
                    <div class="p-12 text-center">
                        <i class="fas fa-inbox text-gray-300 dark:text-gray-600 text-5xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-1">No pending requests</h3>
                        <p class="text-gray-500 dark:text-gray-400 text-sm">Reset requests submitted by users will appear here.</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-200 dark:divide-gray-700">
                        <?php foreach ($pending as $r): ?>
                        <div class="p-5 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($r['user_name'] ?: ($r['name'] ?: 'Unknown user')); ?></span>
                                    <?php if ($r['user_name']): ?>
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300"><i class="fas fa-check mr-1"></i>Account found</span>
                                    <?php else: ?>
                                    <span class="px-2 py-0.5 text-xs rounded-full bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300">No matching account</span>
                                    <?php endif; ?>
                                    <?php if ($r['user_role']): ?><span class="px-2 py-0.5 text-xs rounded-full bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300"><?php echo htmlspecialchars(function_exists('formatRoleName') ? formatRoleName($r['user_role']) : $r['user_role']); ?></span><?php endif; ?>
                                    <?php if ($r['school_name']): ?><span class="text-xs text-gray-400"><i class="fas fa-school mr-1"></i><?php echo htmlspecialchars($r['school_name']); ?></span><?php endif; ?>
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400 mt-0.5"><?php echo htmlspecialchars($r['email']); ?></div>
                                <?php if ($r['reason']): ?><div class="text-sm text-gray-600 dark:text-gray-300 mt-1 italic">"<?php echo htmlspecialchars($r['reason']); ?>"</div><?php endif; ?>
                                <div class="text-xs text-gray-400 mt-1"><i class="fas fa-clock mr-1"></i><?php echo date('M j, Y g:i A', strtotime($r['requested_at'])); ?> · IP <?php echo htmlspecialchars($r['requested_ip'] ?? '—'); ?></div>
                            </div>
                            <div class="flex items-center gap-2 flex-shrink-0">
                                <?php if ($r['user_name']): ?>
                                <button type="button" onclick="openResetModal(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['user_name']), ENT_QUOTES); ?>', '<?php echo htmlspecialchars(addslashes($r['email']), ENT_QUOTES); ?>')"
                                    class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium"><i class="fas fa-key mr-1"></i>Reset Password</button>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Reject this request?');">
                                    <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                    <button type="submit" name="reject_request" class="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg text-sm">Reject</button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Recently handled -->
                <?php if (!empty($recent)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="font-semibold text-gray-900 dark:text-white"><i class="fas fa-clock-rotate-left mr-2 text-gray-400"></i>Recently Handled</h2>
                    </div>
                    <ul class="divide-y divide-gray-100 dark:divide-gray-700">
                        <?php foreach ($recent as $r): ?>
                        <li class="px-6 py-3 flex items-center justify-between gap-3 text-sm">
                            <div class="min-w-0">
                                <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($r['user_name'] ?: $r['email']); ?></span>
                                <span class="text-gray-400">· <?php echo htmlspecialchars($r['email']); ?></span>
                            </div>
                            <div class="flex items-center gap-3 flex-shrink-0">
                                <span class="px-2 py-0.5 text-xs rounded-full <?php echo $r['status']==='completed' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'; ?>"><?php echo ucfirst($r['status']); ?></span>
                                <span class="text-xs text-gray-400"><?php echo $r['handled_at'] ? date('M j, g:i A', strtotime($r['handled_at'])) : ''; ?><?php echo $r['handler_name'] ? ' · ' . htmlspecialchars($r['handler_name']) : ''; ?></span>
                            </div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0"><?php include '../includes/footer.php'; ?></div>
    </div>
</div>

<!-- Reset modal -->
<div id="resetModal" class="fixed inset-0 bg-black/50 hidden z-50 items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-md">
        <form method="POST" class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><i class="fas fa-key mr-2 text-indigo-600"></i>Reset Password</h3>
                <button type="button" onclick="closeResetModal()" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
            </div>
            <input type="hidden" name="request_id" id="modalRequestId">
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 mb-4 text-sm">
                <div class="text-gray-500 dark:text-gray-400">Resetting password for</div>
                <div class="font-semibold text-gray-900 dark:text-white" id="modalName"></div>
                <div class="text-xs text-gray-400" id="modalEmail"></div>
            </div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">New Password</label>
            <div class="relative mb-4">
                <input type="text" name="new_password" id="modalPw" minlength="6" required
                    class="w-full px-3 py-2 pr-24 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100 focus:ring-2 focus:ring-indigo-500 focus:outline-none" placeholder="At least 6 characters">
                <button type="button" onclick="genPw()" class="absolute right-2 top-1/2 -translate-y-1/2 text-xs bg-gray-200 dark:bg-gray-600 hover:bg-gray-300 px-2 py-1 rounded"><i class="fas fa-dice mr-1"></i>Generate</button>
            </div>
            <p class="text-xs text-gray-400 mb-4">Share this password with the user securely; they should change it after signing in.</p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeResetModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400">Cancel</button>
                <button type="submit" name="complete_request" class="px-5 py-2 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium"><i class="fas fa-check mr-2"></i>Reset &amp; Complete</button>
            </div>
        </form>
    </div>
</div>

<script>
function openResetModal(id, name, email) {
    document.getElementById('modalRequestId').value = id;
    document.getElementById('modalName').textContent = name;
    document.getElementById('modalEmail').textContent = email;
    document.getElementById('modalPw').value = '';
    const m = document.getElementById('resetModal'); m.classList.remove('hidden'); m.classList.add('flex');
}
function closeResetModal() { const m = document.getElementById('resetModal'); m.classList.add('hidden'); m.classList.remove('flex'); }
function genPw() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789@#$%';
    let pw = ''; const arr = new Uint32Array(12); (window.crypto||window.msCrypto).getRandomValues(arr);
    for (let i=0;i<12;i++) pw += chars[arr[i] % chars.length];
    document.getElementById('modalPw').value = pw;
}
document.getElementById('resetModal').addEventListener('click', function(e){ if (e.target===this) closeResetModal(); });
</script>
