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
    $is_typing = $input['is_typing'] ?? false;
    
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
    
    if ($is_typing) {
        // Insert or update typing indicator
        $typing_query = "
            INSERT INTO chat_typing (conversation_id, user_id, is_typing, updated_at)
            VALUES (:conversation_id, :user_id, TRUE, NOW())
            ON DUPLICATE KEY UPDATE is_typing = TRUE, updated_at = NOW()
        ";
        $typing_stmt = $db->prepare($typing_query);
        $typing_stmt->bindParam(':conversation_id', $conversation_id);
        $typing_stmt->bindParam(':user_id', $user_id);
        $typing_stmt->execute();
    } else {
        // Remove typing indicator
        $remove_typing_query = "
            DELETE FROM chat_typing 
            WHERE conversation_id = :conversation_id AND user_id = :user_id
        ";
        $remove_typing_stmt = $db->prepare($remove_typing_query);
        $remove_typing_stmt->bindParam(':conversation_id', $conversation_id);
        $remove_typing_stmt->bindParam(':user_id', $user_id);
        $remove_typing_stmt->execute();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Typing status updated'
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
