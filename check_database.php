<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Status Check</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
    </style>
</head>
<body>
    <h1>School Management System - Database Status</h1>
    
    <?php
    $errors = [];
    $warnings = [];
    $success = [];
    
    // Check database connection
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=school_ms', 'root', '');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $success[] = "Database connection successful";
        
        // Check required tables
        $required_tables = [
            'users' => 'User accounts',
            'classes' => 'School classes',
            'student_profiles' => 'Student profile information',
            'student_classes' => 'Student-class assignments',
            'subjects' => 'School subjects'
        ];
        
        foreach ($required_tables as $table => $description) {
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
                if ($stmt->rowCount() > 0) {
                    // Check record count
                    $count_stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                    $count = $count_stmt->fetch(PDO::FETCH_ASSOC)['count'];
                    $success[] = "Table '$table' exists with $count records ($description)";
                } else {
                    $errors[] = "Table '$table' is missing ($description)";
                }
            } catch (PDOException $e) {
                $errors[] = "Error checking table '$table': " . $e->getMessage();
            }
        }
        
        // Check for active classes specifically
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM classes WHERE status = 'active'");
            $active_classes = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            if ($active_classes > 0) {
                $success[] = "Found $active_classes active classes";
            } else {
                $warnings[] = "No active classes found - students cannot be enrolled without classes";
            }
        } catch (PDOException $e) {
            $errors[] = "Error checking active classes: " . $e->getMessage();
        }
        
        // Check for parent users
        try {
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE role = 'parent' AND status = 'active'");
            $parent_count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
            if ($parent_count > 0) {
                $success[] = "Found $parent_count active parent accounts";
            } else {
                $warnings[] = "No parent accounts found - consider creating parent accounts for student enrollment";
            }
        } catch (PDOException $e) {
            $warnings[] = "Could not check parent accounts: " . $e->getMessage();
        }
        
    } catch (PDOException $e) {
        $errors[] = "Database connection failed: " . $e->getMessage();
        $errors[] = "Please ensure XAMPP MySQL is running and the database 'school_ms' exists";
    }
    ?>
    
    <?php if (!empty($errors)): ?>
    <div class="section">
        <h2 class="error">❌ Errors Found</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
            <li class="error"><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
        
        <?php if (strpos(implode(' ', $errors), 'missing') !== false): ?>
        <p><strong>Solution:</strong> Run the database setup script:</p>
        <ol>
            <li>Open command prompt in the school_ms directory</li>
            <li>Run: <code>php setup_database.php</code></li>
            <li>Or run: <code>setup_database.bat</code></li>
        </ol>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($warnings)): ?>
    <div class="section">
        <h2 class="warning">⚠️ Warnings</h2>
        <ul>
            <?php foreach ($warnings as $warning): ?>
            <li class="warning"><?php echo htmlspecialchars($warning); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($success)): ?>
    <div class="section">
        <h2 class="success">✅ Status OK</h2>
        <ul>
            <?php foreach ($success as $item): ?>
            <li class="success"><?php echo htmlspecialchars($item); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>Quick Actions</h2>
        <p><a href="students/enroll.php">Try Student Enrollment</a></p>
        <p><a href="index.php">Go to Dashboard</a></p>
        <?php if (!empty($errors)): ?>
        <p><strong>Setup Database:</strong> <a href="setup_database.php">Run Database Setup</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
