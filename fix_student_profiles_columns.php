<?php
/**
 * Fix Student Profiles Table - Add Missing Columns
 * This script adds missing columns to the student_profiles table
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h2>Fixing Student Profiles Table Columns</h2>\n";

try {
    // Add missing columns to student_profiles table
    echo "Adding missing columns to student_profiles table...\n";
    
    // Add previous_school column
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS previous_school VARCHAR(255)");
    echo "✓ Added previous_school column\n";
    
    // Add nationality column
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS nationality VARCHAR(100)");
    echo "✓ Added nationality column\n";
    
    // Add religion column
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS religion VARCHAR(100)");
    echo "✓ Added religion column\n";
    
    // Add parent_name column (for backward compatibility)
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS parent_name VARCHAR(100)");
    echo "✓ Added parent_name column\n";
    
    // Add parent_phone column (for backward compatibility)
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS parent_phone VARCHAR(20)");
    echo "✓ Added parent_phone column\n";
    
    // Add parent_email column (for backward compatibility)
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS parent_email VARCHAR(100)");
    echo "✓ Added parent_email column\n";
    
    // Add admission_number column
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS admission_number VARCHAR(50)");
    echo "✓ Added admission_number column\n";
    
    // Add class_id column for direct class reference
    $db->exec("ALTER TABLE student_profiles ADD COLUMN IF NOT EXISTS class_id INT");
    echo "✓ Added class_id column\n";
    
    // Add foreign key constraint for class_id
    try {
        $db->exec("ALTER TABLE student_profiles ADD CONSTRAINT IF NOT EXISTS fk_student_profiles_class 
                   FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL");
        echo "✓ Added foreign key constraint for class_id\n";
    } catch (PDOException $e) {
        echo "⚠ Warning: Could not add foreign key constraint for class_id: " . $e->getMessage() . "\n";
    }
    
    echo "\n✅ Student profiles table columns updated successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Error updating student profiles table: " . $e->getMessage() . "\n";
}

echo "\n<a href='dashboard.php'>Return to Dashboard</a>\n";
?>
