<?php
/**
 * Create Test Parent Account and Link to Student
 * This script creates a test parent account and links it to an existing student
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Creating Test Parent Account</h2>\n";

try {
    // Check if test parent already exists
    $check_parent = "SELECT id FROM users WHERE email = 'parent@test.com' AND role = 'parent'";
    $stmt = $db->query($check_parent);
    
    if ($stmt->rowCount() > 0) {
        $parent_id = $stmt->fetch(PDO::FETCH_ASSOC)['id'];
        echo "✓ Test parent account already exists (ID: $parent_id)\n";
    } else {
        // Create test parent account
        $parent_password = password_hash('parent123', PASSWORD_DEFAULT);
        $create_parent = "INSERT INTO users (name, email, password, role, status) VALUES ('Test Parent', 'parent@test.com', :password, 'parent', 'active')";
        $stmt = $db->prepare($create_parent);
        $stmt->bindParam(':password', $parent_password);
        $stmt->execute();
        $parent_id = $db->lastInsertId();
        echo "✓ Created test parent account (ID: $parent_id)\n";
        echo "  Email: parent@test.com\n";
        echo "  Password: parent123\n";
    }
    
    // Get first available student
    $get_student = "SELECT u.id, u.name, sp.student_id FROM users u 
                   JOIN student_profiles sp ON u.id = sp.user_id 
                   WHERE u.role = 'student' AND u.status = 'active' 
                   LIMIT 1";
    $stmt = $db->query($get_student);
    
    if ($stmt->rowCount() > 0) {
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        $student_id = $student['id'];
        
        // Check if relationship already exists
        $check_relationship = "SELECT id FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
        $stmt = $db->prepare($check_relationship);
        $stmt->bindParam(':parent_id', $parent_id);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo "✓ Parent-student relationship already exists\n";
        } else {
            // Create parent-student relationship
            $create_relationship = "INSERT INTO parent_students (parent_id, student_id, relationship, is_primary) 
                                   VALUES (:parent_id, :student_id, 'guardian', TRUE)";
            $stmt = $db->prepare($create_relationship);
            $stmt->bindParam(':parent_id', $parent_id);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->execute();
            echo "✓ Created parent-student relationship\n";
        }
        
        echo "  Student: " . htmlspecialchars($student['name']) . " (ID: " . htmlspecialchars($student['student_id']) . ")\n";
        echo "  Parent: Test Parent (parent@test.com)\n";
        
    } else {
        echo "⚠ No students found in the system. Please create a student first.\n";
    }
    
    echo "\n✅ Test setup complete!\n";
    echo "\n<strong>Login Details:</strong>\n";
    echo "Email: parent@test.com\n";
    echo "Password: parent123\n";
    echo "\n<a href='login.php'>Login as Parent</a> | <a href='parent/dashboard.php'>Parent Dashboard</a>\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n<a href='dashboard.php'>Return to Dashboard</a>\n";
?>
