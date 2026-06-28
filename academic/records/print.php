<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Settings helper expects a global $pdo
$pdo = $db;
require_once '../../includes/settings_helper.php';
require_once '../../includes/signature_helper.php';
$headmaster_sig = getSchoolSignature('headmaster');

$academic_context = $database->getCurrentAcademicContext();

// Filters (mirrors index.php)
$selected_year_id = $_GET['year_id'] ?? $academic_context['year_id'];
$selected_term_id = $_GET['term_id'] ?? $academic_context['term_id'];
$selected_class_id = $_GET['class_id'] ?? '';
$selected_student_id = $_GET['student_id'] ?? '';

// Build records query
$records = [];
$where_conditions = [];
$params = [];

if ($selected_year_id) {
    $where_conditions[] = "sar.academic_year_id = :year_id";
    $params[':year_id'] = $selected_year_id;
}
if ($selected_term_id) {
    $where_conditions[] = "sar.academic_term_id = :term_id";
    $params[':term_id'] = $selected_term_id;
}
if ($selected_class_id) {
    $where_conditions[] = "sar.class_id = :class_id";
    $params[':class_id'] = $selected_class_id;
}
if ($selected_student_id) {
    $where_conditions[] = "sar.student_id = :student_id";
    $params[':student_id'] = $selected_student_id;
}

if (!empty($where_conditions)) {
    $records_sql = "SELECT
        sar.*,
        u.name as student_name,
        sp.student_id as profile_student_id,
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
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id
    WHERE " . implode(' AND ', $where_conditions) . "
    ORDER BY u.name, s.name";

    $stmt = $db->prepare($records_sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Summary
$total_records = count($records);
$students_count = $total_records ? count(array_unique(array_column($records, 'student_id'))) : 0;
$subjects_count = $total_records ? count(array_unique(array_column($records, 'subject_id'))) : 0;
$average_score = $total_records ? array_sum(array_column($records, 'total_score')) / $total_records : 0;

// Resolve filter labels for the header
function records_lookup_name($db, $sql, $id) {
    if (!$id) return null;
    $stmt = $db->prepare($sql);
    $stmt->execute([':id' => $id]);
    return $stmt->fetchColumn() ?: null;
}
$year_label  = records_lookup_name($db, "SELECT year_name FROM academic_years WHERE id = :id", $selected_year_id) ?: 'All Years';
$term_label  = records_lookup_name($db, "SELECT term_name FROM academic_terms WHERE id = :id", $selected_term_id) ?: 'All Terms';
$class_label = records_lookup_name($db, "SELECT CONCAT('Grade ', grade_level, ' - ', name) FROM classes WHERE id = :id", $selected_class_id) ?: 'All Classes';
$student_label = $selected_student_id ? records_lookup_name($db, "SELECT name FROM users WHERE id = :id", $selected_student_id) : null;

// School settings
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$school_motto = '';
$school_postal = '';
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

function records_grade_color($score) {
    if ($score >= 80) return '#099268';
    if ($score >= 70) return '#2d5a8e';
    if ($score >= 60) return '#b7791f';
    if ($score >= 50) return '#c05621';
    return '#c53030';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Academic Records - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
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

        .statement-card {
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

        .no-print-controls button,
        .no-print-controls a {
            padding: 8px 24px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-family: 'Inter', sans-serif;
            font-size: 13px;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
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

        .statement-title {
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

        /* Info Grid */
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
            padding: 5px 10px;
            background: #f5f7fa;
            min-width: 130px;
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

        /* Section + Table */
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

        .ledger-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
            font-size: 10px;
        }

        .ledger-table thead th {
            background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
            color: white;
            padding: 6px 8px;
            text-align: center;
            font-weight: 600;
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            border: 1px solid #1a3455;
        }

        .ledger-table thead th:first-child,
        .ledger-table thead th:nth-child(2) {
            text-align: left;
        }

        .ledger-table tbody td {
            padding: 5px 8px;
            text-align: center;
            border: 1px solid #ddd;
        }

        .ledger-table tbody td:first-child,
        .ledger-table tbody td:nth-child(2) {
            text-align: left;
        }

        .ledger-table tbody tr:nth-child(even) {
            background: #f9fafb;
        }

        .grade-pill {
            display: inline-block;
            padding: 1px 8px;
            border-radius: 10px;
            font-size: 9px;
            font-weight: 700;
            color: #fff;
        }

        /* Totals */
        .totals-block {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 25px;
        }

        .totals-table {
            width: 280px;
            border-collapse: collapse;
            font-size: 10.5px;
        }

        .totals-table td {
            padding: 4px 8px;
            border-bottom: 1px solid #e5e5e5;
        }

        .totals-table tr:last-child td {
            border-bottom: none;
        }

        .totals-label {
            color: #666;
            font-weight: 500;
        }

        .totals-value {
            text-align: right;
            font-weight: 700;
            color: #1a1a1a;
        }

        .grand-total-row {
            background: #eef2f7;
            font-weight: 800;
        }

        .grand-total-row td {
            border-top: 1px solid #1e3a5f;
            border-bottom: 1px solid #1e3a5f;
            color: #1e3a5f !important;
        }

        /* Signatures */
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 40px;
            padding-top: 10px;
        }

        .signature-box {
            text-align: center;
        }

        .signature-line {
            border-top: 1px solid #333;
            margin-top: 8px;
            padding-top: 4px;
            font-size: 9.5px;
            font-weight: 600;
            color: #333;
        }
        .sig-img { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 44px; max-width: 160px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .signature-sub {
            font-size: 8px;
            color: #777;
            margin-top: 1px;
        }

        /* Watermark */
        .status-watermark {
            position: absolute;
            top: 35%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            font-size: 70px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: 5px;
            opacity: 0.06;
            pointer-events: none;
            width: 100%;
            text-align: center;
            color: #1e3a5f;
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

            .statement-card {
                width: 100%;
                margin: 0;
                padding: 10mm 12mm;
                box-shadow: none;
                min-height: auto;
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
        <a href="index.php" class="btn-back">&larr; Back to Records</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print Records</button>
    </div>

    <div class="statement-card">
        <!-- Watermark -->
        <div class="status-watermark">Academic Records</div>

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

        <!-- Title -->
        <div class="statement-title">Academic Records Report</div>

        <!-- Report Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Date Generated</span>
                    <span class="info-value"><?php echo date('M d, Y H:i'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Academic Year</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($year_label); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Term</span>
                    <span class="info-value"><?php echo htmlspecialchars($term_label); ?></span>
                </div>
                <?php if ($student_label): ?>
                <div class="info-row">
                    <span class="info-label">Student</span>
                    <span class="info-value"><?php echo htmlspecialchars($student_label); ?></span>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value"><?php echo htmlspecialchars($class_label); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Total Records</span>
                    <span class="info-value" style="font-weight: 700;"><?php echo $total_records; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Students / Subjects</span>
                    <span class="info-value"><?php echo $students_count; ?> student(s), <?php echo $subjects_count; ?> subject(s)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Average Score</span>
                    <span class="info-value" style="font-weight: 700; color: <?php echo records_grade_color($average_score); ?>;"><?php echo number_format($average_score, 1); ?></span>
                </div>
            </div>
        </div>

        <!-- Records Table -->
        <div class="section-title">Student Performance Records</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 22%">Student</th>
                    <th style="width: 22%">Subject</th>
                    <th style="width: 10%">CA</th>
                    <th style="width: 10%">Exam</th>
                    <th style="width: 10%">Total</th>
                    <th style="width: 9%">Grade</th>
                    <th style="width: 17%">Teacher</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $record): ?>
                <tr>
                    <td>
                        <span style="font-weight: 600;"><?php echo htmlspecialchars($record['student_name']); ?></span><br>
                        <span style="color: #777; font-size: 9px;">ID: <?php echo htmlspecialchars($record['profile_student_id']); ?></span>
                    </td>
                    <td>
                        <span style="font-weight: 500;"><?php echo htmlspecialchars($record['subject_name']); ?></span><br>
                        <span style="color: #777; font-size: 9px;"><?php echo htmlspecialchars($record['subject_code']); ?></span>
                    </td>
                    <td><?php echo number_format($record['continuous_assessment'], 1); ?></td>
                    <td><?php echo number_format($record['exam_score'], 1); ?></td>
                    <td style="font-weight: 700;"><?php echo number_format($record['total_score'], 1); ?></td>
                    <td>
                        <span class="grade-pill" style="background: <?php echo records_grade_color($record['total_score']); ?>;">
                            <?php echo htmlspecialchars($record['grade'] ?: 'N/A'); ?>
                        </span>
                    </td>
                    <td style="text-align: left;"><?php echo htmlspecialchars($record['teacher_name'] ?: 'N/A'); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($records)): ?>
                <tr>
                    <td colspan="7" style="text-align: center; padding: 15px; color: #666;">No academic records found for the selected filters.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Summary Totals -->
        <div class="totals-block">
            <table class="totals-table">
                <tr>
                    <td class="totals-label">Total Records</td>
                    <td class="totals-value"><?php echo $total_records; ?></td>
                </tr>
                <tr>
                    <td class="totals-label">Students Covered</td>
                    <td class="totals-value"><?php echo $students_count; ?></td>
                </tr>
                <tr>
                    <td class="totals-label">Subjects Covered</td>
                    <td class="totals-value"><?php echo $subjects_count; ?></td>
                </tr>
                <tr class="grand-total-row">
                    <td>Average Score</td>
                    <td class="totals-value"><?php echo number_format($average_score, 1); ?></td>
                </tr>
            </table>
        </div>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"></div>
                <div class="signature-line">Prepared By</div>
                <div class="signature-sub">Class Teacher / Academic Office</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($headmaster_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($headmaster_sig['name'] ?: 'Authorized Signature / Stamp'); ?></div>
                <div class="signature-sub">Headmaster / Headmistress</div>
            </div>
        </div>
    </div>
</body>
</html>
