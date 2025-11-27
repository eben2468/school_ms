<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Student Enrollment</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        .debug { background: #f5f5f5; padding: 10px; margin: 10px 0; border-radius: 5px; }
        form { background: #f9f9f9; padding: 20px; border-radius: 5px; }
        input, select, textarea { width: 100%; padding: 8px; margin: 5px 0; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; }
        button:hover { background: #005a87; }
    </style>
</head>
<body>
    <h1>Test Student Enrollment with Debug Info</h1>
    
    <?php
    require_once 'config/database.php';
    
    $debug_info = [];
    $errors = [];
    $success_message = '';
    
    try {
        $database = new Database();
        $db = $database->getConnection();
        $debug_info[] = "✅ Database connection successful";
        
        // Fetch active classes
        $classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
        $classes_stmt = $db->query($classes_query);
        $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info[] = "📚 Found " . count($classes) . " active classes";
        
        // Fetch parents
        $parents_query = "SELECT id, name, email FROM users WHERE role = 'parent' AND status = 'active' ORDER BY name";
        $parents_stmt = $db->query($parents_query);
        $parents = $parents_stmt->fetchAll(PDO::FETCH_ASSOC);
        $debug_info[] = "👨‍👩‍👧‍👦 Found " . count($parents) . " active parents";
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $debug_info[] = "📝 Processing form submission...";
            
            // Collect form data
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $email = filter_input(INPUT_POST, 'email', FILTER_SANITIZE_EMAIL);
            $password = $_POST['password'];
            $date_of_birth = filter_input(INPUT_POST, 'date_of_birth', FILTER_SANITIZE_STRING);
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
            $parent_id = filter_input(INPUT_POST, 'parent_id', FILTER_SANITIZE_NUMBER_INT);
            $admission_date = filter_input(INPUT_POST, 'admission_date', FILTER_SANITIZE_STRING);
            
            $debug_info[] = "📋 Form data collected: name=$name, email=$email, class_id=$class_id, parent_id=$parent_id";
            
            // Basic validation
            if (empty($name)) $errors[] = "Student name is required.";
            if (empty($email)) $errors[] = "Email is required.";
            if (empty($password)) $errors[] = "Password is required.";
            if (empty($date_of_birth)) $errors[] = "Date of birth is required.";
            if (empty($class_id)) $errors[] = "Class selection is required.";
            if (empty($admission_date)) $errors[] = "Admission date is required.";
            
            $debug_info[] = "✅ Basic validation completed. Errors: " . count($errors);
            
            // Check if email already exists
            if (!empty($email)) {
                $email_check = "SELECT id FROM users WHERE email = :email";
                $email_stmt = $db->prepare($email_check);
                $email_stmt->bindParam(':email', $email);
                $email_stmt->execute();
                if ($email_stmt->rowCount() > 0) {
                    $errors[] = "Email address already exists.";
                    $debug_info[] = "❌ Email already exists in database";
                } else {
                    $debug_info[] = "✅ Email is unique";
                }
            }
            
            // Validate class ID exists and is active
            if (!empty($class_id)) {
                $class_check = "SELECT id, name FROM classes WHERE id = :class_id AND status = 'active'";
                $class_stmt = $db->prepare($class_check);
                $class_stmt->bindParam(':class_id', $class_id);
                $class_stmt->execute();
                $class_result = $class_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$class_result) {
                    $errors[] = "Selected class is not valid or inactive.";
                    $debug_info[] = "❌ Class ID $class_id not found or inactive";
                } else {
                    $debug_info[] = "✅ Class validated: " . $class_result['name'];
                }
            }
            
            // Validate parent ID if provided
            if (!empty($parent_id)) {
                $parent_check = "SELECT id, name FROM users WHERE id = :parent_id AND role = 'parent' AND status = 'active'";
                $parent_stmt = $db->prepare($parent_check);
                $parent_stmt->bindParam(':parent_id', $parent_id);
                $parent_stmt->execute();
                $parent_result = $parent_stmt->fetch(PDO::FETCH_ASSOC);
                if (!$parent_result) {
                    $errors[] = "Selected parent is not valid or inactive.";
                    $debug_info[] = "❌ Parent ID $parent_id not found or inactive";
                } else {
                    $debug_info[] = "✅ Parent validated: " . $parent_result['name'];
                }
            } else {
                $debug_info[] = "ℹ️ No parent selected (optional)";
            }
            
            if (empty($errors)) {
                $debug_info[] = "🚀 All validations passed, attempting enrollment...";
                
                try {
                    $db->beginTransaction();
                    $debug_info[] = "📊 Database transaction started";
                    
                    // Generate student ID using the new format STU20254927
                    $student_id = $database->generateStudentId();
                    $debug_info[] = "🆔 Generated student ID: $student_id";
                    
                    // Create user account
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $user_query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, 'student', 'active')";
                    $user_stmt = $db->prepare($user_query);
                    $user_stmt->bindParam(':name', $name);
                    $user_stmt->bindParam(':email', $email);
                    $user_stmt->bindParam(':password', $hashed_password);
                    $user_stmt->execute();
                    $user_id = $db->lastInsertId();
                    $debug_info[] = "👤 User account created with ID: $user_id";
                    
                    // Create student profile
                    $profile_query = "INSERT INTO student_profiles (user_id, student_id, admission_date, date_of_birth, parent_id) VALUES (:user_id, :student_id, :admission_date, :date_of_birth, :parent_id)";
                    $profile_stmt = $db->prepare($profile_query);
                    $profile_stmt->bindParam(':user_id', $user_id);
                    $profile_stmt->bindParam(':student_id', $student_id);
                    $profile_stmt->bindParam(':admission_date', $admission_date);
                    $profile_stmt->bindParam(':date_of_birth', $date_of_birth);
                    $profile_stmt->bindParam(':parent_id', $parent_id);
                    $profile_stmt->execute();
                    $debug_info[] = "📝 Student profile created";
                    
                    // Assign to class
                    $class_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
                    $class_stmt = $db->prepare($class_query);
                    $class_stmt->bindParam(':student_id', $user_id);
                    $class_stmt->bindParam(':class_id', $class_id);
                    $class_stmt->execute();
                    $debug_info[] = "🏫 Student assigned to class";
                    
                    $db->commit();
                    $debug_info[] = "✅ Transaction committed successfully";
                    $success_message = "Student enrolled successfully with ID: $student_id";
                    
                } catch (PDOException $e) {
                    $db->rollBack();
                    $debug_info[] = "❌ Database error: " . $e->getMessage();
                    $errors[] = "Database error: " . $e->getMessage();
                }
            } else {
                $debug_info[] = "❌ Validation failed, not proceeding with enrollment";
            }
        }
        
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
    
    <!-- Enrollment Form -->
    <div class="section">
        <h2>Test Enrollment Form</h2>
        <form method="POST">
            <label>Student Name *</label>
            <input type="text" name="name" value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : 'Test Student'; ?>" required>
            
            <label>Email *</label>
            <input type="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : 'test.student' . rand(1000,9999) . '@example.com'; ?>" required>
            
            <label>Password *</label>
            <input type="password" name="password" value="password123" required>
            
            <label>Date of Birth *</label>
            <input type="date" name="date_of_birth" value="<?php echo isset($_POST['date_of_birth']) ? htmlspecialchars($_POST['date_of_birth']) : '2010-01-01'; ?>" required>
            
            <label>Class *</label>
            <select name="class_id" required>
                <option value="">Select Class</option>
                <?php foreach ($classes as $class): ?>
                <option value="<?php echo $class['id']; ?>" <?php echo (isset($_POST['class_id']) && $_POST['class_id'] == $class['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <label>Parent (Optional)</label>
            <select name="parent_id">
                <option value="">Select Parent (Optional)</option>
                <?php foreach ($parents as $parent): ?>
                <option value="<?php echo $parent['id']; ?>" <?php echo (isset($_POST['parent_id']) && $_POST['parent_id'] == $parent['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($parent['name'] . ' (' . $parent['email'] . ')'); ?>
                </option>
                <?php endforeach; ?>
            </select>
            
            <label>Admission Date *</label>
            <input type="date" name="admission_date" value="<?php echo isset($_POST['admission_date']) ? htmlspecialchars($_POST['admission_date']) : date('Y-m-d'); ?>" required>
            
            <button type="submit">Test Enrollment</button>
        </form>
    </div>
    
    <div class="section">
        <h2>Quick Links</h2>
        <p><a href="students/enroll.php">Go to Main Enrollment Page</a></p>
        <p><a href="debug_enrollment.php">View Database Debug Info</a></p>
        <p><a href="academic/classes/create.php">Create New Class</a></p>
    </div>
</body>
</html>
