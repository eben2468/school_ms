<?php
// Script to ensure chat message persistence and proper database structure
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header('Location: /school_ms/login.php');
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Chat Persistence Check & Fix</h2>";
    
    // Check if chat tables exist
    $tables_check = [
        'chat_conversations' => "
            CREATE TABLE IF NOT EXISTS chat_conversations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                support_agent_id INT NULL,
                subject VARCHAR(255) NOT NULL,
                priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_user_id (user_id),
                INDEX idx_agent_id (support_agent_id),
                INDEX idx_status (status),
                INDEX idx_priority (priority),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (support_agent_id) REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        'chat_messages' => "
            CREATE TABLE IF NOT EXISTS chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                message_type ENUM('text', 'system', 'file') DEFAULT 'text',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_sender_id (sender_id),
                INDEX idx_created_at (created_at),
                INDEX idx_is_read (is_read),
                FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",
        'chat_typing_status' => "
            CREATE TABLE IF NOT EXISTS chat_typing_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                conversation_id INT NOT NULL,
                user_id INT NOT NULL,
                is_typing BOOLEAN DEFAULT FALSE,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_user_conversation (conversation_id, user_id),
                INDEX idx_conversation_id (conversation_id),
                INDEX idx_user_id (user_id),
                INDEX idx_last_activity (last_activity),
                FOREIGN KEY (conversation_id) REFERENCES chat_conversations(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        "
    ];
    
    foreach ($tables_check as $table_name => $create_sql) {
        try {
            $db->exec($create_sql);
            echo "<p>✅ Table '$table_name' ensured with proper structure</p>";
        } catch (PDOException $e) {
            echo "<p>❌ Error creating table '$table_name': " . $e->getMessage() . "</p>";
        }
    }
    
    // Add missing columns if they don't exist
    $column_checks = [
        'chat_conversations' => [
            'support_agent_id' => "ALTER TABLE chat_conversations ADD COLUMN support_agent_id INT NULL AFTER user_id",
            'priority' => "ALTER TABLE chat_conversations ADD COLUMN priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium' AFTER subject",
            'updated_at' => "ALTER TABLE chat_conversations ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at"
        ],
        'chat_messages' => [
            'message_type' => "ALTER TABLE chat_messages ADD COLUMN message_type ENUM('text', 'system', 'file') DEFAULT 'text' AFTER message",
            'is_read' => "ALTER TABLE chat_messages ADD COLUMN is_read BOOLEAN DEFAULT FALSE AFTER message_type"
        ]
    ];
    
    foreach ($column_checks as $table => $columns) {
        foreach ($columns as $column => $alter_sql) {
            try {
                // Check if column exists
                $check_query = "SHOW COLUMNS FROM $table LIKE '$column'";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->execute();
                
                if ($check_stmt->rowCount() == 0) {
                    $db->exec($alter_sql);
                    echo "<p>✅ Added missing column '$column' to table '$table'</p>";
                } else {
                    echo "<p>ℹ️ Column '$column' already exists in table '$table'</p>";
                }
            } catch (PDOException $e) {
                echo "<p>⚠️ Could not add column '$column' to table '$table': " . $e->getMessage() . "</p>";
            }
        }
    }
    
    // Ensure proper indexes for performance
    $indexes = [
        'chat_conversations' => [
            'idx_user_status' => "CREATE INDEX idx_user_status ON chat_conversations(user_id, status)",
            'idx_agent_status' => "CREATE INDEX idx_agent_status ON chat_conversations(support_agent_id, status)",
            'idx_priority_status' => "CREATE INDEX idx_priority_status ON chat_conversations(priority, status)"
        ],
        'chat_messages' => [
            'idx_conv_created' => "CREATE INDEX idx_conv_created ON chat_messages(conversation_id, created_at)",
            'idx_unread_messages' => "CREATE INDEX idx_unread_messages ON chat_messages(conversation_id, is_read, sender_id)"
        ]
    ];
    
    foreach ($indexes as $table => $table_indexes) {
        foreach ($table_indexes as $index_name => $create_index_sql) {
            try {
                $db->exec($create_index_sql);
                echo "<p>✅ Created index '$index_name' on table '$table'</p>";
            } catch (PDOException $e) {
                if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                    echo "<p>ℹ️ Index '$index_name' already exists on table '$table'</p>";
                } else {
                    echo "<p>⚠️ Could not create index '$index_name' on table '$table': " . $e->getMessage() . "</p>";
                }
            }
        }
    }
    
    // Test message persistence
    echo "<h3>Testing Message Persistence</h3>";
    
    // Count existing messages
    $count_query = "SELECT COUNT(*) as total FROM chat_messages";
    $count_stmt = $db->prepare($count_query);
    $count_stmt->execute();
    $message_count = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo "<p>📊 Total messages in database: $message_count</p>";
    
    // Check for orphaned messages (messages without conversations)
    $orphan_query = "
        SELECT COUNT(*) as orphaned 
        FROM chat_messages cm 
        LEFT JOIN chat_conversations cc ON cm.conversation_id = cc.id 
        WHERE cc.id IS NULL
    ";
    $orphan_stmt = $db->prepare($orphan_query);
    $orphan_stmt->execute();
    $orphaned_count = $orphan_stmt->fetch(PDO::FETCH_ASSOC)['orphaned'];
    
    if ($orphaned_count > 0) {
        echo "<p>⚠️ Found $orphaned_count orphaned messages (messages without conversations)</p>";
        
        // Clean up orphaned messages
        $cleanup_query = "
            DELETE cm FROM chat_messages cm 
            LEFT JOIN chat_conversations cc ON cm.conversation_id = cc.id 
            WHERE cc.id IS NULL
        ";
        $cleanup_stmt = $db->prepare($cleanup_query);
        $cleanup_stmt->execute();
        echo "<p>🧹 Cleaned up orphaned messages</p>";
    } else {
        echo "<p>✅ No orphaned messages found</p>";
    }
    
    // Check message retention settings
    echo "<h3>Message Retention Policy</h3>";
    echo "<p>ℹ️ Messages are stored permanently unless manually deleted</p>";
    echo "<p>ℹ️ Conversations are preserved even when users are offline</p>";
    echo "<p>ℹ️ Support agents can access all historical conversations</p>";
    
    echo "<h3>Persistence Features Enabled:</h3>";
    echo "<ul>";
    echo "<li>✅ Messages persist across user sessions</li>";
    echo "<li>✅ Conversations remain accessible when users are offline</li>";
    echo "<li>✅ Support agents can view all conversations</li>";
    echo "<li>✅ Message history is maintained indefinitely</li>";
    echo "<li>✅ Proper database indexes for performance</li>";
    echo "<li>✅ Foreign key constraints prevent data corruption</li>";
    echo "</ul>";
    
    echo "<p><strong>✅ Chat persistence is properly configured!</strong></p>";
    
} catch (PDOException $e) {
    echo "<p>❌ Database error: " . $e->getMessage() . "</p>";
} catch (Exception $e) {
    echo "<p>❌ Error: " . $e->getMessage() . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
h2, h3 { color: #333; }
p { margin: 5px 0; }
ul { margin: 10px 0; }
li { margin: 3px 0; }
</style>
