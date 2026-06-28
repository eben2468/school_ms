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
    $certificate_id = $_GET['id'] ?? null;
    
    if (!$certificate_id) {
        header("HTTP/1.0 400 Bad Request");
        echo "Missing certificate ID";
        exit();
    }
    
    // Fetch certificate details
    $cert_query = "
        SELECT gc.*, u.name as student_name 
        FROM generated_certificates gc
        JOIN users u ON gc.student_id = u.id
        WHERE gc.id = :cert_id
    ";
    $cert_stmt = $db->prepare($cert_query);
    $cert_stmt->bindParam(':cert_id', $certificate_id);
    $cert_stmt->execute();
    $cert = $cert_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$cert) {
        header("HTTP/1.0 404 Not Found");
        echo "Certificate not found";
        exit();
    }
    
    // Check permission: admins, principal, teachers, or the student themselves, or the student's parent
    $has_access = false;
    if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        $has_access = true;
    } elseif ($cert['student_id'] == $user_id) {
        $has_access = true;
    } elseif ($role === 'parent') {
        // Check if student is parent's child
        $parent_query = "SELECT 1 FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':parent_id', $user_id);
        $parent_stmt->bindParam(':student_id', $cert['student_id']);
        $parent_stmt->execute();
        if ($parent_stmt->fetch()) {
            $has_access = true;
        }
    }
    
    if (!$has_access) {
        header("HTTP/1.0 403 Forbidden");
        echo "Access denied";
        exit();
    }
    
    $file_path = '../' . $cert['file_path'];
    
    if (!file_exists($file_path)) {
        header("HTTP/1.0 404 Not Found");
        echo "Certificate file not found on the server";
        exit();
    }
    
    // Log the download action in documents if possible
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Find if there is a document record
        $doc_query = "SELECT id FROM documents WHERE file_path = :file_path LIMIT 1";
        $doc_stmt = $db->prepare($doc_query);
        $doc_stmt->bindParam(':file_path', $cert['file_path']);
        $doc_stmt->execute();
        $doc_id = $doc_stmt->fetchColumn();
        
        if ($doc_id) {
            $log_query = "
                INSERT INTO document_access_logs (document_id, user_id, access_type, ip_address, user_agent, accessed_at)
                VALUES (:document_id, :user_id, 'download', :ip_address, :user_agent, NOW())
            ";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->bindParam(':document_id', $doc_id);
            $log_stmt->bindParam(':user_id', $user_id);
            $log_stmt->bindParam(':ip_address', $ip_address);
            $log_stmt->bindParam(':user_agent', $user_agent);
            $log_stmt->execute();
            
            // Increment download count
            $update_doc = "UPDATE documents SET download_count = download_count + 1 WHERE id = :doc_id";
            $update_stmt = $db->prepare($update_doc);
            $update_stmt->bindParam(':doc_id', $doc_id);
            $update_stmt->execute();
        }
    } catch (PDOException $e) {
        error_log("Error logging certificate access: " . $e->getMessage());
    }
    
    $preview = isset($_GET['preview']) && $_GET['preview'] == 1;
    
    if ($preview) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($file_path);
    } else {
        // Stream HTML file download
        $file_name = 'Certificate_' . str_replace(' ', '_', $cert['student_name']) . '_' . $certificate_id . '.html';
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, must-revalidate');
        header('Pragma: private');
        header('Expires: 0');
        
        readfile($file_path);
    }
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
