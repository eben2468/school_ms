<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if ($db) {
        echo "Database connection successful!\n";
        
        // Test a simple query
        $result = $db->query("SELECT 1 as test");
        if ($result) {
            echo "Database query test successful!\n";
        }
        
        // Check if tables exist
        $tables = ['users', 'hostel_blocks', 'health_records', 'canteen_inventory'];
        foreach ($tables as $table) {
            try {
                $result = $db->query("SELECT COUNT(*) FROM $table");
                $count = $result->fetchColumn();
                echo "Table '$table' exists with $count records\n";
            } catch (PDOException $e) {
                echo "Table '$table' issue: " . $e->getMessage() . "\n";
            }
        }
    } else {
        echo "Database connection failed!\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
