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

$class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$year_id = filter_input(INPUT_GET, 'year_id', FILTER_SANITIZE_NUMBER_INT);
$term_id = filter_input(INPUT_GET, 'term_id', FILTER_SANITIZE_NUMBER_INT);

if (!$class_id || !$year_id || !$term_id) {
    echo json_encode(['success' => false, 'message' => 'Class, Academic Year, and Term are required parameters.']);
    exit();
}

try {
    // We want to list all students enrolled in the class.
    // And for each student, fetch:
    // - Name and ID
    // - Average score and subjects count from student_academic_records
    // - Report generation status (has_report, report_id) from term_reports
    $sql = "SELECT 
                u.id, 
                u.name, 
                COALESCE(sp.student_id, u.student_id) as student_id,
                AVG(sar.total_score) as average_score,
                COUNT(sar.id) as subjects_count,
                tr.id as report_id,
                CASE WHEN tr.id IS NOT NULL THEN 1 ELSE 0 END as has_report
            FROM student_classes sc
            JOIN users u ON sc.student_id = u.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN student_academic_records sar ON u.id = sar.student_id 
                AND sar.academic_class_id_placeholder_or_real = sc.class_id -- Let's be careful about column names in sar
                AND sar.academic_year_id = :year_id 
                AND sar.academic_term_id = :term_id
            LEFT JOIN term_reports tr ON u.id = tr.student_id 
                AND tr.academic_year_id = :year_id 
                AND tr.academic_term_id = :term_id
                AND tr.class_id = :class_id
            WHERE sc.class_id = :class_id 
              AND sc.status = 'active'
              AND u.status = 'active'
              AND u.role = 'student'
            GROUP BY u.id, u.name, sp.student_id, u.student_id, tr.id
            ORDER BY u.name ASC";
            
    // Wait, let's verify what the class column is inside student_academic_records. 
    // In our schema check output, it listed:
    // class_id - int(11)
    // So the column in student_academic_records is indeed `class_id`! Let's correct that: sar.class_id = sc.class_id.
    
    $sql = "SELECT 
                u.id, 
                u.name, 
                COALESCE(sp.student_id, u.student_id) as student_id,
                AVG(sar.total_score) as average_score,
                COUNT(sar.id) as subjects_count,
                tr.id as report_id,
                CASE WHEN tr.id IS NOT NULL THEN 1 ELSE 0 END as has_report
            FROM student_classes sc
            JOIN users u ON sc.student_id = u.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            LEFT JOIN student_academic_records sar ON u.id = sar.student_id 
                AND sar.class_id = sc.class_id
                AND sar.academic_year_id = :year_id 
                AND sar.academic_term_id = :term_id
            LEFT JOIN term_reports tr ON u.id = tr.student_id 
                AND tr.academic_year_id = :year_id 
                AND tr.academic_term_id = :term_id
                AND tr.class_id = :class_id
            WHERE sc.class_id = :class_id 
              AND sc.status = 'active'
              AND u.status = 'active'
              AND u.role = 'student'
            GROUP BY u.id, u.name, sp.student_id, u.student_id, tr.id
            ORDER BY u.name ASC";

    $stmt = $db->prepare($sql);
    $stmt->bindParam(':class_id', $class_id);
    $stmt->bindParam(':year_id', $year_id);
    $stmt->bindParam(':term_id', $term_id);
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format fields (convert numeric values from strings if necessary)
    foreach ($students as &$student) {
        $student['average_score'] = $student['average_score'] !== null ? round((float)$student['average_score'], 2) : null;
        $student['subjects_count'] = (int)$student['subjects_count'];
        $student['has_report'] = (bool)$student['has_report'];
        $student['report_id'] = $student['report_id'] !== null ? (int)$student['report_id'] : null;
    }

    echo json_encode(['success' => true, 'students' => $students]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
