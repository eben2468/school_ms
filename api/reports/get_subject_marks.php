<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$class_id   = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$year_id    = filter_input(INPUT_GET, 'year_id', FILTER_SANITIZE_NUMBER_INT);
$term_id    = filter_input(INPUT_GET, 'term_id', FILTER_SANITIZE_NUMBER_INT);
$subject_id = filter_input(INPUT_GET, 'subject_id', FILTER_SANITIZE_NUMBER_INT);

if (!$class_id || !$year_id || !$term_id || !$subject_id) {
    echo json_encode(['success' => false, 'message' => 'Class, Year, Term, and Subject are required.']);
    exit();
}

try {
    $sql = "SELECT student_id, continuous_assessment, exam_score, total_score, grade, remarks
            FROM student_academic_records
            WHERE class_id = :class_id
              AND academic_year_id = :year_id
              AND academic_term_id = :term_id
              AND subject_id = :subject_id";

    $stmt = $db->prepare($sql);
    $stmt->execute([
        ':class_id'   => $class_id,
        ':year_id'    => $year_id,
        ':term_id'    => $term_id,
        ':subject_id' => $subject_id
    ]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'records' => $records]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
