<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test New User Roles - Transport Officer & Hostel Warden</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
        .info { color: blue; }
        .section { margin: 20px 0; padding: 15px; border: 1px solid #ddd; border-radius: 5px; }
        button { background: #007cba; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; margin: 5px; }
        button:hover { background: #005a87; }
        .create-button { background: #28a745; }
        .create-button:hover { background: #218838; }
        table { border-collapse: collapse; width: 100%; margin: 10px 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #f2f2f2; }
        .role-badge { padding: 4px 8px; border-radius: 4px; color: white; font-size: 12px; }
        .transport-officer { background-color: #17a2b8; }
        .hostel-warden { background-color: #28a745; }
    </style>
</head>
<body>
    <h1>Test New User Roles: Transport Officer & Hostel Warden</h1>
    
    <?php
    $action = $_GET['action'] ?? '';
    $messages = [];
    
    if ($action === 'create_test_users') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Create test Transport Officer
            $transport_email = 'transport.officer@school.com';
            $hostel_email = 'hostel.warden@school.com';
            
            // Check if users already exist
            $check_query = "SELECT email, role FROM users WHERE email IN (:transport_email, :hostel_email)";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':transport_email', $transport_email);
            $check_stmt->bindParam(':hostel_email', $hostel_email);
            $check_stmt->execute();
            $existing_users = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $created_count = 0;
            
            // Create Transport Officer if doesn't exist
            $transport_exists = false;
            foreach ($existing_users as $user) {
                if ($user['email'] === $transport_email) {
                    $transport_exists = true;
                    $messages[] = ['warning', "Transport Officer user already exists: $transport_email"];
                    break;
                }
            }
            
            if (!$transport_exists) {
                $password = password_hash('password123', PASSWORD_DEFAULT);
                $insert_query = "INSERT INTO users (name, email, password, role, status) VALUES (:name, :email, :password, :role, 'active')";
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindValue(':name', 'Test Transport Officer');
                $insert_stmt->bindValue(':email', $transport_email);
                $insert_stmt->bindValue(':password', $password);
                $insert_stmt->bindValue(':role', 'transport_officer');
                $insert_stmt->execute();
                $created_count++;
                $messages[] = ['success', "✅ Created Transport Officer: $transport_email (password: password123)"];
            }
            
            // Create Hostel Warden if doesn't exist
            $hostel_exists = false;
            foreach ($existing_users as $user) {
                if ($user['email'] === $hostel_email) {
                    $hostel_exists = true;
                    $messages[] = ['warning', "Hostel Warden user already exists: $hostel_email"];
                    break;
                }
            }
            
            if (!$hostel_exists) {
                $password = password_hash('password123', PASSWORD_DEFAULT);
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindValue(':name', 'Test Hostel Warden');
                $insert_stmt->bindValue(':email', $hostel_email);
                $insert_stmt->bindValue(':password', $password);
                $insert_stmt->bindValue(':role', 'hostel_warden');
                $insert_stmt->execute();
                $created_count++;
                $messages[] = ['success', "✅ Created Hostel Warden: $hostel_email (password: password123)"];
            }
            
            if ($created_count > 0) {
                $messages[] = ['info', "🎉 Created $created_count new test users. You can now test their access!"];
            }
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
        }
    }
    
    if ($action === 'check_database') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Check if the roles exist in the ENUM
            $enum_query = "SHOW COLUMNS FROM users LIKE 'role'";
            $enum_stmt = $db->query($enum_query);
            $enum_result = $enum_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($enum_result) {
                $enum_values = $enum_result['Type'];
                if (strpos($enum_values, 'transport_officer') !== false) {
                    $messages[] = ['success', '✅ transport_officer role exists in database ENUM'];
                } else {
                    $messages[] = ['error', '❌ transport_officer role missing from database ENUM'];
                }
                
                if (strpos($enum_values, 'hostel_warden') !== false) {
                    $messages[] = ['success', '✅ hostel_warden role exists in database ENUM'];
                } else {
                    $messages[] = ['error', '❌ hostel_warden role missing from database ENUM'];
                }
                
                $messages[] = ['info', "📋 Current ENUM values: $enum_values"];
            }
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
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
            
            // Check existing users with new roles
            $users_query = "SELECT id, name, email, role, status, created_at FROM users WHERE role IN ('transport_officer', 'hostel_warden') ORDER BY role, name";
            $users_stmt = $db->query($users_query);
            $role_users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($role_users)) {
                echo "<h3>Existing Users with New Roles:</h3>";
                echo "<table>";
                echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th><th>Created</th></tr>";
                foreach ($role_users as $user) {
                    $role_class = str_replace('_', '-', $user['role']);
                    echo "<tr>";
                    echo "<td>{$user['id']}</td>";
                    echo "<td>" . htmlspecialchars($user['name']) . "</td>";
                    echo "<td>" . htmlspecialchars($user['email']) . "</td>";
                    echo "<td><span class='role-badge $role_class'>" . ucfirst(str_replace('_', ' ', $user['role'])) . "</span></td>";
                    echo "<td>" . ucfirst($user['status']) . "</td>";
                    echo "<td>" . date('M j, Y', strtotime($user['created_at'])) . "</td>";
                    echo "</tr>";
                }
                echo "</table>";
            } else {
                echo "<div class='warning'>⚠️ No users found with transport_officer or hostel_warden roles</div>";
            }
            
        } catch (PDOException $e) {
            echo "<div class='error'>❌ Database error: " . htmlspecialchars($e->getMessage()) . "</div>";
        }
        ?>
    </div>
    
    <!-- Actions -->
    <div class="section">
        <h2>Test Actions</h2>
        <a href="?action=check_database"><button>🔍 Check Database Schema</button></a>
        <a href="?action=create_test_users"><button class="create-button">👥 Create Test Users</button></a>
    </div>
    
    <!-- Access Test Links -->
    <div class="section">
        <h2>Test Role Access</h2>
        <p><strong>Transport Officer Access:</strong></p>
        <ul>
            <li><a href="transport/index.php" target="_blank">Transport Dashboard</a></li>
            <li><a href="transport/routes/index.php" target="_blank">Transport Routes</a></li>
            <li><a href="transport/vehicles/index.php" target="_blank">Transport Vehicles</a></li>
            <li><a href="transport/assignments/index.php" target="_blank">Student Transport Assignments</a></li>
        </ul>
        
        <p><strong>Hostel Warden Access:</strong></p>
        <ul>
            <li><a href="hostel/index.php" target="_blank">Hostel Dashboard</a></li>
            <li><a href="hostel/blocks/index.php" target="_blank">Hostel Blocks</a></li>
            <li><a href="hostel/rooms/index.php" target="_blank">Hostel Rooms</a></li>
            <li><a href="hostel/allocations/index.php" target="_blank">Room Allocations</a></li>
        </ul>
        
        <p><strong>Note:</strong> You need to log in with the respective role to test access.</p>
    </div>
    
    <!-- User Creation Test -->
    <div class="section">
        <h2>User Creation Test</h2>
        <p>Test creating users with new roles:</p>
        <ul>
            <li><a href="users/create.php" target="_blank">Create New User (should show Transport Officer & Hostel Warden options)</a></li>
            <li><a href="users/edit.php?id=1" target="_blank">Edit User (should show new roles in dropdown)</a></li>
        </ul>
    </div>
    
    <!-- Login Instructions -->
    <div class="section">
        <h2>Test Login Instructions</h2>
        <p>After creating test users, you can log in with:</p>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <h4>Transport Officer:</h4>
            <p><strong>Email:</strong> transport.officer@school.com</p>
            <p><strong>Password:</strong> password123</p>
        </div>
        
        <div style="background: #f8f9fa; padding: 15px; border-radius: 5px; margin: 10px 0;">
            <h4>Hostel Warden:</h4>
            <p><strong>Email:</strong> hostel.warden@school.com</p>
            <p><strong>Password:</strong> password123</p>
        </div>
        
        <p><a href="auth/logout.php">Logout Current Session</a> | <a href="index.php">Go to Login Page</a></p>
    </div>
    
    <!-- Expected Behavior -->
    <div class="section">
        <h2>Expected Behavior</h2>
        <h3>Transport Officer should have access to:</h3>
        <ul>
            <li>✅ Transport Dashboard</li>
            <li>✅ Transport Routes management</li>
            <li>✅ Vehicle management</li>
            <li>✅ Student transport assignments</li>
            <li>❌ Academic sections</li>
            <li>❌ Hostel sections</li>
            <li>❌ User management (unless admin)</li>
        </ul>
        
        <h3>Hostel Warden should have access to:</h3>
        <ul>
            <li>✅ Hostel Dashboard</li>
            <li>✅ Hostel blocks management</li>
            <li>✅ Room management</li>
            <li>✅ Student room allocations</li>
            <li>❌ Academic sections</li>
            <li>❌ Transport sections</li>
            <li>❌ User management (unless admin)</li>
        </ul>
    </div>
</body>
</html>
