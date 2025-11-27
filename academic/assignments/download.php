<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    http_response_code(403);
    die('Access denied. Please log in to download files.');
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$assignment_id = filter_input(INPUT_GET, 'assignment_id', FILTER_SANITIZE_NUMBER_INT);
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!$assignment_id) {
    http_response_code(400);
    die('Invalid request. Assignment ID is required.');
}

// Get assignment details and verify access
$query = "SELECT a.*, s.name as subject_name, c.name as class_name 
          FROM assignments a
          JOIN subjects s ON a.subject_id = s.id
          JOIN classes c ON a.class_id = c.id
          WHERE a.id = :assignment_id";

// Add permission check for students
if ($user_role === 'student') {
    $query .= " AND a.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :user_id AND status = 'active')";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':assignment_id', $assignment_id);
if ($user_role === 'student') {
    $stmt->bindParam(':user_id', $user_id);
}
$stmt->execute();
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    http_response_code(404);
    die('Assignment not found or you do not have permission to access this file.');
}

// Check if assignment has an attachment
if (empty($assignment['attachment_path']) || empty($assignment['attachment_name'])) {
    http_response_code(404);
    die('No attachment found for this assignment.');
}

// Build the full file path
$file_path = '../../' . $assignment['attachment_path'];

// Verify file exists
if (!file_exists($file_path)) {
    http_response_code(404);
    die('File not found on server. The file may have been moved or deleted.');
}

// Get file information
$file_size = filesize($file_path);
$file_name = $assignment['attachment_name'];
$file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

// Set appropriate content type
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

// Clear any previous output
if (ob_get_level()) {
    ob_end_clean();
}

// Set headers for file download
header('Content-Type: ' . $content_type);
header('Content-Disposition: attachment; filename="' . $file_name . '"');
header('Content-Length: ' . $file_size);
header('Cache-Control: private, must-revalidate');
header('Pragma: private');
header('Expires: 0');

// Prevent any additional output
header('Connection: close');

// Output the file
readfile($file_path);
exit();
?>
