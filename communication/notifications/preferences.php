<?php
/**
 * preferences.php
 * GET  -> returns the current user's notification preferences.
 * POST -> saves the current user's notification preferences.
 * Preferences are stored as a single master row per user (type = 'all').
 */
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

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            $input = $_POST;
        }

        $email = !empty($input['email_enabled']) ? 1 : 0;
        $push = !empty($input['push_enabled']) ? 1 : 0;
        $sms = !empty($input['sms_enabled']) ? 1 : 0;
        $in_app = !empty($input['in_app_enabled']) ? 1 : 0;

        $query = "
            INSERT INTO notification_preferences (user_id, type, email_enabled, push_enabled, sms_enabled, in_app_enabled)
            VALUES (:user_id, 'all', :email, :push, :sms, :in_app)
            ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                push_enabled = VALUES(push_enabled),
                sms_enabled = VALUES(sms_enabled),
                in_app_enabled = VALUES(in_app_enabled)
        ";
        $stmt = $db->prepare($query);
        $stmt->execute([
            ':user_id' => $user_id,
            ':email' => $email,
            ':push' => $push,
            ':sms' => $sms,
            ':in_app' => $in_app,
        ]);

        echo json_encode(['success' => true, 'message' => 'Preferences saved']);
        exit();
    }

    // GET — return current preferences (defaults when none saved yet)
    $stmt = $db->prepare("SELECT email_enabled, push_enabled, sms_enabled, in_app_enabled FROM notification_preferences WHERE user_id = :user_id AND type = 'all'");
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $prefs = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prefs) {
        $prefs = ['email_enabled' => 1, 'push_enabled' => 1, 'sms_enabled' => 0, 'in_app_enabled' => 1];
    }

    echo json_encode([
        'success' => true,
        'preferences' => [
            'email_enabled' => (bool)$prefs['email_enabled'],
            'push_enabled' => (bool)$prefs['push_enabled'],
            'sms_enabled' => (bool)$prefs['sms_enabled'],
            'in_app_enabled' => (bool)$prefs['in_app_enabled'],
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
