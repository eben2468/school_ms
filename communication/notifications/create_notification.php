<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $created_by = $_SESSION['user_id'];
    $role = $_SESSION['role'];
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    $required_fields = ['title', 'message', 'type'];
    foreach ($required_fields as $field) {
        if (!isset($input[$field]) || empty(trim($input[$field]))) {
            echo json_encode(['success' => false, 'message' => "Field '$field' is required"]);
            exit();
        }
    }
    
    // Extract and validate data
    $user_id = $input['user_id'] ?? null; // null for global notifications
    $title = trim($input['title']);
    $message = trim($input['message']);
    $type = $input['type'];
    $priority = $input['priority'] ?? 'medium';
    $icon = $input['icon'] ?? 'fas fa-bell';
    $action_url = $input['action_url'] ?? null;
    $action_text = $input['action_text'] ?? null;
    $expires_at = $input['expires_at'] ?? null;
    $metadata = $input['metadata'] ?? null;
    
    // Validate type
    $valid_types = ['academic', 'finance', 'system', 'announcement', 'general', 'attendance', 'grades', 'events', 'library', 'transport', 'hostel', 'canteen', 'health'];
    if (!in_array($type, $valid_types)) {
        echo json_encode(['success' => false, 'message' => 'Invalid notification type']);
        exit();
    }
    
    // Validate priority
    $valid_priorities = ['low', 'medium', 'high', 'urgent'];
    if (!in_array($priority, $valid_priorities)) {
        echo json_encode(['success' => false, 'message' => 'Invalid priority level']);
        exit();
    }
    
    // Check permissions for global notifications
    if ($user_id === null && !in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        echo json_encode(['success' => false, 'message' => 'Insufficient permissions for global notifications']);
        exit();
    }
    
    // Validate target user exists if specified
    if ($user_id !== null) {
        $user_check_query = "SELECT id FROM users WHERE id = :user_id";
        $user_check_stmt = $db->prepare($user_check_query);
        $user_check_stmt->bindParam(':user_id', $user_id);
        $user_check_stmt->execute();
        
        if (!$user_check_stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Target user not found']);
            exit();
        }
    }
    
    // Validate expires_at format if provided
    if ($expires_at && !strtotime($expires_at)) {
        echo json_encode(['success' => false, 'message' => 'Invalid expiration date format']);
        exit();
    }
    
    // Encode metadata as JSON if provided
    if ($metadata && is_array($metadata)) {
        $metadata = json_encode($metadata);
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Insert notification
    $insert_query = "
        INSERT INTO notifications 
        (user_id, title, message, type, priority, icon, action_url, action_text, expires_at, metadata, created_by)
        VALUES (:user_id, :title, :message, :type, :priority, :icon, :action_url, :action_text, :expires_at, :metadata, :created_by)
    ";
    
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':user_id', $user_id);
    $insert_stmt->bindParam(':title', $title);
    $insert_stmt->bindParam(':message', $message);
    $insert_stmt->bindParam(':type', $type);
    $insert_stmt->bindParam(':priority', $priority);
    $insert_stmt->bindParam(':icon', $icon);
    $insert_stmt->bindParam(':action_url', $action_url);
    $insert_stmt->bindParam(':action_text', $action_text);
    $insert_stmt->bindParam(':expires_at', $expires_at);
    $insert_stmt->bindParam(':metadata', $metadata);
    $insert_stmt->bindParam(':created_by', $created_by);
    $insert_stmt->execute();
    
    $notification_id = $db->lastInsertId();
    
    // Log the creation
    $log_query = "
        INSERT INTO notification_logs (notification_id, action, user_id, ip_address, user_agent)
        VALUES (:notification_id, 'created', :user_id, :ip_address, :user_agent)
    ";
    $log_stmt = $db->prepare($log_query);
    $log_stmt->bindParam(':notification_id', $notification_id);
    $log_stmt->bindParam(':user_id', $created_by);
    $log_stmt->bindParam(':ip_address', $_SERVER['REMOTE_ADDR'] ?? null);
    $log_stmt->bindParam(':user_agent', $_SERVER['HTTP_USER_AGENT'] ?? null);
    $log_stmt->execute();
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Notification created successfully',
        'notification_id' => $notification_id,
        'is_global' => $user_id === null
    ]);
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollback();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
