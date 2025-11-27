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
    $message = $input['message'] ?? '';
    
    if (!$conversation_id || !$message) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID and message are required']);
        exit();
    }
    
    // Verify user has access to this conversation
    $access_query = "
        SELECT status FROM chat_conversations 
        WHERE id = :conversation_id 
        AND (user_id = :user_id OR support_agent_id = :user_id)
    ";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':conversation_id', $conversation_id);
    $access_stmt->bindParam(':user_id', $user_id);
    $access_stmt->execute();
    $conversation = $access_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'message' => 'Access denied']);
        exit();
    }
    
    if ($conversation['status'] === 'closed') {
        echo json_encode(['success' => false, 'message' => 'Cannot send message to closed conversation']);
        exit();
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Insert message
    $msg_query = "
        INSERT INTO chat_messages (conversation_id, sender_id, message, message_type, created_at)
        VALUES (:conversation_id, :sender_id, :message, 'text', NOW())
    ";
    $msg_stmt = $db->prepare($msg_query);
    $msg_stmt->bindParam(':conversation_id', $conversation_id);
    $msg_stmt->bindParam(':sender_id', $user_id);
    $msg_stmt->bindParam(':message', $message);
    $msg_stmt->execute();
    
    // Update conversation timestamp and status
    $update_conv_query = "
        UPDATE chat_conversations 
        SET updated_at = NOW(),
            status = CASE 
                WHEN status = 'open' THEN 'in_progress'
                ELSE status
            END
        WHERE id = :conversation_id
    ";
    $update_conv_stmt = $db->prepare($update_conv_query);
    $update_conv_stmt->bindParam(':conversation_id', $conversation_id);
    $update_conv_stmt->execute();
    
    // Clear typing indicator
    $clear_typing_query = "
        DELETE FROM chat_typing 
        WHERE conversation_id = :conversation_id AND user_id = :user_id
    ";
    $clear_typing_stmt = $db->prepare($clear_typing_query);
    $clear_typing_stmt->bindParam(':conversation_id', $conversation_id);
    $clear_typing_stmt->bindParam(':user_id', $user_id);
    $clear_typing_stmt->execute();
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Message sent successfully'
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
