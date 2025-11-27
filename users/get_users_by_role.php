<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$role = $_GET['role'] ?? '';

if (empty($role)) {
    http_response_code(400);
    echo json_encode(['error' => 'Role parameter is required']);
    exit();
}

try {
    // Get users with the specified role (excluding current user and super_admin)
    $query = "SELECT id, name, email, status, created_at 
              FROM users 
              WHERE role = :role 
              AND id != :current_user_id 
              AND role != 'super_admin'
              ORDER BY name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':role', $role);
    $stmt->bindParam(':current_user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $response = [
        'success' => true,
        'role' => $role,
        'users' => $users,
        'count' => count($users)
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
