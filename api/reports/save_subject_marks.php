<?php
session_start();
header('Content-Type: application/json');

// Access control
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);

$academic_year_id = isset($input['academic_year_id']) ? (int)$input['academic_year_id'] : 0;
$academic_term_id = isset($input['academic_term_id']) ? (int)$input['academic_term_id'] : 0;
$class_id         = isset($input['class_id']) ? (int)$input['class_id'] : 0;
$subject_id       = isset($input['subject_id']) ? (int)$input['subject_id'] : 0;
$records          = isset($input['records']) ? $input['records'] : [];

if (!$academic_year_id || !$academic_term_id || !$class_id || !$subject_id || empty($records)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (Year, Term, Class, Subject, or Records).']);
    exit();
}

$user_id   = $_SESSION['user_id'];
$user_role = $_SESSION['role'];

try {
    // If role is teacher, verify they are assigned to this class + subject
    if ($user_role === 'teacher') {
        $perm_stmt = $db->prepare("
            SELECT id FROM class_teachers
            WHERE teacher_id = :teacher_id AND class_id = :class_id AND subject_id = :subject_id
        ");
        $perm_stmt->execute([
            ':teacher_id' => $user_id,
            ':class_id'   => $class_id,
            ':subject_id' => $subject_id
        ]);
        if (!$perm_stmt->fetch()) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'You are not assigned to teach this subject in this class.']);
            exit();
        }
    }

    // Load active grading scales
    $scale_stmt = $db->query("SELECT min_score, max_score, grade, interpretation FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC");
    $grading_scales = $scale_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Helper: map total score to a grade
    function mapScoreToGrade($score, $scales) {
        foreach ($scales as $scale) {
            if ($score >= $scale['min_score'] && $score <= $scale['max_score']) {
                return ['grade' => $scale['grade'], 'interpretation' => $scale['interpretation'] ?? ''];
            }
        }
        return ['grade' => 'F9', 'interpretation' => 'Fail'];
    }

    // Begin transaction
    $db->beginTransaction();

    $saved   = 0;
    $errors  = [];

    foreach ($records as $rec) {
        $student_id = isset($rec['student_id']) ? (int)$rec['student_id'] : 0;
        $ca         = isset($rec['continuous_assessment']) ? floatval($rec['continuous_assessment']) : 0;
        $exam       = isset($rec['exam_score']) ? floatval($rec['exam_score']) : 0;
        $remarks    = isset($rec['remarks']) ? trim($rec['remarks']) : '';

        if (!$student_id) {
            $errors[] = 'Skipped record with missing student_id.';
            continue;
        }

        // Validate ranges
        if ($ca < 0 || $ca > 100) {
            $errors[] = "Student ID $student_id: CA score must be between 0 and 100.";
            continue;
        }
        if ($exam < 0 || $exam > 100) {
            $errors[] = "Student ID $student_id: Exam score must be between 0 and 100.";
            continue;
        }

        $total_score = $ca + $exam;
        if ($total_score > 100) {
            $total_score = 100; // cap
        }

        $grade_info = mapScoreToGrade($total_score, $grading_scales);
        $grade      = $grade_info['grade'];
        $remarks    = $grade_info['interpretation'];

        // Check for existing record (upsert) - make this class-agnostic to prevent duplicate subject rows
        $check_stmt = $db->prepare("
            SELECT id FROM student_academic_records
            WHERE student_id = :student_id
              AND academic_year_id = :year_id
              AND academic_term_id = :term_id
              AND subject_id = :subject_id
        ");
        $check_stmt->execute([
            ':student_id' => $student_id,
            ':year_id'    => $academic_year_id,
            ':term_id'    => $academic_term_id,
            ':subject_id' => $subject_id
        ]);
        $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update - always ensure class_id is updated to current selection
            $update_stmt = $db->prepare("
                UPDATE student_academic_records SET
                    class_id = :class_id,
                    continuous_assessment = :ca,
                    exam_score = :exam,
                    total_score = :total,
                    grade = :grade,
                    remarks = :remarks,
                    teacher_id = :teacher_id,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $update_stmt->execute([
                ':class_id'   => $class_id,
                ':ca'         => $ca,
                ':exam'       => $exam,
                ':total'      => $total_score,
                ':grade'      => $grade,
                ':remarks'    => $remarks,
                ':teacher_id' => $user_id,
                ':id'         => $existing['id']
            ]);
        } else {
            // Insert
            $insert_stmt = $db->prepare("
                INSERT INTO student_academic_records
                    (student_id, academic_year_id, academic_term_id, class_id, subject_id,
                     continuous_assessment, exam_score, total_score, grade, remarks, teacher_id)
                VALUES
                    (:student_id, :year_id, :term_id, :class_id, :subject_id,
                     :ca, :exam, :total, :grade, :remarks, :teacher_id)
            ");
            $insert_stmt->execute([
                ':student_id' => $student_id,
                ':year_id'    => $academic_year_id,
                ':term_id'    => $academic_term_id,
                ':class_id'   => $class_id,
                ':subject_id' => $subject_id,
                ':ca'         => $ca,
                ':exam'       => $exam,
                ':total'      => $total_score,
                ':grade'      => $grade,
                ':remarks'    => $remarks,
                ':teacher_id' => $user_id
            ]);
        }
        $saved++;
    }

    $db->commit();

    echo json_encode([
        'success' => true,
        'saved'   => $saved,
        'errors'  => $errors,
        'message' => "$saved record(s) saved successfully." . (!empty($errors) ? ' Some records had issues.' : '')
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
