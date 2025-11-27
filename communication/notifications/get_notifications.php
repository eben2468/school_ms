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
    $role = $_SESSION['role'];
    
    // Get parameters
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = max(0, intval($_GET['offset'] ?? 0));
    $type = $_GET['type'] ?? 'all';
    $unread_only = filter_var($_GET['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN);
    
    // Build WHERE clause
    $where_conditions = [];
    $params = [];
    
    // User-specific or global notifications
    $where_conditions[] = "(user_id = :user_id OR user_id IS NULL)";
    $params[':user_id'] = $user_id;
    
    // Filter by type
    if ($type !== 'all') {
        $where_conditions[] = "type = :type";
        $params[':type'] = $type;
    }
    
    // Filter unread only
    if ($unread_only) {
        $where_conditions[] = "is_read = FALSE";
    }
    
    // Exclude dismissed notifications
    $where_conditions[] = "is_dismissed = FALSE";
    
    // Exclude expired notifications
    $where_conditions[] = "(expires_at IS NULL OR expires_at > NOW())";
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM notifications $where_clause";
    $count_stmt = $db->prepare($count_query);
    foreach ($params as $key => $value) {
        $count_stmt->bindValue($key, $value);
    }
    $count_stmt->execute();
    $total_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Get unread count
    $unread_where = str_replace('is_read = FALSE', 'is_read = FALSE', $where_clause);
    if (!$unread_only) {
        $unread_where .= ' AND is_read = FALSE';
    }
    $unread_query = "SELECT COUNT(*) as unread FROM notifications $unread_where";
    $unread_stmt = $db->prepare($unread_query);
    foreach ($params as $key => $value) {
        $unread_stmt->bindValue($key, $value);
    }
    $unread_stmt->execute();
    $unread_count = $unread_stmt->fetch(PDO::FETCH_ASSOC)['unread'];
    
    // Get notifications
    $notifications_query = "
        SELECT n.*, 
               u.name as created_by_name,
               CASE 
                   WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) THEN 'just now'
                   WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN CONCAT(TIMESTAMPDIFF(MINUTE, n.created_at, NOW()), ' min ago')
                   WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN CONCAT(TIMESTAMPDIFF(HOUR, n.created_at, NOW()), ' hr ago')
                   WHEN n.created_at >= DATE_SUB(NOW(), INTERVAL 1 WEEK) THEN CONCAT(TIMESTAMPDIFF(DAY, n.created_at, NOW()), ' days ago')
                   ELSE DATE_FORMAT(n.created_at, '%M %d, %Y')
               END as time_ago
        FROM notifications n
        LEFT JOIN users u ON n.created_by = u.id
        $where_clause
        ORDER BY n.priority = 'urgent' DESC, n.priority = 'high' DESC, n.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $notifications_stmt = $db->prepare($notifications_query);
    foreach ($params as $key => $value) {
        $notifications_stmt->bindValue($key, $value);
    }
    $notifications_stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $notifications_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $notifications_stmt->execute();
    $notifications = $notifications_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get type counts
    $type_counts_query = "
        SELECT 
            type,
            COUNT(*) as total,
            SUM(CASE WHEN is_read = FALSE THEN 1 ELSE 0 END) as unread
        FROM notifications 
        WHERE (user_id = :user_id OR user_id IS NULL) 
        AND is_dismissed = FALSE 
        AND (expires_at IS NULL OR expires_at > NOW())
        GROUP BY type
    ";
    $type_counts_stmt = $db->prepare($type_counts_query);
    $type_counts_stmt->bindParam(':user_id', $user_id);
    $type_counts_stmt->execute();
    $type_counts = $type_counts_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format type counts for easier frontend use
    $counts_by_type = [];
    foreach ($type_counts as $count) {
        $counts_by_type[$count['type']] = [
            'total' => (int)$count['total'],
            'unread' => (int)$count['unread']
        ];
    }
    
    // Add color and icon mappings for frontend
    foreach ($notifications as &$notification) {
        $notification['color'] = match($notification['type']) {
            'academic' => 'blue',
            'finance' => 'green',
            'system' => 'yellow',
            'announcement' => 'purple',
            'attendance' => 'orange',
            'grades' => 'indigo',
            'events' => 'pink',
            'library' => 'teal',
            'transport' => 'cyan',
            'hostel' => 'lime',
            'canteen' => 'amber',
            'health' => 'red',
            default => 'gray'
        };
        
        $notification['priority_color'] = match($notification['priority']) {
            'urgent' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'green',
            default => 'gray'
        };
        
        // Parse metadata if exists
        if ($notification['metadata']) {
            $notification['metadata'] = json_decode($notification['metadata'], true);
        }
    }
    
    echo json_encode([
        'success' => true,
        'notifications' => $notifications,
        'pagination' => [
            'total' => (int)$total_count,
            'unread' => (int)$unread_count,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total_count
        ],
        'counts_by_type' => $counts_by_type,
        'user_role' => $role
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
