<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $user_id = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    
    // Check if user has permission to upload
    if (!in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        echo json_encode(['success' => false, 'message' => 'You do not have permission to upload documents']);
        exit();
    }
    
    // Handle file uploads
    if (!isset($_FILES['files']) && !isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'message' => 'No files uploaded']);
        exit();
    }
    
    // Get form data
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $document_type = $_POST['document_type'] ?? 'other';
    $access_level = $_POST['access_level'] ?? 'private';
    
    // Create uploads directory if it doesn't exist
    $upload_dir = '../uploads/documents/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $uploaded_files = [];
    $errors = [];
    
    // Handle multiple files or single file
    $files = $_FILES['files'] ?? [$_FILES['file']];
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type']],
            'tmp_name' => [$files['tmp_name']],
            'error' => [$files['error']],
            'size' => [$files['size']]
        ];
    }
    
    for ($i = 0; $i < count($files['name']); $i++) {
        $file_name = $files['name'][$i];
        $file_type = $files['type'][$i];
        $file_tmp = $files['tmp_name'][$i];
        $file_error = $files['error'][$i];
        $file_size = $files['size'][$i];
        
        // Skip if no file
        if ($file_error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        
        // Check for upload errors
        if ($file_error !== UPLOAD_ERR_OK) {
            $errors[] = "Upload error for file: $file_name";
            continue;
        }
        
        // Validate file size (max 10MB)
        if ($file_size > 10 * 1024 * 1024) {
            $errors[] = "File too large: $file_name (max 10MB)";
            continue;
        }
        
        // Validate file type
        $allowed_types = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'image/jpeg',
            'image/png',
            'image/gif',
            'text/plain'
        ];
        
        if (!in_array($file_type, $allowed_types)) {
            $errors[] = "Invalid file type: $file_name";
            continue;
        }
        
        // Generate unique filename
        $file_extension = pathinfo($file_name, PATHINFO_EXTENSION);
        $unique_name = uniqid() . '_' . time() . '.' . $file_extension;
        $file_path = $upload_dir . $unique_name;
        
        // Move uploaded file
        if (move_uploaded_file($file_tmp, $file_path)) {
            // Insert into database
            $insert_query = "
                INSERT INTO documents 
                (title, description, file_name, file_path, file_type, file_size, document_type, uploaded_by, access_level, created_at)
                VALUES (:title, :description, :file_name, :file_path, :file_type, :file_size, :document_type, :uploaded_by, :access_level, NOW())
            ";
            
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':title', $title ?: $file_name);
            $insert_stmt->bindParam(':description', $description);
            $insert_stmt->bindParam(':file_name', $file_name);
            $insert_stmt->bindParam(':file_path', 'uploads/documents/' . $unique_name);
            $insert_stmt->bindParam(':file_type', $file_extension);
            $insert_stmt->bindParam(':file_size', $file_size);
            $insert_stmt->bindParam(':document_type', $document_type);
            $insert_stmt->bindParam(':uploaded_by', $user_id);
            $insert_stmt->bindParam(':access_level', $access_level);
            
            if ($insert_stmt->execute()) {
                $uploaded_files[] = [
                    'id' => $db->lastInsertId(),
                    'name' => $file_name,
                    'size' => $file_size
                ];
            } else {
                $errors[] = "Database error for file: $file_name";
                unlink($file_path); // Remove uploaded file if database insert fails
            }
        } else {
            $errors[] = "Failed to move uploaded file: $file_name";
        }
    }
    
    if (!empty($uploaded_files)) {
        echo json_encode([
            'success' => true,
            'message' => count($uploaded_files) . ' file(s) uploaded successfully',
            'files' => $uploaded_files,
            'errors' => $errors
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No files were uploaded successfully',
            'errors' => $errors
        ]);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
