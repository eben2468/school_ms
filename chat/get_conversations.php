<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
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
    $role = $_SESSION['role'];
    
    // Build query based on user role
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Support agents can see all conversations
        $query = "
            SELECT cc.*, 
                   u.name as user_name, 
                   u.role as user_role,
                   sa.name as support_agent_name,
                   (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.id AND cm.sender_id != :user_id AND cm.is_read = FALSE) as unread_count,
                   (SELECT cm.message FROM chat_messages cm WHERE cm.conversation_id = cc.id ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                   (SELECT cm.created_at FROM chat_messages cm WHERE cm.conversation_id = cc.id ORDER BY cm.created_at DESC LIMIT 1) as last_message_time
            FROM chat_conversations cc
            LEFT JOIN users u ON cc.user_id = u.id
            LEFT JOIN users sa ON cc.support_agent_id = sa.id
            ORDER BY cc.updated_at DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
    } else {
        // Regular users can only see their own conversations
        $query = "
            SELECT cc.*, 
                   u.name as user_name, 
                   u.role as user_role,
                   sa.name as support_agent_name,
                   (SELECT COUNT(*) FROM chat_messages cm WHERE cm.conversation_id = cc.id AND cm.sender_id != :user_id AND cm.is_read = FALSE) as unread_count,
                   (SELECT cm.message FROM chat_messages cm WHERE cm.conversation_id = cc.id ORDER BY cm.created_at DESC LIMIT 1) as last_message,
                   (SELECT cm.created_at FROM chat_messages cm WHERE cm.conversation_id = cc.id ORDER BY cm.created_at DESC LIMIT 1) as last_message_time
            FROM chat_conversations cc
            LEFT JOIN users u ON cc.user_id = u.id
            LEFT JOIN users sa ON cc.support_agent_id = sa.id
            WHERE cc.user_id = :user_id
            ORDER BY cc.updated_at DESC
        ";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
    }
    
    $stmt->execute();
    $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the conversations for better frontend handling
    foreach ($conversations as &$conversation) {
        $conversation['formatted_time'] = $conversation['last_message_time'] 
            ? date('M j, g:i A', strtotime($conversation['last_message_time']))
            : date('M j, g:i A', strtotime($conversation['created_at']));
        
        $conversation['priority_color'] = match($conversation['priority']) {
            'urgent' => 'red',
            'high' => 'orange', 
            'medium' => 'yellow',
            'low' => 'green',
            default => 'gray'
        };
        
        $conversation['status_color'] = match($conversation['status']) {
            'open' => 'green',
            'in_progress' => 'blue',
            'resolved' => 'gray',
            'closed' => 'red',
            default => 'gray'
        };
        
        // Truncate last message for display
        if ($conversation['last_message']) {
            $conversation['last_message_preview'] = strlen($conversation['last_message']) > 50 
                ? substr($conversation['last_message'], 0, 50) . '...'
                : $conversation['last_message'];
        } else {
            $conversation['last_message_preview'] = 'No messages yet';
        }
    }
    
    echo json_encode([
        'success' => true,
        'conversations' => $conversations,
        'user_role' => $role,
        'total_count' => count($conversations)
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
