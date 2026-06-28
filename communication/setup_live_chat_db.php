<?php
// Setup Live Chat Database Schema
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header('Location: /login.php');
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Live Chat Database Setup</h2>";
    echo "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px;'>";
    
    // Create tables directly instead of reading from file
    $tables = [
        'live_chat_rooms' => "
            CREATE TABLE IF NOT EXISTS live_chat_rooms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                description TEXT,
                room_type ENUM('public', 'private', 'class', 'department', 'admin_only') DEFAULT 'public',
                created_by INT NOT NULL,
                max_participants INT DEFAULT 100,
                is_active BOOLEAN DEFAULT TRUE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_room_type (room_type),
                INDEX idx_is_active (is_active),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_participants' => "
            CREATE TABLE IF NOT EXISTS live_chat_participants (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                user_id INT NOT NULL,
                role ENUM('member', 'moderator', 'admin') DEFAULT 'member',
                joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                is_muted BOOLEAN DEFAULT FALSE,
                is_banned BOOLEAN DEFAULT FALSE,
                UNIQUE KEY unique_room_user (room_id, user_id),
                FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_room_id (room_id),
                INDEX idx_user_id (user_id),
                INDEX idx_role (role),
                INDEX idx_last_seen (last_seen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_messages' => "
            CREATE TABLE IF NOT EXISTS live_chat_messages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                room_id INT NOT NULL,
                sender_id INT NOT NULL,
                message TEXT NOT NULL,
                message_type ENUM('text', 'file', 'image', 'system', 'announcement') DEFAULT 'text',
                file_path VARCHAR(500) NULL,
                file_name VARCHAR(255) NULL,
                file_size INT NULL,
                reply_to_message_id INT NULL,
                is_edited BOOLEAN DEFAULT FALSE,
                edited_at TIMESTAMP NULL,
                is_deleted BOOLEAN DEFAULT FALSE,
                deleted_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reply_to_message_id) REFERENCES live_chat_messages(id) ON DELETE SET NULL,
                INDEX idx_room_created (room_id, created_at),
                INDEX idx_sender_id (sender_id),
                INDEX idx_message_type (message_type),
                INDEX idx_reply_to (reply_to_message_id),
                INDEX idx_is_deleted (is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_message_reactions' => "
            CREATE TABLE IF NOT EXISTS live_chat_message_reactions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                user_id INT NOT NULL,
                reaction_emoji VARCHAR(10) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_message_user_reaction (message_id, user_id, reaction_emoji),
                FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_message_id (message_id),
                INDEX idx_user_id (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_user_status' => "
            CREATE TABLE IF NOT EXISTS live_chat_user_status (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                room_id INT NULL,
                status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
                is_typing BOOLEAN DEFAULT FALSE,
                typing_in_room_id INT NULL,
                last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                custom_status VARCHAR(100) NULL,
                UNIQUE KEY unique_user_room (user_id, room_id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (typing_in_room_id) REFERENCES live_chat_rooms(id) ON DELETE SET NULL,
                INDEX idx_user_id (user_id),
                INDEX idx_status (status),
                INDEX idx_last_activity (last_activity),
                INDEX idx_typing (is_typing, typing_in_room_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_message_reads' => "
            CREATE TABLE IF NOT EXISTS live_chat_message_reads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message_id INT NOT NULL,
                user_id INT NOT NULL,
                read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_message_user (message_id, user_id),
                FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_message_id (message_id),
                INDEX idx_user_id (user_id),
                INDEX idx_read_at (read_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_blocked_users' => "
            CREATE TABLE IF NOT EXISTS live_chat_blocked_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                blocker_id INT NOT NULL,
                blocked_id INT NOT NULL,
                reason VARCHAR(255) NULL,
                blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY unique_blocker_blocked (blocker_id, blocked_id),
                FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_blocker_id (blocker_id),
                INDEX idx_blocked_id (blocked_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ",

        'live_chat_reports' => "
            CREATE TABLE IF NOT EXISTS live_chat_reports (
                id INT AUTO_INCREMENT PRIMARY KEY,
                reporter_id INT NOT NULL,
                reported_user_id INT NULL,
                message_id INT NULL,
                room_id INT NOT NULL,
                report_type ENUM('spam', 'harassment', 'inappropriate_content', 'other') NOT NULL,
                description TEXT NOT NULL,
                status ENUM('pending', 'reviewed', 'resolved', 'dismissed') DEFAULT 'pending',
                reviewed_by INT NULL,
                reviewed_at TIMESTAMP NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (reported_user_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (message_id) REFERENCES live_chat_messages(id) ON DELETE CASCADE,
                FOREIGN KEY (room_id) REFERENCES live_chat_rooms(id) ON DELETE CASCADE,
                FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL,
                INDEX idx_reporter_id (reporter_id),
                INDEX idx_reported_user_id (reported_user_id),
                INDEX idx_status (status),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        "
    ];

    foreach ($tables as $table_name => $sql) {
        try {
            $db->exec($sql);
            echo "<p style='color: green;'>✅ Created table: $table_name</p>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'already exists') !== false) {
                echo "<p style='color: orange;'>⚠️ Table $table_name already exists</p>";
            } else {
                echo "<p style='color: red;'>❌ Error creating $table_name: " . $e->getMessage() . "</p>";
            }
        }
    }
    
    echo "<p style='color: green;'>✅ Live chat database schema created successfully</p>";

    // Insert default chat rooms
    $default_rooms = [
        [1, 'General Discussion', 'Main chat room for general school discussions', 'public', 1],
        [2, 'Academic Support', 'Get help with academic questions and assignments', 'public', 1],
        [3, 'Announcements', 'Official school announcements and updates', 'public', 1],
        [4, 'Staff Room', 'Private chat room for school staff members', 'admin_only', 1]
    ];

    foreach ($default_rooms as $room) {
        try {
            $room_query = "
                INSERT IGNORE INTO live_chat_rooms (id, name, description, room_type, created_by)
                VALUES (?, ?, ?, ?, ?)
            ";
            $room_stmt = $db->prepare($room_query);
            $room_stmt->execute($room);
            echo "<p style='color: green;'>✅ Created default room: {$room[1]}</p>";
        } catch (PDOException $e) {
            echo "<p style='color: orange;'>⚠️ Room {$room[1]} may already exist: " . $e->getMessage() . "</p>";
        }
    }

    // Add default participants to public rooms
    $default_rooms_query = "
        INSERT IGNORE INTO live_chat_participants (room_id, user_id, role)
        SELECT r.id, u.id, 
               CASE 
                   WHEN u.role IN ('super_admin', 'school_admin', 'principal') THEN 'admin'
                   WHEN u.role = 'teacher' THEN 'moderator'
                   ELSE 'member'
               END as role
        FROM live_chat_rooms r
        CROSS JOIN users u
        WHERE r.room_type IN ('public') 
        AND u.status = 'active'
        AND r.id IN (1, 2, 3)
    ";
    
    try {
        $db->exec($default_rooms_query);
        echo "<p style='color: green;'>✅ Default participants added to public rooms</p>";
    } catch (PDOException $e) {
        echo "<p style='color: orange;'>⚠️ Warning adding default participants: " . $e->getMessage() . "</p>";
    }
    
    // Add staff to staff room
    $staff_room_query = "
        INSERT IGNORE INTO live_chat_participants (room_id, user_id, role)
        SELECT 4, u.id, 
               CASE 
                   WHEN u.role IN ('super_admin', 'school_admin', 'principal') THEN 'admin'
                   ELSE 'moderator'
               END as role
        FROM users u
        WHERE u.role IN ('super_admin', 'school_admin', 'principal', 'teacher', 'librarian', 'accountant', 'transport_officer', 'hostel_warden', 'canteen_manager', 'nurse', 'counselor')
        AND u.status = 'active'
    ";
    
    try {
        $db->exec($staff_room_query);
        echo "<p style='color: green;'>✅ Staff members added to staff room</p>";
    } catch (PDOException $e) {
        echo "<p style='color: orange;'>⚠️ Warning adding staff to staff room: " . $e->getMessage() . "</p>";
    }
    
    // Create welcome messages
    $welcome_messages = [
        [
            'room_id' => 1,
            'message' => 'Welcome to the General Discussion room! This is a place for all school community members to chat and share ideas. Please be respectful and follow school guidelines.',
            'type' => 'system'
        ],
        [
            'room_id' => 2,
            'message' => 'Welcome to Academic Support! Students can ask questions about their studies here, and teachers and peers can help provide answers and guidance.',
            'type' => 'system'
        ],
        [
            'room_id' => 3,
            'message' => 'This is the Announcements room. Important school updates and official announcements will be posted here.',
            'type' => 'announcement'
        ],
        [
            'room_id' => 4,
            'message' => 'Welcome to the Staff Room! This is a private space for school staff to communicate and collaborate.',
            'type' => 'system'
        ]
    ];
    
    foreach ($welcome_messages as $msg) {
        try {
            $insert_msg = "
                INSERT IGNORE INTO live_chat_messages (room_id, sender_id, message, message_type, created_at)
                VALUES (:room_id, 1, :message, :type, NOW())
            ";
            $stmt = $db->prepare($insert_msg);
            $stmt->bindParam(':room_id', $msg['room_id']);
            $stmt->bindParam(':message', $msg['message']);
            $stmt->bindParam(':type', $msg['type']);
            $stmt->execute();
        } catch (PDOException $e) {
            // Ignore duplicate entry errors
        }
    }
    
    echo "<p style='color: green;'>✅ Welcome messages added to chat rooms</p>";
    
    // Verify tables were created
    $tables_to_check = [
        'live_chat_rooms',
        'live_chat_participants', 
        'live_chat_messages',
        'live_chat_message_reactions',
        'live_chat_user_status',
        'live_chat_message_reads',
        'live_chat_blocked_users',
        'live_chat_reports'
    ];
    
    echo "<h3>Database Tables Status:</h3>";
    foreach ($tables_to_check as $table) {
        try {
            $check_query = "SELECT COUNT(*) as count FROM $table";
            $stmt = $db->prepare($check_query);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            echo "<p style='color: green;'>✅ $table: {$result['count']} records</p>";
        } catch (PDOException $e) {
            echo "<p style='color: red;'>❌ $table: Error - " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h3>Next Steps:</h3>";
    echo "<ul>";
    echo "<li>✅ Database schema created</li>";
    echo "<li>✅ Default chat rooms created</li>";
    echo "<li>✅ Users added to appropriate rooms</li>";
    echo "<li>🔄 <a href='live_chat.php'>Go to Live Chat</a></li>";
    echo "</ul>";
    
    echo "</div>";
    
} catch (Exception $e) {
    echo "<p style='color: red; font-weight: bold;'>❌ Setup failed: " . $e->getMessage() . "</p>";
    echo "<p>Please check your database connection and permissions.</p>";
}
?>
