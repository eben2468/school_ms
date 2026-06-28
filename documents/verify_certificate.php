<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $code = trim($_GET['code'] ?? '');
    
    if (empty($code)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Verification code is required']);
        exit();
    }
    
    $query = "
        SELECT gc.*, s.name as student_name, s.student_id as student_number, ib.name as issued_by_name
        FROM generated_certificates gc
        LEFT JOIN users s ON gc.student_id = s.id
        LEFT JOIN users ib ON gc.issued_by = ib.id
        WHERE gc.verification_code = :code OR gc.certificate_number = :code
        LIMIT 1
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':code', $code);
    $stmt->execute();
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cert) {
        echo json_encode([
            'success' => true,
            'certificate_number' => $cert['certificate_number'],
            'title' => $cert['title'],
            'description' => $cert['description'],
            'student_name' => $cert['student_name'],
            'student_number' => $cert['student_number'] ?: 'N/A',
            'issue_date' => date('F j, Y', strtotime($cert['issue_date'])),
            'issued_by' => $cert['issued_by_name'],
            'status' => ucfirst($cert['status'])
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No certificate found with the provided code.'
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
