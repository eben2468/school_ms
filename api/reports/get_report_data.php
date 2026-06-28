<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];

// Get parameters
$report_id = filter_input(INPUT_GET, 'report_id', FILTER_SANITIZE_NUMBER_INT);
$student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
$year_id = filter_input(INPUT_GET, 'year_id', FILTER_SANITIZE_NUMBER_INT);
$term_id = filter_input(INPUT_GET, 'term_id', FILTER_SANITIZE_NUMBER_INT);

try {
    // 1. Fetch main report metadata
    if ($report_id) {
        $stmt = $db->prepare("SELECT * FROM term_reports WHERE id = :report_id");
        $stmt->execute([':report_id' => $report_id]);
    } else if ($student_id && $year_id && $term_id) {
        $stmt = $db->prepare("
            SELECT * FROM term_reports 
            WHERE student_id = :student_id 
              AND academic_year_id = :year_id 
              AND academic_term_id = :term_id
        ");
        $stmt->execute([
            ':student_id' => $student_id,
            ':year_id' => $year_id,
            ':term_id' => $term_id
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Missing report_id or (student_id, year_id, term_id)']);
        exit();
    }

    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) {
        echo json_encode(['success' => false, 'message' => 'Report not found']);
        exit();
    }

    $target_student_id = (int)$report['student_id'];
    $class_id = (int)$report['class_id'];
    $year_id = (int)$report['academic_year_id'];
    $term_id = (int)$report['academic_term_id'];

    // 2. Validate Access Control
    $has_access = false;
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        $has_access = true;
    } else if ($role === 'teacher') {
        // Verify if teacher teaches this class OR is the class teacher
        $teacher_check = $db->prepare("
            SELECT id FROM class_teachers 
            WHERE teacher_id = :teacher_id AND class_id = :class_id
        ");
        $teacher_check->execute([':teacher_id' => $user_id, ':class_id' => $class_id]);
        if ($teacher_check->rowCount() > 0) {
            $has_access = true;
        }
    } else if ($role === 'student') {
        if ($user_id === $target_student_id) {
            $has_access = true;
        }
    } else if ($role === 'parent') {
        // Verify parent-student relationship
        $parent_check = $db->prepare("
            SELECT id FROM parent_students 
            WHERE parent_id = :parent_id AND student_id = :student_id
        ");
        $parent_check->execute([':parent_id' => $user_id, ':student_id' => $target_student_id]);
        if ($parent_check->rowCount() > 0) {
            $has_access = true;
        }
    }

    if (!$has_access) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }

    // 3. Fetch Student details
    $student_stmt = $db->prepare("
        SELECT 
            u.name, 
            u.email,
            sp.student_id as student_code,
            sp.date_of_birth,
            sp.gender,
            sp.address,
            sp.phone,
            sp.guardian_name,
            sp.guardian_phone,
            sp.guardian_email,
            c.name as class_name
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        JOIN classes c ON c.id = :class_id
        WHERE u.id = :student_id
    ");
    $student_stmt->execute([':student_id' => $target_student_id, ':class_id' => $class_id]);
    $student = $student_stmt->fetch(PDO::FETCH_ASSOC);

    // 4. Fetch Academic details
    $term_stmt = $db->prepare("
        SELECT t.term_name, t.term_number, y.year_name 
        FROM academic_terms t
        JOIN academic_years y ON t.academic_year_id = y.id
        WHERE t.id = :term_id
    ");
    $term_stmt->execute([':term_id' => $term_id]);
    $term_info = $term_stmt->fetch(PDO::FETCH_ASSOC);

    // 5. Fetch Student Academic Records (subjects, CA, exams, grades)
    $records_stmt = $db->prepare("
        SELECT 
            sar.continuous_assessment,
            sar.exam_score,
            sar.total_score,
            sar.grade,
            sar.remarks,
            sub.name as subject_name,
            sub.code as subject_code,
            tu.name as teacher_name
        FROM student_academic_records sar
        JOIN subjects sub ON sar.subject_id = sub.id
        LEFT JOIN users tu ON sar.teacher_id = tu.id
        WHERE sar.student_id = :student_id
          AND sar.class_id = :class_id
          AND sar.academic_year_id = :year_id
          AND sar.academic_term_id = :term_id
        ORDER BY sub.name ASC
    ");
    $records_stmt->execute([
        ':student_id' => $target_student_id,
        ':class_id' => $class_id,
        ':year_id' => $year_id,
        ':term_id' => $term_id
    ]);
    $records = $records_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Fetch active Grading Scales for reference key
    $scales_stmt = $db->query("
        SELECT min_score, max_score, grade, interpretation 
        FROM grading_scales 
        WHERE is_active = 1 
        ORDER BY min_score DESC
    ");
    $grading_scales = $scales_stmt->fetchAll(PDO::FETCH_ASSOC);

    // 7. Get School settings
    $settings_stmt = $db->query("SELECT setting_key, setting_value FROM academic_settings");
    $settings_rows = $settings_stmt->fetchAll(PDO::FETCH_ASSOC);
    $school_settings = [];
    foreach ($settings_rows as $row) {
        $school_settings[$row['setting_key']] = $row['setting_value'];
    }

    // Default values if settings are not set
    $school_info = [
        'name' => $school_settings['school_name'] ?? 'Antigravity Academy',
        'address' => $school_settings['school_address'] ?? '123 Education Drive, Accra',
        'phone' => $school_settings['school_phone'] ?? '+233 24 123 4567',
        'email' => $school_settings['school_email'] ?? 'info@antigravityacademy.edu',
        'motto' => $school_settings['school_motto'] ?? 'Excellence in Character and Knowledge',
        'postal' => $school_settings['school_postal'] ?? 'P.O. Box GP 1234, Accra',
        'logo' => $school_settings['school_logo'] ?? ''
    ];

    // Response packet
    echo json_encode([
        'success' => true,
        'report' => $report,
        'student' => $student,
        'term_info' => $term_info,
        'records' => $records,
        'grading_scales' => $grading_scales,
        'school_info' => $school_info
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
