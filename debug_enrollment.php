<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Student Enrollment</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Student Enrollment Debug Information</h1>
    
    <?php
    require_once 'config/database.php';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        echo "<div class='section'>";
        echo "<h2>Database Connection</h2>";
        echo "<div class='success'>✅ Database connection successful</div>";
        echo "</div>";
        
        // Check classes
        echo "<div class='section'>";
        echo "<h2>Available Classes</h2>";
        $classes_query = "SELECT id, name, grade_level, academic_year, status FROM classes ORDER BY grade_level, name";
        $classes_stmt = $db->query($classes_query);
        $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($classes)) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Name</th><th>Grade Level</th><th>Academic Year</th><th>Status</th></tr>";
            foreach ($classes as $class) {
                $status_class = $class['status'] === 'active' ? 'success' : 'warning';
                echo "<tr>";
                echo "<td>{$class['id']}</td>";
                echo "<td>{$class['name']}</td>";
                echo "<td>{$class['grade_level']}</td>";
                echo "<td>{$class['academic_year']}</td>";
                echo "<td class='$status_class'>{$class['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            $active_count = count(array_filter($classes, function($c) { return $c['status'] === 'active'; }));
            echo "<p class='info'>Total classes: " . count($classes) . " | Active classes: $active_count</p>";
        } else {
            echo "<div class='error'>❌ No classes found</div>";
            echo "<p>You need to create classes before enrolling students.</p>";
        }
        echo "</div>";
        
        // Check parents
        echo "<div class='section'>";
        echo "<h2>Available Parents</h2>";
        $parents_query = "SELECT id, name, email, status FROM users WHERE role = 'parent' ORDER BY name";
        $parents_stmt = $db->query($parents_query);
        $parents = $parents_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($parents)) {
            echo "<table>";
            echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Status</th></tr>";
            foreach ($parents as $parent) {
                $status_class = $parent['status'] === 'active' ? 'success' : 'warning';
                echo "<tr>";
                echo "<td>{$parent['id']}</td>";
                echo "<td>{$parent['name']}</td>";
                echo "<td>{$parent['email']}</td>";
                echo "<td class='$status_class'>{$parent['status']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            
            $active_count = count(array_filter($parents, function($p) { return $p['status'] === 'active'; }));
            echo "<p class='info'>Total parents: " . count($parents) . " | Active parents: $active_count</p>";
        } else {
            echo "<div class='warning'>⚠️ No parent accounts found</div>";
            echo "<p>Parent accounts are optional but recommended for student enrollment.</p>";
        }
        echo "</div>";
        
        // Check student_classes table structure
        echo "<div class='section'>";
        echo "<h2>Student Classes Table Structure</h2>";
        $structure_query = "DESCRIBE student_classes";
        $structure_stmt = $db->query($structure_query);
        $columns = $structure_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table>";
        echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>{$column['Field']}</td>";
            echo "<td>{$column['Type']}</td>";
            echo "<td>{$column['Null']}</td>";
            echo "<td>{$column['Key']}</td>";
            echo "<td>{$column['Default']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        echo "</div>";
        
        // Test enrollment data
        echo "<div class='section'>";
        echo "<h2>Test Enrollment Data</h2>";
        echo "<p>Sample data you can use for testing:</p>";
        echo "<ul>";
        echo "<li><strong>Name:</strong> Test Student</li>";
        echo "<li><strong>Email:</strong> test.student@example.com</li>";
        echo "<li><strong>Password:</strong> password123</li>";
        echo "<li><strong>Date of Birth:</strong> 2010-01-01</li>";
        if (!empty($classes)) {
            $first_active_class = null;
            foreach ($classes as $class) {
                if ($class['status'] === 'active') {
                    $first_active_class = $class;
                    break;
                }
            }
            if ($first_active_class) {
                echo "<li><strong>Class:</strong> {$first_active_class['name']} (ID: {$first_active_class['id']})</li>";
            }
        }
        echo "<li><strong>Admission Date:</strong> " . date('Y-m-d') . "</li>";
        echo "</ul>";
        echo "</div>";
        
    } catch (Exception $e) {
        echo "<div class='error'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
    ?>
    
    <div class="section">
        <h2>Quick Actions</h2>
        <p><a href="students/enroll.php">Try Student Enrollment</a></p>
        <p><a href="academic/classes/create.php">Create New Class</a></p>
        <p><a href="check_database.php">Check Database Status</a></p>
    </div>
</body>
</html>
