<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        die("Database connection failed!\n");
    }
    
    echo "Starting database fixes...\n";

    // Array of SQL commands to execute
    $sql_commands = [
        // CRITICAL FIXES FOR PARENT PORTAL ERRORS
        // Fix attendance table - add missing remarks column
        "ALTER TABLE attendance ADD COLUMN IF NOT EXISTS remarks TEXT",

        // Fix exams table - add missing teacher_id column
        "ALTER TABLE exams ADD COLUMN IF NOT EXISTS teacher_id INT",
        "ALTER TABLE exams ADD CONSTRAINT fk_exams_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL",

        // Fix users table - add missing student_id column
        "ALTER TABLE users ADD COLUMN IF NOT EXISTS student_id VARCHAR(50)",
        "CREATE INDEX IF NOT EXISTS idx_users_student_id ON users(student_id)",

        // Create notifications table for the notifications system
        "CREATE TABLE IF NOT EXISTS notifications (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('academic', 'finance', 'system', 'announcement', 'general') DEFAULT 'general',
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            is_read BOOLEAN DEFAULT FALSE,
            action_url VARCHAR(500),
            action_text VARCHAR(100),
            icon VARCHAR(50) DEFAULT 'fas fa-bell',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            read_at TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",

        // Fix existing notifications table if it exists but missing read_at column
        "ALTER TABLE notifications ADD COLUMN IF NOT EXISTS read_at TIMESTAMP NULL",

        // Create document_uploads table for document management
        "CREATE TABLE IF NOT EXISTS document_uploads (
            id INT PRIMARY KEY AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_type VARCHAR(50) NOT NULL,
            file_size BIGINT NOT NULL,
            document_type ENUM('academic', 'administrative', 'certificate', 'transcript', 'id_card', 'other') DEFAULT 'other',
            uploaded_by INT NOT NULL,
            access_level ENUM('public', 'staff', 'students', 'parents', 'private') DEFAULT 'private',
            is_shared BOOLEAN DEFAULT FALSE,
            download_count INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
        )",

        // Create shared_documents table for file sharing
        "CREATE TABLE IF NOT EXISTS shared_documents (
            id INT PRIMARY KEY AUTO_INCREMENT,
            document_id INT NOT NULL,
            shared_by INT NOT NULL,
            shared_with INT,
            shared_with_role ENUM('student', 'teacher', 'parent', 'admin', 'all'),
            access_type ENUM('view', 'download', 'edit') DEFAULT 'view',
            expires_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (document_id) REFERENCES document_uploads(id) ON DELETE CASCADE,
            FOREIGN KEY (shared_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (shared_with) REFERENCES users(id) ON DELETE CASCADE
        )",

        // Create transcript_requests table
        "CREATE TABLE IF NOT EXISTS transcript_requests (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            requested_by INT NOT NULL,
            request_type ENUM('official', 'unofficial', 'electronic') DEFAULT 'official',
            purpose VARCHAR(255),
            delivery_method ENUM('pickup', 'mail', 'email') DEFAULT 'pickup',
            delivery_address TEXT,
            status ENUM('pending', 'processing', 'ready', 'delivered', 'cancelled') DEFAULT 'pending',
            fee_amount DECIMAL(10,2) DEFAULT 0.00,
            fee_paid BOOLEAN DEFAULT FALSE,
            processed_by INT,
            processed_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL
        )",

        // Fix hostel_blocks table
        "ALTER TABLE hostel_blocks ADD COLUMN IF NOT EXISTS name VARCHAR(100)",
        "UPDATE hostel_blocks SET name = COALESCE(block_name, CONCAT('Block ', id)) WHERE name IS NULL OR name = ''",
        
        // Create hostel_students table
        "CREATE TABLE IF NOT EXISTS hostel_students (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            block_id INT NOT NULL,
            room_id INT NOT NULL,
            allocation_date DATE NOT NULL,
            checkout_date DATE,
            status ENUM('active', 'checked_out', 'transferred') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (block_id) REFERENCES hostel_blocks(id) ON DELETE CASCADE,
            FOREIGN KEY (room_id) REFERENCES hostel_rooms(id) ON DELETE CASCADE
        )",
        
        // Add visit_date column to health_records
        "ALTER TABLE health_records ADD COLUMN visit_date DATE",
        "UPDATE health_records SET visit_date = record_date WHERE visit_date IS NULL AND record_date IS NOT NULL",
        
        // Create counseling_sessions table
        "CREATE TABLE IF NOT EXISTS counseling_sessions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            counselor_id INT NOT NULL,
            session_date DATE NOT NULL,
            session_time TIME NOT NULL,
            duration_minutes INT DEFAULT 60,
            session_type ENUM('academic', 'behavioral', 'personal', 'career', 'other') NOT NULL,
            notes TEXT,
            recommendations TEXT,
            follow_up_required BOOLEAN DEFAULT FALSE,
            follow_up_date DATE,
            status ENUM('scheduled', 'completed', 'cancelled', 'no_show') DEFAULT 'scheduled',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (counselor_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        
        // Fix canteen_inventory table
        "ALTER TABLE canteen_inventory ADD COLUMN unit_price DECIMAL(8,2) DEFAULT 0.00",
        
        // Create support_tickets table
        "CREATE TABLE IF NOT EXISTS support_tickets (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            subject VARCHAR(255) NOT NULL,
            description TEXT NOT NULL,
            priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
            status ENUM('open', 'in_progress', 'resolved', 'closed') DEFAULT 'open',
            assigned_to INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
        )",
        
        // Create feedback table
        "CREATE TABLE IF NOT EXISTS feedback (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            feedback_type ENUM('suggestion', 'complaint', 'compliment', 'bug_report', 'other') NOT NULL,
            subject VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            rating INT CHECK (rating >= 1 AND rating <= 5),
            status ENUM('pending', 'reviewed', 'addressed') DEFAULT 'pending',
            admin_response TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )",
        
        // Create school_settings table
        "CREATE TABLE IF NOT EXISTS school_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            school_name VARCHAR(255) NOT NULL DEFAULT 'Greenwood Academy',
            school_address TEXT,
            school_phone VARCHAR(20),
            school_email VARCHAR(100),
            school_website VARCHAR(255),
            principal_name VARCHAR(100),
            academic_year_start DATE,
            academic_year_end DATE,
            currency VARCHAR(10) DEFAULT 'GHS',
            timezone VARCHAR(50) DEFAULT 'Africa/Accra',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",
        
        // Insert default school settings
        "INSERT IGNORE INTO school_settings (school_name, academic_year_start, academic_year_end, currency, timezone)
         VALUES ('Greenwood Academy', CONCAT(YEAR(CURDATE()), '-09-01'), CONCAT(YEAR(CURDATE()) + 1, '-06-30'), 'GHS', 'Africa/Accra')",

        // Insert sample notifications for testing
        "INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
         (NULL, 'New Student Enrollment', 'John Doe has been successfully enrolled in Grade 5A. Please review the enrollment details and assign necessary resources.', 'academic', 'medium', 'fas fa-user-plus')",

        "INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
         (NULL, 'Assignment Submissions', '15 students have submitted their Math homework for Chapter 5. Review submissions are now available.', 'academic', 'low', 'fas fa-check')",

        "INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
         (NULL, 'Fee Payment Reminder', 'Monthly fees for December are due. Please ensure timely payment to avoid late charges.', 'finance', 'high', 'fas fa-money-bill-wave')",

        "INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
         (NULL, 'System Maintenance', 'Scheduled system maintenance will occur this weekend. Some services may be temporarily unavailable.', 'system', 'medium', 'fas fa-tools')",

        "INSERT IGNORE INTO notifications (user_id, title, message, type, priority, icon) VALUES
         (NULL, 'Parent-Teacher Meeting', 'Parent-teacher meetings are scheduled for next week. Please check your calendar for appointment details.', 'announcement', 'high', 'fas fa-calendar-alt')"
    ];

    $success_count = 0;
    $error_count = 0;

    foreach ($sql_commands as $index => $sql) {
        try {
            runDdlSafely($db, $sql);
            $success_count++;
            echo "✓ Command " . ($index + 1) . " executed successfully\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false || 
                strpos($e->getMessage(), 'already exists') !== false) {
                echo "✓ Command " . ($index + 1) . " - Already exists (OK)\n";
                $success_count++;
            } else {
                echo "✗ Command " . ($index + 1) . " failed: " . $e->getMessage() . "\n";
                $error_count++;
            }
        }
    }

    echo "\n=== SUMMARY ===\n";
    echo "✓ Successful operations: $success_count\n";
    echo "✗ Failed operations: $error_count\n";
    echo "\nDatabase fixes completed!\n";
    
    // Test some key tables
    echo "\n=== TESTING TABLES ===\n";
    $test_tables = ['hostel_students', 'counseling_sessions', 'support_tickets', 'feedback', 'school_settings'];
    
    foreach ($test_tables as $table) {
        try {
            $result = $db->query("SELECT COUNT(*) FROM $table");
            $count = $result->fetchColumn();
            echo "✓ Table '$table' exists with $count records\n";
        } catch (PDOException $e) {
            echo "✗ Table '$table' issue: " . $e->getMessage() . "\n";
        }
    }
    
} catch (Exception $e) {
    echo "✗ Fatal error: " . $e->getMessage() . "\n";
}
?>
