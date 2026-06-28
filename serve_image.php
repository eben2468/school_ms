<?php
session_start();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("HTTP/1.0 403 Forbidden");
    exit();
}

// Get the requested image path
$image_path = $_GET['path'] ?? '';

// Security: only allow known image sub-folders and validate the filename.
if (empty($image_path) || !preg_match('/^(profile_pictures|signatures|logos|book_covers)\/[a-zA-Z0-9_.-]+\.(jpg|jpeg|png|gif)$/i', $image_path)) {
    header("HTTP/1.0 404 Not Found");
    exit();
}
// Disallow any path traversal that slipped past the pattern.
if (strpos($image_path, '..') !== false) {
    header("HTTP/1.0 404 Not Found");
    exit();
}

// Full path to the image
$full_path = __DIR__ . '/uploads/' . $image_path;

// Check if file exists
if (!file_exists($full_path) || !is_file($full_path)) {
    header("HTTP/1.0 404 Not Found");
    exit();
}

// Get file info
$file_info = pathinfo($full_path);
$extension = strtolower($file_info['extension']);

// Set appropriate content type
switch ($extension) {
    case 'jpg':
    case 'jpeg':
        $content_type = 'image/jpeg';
        break;
    case 'png':
        $content_type = 'image/png';
        break;
    case 'gif':
        $content_type = 'image/gif';
        break;
    default:
        header("HTTP/1.0 403 Forbidden");
        exit();
}

// Set headers
header('Content-Type: ' . $content_type);
header('Content-Length: ' . filesize($full_path));
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 3600) . ' GMT');

// Output the image
readfile($full_path);
exit();
?>
