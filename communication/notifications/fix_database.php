<?php
// Fix notification database schema issues
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header('Location: /login.php');
    exit();
}

require_once '../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Fixing Notification Database Schema</h2>";
    
    // Check if notifications table exists
    $check_table = "SHOW TABLES LIKE 'notifications'";
    $table_exists = $db->query($check_table)->rowCount() > 0;
    
    if (!$table_exists) {
        echo "<p>❌ Notifications table doesn't exist. Creating it...</p>";
        
        // Create the complete notifications table
        $create_table = "
            CREATE TABLE notifications (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('academic', 'finance', 'system', 'announcement', 'general', 'attendance', 'grades', 'events', 'library', 'transport', 'hostel', 'canteen', 'health') DEFAULT 'general',
                priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                icon VARCHAR(100) DEFAULT 'fas fa-bell',
                action_url VARCHAR(500) NULL,
                action_text VARCHAR(100) NULL,
                is_read BOOLEAN DEFAULT FALSE,
                is_dismissed BOOLEAN DEFAULT FALSE,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                read_at TIMESTAMP NULL,
                dismissed_at TIMESTAMP NULL,
                expires_at TIMESTAMP NULL,
                metadata JSON NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_type (type),
                INDEX idx_priority (priority),
                INDEX idx_is_read (is_read),
                INDEX idx_created_at (created_at),
                INDEX idx_expires_at (expires_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ";
        
        $db->exec($create_table);
        echo "<p>✅ Notifications table created successfully</p>";
    } else {
        echo "<p>ℹ️ Notifications table exists. Checking for missing columns...</p>";
        
        // Get current table structure
        $columns_query = "DESCRIBE notifications";
        $columns_result = $db->query($columns_query);
        $existing_columns = [];
        
        while ($row = $columns_result->fetch(PDO::FETCH_ASSOC)) {
            $existing_columns[] = $row['Field'];
        }
        
        // Define required columns and their definitions
        $required_columns = [
            'priority' => "ALTER TABLE notifications ADD COLUMN priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' AFTER type",
            'icon' => "ALTER TABLE notifications ADD COLUMN icon VARCHAR(100) DEFAULT 'fas fa-bell' AFTER priority",
            'action_url' => "ALTER TABLE notifications ADD COLUMN action_url VARCHAR(500) NULL AFTER icon",
            'action_text' => "ALTER TABLE notifications ADD COLUMN action_text VARCHAR(100) NULL AFTER action_url",
            'is_dismissed' => "ALTER TABLE notifications ADD COLUMN is_dismissed BOOLEAN DEFAULT FALSE AFTER is_read",
            'created_by' => "ALTER TABLE notifications ADD COLUMN created_by INT NULL AFTER is_dismissed",
            'read_at' => "ALTER TABLE notifications ADD COLUMN read_at TIMESTAMP NULL AFTER created_at",
            'dismissed_at' => "ALTER TABLE notifications ADD COLUMN dismissed_at TIMESTAMP NULL AFTER read_at",
            'expires_at' => "ALTER TABLE notifications ADD COLUMN expires_at TIMESTAMP NULL AFTER dismissed_at",
            'metadata' => "ALTER TABLE notifications ADD COLUMN metadata JSON NULL AFTER expires_at"
        ];
        
        // Add missing columns
        foreach ($required_columns as $column => $alter_sql) {
            if (!in_array($column, $existing_columns)) {
                try {
                    $db->exec($alter_sql);
                    echo "<p>✅ Added missing column: $column</p>";
                } catch (PDOException $e) {
                    echo "<p>⚠️ Could not add column $column: " . $e->getMessage() . "</p>";
                }
            } else {
                echo "<p>ℹ️ Column $column already exists</p>";
            }
        }
        
        // Update type enum if needed
        try {
            $update_type_enum = "
                ALTER TABLE notifications 
                MODIFY COLUMN type ENUM('academic', 'finance', 'system', 'announcement', 'general', 'attendance', 'grades', 'events', 'library', 'transport', 'hostel', 'canteen', 'health') DEFAULT 'general'
            ";
            $db->exec($update_type_enum);
            echo "<p>✅ Updated type enum with new values</p>";
        } catch (PDOException $e) {
            echo "<p>⚠️ Could not update type enum: " . $e->getMessage() . "</p>";
        }
    }
    
    // Create indexes if they don't exist
    $indexes = [
        'idx_user_id' => "CREATE INDEX idx_user_id ON notifications(user_id)",
        'idx_type' => "CREATE INDEX idx_type ON notifications(type)",
        'idx_priority' => "CREATE INDEX idx_priority ON notifications(priority)",
        'idx_is_read' => "CREATE INDEX idx_is_read ON notifications(is_read)",
        'idx_created_at' => "CREATE INDEX idx_created_at ON notifications(created_at)",
        'idx_expires_at' => "CREATE INDEX idx_expires_at ON notifications(expires_at)"
    ];
    
    foreach ($indexes as $index_name => $create_sql) {
        try {
            $db->exec($create_sql);
            echo "<p>✅ Created index: $index_name</p>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "<p>ℹ️ Index $index_name already exists</p>";
            } else {
                echo "<p>⚠️ Could not create index $index_name: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // Insert sample notifications if table is empty
    $count_query = "SELECT COUNT(*) as count FROM notifications";
    $count_result = $db->query($count_query);
    $notification_count = $count_result->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($notification_count == 0) {
        echo "<p>📝 Table is empty. Adding sample notifications...</p>";
        
        $sample_notifications = [
            [
                'user_id' => null,
                'title' => 'Welcome to Enhanced Notifications',
                'message' => 'The notification system has been successfully upgraded with real-time updates, better organization, and database integration.',
                'type' => 'system',
                'priority' => 'medium',
                'icon' => 'fas fa-rocket',
                'created_by' => $_SESSION['user_id']
            ],
            [
                'user_id' => $_SESSION['user_id'],
                'title' => 'Profile Update Reminder',
                'message' => 'Please review and update your profile information to ensure all details are current and accurate.',
                'type' => 'general',
                'priority' => 'low',
                'icon' => 'fas fa-user-edit',
                'action_url' => '/settings/school.php',
                'action_text' => 'Update Profile',
                'created_by' => $_SESSION['user_id']
            ],
            [
                'user_id' => null,
                'title' => 'System Maintenance Notice',
                'message' => 'Scheduled maintenance will be performed this weekend. The system may be temporarily unavailable.',
                'type' => 'system',
                'priority' => 'high',
                'icon' => 'fas fa-tools',
                'created_by' => $_SESSION['user_id']
            ]
        ];
        
        $insert_query = "
            INSERT INTO notifications 
            (user_id, title, message, type, priority, icon, action_url, action_text, created_by) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ";
        $insert_stmt = $db->prepare($insert_query);
        
        foreach ($sample_notifications as $notification) {
            $insert_stmt->execute([
                $notification['user_id'],
                $notification['title'],
                $notification['message'],
                $notification['type'],
                $notification['priority'],
                $notification['icon'],
                $notification['action_url'] ?? null,
                $notification['action_text'] ?? null,
                $notification['created_by']
            ]);
        }
        
        echo "<p>✅ Added " . count($sample_notifications) . " sample notifications</p>";
    } else {
        echo "<p>ℹ️ Table already contains $notification_count notifications</p>";
    }
    
    // Test the table structure
    echo "<h3>Testing Table Structure</h3>";
    
    $test_query = "
        SELECT id, user_id, title, message, type, priority, icon, action_url, action_text, 
               is_read, is_dismissed, created_by, created_at, read_at, dismissed_at, expires_at, metadata
        FROM notifications 
        LIMIT 1
    ";
    
    try {
        $test_result = $db->query($test_query);
        echo "<p>✅ Table structure test passed - all columns accessible</p>";
    } catch (PDOException $e) {
        echo "<p>❌ Table structure test failed: " . $e->getMessage() . "</p>";
    }
    
    echo "<h3>✅ Database Fix Complete!</h3>";
    echo "<p><strong>The notification system should now work properly.</strong></p>";
    echo "<p><a href='/notifications.php' class='text-blue-600 hover:text-blue-800'>→ Test Notifications Page</a></p>";
    echo "<p><a href='/dashboard.php' class='text-blue-600 hover:text-blue-800'>→ Test Dashboard Notifications</a></p>";
    
} catch (PDOException $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>

<style>
body { 
    font-family: Arial, sans-serif; 
    margin: 20px; 
    background-color: #f5f5f5;
}
h2, h3 { 
    color: #333; 
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}
p { 
    margin: 8px 0; 
    padding: 8px;
    background: white;
    border-left: 4px solid #007bff;
    border-radius: 4px;
}
a {
    color: #007bff;
    text-decoration: none;
    font-weight: bold;
}
a:hover {
    color: #0056b3;
    text-decoration: underline;
}
</style>
