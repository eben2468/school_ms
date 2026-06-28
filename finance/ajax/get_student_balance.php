<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';
require_once '../includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
$academic_year_id = filter_input(INPUT_GET, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT) ?: null;

if (!$student_id) {
    echo json_encode(['balance' => 0.00, 'error' => 'Invalid student ID']);
    exit();
}

$balance = getStudentBalance($student_id, $academic_year_id, $db);
echo json_encode(['balance' => $balance]);
