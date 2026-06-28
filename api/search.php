<?php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get search query
$input = json_decode(file_get_contents('php://input'), true);
$query = isset($input['query']) ? trim($input['query']) : '';

if (empty($query) || strlen($query) < 2) {
    echo json_encode([]);
    exit();
}

$results = [];
$user_role = $_SESSION['role'];

try {
    // Search students (if user has permission)
    if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        // Try to search students with fallback for missing tables
        try {
            $student_query = "SELECT u.id, u.name, u.email, 'student' as type, sp.student_id as identifier
                             FROM users u
                             LEFT JOIN student_profiles sp ON u.id = sp.user_id
                             WHERE u.role = 'student'
                             AND u.status = 'active'
                             AND (u.name LIKE :query OR u.email LIKE :query OR sp.student_id LIKE :query)
                             LIMIT 5";
            $stmt = $db->prepare($student_query);
            $stmt->bindValue(':query', "%$query%");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback: search users table only
            $student_query = "SELECT u.id, u.name, u.email, 'student' as type, u.email as identifier
                             FROM users u
                             WHERE u.role = 'student'
                             AND u.status = 'active'
                             AND (u.name LIKE :query OR u.email LIKE :query)
                             LIMIT 5";
            $stmt = $db->prepare($student_query);
            $stmt->bindValue(':query', "%$query%");
            $stmt->execute();
            $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach ($students as $student) {
            $results[] = [
                'id' => $student['id'],
                'title' => $student['name'],
                'subtitle' => $student['identifier'] ? "ID: " . $student['identifier'] : $student['email'],
                'type' => 'student',
                'icon' => 'fas fa-user-graduate',
                'url' => '/school_ms/students/profile.php?id=' . $student['id']
            ];
        }
    }

    // Search teachers (if user has permission)
    if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])) {
        // Try to search teachers with fallback for missing tables
        try {
            $teacher_query = "SELECT u.id, u.name, u.email, 'teacher' as type, tp.employee_id as identifier
                             FROM users u
                             LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
                             WHERE u.role = 'teacher'
                             AND u.status = 'active'
                             AND (u.name LIKE :query OR u.email LIKE :query OR tp.employee_id LIKE :query)
                             LIMIT 5";
            $stmt = $db->prepare($teacher_query);
            $stmt->bindValue(':query', "%$query%");
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // Fallback: search users table only
            $teacher_query = "SELECT u.id, u.name, u.email, 'teacher' as type, u.email as identifier
                             FROM users u
                             WHERE u.role = 'teacher'
                             AND u.status = 'active'
                             AND (u.name LIKE :query OR u.email LIKE :query)
                             LIMIT 5";
            $stmt = $db->prepare($teacher_query);
            $stmt->bindValue(':query', "%$query%");
            $stmt->execute();
            $teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        foreach ($teachers as $teacher) {
            $results[] = [
                'id' => $teacher['id'],
                'title' => $teacher['name'],
                'subtitle' => $teacher['identifier'] ? "ID: " . $teacher['identifier'] : $teacher['email'],
                'type' => 'teacher',
                'icon' => 'fas fa-chalkboard-teacher',
                'url' => '/school_ms/users/profile.php?id=' . $teacher['id']
            ];
        }
    }

    // Search classes
    try {
        $class_query = "SELECT id, name, grade_level, description
                       FROM classes
                       WHERE status = 'active'
                       AND (name LIKE :query OR grade_level LIKE :query OR description LIKE :query)
                       LIMIT 5";
        $stmt = $db->prepare($class_query);
        $stmt->bindValue(':query', "%$query%");
        $stmt->execute();
        $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If classes table doesn't exist or has different structure, skip
        $classes = [];
    }
    
    foreach ($classes as $class) {
        $results[] = [
            'id' => $class['id'],
            'title' => $class['grade_level'] . ' - ' . $class['name'],
            'subtitle' => $class['description'] ?: 'Class',
            'type' => 'class',
            'icon' => 'fas fa-chalkboard',
            'url' => '/school_ms/academic/classes/view.php?id=' . $class['id']
        ];
    }

    // Search subjects
    try {
        $subject_query = "SELECT id, name, code, description
                         FROM subjects
                         WHERE status = 'active'
                         AND (name LIKE :query OR code LIKE :query OR description LIKE :query)
                         LIMIT 5";
        $stmt = $db->prepare($subject_query);
        $stmt->bindValue(':query', "%$query%");
        $stmt->execute();
        $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // If subjects table doesn't exist or has different structure, skip
        $subjects = [];
    }
    
    foreach ($subjects as $subject) {
        $results[] = [
            'id' => $subject['id'],
            'title' => $subject['name'],
            'subtitle' => $subject['code'] ? "Code: " . $subject['code'] : 'Subject',
            'type' => 'subject',
            'icon' => 'fas fa-book',
            'url' => '/school_ms/academic/subjects/view.php?id=' . $subject['id']
        ];
    }

    // Search assignments (if user has permission)
    if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
        try {
            $assignment_query = "SELECT a.id, a.title, a.description, s.name as subject_name
                               FROM assignments a
                               LEFT JOIN subjects s ON a.subject_id = s.id
                               WHERE a.status = 'active'
                               AND (a.title LIKE :query OR a.description LIKE :query OR s.name LIKE :query)
                               LIMIT 5";
            $stmt = $db->prepare($assignment_query);
            $stmt->bindValue(':query', "%$query%");
            $stmt->execute();
            $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // If assignments table doesn't exist or has different structure, skip
            $assignments = [];
        }
        
        foreach ($assignments as $assignment) {
            $results[] = [
                'id' => $assignment['id'],
                'title' => $assignment['title'],
                'subtitle' => $assignment['subject_name'] ?: 'Assignment',
                'type' => 'assignment',
                'icon' => 'fas fa-tasks',
                'url' => '/school_ms/academic/assignments/view.php?id=' . $assignment['id']
            ];
        }
    }

    // Add quick actions based on search query
    $quick_actions = [];
    
    if (stripos($query, 'student') !== false || stripos($query, 'enroll') !== false) {
        if (in_array($user_role, ['super_admin', 'school_admin'])) {
            $quick_actions[] = [
                'title' => 'Enroll New Student',
                'subtitle' => 'Add a new student to the system',
                'type' => 'action',
                'icon' => 'fas fa-user-plus',
                'url' => '/school_ms/students/enroll.php'
            ];
        }
    }
    
    if (stripos($query, 'class') !== false || stripos($query, 'create') !== false) {
        if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])) {
            $quick_actions[] = [
                'title' => 'Create New Class',
                'subtitle' => 'Set up a new class',
                'type' => 'action',
                'icon' => 'fas fa-plus-circle',
                'url' => '/school_ms/academic/classes/create.php'
            ];
        }
    }
    
    if (stripos($query, 'assignment') !== false || stripos($query, 'homework') !== false) {
        if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])) {
            $quick_actions[] = [
                'title' => 'Create Assignment',
                'subtitle' => 'Create a new assignment',
                'type' => 'action',
                'icon' => 'fas fa-tasks',
                'url' => '/school_ms/academic/assignments/create.php'
            ];
        }
    }

    // Combine results with quick actions at the top
    $final_results = array_merge($quick_actions, $results);
    
    // Limit total results
    $final_results = array_slice($final_results, 0, 10);
    
    echo json_encode($final_results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
