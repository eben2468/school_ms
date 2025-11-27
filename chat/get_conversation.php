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
    $conversation_id = $_GET['id'] ?? null;
    
    if (!$conversation_id) {
        echo json_encode(['success' => false, 'message' => 'Conversation ID is required']);
        exit();
    }
    
    // Get conversation details
    $conv_query = "
        SELECT cc.*, u.name as user_name, sa.name as support_agent_name
        FROM chat_conversations cc
        LEFT JOIN users u ON cc.user_id = u.id
        LEFT JOIN users sa ON cc.support_agent_id = sa.id
        WHERE cc.id = :conversation_id 
        AND (cc.user_id = :user_id OR cc.support_agent_id = :user_id)
    ";
    $conv_stmt = $db->prepare($conv_query);
    $conv_stmt->bindParam(':conversation_id', $conversation_id);
    $conv_stmt->bindParam(':user_id', $user_id);
    $conv_stmt->execute();
    $conversation = $conv_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$conversation) {
        echo json_encode(['success' => false, 'message' => 'Conversation not found or access denied']);
        exit();
    }
    
    echo json_encode([
        'success' => true,
        'conversation' => $conversation
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
