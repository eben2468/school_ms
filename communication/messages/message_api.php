<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthenticated']);
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$id = intval($_GET['id'] ?? 0);

if (!$id && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = intval($_POST['id'] ?? 0);
}

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message ID']);
    exit();
}

header('Content-Type: application/json');

try {
    switch ($action) {
        case 'get':
            $type = $_GET['type'] ?? 'inbox';
            
            if ($type === 'inbox') {
                $query = "SELECT m.*, u.name as sender_name, u.email as sender_email
                          FROM messages m
                          JOIN users u ON m.sender_id = u.id
                          WHERE m.id = :id AND m.recipient_id = :user_id";
            } else {
                $query = "SELECT m.*, u.name as recipient_name, u.email as recipient_email
                          FROM messages m
                          JOIN users u ON m.recipient_id = u.id
                          WHERE m.id = :id AND m.sender_id = :user_id";
            }
            
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $user_id
            ]);
            $message = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($message) {
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Message not found or access denied']);
            }
            break;
            
        case 'read':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            
            $query = "UPDATE messages 
                      SET is_read = TRUE, read_at = NOW() 
                      WHERE id = :id AND recipient_id = :user_id AND is_read = FALSE";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $user_id
            ]);
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                exit();
            }
            
            $type = $_GET['type'] ?? 'inbox';
            
            // Delete message row if current user is either the sender or receiver
            $query = "DELETE FROM messages WHERE id = :id AND (recipient_id = :user_id OR sender_id = :user_id)";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':id' => $id,
                ':user_id' => $user_id
            ]);
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Message not found or permission denied']);
            }
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action parameter']);
            break;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>
