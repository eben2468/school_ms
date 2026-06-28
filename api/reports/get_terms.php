<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$year_id = filter_input(INPUT_GET, 'year_id', FILTER_SANITIZE_NUMBER_INT);

if (!$year_id) {
    echo json_encode(['success' => false, 'message' => 'Academic Year ID is required']);
    exit();
}

try {
    $sql = "SELECT id, term_name, term_number, start_date, end_date, status 
            FROM academic_terms 
            WHERE academic_year_id = :year_id 
            ORDER BY term_number ASC";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':year_id', $year_id);
    $stmt->execute();
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'terms' => $terms]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
