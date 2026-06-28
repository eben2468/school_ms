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

// Get POST input
$input = json_decode(file_get_contents('php://input'), true);

$year_id = isset($input['academic_year_id']) ? (int)$input['academic_year_id'] : 0;
$term_id = isset($input['academic_term_id']) ? (int)$input['academic_term_id'] : 0;
$class_id = isset($input['class_id']) ? (int)$input['class_id'] : 0;
$student_ids = isset($input['student_ids']) ? $input['student_ids'] : [];
$regenerate = isset($input['regenerate']) ? (bool)$input['regenerate'] : false;

if (!$year_id || !$term_id || !$class_id || empty($student_ids)) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields (Year, Term, Class, or Students)']);
    exit();
}

try {
    // 1. Get term date boundaries & current term details
    $term_stmt = $db->prepare("SELECT term_number, start_date, end_date FROM academic_terms WHERE id = :term_id");
    $term_stmt->execute([':term_id' => $term_id]);
    $current_term = $term_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$current_term) {
        echo json_encode(['success' => false, 'message' => 'Invalid academic term ID']);
        exit();
    }

    $term_num = $current_term['term_number'];
    $start_date = $current_term['start_date'];
    $end_date = $current_term['end_date'];

    // 2. Determine Next Term Begins Date
    $next_term_stmt = $db->prepare("
        SELECT start_date FROM academic_terms 
        WHERE academic_year_id = :year_id AND term_number > :term_num 
        ORDER BY term_number ASC LIMIT 1
    ");
    $next_term_stmt->execute([':year_id' => $year_id, ':term_num' => $term_num]);
    $next_term = $next_term_stmt->fetch(PDO::FETCH_ASSOC);
    $next_term_begins = $next_term ? $next_term['start_date'] : null;

    // 3. Fetch all students' academic records to calculate ranks/positions and class average
    $rank_stmt = $db->prepare("
        SELECT student_id, AVG(total_score) as avg_score, SUM(total_score) as sum_score, COUNT(id) as subjects_count
        FROM student_academic_records
        WHERE class_id = :class_id AND academic_year_id = :year_id AND academic_term_id = :term_id
        GROUP BY student_id
        ORDER BY avg_score DESC
    ");
    $rank_stmt->execute([':class_id' => $class_id, ':year_id' => $year_id, ':term_id' => $term_id]);
    $all_averages = $rank_stmt->fetchAll(PDO::FETCH_ASSOC);

    $positions = [];
    $class_size = count($all_averages);
    $class_avg_sum = 0;
    
    $current_rank = 1;
    $prev_score = null;
    foreach ($all_averages as $index => $row) {
        if ($prev_score !== null && $row['avg_score'] < $prev_score) {
            $current_rank = $index + 1;
        }
        $positions[$row['student_id']] = [
            'rank' => $current_rank,
            'avg' => (float)$row['avg_score'],
            'sum' => (float)$row['sum_score'],
            'subjects' => (int)$row['subjects_count']
        ];
        $class_avg_sum += (float)$row['avg_score'];
        $prev_score = $row['avg_score'];
    }

    $class_average = $class_size > 0 ? ($class_avg_sum / $class_size) : 0;

    // 4. Load grading scales
    $scale_stmt = $db->query("SELECT min_score, max_score, grade FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC");
    $grading_scales = $scale_stmt->fetchAll(PDO::FETCH_ASSOC);

    function getGradeFromScore($score, $scales) {
        foreach ($scales as $scale) {
            if ($score >= $scale['min_score'] && $score <= $scale['max_score']) {
                return $scale['grade'];
            }
        }
        return 'F9'; // Default
    }

    // Helper for automatic remarks
    function getAutoRemarks($avg) {
        if ($avg >= 80) return "An excellent performance. Keep up the brilliant work!";
        if ($avg >= 70) return "Very good performance. Shows high potential and capability.";
        if ($avg >= 60) return "A good performance. With more effort, higher grades are within reach.";
        if ($avg >= 50) return "An average performance. Needs to show more dedication to studies.";
        if ($avg >= 40) return "Passable results, but has room for significant improvement. Focus more next term.";
        return "Failed to meet minimum requirements. Urgent improvement and extra classes recommended.";
    }

    // Helper for automatic principal remarks
    function getPrincipalAutoRemarks($avg) {
        if ($avg >= 80) return "Outstanding! A standard-bearer for academic excellence.";
        if ($avg >= 70) return "Impressive work. Highly commendable attitude and results.";
        if ($avg >= 60) return "Good progress. Capable of even higher achievements.";
        if ($avg >= 50) return "Fair performance, but with potential for much more.";
        if ($avg >= 40) return "Satisfactory progress. Willingness to apply yourself will bring better grades.";
        return "Disappointing results. A firm determination to work harder is required.";
    }

    $generated = 0;
    $skipped = 0;
    $errors = [];

    // Loop through the requested student IDs to generate report
    foreach ($student_ids as $student_id) {
        $student_id = (int)$student_id;

        // Skip if this student doesn't have academic records in this class/term/year
        if (!isset($positions[$student_id])) {
            $skipped++;
            $errors[] = "Student ID $student_id has no academic records for this term.";
            continue;
        }

        // Check if report already exists - ignore class_id to prevent duplicates in index/print if class changes
        $check_stmt = $db->prepare("
            SELECT id FROM term_reports 
            WHERE student_id = :student_id 
              AND academic_year_id = :year_id 
              AND academic_term_id = :term_id
        ");
        $check_stmt->execute([
            ':student_id' => $student_id,
            ':year_id' => $year_id,
            ':term_id' => $term_id
        ]);
        $existing_report = $check_stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing_report && !$regenerate) {
            $skipped++;
            continue;
        }

        // Student's stats
        $student_stats = $positions[$student_id];
        $avg_score = $student_stats['avg'];
        $sum_score = $student_stats['sum'];
        $subjects_count = $student_stats['subjects'];
        $rank = $student_stats['rank'];

        // Get Attendance details
        $att_stmt = $db->prepare("
            SELECT 
                COUNT(*) as total_days,
                SUM(CASE WHEN status IN ('present', 'late') THEN 1 ELSE 0 END) as present_days
            FROM attendance
            WHERE student_id = :student_id
              AND (
                (academic_term_id = :term_id) OR 
                (date BETWEEN :start_date AND :end_date)
              )
        ");
        $att_stmt->execute([
            ':student_id' => $student_id,
            ':term_id' => $term_id,
            ':start_date' => $start_date,
            ':end_date' => $end_date
        ]);
        $att_row = $att_stmt->fetch(PDO::FETCH_ASSOC);
        $attendance_days = (int)($att_row['total_days'] ?? 0);
        $attendance_present = (int)($att_row['present_days'] ?? 0);

        // Get Conduct details
        $cond_stmt = $db->prepare("
            SELECT conduct_grade, attitude, interest, remarks 
            FROM conduct_records 
            WHERE student_id = :student_id 
              AND academic_year_id = :year_id 
              AND academic_term_id = :term_id
        ");
        $cond_stmt->execute([
            ':student_id' => $student_id,
            ':year_id' => $year_id,
            ':term_id' => $term_id
        ]);
        $cond_row = $cond_stmt->fetch(PDO::FETCH_ASSOC);
        
        $conduct_grade = $cond_row ? $cond_row['conduct_grade'] : 'B';
        $attitude = $cond_row ? $cond_row['attitude'] : 'Good';
        $interest = $cond_row ? $cond_row['interest'] : 'Improving';
        $conduct_remarks = $cond_row ? $cond_row['remarks'] : '';

        // Calculate grades
        $overall_grade = getGradeFromScore($avg_score, $grading_scales);
        $teacher_remarks = getAutoRemarks($avg_score);
        if (!empty($conduct_remarks)) {
            $teacher_remarks .= " " . $conduct_remarks;
        }
        $principal_remarks = getPrincipalAutoRemarks($avg_score);

        // Auto promoted status if third term
        $promoted = null;
        if ($term_num == '3') {
            $promoted = $avg_score >= 45 ? 1 : 0;
        }

        $total_marks_possible = $subjects_count * 100.00;

        if ($existing_report) {
            // Update existing report - set class_id = :class_id to ensure class updates correctly
            $update_sql = "
                UPDATE term_reports SET
                    class_id = :class_id,
                    total_subjects = :total_subjects,
                    total_score = :total_score,
                    average_score = :average_score,
                    position_in_class = :position_in_class,
                    class_size = :class_size,
                    attendance_days = :attendance_days,
                    attendance_present = :attendance_present,
                    conduct_grade = :conduct_grade,
                    teacher_remarks = :teacher_remarks,
                    principal_remarks = :principal_remarks,
                    next_term_begins = :next_term_begins,
                    report_generated_at = CURRENT_TIMESTAMP,
                    generated_by = :generated_by,
                    overall_grade = :overall_grade,
                    total_marks_obtained = :total_marks_obtained,
                    total_marks_possible = :total_marks_possible,
                    promoted = :promoted,
                    class_average = :class_average,
                    interest = :interest,
                    attitude = :attitude
                WHERE id = :report_id
            ";
            $save_stmt = $db->prepare($update_sql);
            $save_stmt->execute([
                ':class_id' => $class_id,
                ':total_subjects' => $subjects_count,
                ':total_score' => $avg_score,
                ':average_score' => $avg_score,
                ':position_in_class' => $rank,
                ':class_size' => $class_size,
                ':attendance_days' => $attendance_days,
                ':attendance_present' => $attendance_present,
                ':conduct_grade' => $conduct_grade,
                ':teacher_remarks' => $teacher_remarks,
                ':principal_remarks' => $principal_remarks,
                ':next_term_begins' => $next_term_begins,
                ':generated_by' => $_SESSION['user_id'],
                ':overall_grade' => $overall_grade,
                ':total_marks_obtained' => $sum_score,
                ':total_marks_possible' => $total_marks_possible,
                ':promoted' => $promoted,
                ':class_average' => $class_average,
                ':interest' => $interest,
                ':attitude' => $attitude,
                ':report_id' => $existing_report['id']
            ]);
        } else {
            // Insert new report
            $insert_sql = "
                INSERT INTO term_reports (
                    student_id, academic_year_id, academic_term_id, class_id,
                    total_subjects, total_score, average_score, position_in_class, class_size,
                    attendance_days, attendance_present, conduct_grade, teacher_remarks, principal_remarks,
                    next_term_begins, generated_by, overall_grade, total_marks_obtained,
                    total_marks_possible, promoted, class_average, interest, attitude
                ) VALUES (
                    :student_id, :academic_year_id, :academic_term_id, :class_id,
                    :total_subjects, :total_score, :average_score, :position_in_class, :class_size,
                    :attendance_days, :attendance_present, :conduct_grade, :teacher_remarks, :principal_remarks,
                    :next_term_begins, :generated_by, :overall_grade, :total_marks_obtained,
                    :total_marks_possible, :promoted, :class_average, :interest, :attitude
                )
            ";
            $save_stmt = $db->prepare($insert_sql);
            $save_stmt->execute([
                ':student_id' => $student_id,
                ':academic_year_id' => $year_id,
                ':academic_term_id' => $term_id,
                ':class_id' => $class_id,
                ':total_subjects' => $subjects_count,
                ':total_score' => $avg_score,
                ':average_score' => $avg_score,
                ':position_in_class' => $rank,
                ':class_size' => $class_size,
                ':attendance_days' => $attendance_days,
                ':attendance_present' => $attendance_present,
                ':conduct_grade' => $conduct_grade,
                ':teacher_remarks' => $teacher_remarks,
                ':principal_remarks' => $principal_remarks,
                ':next_term_begins' => $next_term_begins,
                ':generated_by' => $_SESSION['user_id'],
                ':overall_grade' => $overall_grade,
                ':total_marks_obtained' => $sum_score,
                ':total_marks_possible' => $total_marks_possible,
                ':promoted' => $promoted,
                ':class_average' => $class_average,
                ':interest' => $interest,
                ':attitude' => $attitude
            ]);
        }
        $generated++;
    }

    echo json_encode([
        'success' => true,
        'generated' => $generated,
        'skipped' => $skipped,
        'errors' => $errors,
        'total' => count($student_ids)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
