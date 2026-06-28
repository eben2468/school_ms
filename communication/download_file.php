<?php
session_start();

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access denied.");
}

$file = $_GET['file'] ?? '';
if (empty($file)) {
    header("HTTP/1.0 400 Bad Request");
    exit("File parameter is required.");
}

// Security: Prevent directory traversal
$file = str_replace(array('..', '\\'), '', $file);

// Ensure file resides in either uploads/chat_files/ or uploads/profile_pictures/
$allowed_subdirs = ['chat_files', 'profile_pictures'];
$parts = explode('/', $file);
$subdir = $parts[0] ?? '';

if (!in_array($subdir, $allowed_subdirs)) {
    header("HTTP/1.0 403 Forbidden");
    exit("Access denied for this directory.");
}

$basename = basename($file);
$full_path = realpath(__DIR__ . '/../uploads/' . $subdir . '/' . $basename);
$allowed_dir = realpath(__DIR__ . '/../uploads/' . $subdir);

if (!$full_path || strpos($full_path, $allowed_dir) !== 0 || !is_file($full_path)) {
    header("HTTP/1.0 404 Not Found");
    exit("File not found.");
}

// Get file mime type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $full_path);
finfo_close($finfo);

// Fallback mime type if not detected
if (!$mime_type) {
    $mime_type = 'application/octet-stream';
}

// Determine original filename if it's a chat file
$download_name = $basename;
if ($subdir === 'chat_files') {
    try {
        require_once '../config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
        $query = "SELECT file_name FROM live_chat_messages WHERE file_path = :file_path LIMIT 1";
        $stmt = $db->prepare($query);
        $db_path = 'chat_files/' . $basename;
        $stmt->bindParam(':file_path', $db_path);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['file_name'])) {
            $download_name = $row['file_name'];
        }
    } catch (Exception $e) {
        // Ignore database errors, fall back to basename
    }
}

// Set headers
header('Content-Type: ' . $mime_type);
header('Content-Length: ' . filesize($full_path));

// If it's an image, render it inline; otherwise, download as attachment
if (strpos($mime_type, 'image/') === 0) {
    header('Content-Disposition: inline; filename="' . basename($download_name) . '"');
    header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
} else {
    // Force download
    header('Content-Disposition: attachment; filename="' . basename($download_name) . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
}

// Output file content
readfile($full_path);
exit();
?>
