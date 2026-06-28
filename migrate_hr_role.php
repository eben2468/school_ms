<?php
header('Content-Type: text/plain');
require_once 'config/database.php';

try {
    echo "=== Starting HR Role DB Migration ===\n";
    $database = new Database();
    $db = $database->getConnection();

    // 1. Check current role column configuration on users table
    echo "Checking users table role column...\n";
    $stmt = $db->query("SHOW COLUMNS FROM users LIKE 'role'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($column) {
        $type = $column['Type'];
        echo "Current users.role Type: $type\n";
        
        if (strpos($type, "'hr'") === false) {
            echo "Altering users table to add 'hr' to role ENUM...\n";
            $db->exec("ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin', 
                'school_admin', 
                'principal', 
                'teacher', 
                'student', 
                'parent', 
                'librarian', 
                'accountant', 
                'transport_officer', 
                'hostel_warden', 
                'canteen_manager', 
                'nurse', 
                'counselor',
                'hr'
            ) NOT NULL");
            echo "✅ Successfully altered users table.\n";
        } else {
            echo "ℹ️ 'hr' role already exists in users.role ENUM.\n";
        }
    } else {
        echo "❌ Error: users table or role column not found.\n";
    }

    // 2. Check current shared_with_role column configuration on document_shares table if it exists
    echo "\nChecking document_shares table shared_with_role column...\n";
    try {
        $stmt = $db->query("SHOW COLUMNS FROM document_shares LIKE 'shared_with_role'");
        $column = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($column) {
            $type = $column['Type'];
            echo "Current document_shares.shared_with_role Type: $type\n";
            
            if (strpos($type, "'hr'") === false) {
                echo "Altering document_shares table to add 'hr' to shared_with_role ENUM...\n";
                $db->exec("ALTER TABLE document_shares MODIFY COLUMN shared_with_role ENUM(
                    'super_admin', 
                    'school_admin', 
                    'principal', 
                    'teacher', 
                    'student', 
                    'parent', 
                    'librarian', 
                    'accountant', 
                    'transport_officer', 
                    'hostel_warden', 
                    'canteen_manager', 
                    'nurse', 
                    'counselor',
                    'hr'
                )");
                echo "✅ Successfully altered document_shares table.\n";
            } else {
                echo "ℹ️ 'hr' role already exists in document_shares.shared_with_role ENUM.\n";
            }
        } else {
            echo "ℹ️ Column shared_with_role does not exist in document_shares.\n";
        }
    } catch (PDOException $e) {
        echo "ℹ️ document_shares table might not exist yet: " . $e->getMessage() . "\n";
    }

    echo "\n=== DB Migration Finished Successfully ===\n";
} catch (Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
}
