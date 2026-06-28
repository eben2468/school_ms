<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Updating Existing Records with Academic Context</h2>\n";
    echo "<pre>\n";
    
    // Get current academic context
    $academic_context = $database->getCurrentAcademicContext();
    $current_year_id = $academic_context['year_id'];
    $current_term_id = $academic_context['term_id'];
    
    echo "Current Academic Year: {$academic_context['year_name']}\n";
    echo "Current Term: {$academic_context['term_name']}\n\n";
    
    // Check if academic tables exist first
    $tables_exist = true;
    try {
        $db->query("SELECT 1 FROM academic_years LIMIT 1");
        $db->query("SELECT 1 FROM academic_terms LIMIT 1");
    } catch (PDOException $e) {
        echo "❌ Academic tables not found. Please run setup_academic_system.php first.\n";
        $tables_exist = false;
    }

    if (!$tables_exist) {
        echo "\n<a href='setup_academic_system.php' style='background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Run Academic System Setup First</a>\n";
        return;
    }

    // Update exams table to include academic_year_id and academic_term_id columns
    echo "Updating exams table structure...\n";
    try {
        // Check if exams table exists
        $result = $db->query("SHOW TABLES LIKE 'exams'");
        if ($result->rowCount() > 0) {
            $db->exec("ALTER TABLE exams ADD COLUMN IF NOT EXISTS academic_year_id INT");
            $db->exec("ALTER TABLE exams ADD COLUMN IF NOT EXISTS academic_term_id INT");
            echo "✓ Exams table structure updated\n";
        } else {
            echo "⚠ Exams table not found, skipping\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Warning updating exams table: " . $e->getMessage() . "\n";
    }
    
    // Update attendance table
    echo "Updating attendance table structure...\n";
    try {
        $result = $db->query("SHOW TABLES LIKE 'attendance'");
        if ($result->rowCount() > 0) {
            $db->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS academic_year_id INT");
            $db->exec("ALTER TABLE attendance ADD COLUMN IF NOT EXISTS academic_term_id INT");
            echo "✓ Attendance table structure updated\n";
        } else {
            echo "⚠ Attendance table not found, skipping\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Warning updating attendance table: " . $e->getMessage() . "\n";
    }

    // Update assignments table
    echo "Updating assignments table structure...\n";
    try {
        $result = $db->query("SHOW TABLES LIKE 'assignments'");
        if ($result->rowCount() > 0) {
            $db->exec("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS academic_year_id INT");
            $db->exec("ALTER TABLE assignments ADD COLUMN IF NOT EXISTS academic_term_id INT");
            echo "✓ Assignments table structure updated\n";
        } else {
            echo "⚠ Assignments table not found, skipping\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Warning updating assignments table: " . $e->getMessage() . "\n";
    }

    // Update grades table
    echo "Updating grades table structure...\n";
    try {
        $result = $db->query("SHOW TABLES LIKE 'grades'");
        if ($result->rowCount() > 0) {
            $db->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS academic_year_id INT");
            $db->exec("ALTER TABLE grades ADD COLUMN IF NOT EXISTS academic_term_id INT");
            echo "✓ Grades table structure updated\n";
        } else {
            echo "⚠ Grades table not found, skipping\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Warning updating grades table: " . $e->getMessage() . "\n";
    }

    // Update classes table to include academic_year_id
    echo "Updating classes table structure...\n";
    try {
        $result = $db->query("SHOW TABLES LIKE 'classes'");
        if ($result->rowCount() > 0) {
            $db->exec("ALTER TABLE classes ADD COLUMN IF NOT EXISTS academic_year_id INT");
            echo "✓ Classes table structure updated\n";
        } else {
            echo "⚠ Classes table not found, skipping\n";
        }
    } catch (PDOException $e) {
        echo "⚠ Warning updating classes table: " . $e->getMessage() . "\n";
    }
    
    // Update existing records with current academic context (only if they don't have values)
    if ($current_year_id && $current_term_id) {
        echo "\nUpdating existing records with current academic context...\n";
        
        // Update exams
        $update_exams = "UPDATE exams SET academic_year_id = :year_id, academic_term_id = :term_id 
                        WHERE academic_year_id IS NULL OR academic_term_id IS NULL";
        $stmt = $db->prepare($update_exams);
        $stmt->bindParam(':year_id', $current_year_id);
        $stmt->bindParam(':term_id', $current_term_id);
        $stmt->execute();
        $updated_exams = $stmt->rowCount();
        echo "✓ Updated $updated_exams exam records\n";
        
        // Update attendance
        $update_attendance = "UPDATE attendance SET academic_year_id = :year_id, academic_term_id = :term_id 
                             WHERE academic_year_id IS NULL OR academic_term_id IS NULL";
        $stmt = $db->prepare($update_attendance);
        $stmt->bindParam(':year_id', $current_year_id);
        $stmt->bindParam(':term_id', $current_term_id);
        $stmt->execute();
        $updated_attendance = $stmt->rowCount();
        echo "✓ Updated $updated_attendance attendance records\n";
        
        // Update assignments
        $update_assignments = "UPDATE assignments SET academic_year_id = :year_id, academic_term_id = :term_id 
                              WHERE academic_year_id IS NULL OR academic_term_id IS NULL";
        $stmt = $db->prepare($update_assignments);
        $stmt->bindParam(':year_id', $current_year_id);
        $stmt->bindParam(':term_id', $current_term_id);
        $stmt->execute();
        $updated_assignments = $stmt->rowCount();
        echo "✓ Updated $updated_assignments assignment records\n";
        
        // Update grades
        $update_grades = "UPDATE grades SET academic_year_id = :year_id, academic_term_id = :term_id 
                         WHERE academic_year_id IS NULL OR academic_term_id IS NULL";
        $stmt = $db->prepare($update_grades);
        $stmt->bindParam(':year_id', $current_year_id);
        $stmt->bindParam(':term_id', $current_term_id);
        $stmt->execute();
        $updated_grades = $stmt->rowCount();
        echo "✓ Updated $updated_grades grade records\n";
        
        // Update classes
        $update_classes = "UPDATE classes SET academic_year_id = :year_id 
                          WHERE academic_year_id IS NULL";
        $stmt = $db->prepare($update_classes);
        $stmt->bindParam(':year_id', $current_year_id);
        $stmt->execute();
        $updated_classes = $stmt->rowCount();
        echo "✓ Updated $updated_classes class records\n";
    }
    
    echo "\n✅ Academic records update completed successfully!\n";
    echo "</pre>\n";
    
} catch (Exception $e) {
    echo "<pre>❌ Error: " . $e->getMessage() . "</pre>\n";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Update Academic Records</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
    </style>
</head>
<body>
    <h1>Academic Records Update</h1>
    <p>This script updates existing academic records to include proper academic year and term references.</p>
    
    <div style="margin-top: 80px;">
        <a href="academic/settings/" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
            Go to Academic Settings
        </a>
        <a href="index.php" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">
            Back to Dashboard
        </a>
    </div>
</body>
</html>
