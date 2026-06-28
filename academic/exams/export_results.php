<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$exam_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$exam_id) {
    header("Location: index.php");
    exit();
}

// Get exam details
$query = "SELECT e.*, s.name as subject_name, s.code as subject_code, 
          c.name as class_name, c.grade_level
          FROM exams e 
          JOIN subjects s ON e.subject_id = s.id 
          JOIN classes c ON e.class_id = c.id 
          WHERE e.id = :exam_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':exam_id', $exam_id);
$stmt->execute();
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: index.php");
    exit();
}

// Get students and their results
$query = "SELECT u.name, sp.student_id as roll_number, er.marks_obtained as marks, er.remarks,
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
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
$filename = sprintf('exam_results_%s_%s_%s.csv', 
    str_replace(' ', '_', $exam['subject_code']),
    str_replace(' ', '_', $exam['class_name']),
    date('Y-m-d')
);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

// Create CSV
$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write exam information
fputcsv($output, ['Exam Information']);
fputcsv($output, ['Subject', $exam['subject_name'] . ' (' . $exam['subject_code'] . ')']);
fputcsv($output, ['Class', 'Grade ' . $exam['grade_level'] . ' - ' . $exam['class_name']]);
fputcsv($output, ['Date', date('F j, Y', strtotime($exam['exam_date']))]);
fputcsv($output, ['Time', date('g:i A', strtotime($exam['start_time']))]);
fputcsv($output, ['Maximum Marks', $exam['total_marks']]);
fputcsv($output, ['Passing Marks', $exam['passing_marks'] !== null ? $exam['passing_marks'] : 'N/A']);
fputcsv($output, []); // Empty line for spacing

// Write headers
// "Grade" header reflects the school's configured grading system
// (percentage / letter / GPA / points) — see getGradingSystemLabel().
fputcsv($output, [
    'Roll Number',
    'Student Name',
    'Marks',
    'Grade (' . getGradingSystemLabel() . ')',
    'Status',
    'Remarks',
    'Last Updated',
    'Updated By'
]);

$total_marks = (float)($exam['total_marks'] ?? 0);

// Write data rows
foreach ($results as $result) {
    $status = '';
    if (isset($result['marks'])) {
        if ($exam['passing_marks'] !== null) {
            $status = $result['marks'] >= $exam['passing_marks'] ? 'Pass' : 'Fail';
        } else {
            $status = 'Submitted';
        }
    } else {
        $status = 'Not Attempted';
    }

    // Convert the raw mark to a percentage of the exam total, then format it in
    // the school's chosen grading style. Blank when no mark was recorded.
    if (isset($result['marks']) && $total_marks > 0) {
        $grade_display = formatGrade(((float)$result['marks'] / $total_marks) * 100);
    } else {
        $grade_display = 'N/A';
    }

    fputcsv($output, [
        $result['roll_number'],
        $result['name'],
        $result['marks'] ?? 'N/A',
        $grade_display,
        $status,
        $result['remarks'] ?? '',
        isset($result['updated_at']) ? date('Y-m-d H:i:s', strtotime($result['updated_at'])) : 'N/A',
        $result['submitted_by'] ?? 'N/A'
    ]);
}

// Close the file
fclose($output);
exit();