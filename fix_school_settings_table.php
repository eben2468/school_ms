<?php
/**
 * Fix School Settings Table
 * This script updates the school_settings table structure
 */

require_once 'config/database.php';

try {
    echo "Checking and fixing school settings table...\n";
    
    // Check if table exists
    if (isset($pdo)) {
        $db = $pdo;
    } elseif (isset($conn)) {
        // Convert mysqli to PDO for consistency
        $db = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        throw new Exception("No database connection available");
    }
    
    // Check if table exists
    $tableExists = $db->query("SHOW TABLES LIKE 'school_settings'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "Creating school_settings table...\n";
        $createTable = "
        CREATE TABLE `school_settings` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `school_name` varchar(255) NOT NULL DEFAULT 'Greenwood Academy',
          `school_address` text,
          `school_phone` varchar(50),
          `school_email` varchar(255),
          `school_website` varchar(255),
          `school_logo` varchar(255),
          `principal_name` varchar(255),
          `academic_year_start` date,
          `academic_year_end` date,
          `currency` varchar(10) DEFAULT 'GHS',
          `currency_symbol` varchar(10) DEFAULT '₵',
          `timezone` varchar(100) DEFAULT 'Africa/Accra',
          `terms_per_year` int(11) DEFAULT 3,
          `grading_system` enum('percentage','letter','gpa','points') DEFAULT 'percentage',
          `theme_color` varchar(50) DEFAULT 'blue',
          `default_language` varchar(10) DEFAULT 'en',
          `date_format` varchar(20) DEFAULT 'Y-m-d',
          `time_format` varchar(20) DEFAULT 'H:i',
          `sms_gateway` enum('disabled','twilio','nexmo','local') DEFAULT 'disabled',
          `email_notifications` enum('enabled','disabled','admin_only') DEFAULT 'enabled',
          `parent_portal` enum('enabled','disabled','restricted') DEFAULT 'enabled',
          `student_portal` enum('enabled','disabled','restricted') DEFAULT 'enabled',
          `maintenance_mode` enum('enabled','disabled') DEFAULT 'disabled',
          `registration_enabled` enum('enabled','disabled','admin_only') DEFAULT 'enabled',
          `max_file_upload_size` varchar(10) DEFAULT '10MB',
          `session_timeout` int(11) DEFAULT 30,
          `backup_frequency` enum('daily','weekly','monthly','manual') DEFAULT 'weekly',
          `auto_backup` enum('enabled','disabled') DEFAULT 'enabled',
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $db->exec($createTable);
        echo "✅ Table created successfully!\n";
    } else {
        echo "Table exists, checking columns...\n";
        
        // Get existing columns
        $columns = $db->query("SHOW COLUMNS FROM school_settings")->fetchAll(PDO::FETCH_COLUMN);
        
        // Define required columns
        $requiredColumns = [
            'school_logo' => "ADD COLUMN `school_logo` varchar(255) AFTER `school_website`",
            'currency_symbol' => "ADD COLUMN `currency_symbol` varchar(10) DEFAULT '₵' AFTER `currency`",
            'terms_per_year' => "ADD COLUMN `terms_per_year` int(11) DEFAULT 3 AFTER `timezone`",
            'grading_system' => "ADD COLUMN `grading_system` enum('percentage','letter','gpa','points') DEFAULT 'percentage' AFTER `terms_per_year`",
            'theme_color' => "ADD COLUMN `theme_color` varchar(50) DEFAULT 'blue' AFTER `grading_system`",
            'default_language' => "ADD COLUMN `default_language` varchar(10) DEFAULT 'en' AFTER `theme_color`",
            'date_format' => "ADD COLUMN `date_format` varchar(20) DEFAULT 'Y-m-d' AFTER `default_language`",
            'time_format' => "ADD COLUMN `time_format` varchar(20) DEFAULT 'H:i' AFTER `date_format`",
            'sms_gateway' => "ADD COLUMN `sms_gateway` enum('disabled','twilio','nexmo','local') DEFAULT 'disabled' AFTER `time_format`",
            'email_notifications' => "ADD COLUMN `email_notifications` enum('enabled','disabled','admin_only') DEFAULT 'enabled' AFTER `sms_gateway`",
            'parent_portal' => "ADD COLUMN `parent_portal` enum('enabled','disabled','restricted') DEFAULT 'enabled' AFTER `email_notifications`",
            'student_portal' => "ADD COLUMN `student_portal` enum('enabled','disabled','restricted') DEFAULT 'enabled' AFTER `parent_portal`",
            'maintenance_mode' => "ADD COLUMN `maintenance_mode` enum('enabled','disabled') DEFAULT 'disabled' AFTER `student_portal`",
            'registration_enabled' => "ADD COLUMN `registration_enabled` enum('enabled','disabled','admin_only') DEFAULT 'enabled' AFTER `maintenance_mode`",
            'max_file_upload_size' => "ADD COLUMN `max_file_upload_size` varchar(10) DEFAULT '10MB' AFTER `registration_enabled`",
            'session_timeout' => "ADD COLUMN `session_timeout` int(11) DEFAULT 30 AFTER `max_file_upload_size`",
            'backup_frequency' => "ADD COLUMN `backup_frequency` enum('daily','weekly','monthly','manual') DEFAULT 'weekly' AFTER `session_timeout`",
            'auto_backup' => "ADD COLUMN `auto_backup` enum('enabled','disabled') DEFAULT 'enabled' AFTER `backup_frequency`"
        ];
        
        // Add missing columns
        foreach ($requiredColumns as $column => $sql) {
            if (!in_array($column, $columns)) {
                echo "Adding column: $column\n";
                $db->exec("ALTER TABLE school_settings $sql");
            }
        }
        
        echo "✅ Table structure updated!\n";
    }
    
    // Insert default settings if table is empty
    $count = $db->query("SELECT COUNT(*) FROM school_settings")->fetchColumn();
    
    if ($count == 0) {
        echo "Inserting default settings...\n";
        $insertDefault = "
        INSERT INTO `school_settings` (
          `school_name`,
          `school_address`,
          `school_phone`,
          `school_email`,
          `school_website`,
          `principal_name`,
          `academic_year_start`,
          `academic_year_end`,
          `currency`,
          `currency_symbol`,
          `timezone`,
          `terms_per_year`,
          `grading_system`,
          `theme_color`,
          `default_language`,
          `date_format`,
          `time_format`,
          `sms_gateway`,
          `email_notifications`,
          `parent_portal`,
          `student_portal`,
          `maintenance_mode`,
          `registration_enabled`,
          `max_file_upload_size`,
          `session_timeout`,
          `backup_frequency`,
          `auto_backup`
        ) VALUES (
          'Greenwood Academy',
          '123 Education Street, Learning City, LC 12345',
          '+1 (234) 567-8900',
          'info@greenwoodacademy.edu',
          'https://www.greenwoodacademy.edu',
          'Dr. Jane Smith',
          '2024-09-01',
          '2025-06-30',
          'GHS',
          '₵',
          'Africa/Accra',
          3,
          'percentage',
          'blue',
          'en',
          'Y-m-d',
          'H:i',
          'disabled',
          'enabled',
          'enabled',
          'enabled',
          'disabled',
          'enabled',
          '10MB',
          30,
          'weekly',
          'enabled'
        )";
        
        $db->exec($insertDefault);
        echo "✅ Default settings inserted!\n";
    } else {
        echo "Settings already exist, skipping default insert.\n";
    }
    
    echo "✅ School settings table setup completed successfully!\n";
    echo "✅ You can now configure your school settings at: settings/school.php\n";
    
} catch (Exception $e) {
    echo "❌ Error setting up school settings: " . $e->getMessage() . "\n";
    exit(1);
}
?>
