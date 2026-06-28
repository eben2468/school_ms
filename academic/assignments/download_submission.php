<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    http_response_code(403);
    die('Access denied. Please log in to download files.');
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$submission_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!$submission_id) {
    http_response_code(400);
    die('Invalid request. Submission ID is required.');
}

// Get submission details and verify access
$query = "SELECT sa.*, a.teacher_id, a.class_id, u.name as student_name
          FROM student_assignments sa
          JOIN assignments a ON sa.assignment_id = a.id
          JOIN users u ON sa.student_id = u.id
          WHERE sa.id = :submission_id";

$stmt = $db->prepare($query);
$stmt->bindParam(':submission_id', $submission_id);
$stmt->execute();
$submission = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$submission) {
    http_response_code(404);
    die('Submission not found.');
}

// Access control:
// Students can only download their own submission.
// Teachers, admins, and principals can download submissions.
if ($user_role === 'student' && $submission['student_id'] != $user_id) {
    http_response_code(403);
    die('Access denied. You can only download your own submission.');
}

// Teachers can only view submissions for assignments they teach or created
if ($user_role === 'teacher') {
    if ($submission['teacher_id'] != $user_id) {
        // Double check if teacher is assigned to this class and subject in class_teachers
        $verify_teacher_query = "SELECT COUNT(*) as count FROM class_teachers 
                                 WHERE teacher_id = :teacher_id AND class_id = :class_id";
        $verify_teacher_stmt = $db->prepare($verify_teacher_query);
        $verify_teacher_stmt->bindParam(':teacher_id', $user_id);
        $verify_teacher_stmt->bindParam(':class_id', $submission['class_id']);
        $verify_teacher_stmt->execute();
        $teacher_check = $verify_teacher_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($teacher_check['count'] == 0) {
            http_response_code(403);
            die('Access denied. You do not teach this class.');
        }
    }
}

if (empty($submission['file_path'])) {
    http_response_code(404);
    die('No file attachment found for this submission.');
}

// Build the full file path
$file_path = '../../' . $submission['file_path'];

if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on server.');
}

// Get file information
$file_size = filesize($file_path);
$file_name = basename($submission['file_path']);

// Extract original name from unique filename structure: uniqid() . '_' . time() . '_' . name
$clean_name = preg_replace('/^[a-f0-9]+_[0-9]+_/', '', $file_name);
if (empty($clean_name)) {
    $clean_name = $file_name;
}

$file_extension = strtolower(pathinfo($clean_name, PATHINFO_EXTENSION));

$content_types = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'txt' => 'text/plain',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'zip' => 'application/zip',
    'rar' => 'application/x-rar-compressed'
];

$content_type = isset($content_types[$file_extension]) ? $content_types[$file_extension] : 'application/octet-stream';

if (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . $clean_name . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: private, must-revalidate');
header('Pragma: private');
header('Expires: 0');
header('Connection: close');

readfile($file_path);
exit();
?>
