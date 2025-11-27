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
    $conversation_id = $_GET['conversation_id'] ?? null;
    
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
    
    // Get messages
    $messages_query = "
        SELECT cm.*, u.name as sender_name
        FROM chat_messages cm
        LEFT JOIN users u ON cm.sender_id = u.id
        WHERE cm.conversation_id = :conversation_id
        ORDER BY cm.created_at ASC
    ";
    $messages_stmt = $db->prepare($messages_query);
    $messages_stmt->bindParam(':conversation_id', $conversation_id);
    $messages_stmt->execute();
    $messages = $messages_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'messages' => $messages
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
