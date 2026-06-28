<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';
require_once '../includes/payment_functions.php';

$database = new Database();
$db = $database->getConnection();

$invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
$amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
$method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
$ref = filter_input(INPUT_POST, 'reference_number', FILTER_SANITIZE_STRING) ?: '';
$notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING) ?: '';

if ($invoice_id && $amount && $method) {
    $res = recordPayment($invoice_id, $amount, $method, $ref, $notes, $db);
    echo json_encode($res);
} else {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
}
