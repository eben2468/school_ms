<?php
// Simple script to add profile_picture column to users table
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "Checking if profile_picture column exists...\n";
    
    // Check if column exists
    $check_query = "SHOW COLUMNS FROM users LIKE 'profile_picture'";
    $check_stmt = $db->query($check_query);
    
    if ($check_stmt->rowCount() == 0) {
        echo "Column doesn't exist. Adding profile_picture column...\n";
        
        // Add the column
        $add_query = "ALTER TABLE users ADD COLUMN profile_picture VARCHAR(255) DEFAULT NULL";
        $db->exec($add_query);
        
        echo "✅ Profile picture column added successfully!\n";
    } else {
        echo "✅ Profile picture column already exists!\n";
    }
    
    // Verify the column was added
    $verify_query = "DESCRIBE users";
    $verify_stmt = $db->query($verify_query);
    $columns = $verify_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nCurrent users table structure:\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

echo "\nDone!\n";
?>
