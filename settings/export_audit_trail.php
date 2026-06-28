<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'super_admin') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Pull the platform-wide audit trail with friendly school / user labels
try {
    $rows = $db->query("
        SELECT al.id, al.created_at, al.action,
               COALESCE(s.name, '—') AS school_name,
               COALESCE(u.name, '—') AS user_name,
               al.details, al.ip_address
        FROM audit_logs al
        LEFT JOIN schools s ON al.school_id = s.id
        LEFT JOIN users u ON al.user_id = u.id
        ORDER BY al.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $rows = [];
}

$filename = 'platform_audit_trail_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel renders symbols correctly
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['ID', 'Date', 'Action', 'School', 'User', 'Details', 'IP Address']);

foreach ($rows as $r) {
    fputcsv($out, [
        $r['id'],
        $r['created_at'],
        $r['action'],
        $r['school_name'],
        $r['user_name'],
        $r['details'],
        $r['ip_address'],
    ]);
}

fclose($out);
exit();
