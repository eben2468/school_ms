<?php
/**
 * send_notification.php
 * Sends a notification to a chosen audience (all users, a role, or a single user).
 * Admin-only. Reuses NotificationHelper for bulk / global / targeted delivery.
 */
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['super_admin', 'school_admin', 'principal'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You do not have permission to send notifications']);
    exit();
}

require_once '../../config/database.php';
require_once 'NotificationHelper.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    $helper = new NotificationHelper($db);

    $created_by = $_SESSION['user_id'];

    // Read input (JSON or form-encoded)
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    // Validate required fields
    $title = trim($input['title'] ?? '');
    $message = trim($input['message'] ?? '');
    $audience = $input['audience'] ?? 'all';

    if ($title === '' || $message === '') {
        echo json_encode(['success' => false, 'message' => 'Title and message are required']);
        exit();
    }

    $type = $input['type'] ?? 'announcement';
    $valid_types = ['academic', 'finance', 'system', 'announcement', 'general', 'attendance', 'grades', 'events', 'library', 'transport', 'hostel', 'canteen', 'health'];
    if (!in_array($type, $valid_types)) {
        $type = 'general';
    }

    $priority = $input['priority'] ?? 'medium';
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'])) {
        $priority = 'medium';
    }

    // Default icon per type
    $type_icons = [
        'academic' => 'fas fa-graduation-cap',
        'finance' => 'fas fa-money-bill-wave',
        'system' => 'fas fa-cog',
        'announcement' => 'fas fa-bullhorn',
        'attendance' => 'fas fa-calendar-check',
        'grades' => 'fas fa-star',
        'events' => 'fas fa-calendar-alt',
        'library' => 'fas fa-book',
        'general' => 'fas fa-bell',
    ];
    $icon = $input['icon'] ?? ($type_icons[$type] ?? 'fas fa-bell');

    $action_url = !empty($input['action_url']) ? trim($input['action_url']) : null;
    $action_text = !empty($input['action_text']) ? trim($input['action_text']) : null;
    $expires_at = !empty($input['expires_at']) ? $input['expires_at'] : null;
    if ($expires_at && !strtotime($expires_at)) {
        $expires_at = null;
    }

    $notification_data = [
        'title' => $title,
        'message' => $message,
        'type' => $type,
        'priority' => $priority,
        'icon' => $icon,
        'action_url' => $action_url,
        'action_text' => $action_text,
        'expires_at' => $expires_at,
        'created_by' => $created_by,
    ];

    $recipients = 0;

    switch ($audience) {
        case 'all':
            // Single global notification visible to every user.
            $result = $helper->createGlobalNotification($notification_data);
            if ($result === false) {
                throw new Exception('Failed to create global notification');
            }
            // Count active users for reporting.
            $cnt = $db->query("SELECT COUNT(*) FROM users WHERE status = 'active' OR status IS NULL");
            $recipients = (int)$cnt->fetchColumn();
            $summary = "all users";
            break;

        case 'role':
            $target_role = $input['role'] ?? '';
            $valid_roles = ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent', 'librarian', 'accountant', 'counselor', 'nurse', 'canteen_manager', 'transport_officer', 'hostel_warden'];
            if (!in_array($target_role, $valid_roles)) {
                echo json_encode(['success' => false, 'message' => 'Invalid target role']);
                exit();
            }
            $recipients = $helper->createForRoles([$target_role], $notification_data);
            $summary = "the " . str_replace('_', ' ', $target_role) . " role";
            break;

        case 'user':
            $target_user = intval($input['user_id'] ?? 0);
            if ($target_user <= 0) {
                echo json_encode(['success' => false, 'message' => 'Please select a recipient']);
                exit();
            }
            // Confirm user exists
            $check = $db->prepare("SELECT name FROM users WHERE id = :id");
            $check->bindParam(':id', $target_user, PDO::PARAM_INT);
            $check->execute();
            $target = $check->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                echo json_encode(['success' => false, 'message' => 'Recipient not found']);
                exit();
            }
            $notification_data['user_id'] = $target_user;
            $result = $helper->createNotification($notification_data);
            if ($result === false) {
                throw new Exception('Failed to create notification');
            }
            $recipients = 1;
            $summary = $target['name'];
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid audience']);
            exit();
    }

    echo json_encode([
        'success' => true,
        'message' => "Notification sent to $summary",
        'recipients' => $recipients
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
