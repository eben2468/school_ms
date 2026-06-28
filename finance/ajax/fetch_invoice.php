<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'student', 'parent'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';
require_once '../includes/invoice_functions.php';

$database = new Database();
$db = $database->getConnection();

$invoice_id = filter_input(INPUT_GET, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);

if (!$invoice_id) {
    echo json_encode(['error' => 'Invalid invoice ID']);
    exit();
}

$invoice = getInvoiceDetails($invoice_id, $db);

if (!$invoice) {
    echo json_encode(['error' => 'Invoice not found']);
    exit();
}

// Enforce role-based view permissions
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if ($user_role === 'student' && $invoice['student_id'] != $user_id) {
    echo json_encode(['error' => 'Access denied']);
    exit();
} elseif ($user_role === 'parent') {
    $check = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE user_id = :student_id AND parent_id = :parent_id");
    $check->execute([':student_id' => $invoice['student_id'], ':parent_id' => $user_id]);
    if ($check->fetchColumn() == 0) {
        echo json_encode(['error' => 'Access denied']);
        exit();
    }
}

echo json_encode($invoice);
