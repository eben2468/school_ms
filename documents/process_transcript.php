<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $request_id = $input['request_id'] ?? null;
    $action = $input['action'] ?? '';
    
    if (!$request_id || $action !== 'process') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid request params']);
        exit();
    }
    
    // Fetch request details
    $req_query = "
        SELECT tr.*, u.name as student_name, u.email as student_email, u.student_id as student_number
        FROM transcript_requests tr
        JOIN users u ON tr.student_id = u.id
        WHERE tr.id = :request_id
    ";
    $req_stmt = $db->prepare($req_query);
    $req_stmt->bindParam(':request_id', $request_id);
    $req_stmt->execute();
    $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Transcript request not found']);
        exit();
    }
    
    $student_id = $request['student_id'];
    $student_name = $request['student_name'];
    $student_number = $request['student_number'] ?? 'N/A';
    
    // Get student profile details
    $profile_query = "SELECT * FROM student_profiles WHERE user_id = :student_id LIMIT 1";
    $profile_stmt = $db->prepare($profile_query);
    $profile_stmt->bindParam(':student_id', $student_id);
    $profile_stmt->execute();
    $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
    
    $admission_date = ($profile && $profile['admission_date']) ? date('M j, Y', strtotime($profile['admission_date'])) : 'N/A';
    $dob = ($profile && $profile['date_of_birth']) ? date('M j, Y', strtotime($profile['date_of_birth'])) : 'N/A';
    $gender = ($profile && $profile['gender']) ? ucfirst($profile['gender']) : 'N/A';
    
    // Get student active class
    $class_query = "
        SELECT c.name as class_name, c.academic_year 
        FROM classes c 
        JOIN student_classes sc ON c.id = sc.class_id 
        WHERE sc.student_id = :student_id AND sc.status = 'active' 
        LIMIT 1
    ";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->bindParam(':student_id', $student_id);
    $class_stmt->execute();
    $class_info = $class_stmt->fetch(PDO::FETCH_ASSOC);
    $class_name = $class_info ? $class_info['class_name'] : 'N/A';
    $academic_year = $class_info ? $class_info['academic_year'] : date('Y') . '-' . (date('Y') + 1);
    
    // Get exam results.
    // Only include subjects that actually belong to a class the student is (or was)
    // enrolled in. Some exam_results reference subjects taught in other classes;
    // without this filter those foreign subjects wrongly appear on the transcript.
    $results_query = "
        SELECT er.marks_obtained, er.grade, er.remarks,
               e.name as exam_name, e.exam_type, e.total_marks, e.academic_year as exam_year, e.academic_term as exam_term,
               s.name as subject_name, s.code as subject_code
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.id
        JOIN subjects s ON e.subject_id = s.id
        WHERE er.student_id = :student_id
          AND s.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :student_id)
        ORDER BY e.academic_year DESC, e.academic_term ASC, s.name ASC
    ";
    $results_stmt = $db->prepare($results_query);
    $results_stmt->bindParam(':student_id', $student_id);
    $results_stmt->execute();
    $results = $results_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate performance stats
    $total_subjects = 0;
    $total_marks_obtained = 0;
    $total_max_marks = 0;
    $unique_subjects = [];
    
    foreach ($results as $res) {
        $total_marks_obtained += $res['marks_obtained'];
        $total_max_marks += $res['total_marks'];
        if (!in_array($res['subject_name'], $unique_subjects)) {
            $unique_subjects[] = $res['subject_name'];
        }
    }
    
    $total_subjects = count($unique_subjects);
    $overall_percentage = $total_max_marks > 0 ? round(($total_marks_obtained / $total_max_marks) * 100, 2) : 0;
    $average_score = count($results) > 0 ? round($total_marks_obtained / count($results), 2) : 0;

    // Fetch school settings
    $school_name = getSchoolSetting('school_name', 'Greenwood Academy');
    $school_address = getSchoolSetting('school_address', '');
    $school_phone = getSchoolSetting('school_phone', '');
    $school_email = getSchoolSetting('school_email', '');
    $school_website = getSchoolSetting('school_website', '');
    $school_motto = 'Excellence in Character and Knowledge';
    $school_postal = '';

    // Fetch school motto and postal from academic settings
    try {
        $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto' LIMIT 1");
        $motto_stmt->execute();
        $motto_result = $motto_stmt->fetch(PDO::FETCH_ASSOC);
        if ($motto_result && !empty($motto_result['setting_value'])) {
            $school_motto = $motto_result['setting_value'];
        }
        
        $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal' LIMIT 1");
        $postal_stmt->execute();
        $postal_result = $postal_stmt->fetch(PDO::FETCH_ASSOC);
        if ($postal_result && !empty($postal_result['setting_value'])) {
            $school_postal = $postal_result['setting_value'];
        }
    } catch (PDOException $e) {
        // Fallback
    }

    $logo_url = getSchoolLogo();

    // Get grading scale from DB
    $grading_scale = [];
    try {
        $gs_stmt = $db->query("SELECT * FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC");
        $grading_scale = $gs_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Fallback grading scale
        $grading_scale = [
            ['grade' => 'A1', 'min_score' => 80, 'max_score' => 100, 'interpretation' => 'Excellent'],
            ['grade' => 'B2', 'min_score' => 70, 'max_score' => 79.99, 'interpretation' => 'Very Good'],
            ['grade' => 'B3', 'min_score' => 65, 'max_score' => 69.99, 'interpretation' => 'Good'],
            ['grade' => 'C4', 'min_score' => 60, 'max_score' => 64.99, 'interpretation' => 'Credit'],
            ['grade' => 'C5', 'min_score' => 55, 'max_score' => 59.99, 'interpretation' => 'Credit'],
            ['grade' => 'C6', 'min_score' => 50, 'max_score' => 54.99, 'interpretation' => 'Credit'],
            ['grade' => 'D7', 'min_score' => 45, 'max_score' => 49.99, 'interpretation' => 'Pass'],
            ['grade' => 'E8', 'min_score' => 40, 'max_score' => 44.99, 'interpretation' => 'Pass'],
            ['grade' => 'F9', 'min_score' => 0, 'max_score' => 39.99, 'interpretation' => 'Fail'],
        ];
    }

    if (!function_exists('getGradeFromScore')) {
        function getGradeFromScore($score, $grading_scale) {
            foreach ($grading_scale as $gs) {
                if ($score >= $gs['min_score'] && $score <= $gs['max_score']) {
                    return $gs;
                }
            }
            return ['grade' => 'F9', 'interpretation' => 'Fail'];
        }
    }

    // Compile rows
    $table_rows_html = '';
    if (empty($results)) {
        $table_rows_html = '
            <tr>
                <td colspan="6" style="text-align: center; color: #6b7280; padding: 20px;">No academic exam records found for this student.</td>
            </tr>';
    } else {
        foreach ($results as $res) {
            $pct = $res['total_marks'] > 0 ? ($res['marks_obtained'] / $res['total_marks']) * 100 : 0;
            $grade_info = getGradeFromScore($pct, $grading_scale);
            
            $grade_class = 'grade-fail';
            if ($pct >= 80) $grade_class = 'grade-excellent';
            elseif ($pct >= 70) $grade_class = 'grade-very-good';
            elseif ($pct >= 65) $grade_class = 'grade-good';
            elseif ($pct >= 50) $grade_class = 'grade-credit';
            elseif ($pct >= 40) $grade_class = 'grade-pass';
            
            $res_year = htmlspecialchars($res['exam_year']);
            $res_term = ucfirst(htmlspecialchars($res['exam_term']));
            $res_subject = htmlspecialchars($res['subject_name']) . ' (' . htmlspecialchars($res['subject_code']) . ')';
            $res_exam = htmlspecialchars($res['exam_name']);
            $res_score = number_format($res['marks_obtained'], 1) . ' / ' . htmlspecialchars($res['total_marks']);
            // Display the grade in the school's configured grading style.
            $res_grade = htmlspecialchars(formatGrade($pct));
            
            $table_rows_html .= "
                <tr>
                    <td>{$res_year}</td>
                    <td>{$res_term}</td>
                    <td><strong>{$res_subject}</strong></td>
                    <td>{$res_exam}</td>
                    <td style=\"font-weight: 600;\">{$res_score}</td>
                    <td><span class=\"grade-badge {$grade_class}\">{$res_grade}</span></td>
                </tr>";
        }
    }

    // Overall grade calculation
    $overall_grade_info = getGradeFromScore($average_score, $grading_scale);
    $overall_grade_class = 'grade-fail';
    if ($average_score >= 80) $overall_grade_class = 'grade-excellent';
    elseif ($average_score >= 70) $overall_grade_class = 'grade-very-good';
    elseif ($average_score >= 65) $overall_grade_class = 'grade-good';
    elseif ($average_score >= 50) $overall_grade_class = 'grade-credit';
    elseif ($average_score >= 40) $overall_grade_class = 'grade-pass';
    $overall_grade_badge = '<span class="grade-badge ' . $overall_grade_class . '">' . htmlspecialchars(formatGrade($average_score)) . '</span>';

    // Logo HTML with Base64 encoding for robustness
    $logo_html = '';
    $logo_name = getSchoolSetting('school_logo', '');
    if ($logo_name) {
        $logo_file_path = __DIR__ . '/../uploads/logos/' . $logo_name;
        if (file_exists($logo_file_path)) {
            $logo_data = base64_encode(file_get_contents($logo_file_path));
            $logo_type = pathinfo($logo_file_path, PATHINFO_EXTENSION);
            if ($logo_type === 'svg') {
                $logo_type = 'svg+xml';
            }
            $logo_base64 = 'data:image/' . $logo_type . ';base64,' . $logo_data;
            $logo_html = '<div class="school-logo"><img src="' . $logo_base64 . '" alt="School Logo"></div>';
        }
    }
    
    if (empty($logo_html)) {
        $initial = strtoupper(substr($school_name, 0, 1));
        $logo_html = '<div class="school-logo-placeholder">' . $initial . '</div>';
    }

    // Grading Key table
    $grading_key_html = '';
    if (!empty($grading_scale)) {
        $grading_key_html .= '
        <div class="grading-key">
            <div class="grading-key-title">Grading Key / Interpretation Scheme</div>
            <table class="grading-key-table">
                <thead>
                    <tr>
                        <th>Grade</th>';
        foreach ($grading_scale as $gs) {
            $grading_key_html .= '<td><strong>' . htmlspecialchars($gs['grade']) . '</strong></td>';
        }
        $grading_key_html .= '
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th>Range</th>';
        foreach ($grading_scale as $gs) {
            $grading_key_html .= '<td>' . intval($gs['min_score']) . '-' . intval($gs['max_score']) . '%</td>';
        }
        $grading_key_html .= '
                    </tr>
                    <tr>
                        <th>Interpretation</th>';
        foreach ($grading_scale as $gs) {
            $grading_key_html .= '<td>' . htmlspecialchars($gs['interpretation']) . '</td>';
        }
        $grading_key_html .= '
                    </tr>
                </tbody>
            </table>
        </div>';
    }

    // School details footer block
    $school_contact_details = '';
    if ($school_postal) { $school_contact_details .= htmlspecialchars($school_postal) . ' | '; }
    $school_contact_details .= htmlspecialchars($school_address);
    if ($school_phone) { $school_contact_details .= ' | Tel: ' . htmlspecialchars($school_phone); }
    if ($school_email) { $school_contact_details .= ' | ' . htmlspecialchars($school_email); }
    if ($school_website) { $school_contact_details .= ' | ' . htmlspecialchars($school_website); }

    $print_date = date('F j, Y');

    // Institutional signatures (embedded when enabled in Settings).
    require_once '../includes/signature_helper.php';
    $registrar_sig  = getSchoolSignature('registrar');
    $headmaster_sig = getSchoolSignature('headmaster');
    $registrar_img  = signatureImg($registrar_sig['url']);
    $headmaster_img = signatureImg($headmaster_sig['url']);
    $registrar_name  = $registrar_sig['name'] ? htmlspecialchars($registrar_sig['name']) : 'Academic Registrar';
    $headmaster_name = $headmaster_sig['name'] ? htmlspecialchars($headmaster_sig['name']) : 'School Headmaster/Headmistress';

    // Generate HTML via HEREDOC
    $transcript_html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Transcript - {$student_name}</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            font-size: 11px;
            line-height: 1.4;
            color: #1a1a1a;
            background: #f0f0f0;
            padding-top: 60px; /* Offset for action bar */
        }
        
        .transcript-card {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 12mm 15mm;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
        }
        
        /* School Header */
        .school-header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 3px double #1e3a5f;
            margin-bottom: 15px;
        }
        
        .school-logo {
            width: 60px;
            height: 60px;
            margin: 0 auto 6px;
        }
        
        .school-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }
        
        .school-logo-placeholder {
            width: 60px;
            height: 60px;
            margin: 0 auto 6px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            font-weight: 800;
        }
        
        .school-name {
            font-size: 22px;
            font-weight: 800;
            color: #1e3a5f;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 2px;
        }
        
        .school-details {
            font-size: 10px;
            color: #555;
            line-height: 1.5;
        }
        
        .school-motto {
            font-style: italic;
            color: #2d5a8e;
            font-size: 11px;
            margin-top: 3px;
            font-weight: 500;
        }
        
        .report-title {
            text-align: center;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 8px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 15px 0;
            border-radius: 4px;
        }
        
        /* Student Info Grid */
        .student-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #d0d0d0;
            margin-bottom: 15px;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .info-row {
            display: flex;
            border-bottom: 1px solid #e5e5e5;
            font-size: 10.5px;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 600;
            color: #333;
            padding: 6px 10px;
            background: #f5f7fa;
            min-width: 130px;
            border-right: 1px solid #e5e5e5;
        }
        
        .info-value {
            padding: 6px 10px;
            flex: 1;
            color: #1a1a1a;
            font-weight: 500;
        }
        
        .info-col-divider {
            border-right: 1px solid #d0d0d0;
        }
        
        /* Performance Table */
        .section-title {
            font-size: 12px;
            font-weight: 700;
            color: #1e3a5f;
            padding: 5px 10px;
            background: #eef2f7;
            border-left: 4px solid #1e3a5f;
            margin-bottom: 8px;
            border-radius: 0 4px 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10.5px;
        }
        
        .performance-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 8px 10px;
            text-align: center;
            font-weight: 600;
            font-size: 9.5px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #1a3455;
        }
        
        .performance-table thead th:first-child {
            text-align: left;
        }
        
        .performance-table tbody td {
            padding: 6px 10px;
            text-align: center;
            border: 1px solid #ddd;
        }
        
        .performance-table tbody td:first-child {
            text-align: left;
            font-weight: 500;
        }
        
        .performance-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }
        
        .performance-table tbody tr:hover {
            background: #eef2f7;
        }
        
        .performance-table tfoot td {
            padding: 8px 10px;
            font-weight: 700;
            background: #f0f4f8;
            border: 1px solid #ccc;
            text-align: center;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 10px;
        }
        
        .grade-excellent { background: #d1fae5; color: #065f46; }
        .grade-very-good { background: #dbeafe; color: #1e40af; }
        .grade-good { background: #e0e7ff; color: #3730a3; }
        .grade-credit { background: #fef3c7; color: #92400e; }
        .grade-pass { background: #fed7aa; color: #9a3412; }
        .grade-fail { background: #fecaca; color: #991b1b; }
        
        /* Summary Grid */
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 20px;
        }
        
        .summary-card {
            text-align: center;
            padding: 10px;
            border: 1px solid #d0d0d0;
            border-radius: 6px;
            background: #f8fafc;
        }
        
        .summary-value {
            font-size: 18px;
            font-weight: 800;
            color: #1e3a5f;
        }
        
        .summary-label {
            font-size: 9px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 4px;
        }
        
        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-top: 30px;
            margin-bottom: 20px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 8px;
            padding-top: 6px;
            font-size: 9.5px;
            font-weight: 600;
            color: #333;
        }
        .sig-img { height: 40px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 40px; max-width: 150px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        
        .signature-sub {
            font-size: 8.5px;
            color: #777;
            margin-top: 2px;
        }
        
        /* Grading Key */
        .grading-key {
            margin-top: 20px;
            padding-top: 10px;
            border-top: 1px solid #e0e0e0;
        }
        
        .grading-key-title {
            font-size: 9.5px;
            font-weight: 700;
            color: #1e3a5f;
            text-transform: uppercase;
            margin-bottom: 6px;
            letter-spacing: 0.5px;
        }
        
        .grading-key-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        
        .grading-key-table th,
        .grading-key-table td {
            padding: 3px 6px;
            border: 1px solid #e0e0e0;
            text-align: center;
        }
        
        .grading-key-table th {
            background: #f0f4f8;
            font-weight: 600;
            color: #1e3a5f;
        }
        
        .watermark {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-45deg);
            font-size: 70px;
            font-weight: 900;
            color: rgba(30, 58, 95, 0.03);
            pointer-events: none;
            z-index: 0;
            text-transform: uppercase;
            letter-spacing: 6px;
            white-space: nowrap;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0 !important;
            }
            
            .no-print {
                display: none !important;
            }
            
            .transcript-card {
                width: 100%;
                margin: 0;
                padding: 10mm 12mm;
                box-shadow: none;
                min-height: auto;
            }
            
            @page {
                size: A4 portrait;
                margin: 8mm;
            }
        }
    </style>
</head>
<body>
    <!-- Floating print action bar -->
    <div id="transcriptActionBar" class="no-print" style="position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #1F2937; display: flex; align-items: center; justify-content: space-between; padding: 0 30px; box-shadow: 0 4px 6px rgba(0,0,0,0.15); z-index: 9999; font-family: 'Inter', sans-serif; box-sizing: border-box;">
        <div style="color: white; font-weight: 600; font-size: 14px; display: flex; align-items: center; gap: 8px;">
            <svg width="18" height="18" fill="#D4AF37" viewBox="0 0 20 20" style="color: #D4AF37;"><path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.939.831a1 1 0 00.788 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762zM9.3 16.573A9.026 9.026 0 007 14.935v-3.957l1.818.78a3 3 0 002.364 0l5.508-2.361a11.026 11.026 0 01.25 3.762 1 1 0 01-.89.89 8.968 8.968 0 00-5.35 2.999 1 1 0 01-1.4 0z"/></svg>
            <span>Official Academic Transcript - {$student_name}</span>
        </div>
        <div style="display: flex; gap: 12px; align-items: center;">
            <button onclick="window.print()" style="background: #3B82F6; color: white; border: none; padding: 8px 18px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; display: flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transition: background 0.15s ease;" onmouseover="this.style.background='#2563EB'" onmouseout="this.style.background='#3B82F6'">
                <svg width="14" height="14" fill="currentColor" viewBox="0 0 24 24"><path d="M19 8H5c-1.66 0-3 1.34-3 3v6h4v4h12v-4h4v-6c0-1.66-1.34-3-3-3zm-3 11H8v-5h8v5zm3-7c-.55 0-1-.45-1-1s.45-1 1-1 1 .45 1 1-.45 1-1 1zm-1-9H6v4h12V3z"/></svg>
                Print / Save as PDF
            </button>
            <button onclick="closeTranscriptView()" style="background: #4B5563; color: white; border: none; padding: 8px 14px; border-radius: 6px; font-weight: 600; font-size: 13px; cursor: pointer; transition: background 0.15s ease;" onmouseover="this.style.background='#374151'" onmouseout="this.style.background='#4B5563'">
                Close
            </button>
        </div>
    </div>

    <div class="transcript-card">
        <!-- Background Watermark -->
        <div class="watermark">OFFICIAL TRANSCRIPT</div>
        
        <!-- School Header -->
        <div class="school-header">
            {$logo_html}
            <div class="school-name">{$school_name}</div>
            <div class="school-motto">"{$school_motto}"</div>
            <div class="school-details">{$school_contact_details}</div>
        </div>
        
        <!-- Transcript Banner Title -->
        <div class="report-title">Official Academic Transcript</div>
        
        <!-- Student Information Grid -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Student Name</span>
                    <span class="info-value">{$student_name}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID</span>
                    <span class="info-value">{$student_number}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date of Birth</span>
                    <span class="info-value">{$dob}</span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Gender</span>
                    <span class="info-value">{$gender}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Admission Date</span>
                    <span class="info-value">{$admission_date}</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Current Class</span>
                    <span class="info-value">{$class_name}</span>
                </div>
            </div>
        </div>
        
        <!-- Academic Performance Table -->
        <div class="section-title">Academic Examination Records</div>
        <table class="performance-table">
            <thead>
                <tr>
                    <th style="width: 12%;">Year</th>
                    <th style="width: 12%;">Term</th>
                    <th style="width: 30%;">Subject</th>
                    <th style="width: 18%;">Exam</th>
                    <th style="width: 14%;">Score</th>
                    <th style="width: 14%;">Grade</th>
                </tr>
            </thead>
            <tbody>
                {$table_rows_html}
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align: left; font-weight: bold;">OVERALL AVERAGE / STANDING</td>
                    <td style="font-weight: bold;">{$average_score}%</td>
                    <td>{$overall_grade_badge}</td>
                </tr>
            </tfoot>
        </table>
        
        <!-- Summary Cards Grid -->
        <div class="section-title">Summary Statistics</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-value">{$total_subjects}</div>
                <div class="summary-label">Subjects Taken</div>
            </div>
            <div class="summary-card">
                <div class="summary-value">{$total_marks_obtained} / {$total_max_marks}</div>
                <div class="summary-label">Total Marks</div>
            </div>
            <div class="summary-card">
                <div class="summary-value">{$average_score}%</div>
                <div class="summary-label">Overall Average</div>
            </div>
            <div class="summary-card">
                <div class="summary-value">Good Standing</div>
                <div class="summary-label">Academic Standing</div>
            </div>
        </div>
        
        <!-- Signatures Box -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img">{$registrar_img}</div>
                <div class="signature-line">{$registrar_name}</div>
                <div class="signature-sub">Office of Admissions & Records</div>
            </div>
            <div class="signature-box">
                <div class="sig-img">{$headmaster_img}</div>
                <div class="signature-line">{$headmaster_name}</div>
                <div class="signature-sub">{$school_name}</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"></div>
                <div class="signature-line">Date of Issue</div>
                <div class="signature-sub">{$print_date}</div>
            </div>
        </div>
        
        <!-- Grading Key -->
        {$grading_key_html}
    </div>
    <script>
        function closeTranscriptView() {
            // If this transcript was opened as its own window/tab, close it.
            try { window.close(); } catch (e) {}
            // When embedded in the preview iframe (or any tab where close() is
            // blocked), hide the floating action bar so the toolbar closes.
            var bar = document.getElementById('transcriptActionBar');
            if (bar) { bar.style.display = 'none'; }
        }
    </script>
</body>
</html>
HTML;

    
    // Save generated HTML file
    $file_name = 'transcript_req_' . $request_id . '_' . time() . '.html';
    $file_path = '../uploads/transcripts/' . $file_name;
    $db_file_path = 'uploads/transcripts/' . $file_name;
    
    if (!is_dir('../uploads/transcripts/')) {
        mkdir('../uploads/transcripts/', 0755, true);
    }
    
    if (file_put_contents($file_path, $transcript_html)) {
        // Update request status
        $update_query = "
            UPDATE transcript_requests
            SET status = 'ready',
                generated_file_path = :file_path,
                processed_by = :processed_by,
                processed_at = NOW()
            WHERE id = :request_id
        ";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':file_path', $db_file_path);
        $update_stmt->bindParam(':processed_by', $_SESSION['user_id']);
        $update_stmt->bindParam(':request_id', $request_id);
        
        if ($update_stmt->execute()) {
            // Notify the student
            $notif_query = "
                INSERT INTO notifications (user_id, title, message, type, is_read, created_at)
                VALUES (:user_id, :title, :message, 'success', 0, NOW())
            ";
            $notif_stmt = $db->prepare($notif_query);
            $notif_stmt->bindParam(':user_id', $student_id);
            $notif_title = "Transcript Request Ready";
            $notif_msg = "Your requested transcript is ready. You can download it now.";
            $notif_stmt->bindParam(':title', $notif_title);
            $notif_stmt->bindParam(':message', $notif_msg);
            $notif_stmt->execute();
            
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update request in database']);
            unlink($file_path);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to write generated file to disk']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
