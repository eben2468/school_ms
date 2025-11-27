<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update User Roles Database</title>
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
    <h1>Update User Roles Database</h1>
    
    <?php
    $action = $_GET['action'] ?? '';
    $messages = [];
    
    if ($action === 'update_enum') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Update the ENUM to include the new roles
            $alter_query = "ALTER TABLE users MODIFY COLUMN role ENUM(
                'super_admin', 
                'school_admin', 
                'principal', 
                'teacher', 
                'student', 
                'parent', 
                'librarian', 
                'accountant', 
                'transport_officer', 
                'hostel_warden', 
                'canteen_manager', 
                'nurse', 
                'counselor'
            ) NOT NULL";
            
            $db->exec($alter_query);
            $messages[] = ['success', '✅ Successfully updated users table ENUM to include transport_officer and hostel_warden'];
            
        } catch (PDOException $e) {
            $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
        }
    }
    
    if ($action === 'check_current') {
        try {
            require_once 'config/database.php';
            $database = new Database();
            $db = $database->getConnection();
            
            // Check current ENUM values
            $enum_query = "SHOW COLUMNS FROM users LIKE 'role'";
            $enum_stmt = $db->query($enum_query);
            $enum_result = $enum_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($enum_result) {
                $enum_values = $enum_result['Type'];
                $messages[] = ['info', "📋 Current role ENUM: $enum_values"];
                
                // Check if new roles are present
                if (strpos($enum_values, 'transport_officer') !== false) {
                    $messages[] = ['success', '✅ transport_officer is present in ENUM'];
                } else {
                    $messages[] = ['warning', '⚠️ transport_officer is missing from ENUM'];
                }
                
                if (strpos($enum_values, 'hostel_warden') !== false) {
                    $messages[] = ['success', '✅ hostel_warden is present in ENUM'];
                } else {
                    $messages[] = ['warning', '⚠️ hostel_warden is missing from ENUM'];
                }
            }
            
            // Check if there are any users with these roles
            $users_query = "SELECT role, COUNT(*) as count FROM users WHERE role IN ('transport_officer', 'hostel_warden') GROUP BY role";
            $users_stmt = $db->query($users_query);
            $role_counts = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($role_counts)) {
                $messages[] = ['info', '👥 Users with new roles:'];
                foreach ($role_counts as $role_count) {
                    $messages[] = ['info', "   - {$role_count['role']}: {$role_count['count']} users"];
                }
            } else {
                $messages[] = ['info', '👥 No users found with transport_officer or hostel_warden roles yet'];
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
    
    <!-- Actions -->
    <div class="section">
        <h2>Database Update Actions</h2>
        <a href="?action=check_current"><button>🔍 Check Current Status</button></a>
        <a href="?action=update_enum"><button>🔧 Update ENUM Values</button></a>
    </div>
    
    <!-- Manual SQL -->
    <div class="section">
        <h2>Manual SQL (if needed)</h2>
        <p>If the automatic update doesn't work, you can run this SQL manually in phpMyAdmin or MySQL command line:</p>
        <pre>ALTER TABLE users MODIFY COLUMN role ENUM(
    'super_admin', 
    'school_admin', 
    'principal', 
    'teacher', 
    'student', 
    'parent', 
    'librarian', 
    'accountant', 
    'transport_officer', 
    'hostel_warden', 
    'canteen_manager', 
    'nurse', 
    'counselor'
) NOT NULL;</pre>
    </div>
    
    <!-- Next Steps -->
    <div class="section">
        <h2>Next Steps</h2>
        <ol>
            <li><strong>Update Database:</strong> Click "Update ENUM Values" above</li>
            <li><strong>Test User Creation:</strong> <a href="users/create.php">Create New User</a> (should show new roles)</li>
            <li><strong>Create Test Users:</strong> <a href="test_new_roles.php">Test New Roles</a></li>
            <li><strong>Test Access:</strong> Log in with new roles and test access to respective sections</li>
        </ol>
    </div>
    
    <!-- File Updates Summary -->
    <div class="section">
        <h2>Files Updated</h2>
        <p>The following files have been updated to support the new roles:</p>
        <ul>
            <li>✅ <strong>users/create.php</strong> - Added transport_officer and hostel_warden to role selection</li>
            <li>✅ <strong>users/edit.php</strong> - Added new roles to edit form</li>
            <li>✅ <strong>hostel/allocations/create.php</strong> - Added hostel_warden access</li>
            <li>✅ <strong>includes/sidebar.php</strong> - Already had proper access controls</li>
            <li>✅ <strong>Transport pages</strong> - Already had transport_officer access</li>
            <li>✅ <strong>Hostel pages</strong> - Already had hostel_warden access</li>
        </ul>
    </div>
</body>
</html>
