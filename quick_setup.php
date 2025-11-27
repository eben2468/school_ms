<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Database Setup & Class Creation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        button:hover { background: #005a87; }
        .setup-button { background: #28a745; }
        .setup-button:hover { background: #218838; }
    </style>
</head>
<body>
    <h1>Quick Setup: Database & Classes</h1>
    
    <?php
    $action = $_GET['action'] ?? '';
    $messages = [];
    
    if ($action === 'setup_db') {
        try {
            $host = 'localhost';
            $username = 'root';
            $password = '';
            
            // Create connection
            $conn = new PDO("mysql:host=$host", $username, $password);
            $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            if (file_exists('config/schema.sql')) {
                $sql = file_get_contents('config/schema.sql');
                $conn->exec($sql);
                $messages[] = ['success', '✅ Database setup completed successfully!'];
            } else {
                $messages[] = ['error', '❌ Schema file not found!'];
            }
            
        } catch(PDOException $e) {
            $messages[] = ['error', '❌ Database setup error: ' . $e->getMessage()];
        }
    }
    
    if ($action === 'create_classes') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            $classes_to_create = [
                ['Grade 1A', 'Grade 1', '2025-2026'],
                ['Grade 1B', 'Grade 1', '2025-2026'],
                ['Grade 2A', 'Grade 2', '2025-2026'],
                ['Grade 3A', 'Grade 3', '2025-2026'],
                ['Grade 4A', 'Grade 4', '2025-2026'],
                ['Grade 5A', 'Grade 5', '2025-2026'],
            ];
            
            $created_count = 0;
            foreach ($classes_to_create as $class_data) {
                try {
                    $query = "INSERT INTO classes (name, grade_level, academic_year, status) VALUES (?, ?, ?, 'active')";
                    $stmt = $db->prepare($query);
                    $stmt->execute($class_data);
                    $created_count++;
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        throw $e; // Re-throw if it's not a duplicate entry error
                    }
                    // Ignore duplicate entries
                }
            }
            
            $messages[] = ['success', "✅ Created $created_count new classes (duplicates skipped)"];
            
        } catch (Exception $e) {
            $messages[] = ['error', '❌ Error creating classes: ' . $e->getMessage()];
        }
    }
    
    // Check current status
    try {
        require_once 'config/database.php';
        $database = new Database();
        $db = $database->getConnection();
        
        // Check tables
        $required_tables = ['users', 'classes', 'subjects', 'class_teachers', 'student_profiles', 'student_classes'];
        $existing_tables = [];
        $missing_tables = [];
        
        foreach ($required_tables as $table) {
            $stmt = $db->query("SHOW TABLES LIKE '$table'");
            if ($stmt->rowCount() > 0) {
                $existing_tables[] = $table;
            } else {
                $missing_tables[] = $table;
            }
        }
        
        // Check classes
        $classes_stmt = $db->query("SELECT COUNT(*) as count FROM classes WHERE status = 'active'");
        $classes_count = $classes_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Check subjects
        $subjects_stmt = $db->query("SELECT COUNT(*) as count FROM subjects");
        $subjects_count = $subjects_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        // Check users
        $users_stmt = $db->query("SELECT COUNT(*) as count FROM users");
        $users_count = $users_stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
    } catch (Exception $e) {
        $db_error = $e->getMessage();
    }
    ?>
    
    <!-- Messages -->
    <?php foreach ($messages as $message): ?>
    <div class="section">
        <div class="<?php echo $message[0]; ?>"><?php echo htmlspecialchars($message[1]); ?></div>
    </div>
    <?php endforeach; ?>
    
    <!-- Database Status -->
    <div class="section">
        <h2>Database Status</h2>
        <?php if (isset($db_error)): ?>
            <div class="error">❌ Database connection failed: <?php echo htmlspecialchars($db_error); ?></div>
            <p><strong>Action needed:</strong> Set up the database first.</p>
        <?php else: ?>
            <div class="success">✅ Database connection successful</div>
            
            <h3>Tables Status:</h3>
            <ul>
                <?php foreach ($existing_tables as $table): ?>
                <li class="success">✅ <?php echo $table; ?> - EXISTS</li>
                <?php endforeach; ?>
                <?php foreach ($missing_tables as $table): ?>
                <li class="error">❌ <?php echo $table; ?> - MISSING</li>
                <?php endforeach; ?>
            </ul>
            
            <h3>Data Status:</h3>
            <ul>
                <li class="<?php echo $users_count > 0 ? 'success' : 'warning'; ?>">
                    👥 Users: <?php echo $users_count; ?> records
                </li>
                <li class="<?php echo $classes_count > 0 ? 'success' : 'warning'; ?>">
                    🏫 Active Classes: <?php echo $classes_count; ?> records
                </li>
                <li class="<?php echo $subjects_count > 0 ? 'success' : 'warning'; ?>">
                    📚 Subjects: <?php echo $subjects_count; ?> records
                </li>
            </ul>
        <?php endif; ?>
    </div>
    
    <!-- Quick Actions -->
    <div class="section">
        <h2>Quick Setup Actions</h2>
        
        <?php if (isset($db_error) || !empty($missing_tables)): ?>
        <div class="warning">⚠️ Database setup required</div>
        <a href="?action=setup_db">
            <button class="setup-button">🚀 Setup Database</button>
        </a>
        <?php endif; ?>
        
        <?php if (!isset($db_error) && empty($missing_tables) && $classes_count == 0): ?>
        <div class="warning">⚠️ No classes found</div>
        <a href="?action=create_classes">
            <button class="setup-button">🏫 Create Sample Classes</button>
        </a>
        <?php endif; ?>
        
        <?php if (!isset($db_error) && empty($missing_tables) && $classes_count > 0): ?>
        <div class="success">✅ System ready for student enrollment!</div>
        <?php endif; ?>
    </div>
    
    <!-- Navigation -->
    <div class="section">
        <h2>Navigation</h2>
        <a href="create_simple_class.php"><button>➕ Create Individual Class</button></a>
        <a href="test_enrollment.php"><button>👨‍🎓 Test Student Enrollment</button></a>
        <a href="students/enroll.php"><button>📝 Student Enrollment Form</button></a>
        <a href="debug_enrollment.php"><button>🔍 Debug Information</button></a>
        <a href="check_database.php"><button>🗄️ Database Status</button></a>
    </div>
    
    <!-- Instructions -->
    <div class="section">
        <h2>Setup Instructions</h2>
        <ol>
            <li><strong>Setup Database:</strong> Click "Setup Database" if tables are missing</li>
            <li><strong>Create Classes:</strong> Click "Create Sample Classes" to add basic classes</li>
            <li><strong>Test Enrollment:</strong> Use "Test Student Enrollment" to verify everything works</li>
            <li><strong>Use System:</strong> Go to "Student Enrollment Form" for normal use</li>
        </ol>
    </div>
</body>
</html>
