<?php
// Initialize new features database tables
require_once 'database.php';

$database = new Database();
$db = $database->getConnection();

echo "Initializing Online Learning Tools and Document Management features...\n";

try {
    // Read and execute online learning schema
    $online_learning_sql = file_get_contents(__DIR__ . '/online_learning_schema.sql');
    if ($online_learning_sql) {
        $statements = explode(';', $online_learning_sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !str_starts_with($statement, '--') && !str_starts_with($statement, 'SELECT')) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    // Continue if table already exists
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        echo "Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        echo "✓ Online Learning Tools schema initialized\n";
    }

    // Read and execute document management schema
    $document_sql = file_get_contents(__DIR__ . '/document_management_schema.sql');
    if ($document_sql) {
        $statements = explode(';', $document_sql);
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if (!empty($statement) && !str_starts_with($statement, '--') && !str_starts_with($statement, 'SELECT')) {
                try {
                    $db->exec($statement);
                } catch (PDOException $e) {
                    // Continue if table already exists or column already exists
                    if (strpos($e->getMessage(), 'already exists') === false && 
                        strpos($e->getMessage(), 'Duplicate column') === false) {
                        echo "Warning: " . $e->getMessage() . "\n";
                    }
                }
            }
        }
        echo "✓ Document Management schema initialized\n";
    }

    // Create uploads directories if they don't exist
    $upload_dirs = [
        '../uploads/documents',
        '../uploads/learning_materials',
        '../uploads/certificates',
        '../uploads/transcripts'
    ];

    foreach ($upload_dirs as $dir) {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            echo "✓ Created directory: $dir\n";
        }
    }

    echo "\n🎉 All features initialized successfully!\n";
    echo "\nNew features available:\n";
    echo "- Online Learning Tools (/online_learning/)\n";
    echo "- Document & File Management (/documents/)\n";
    echo "- Virtual Classroom Integration\n";
    echo "- Certificate Generation\n";
    echo "- Secure File Sharing\n";
    echo "- Learning Materials Management\n";

} catch (PDOException $e) {
    echo "❌ Error initializing features: " . $e->getMessage() . "\n";
}
?>
