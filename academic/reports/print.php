<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
require_once '../../includes/signature_helper.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get report IDs (single or multiple)
$report_ids = [];
if (isset($_GET['id'])) {
    $report_ids = [intval($_GET['id'])];
} elseif (isset($_GET['ids'])) {
    $report_ids = array_map('intval', explode(',', $_GET['ids']));
} elseif (isset($_GET['class_id']) && isset($_GET['year_id']) && isset($_GET['term_id'])) {
    // Bulk print all reports for a class/term
    $bulk_sql = "SELECT id FROM term_reports WHERE class_id = :class_id AND academic_year_id = :year_id AND academic_term_id = :term_id ORDER BY position_in_class";
    $stmt = $db->prepare($bulk_sql);
    $stmt->bindParam(':class_id', $_GET['class_id']);
    $stmt->bindParam(':year_id', $_GET['year_id']);
    $stmt->bindParam(':term_id', $_GET['term_id']);
    $stmt->execute();
    $report_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($report_ids)) {
    echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">No reports found to print.</p>';
    exit();
}

// Get school settings
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$school_motto = '';
$school_postal = '';

// Try to get motto and postal from academic_settings
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    $motto_result = $motto_stmt->fetch(PDO::FETCH_ASSOC);
    if ($motto_result) $school_motto = $motto_result['setting_value'];
    
    $postal_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
    $postal_stmt->execute();
    $postal_result = $postal_stmt->fetch(PDO::FETCH_ASSOC);
    if ($postal_result) $school_postal = $postal_result['setting_value'];
} catch (PDOException $e) {
    // Settings not available yet
}

$logo_url = getSchoolLogo();

// Institutional signer (same on every card); class-teacher signature is per-report.
$headmaster_sig = getSchoolSignature('headmaster');

// Get grading scale
$grading_scale = [];
try {
    $gs_stmt = $db->query("SELECT * FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC");
    $grading_scale = $gs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Use default grading
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

// Helper function to get grade from score using grading scale
function getGradeFromScore($score, $grading_scale) {
    foreach ($grading_scale as $gs) {
        if ($score >= $gs['min_score'] && $score <= $gs['max_score']) {
            return $gs;
        }
    }
    return ['grade' => 'F9', 'interpretation' => 'Fail'];
}

// Helper to get ordinal suffix
function getOrdinal($number) {
    $suffix = ['th','st','nd','rd','th','th','th','th','th','th'];
    if ((($number % 100) >= 11) && (($number % 100) <= 13)) {
        return $number . 'th';
    }
    return $number . $suffix[$number % 10];
}

// Helpers for automatic remarks
if (!function_exists('getAutoRemarks')) {
    function getAutoRemarks($avg) {
        if ($avg >= 80) return "An excellent performance. Keep up the brilliant work!";
        if ($avg >= 70) return "Very good performance. Shows high potential and capability.";
        if ($avg >= 60) return "A good performance. With more effort, higher grades are within reach.";
        if ($avg >= 50) return "An average performance. Needs to show more dedication to studies.";
        if ($avg >= 40) return "Passable results, but has room for significant improvement. Focus more next term.";
        return "Failed to meet minimum requirements. Urgent improvement and extra classes recommended.";
    }
}

if (!function_exists('getPrincipalAutoRemarks')) {
    function getPrincipalAutoRemarks($avg) {
        if ($avg >= 80) return "Outstanding! A standard-bearer for academic excellence.";
        if ($avg >= 70) return "Impressive work. Highly commendable attitude and results.";
        if ($avg >= 60) return "Good progress. Capable of even higher achievements.";
        if ($avg >= 50) return "Fair performance, but with potential for much more.";
        if ($avg >= 40) return "Satisfactory progress. Willingness to apply yourself will bring better grades.";
        return "Disappointing results. A firm determination to work harder is required.";
    }
}

// Fetch all reports data
$reports_data = [];
foreach ($report_ids as $rid) {
    // Get report details
    $report_sql = "SELECT 
        tr.*,
        u.name as student_name,
        sp.student_id as profile_student_id,
        sp.date_of_birth, sp.gender, sp.guardian_name, sp.guardian_phone,
        c.name as class_name, c.grade_level, c.main_teacher_id,
        ay.year_name,
        at2.term_name, at2.start_date as term_start, at2.end_date as term_end
    FROM term_reports tr
    JOIN users u ON tr.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    JOIN classes c ON tr.class_id = c.id
    JOIN academic_years ay ON tr.academic_year_id = ay.id
    JOIN academic_terms at2 ON tr.academic_term_id = at2.id
    WHERE tr.id = :report_id";
    
    // Access control
    if ($user_role === 'student') {
        $report_sql .= " AND tr.student_id = :user_id";
    } elseif ($user_role === 'parent') {
        $report_sql .= " AND tr.student_id IN (SELECT student_id FROM parent_students WHERE parent_id = :user_id)";
    }
    
    $stmt = $db->prepare($report_sql);
    $stmt->bindParam(':report_id', $rid);
    if (in_array($user_role, ['student', 'parent'])) {
        $stmt->bindParam(':user_id', $user_id);
    }
    $stmt->execute();
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) continue;
    
    // Get academic records
    $records_sql = "SELECT 
        sar.*,
        s.name as subject_name, s.code as subject_code,
        teacher.name as teacher_name
    FROM student_academic_records sar
    JOIN subjects s ON sar.subject_id = s.id
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id
    WHERE sar.student_id = :student_id 
    AND sar.academic_year_id = :year_id 
    AND sar.academic_term_id = :term_id
    ORDER BY s.name";
    
    $stmt = $db->prepare($records_sql);
    $stmt->bindParam(':student_id', $report['student_id']);
    $stmt->bindParam(':year_id', $report['academic_year_id']);
    $stmt->bindParam(':term_id', $report['academic_term_id']);
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get attendance data using date range
    $attendance = ['total_days' => $report['attendance_days'] ?? 0, 'present' => $report['attendance_present'] ?? 0];
    try {
        $att_sql = "SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late
        FROM attendance 
        WHERE student_id = :student_id 
        AND date BETWEEN :start_date AND :end_date";
        $att_stmt = $db->prepare($att_sql);
        $att_stmt->bindParam(':student_id', $report['student_id']);
        $att_stmt->bindParam(':start_date', $report['term_start']);
        $att_stmt->bindParam(':end_date', $report['term_end']);
        $att_stmt->execute();
        $att_data = $att_stmt->fetch(PDO::FETCH_ASSOC);
        if ($att_data && $att_data['total_days'] > 0) {
            $attendance = $att_data;
        }
    } catch (PDOException $e) {
        // Use stored attendance data
    }
    
    // Get conduct record
    $conduct = ['conduct_grade' => $report['conduct_grade'] ?? 'B', 'attitude' => 'Good', 'interest' => 'Improving'];
    try {
        $cond_sql = "SELECT * FROM conduct_records WHERE student_id = :student_id AND academic_year_id = :year_id AND academic_term_id = :term_id";
        $cond_stmt = $db->prepare($cond_sql);
        $cond_stmt->bindParam(':student_id', $report['student_id']);
        $cond_stmt->bindParam(':year_id', $report['academic_year_id']);
        $cond_stmt->bindParam(':term_id', $report['academic_term_id']);
        $cond_stmt->execute();
        $cond_data = $cond_stmt->fetch(PDO::FETCH_ASSOC);
        if ($cond_data) {
            $conduct = $cond_data;
        }
    } catch (PDOException $e) {
        // Use defaults
    }
    
    $reports_data[] = [
        'report' => $report,
        'records' => $records,
        'attendance' => $attendance,
        'conduct' => $conduct
    ];
}

if (empty($reports_data)) {
    echo '<p style="text-align:center;padding:40px;font-family:Inter,sans-serif;">No authorized reports found.</p>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Term Report Card - <?php echo htmlspecialchars($school_name); ?></title>
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
        }
        
        .report-card {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            padding: 12mm 15mm;
            background: white;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
        }
        
        .no-print-controls {
            text-align: center;
            padding: 15px;
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            position: sticky;
            top: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
        }
        
        .no-print-controls button {
            padding: 8px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .btn-print {
            background: #10b981;
            color: white;
        }
        
        .btn-print:hover {
            background: #059669;
        }
        
        .btn-back {
            background: rgba(255,255,255,0.2);
            color: white;
            border: 1px solid rgba(255,255,255,0.3) !important;
        }
        
        .btn-back:hover {
            background: rgba(255,255,255,0.3);
        }
        
        /* School Header */
        .school-header {
            text-align: center;
            padding-bottom: 10px;
            border-bottom: 3px double #1e3a5f;
            margin-bottom: 10px;
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
            padding: 6px 20px;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin: 10px 0;
            border-radius: 4px;
        }
        
        /* Student Info Grid */
        .student-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0;
            border: 1px solid #d0d0d0;
            margin-bottom: 10px;
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
            padding: 5px 10px;
            background: #f5f7fa;
            min-width: 110px;
            border-right: 1px solid #e5e5e5;
        }
        
        .info-value {
            padding: 5px 10px;
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
            margin-bottom: 6px;
            border-radius: 0 4px 4px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .performance-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 10.5px;
        }
        
        .performance-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 6px 8px;
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
            padding: 5px 8px;
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
            padding: 6px 8px;
            font-weight: 700;
            background: #f0f4f8;
            border: 1px solid #ccc;
            text-align: center;
        }
        
        .performance-table tfoot td:first-child {
            text-align: left;
        }
        
        .grade-badge {
            display: inline-block;
            padding: 1px 8px;
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
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .summary-card {
            text-align: center;
            padding: 8px;
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
            font-size: 8.5px;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            margin-top: 2px;
        }
        
        /* Attendance Row */
        .attendance-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .att-card {
            text-align: center;
            padding: 6px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            background: #fafafa;
        }
        
        .att-value {
            font-size: 14px;
            font-weight: 700;
            color: #1e3a5f;
        }
        
        .att-label {
            font-size: 8px;
            color: #777;
            text-transform: uppercase;
        }
        
        /* Conduct Section */
        .conduct-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 10px;
        }
        
        .conduct-card {
            text-align: center;
            padding: 8px;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            background: #f0f4f8;
        }
        
        .conduct-value {
            font-size: 14px;
            font-weight: 700;
            color: #2d5a8e;
        }
        
        .conduct-label {
            font-size: 8px;
            color: #666;
            text-transform: uppercase;
        }
        
        /* Remarks */
        .remarks-section {
            margin-bottom: 10px;
        }
        
        .remark-box {
            border: 1px solid #d0d0d0;
            border-radius: 4px;
            padding: 8px 12px;
            margin-bottom: 6px;
            min-height: 40px;
            background: #fafafa;
        }
        
        .remark-label {
            font-weight: 600;
            color: #1e3a5f;
            font-size: 10px;
            margin-bottom: 3px;
        }
        
        .remark-text {
            font-size: 10.5px;
            color: #333;
            font-style: italic;
        }
        
        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 15px;
            padding-top: 10px;
        }
        
        .signature-box {
            text-align: center;
        }
        
        .signature-line {
            border-top: 1px solid #333;
            margin-top: 6px;
            padding-top: 4px;
            font-size: 9.5px;
            font-weight: 600;
            color: #333;
        }

        .sig-img {
            height: 38px;
            display: flex;
            align-items: flex-end;
            justify-content: center;
        }
        .sig-img img {
            max-height: 38px;
            max-width: 150px;
            object-fit: contain;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        
        .signature-sub {
            font-size: 8px;
            color: #777;
            margin-top: 1px;
        }
        
        /* Grading Key */
        .grading-key {
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid #e0e0e0;
        }
        
        .grading-key-title {
            font-size: 9px;
            font-weight: 700;
            color: #1e3a5f;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .grading-key-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8px;
        }
        
        .grading-key-table th,
        .grading-key-table td {
            padding: 2px 6px;
            border: 1px solid #e0e0e0;
            text-align: center;
        }
        
        .grading-key-table th {
            background: #f0f4f8;
            font-weight: 600;
            color: #1e3a5f;
        }
        
        /* Next term info */
        .next-term-info {
            text-align: center;
            padding: 6px;
            background: #eef2f7;
            border-radius: 4px;
            font-size: 10px;
            color: #1e3a5f;
            font-weight: 500;
            margin-top: 8px;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                margin: 0;
                padding: 0;
            }
            
            .no-print-controls {
                display: none !important;
            }
            
            .report-card {
                width: 100%;
                margin: 0;
                padding: 10mm 12mm;
                box-shadow: none;
                page-break-after: always;
                min-height: auto;
            }
            
            .report-card:last-child {
                page-break-after: avoid;
            }
            
            @page {
                size: A4;
                margin: 5mm;
            }
        }
    </style>
</head>
<body>
    <!-- Print Controls (hidden in print) -->
    <div class="no-print-controls">
        <button class="btn-back" onclick="history.back()">← Back</button>
        <button class="btn-print" onclick="window.print()">🖨️ Print Report<?php echo count($reports_data) > 1 ? 's (' . count($reports_data) . ')' : ''; ?></button>
        <button class="btn-back" onclick="window.close()">✕ Close</button>
    </div>

    <?php foreach ($reports_data as $rd): 
        $report = $rd['report'];
        $records = $rd['records'];
        $attendance = $rd['attendance'];
        $conduct = $rd['conduct'];
        
        // Calculate totals
        $total_ca = 0;
        $total_exam = 0;
        $total_score = 0;
        foreach ($records as $rec) {
            $total_ca += $rec['continuous_assessment'];
            $total_exam += $rec['exam_score'];
            $total_score += $rec['total_score'];
        }
        $num_subjects = count($records);
        $average = $num_subjects > 0 ? $total_score / $num_subjects : 0;
        $overall_grade_data = getGradeFromScore($average, $grading_scale);
        
        // Attendance calculations
        $att_total = $attendance['total_days'] ?? 0;
        $att_present = $attendance['present'] ?? 0;
        $att_absent = $attendance['absent'] ?? ($att_total - $att_present);
        $att_late = $attendance['late'] ?? 0;
        $att_rate = $att_total > 0 ? ($att_present / $att_total) * 100 : 0;
    ?>
    <div class="report-card">
        <!-- School Header -->
        <div class="school-header">
            <?php if ($logo_url): ?>
            <div class="school-logo">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo">
            </div>
            <?php else: ?>
            <div class="school-logo-placeholder">
                <?php echo strtoupper(substr($school_name, 0, 1)); ?>
            </div>
            <?php endif; ?>
            <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
            <?php if ($school_motto): ?>
            <div class="school-motto">"<?php echo htmlspecialchars($school_motto); ?>"</div>
            <?php endif; ?>
            <div class="school-details">
                <?php if ($school_postal): ?><?php echo htmlspecialchars($school_postal); ?> | <?php endif; ?>
                <?php echo htmlspecialchars($school_address); ?>
                <?php if ($school_phone): ?> | Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                <?php if ($school_email): ?> | <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
            </div>
        </div>
        
        <!-- Report Title -->
        <div class="report-title">End of Term Report Card</div>
        
        <!-- Student Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Student Name</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['student_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Student ID</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['profile_student_id'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['grade_level'] . ' - ' . $report['class_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Gender</span>
                    <span class="info-value"><?php echo htmlspecialchars(ucfirst($report['gender'] ?? 'N/A')); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Academic Year</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['year_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Term</span>
                    <span class="info-value"><?php echo htmlspecialchars($report['term_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Position</span>
                    <span class="info-value"><?php echo getOrdinal($report['position_in_class']); ?> out of <?php echo $report['class_size']; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">No. on Roll</span>
                    <span class="info-value"><?php echo $report['class_size']; ?></span>
                </div>
            </div>
        </div>
        
        <!-- Academic Performance -->
        <div class="section-title">Academic Performance</div>
        <table class="performance-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Subject</th>
                    <th style="width: 12%;">Class Score (50%)</th>
                    <th style="width: 12%;">Exam Score (50%)</th>
                    <th style="width: 12%;">Total (100%)</th>
                    <th style="width: 9%;">Grade</th>
                    <th style="width: 15%;">Interpretation</th>
                    <th style="width: 15%;">Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record):
                    $grade_data = getGradeFromScore($record['total_score'], $grading_scale);
                    $grade_class = '';
                    if ($record['total_score'] >= 80) $grade_class = 'grade-excellent';
                    elseif ($record['total_score'] >= 70) $grade_class = 'grade-very-good';
                    elseif ($record['total_score'] >= 65) $grade_class = 'grade-good';
                    elseif ($record['total_score'] >= 50) $grade_class = 'grade-credit';
                    elseif ($record['total_score'] >= 40) $grade_class = 'grade-pass';
                    else $grade_class = 'grade-fail';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($record['subject_name']); ?></td>
                    <td><?php echo number_format($record['continuous_assessment'], 1); ?></td>
                    <td><?php echo number_format($record['exam_score'], 1); ?></td>
                    <td style="font-weight: 600;"><?php echo number_format($record['total_score'], 1); ?></td>
                    <td><span class="grade-badge <?php echo $grade_class; ?>"><?php echo htmlspecialchars(formatGrade($record['total_score'])); ?></span></td>
                    <td style="font-size: 9px;"><?php echo htmlspecialchars($grade_data['interpretation']); ?></td>
                    <td style="font-size: 9px;"><?php echo htmlspecialchars($record['remarks'] ?? ''); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL / AVERAGE</td>
                    <td><?php echo number_format($total_ca, 1); ?></td>
                    <td><?php echo number_format($total_exam, 1); ?></td>
                    <td><?php echo number_format($total_score, 1); ?></td>
                    <td><span class="grade-badge <?php echo $average >= 80 ? 'grade-excellent' : ($average >= 70 ? 'grade-very-good' : ($average >= 50 ? 'grade-credit' : 'grade-fail')); ?>"><?php echo htmlspecialchars(formatGrade($average)); ?></span></td>
                    <td colspan="2" style="font-size: 9px;">Average: <?php echo number_format($average, 1); ?>%</td>
                </tr>
            </tfoot>
        </table>
        
        <!-- Summary Statistics -->
        <div class="section-title">Summary</div>
        <div class="summary-grid">
            <div class="summary-card">
                <div class="summary-value"><?php echo $num_subjects; ?></div>
                <div class="summary-label">Subjects Taken</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo number_format($total_score, 0); ?>/<?php echo $num_subjects * 100; ?></div>
                <div class="summary-label">Total Score</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo number_format($average, 1); ?>%</div>
                <div class="summary-label">Average Score</div>
            </div>
            <div class="summary-card">
                <div class="summary-value"><?php echo getOrdinal($report['position_in_class']); ?></div>
                <div class="summary-label">Position in Class</div>
            </div>
        </div>
        
        <!-- Attendance -->
        <div class="section-title">Attendance Record</div>
        <div class="attendance-grid">
            <div class="att-card">
                <div class="att-value"><?php echo $att_total; ?></div>
                <div class="att-label">Total Days</div>
            </div>
            <div class="att-card">
                <div class="att-value"><?php echo $att_present; ?></div>
                <div class="att-label">Days Present</div>
            </div>
            <div class="att-card">
                <div class="att-value"><?php echo $att_absent; ?></div>
                <div class="att-label">Days Absent</div>
            </div>
            <div class="att-card">
                <div class="att-value"><?php echo $att_late; ?></div>
                <div class="att-label">Late</div>
            </div>
            <div class="att-card">
                <div class="att-value" style="color: <?php echo $att_rate >= 90 ? '#059669' : ($att_rate >= 75 ? '#d97706' : '#dc2626'); ?>;"><?php echo number_format($att_rate, 1); ?>%</div>
                <div class="att-label">Attendance Rate</div>
            </div>
        </div>
        
        <!-- Conduct & Behavior -->
        <div class="section-title">Conduct &amp; Behavior</div>
        <div class="conduct-grid">
            <div class="conduct-card">
                <div class="conduct-value"><?php echo htmlspecialchars($conduct['conduct_grade'] ?? 'B'); ?></div>
                <div class="conduct-label">Conduct Grade</div>
            </div>
            <div class="conduct-card">
                <div class="conduct-value"><?php echo htmlspecialchars($conduct['attitude'] ?? 'Good'); ?></div>
                <div class="conduct-label">Attitude</div>
            </div>
            <div class="conduct-card">
                <div class="conduct-value"><?php echo htmlspecialchars($conduct['interest'] ?? 'Improving'); ?></div>
                <div class="conduct-label">Interest</div>
            </div>
        </div>
        
        <!-- Remarks -->
        <div class="section-title">Remarks</div>
        <div class="remarks-section">
            <?php
            $t_remarks = trim($report['teacher_remarks'] ?? '');
            if (empty($t_remarks) || $t_remarks === 'No remarks provided.') {
                $t_remarks = getAutoRemarks($average);
            }
            
            $p_remarks = trim($report['principal_remarks'] ?? '');
            if (empty($p_remarks)) {
                $p_remarks = getPrincipalAutoRemarks($average);
            }
            ?>
            <div class="remark-box">
                <div class="remark-label">Class Teacher's Remarks:</div>
                <div class="remark-text"><?php echo htmlspecialchars($t_remarks); ?></div>
            </div>
            <div class="remark-box">
                <div class="remark-label">Head Teacher / Headmaster/Headmistress's Remarks:</div>
                <div class="remark-text"><?php echo htmlspecialchars($p_remarks); ?></div>
            </div>
        </div>
        
        <?php if ($report['next_term_begins']): ?>
        <div class="next-term-info">
            📅 Next Term Begins: <strong><?php echo date('l, F j, Y', strtotime($report['next_term_begins'])); ?></strong>
        </div>
        <?php endif; ?>
        
        <!-- Signatures -->
        <?php
            $class_teacher_sig = getStaffSignatureUrl($db, $report['main_teacher_id'] ?? 0);
        ?>
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($class_teacher_sig); ?></div>
                <div class="signature-line">Class Teacher</div>
                <div class="signature-sub">Sign</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($headmaster_sig['url']); ?></div>
                <div class="signature-line"><?php echo $headmaster_sig['name'] ? htmlspecialchars($headmaster_sig['name']) : 'Headmaster/Headmistress'; ?></div>
                <div class="signature-sub"><?php echo $headmaster_sig['name'] ? 'Headmaster/Headmistress' : 'Sign/Stamp'; ?></div>
            </div>
        </div>
        
        <!-- Grading Key -->
        <div class="grading-key">
            <div class="grading-key-title">Grading Key</div>
            <table class="grading-key-table">
                <tr>
                    <th>Grade</th>
                    <?php foreach ($grading_scale as $gs): ?>
                    <td><strong><?php echo $gs['grade']; ?></strong></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <th>Score Range</th>
                    <?php foreach ($grading_scale as $gs): ?>
                    <td><?php echo intval($gs['min_score']); ?>-<?php echo intval($gs['max_score']); ?></td>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <th>Interpretation</th>
                    <?php foreach ($grading_scale as $gs): ?>
                    <td><?php echo $gs['interpretation']; ?></td>
                    <?php endforeach; ?>
                </tr>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script>
        // Auto-print if requested
        <?php if (isset($_GET['auto_print']) && $_GET['auto_print'] === '1'): ?>
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 500);
        });
        <?php endif; ?>
    </script>
</body>
</html>
