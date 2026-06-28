<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

require_once '../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    $user_id = $_SESSION['user_id'];
    
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    $notification_id = $input['notification_id'] ?? null;
    
    if (!$notification_id) {
        echo json_encode(['success' => false, 'message' => 'Notification ID is required']);
        exit();
    }
    
    // Dismiss the notification (non-destructive: keeps it for audit/logs)
    $query = "UPDATE notifications SET is_dismissed = TRUE, dismissed_at = NOW() WHERE id = :notification_id AND (user_id = :user_id OR user_id IS NULL) AND is_dismissed = FALSE";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':notification_id', $notification_id);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $affected_rows = $stmt->rowCount();

    if ($affected_rows > 0) {
        // Best-effort log (ignore failure)
        try {
            $log = $db->prepare("INSERT INTO notification_logs (notification_id, action, user_id, ip_address, user_agent) VALUES (:nid, 'dismissed', :uid, :ip, :ua)");
            $log->execute([
                ':nid' => $notification_id,
                ':uid' => $user_id,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            ]);
        } catch (Exception $e) { /* non-fatal */ }
        echo json_encode(['success' => true, 'message' => 'Notification dismissed']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Notification not found or already dismissed']);
    }
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
