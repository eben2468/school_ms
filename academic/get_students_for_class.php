<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$class_id = $_GET['class_id'] ?? '';

if (empty($class_id)) {
    http_response_code(400);
    echo json_encode(['error' => 'Class ID parameter is required']);
    exit();
}

try {
    // Get all students with their current class assignments
    $query = "SELECT 
                u.id,
                u.name,
                sp.student_id as roll_number,
                sc.class_id as current_class_id,
                c.name as current_class_name,
                CASE 
                    WHEN sc.class_id IS NULL THEN 'unassigned'
                    WHEN sc.class_id = :class_id THEN 'current_class'
                    ELSE 'other_class'
                END as assignment_status
              FROM users u
              LEFT JOIN student_profiles sp ON u.id = sp.user_id
              LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
              LEFT JOIN classes c ON sc.class_id = c.id
              WHERE u.role = 'student' AND u.status = 'active'
              ORDER BY 
                CASE 
                    WHEN sc.class_id IS NULL THEN 1
                    WHEN sc.class_id = :class_id THEN 2
                    ELSE 3
                END,
                u.name ASC";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get class name for the selected class
    $class_query = "SELECT name FROM classes WHERE id = :class_id";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
    $class_stmt->execute();
    $class_info = $class_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Categorize students
    $categorized_students = [
        'unassigned' => [],
        'current_class' => [],
        'other_class' => []
    ];
    
    foreach ($students as $student) {
        $categorized_students[$student['assignment_status']][] = $student;
    }
    
    // Format the response
    $response = [
        'success' => true,
        'class_id' => $class_id,
        'class_name' => $class_info['name'] ?? 'Unknown Class',
        'students' => $categorized_students,
        'counts' => [
            'unassigned' => count($categorized_students['unassigned']),
            'current_class' => count($categorized_students['current_class']),
            'other_class' => count($categorized_students['other_class']),
            'total' => count($students)
        ]
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
