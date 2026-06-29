<?php
/**
 * Fix Database Columns for Parent Pages
 * This script adds missing columns needed for parent functionality
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Fixing Database Columns for Parent Pages</h2>\n";

try {
    // Fix student_academic_records table
    echo "Checking student_academic_records table...\n";
    
    // Check if table exists
    $result = $db->query("SHOW TABLES LIKE 'student_academic_records'");
    if ($result->rowCount() == 0) {
        echo "Creating student_academic_records table...\n";
        $create_table = "CREATE TABLE student_academic_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            subject_id INT NOT NULL,
            academic_year_id INT,
            academic_term_id INT,
            continuous_assessment DECIMAL(5,2),
            mid_term_exam DECIMAL(5,2),
            final_exam DECIMAL(5,2),
            total_score DECIMAL(5,2),
            grade VARCHAR(5),
            remarks TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        )";
        $db->exec($create_table);
        echo "✓ Created student_academic_records table\n";
    } else {
        echo "✓ student_academic_records table exists\n";
        
        // Add missing columns
        $columns_to_add = [
            'mid_term_exam' => 'DECIMAL(5,2)',
            'continuous_assessment' => 'DECIMAL(5,2)',
            'final_exam' => 'DECIMAL(5,2)',
            'total_score' => 'DECIMAL(5,2)',
            'grade' => 'VARCHAR(5)',
            'remarks' => 'TEXT',
            'academic_year_id' => 'INT',
            'academic_term_id' => 'INT'
        ];
        
        foreach ($columns_to_add as $column => $type) {
            try {
                runDdlSafely($db, "ALTER TABLE student_academic_records ADD COLUMN IF NOT EXISTS $column $type");
                echo "✓ Added/verified column: $column\n";
            } catch (PDOException $e) {
                echo "⚠ Warning for column $column: " . $e->getMessage() . "\n";
            }
        }
    }
    
    // Fix attendance table
    echo "\nChecking attendance table...\n";
    $attendance_columns = [
        'time_in' => 'TIME',
        'time_out' => 'TIME',
        'remarks' => 'TEXT'
    ];
    
    foreach ($attendance_columns as $column => $type) {
        try {
            runDdlSafely($db, "ALTER TABLE attendance ADD COLUMN IF NOT EXISTS $column $type");
            echo "✓ Added/verified attendance column: $column\n";
        } catch (PDOException $e) {
            echo "⚠ Warning for attendance column $column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix assignments table
    echo "\nChecking assignments table...\n";
    $assignment_columns = [
        'total_marks' => 'INT DEFAULT 100',
        'status' => "ENUM('active', 'inactive', 'completed') DEFAULT 'active'"
    ];
    
    foreach ($assignment_columns as $column => $type) {
        try {
            runDdlSafely($db, "ALTER TABLE assignments ADD COLUMN IF NOT EXISTS $column $type");
            echo "✓ Added/verified assignments column: $column\n";
        } catch (PDOException $e) {
            echo "⚠ Warning for assignments column $column: " . $e->getMessage() . "\n";
        }
    }
    
    // Fix student_assignments table
    echo "\nChecking student_assignments table...\n";
    $student_assignment_columns = [
        'grade' => 'DECIMAL(5,2)',
        'feedback' => 'TEXT',
        'submitted_at' => 'TIMESTAMP NULL'
    ];
    
    foreach ($student_assignment_columns as $column => $type) {
        try {
            runDdlSafely($db, "ALTER TABLE student_assignments ADD COLUMN IF NOT EXISTS $column $type");
            echo "✓ Added/verified student_assignments column: $column\n";
        } catch (PDOException $e) {
            echo "⚠ Warning for student_assignments column $column: " . $e->getMessage() . "\n";
        }
    }
    
    // Ensure parent_students table exists
    echo "\nChecking parent_students table...\n";
    $result = $db->query("SHOW TABLES LIKE 'parent_students'");
    if ($result->rowCount() == 0) {
        echo "Creating parent_students table...\n";
        $create_parent_students = "CREATE TABLE parent_students (
            id INT PRIMARY KEY AUTO_INCREMENT,
            parent_id INT NOT NULL,
            student_id INT NOT NULL,
            relationship ENUM('father', 'mother', 'guardian', 'other') NOT NULL DEFAULT 'guardian',
            is_primary BOOLEAN DEFAULT FALSE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_parent_student (parent_id, student_id)
        )";
        $db->exec($create_parent_students);
        echo "✓ Created parent_students table\n";
    } else {
        echo "✓ parent_students table exists\n";
    }
    
    echo "\n✅ All database columns have been fixed!\n";
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\n<a href='dashboard.php'>Return to Dashboard</a>\n";
?>
