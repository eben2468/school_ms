<?php
/**
 * Settings Migration Script
 * Adds new columns to school_settings table for the reorganized settings interface
 * 
 * Run this script once to update your database schema
 */

require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Settings Migration</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #3b82f6;
            padding-bottom: 10px;
        }
        .success {
            background: #d1fae5;
            border-left: 4px solid #10b981;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .error {
            background: #fee2e2;
            border-left: 4px solid #ef4444;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .info {
            background: #dbeafe;
            border-left: 4px solid #3b82f6;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
        .step {
            margin: 15px 0;
            padding: 10px;
            background: #f9fafb;
            border-radius: 4px;
        }
        .icon {
            display: inline-block;
            width: 20px;
            text-align: center;
            margin-right: 5px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #2563eb;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 Settings Migration</h1>
        <p>This script will update your database schema to support the new settings interface.</p>
";

$results = [];
$errors = [];

try {
    echo "<div class='info'><strong>Starting migration...</strong></div>";
    
    // Read and execute migration SQL
    $sql_file = 'config/settings_migration.sql';
    
    if (!file_exists($sql_file)) {
        throw new Exception("Migration file not found: {$sql_file}");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Remove comments and split by semicolon
    $sql = preg_replace('/--[^\n]*\n/', "\n", $sql);
    $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success_count = 0;
    $skip_count = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement)) continue;
        
        try {
            $db->exec($statement);
            $success_count++;
            
            // Extract column name for display
            if (preg_match('/ADD COLUMN.*?(\w+)\s+/i', $statement, $matches)) {
                $results[] = "Added column: {$matches[1]}";
            } elseif (preg_match('/MODIFY COLUMN\s+(\w+)/i', $statement, $matches)) {
                $results[] = "Modified column: {$matches[1]}";
            } elseif (preg_match('/CREATE INDEX.*?(\w+)/i', $statement, $matches)) {
                $results[] = "Created index: {$matches[1]}";
            } else {
                $results[] = "Executed statement successfully";
            }
        } catch (PDOException $e) {
            // Check if error is due to column already existing
            if (strpos($e->getMessage(), 'Duplicate column') !== false || 
                strpos($e->getMessage(), 'already exists') !== false ||
                $e->getCode() == '42S21') {
                $skip_count++;
                if (preg_match('/ADD COLUMN.*?(\w+)\s+/i', $statement, $matches)) {
                    $results[] = "Skipped (already exists): {$matches[1]}";
                }
            } else {
                $errors[] = "Error: " . $e->getMessage();
            }
        }
    }
    
    echo "<div class='success'>";
    echo "<strong>✅ Migration completed successfully!</strong><br>";
    echo "Statements executed: {$success_count}<br>";
    echo "Statements skipped: {$skip_count}";
    echo "</div>";
    
    if (!empty($results)) {
        echo "<div class='step'><strong>Changes made:</strong><ul>";
        foreach ($results as $result) {
            echo "<li>{$result}</li>";
        }
        echo "</ul></div>";
    }
    
    if (!empty($errors)) {
        echo "<div class='error'><strong>⚠️ Some errors occurred:</strong><ul>";
        foreach ($errors as $error) {
            echo "<li>{$error}</li>";
        }
        echo "</ul></div>";
    }
    
    echo "<div class='info'>";
    echo "<strong>Next Steps:</strong><br>";
    echo "1. Navigate to <strong>Settings > School Settings</strong><br>";
    echo "2. Explore the new tabbed interface<br>";
    echo "3. Configure your school settings in each tab<br>";
    echo "4. Test the new features (SMS, Email, Payment integrations)";
    echo "</div>";
    
    echo "<a href='settings/school.php' class='btn'>Go to School Settings →</a>";
    echo "<a href='index.php' class='btn' style='background: #6b7280; margin-left: 10px;'>Go to Dashboard →</a>";
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<strong>❌ Migration failed:</strong><br>";
    echo htmlspecialchars($e->getMessage());
    echo "</div>";
    
    echo "<div class='info'>";
    echo "<strong>Troubleshooting:</strong><br>";
    echo "1. Check database connection settings in config/database.php<br>";
    echo "2. Ensure the database user has ALTER TABLE privileges<br>";
    echo "3. Verify the school_settings table exists<br>";
    echo "4. Check error logs for more details";
    echo "</div>";
}

echo "
    </div>
</body>
</html>";
?>
