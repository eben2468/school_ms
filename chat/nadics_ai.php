<?php
/**
 * Nadics AI endpoint
 * --------------------------------------------------------------------------
 * Conversational assistant available to every signed-in user. Assembles a
 * small, ROLE-SCOPED context (only the requesting user's own, permitted data),
 * asks the configured free AI provider (with an offline built-in fallback) and
 * returns a reply as JSON. Every interaction is logged for quality review.
 */
session_start();
header('Content-Type: application/json');

// --- Authentication -------------------------------------------------------
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Please sign in to use the assistant.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

require_once __DIR__ . '/../includes/csrf.php';
csrf_require(); // token auto-attached to same-origin fetch by footer.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ai_helper.php';
require_once __DIR__ . '/../includes/schema_helpers.php';

// --- Master enable check --------------------------------------------------
$config = getNadicsConfig();
if (($config['nadics_enabled'] ?? '1') === '0') {
    echo json_encode(['success' => false, 'message' => 'The assistant is currently disabled by your administrator.']);
    exit();
}

// --- Lightweight per-session rate limiting --------------------------------
// Protects the free API quota and curbs abuse: max 20 messages per rolling
// 60-second window, per session.
$now = time();
$_SESSION['nadics_hits'] = array_values(array_filter(
    $_SESSION['nadics_hits'] ?? [],
    function ($t) use ($now) { return ($now - $t) < 60; }
));
if (count($_SESSION['nadics_hits']) >= 20) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => "You're sending messages a little too quickly. Please wait a moment and try again.",
    ]);
    exit();
}
$_SESSION['nadics_hits'][] = $now;

// --- Input ----------------------------------------------------------------
$input   = json_decode(file_get_contents('php://input'), true) ?: [];
$message = trim((string)($input['message'] ?? ''));
$history = is_array($input['history'] ?? null) ? $input['history'] : [];

if ($message === '') {
    echo json_encode(['success' => false, 'message' => 'Please type a question.']);
    exit();
}
if (mb_strlen($message) > 2000) {
    $message = mb_substr($message, 0, 2000);
}

// Keep only the last 10 turns and sanitise their shape before sending upstream.
$cleanHistory = [];
foreach (array_slice($history, -10) as $turn) {
    $role = ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $text = trim((string)($turn['text'] ?? ''));
    if ($text === '') { continue; }
    $cleanHistory[] = ['role' => $role, 'text' => mb_substr($text, 0, 2000)];
}

// --- Build role-scoped context (only the user's own data) -----------------
$database = new Database();
$db = $database->getConnection();

$user_id   = $_SESSION['user_id'];
$role      = $_SESSION['role'] ?? 'student';
$roleLabel = function_exists('formatRoleName') ? formatRoleName($role) : ucfirst($role);

$academic = $database->getCurrentAcademicContext();
$academicStr = ($academic['year_name'] ?? '') . ', ' . ($academic['term_name'] ?? '');

$facts = [];

/**
 * Each fact query is wrapped so a missing table/column never breaks the chat —
 * the assistant simply has less context to work with.
 */
$safe = function (callable $fn) {
    try { return $fn(); } catch (Throwable $e) { return null; }
};

if (in_array($role, ['super_admin', 'school_admin', 'principal'], true)) {
    $safe(function () use ($db, &$facts) {
        $row = $db->query("SELECT
            (SELECT COUNT(*) FROM users WHERE role='student' AND status='active') AS students,
            (SELECT COUNT(*) FROM users WHERE role='teacher' AND status='active') AS teachers,
            (SELECT COUNT(*) FROM classes WHERE status='active') AS classes")->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $facts['Active students'] = $row['students'];
            $facts['Active teachers'] = $row['teachers'];
            $facts['Active classes']  = $row['classes'];
        }
    });
} elseif ($role === 'teacher') {
    $safe(function () use ($db, $user_id, &$facts) {
        $stmt = $db->prepare("SELECT
            (SELECT COUNT(DISTINCT class_id) FROM class_teachers WHERE teacher_id = :u) AS classes,
            (SELECT COUNT(DISTINCT sc.student_id) FROM student_classes sc
              WHERE sc.status='active' AND sc.class_id IN
                (SELECT class_id FROM class_teachers WHERE teacher_id = :u)) AS students,
            (SELECT COUNT(*) FROM assignments WHERE teacher_id = :u AND status='active') AS assignments");
        $stmt->execute([':u' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $facts['My classes']     = $row['classes'];
            $facts['My students']    = $row['students'];
            $facts['My assignments'] = $row['assignments'];
        }
    });
} elseif ($role === 'student') {
    $safe(function () use ($db, $user_id, &$facts) {
        $stmt = $db->prepare("SELECT
            (SELECT COUNT(*) FROM student_classes WHERE student_id = :u) AS classes,
            (SELECT COUNT(*) FROM assignments a JOIN student_classes sc ON a.class_id = sc.class_id
              WHERE sc.student_id = :u AND a.status='active') AS pending,
            (SELECT COUNT(*) FROM attendance WHERE student_id = :u AND status='present'
              AND MONTH(date)=MONTH(NOW())) AS present_month,
            (SELECT COUNT(*) FROM book_loans WHERE borrower_id = :u AND status='borrowed') AS borrowed");
        $stmt->execute([':u' => $user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $facts['My classes']           = $row['classes'];
            $facts['Pending assignments']  = $row['pending'];
            $facts['Attendance this month']= $row['present_month'] . ' days present';
            $facts['Borrowed books']       = $row['borrowed'];
        }
    });
} elseif ($role === 'parent') {
    $safe(function () use ($db, $user_id, &$facts) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM parent_students WHERE parent_id = :u");
        $stmt->execute([':u' => $user_id]);
        $facts['Children linked'] = (int)$stmt->fetchColumn();
    });
}

// Live, role-checked data tools (lists, counts, own fee balance, etc.). Returns
// only what this user is permitted to see; empty for questions that need none.
$liveData = [];
require_once __DIR__ . '/../includes/nadics_tools.php';
try {
    $liveData = nadicsGatherLiveData($db, $message, $role, $user_id);
} catch (Throwable $e) {
    error_log("Nadics tools error: " . $e->getMessage());
}

$context = [
    'role_label'  => $roleLabel,
    'user_name'   => $_SESSION['user_name'] ?? 'there',
    'school_name' => function_exists('getSchoolSetting') ? getSchoolSetting('school_name', 'the school') : 'the school',
    'academic'    => $academicStr,
    'facts'       => $facts,
    'live_data'   => $liveData,
];

// --- Generate reply -------------------------------------------------------
try {
    $result = nadicsAIChat($message, $cleanHistory, $context);
} catch (Throwable $e) {
    error_log("Nadics AI fatal: " . $e->getMessage());
    $result = [
        'success' => false,
        'reply'   => "Sorry, I'm having trouble responding right now. Please try again in a moment.",
        'source'  => 'error',
    ];
}

// --- Log the interaction for quality review (best-effort) -----------------
try {
    ensureNadicsAiTable($db);
    $log = $db->prepare("INSERT INTO nadics_ai_logs
        (user_id, user_role, user_message, ai_reply, source, success, created_at)
        VALUES (:uid, :role, :msg, :reply, :src, :ok, NOW())");
    $log->execute([
        ':uid'   => $user_id,
        ':role'  => $role,
        ':msg'   => $message,
        ':reply' => $result['reply'] ?? '',
        ':src'   => $result['source'] ?? null,
        ':ok'    => !empty($result['success']) ? 1 : 0,
    ]);
} catch (Throwable $e) {
    error_log("Nadics AI log failed: " . $e->getMessage());
}

echo json_encode([
    'success' => !empty($result['success']),
    'reply'   => $result['reply'] ?? "Sorry, I couldn't generate a response.",
    'source'  => $result['source'] ?? 'unknown',
]);
