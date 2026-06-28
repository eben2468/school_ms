<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    http_response_code(403);
    exit('Access denied');
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get filter parameters
$class_filter = $_GET['class'] ?? '';
$subject_filter = $_GET['subject'] ?? '';
$term_filter = $_GET['term'] ?? '';
$year_filter = $_GET['year'] ?? '';
$student_filter = $_GET['student'] ?? '';
$format = $_GET['format'] ?? 'csv';

try {
    // Get current academic year
    $current_year_query = "SELECT * FROM academic_years WHERE status = 'active' LIMIT 1";
    $current_year = $db->query($current_year_query)->fetch(PDO::FETCH_ASSOC);
    
    // Default to current academic year if not explicitly set in URL query parameters
    if (!isset($_GET['year']) && $current_year) {
        $year_filter = $current_year['id'];
    }

    // Automatically detect student's active class to enforce it
    if ($user_role === 'student') {
        $class_stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $class_stmt->execute([':student_id' => $user_id]);
        $detected_class_id = $class_stmt->fetchColumn();
        if ($detected_class_id) {
            $class_filter = $detected_class_id;
        }
    }

    // Validate that the selected term belongs to the selected/active year to prevent invalid state
    $selected_year_id = $year_filter ?: ($current_year['id'] ?? null);
    if ($term_filter && $selected_year_id) {
        $term_check_stmt = $db->prepare("SELECT COUNT(*) FROM academic_terms WHERE id = :term_id AND academic_year_id = :year_id");
        $term_check_stmt->execute([':term_id' => $term_filter, ':year_id' => $selected_year_id]);
        if ($term_check_stmt->fetchColumn() == 0) {
            $term_filter = '';
        }
    }

    // Build WHERE conditions for grades query
    $where_conditions = [];
    $params = [];

    if ($class_filter) {
        $where_conditions[] = "sar.class_id = :class_id";
        $params[':class_id'] = $class_filter;
    }

    if ($subject_filter) {
        $where_conditions[] = "sar.subject_id = :subject_id";
        $params[':subject_id'] = $subject_filter;
    }

    if ($term_filter) {
        $where_conditions[] = "sar.academic_term_id = :term_id";
        $params[':term_id'] = $term_filter;
    }

    if ($year_filter) {
        $where_conditions[] = "sar.academic_year_id = :year_id";
        $params[':year_id'] = $year_filter;
    } else if (!isset($_GET['year']) && $current_year) {
        $where_conditions[] = "sar.academic_year_id = :current_year_id";
        $params[':current_year_id'] = $current_year['id'];
    }

    if ($student_filter && $user_role !== 'student') {
        $where_conditions[] = "(u.name LIKE :student_name OR sp.student_id LIKE :student_id_search)";
        $params[':student_name'] = "%$student_filter%";
        $params[':student_id_search'] = "%$student_filter%";
    }

    // Role-based filtering
    if ($user_role === 'teacher') {
        $where_conditions[] = "sar.teacher_id = :teacher_id";
        $params[':teacher_id'] = $user_id;
    } elseif ($user_role === 'student') {
        $where_conditions[] = "sar.student_id = :current_student_id";
        $params[':current_student_id'] = $user_id;
    }

    // Get grades/academic records
    $grades_sql = "SELECT 
        sar.*,
        u.name as student_name,
        sp.student_id as student_number,
        s.name as subject_name, s.code as subject_code,
        c.name as class_name, c.grade_level,
        ay.year_name,
        at.term_name,
        teacher.name as teacher_name
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN subjects s ON sar.subject_id = s.id
    JOIN classes c ON sar.class_id = c.id
    JOIN academic_years ay ON sar.academic_year_id = ay.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id";

    if (!empty($where_conditions)) {
        $grades_sql .= " WHERE " . implode(' AND ', $where_conditions);
    }

    $grades_sql .= " ORDER BY u.name, s.name, at.term_number";

    $stmt = $db->prepare($grades_sql);
    $stmt->execute($params);
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    http_response_code(500);
    exit("Database error: " . $e->getMessage());
}

// Grade letters are derived from the school's configured grading system via
// formatGrade()/getGradeLetter() in settings_helper.php.
require_once '../../includes/settings_helper.php';

$timestamp = date('Y-m-d_H-i-s');

if ($format === 'excel') {
    $filename = "grades_export_{$timestamp}.xls";
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');

    echo '<table border="1">';
    echo '<tr style="background-color: #f2f2f2; font-weight: bold;">';
    echo '<td>Student Name</td>';
    echo '<td>Student ID</td>';
    echo '<td>Class</td>';
    echo '<td>Subject</td>';
    echo '<td>Academic Year</td>';
    echo '<td>Term</td>';
    echo '<td>Continuous Assessment</td>';
    echo '<td>Exam Score</td>';
    echo '<td>Total Score</td>';
    echo '<td>Grade</td>';
    echo '<td>Teacher</td>';
    echo '</tr>';

    foreach ($grades as $grade) {
        $grade_letter = formatGrade($grade['total_score']);
        echo '<tr>';
        echo '<td>' . htmlspecialchars($grade['student_name']) . '</td>';
        echo '<td>' . htmlspecialchars($grade['student_number']) . '</td>';
        echo '<td>' . htmlspecialchars($grade['class_name'] . ' (' . $grade['grade_level'] . ')') . '</td>';
        echo '<td>' . htmlspecialchars($grade['subject_name'] . ' [' . $grade['subject_code'] . ']') . '</td>';
        echo '<td>' . htmlspecialchars($grade['year_name']) . '</td>';
        echo '<td>' . htmlspecialchars($grade['term_name']) . '</td>';
        echo '<td>' . number_format($grade['continuous_assessment'] ?? 0, 1) . '</td>';
        echo '<td>' . number_format($grade['exam_score'] ?? 0, 1) . '</td>';
        echo '<td>' . number_format($grade['total_score'] ?? 0, 1) . '%</td>';
        echo '<td>' . $grade_letter . '</td>';
        echo '<td>' . htmlspecialchars($grade['teacher_name'] ?? 'N/A') . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    exit();
} else {
    // Default to CSV
    $filename = "grades_export_{$timestamp}.csv";
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fputcsv($output, [
        'Student Name',
        'Student ID',
        'Class',
        'Subject',
        'Academic Year',
        'Term',
        'Continuous Assessment',
        'Exam Score',
        'Total Score',
        'Grade',
        'Teacher'
    ]);

    foreach ($grades as $grade) {
        $grade_letter = formatGrade($grade['total_score']);
        fputcsv($output, [
            $grade['student_name'],
            $grade['student_number'],
            $grade['class_name'] . ' (' . $grade['grade_level'] . ')',
            $grade['subject_name'] . ' [' . $grade['subject_code'] . ']',
            $grade['year_name'],
            $grade['term_name'],
            number_format($grade['continuous_assessment'] ?? 0, 1),
            number_format($grade['exam_score'] ?? 0, 1),
            number_format($grade['total_score'] ?? 0, 1) . '%',
            $grade_letter,
            $grade['teacher_name'] ?? 'N/A'
        ]);
    }
    fclose($output);
    exit();
}
?>
