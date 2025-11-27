<?php
// Suppress any output that might interfere with JSON
ob_start();
error_reporting(0); // Suppress all errors to prevent HTML output
ini_set('display_errors', 0);

session_start();

// Clean any output buffer and set JSON header
ob_clean();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Capture any potential output from database connection
ob_start();
require_once '../config/database.php';
ob_end_clean(); // Discard any output from database.php
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {
        case 'get_messages':
            handleGetMessages($db, $user_id);
            break;
            
        case 'send_message':
            handleSendMessage($db, $user_id);
            break;
            
        case 'join_room':
            handleJoinRoom($db, $user_id);
            break;
            
        case 'update_status':
            handleUpdateStatus($db, $user_id);
            break;
            
        case 'typing':
            handleTyping($db, $user_id);
            break;
            
        case 'upload_file':
            handleFileUpload($db, $user_id);
            break;
            
        case 'get_room_info':
            handleGetRoomInfo($db, $user_id);
            break;
            
        case 'react_to_message':
            handleMessageReaction($db, $user_id);
            break;
            
        case 'mark_messages_read':
            handleMarkMessagesRead($db, $user_id);
            break;

        case 'search_messages':
            handleSearchMessages($db, $user_id);
            break;

        case 'submit_report':
            handleSubmitReport($db, $user_id);
            break;

        case 'block_user':
            handleBlockUser($db, $user_id);
            break;

        case 'unblock_user':
            handleUnblockUser($db, $user_id);
            break;

        case 'bulk_delete_messages':
            handleBulkDeleteMessages($db, $user_id);
            break;

        case 'export_messages':
            handleExportMessages($db, $user_id);
            break;

        case 'export_chat_history':
            handleExportChatHistory($db, $user_id);
            break;

        case 'advanced_search':
            handleAdvancedSearch($db, $user_id);
            break;

        case 'get_room_users':
            handleGetRoomUsers($db, $user_id);
            break;

        case 'get_thread_messages':
            handleGetThreadMessages($db, $user_id);
            break;

        case 'send_thread_reply':
            handleSendThreadReply($db, $user_id);
            break;

        case 'debug_user_access':
            handleDebugUserAccess($db, $user_id);
            break;

        case 'get_online_users':
            handleGetOnlineUsers($db, $user_id);
            break;

        case 'get_rooms':
            handleGetRooms($db, $user_id);
            break;

        case 'test':
            echo json_encode(['success' => true, 'message' => 'API is working', 'user_id' => $user_id, 'timestamp' => date('Y-m-d H:i:s')]);
            break;

        default:
            throw new Exception('Invalid action: ' . $action);
    }
} catch (Exception $e) {
    // Ensure proper JSON response
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleGetMessages($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;
    $last_id = $_GET['last_id'] ?? 0;
    $limit = min($_GET['limit'] ?? 50, 100); // Max 100 messages
    
    if (!$room_id) {
        throw new Exception('Room ID required');
    }
    
    // Check if user has access to this room
    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }
    
    $query = "
        SELECT m.*, u.name as sender_name, u.profile_picture as sender_avatar,
               reply_msg.message as reply_to_message, reply_user.name as reply_to_sender_name
        FROM live_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN live_chat_messages reply_msg ON m.reply_to_message_id = reply_msg.id
        LEFT JOIN users reply_user ON reply_msg.sender_id = reply_user.id
        WHERE m.room_id = :room_id
        AND m.is_deleted = FALSE
        AND m.id > :last_id
        ORDER BY m.created_at ASC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
    $stmt->bindParam(':last_id', $last_id, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Add reactions to each message
    foreach ($messages as &$message) {
        $reactions_query = "
            SELECT reaction_emoji, COUNT(*) as count,
                   GROUP_CONCAT(u.name SEPARATOR ', ') as user_names
            FROM live_chat_message_reactions r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.message_id = :message_id
            GROUP BY reaction_emoji
        ";

        $reactions_stmt = $db->prepare($reactions_query);
        $reactions_stmt->bindParam(':message_id', $message['id']);
        $reactions_stmt->execute();

        $message['reactions'] = $reactions_stmt->fetchAll(PDO::FETCH_ASSOC);
        $message['reaction_count'] = array_sum(array_column($message['reactions'], 'count'));
    }

    // Mark messages as read
    if (!empty($messages)) {
        markMessagesAsRead($db, $user_id, array_column($messages, 'id'));
    }

    echo json_encode(['success' => true, 'messages' => $messages]);
}

function handleSendMessage($db, $user_id) {
    $room_id = $_POST['room_id'] ?? 0;
    $message = trim($_POST['message'] ?? '');
    $reply_to = $_POST['reply_to'] ?? null;
    $is_encrypted = $_POST['is_encrypted'] ?? '0';

    if (!$room_id || !$message) {
        throw new Exception('Room ID and message are required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    if (isUserMuted($db, $user_id, $room_id)) {
        throw new Exception('You are muted in this room');
    }

    // Insert message
    if ($reply_to) {
        $query = "
            INSERT INTO live_chat_messages (room_id, sender_id, message, reply_to_message_id, created_at)
            VALUES (:room_id, :sender_id, :message, :reply_to, NOW())
        ";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
        $stmt->bindParam(':sender_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->bindParam(':reply_to', $reply_to, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $query = "
            INSERT INTO live_chat_messages (room_id, sender_id, message, created_at)
            VALUES (:room_id, :sender_id, :message, NOW())
        ";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
        $stmt->bindParam(':sender_id', $user_id, PDO::PARAM_INT);
        $stmt->bindParam(':message', $message, PDO::PARAM_STR);
        $stmt->execute();
    }

    $message_id = $db->lastInsertId();

    // Update room's last activity
    updateRoomActivity($db, $room_id);

    echo json_encode(['success' => true, 'message_id' => $message_id]);
}

function handleJoinRoom($db, $user_id) {
    $room_id = $_POST['room_id'] ?? 0;
    
    if (!$room_id) {
        throw new Exception('Room ID required');
    }
    
    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }
    
    // Update or insert participant record
    $query = "
        INSERT INTO live_chat_participants (room_id, user_id, last_seen)
        VALUES (:room_id, :user_id, NOW())
        ON DUPLICATE KEY UPDATE last_seen = NOW()
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}

function handleUpdateStatus($db, $user_id) {
    $status = $_POST['status'] ?? 'online';
    $custom_status = $_POST['custom_status'] ?? null;
    
    $valid_statuses = ['online', 'away', 'busy', 'offline'];
    if (!in_array($status, $valid_statuses)) {
        throw new Exception('Invalid status');
    }
    
    $query = "
        INSERT INTO live_chat_user_status (user_id, status, custom_status, last_activity)
        VALUES (:user_id, :status, :custom_status, NOW())
        ON DUPLICATE KEY UPDATE 
            status = VALUES(status),
            custom_status = VALUES(custom_status),
            last_activity = NOW()
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':custom_status', $custom_status);
    $stmt->execute();
    
    echo json_encode(['success' => true]);
}

function handleTyping($db, $user_id) {
    $room_id = $_POST['room_id'] ?? 0;
    $is_typing = $_POST['typing'] === 'true';
    
    if (!$room_id) {
        throw new Exception('Room ID required');
    }
    
    if ($is_typing) {
        $query = "
            INSERT INTO live_chat_user_status (user_id, room_id, is_typing, typing_in_room_id, last_activity)
            VALUES (:user_id, :room_id, TRUE, :room_id, NOW())
            ON DUPLICATE KEY UPDATE 
                is_typing = TRUE,
                typing_in_room_id = :room_id2,
                last_activity = NOW()
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':room_id', $room_id);
        $stmt->bindParam(':room_id2', $room_id);
        $stmt->execute();
    } else {
        $query = "
            UPDATE live_chat_user_status 
            SET is_typing = FALSE, typing_in_room_id = NULL, last_activity = NOW()
            WHERE user_id = :user_id
        ";
        
        $stmt = $db->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
    }
    
    echo json_encode(['success' => true]);
}

function handleFileUpload($db, $user_id) {
    $room_id = $_POST['room_id'] ?? 0;

    if (!$room_id) {
        throw new Exception('Room ID required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'File too large (server limit)',
            UPLOAD_ERR_FORM_SIZE => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL => 'File upload incomplete',
            UPLOAD_ERR_NO_FILE => 'No file uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'No temporary directory',
            UPLOAD_ERR_CANT_WRITE => 'Cannot write file',
            UPLOAD_ERR_EXTENSION => 'Upload stopped by extension'
        ];

        $error_code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
        $error_message = $error_messages[$error_code] ?? 'File upload failed';
        throw new Exception($error_message);
    }

    $file = $_FILES['file'];
    $allowed_types = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf', 'text/plain', 'text/csv',
        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip', 'application/x-rar-compressed'
    ];
    $max_size = 10 * 1024 * 1024; // 10MB

    if (!in_array($file['type'], $allowed_types)) {
        throw new Exception('File type not allowed. Supported types: images, PDF, documents, and archives.');
    }

    if ($file['size'] > $max_size) {
        throw new Exception('File too large (max 10MB)');
    }

    // Create upload directory if it doesn't exist
    $upload_dir = '../uploads/chat_files/';
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Generate unique filename
    $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safe_filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename = $safe_filename . '_' . uniqid() . '_' . time() . '.' . $file_extension;
    $file_path = $upload_dir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Failed to save file to server');
    }

    // Insert file message
    $message_type = strpos($file['type'], 'image/') === 0 ? 'image' : 'file';
    $message = $message_type === 'image' ? '[Image: ' . $file['name'] . ']' : '[File: ' . $file['name'] . ']';

    $query = "
        INSERT INTO live_chat_messages (room_id, sender_id, message, message_type, file_path, file_name, file_size, created_at)
        VALUES (:room_id, :sender_id, :message, :message_type, :file_path, :file_name, :file_size, NOW())
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->bindParam(':sender_id', $user_id);
    $stmt->bindParam(':message', $message);
    $stmt->bindParam(':message_type', $message_type);
    $stmt->bindParam(':file_path', 'chat_files/' . $filename);
    $stmt->bindParam(':file_name', $file['name']);
    $stmt->bindParam(':file_size', $file['size']);
    $stmt->execute();

    // Update room activity
    updateRoomActivity($db, $room_id);

    echo json_encode([
        'success' => true,
        'message_id' => $db->lastInsertId(),
        'file_name' => $file['name'],
        'file_size' => $file['size'],
        'message_type' => $message_type
    ]);
}

function handleGetRoomInfo($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;
    
    if (!$room_id) {
        throw new Exception('Room ID required');
    }
    
    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }
    
    $query = "
        SELECT r.*, u.name as created_by_name,
               COUNT(p.user_id) as participant_count
        FROM live_chat_rooms r
        LEFT JOIN users u ON r.created_by = u.id
        LEFT JOIN live_chat_participants p ON r.id = p.room_id AND p.is_banned = FALSE
        WHERE r.id = :room_id
        GROUP BY r.id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();
    
    $room_info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$room_info) {
        throw new Exception('Room not found');
    }
    
    echo json_encode(['success' => true, 'room' => $room_info]);
}

function handleMessageReaction($db, $user_id) {
    $message_id = $_POST['message_id'] ?? 0;
    $emoji = $_POST['emoji'] ?? '';

    if (!$message_id || !$emoji) {
        throw new Exception('Message ID and emoji required');
    }

    // Ensure reactions table exists
    createReactionsTableIfNotExists($db);
    
    // Toggle reaction
    $check_query = "
        SELECT id FROM live_chat_message_reactions 
        WHERE message_id = :message_id AND user_id = :user_id AND reaction_emoji = :emoji
    ";
    
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':message_id', $message_id);
    $check_stmt->bindParam(':user_id', $user_id);
    $check_stmt->bindParam(':emoji', $emoji);
    $check_stmt->execute();
    
    if ($check_stmt->rowCount() > 0) {
        // Remove reaction
        $delete_query = "
            DELETE FROM live_chat_message_reactions 
            WHERE message_id = :message_id AND user_id = :user_id AND reaction_emoji = :emoji
        ";
        
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':message_id', $message_id);
        $delete_stmt->bindParam(':user_id', $user_id);
        $delete_stmt->bindParam(':emoji', $emoji);
        $delete_stmt->execute();
        
        echo json_encode(['success' => true, 'action' => 'removed']);
    } else {
        // Add reaction
        $insert_query = "
            INSERT INTO live_chat_message_reactions (message_id, user_id, reaction_emoji)
            VALUES (:message_id, :user_id, :emoji)
        ";
        
        $insert_stmt = $db->prepare($insert_query);
        $insert_stmt->bindParam(':message_id', $message_id);
        $insert_stmt->bindParam(':user_id', $user_id);
        $insert_stmt->bindParam(':emoji', $emoji);
        $insert_stmt->execute();
        
        echo json_encode(['success' => true, 'action' => 'added']);
    }
}

function handleMarkMessagesRead($db, $user_id) {
    $room_id = $_POST['room_id'] ?? 0;
    
    if (!$room_id) {
        throw new Exception('Room ID required');
    }
    
    // Get all unread messages in the room
    $messages_query = "
        SELECT id FROM live_chat_messages 
        WHERE room_id = :room_id 
        AND sender_id != :user_id
        AND id NOT IN (
            SELECT message_id FROM live_chat_message_reads WHERE user_id = :user_id2
        )
    ";
    
    $messages_stmt = $db->prepare($messages_query);
    $messages_stmt->bindParam(':room_id', $room_id);
    $messages_stmt->bindParam(':user_id', $user_id);
    $messages_stmt->bindParam(':user_id2', $user_id);
    $messages_stmt->execute();
    
    $message_ids = $messages_stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (!empty($message_ids)) {
        markMessagesAsRead($db, $user_id, $message_ids);
    }
    
    echo json_encode(['success' => true, 'marked_count' => count($message_ids)]);
}

// Helper functions
function hasRoomAccess($db, $user_id, $room_id) {
    $query = "
        SELECT r.room_type, p.user_id as is_participant
        FROM live_chat_rooms r
        LEFT JOIN live_chat_participants p ON r.id = p.room_id AND p.user_id = :user_id AND p.is_banned = FALSE
        WHERE r.id = :room_id AND r.is_active = TRUE
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return false;
    }
    
    // Public rooms are accessible to all
    if ($result['room_type'] === 'public') {
        // Auto-add user as participant if not already
        if ($result['is_participant'] === null) {
            try {
                $add_participant_query = "
                    INSERT IGNORE INTO live_chat_participants (room_id, user_id, joined_at)
                    VALUES (:room_id, :user_id, NOW())
                ";
                $add_stmt = $db->prepare($add_participant_query);
                $add_stmt->bindParam(':room_id', $room_id);
                $add_stmt->bindParam(':user_id', $user_id);
                $add_stmt->execute();
            } catch (Exception $e) {
                // Ignore errors, user can still access public rooms
            }
        }
        return true;
    }
    
    // Admin-only rooms require admin role
    if ($result['room_type'] === 'admin_only') {
        return in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal']);
    }
    
    // Private rooms require explicit participation
    return $result['is_participant'] !== null;
}

function isUserMuted($db, $user_id, $room_id) {
    $query = "
        SELECT is_muted FROM live_chat_participants 
        WHERE user_id = :user_id AND room_id = :room_id
    ";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result && $result['is_muted'];
}

function markMessagesAsRead($db, $user_id, $message_ids) {
    if (empty($message_ids)) return;
    
    $placeholders = str_repeat('?,', count($message_ids) - 1) . '?';
    $query = "
        INSERT IGNORE INTO live_chat_message_reads (message_id, user_id, read_at)
        SELECT id, ?, NOW() FROM live_chat_messages WHERE id IN ($placeholders)
    ";
    
    $stmt = $db->prepare($query);
    $params = array_merge([$user_id], $message_ids);
    $stmt->execute($params);
}

function updateRoomActivity($db, $room_id) {
    $query = "UPDATE live_chat_rooms SET updated_at = NOW() WHERE id = :room_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();
}

function createReactionsTableIfNotExists($db) {
    $query = "
        CREATE TABLE IF NOT EXISTS live_chat_message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_emoji VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_reaction (message_id, user_id, reaction_emoji),
            FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ";

    $db->exec($query);
}

function handleSearchMessages($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;
    $query = $_GET['query'] ?? '';
    $limit = min($_GET['limit'] ?? 50, 100);

    if (!$room_id || !$query) {
        throw new Exception('Room ID and search query required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    $search_query = "
        SELECT m.*, u.name as sender_name, u.profile_picture as sender_avatar
        FROM live_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE m.room_id = :room_id
        AND m.is_deleted = FALSE
        AND m.message LIKE :search_term
        ORDER BY m.created_at DESC
        LIMIT :limit
    ";

    $stmt = $db->prepare($search_query);
    $stmt->bindParam(':room_id', $room_id, PDO::PARAM_INT);
    $search_term = '%' . $query . '%';
    $stmt->bindParam(':search_term', $search_term);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'messages' => $messages]);
}

function handleSubmitReport($db, $user_id) {
    $reported_user_id = $_POST['reported_user_id'] ?? null;
    $message_id = $_POST['message_id'] ?? null;
    $room_id = $_POST['room_id'] ?? 0;
    $report_type = $_POST['report_type'] ?? '';
    $description = trim($_POST['description'] ?? '');

    if (!$room_id || !$report_type || !$description) {
        throw new Exception('Room ID, report type, and description are required');
    }

    $valid_types = ['spam', 'harassment', 'inappropriate_content', 'other'];
    if (!in_array($report_type, $valid_types)) {
        throw new Exception('Invalid report type');
    }

    $query = "
        INSERT INTO live_chat_reports (reporter_id, reported_user_id, message_id, room_id, report_type, description, created_at)
        VALUES (:reporter_id, :reported_user_id, :message_id, :room_id, :report_type, :description, NOW())
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':reporter_id', $user_id);
    $stmt->bindParam(':reported_user_id', $reported_user_id);
    $stmt->bindParam(':message_id', $message_id);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->bindParam(':report_type', $report_type);
    $stmt->bindParam(':description', $description);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Report submitted successfully']);
}

function handleBlockUser($db, $user_id) {
    $blocked_id = $_POST['blocked_id'] ?? 0;
    $reason = $_POST['reason'] ?? '';

    if (!$blocked_id) {
        throw new Exception('User ID to block is required');
    }

    if ($blocked_id == $user_id) {
        throw new Exception('Cannot block yourself');
    }

    $query = "
        INSERT INTO live_chat_blocked_users (blocker_id, blocked_id, reason, blocked_at)
        VALUES (:blocker_id, :blocked_id, :reason, NOW())
        ON DUPLICATE KEY UPDATE reason = VALUES(reason), blocked_at = NOW()
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':blocker_id', $user_id);
    $stmt->bindParam(':blocked_id', $blocked_id);
    $stmt->bindParam(':reason', $reason);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'User blocked successfully']);
}

function handleUnblockUser($db, $user_id) {
    $blocked_id = $_POST['blocked_id'] ?? 0;

    if (!$blocked_id) {
        throw new Exception('User ID to unblock is required');
    }

    $query = "DELETE FROM live_chat_blocked_users WHERE blocker_id = :blocker_id AND blocked_id = :blocked_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':blocker_id', $user_id);
    $stmt->bindParam(':blocked_id', $blocked_id);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'User unblocked successfully']);
}

function handleBulkDeleteMessages($db, $user_id) {
    $message_ids = $_POST['message_ids'] ?? '';

    if (!$message_ids) {
        throw new Exception('Message IDs required');
    }

    $ids = explode(',', $message_ids);
    $ids = array_filter(array_map('intval', $ids));

    if (empty($ids)) {
        throw new Exception('Valid message IDs required');
    }

    // Only allow users to delete their own messages or admins to delete any
    $is_admin = in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal']);

    if ($is_admin) {
        $query = "UPDATE live_chat_messages SET is_deleted = TRUE, deleted_at = NOW() WHERE id IN (" . str_repeat('?,', count($ids) - 1) . "?)";
    } else {
        $query = "UPDATE live_chat_messages SET is_deleted = TRUE, deleted_at = NOW() WHERE id IN (" . str_repeat('?,', count($ids) - 1) . "?) AND sender_id = ?";
        $ids[] = $user_id;
    }

    $stmt = $db->prepare($query);
    $stmt->execute($ids);

    $deleted_count = $stmt->rowCount();

    echo json_encode(['success' => true, 'deleted_count' => $deleted_count]);
}

function handleExportMessages($db, $user_id) {
    $message_ids = $_GET['message_ids'] ?? '';

    if (!$message_ids) {
        throw new Exception('Message IDs required');
    }

    $ids = explode(',', $message_ids);
    $ids = array_filter(array_map('intval', $ids));

    if (empty($ids)) {
        throw new Exception('Valid message IDs required');
    }

    $placeholders = str_repeat('?,', count($ids) - 1) . '?';
    $query = "
        SELECT m.*, u.name as sender_name, r.name as room_name
        FROM live_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN live_chat_rooms r ON m.room_id = r.id
        WHERE m.id IN ($placeholders)
        ORDER BY m.created_at ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->execute($ids);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV content
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="chat_messages_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Time', 'Room', 'Sender', 'Message', 'Type']);

    foreach ($messages as $message) {
        $date = date('Y-m-d', strtotime($message['created_at']));
        $time = date('H:i:s', strtotime($message['created_at']));

        fputcsv($output, [
            $date,
            $time,
            $message['room_name'],
            $message['sender_name'],
            $message['message'],
            $message['message_type']
        ]);
    }

    fclose($output);
    exit;
}

function handleExportChatHistory($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;

    if (!$room_id) {
        throw new Exception('Room ID required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    $query = "
        SELECT m.*, u.name as sender_name, r.name as room_name
        FROM live_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        LEFT JOIN live_chat_rooms r ON m.room_id = r.id
        WHERE m.room_id = :room_id AND m.is_deleted = FALSE
        ORDER BY m.created_at ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Generate CSV content
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="chat_history_' . $messages[0]['room_name'] . '_' . date('Y-m-d_H-i-s') . '.csv"');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Time', 'Sender', 'Message', 'Type', 'File']);

    foreach ($messages as $message) {
        $date = date('Y-m-d', strtotime($message['created_at']));
        $time = date('H:i:s', strtotime($message['created_at']));

        fputcsv($output, [
            $date,
            $time,
            $message['sender_name'],
            $message['message'],
            $message['message_type'],
            $message['file_name'] ?? ''
        ]);
    }

    fclose($output);
    exit;
}

function handleAdvancedSearch($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;
    $query = $_GET['query'] ?? '';
    $message_type = $_GET['message_type'] ?? 'all';
    $date_from = $_GET['date_from'] ?? '';
    $date_to = $_GET['date_to'] ?? '';
    $search_user_id = $_GET['user_id'] ?? '';
    $limit = min($_GET['limit'] ?? 50, 100);

    if (!$room_id) {
        throw new Exception('Room ID required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    $conditions = ['m.room_id = :room_id', 'm.is_deleted = FALSE'];
    $params = [':room_id' => $room_id];

    if ($query) {
        $conditions[] = 'm.message LIKE :search_term';
        $params[':search_term'] = '%' . $query . '%';
    }

    if ($message_type && $message_type !== 'all') {
        $conditions[] = 'm.message_type = :message_type';
        $params[':message_type'] = $message_type;
    }

    if ($date_from) {
        $conditions[] = 'DATE(m.created_at) >= :date_from';
        $params[':date_from'] = $date_from;
    }

    if ($date_to) {
        $conditions[] = 'DATE(m.created_at) <= :date_to';
        $params[':date_to'] = $date_to;
    }

    if ($search_user_id) {
        $conditions[] = 'm.sender_id = :search_user_id';
        $params[':search_user_id'] = $search_user_id;
    }

    $where_clause = implode(' AND ', $conditions);

    $search_query = "
        SELECT m.*, u.name as sender_name, u.profile_picture as sender_avatar
        FROM live_chat_messages m
        LEFT JOIN users u ON m.sender_id = u.id
        WHERE $where_clause
        ORDER BY m.created_at DESC
        LIMIT :limit
    ";

    $stmt = $db->prepare($search_query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'messages' => $messages]);
}

function handleGetRoomUsers($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;

    if (!$room_id) {
        throw new Exception('Room ID required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    $query = "
        SELECT DISTINCT u.id, u.name
        FROM users u
        INNER JOIN live_chat_participants p ON u.id = p.user_id
        WHERE p.room_id = :room_id AND p.is_banned = FALSE
        ORDER BY u.name ASC
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->execute();

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
}

function handleGetThreadMessages($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 0;

    if (!$room_id) {
        throw new Exception('Room ID required');
    }

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    // Get messages that have replies (threads)
    $query = "
        SELECT
            parent.id,
            parent.message as original_message,
            parent.sender_id as original_sender_id,
            u1.name as original_sender_name,
            COUNT(replies.id) as reply_count,
            GROUP_CONCAT(
                JSON_OBJECT(
                    'id', replies.id,
                    'message', replies.message,
                    'sender_name', u2.name,
                    'created_at', replies.created_at
                )
                ORDER BY replies.created_at ASC
            ) as replies_json
        FROM live_chat_messages parent
        LEFT JOIN users u1 ON parent.sender_id = u1.id
        LEFT JOIN live_chat_messages replies ON parent.id = replies.reply_to_message_id
        LEFT JOIN users u2 ON replies.sender_id = u2.id
        WHERE parent.room_id = :room_id
        AND parent.is_deleted = FALSE
        AND parent.id IN (
            SELECT DISTINCT reply_to_message_id
            FROM live_chat_messages
            WHERE reply_to_message_id IS NOT NULL
            AND room_id = :room_id2
        )
        GROUP BY parent.id
        ORDER BY parent.created_at DESC
        LIMIT 20
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':room_id', $room_id);
    $stmt->bindParam(':room_id2', $room_id);
    $stmt->execute();

    $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Parse JSON replies
    foreach ($threads as &$thread) {
        if ($thread['replies_json']) {
            $thread['replies'] = json_decode('[' . $thread['replies_json'] . ']', true);
        } else {
            $thread['replies'] = [];
        }
        unset($thread['replies_json']);
    }

    echo json_encode(['success' => true, 'threads' => $threads]);
}

function handleSendThreadReply($db, $user_id) {
    $thread_id = $_POST['thread_id'] ?? 0;
    $message = trim($_POST['message'] ?? '');

    if (!$thread_id || !$message) {
        throw new Exception('Thread ID and message are required');
    }

    // Get the original message to find the room
    $query = "SELECT room_id FROM live_chat_messages WHERE id = :thread_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':thread_id', $thread_id);
    $stmt->execute();

    $original_message = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$original_message) {
        throw new Exception('Original message not found');
    }

    $room_id = $original_message['room_id'];

    if (!hasRoomAccess($db, $user_id, $room_id)) {
        throw new Exception('Access denied to this room');
    }

    // Insert reply
    $insert_query = "
        INSERT INTO live_chat_messages (room_id, sender_id, message, reply_to_message_id, created_at)
        VALUES (:room_id, :sender_id, :message, :thread_id, NOW())
    ";

    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':room_id', $room_id);
    $insert_stmt->bindParam(':sender_id', $user_id);
    $insert_stmt->bindParam(':message', $message);
    $insert_stmt->bindParam(':thread_id', $thread_id);
    $insert_stmt->execute();

    echo json_encode(['success' => true, 'message_id' => $db->lastInsertId()]);
}

function handleDebugUserAccess($db, $user_id) {
    $room_id = $_GET['room_id'] ?? 1;

    // Get user info
    $user_query = "SELECT id, name, role FROM users WHERE id = :user_id";
    $user_stmt = $db->prepare($user_query);
    $user_stmt->bindParam(':user_id', $user_id);
    $user_stmt->execute();
    $user = $user_stmt->fetch(PDO::FETCH_ASSOC);

    // Get room info
    $room_query = "SELECT id, name, room_type FROM live_chat_rooms WHERE id = :room_id";
    $room_stmt = $db->prepare($room_query);
    $room_stmt->bindParam(':room_id', $room_id);
    $room_stmt->execute();
    $room = $room_stmt->fetch(PDO::FETCH_ASSOC);

    // Check access
    $has_access = hasRoomAccess($db, $user_id, $room_id);
    $is_muted = isUserMuted($db, $user_id, $room_id);

    // Get participant info
    $participant_query = "SELECT * FROM live_chat_participants WHERE user_id = :user_id AND room_id = :room_id";
    $participant_stmt = $db->prepare($participant_query);
    $participant_stmt->bindParam(':user_id', $user_id);
    $participant_stmt->bindParam(':room_id', $room_id);
    $participant_stmt->execute();
    $participant = $participant_stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'debug_info' => [
            'user' => $user,
            'room' => $room,
            'has_access' => $has_access,
            'is_muted' => $is_muted,
            'participant' => $participant,
            'session_role' => $_SESSION['role'] ?? 'not_set'
        ]
    ]);
}

function handleGetOnlineUsers($db, $user_id) {
    // Get online users - only truly online users
    $query = "
        SELECT DISTINCT u.id, u.name, u.role, u.profile_picture,
               COALESCE(s.status, 'offline') as status,
               s.custom_status, s.last_activity
        FROM users u
        INNER JOIN live_chat_user_status s ON u.id = s.user_id
        WHERE u.status = 'active'
        AND s.status = 'online'
        AND s.last_activity > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
        GROUP BY u.id
        ORDER BY u.name
    ";

    $stmt = $db->prepare($query);
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'users' => $users]);
}

function handleGetRooms($db, $user_id) {
    // Get accessible rooms for the user
    $query = "
        SELECT r.id, r.name, r.description, r.room_type, r.created_at,
               COUNT(DISTINCT p.user_id) as participant_count,
               u.name as created_by_name
        FROM live_chat_rooms r
        LEFT JOIN users u ON r.created_by = u.id
        LEFT JOIN live_chat_participants p ON r.id = p.room_id AND p.is_banned = FALSE
        WHERE r.is_active = TRUE
        AND (
            r.room_type = 'public'
            OR (r.room_type = 'admin_only' AND :user_role IN ('super_admin', 'school_admin', 'principal'))
            OR (r.room_type = 'private' AND p.user_id = :user_id)
        )
        GROUP BY r.id
        ORDER BY r.room_type, r.name
    ";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->bindParam(':user_role', $_SESSION['role']);
    $stmt->execute();
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'rooms' => $rooms]);
}
?>
