<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../../index.php");
    exit();
}

$type = $_GET['report_type'] ?? 'pnl';
$start = $_GET['start_date'] ?? '';
$end = $_GET['end_date'] ?? '';

header("Location: ../reports.php?export=csv&report_type=" . urlencode($type) . "&start_date=" . urlencode($start) . "&end_date=" . urlencode($end));
exit();
