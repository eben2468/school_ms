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
    $subject = $input['subject'] ?? '';
    $priority = $input['priority'] ?? 'medium';
    $initial_message = $input['initial_message'] ?? '';
    
    if (!$subject) {
        echo json_encode(['success' => false, 'message' => 'Subject is required']);
        exit();
    }
    
    // Start transaction
    $db->beginTransaction();
    
    // Create conversation
    $conv_query = "
        INSERT INTO chat_conversations (user_id, subject, priority, status, created_at, updated_at)
        VALUES (:user_id, :subject, :priority, 'open', NOW(), NOW())
    ";
    $conv_stmt = $db->prepare($conv_query);
    $conv_stmt->bindParam(':user_id', $user_id);
    $conv_stmt->bindParam(':subject', $subject);
    $conv_stmt->bindParam(':priority', $priority);
    $conv_stmt->execute();
    
    $conversation_id = $db->lastInsertId();
    
    // Add initial message if provided
    if ($initial_message) {
        $msg_query = "
            INSERT INTO chat_messages (conversation_id, sender_id, message, message_type, created_at)
            VALUES (:conversation_id, :sender_id, :message, 'text', NOW())
        ";
        $msg_stmt = $db->prepare($msg_query);
        $msg_stmt->bindParam(':conversation_id', $conversation_id);
        $msg_stmt->bindParam(':sender_id', $user_id);
        $msg_stmt->bindParam(':message', $initial_message);
        $msg_stmt->execute();
    }
    
    // Add system welcome message
    $welcome_message = "Hello! Thank you for contacting support. A support agent will be with you shortly. Please describe your issue in detail so we can assist you better.";
    $system_msg_query = "
        INSERT INTO chat_messages (conversation_id, sender_id, message, message_type, created_at)
        VALUES (:conversation_id, 1, :message, 'system', NOW())
    ";
    $system_msg_stmt = $db->prepare($system_msg_query);
    $system_msg_stmt->bindParam(':conversation_id', $conversation_id);
    $system_msg_stmt->bindParam(':message', $welcome_message);
    $system_msg_stmt->execute();
    
    // Commit transaction
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Conversation created successfully',
        'conversation_id' => $conversation_id
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
