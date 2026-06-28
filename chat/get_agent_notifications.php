<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    ensureChatTables($db); // heal tenants that predate the chat module
    
    $user_id = $_SESSION['user_id'];
    
    // Get unassigned conversations
    $unassigned_query = "
        SELECT COUNT(*) as count
        FROM chat_conversations 
        WHERE support_agent_id IS NULL 
        AND status IN ('open', 'in_progress')
    ";
    $unassigned_stmt = $db->prepare($unassigned_query);
    $unassigned_stmt->execute();
    $unassigned_count = $unassigned_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get conversations assigned to this agent with unread messages
    $my_unread_query = "
        SELECT COUNT(DISTINCT cc.id) as count
        FROM chat_conversations cc
        INNER JOIN chat_messages cm ON cc.id = cm.conversation_id
        WHERE cc.support_agent_id = :user_id 
        AND cm.sender_id != :user_id 
        AND cm.is_read = FALSE
        AND cc.status IN ('open', 'in_progress')
    ";
    $my_unread_stmt = $db->prepare($my_unread_query);
    $my_unread_stmt->bindParam(':user_id', $user_id);
    $my_unread_stmt->execute();
    $my_unread_count = $my_unread_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get urgent conversations
    $urgent_query = "
        SELECT COUNT(*) as count
        FROM chat_conversations 
        WHERE priority = 'urgent' 
        AND status IN ('open', 'in_progress')
    ";
    $urgent_stmt = $db->prepare($urgent_query);
    $urgent_stmt->execute();
    $urgent_count = $urgent_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    // Get recent conversations (last 5 minutes)
    $recent_query = "
        SELECT cc.*, u.name as user_name
        FROM chat_conversations cc
        LEFT JOIN users u ON cc.user_id = u.id
        WHERE cc.created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        AND cc.status IN ('open', 'in_progress')
        ORDER BY cc.created_at DESC
        LIMIT 5
    ";
    $recent_stmt = $db->prepare($recent_query);
    $recent_stmt->execute();
    $recent_conversations = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'notifications' => [
            'unassigned_count' => $unassigned_count,
            'my_unread_count' => $my_unread_count,
            'urgent_count' => $urgent_count,
            'recent_conversations' => $recent_conversations
        ]
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
