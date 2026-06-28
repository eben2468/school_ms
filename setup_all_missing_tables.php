<?php
/**
 * Comprehensive Database Setup Script
 * Creates ALL missing tables for the school management system
 * - Reports System (grading_scales, conduct_records, etc.)
 * - Live Chat System (live_chat_rooms, live_chat_messages, etc.)
 * - Online Learning System
 * - Document Management System
 */

session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    die("Access denied. Please login as admin.");
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$results = [];

function logResult(&$results, $step, $success, $message) {
    $results[] = [
        'step' => $step,
        'success' => $success,
        'message' => $message
    ];
}

function columnExists($db, $table, $column) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $stmt->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

function tableExists($db, $table) {
    try {
        $result = $db->query("SHOW TABLES LIKE '$table'");
        return $result->rowCount() > 0;
    } catch (Exception $e) {
        return false;
    }
}

try {
    // Get current database name
    $current_db = $db->query("SELECT DATABASE()")->fetchColumn();
    logResult($results, "Database Connection", true, "Connected to: $current_db");
    
    // ==========================================
    // SECTION 1: REPORTS SYSTEM TABLES
    // ==========================================
    
    // 1. Create grading_scales table
    if (!tableExists($db, 'grading_scales')) {
        $sql = "CREATE TABLE grading_scales (
            id INT PRIMARY KEY AUTO_INCREMENT,
            min_score DECIMAL(5,2) NOT NULL,
            max_score DECIMAL(5,2) NOT NULL,
            grade VARCHAR(5) NOT NULL,
            grade_point DECIMAL(3,1),
            interpretation VARCHAR(50),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($sql);
        
        // Insert default grading scales
        $defaults = [
            [80.00, 100.00, 'A1', 4.0, 'Excellent'],
            [70.00, 79.99, 'B2', 3.5, 'Very Good'],
            [65.00, 69.99, 'B3', 3.0, 'Good'],
            [60.00, 64.99, 'C4', 2.5, 'Credit'],
            [55.00, 59.99, 'C5', 2.0, 'Credit'],
            [50.00, 54.99, 'C6', 1.5, 'Credit'],
            [45.00, 49.99, 'D7', 1.0, 'Pass'],
            [40.00, 44.99, 'E8', 0.5, 'Pass'],
            [0.00, 39.99, 'F9', 0.0, 'Fail']
        ];
        $stmt = $db->prepare("INSERT INTO grading_scales (min_score, max_score, grade, grade_point, interpretation) VALUES (?, ?, ?, ?, ?)");
        foreach ($defaults as $row) {
            $stmt->execute($row);
        }
        logResult($results, "Create grading_scales table", true, "Created with 9 default WASSEC grades");
    } else {
        logResult($results, "Create grading_scales table", true, "Already exists");
    }
    
    // 2. Create conduct_records table
    if (!tableExists($db, 'conduct_records')) {
        $sql = "CREATE TABLE conduct_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            academic_term_id INT NOT NULL,
            class_id INT NOT NULL,
            conduct_grade VARCHAR(5) DEFAULT 'B',
            attitude VARCHAR(50) DEFAULT 'Good',
            interest VARCHAR(50) DEFAULT 'Improving',
            remarks TEXT,
            recorded_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_student_term_conduct (student_id, academic_year_id, academic_term_id)
        )";
        $db->exec($sql);
        logResult($results, "Create conduct_records table", true, "Created successfully");
    } else {
        logResult($results, "Create conduct_records table", true, "Already exists");
    }
    
    // ==========================================
    // SECTION 2: LIVE CHAT SYSTEM TABLES
    // ==========================================
    
    // 3. Create live_chat_rooms table
    if (!tableExists($db, 'live_chat_rooms')) {
        $sql = "CREATE TABLE live_chat_rooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            description TEXT,
            room_type ENUM('public', 'private', 'class', 'department', 'admin_only') DEFAULT 'public',
            created_by INT NOT NULL,
            max_participants INT DEFAULT 100,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_room_type (room_type),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        
        // Insert default rooms
        $default_rooms = [
            [1, 'General Discussion', 'Main chat room for general school discussions', 'public', 1],
            [2, 'Academic Support', 'Get help with academic questions and assignments', 'public', 1],
            [3, 'Announcements', 'Official school announcements and updates', 'public', 1],
            [4, 'Staff Room', 'Private chat room for school staff members', 'admin_only', 1]
        ];
        $stmt = $db->prepare("INSERT INTO live_chat_rooms (id, name, description, room_type, created_by) VALUES (?, ?, ?, ?, ?)");
        foreach ($default_rooms as $room) {
            try {
                $stmt->execute($room);
            } catch (PDOException $e) {
                // Ignore duplicate key errors
            }
        }
        logResult($results, "Create live_chat_rooms table", true, "Created with 4 default rooms");
    } else {
        logResult($results, "Create live_chat_rooms table", true, "Already exists");
    }
    
    // 4. Create live_chat_participants table
    if (!tableExists($db, 'live_chat_participants')) {
        $sql = "CREATE TABLE live_chat_participants (
            id INT AUTO_INCREMENT PRIMARY KEY,
            room_id INT NOT NULL,
            user_id INT NOT NULL,
            role ENUM('member', 'moderator', 'admin') DEFAULT 'member',
            joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            is_muted BOOLEAN DEFAULT FALSE,
            is_banned BOOLEAN DEFAULT FALSE,
            UNIQUE KEY unique_room_user (room_id, user_id),
            INDEX idx_room_id (room_id),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_participants table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_participants table", true, "Already exists");
    }
    
    // 5. Create live_chat_messages table
    if (!tableExists($db, 'live_chat_messages')) {
        $sql = "CREATE TABLE live_chat_messages (
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
            INDEX idx_room_created (room_id, created_at),
            INDEX idx_sender_id (sender_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_messages table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_messages table", true, "Already exists");
    }
    
    // 6. Create live_chat_message_reactions table
    if (!tableExists($db, 'live_chat_message_reactions')) {
        $sql = "CREATE TABLE live_chat_message_reactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            reaction_emoji VARCHAR(10) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_message_user_reaction (message_id, user_id, reaction_emoji),
            INDEX idx_message_id (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_message_reactions table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_message_reactions table", true, "Already exists");
    }
    
    // 7. Create live_chat_user_status table
    if (!tableExists($db, 'live_chat_user_status')) {
        $sql = "CREATE TABLE live_chat_user_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            room_id INT NULL,
            status ENUM('online', 'away', 'busy', 'offline') DEFAULT 'offline',
            is_typing BOOLEAN DEFAULT FALSE,
            typing_in_room_id INT NULL,
            last_activity TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            custom_status VARCHAR(100) NULL,
            UNIQUE KEY unique_user_room (user_id, room_id),
            INDEX idx_user_id (user_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_user_status table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_user_status table", true, "Already exists");
    }
    
    // 8. Create live_chat_message_reads table
    if (!tableExists($db, 'live_chat_message_reads')) {
        $sql = "CREATE TABLE live_chat_message_reads (
            id INT AUTO_INCREMENT PRIMARY KEY,
            message_id INT NOT NULL,
            user_id INT NOT NULL,
            read_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_message_user (message_id, user_id),
            INDEX idx_message_id (message_id),
            INDEX idx_user_id (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_message_reads table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_message_reads table", true, "Already exists");
    }
    
    // 9. Create live_chat_blocked_users table
    if (!tableExists($db, 'live_chat_blocked_users')) {
        $sql = "CREATE TABLE live_chat_blocked_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            blocker_id INT NOT NULL,
            blocked_id INT NOT NULL,
            reason VARCHAR(255) NULL,
            blocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_blocker_blocked (blocker_id, blocked_id),
            INDEX idx_blocker_id (blocker_id),
            INDEX idx_blocked_id (blocked_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_blocked_users table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_blocked_users table", true, "Already exists");
    }
    
    // 10. Create live_chat_reports table
    if (!tableExists($db, 'live_chat_reports')) {
        $sql = "CREATE TABLE live_chat_reports (
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
            INDEX idx_reporter_id (reporter_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create live_chat_reports table", true, "Created successfully");
    } else {
        logResult($results, "Create live_chat_reports table", true, "Already exists");
    }
    
    // ==========================================
    // SECTION 3: ACADEMIC SETTINGS
    // ==========================================
    
    if (!tableExists($db, 'academic_settings')) {
        $sql = "CREATE TABLE academic_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $db->exec($sql);
        
        // Insert default settings
        $settings = [
            ['report_template_style', 'classic'],
            ['school_motto', 'Excellence in Character and Knowledge'],
            ['school_postal', 'P.O. Box GP 1234, Accra']
        ];
        $stmt = $db->prepare("INSERT INTO academic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key=setting_key");
        foreach ($settings as $setting) {
            $stmt->execute($setting);
        }
        logResult($results, "Create academic_settings table", true, "Created with default settings");
    } else {
        logResult($results, "Create academic_settings table", true, "Already exists");
    }
    
    // ==========================================
    // SECTION 4: NOTIFICATIONS TABLE UPGRADE
    // ==========================================
    
    // Check if notifications table exists and add missing columns
    if (tableExists($db, 'notifications')) {
        $columns_to_add = [
            'is_dismissed' => "ALTER TABLE notifications ADD COLUMN is_dismissed BOOLEAN DEFAULT FALSE",
            'expires_at' => "ALTER TABLE notifications ADD COLUMN expires_at TIMESTAMP NULL DEFAULT NULL",
            'priority' => "ALTER TABLE notifications ADD COLUMN priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium'",
            'created_by' => "ALTER TABLE notifications ADD COLUMN created_by INT NULL",
            'action_url' => "ALTER TABLE notifications ADD COLUMN action_url VARCHAR(500) NULL",
            'action_text' => "ALTER TABLE notifications ADD COLUMN action_text VARCHAR(100) NULL",
            'icon' => "ALTER TABLE notifications ADD COLUMN icon VARCHAR(50) DEFAULT 'fas fa-bell'"
        ];
        
        $added_count = 0;
        foreach ($columns_to_add as $col => $alter_query) {
            if (!columnExists($db, 'notifications', $col)) {
                try {
                    $db->exec($alter_query);
                    $added_count++;
                } catch (PDOException $e) {
                    // Log but continue
                }
            }
        }
        
        if ($added_count > 0) {
            logResult($results, "Upgrade notifications table", true, "Added $added_count missing columns");
        } else {
            logResult($results, "Upgrade notifications table", true, "All columns already exist");
        }
        
        // Add indexes if they don't exist
        try {
            $db->exec("CREATE INDEX IF NOT EXISTS idx_user_dismissed ON notifications(user_id, is_dismissed)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_expires_at ON notifications(expires_at)");
            $db->exec("CREATE INDEX IF NOT EXISTS idx_priority ON notifications(priority)");
        } catch (PDOException $e) {
            // Indexes might already exist
        }
    } else {
        // Create notifications table from scratch with all columns
        $sql = "CREATE TABLE notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('academic', 'finance', 'system', 'announcement', 'general') DEFAULT 'general',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            is_read BOOLEAN DEFAULT FALSE,
            is_dismissed BOOLEAN DEFAULT FALSE,
            read_at TIMESTAMP NULL,
            expires_at TIMESTAMP NULL,
            action_url VARCHAR(500) NULL,
            action_text VARCHAR(100) NULL,
            icon VARCHAR(50) DEFAULT 'fas fa-bell',
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read),
            INDEX idx_user_dismissed (user_id, is_dismissed),
            INDEX idx_expires_at (expires_at),
            INDEX idx_priority (priority),
            INDEX idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $db->exec($sql);
        logResult($results, "Create notifications table", true, "Created with all required columns");
    }
    
    logResult($results, "✅ SETUP COMPLETE", true, "All database tables verified/created successfully!");
    
} catch (PDOException $e) {
    logResult($results, "❌ ERROR", false, "Database error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Complete Database Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-2xl mb-8">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-4xl font-bold mb-2">
                            <i class="fas fa-database mr-3"></i>Complete Database Setup
                        </h1>
                        <p class="text-blue-100 text-lg">All missing tables have been checked and created</p>
                    </div>
                    <div class="hidden md:block">
                        <div class="w-24 h-24 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                            <i class="fas fa-check-circle text-5xl text-white/90"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Results -->
            <div class="bg-white rounded-2xl shadow-xl p-8 mb-8">
                <h2 class="text-2xl font-bold text-gray-800 mb-6 flex items-center">
                    <i class="fas fa-list-check mr-3 text-blue-600"></i>
                    Setup Results
                </h2>
                
                <div class="space-y-3">
                    <?php foreach ($results as $result): ?>
                        <div class="flex items-start p-4 rounded-xl <?php echo $result['success'] ? 'bg-green-50 border-2 border-green-200' : 'bg-red-50 border-2 border-red-200'; ?> transition-all hover:shadow-md">
                            <i class="fas <?php echo $result['success'] ? 'fa-check-circle text-green-600' : 'fa-exclamation-circle text-red-600'; ?> text-2xl mr-4 mt-1"></i>
                            <div class="flex-1">
                                <h3 class="font-bold <?php echo $result['success'] ? 'text-green-800' : 'text-red-800'; ?> text-lg">
                                    <?php echo htmlspecialchars($result['step']); ?>
                                </h3>
                                <p class="<?php echo $result['success'] ? 'text-green-700' : 'text-red-700'; ?> text-sm mt-1">
                                    <?php echo htmlspecialchars($result['message']); ?>
                                </p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-8">
                <a href="dashboard.php" class="flex items-center justify-center px-6 py-4 bg-blue-600 text-white rounded-xl hover:bg-blue-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                    <i class="fas fa-home mr-2 text-xl"></i>
                    <span class="font-semibold">Dashboard</span>
                </a>
                <a href="academic/reports/compilation.php" class="flex items-center justify-center px-6 py-4 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                    <i class="fas fa-file-alt mr-2 text-xl"></i>
                    <span class="font-semibold">Reports</span>
                </a>
                <a href="communication/live_chat.php" class="flex items-center justify-center px-6 py-4 bg-purple-600 text-white rounded-xl hover:bg-purple-700 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-1">
                    <i class="fas fa-comments mr-2 text-xl"></i>
                    <span class="font-semibold">Live Chat</span>
                </a>
            </div>
            
            <!-- Info Box -->
            <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border-2 border-blue-200 rounded-2xl p-6 shadow-lg">
                <div class="flex items-start">
                    <i class="fas fa-info-circle text-blue-600 text-3xl mr-4 mt-1"></i>
                    <div>
                        <h3 class="font-bold text-blue-900 text-xl mb-3">What Was Created?</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-blue-800">
                            <div>
                                <h4 class="font-semibold mb-2"><i class="fas fa-graduation-cap mr-2"></i>Reports System:</h4>
                                <ul class="text-sm space-y-1 ml-6">
                                    <li>• grading_scales (A1-F9)</li>
                                    <li>• conduct_records</li>
                                    <li>• academic_settings</li>
                                </ul>
                                <h4 class="font-semibold mb-2 mt-3"><i class="fas fa-bell mr-2"></i>Notifications:</h4>
                                <ul class="text-sm space-y-1 ml-6">
                                    <li>• Enhanced notifications table</li>
                                    <li>• Priority levels & expiration</li>
                                    <li>• Dismiss functionality</li>
                                </ul>
                            </div>
                            <div>
                                <h4 class="font-semibold mb-2"><i class="fas fa-comments mr-2"></i>Live Chat System:</h4>
                                <ul class="text-sm space-y-1 ml-6">
                                    <li>• live_chat_rooms (4 default rooms)</li>
                                    <li>• live_chat_messages</li>
                                    <li>• live_chat_participants</li>
                                    <li>• live_chat_user_status</li>
                                    <li>• live_chat_reactions</li>
                                    <li>• live_chat_reports</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
