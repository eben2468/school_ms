<?php
header('Content-Type: application/json');
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    echo json_encode([]);
    exit();
}

require_once '../../config/database.php';

$database = new Database();
$db = $database->getConnection();

$query = filter_input(INPUT_GET, 'query', FILTER_SANITIZE_STRING) ?: '';

if (strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

try {
    // Find students who have pending, partially paid, or overdue invoices matching search
    $stmt = $db->prepare("SELECT i.id, i.invoice_number, i.total_amount, i.discount_amount, i.penalty_amount, i.amount_paid,
                                 u.name, sp.student_id, c.name as class_name,
                                 (i.total_amount + i.penalty_amount - i.discount_amount) as grand_total,
                                 (i.total_amount + i.penalty_amount - i.discount_amount - i.amount_paid) as outstanding_balance
                          FROM finance_invoices i
                          JOIN users u ON i.student_id = u.id
                          JOIN student_profiles sp ON u.id = sp.user_id
                          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                          LEFT JOIN classes c ON sc.class_id = c.id
                          WHERE i.status IN ('pending', 'partially_paid', 'overdue', 'paid')
                            AND (u.name LIKE :q OR sp.student_id LIKE :q OR i.invoice_number LIKE :q)
                          ORDER BY u.name ASC LIMIT 10");
    $stmt->execute([':q' => '%' . $query . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($results);
} catch (PDOException $e) {
    error_log("AJAX search student error: " . $e->getMessage());
    echo json_encode([]);
}
