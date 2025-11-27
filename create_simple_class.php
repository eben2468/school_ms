<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Class Creation</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .debug { background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 5px; }
        form { background: #f9f9f9; padding: 20px; border-radius: 5px; }
        input, select { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #005a87; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>Simple Class Creation (No Teacher Assignment)</h1>
    
    <?php
    session_start();
    
    // Simple authentication check - allow if logged in or bypass for testing
    $is_authenticated = isset($_SESSION['user_id']) || true; // Allow for testing
    
    if (!$is_authenticated) {
        echo "<div class='error'>Please log in first.</div>";
        exit();
    }
    
    require_once 'config/database.php';
    
    $debug_info = [];
    $errors = [];
    $success_message = '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        $debug_info[] = "✅ Database connection successful";
        
        // Check if classes table exists
        $table_check = $db->query("SHOW TABLES LIKE 'classes'");
        if ($table_check->rowCount() > 0) {
            $debug_info[] = "✅ Classes table exists";
        } else {
            $errors[] = "❌ Classes table does not exist";
        }
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $debug_info[] = "📝 Processing form submission...";
            
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $grade_level = filter_input(INPUT_POST, 'grade_level', FILTER_SANITIZE_STRING);
            $academic_year = filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_STRING);
            
            $debug_info[] = "📋 Form data: name='$name', grade_level='$grade_level', academic_year='$academic_year'";
            
            // Basic validation
            if (empty($name)) $errors[] = "Class name is required.";
            if (empty($grade_level)) $errors[] = "Grade level is required.";
            if (empty($academic_year)) $errors[] = "Academic year is required.";
            
            if (empty($errors)) {
                try {
                    $debug_info[] = "🚀 Attempting to create class...";
                    
                    // Simple insert without teacher assignments
                    $query = "INSERT INTO classes (name, grade_level, academic_year, status) VALUES (:name, :grade_level, :academic_year, 'active')";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':name', $name);
                    $stmt->bindParam(':grade_level', $grade_level);
                    $stmt->bindParam(':academic_year', $academic_year);
                    $stmt->execute();
                    
                    $class_id = $db->lastInsertId();
                    $debug_info[] = "✅ Class created successfully with ID: $class_id";
                    $success_message = "Class '$name' created successfully with ID: $class_id";
                    
                } catch (PDOException $e) {
                    $debug_info[] = "❌ Database error: " . $e->getMessage();
                    $errors[] = "Database error: " . $e->getMessage();
                }
            } else {
                $debug_info[] = "❌ Validation failed";
            }
        }
        
        // Show existing classes
        $debug_info[] = "📚 Fetching existing classes...";
        $classes_query = "SELECT id, name, grade_level, academic_year, status, created_at FROM classes ORDER BY grade_level, name";
        $classes_stmt = $db->query($classes_query);
        $existing_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info[] = "📚 Found " . count($existing_classes) . " existing classes";
        
    } catch (Exception $e) {
        $debug_info[] = "❌ Fatal error: " . $e->getMessage();
        $errors[] = "System error: " . $e->getMessage();
    }
    ?>
    
    <!-- Debug Information -->
    <div class="section">
        <h2>Debug Information</h2>
        <div class="debug">
            <?php foreach ($debug_info as $info): ?>
                <div><?php echo htmlspecialchars($info); ?></div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Error Messages -->
    <?php if (!empty($errors)): ?>
    <div class="section">
        <h2 class="error">❌ Errors</h2>
        <ul>
            <?php foreach ($errors as $error): ?>
            <li class="error"><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <!-- Success Message -->
    <?php if (!empty($success_message)): ?>
    <div class="section">
        <h2 class="success">✅ Success</h2>
        <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Class Creation Form -->
    <div class="section">
        <h2>Create New Class</h2>
        <form method="POST">
            <label>Class Name *</label>
            <input type="text" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : 'Grade 1A'; ?>" required placeholder="e.g., Grade 1A, Class 5B">
            
            <label>Grade Level *</label>
            <input type="text" name="grade_level" value="<?php echo isset($_POST['grade_level']) ? htmlspecialchars($_POST['grade_level']) : 'Grade 1'; ?>" required placeholder="e.g., Grade 1, Grade 2">
            
            <label>Academic Year *</label>
            <input type="text" name="academic_year" value="<?php echo isset($_POST['academic_year']) ? htmlspecialchars($_POST['academic_year']) : date('Y') . '-' . (date('Y') + 1); ?>" required placeholder="e.g., 2025-2026">
            
            <button type="submit">Create Class</button>
        </form>
    </div>
    
    <!-- Existing Classes -->
    <?php if (!empty($existing_classes)): ?>
    <div class="section">
        <h2>Existing Classes</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Grade Level</th>
                <th>Academic Year</th>
                <th>Status</th>
                <th>Created</th>
            </tr>
            <?php foreach ($existing_classes as $class): ?>
            <tr>
                <td><?php echo $class['id']; ?></td>
                <td><?php echo htmlspecialchars($class['name']); ?></td>
                <td><?php echo htmlspecialchars($class['grade_level']); ?></td>
                <td><?php echo htmlspecialchars($class['academic_year']); ?></td>
                <td class="<?php echo $class['status'] === 'active' ? 'success' : 'warning'; ?>">
                    <?php echo $class['status']; ?>
                </td>
                <td><?php echo date('M j, Y', strtotime($class['created_at'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
    
    <div class="section">
        <h2>Quick Actions</h2>
        <p><a href="academic/classes/create.php">Go to Full Class Creation Page</a></p>
        <p><a href="test_enrollment.php">Test Student Enrollment</a></p>
        <p><a href="debug_enrollment.php">View Database Debug Info</a></p>
        <p><a href="students/enroll.php">Student Enrollment</a></p>
    </div>
    
    <div class="section">
        <h2>Pre-made Classes</h2>
        <p>Click the buttons below to quickly create some standard classes:</p>
        <form method="POST" style="display: inline;">
            <input type="hidden" name="name" value="Grade 1A">
            <input type="hidden" name="grade_level" value="Grade 1">
            <input type="hidden" name="academic_year" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>">
            <button type="submit">Create Grade 1A</button>
        </form>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="name" value="Grade 2A">
            <input type="hidden" name="grade_level" value="Grade 2">
            <input type="hidden" name="academic_year" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>">
            <button type="submit">Create Grade 2A</button>
        </form>
        
        <form method="POST" style="display: inline;">
            <input type="hidden" name="name" value="Grade 3A">
            <input type="hidden" name="grade_level" value="Grade 3">
            <input type="hidden" name="academic_year" value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>">
            <button type="submit">Create Grade 3A</button>
        </form>
    </div>
</body>
</html>
