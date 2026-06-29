<?php
/**
 * Setup School Settings Table
 * This script creates the school_settings table and inserts default values
 */

require_once 'config/database.php';

try {
    echo "Setting up school settings table...\n";
    
    // Read the SQL file
    $sql = file_get_contents('config/create_school_settings_table.sql');
    
    if (!$sql) {
        throw new Exception("Could not read SQL file");
    }
    
    // Split the SQL into individual statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            echo "Executing: " . substr($statement, 0, 50) . "...\n";
            
            if (isset($pdo)) {
                runDdlSafely($pdo, $statement);
            } elseif (isset($conn)) {
                $conn->query($statement);
            } else {
                throw new Exception("No database connection available");
            }
        }
    }
    
    echo "✅ School settings table setup completed successfully!\n";
    echo "✅ Default settings have been inserted.\n";
    echo "✅ You can now configure your school settings at: settings/school.php\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up school settings: " . $e->getMessage() . "\n";
    exit(1);
}
?>
