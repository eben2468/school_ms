<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once 'exam_access.php';
$database = new Database();
$db = $database->getConnection();

// Settings/signature helpers expect a global $pdo.
$pdo = $db;
require_once '../../includes/signature_helper.php';
$headmaster_sig = getSchoolSignature('headmaster');

$exam_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$exam_id) {
    header("Location: index.php");
    exit();
}

// Teachers may only view/save results for classes/subjects they teach
if ($_SESSION['role'] === 'teacher' && !teacherOwnsExam($db, $_SESSION['user_id'], $exam_id)) {
    header("Location: index.php?error=not_authorized");
    exit();
}

// Get exam details
$query = "SELECT e.*, s.name as subject_name, s.code as subject_code,
          c.name as class_name, c.grade_level,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id) as total_submissions,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id AND e.passing_marks IS NOT NULL AND er.marks_obtained >= e.passing_marks) as passed_count
          FROM exams e
          LEFT JOIN subjects s ON e.subject_id = s.id
          LEFT JOIN classes c ON e.class_id = c.id
          WHERE e.id = :exam_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':exam_id', $exam_id);
$stmt->execute();
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: index.php");
    exit();
}

// Handle result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_results'])) {
    foreach ($_POST['marks'] as $student_id => $marks) {
        if ($marks !== '') {
            $query = "INSERT INTO exam_results (exam_id, student_id, marks_obtained, remarks)
                     VALUES (:exam_id, :student_id, :marks, :remarks)
                     ON DUPLICATE KEY UPDATE marks_obtained = :marks, remarks = :remarks";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':exam_id', $exam_id);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':marks', $marks);
            $remarks = isset($_POST['remarks'][$student_id]) ? $_POST['remarks'][$student_id] : '';
            $stmt->bindParam(':remarks', $remarks);
            $stmt->execute();
        }
    }
    
    header("Location: results.php?id=" . $exam_id . "&success=1");
    exit();
}

// ---------------------------------------------------------------------------
// Printable / PDF view: renders a clean, standalone, read-only document and
// auto-opens the browser print dialog (where the user can choose "Save as PDF").
// ---------------------------------------------------------------------------
if (isset($_GET['print'])) {
    require_once '../../includes/settings_helper.php';
    $school_name    = getSchoolSetting('school_name', 'Greenwood Academy');
    $school_address = getSchoolSetting('school_address', '');
    $school_phone   = getSchoolSetting('school_phone', '');
    $school_email   = getSchoolSetting('school_email', '');
    $school_motto = ''; $school_postal = '';
    try {
        $m = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
        $m->execute(); $mr = $m->fetch(PDO::FETCH_ASSOC); if ($mr) $school_motto = $mr['setting_value'];
        $p = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_postal'");
        $p->execute(); $pr = $p->fetch(PDO::FETCH_ASSOC); if ($pr) $school_postal = $pr['setting_value'];
    } catch (PDOException $e) {}
    $logo_url = getSchoolLogo();

    $print_stmt = $db->prepare("SELECT u.name, sp.student_id as roll_number, er.marks_obtained as marks, er.remarks
        FROM users u
        JOIN student_classes sc ON u.id = sc.student_id
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN exam_results er ON u.id = er.student_id AND er.exam_id = :exam_id
        WHERE sc.class_id = :class_id AND u.role = 'student' AND sc.status = 'active'
        ORDER BY sp.student_id, u.name");
    $print_stmt->execute([':exam_id' => $exam_id, ':class_id' => $exam['class_id']]);
    $print_students = $print_stmt->fetchAll(PDO::FETCH_ASSOC);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Results - <?php echo htmlspecialchars($exam['subject_name']); ?> - <?php echo htmlspecialchars($school_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; font-size: 11px; line-height: 1.4; color: #1a1a1a; background: #f0f0f0; }
        .statement-card { width: 210mm; min-height: 297mm; margin: 20px auto; padding: 12mm 15mm; background: white; box-shadow: 0 4px 20px rgba(0,0,0,0.15); position: relative; }
        .no-print-controls { text-align: center; padding: 15px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; position: sticky; top: 0; z-index: 100; display: flex; align-items: center; justify-content: center; gap: 12px; }
        .no-print-controls button, .no-print-controls a { padding: 8px 24px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-family: 'Inter', sans-serif; font-size: 13px; transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
        .btn-print { background: #10b981; color: white; }
        .btn-print:hover { background: #059669; }
        .btn-back { background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3) !important; }
        .btn-back:hover { background: rgba(255,255,255,0.3); }
        .school-header { text-align: center; padding-bottom: 10px; border-bottom: 3px double #1e3a5f; margin-bottom: 10px; }
        .school-logo { width: 60px; height: 60px; margin: 0 auto 6px; }
        .school-logo img { width: 100%; height: 100%; object-fit: contain; }
        .school-logo-placeholder { width: 60px; height: 60px; margin: 0 auto 6px; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); border-radius: 50%; display: flex; align-items: center; justify-content: center; color: white; font-size: 24px; font-weight: 800; }
        .school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 2px; }
        .school-details { font-size: 10px; color: #555; line-height: 1.5; }
        .school-motto { font-style: italic; color: #2d5a8e; font-size: 11px; margin-top: 3px; font-weight: 500; }
        .statement-title { text-align: center; background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 20px; font-size: 14px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin: 10px 0; border-radius: 4px; }
        .student-info { display: grid; grid-template-columns: 1fr 1fr; gap: 0; border: 1px solid #d0d0d0; margin-bottom: 15px; border-radius: 4px; overflow: hidden; }
        .info-row { display: flex; border-bottom: 1px solid #e5e5e5; font-size: 10.5px; }
        .info-row:last-child { border-bottom: none; }
        .info-label { font-weight: 600; color: #333; padding: 5px 10px; background: #f5f7fa; min-width: 120px; border-right: 1px solid #e5e5e5; }
        .info-value { padding: 5px 10px; flex: 1; color: #1a1a1a; font-weight: 500; }
        .info-col-divider { border-right: 1px solid #d0d0d0; }
        .section-title { font-size: 12px; font-weight: 700; color: #1e3a5f; padding: 5px 10px; background: #eef2f7; border-left: 4px solid #1e3a5f; margin-bottom: 8px; border-radius: 0 4px 4px 0; text-transform: uppercase; letter-spacing: 0.5px; }
        .ledger-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 10px; }
        .ledger-table thead th { background: linear-gradient(135deg, #1e3a5f, #2d5a8e); color: white; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.3px; border: 1px solid #1a3455; }
        .ledger-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #ddd; }
        .ledger-table tbody td.t-left { text-align: left; }
        .ledger-table tbody tr:nth-child(even) { background: #f9fafb; }
        .pass { color: #099268; font-weight: 700; }
        .fail { color: #c53030; font-weight: 700; }
        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 40px; padding-top: 10px; }
        .signature-box { text-align: center; }
        .signature-line { border-top: 1px solid #333; margin-top: 8px; padding-top: 4px; font-size: 9.5px; font-weight: 600; color: #333; }
        .sig-img { height: 44px; display: flex; align-items: flex-end; justify-content: center; }
        .sig-img img { max-height: 44px; max-width: 160px; object-fit: contain; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .signature-sub { font-size: 8px; color: #777; margin-top: 1px; }
        @media print {
            body { background: white; margin: 0; padding: 0; }
            .no-print-controls { display: none !important; }
            .statement-card { width: 100%; margin: 0; padding: 10mm 12mm; box-shadow: none; min-height: auto; }
            @page { size: A4; margin: 5mm; }
        }
    </style>
</head>
<body>
    <div class="no-print-controls">
        <a href="results.php?id=<?php echo (int)$exam_id; ?>" class="btn-back">&larr; Back to Results</a>
        <button class="btn-print" onclick="window.print()">🖨️ Print Results</button>
    </div>

    <div class="statement-card">
        <!-- School Header -->
        <div class="school-header">
            <?php if ($logo_url): ?>
            <div class="school-logo"><img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo"></div>
            <?php else: ?>
            <div class="school-logo-placeholder"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
            <?php endif; ?>
            <div class="school-name"><?php echo htmlspecialchars($school_name); ?></div>
            <?php if ($school_motto): ?><div class="school-motto">"<?php echo htmlspecialchars($school_motto); ?>"</div><?php endif; ?>
            <div class="school-details">
                <?php if ($school_postal): ?><?php echo htmlspecialchars($school_postal); ?> | <?php endif; ?>
                <?php echo htmlspecialchars($school_address); ?>
                <?php if ($school_phone): ?> | Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                <?php if ($school_email): ?> | <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
            </div>
        </div>

        <!-- Title -->
        <div class="statement-title">Examination Results</div>

        <!-- Exam Information -->
        <div class="student-info">
            <div class="info-col-divider">
                <div class="info-row">
                    <span class="info-label">Subject</span>
                    <span class="info-value"><?php echo htmlspecialchars($exam['subject_name']); ?> (<?php echo htmlspecialchars($exam['subject_code']); ?>)</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Class</span>
                    <span class="info-value">Grade <?php echo htmlspecialchars($exam['grade_level']); ?> - <?php echo htmlspecialchars($exam['class_name']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Exam Date</span>
                    <span class="info-value"><?php echo date('M j, Y', strtotime($exam['exam_date'])); ?> at <?php echo date('g:i A', strtotime($exam['start_time'])); ?></span>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span class="info-label">Maximum Marks</span>
                    <span class="info-value" style="font-weight: 700; color: #1e3a5f;"><?php echo htmlspecialchars($exam['total_marks']); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Passing Marks</span>
                    <span class="info-value"><?php echo $exam['passing_marks'] !== null ? htmlspecialchars($exam['passing_marks']) : 'N/A'; ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Submitted / Passed</span>
                    <span class="info-value"><?php echo (int)$exam['total_submissions']; ?><?php echo $exam['passing_marks'] !== null ? ' / ' . (int)$exam['passed_count'] : ''; ?></span>
                </div>
            </div>
        </div>

        <!-- Results Table -->
        <div class="section-title">Student Results</div>
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 8%;">#</th>
                    <th style="width: 17%;">Roll No</th>
                    <th style="width: 35%;">Student Name</th>
                    <th style="width: 12%;">Marks</th>
                    <th style="width: <?php echo $exam['passing_marks'] !== null ? '18%' : '28%'; ?>;">Remarks</th>
                    <?php if ($exam['passing_marks'] !== null): ?><th style="width: 10%;">Status</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($print_students)): ?>
                <tr><td colspan="<?php echo $exam['passing_marks'] !== null ? 6 : 5; ?>" style="padding: 15px; color: #666;">No students found in this class.</td></tr>
                <?php else: $i = 1; foreach ($print_students as $s): ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td class="t-left" style="font-weight: 600; color: #1e3a5f;"><?php echo htmlspecialchars($s['roll_number']); ?></td>
                    <td class="t-left" style="font-weight: 500;"><?php echo htmlspecialchars($s['name']); ?></td>
                    <td style="font-weight: 700;"><?php echo $s['marks'] !== null ? htmlspecialchars($s['marks']) : '-'; ?></td>
                    <td class="t-left"><?php echo htmlspecialchars($s['remarks'] ?? ''); ?></td>
                    <?php if ($exam['passing_marks'] !== null): ?>
                    <td>
                        <?php if ($s['marks'] === null): ?>
                            -
                        <?php elseif ($s['marks'] >= $exam['passing_marks']): ?>
                            <span class="pass">Pass</span>
                        <?php else: ?>
                            <span class="fail">Fail</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>

        <!-- Signatures -->
        <div class="signatures">
            <div class="signature-box">
                <div class="sig-img"></div>
                <div class="signature-line">Class Teacher</div>
                <div class="signature-sub">Sign &amp; Date</div>
            </div>
            <div class="signature-box">
                <div class="sig-img"><?php echo signatureImg($headmaster_sig['url']); ?></div>
                <div class="signature-line"><?php echo htmlspecialchars($headmaster_sig['name'] ?: 'Headmaster/Headmistress'); ?></div>
                <div class="signature-sub">Sign &amp; Stamp</div>
            </div>
        </div>
    </div>
</body>
</html>
    <?php
    exit();
}

$title = "Exam Results - " . $exam['subject_name'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">
        <div class="max-w-7xl mx-auto">
            <div class="exam-results-header">
                <h1 class="text-3xl font-semibold text-gray-800 mb-3">Exam Results</h1>
                <div class="flex no-stack space-x-4">
                    <a href="view.php?id=<?php echo $exam_id; ?>" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Exam Details
                    </a>
                    <?php if ($exam['total_submissions'] > 0): ?>
                    <a href="export_results.php?id=<?php echo $exam_id; ?>" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i> Export Results
                    </a>
                    <?php endif; ?>
                    <a href="results.php?id=<?php echo $exam_id; ?>&print=1" target="_blank" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-file-pdf mr-2"></i> Print / PDF
                    </a>
                </div>
            </div>

            <!-- Exam Information -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <dl class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Subject</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                <?php echo htmlspecialchars($exam['subject_name']); ?> 
                                (<?php echo htmlspecialchars($exam['subject_code']); ?>)
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Class</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                Grade <?php echo htmlspecialchars($exam['grade_level']); ?> - 
                                <?php echo htmlspecialchars($exam['class_name']); ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Exam Date & Time</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                <?php echo date('M j, Y', strtotime($exam['exam_date'])); ?> at
                                <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Maximum Marks</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo $exam['total_marks']; ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Passing Marks</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo $exam['passing_marks'] !== null ? htmlspecialchars($exam['passing_marks']) : 'N/A'; ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Results Summary</dt>
                            <dd class="mt-1">
                                <div class="flex items-center space-x-2">
                                    <span class="text-lg text-gray-900"><?php echo $exam['total_submissions']; ?> Submitted</span>
                                    <?php if ($exam['passing_marks'] !== null): ?>
                                    <span class="text-gray-500">|</span>
                                    <span class="text-lg text-green-600"><?php echo $exam['passed_count']; ?> Passed</span>
                                    <?php endif; ?>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline">Results have been saved successfully.</span>
            </div>
            <?php endif; ?>

            <!-- Results Form -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form method="POST" class="divide-y divide-gray-200">
                    <div class="p-6">
                        <?php
                        // Get students and their results
                        $query = "SELECT u.id, u.name, sp.student_id as roll_number, er.marks_obtained as marks, er.remarks,
                                er.created_at as updated_at
                                FROM users u
                                JOIN student_classes sc ON u.id = sc.student_id
                                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                                LEFT JOIN exam_results er ON u.id = er.student_id AND er.exam_id = :exam_id
                                WHERE sc.class_id = :class_id AND u.role = 'student' AND sc.status = 'active'
                                ORDER BY sp.student_id, u.name";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':exam_id', $exam_id);
                        $stmt->bindParam(':class_id', $exam['class_id']);
                        $stmt->execute();
                        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (empty($students)): ?>
                        <p class="text-gray-500 text-center py-4">No students found in this class.</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Marks</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="marks[<?php echo $student['id']; ?>]"
                                                value="<?php echo isset($student['marks']) ? $student['marks'] : ''; ?>"
                                                class="w-24 px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                                min="0" max="<?php echo $exam['total_marks']; ?>"
                                                <?php echo strtotime($exam['exam_date'] . ' ' . $exam['start_time']) > time() ? 'disabled' : ''; ?>>
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="text" name="remarks[<?php echo $student['id']; ?>]"
                                                value="<?php echo isset($student['remarks']) ? htmlspecialchars($student['remarks']) : ''; ?>"
                                                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                                <?php echo strtotime($exam['exam_date'] . ' ' . $exam['start_time']) > time() ? 'disabled' : ''; ?>>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (isset($student['marks'])): ?>
                                            <?php if ($exam['passing_marks'] !== null): ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                <?php echo $student['marks'] >= $exam['passing_marks'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $student['marks'] >= $exam['passing_marks'] ? 'Pass' : 'Fail'; ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                                Submitted
                                            </span>
                                            <?php endif; ?>
                                            <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                Pending
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if (isset($student['updated_at'])): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($student['updated_at'])); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($students) && strtotime($exam['exam_date'] . ' ' . $exam['start_time']) <= time()): ?>
                    <div class="px-6 py-4 bg-gray-50">
                        <div class="flex justify-end">
                            <button type="submit" name="submit_results" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                                Save Results
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
                    </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
