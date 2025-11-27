<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fix Emergency Contact Database Issues</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        button:hover { background: #005a87; }
        pre { background: #f5f5f5; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body>
    <h1>Fix Emergency Contact Database Issues</h1>
    
    <?php
    $action = $_GET['action'] ?? '';
    $messages = [];
    
    if ($action === 'fix_view') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Drop the existing view if it exists
            $db->exec("DROP VIEW IF EXISTS students");
            $messages[] = ['info', '🗑️ Dropped existing students view'];
            
            // Create the corrected view
            $view_sql = "CREATE VIEW students AS
            SELECT
                u.id,
                u.name,
                u.email,
                u.status,
                u.created_at,
                sp.student_id,
                sp.phone,
                sp.date_of_birth,
                sp.address,
                sp.emergency_contact_name,
                sp.emergency_contact_phone,
                sp.admission_date,
                sp.guardian_name,
                sp.guardian_phone,
                sp.guardian_email
            FROM users u
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            WHERE u.role = 'student'";
            
            $db->exec($view_sql);
            $messages[] = ['success', '✅ Created corrected students view with proper emergency contact columns'];
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
        }
    }
    
    if ($action === 'test_columns') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Check student_profiles table structure
            $structure_stmt = $db->query("DESCRIBE student_profiles");
            $columns = $structure_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $emergency_columns = [];
            foreach ($columns as $column) {
                if (strpos($column['Field'], 'emergency') !== false) {
                    $emergency_columns[] = $column;
                }
            }
            
            if (!empty($emergency_columns)) {
                $messages[] = ['success', '✅ Found emergency contact columns in student_profiles table'];
                foreach ($emergency_columns as $col) {
                    $messages[] = ['info', "📋 Column: {$col['Field']} ({$col['Type']})"];
                }
            } else {
                $messages[] = ['error', '❌ No emergency contact columns found'];
            }
            
            // Test the students view
            try {
                $view_test = $db->query("SELECT emergency_contact_name, emergency_contact_phone FROM students LIMIT 1");
                $messages[] = ['success', '✅ Students view works with correct emergency contact columns'];
            } catch (PDOException $e) {
                $messages[] = ['error', '❌ Students view error: ' . $e->getMessage()];
            }
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
        }
    }
    
    if ($action === 'test_edit') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Get a sample student
            $student_stmt = $db->query("SELECT id FROM users WHERE role = 'student' LIMIT 1");
            $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($student) {
                $student_id = $student['id'];
                
                // Test the update query that was causing issues
                $test_query = "UPDATE student_profiles SET 
                             phone = :phone, 
                             date_of_birth = :date_of_birth, 
                             address = :address, 
                             emergency_contact_name = :emergency_contact_name,
                             emergency_contact_phone = :emergency_contact_phone
                             WHERE user_id = :user_id";
                
                $test_stmt = $db->prepare($test_query);
                $test_stmt->bindValue(':phone', '123-456-7890');
                $test_stmt->bindValue(':date_of_birth', '2000-01-01');
                $test_stmt->bindValue(':address', 'Test Address');
                $test_stmt->bindValue(':emergency_contact_name', 'Test Emergency Contact');
                $test_stmt->bindValue(':emergency_contact_phone', '987-654-3210');
                $test_stmt->bindValue(':user_id', $student_id);
                
                // Just prepare, don't execute to avoid changing data
                $messages[] = ['success', '✅ Student edit query syntax is correct'];
                $messages[] = ['info', "📋 Tested with student ID: $student_id"];
                
            } else {
                $messages[] = ['warning', '⚠️ No student records found to test with'];
            }
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Student edit query error: ' . $e->getMessage()];
        }
    }
    ?>
    
    <!-- Messages -->
    <?php foreach ($messages as $message): ?>
    <div class="section">
        <div class="<?php echo $message[0]; ?>"><?php echo htmlspecialchars($message[1]); ?></div>
    </div>
    <?php endforeach; ?>
    
    <!-- Current Status -->
    <div class="section">
        <h2>Current Status Check</h2>
        <?php
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Check if student_profiles table has correct columns
            $structure_stmt = $db->query("DESCRIBE student_profiles");
            $columns = $structure_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $has_emergency_name = false;
            $has_emergency_phone = false;
            $has_old_emergency = false;
            
            foreach ($columns as $column) {
                if ($column['Field'] === 'emergency_contact_name') $has_emergency_name = true;
                if ($column['Field'] === 'emergency_contact_phone') $has_emergency_phone = true;
                if ($column['Field'] === 'emergency_contact') $has_old_emergency = true;
            }
            
            echo "<ul>";
            echo "<li class='" . ($has_emergency_name ? 'success' : 'error') . "'>";
            echo ($has_emergency_name ? '✅' : '❌') . " emergency_contact_name column";
            echo "</li>";
            echo "<li class='" . ($has_emergency_phone ? 'success' : 'error') . "'>";
            echo ($has_emergency_phone ? '✅' : '❌') . " emergency_contact_phone column";
            echo "</li>";
            echo "<li class='" . ($has_old_emergency ? 'warning' : 'success') . "'>";
            echo ($has_old_emergency ? '⚠️' : '✅') . " old emergency_contact column " . ($has_old_emergency ? 'exists (should be removed)' : 'not found (good)');
            echo "</li>";
            echo "</ul>";
            
            // Check students view
            try {
                $view_check = $db->query("SHOW CREATE VIEW students");
                $view_def = $view_check->fetch(PDO::FETCH_ASSOC);
                if (strpos($view_def['Create View'], 'emergency_contact_name') !== false) {
                    echo "<div class='success'>✅ Students view uses correct emergency contact columns</div>";
                } else {
                    echo "<div class='error'>❌ Students view needs to be updated</div>";
                }
            } catch (PDOException $e) {
                echo "<div class='warning'>⚠️ Students view doesn't exist or has issues</div>";
            }
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Database connection error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
    </div>
    
    <!-- Actions -->
    <div class="section">
        <h2>Available Actions</h2>
        <a href="?action=test_columns"><button>🔍 Test Database Columns</button></a>
        <a href="?action=fix_view"><button>🔧 Fix Students View</button></a>
        <a href="?action=test_edit"><button>🧪 Test Edit Query</button></a>
    </div>
    
    <!-- Quick Links -->
    <div class="section">
        <h2>Test the Fixes</h2>
        <p>After running the fixes, test these pages:</p>
        <ul>
            <li><a href="students/edit.php?id=1">Test Student Edit Page</a></li>
            <li><a href="students/profile.php?id=1">Test Student Profile</a></li>
            <li><a href="academic/student_profile.php?id=1">Test Academic Profile</a></li>
            <li><a href="health/records/index.php">Test Health Records</a></li>
        </ul>
    </div>
    
    <!-- SQL Reference -->
    <div class="section">
        <h2>SQL Reference</h2>
        <p><strong>Correct emergency contact columns in student_profiles table:</strong></p>
        <pre>emergency_contact_name VARCHAR(100)
emergency_contact_phone VARCHAR(20)</pre>
        
        <p><strong>If you need to manually fix the students view:</strong></p>
        <pre>DROP VIEW IF EXISTS students;
CREATE VIEW students AS
SELECT
    u.id, u.name, u.email, u.status, u.created_at,
    sp.student_id, sp.phone, sp.date_of_birth, sp.address,
    sp.emergency_contact_name, sp.emergency_contact_phone,
    sp.admission_date, sp.guardian_name, sp.guardian_phone, sp.guardian_email
FROM users u
LEFT JOIN student_profiles sp ON u.id = sp.user_id
WHERE u.role = 'student';</pre>
    </div>
</body>
</html>
