<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Table Structure Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .table-name { background-color: #e3f2fd; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Database Table Structure Check</h1>
    
    <?php
    require_once 'config/database.php';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        echo "<div class='success'>✅ Database connection successful</div>";
        
        // Tables to check
        $tables_to_check = [
            'users' => 'User accounts and basic information',
            'student_profiles' => 'Extended student information',
            'student_classes' => 'Student-class assignments',
            'classes' => 'School classes',
            'subjects' => 'School subjects',
            'exams' => 'Exam information',
            'exam_results' => 'Student exam results',
            'attendance' => 'Student attendance records'
        ];
        
        foreach ($tables_to_check as $table => $description) {
            echo "<div class='section'>";
            echo "<h2 class='table-name'>Table: $table</h2>";
            echo "<p><em>$description</em></p>";
            
            try {
                // Check if table exists
                $check_stmt = $db->query("SHOW TABLES LIKE '$table'");
                if ($check_stmt->rowCount() == 0) {
                    echo "<div class='error'>❌ Table '$table' does not exist</div>";
                    echo "</div>";
                    continue;
                }
                
                // Get table structure
                $structure_stmt = $db->query("DESCRIBE $table");
                $columns = $structure_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<table>";
                echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
                foreach ($columns as $column) {
                    echo "<tr>";
                    echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
                    echo "<td>" . htmlspecialchars($column['Default'] ?? 'NULL') . "</td>";
                    echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
                
                // Get record count
                $count_stmt = $db->query("SELECT COUNT(*) as count FROM $table");
                $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                echo "<p><strong>Records:</strong> $count</p>";
                
            } catch (PDOException $e) {
                echo "<div class='error'>❌ Error checking table '$table': " . htmlspecialchars($e->getMessage()) . "</div>";
            }
            
            echo "</div>";
        }
        
        // Test the student profile query
        echo "<div class='section'>";
        echo "<h2>Test Student Profile Query</h2>";
        
        try {
            // Get a sample student ID
            $sample_stmt = $db->query("SELECT id FROM users WHERE role = 'student' LIMIT 1");
            $sample_student = $sample_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($sample_student) {
                $student_id = $sample_student['id'];
                echo "<p>Testing with student ID: $student_id</p>";
                
                $test_query = "SELECT u.*, sp.*, c.name as class_name, c.grade_level, c.academic_year,
                              p.name as parent_name, p.email as parent_email
                              FROM users u
                              LEFT JOIN student_profiles sp ON u.id = sp.user_id
                              LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                              LEFT JOIN classes c ON sc.class_id = c.id
                              LEFT JOIN users p ON sp.parent_id = p.id
                              WHERE u.id = :student_id AND u.role = 'student'";
                
                $test_stmt = $db->prepare($test_query);
                $test_stmt->bindParam(':student_id', $student_id);
                $test_stmt->execute();
                $result = $test_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($result) {
                    echo "<div class='success'>✅ Query executed successfully</div>";
                    echo "<p><strong>Sample result:</strong></p>";
                    echo "<table>";
                    echo "<tr><th>Field</th><th>Value</th></tr>";
                    foreach ($result as $key => $value) {
                        echo "<tr>";
                        echo "<td>" . htmlspecialchars($key) . "</td>";
                        echo "<td>" . htmlspecialchars($value ?? 'NULL') . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                } else {
                    echo "<div class='error'>❌ Query returned no results</div>";
                }
                
            } else {
                echo "<div class='error'>❌ No student records found to test with</div>";
            }
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Query test failed: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Fatal error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
    
    <div class="section">
        <h2>Quick Links</h2>
        <p><a href="academic/student_profile.php?id=1">Test Academic Student Profile</a></p>
        <p><a href="students/profile.php?id=1">Test Regular Student Profile</a></p>
        <p><a href="debug_enrollment.php">Debug Enrollment</a></p>
        <p><a href="quick_setup.php">Quick Setup</a></p>
    </div>
</body>
</html>
