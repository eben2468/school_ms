<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    $document_id = $_GET['id'] ?? null;
    
    if (!$document_id) {
        header("HTTP/1.0 404 Not Found");
        echo "Document not found";
        exit();
    }
    
    // Get document details
    $query = "
        SELECT du.*, u.name as uploader_name
        FROM document_uploads du
        LEFT JOIN users u ON du.uploaded_by = u.id
        WHERE du.id = :document_id
    ";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':document_id', $document_id);
    $stmt->execute();
    $document = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$document) {
        header("HTTP/1.0 404 Not Found");
        echo "Document not found";
        exit();
    }
    
    // Check access permissions
    $has_access = false;
    
    // Check if user uploaded the document
    if ($document['uploaded_by'] == $user_id) {
        $has_access = true;
    }
    
    // Check access level
    switch ($document['access_level']) {
        case 'public':
            $has_access = true;
            break;
        case 'staff':
            if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
                $has_access = true;
            }
            break;
        case 'students':
            if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
                $has_access = true;
            }
            break;
        case 'parents':
            if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'parent'])) {
                $has_access = true;
            }
            break;
    }
    
    // Check if document is shared with user
    if (!$has_access) {
        $share_query = "
            SELECT 1 FROM shared_documents 
            WHERE document_id = :document_id 
            AND (shared_with = :user_id OR shared_with_role = :role)
        ";
        $share_stmt = $db->prepare($share_query);
        $share_stmt->bindParam(':document_id', $document_id);
        $share_stmt->bindParam(':user_id', $user_id);
        $share_stmt->bindParam(':role', $role);
        $share_stmt->execute();
        
        if ($share_stmt->fetch()) {
            $has_access = true;
        }
    }
    
    if (!$has_access) {
        header("HTTP/1.0 403 Forbidden");
        echo "Access denied";
        exit();
    }
    
    // Build file path
    $file_path = '../' . $document['file_path'];
    
    if (!file_exists($file_path)) {
        header("HTTP/1.0 404 Not Found");
        echo "File not found on server";
        exit();
    }
    
    // Update download count
    $update_query = "UPDATE document_uploads SET download_count = download_count + 1 WHERE id = :document_id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':document_id', $document_id);
    $update_stmt->execute();
    
    // Set headers for file download
    $file_size = filesize($file_path);
    $file_name = $document['file_name'];
    
    // Determine MIME type
    $mime_types = [
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain'
    ];
    
    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    $mime_type = $mime_types[$file_extension] ?? 'application/octet-stream';
    
    // Clear any previous output
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    // Set headers
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: attachment; filename="' . $file_name . '"');
    header('Content-Length: ' . $file_size);
    header('Cache-Control: private, must-revalidate');
    header('Pragma: private');
    header('Expires: 0');
    
    // Output file
    readfile($file_path);
    exit();
    
} catch (PDOException $e) {
    header("HTTP/1.0 500 Internal Server Error");
    echo "Database error: " . $e->getMessage();
    exit();
} catch (Exception $e) {
    header("HTTP/1.0 500 Internal Server Error");
    echo "Server error: " . $e->getMessage();
    exit();
}
?>
