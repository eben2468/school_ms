<?php
// Add sample data for testing - run this once to populate test data
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Adding Sample Data</h2>";
    echo "<pre>";
    
    // Add sample classes
    try {
        $db->exec("INSERT IGNORE INTO classes (id, name, section, grade_level) VALUES 
            (1, 'Grade 5', 'A', 5),
            (2, 'Grade 5', 'B', 5),
            (3, 'Grade 6', 'A', 6),
            (4, 'Grade 6', 'B', 6)");
        echo "✓ Added sample classes\n";
    } catch (PDOException $e) {
        echo "✗ Error adding classes: " . $e->getMessage() . "\n";
    }
    
    // Add sample subjects
    try {
        $db->exec("INSERT IGNORE INTO subjects (id, name, code, description) VALUES 
            (1, 'Mathematics', 'MATH', 'Mathematics curriculum'),
            (2, 'English Language', 'ENG', 'English language and literature'),
            (3, 'Science', 'SCI', 'General science curriculum'),
            (4, 'Social Studies', 'SS', 'Social studies and history'),
            (5, 'Physical Education', 'PE', 'Physical education and sports')");
        echo "✓ Added sample subjects\n";
    } catch (PDOException $e) {
        echo "✗ Error adding subjects: " . $e->getMessage() . "\n";
    }
    
    // Update existing users with class assignments and student IDs
    try {
        $db->exec("UPDATE users SET class_id = 1, student_id = 'STU0001' WHERE id = 2 AND role = 'student'");
        $db->exec("UPDATE users SET class_id = 2, student_id = 'STU0002' WHERE id = 3 AND role = 'student'");
        $db->exec("UPDATE users SET class_id = 1, student_id = 'STU0003' WHERE id = 4 AND role = 'student'");
        echo "✓ Updated users with class assignments\n";
    } catch (PDOException $e) {
        echo "✗ Error updating users: " . $e->getMessage() . "\n";
    }
    
    // Add parent-student relationships
    try {
        $db->exec("INSERT IGNORE INTO parent_students (parent_id, student_id, relationship) VALUES 
            (5, 2, 'father'),
            (5, 3, 'father'),
            (6, 4, 'mother')");
        echo "✓ Added parent-student relationships\n";
    } catch (PDOException $e) {
        echo "✗ Error adding parent-student relationships: " . $e->getMessage() . "\n";
    }
    
    // Add sample assignments
    try {
        $db->exec("INSERT IGNORE INTO assignments (id, title, description, subject_id, teacher_id, class_id, due_date, status) VALUES 
            (1, 'Math Homework Chapter 5', 'Complete exercises 1-20 from Chapter 5: Fractions and Decimals', 1, 1, 1, '2024-01-15 23:59:59', 'active'),
            (2, 'English Essay: My Family', 'Write a 200-word essay about your family members and their roles', 2, 1, 1, '2024-01-18 23:59:59', 'active'),
            (3, 'Science Project: Solar System', 'Create a model or poster of the solar system with planet facts', 3, 1, 1, '2024-01-25 23:59:59', 'active'),
            (4, 'Math Quiz Preparation', 'Study multiplication tables 1-12 for upcoming quiz', 1, 1, 2, '2024-01-12 23:59:59', 'active'),
            (5, 'Reading Comprehension', 'Read Chapter 3 of the class novel and answer questions', 2, 1, 2, '2024-01-20 23:59:59', 'active')");
        echo "✓ Added sample assignments\n";
    } catch (PDOException $e) {
        echo "✗ Error adding assignments: " . $e->getMessage() . "\n";
    }
    
    // Add sample assignment submissions
    try {
        $db->exec("INSERT IGNORE INTO assignment_submissions (assignment_id, student_id, submission_text, status, grade, feedback, submitted_at, graded_at) VALUES 
            (1, 2, 'Completed all 20 exercises. Attached worksheet with solutions.', 'graded', 'A-', 'Excellent work! Minor error in question 15.', '2024-01-14 16:30:00', '2024-01-15 09:15:00'),
            (2, 2, 'My family consists of my parents and my younger sister. My father works as an engineer...', 'graded', 'B+', 'Good essay structure. Work on grammar.', '2024-01-17 20:45:00', '2024-01-18 14:20:00'),
            (4, 3, 'I have been practicing multiplication tables daily.', 'submitted', NULL, NULL, '2024-01-11 19:30:00', NULL)");
        echo "✓ Added sample assignment submissions\n";
    } catch (PDOException $e) {
        echo "✗ Error adding assignment submissions: " . $e->getMessage() . "\n";
    }
    
    // Add some sample notifications
    try {
        $db->exec("INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES 
            (2, 'Assignment Graded', 'Your Math homework has been graded. Grade: A-', 'academic', 'medium', 'fas fa-star'),
            (2, 'New Assignment Posted', 'Science Project: Solar System is now available', 'academic', 'medium', 'fas fa-clipboard-list'),
            (5, 'Parent Meeting Reminder', 'Parent-teacher meeting scheduled for next week', 'announcement', 'high', 'fas fa-calendar'),
            (NULL, 'System Maintenance', 'Scheduled maintenance this weekend', 'system', 'low', 'fas fa-tools')");
        echo "✓ Added sample notifications\n";
    } catch (PDOException $e) {
        echo "✗ Error adding notifications: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Sample data added successfully!\n";
    echo "</pre>";
    
    echo "<p><a href='parent/assignments.php'>Test Assignments Page</a> | <a href='help.php'>Test Live Chat</a> | <a href='dashboard.php'>Go to Dashboard</a></p>";
    
} catch (PDOException $e) {
    echo "<h2>Database Connection Error</h2>";
    echo "<p>Error: " . $e->getMessage() . "</p>";
}
?>
