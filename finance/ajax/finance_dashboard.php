<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

try {
    $total_revenue = (float)$db->query("SELECT SUM(amount) FROM finance_payments")->fetchColumn();
    $outstanding = (float)$db->query("SELECT SUM(total_amount + penalty_amount - discount_amount - amount_paid) FROM finance_invoices WHERE status != 'cancelled'")->fetchColumn();
    
    echo json_encode([
        'total_revenue' => $total_revenue,
        'outstanding_fees' => $outstanding
    ]);
} catch (PDOException $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
