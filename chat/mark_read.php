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
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $conversation_id = $input['conversation_id'] ?? null;
    
    if (!$conversation_id) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
        exit();
    }
    
    // Verify user has access to this conversation
    $access_query = "
        SELECT 1 FROM chat_conversations 
        WHERE id = :conversation_id 
        AND (user_id = :user_id OR support_agent_id = :user_id)
    ";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':conversation_id', $conversation_id);
    $access_stmt->bindParam(':user_id', $user_id);
    $access_stmt->execute();
    
    if (!$access_stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
    
    // Mark messages as read (except user's own messages)
    $mark_read_query = "
        UPDATE chat_messages 
        SET is_read = TRUE 
        WHERE conversation_id = :conversation_id 
        AND sender_id != :user_id 
        AND is_read = FALSE
    ";
    $mark_read_stmt = $db->prepare($mark_read_query);
    $mark_read_stmt->bindParam(':conversation_id', $conversation_id);
    $mark_read_stmt->bindParam(':user_id', $user_id);
    $mark_read_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Messages marked as read'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
