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
    $request_id = $_GET['id'] ?? null;
    $preview = isset($_GET['preview']) && $_GET['preview'] == 1;
    
    if (!$request_id) {
        header("HTTP/1.0 400 Bad Request");
        echo "Missing request ID";
        exit();
    }
    
    // Fetch request details
    $req_query = "
        SELECT tr.*, u.name as student_name 
        FROM transcript_requests tr
        JOIN users u ON tr.student_id = u.id
        WHERE tr.id = :request_id
    ";
    $req_stmt = $db->prepare($req_query);
    $req_stmt->bindParam(':request_id', $request_id);
    $req_stmt->execute();
    $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        header("HTTP/1.0 404 Not Found");
        echo "Transcript request not found";
        exit();
    }
    
    // Check permission
    $has_access = false;
    if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        $has_access = true;
    } elseif ($request['requested_by'] == $user_id || $request['student_id'] == $user_id) {
        $has_access = true;
    } elseif ($role === 'parent') {
        // Check if student is parent's child
        $parent_query = "SELECT 1 FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
        $parent_stmt = $db->prepare($parent_query);
        $parent_stmt->bindParam(':parent_id', $user_id);
        $parent_stmt->bindParam(':student_id', $request['student_id']);
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
    
    if ($request['status'] !== 'ready' || !$request['generated_file_path']) {
        header("HTTP/1.0 400 Bad Request");
        echo "Transcript has not been generated or processed yet";
        exit();
    }
    
    $file_path = '../' . $request['generated_file_path'];
    
    if (!file_exists($file_path)) {
        header("HTTP/1.0 404 Not Found");
        echo "Transcript file not found on the server";
        exit();
    }
    
    // Log the download action
    try {
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Find if there is a document record for transcripts
        $doc_query = "SELECT id FROM documents WHERE file_path = :file_path LIMIT 1";
        $doc_stmt = $db->prepare($doc_query);
        $doc_stmt->bindParam(':file_path', $request['generated_file_path']);
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
        }
    } catch (PDOException $e) {
        error_log("Error logging transcript access: " . $e->getMessage());
    }
    
    // Serve file
    if ($preview) {
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('Content-Type: text/html; charset=utf-8');
        readfile($file_path);
    } else {
        $file_name = 'Transcript_' . str_replace(' ', '_', $request['student_name']) . '_' . $request_id . '.html';
        
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
