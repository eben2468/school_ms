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

    // Seed default certificate templates
    try {
        $admin_query = "SELECT id FROM users WHERE role = 'super_admin' OR role = 'school_admin' LIMIT 1";
        $admin_user = $db->query($admin_query)->fetch(PDO::FETCH_ASSOC);
        $admin_id = $admin_user ? $admin_user['id'] : 1; // Fallback to user ID 1

        // Template 1
        $t1_check = $db->query("SELECT COUNT(*) FROM certificate_templates WHERE id = 1")->fetchColumn();
        if ($t1_check == 0) {
            $insert_template = "
                INSERT INTO certificate_templates (id, name, description, template_type, template_file_path, is_active, created_by)
                VALUES (1, 'Default Academic Certificate', 'Standard certificate template for academic achievements.', 'academic', 'templates/default_academic.html', 1, :admin_id)
            ";
            $stmt = $db->prepare($insert_template);
            $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
            $stmt->execute();
            echo "✓ Seeded default certificate template\n";
        }

        // Template 2
        $t2_check = $db->query("SELECT COUNT(*) FROM certificate_templates WHERE id = 2")->fetchColumn();
        if ($t2_check == 0) {
            $insert_template2 = "
                INSERT INTO certificate_templates (id, name, description, template_type, template_file_path, is_active, created_by)
                VALUES (2, 'Elegant Ribbon Graduation Certificate', 'Graduation template featuring elegant dark blue waves, gold accent curves, and a graduation ribbon.', 'academic', 'templates/elegant_ribbon.html', 1, :admin_id)
            ";
            $stmt = $db->prepare($insert_template2);
            $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
            $stmt->execute();
            echo "✓ Seeded Elegant Ribbon Graduation Certificate template\n";
        }
    } catch (PDOException $e) {
        echo "Warning: Failed to seed templates: " . $e->getMessage() . "\n";
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
