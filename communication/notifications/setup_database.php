<?php
// Setup comprehensive notification database schema
session_start();

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header('Location: /login.php');
    exit();
}

require_once '../../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Notification System Database Setup</h2>";
    
    // Create notifications table with comprehensive structure
    $notifications_table = "
        CREATE TABLE IF NOT EXISTS notifications (
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
    
    $db->exec($notifications_table);
    echo "<p>✅ Notifications table created/updated successfully</p>";
    
    // Create notification preferences table
    $preferences_table = "
        CREATE TABLE IF NOT EXISTS notification_preferences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(50) NOT NULL,
            email_enabled BOOLEAN DEFAULT TRUE,
            push_enabled BOOLEAN DEFAULT TRUE,
            sms_enabled BOOLEAN DEFAULT FALSE,
            in_app_enabled BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_type (user_id, type),
            INDEX idx_user_id (user_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($preferences_table);
    echo "<p>✅ Notification preferences table created successfully</p>";
    
    // Create notification templates table
    $templates_table = "
        CREATE TABLE IF NOT EXISTS notification_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            title_template VARCHAR(255) NOT NULL,
            message_template TEXT NOT NULL,
            type VARCHAR(50) NOT NULL,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            icon VARCHAR(100) DEFAULT 'fas fa-bell',
            action_url_template VARCHAR(500) NULL,
            action_text VARCHAR(100) NULL,
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_name (name),
            INDEX idx_type (type),
            INDEX idx_is_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($templates_table);
    echo "<p>✅ Notification templates table created successfully</p>";
    
    // Create notification logs table for tracking
    $logs_table = "
        CREATE TABLE IF NOT EXISTS notification_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            notification_id INT NOT NULL,
            action ENUM('created', 'read', 'dismissed', 'clicked', 'expired') NOT NULL,
            user_id INT NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_notification_id (notification_id),
            INDEX idx_user_id (user_id),
            INDEX idx_action (action),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    
    $db->exec($logs_table);
    echo "<p>✅ Notification logs table created successfully</p>";
    
    // Insert default notification templates
    $default_templates = [
        [
            'name' => 'student_enrollment',
            'title_template' => 'New Student Enrolled',
            'message_template' => 'Student {student_name} has been enrolled in {class_name}',
            'type' => 'academic',
            'priority' => 'medium',
            'icon' => 'fas fa-user-plus',
            'action_url_template' => '/school_ms/students/view.php?id={student_id}',
            'action_text' => 'View Student'
        ],
        [
            'name' => 'fee_payment_received',
            'title_template' => 'Fee Payment Received',
            'message_template' => 'Payment of ₵{amount} received from {student_name} for {fee_type}',
            'type' => 'finance',
            'priority' => 'medium',
            'icon' => 'fas fa-money-bill-wave',
            'action_url_template' => '/school_ms/finance/view_payment.php?id={payment_id}',
            'action_text' => 'View Payment'
        ],
        [
            'name' => 'fee_payment_overdue',
            'title_template' => 'Fee Payment Overdue',
            'message_template' => '{student_name} has overdue payment of ₵{amount} for {fee_type}',
            'type' => 'finance',
            'priority' => 'high',
            'icon' => 'fas fa-exclamation-triangle',
            'action_url_template' => '/school_ms/finance/overdue.php?student_id={student_id}',
            'action_text' => 'View Details'
        ],
        [
            'name' => 'assignment_submitted',
            'title_template' => 'Assignment Submitted',
            'message_template' => '{student_count} students submitted {assignment_name}',
            'type' => 'academic',
            'priority' => 'medium',
            'icon' => 'fas fa-file-alt',
            'action_url_template' => '/academics/assignments/view.php?id={assignment_id}',
            'action_text' => 'View Submissions'
        ],
        [
            'name' => 'attendance_alert',
            'title_template' => 'Low Attendance Alert',
            'message_template' => '{student_name} has {attendance_percentage}% attendance this month',
            'type' => 'attendance',
            'priority' => 'high',
            'icon' => 'fas fa-calendar-times',
            'action_url_template' => '/school_ms/attendance/student.php?id={student_id}',
            'action_text' => 'View Attendance'
        ],
        [
            'name' => 'grade_published',
            'title_template' => 'Grades Published',
            'message_template' => 'Grades for {subject_name} - {exam_name} have been published',
            'type' => 'grades',
            'priority' => 'medium',
            'icon' => 'fas fa-graduation-cap',
            'action_url_template' => '/academics/grades/view.php?exam_id={exam_id}',
            'action_text' => 'View Grades'
        ],
        [
            'name' => 'event_reminder',
            'title_template' => 'Event Reminder',
            'message_template' => '{event_name} is scheduled for {event_date}',
            'type' => 'events',
            'priority' => 'medium',
            'icon' => 'fas fa-calendar-alt',
            'action_url_template' => '/events/view.php?id={event_id}',
            'action_text' => 'View Event'
        ],
        [
            'name' => 'library_book_due',
            'title_template' => 'Library Book Due',
            'message_template' => 'Book "{book_title}" is due for return on {due_date}',
            'type' => 'library',
            'priority' => 'medium',
            'icon' => 'fas fa-book',
            'action_url_template' => '/school_ms/library/my_books.php',
            'action_text' => 'View Books'
        ],
        [
            'name' => 'system_maintenance',
            'title_template' => 'System Maintenance',
            'message_template' => 'System maintenance scheduled for {maintenance_date} from {start_time} to {end_time}',
            'type' => 'system',
            'priority' => 'high',
            'icon' => 'fas fa-tools',
            'action_url_template' => null,
            'action_text' => null
        ],
        [
            'name' => 'announcement',
            'title_template' => 'New Announcement',
            'message_template' => '{announcement_title}: {announcement_summary}',
            'type' => 'announcement',
            'priority' => 'medium',
            'icon' => 'fas fa-bullhorn',
            'action_url_template' => '/announcements/view.php?id={announcement_id}',
            'action_text' => 'Read More'
        ]
    ];
    
    $template_insert = "
        INSERT IGNORE INTO notification_templates 
        (name, title_template, message_template, type, priority, icon, action_url_template, action_text) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $template_stmt = $db->prepare($template_insert);
    
    foreach ($default_templates as $template) {
        $template_stmt->execute([
            $template['name'],
            $template['title_template'],
            $template['message_template'],
            $template['type'],
            $template['priority'],
            $template['icon'],
            $template['action_url_template'],
            $template['action_text']
        ]);
    }
    
    echo "<p>✅ Default notification templates inserted</p>";
    
    // Insert sample notifications for testing
    $sample_notifications = [
        [
            'user_id' => null, // Global notification
            'title' => 'Welcome to the Enhanced Notification System',
            'message' => 'The notification system has been upgraded with real-time updates and better organization.',
            'type' => 'system',
            'priority' => 'medium',
            'icon' => 'fas fa-rocket'
        ],
        [
            'user_id' => $_SESSION['user_id'],
            'title' => 'Profile Update Reminder',
            'message' => 'Please review and update your profile information to ensure accuracy.',
            'type' => 'general',
            'priority' => 'low',
            'icon' => 'fas fa-user-edit',
            'action_url' => '/school_ms/settings/school.php',
            'action_text' => 'Update Profile'
        ]
    ];
    
    $notification_insert = "
        INSERT INTO notifications 
        (user_id, title, message, type, priority, icon, action_url, action_text, created_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $notification_stmt = $db->prepare($notification_insert);
    
    foreach ($sample_notifications as $notification) {
        $notification_stmt->execute([
            $notification['user_id'],
            $notification['title'],
            $notification['message'],
            $notification['type'],
            $notification['priority'],
            $notification['icon'],
            $notification['action_url'] ?? null,
            $notification['action_text'] ?? null,
            $_SESSION['user_id']
        ]);
    }
    
    echo "<p>✅ Sample notifications created</p>";
    
    echo "<h3>Database Setup Complete!</h3>";
    echo "<p>✅ All notification tables created successfully</p>";
    echo "<p>✅ Default templates and sample data inserted</p>";
    echo "<p>✅ Indexes created for optimal performance</p>";
    
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
</style>
