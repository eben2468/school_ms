<?php
require_once 'config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    echo "<h2>Setting up Academic Term/Year Management System</h2>\n";
    echo "<pre>\n";
    
    // Create tables directly
    $tables = [
        // Academic Years table
        "CREATE TABLE IF NOT EXISTS academic_years (
            id INT PRIMARY KEY AUTO_INCREMENT,
            year_name VARCHAR(9) NOT NULL UNIQUE,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active', 'completed', 'upcoming') DEFAULT 'upcoming',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )",

        // Academic Terms table
        "CREATE TABLE IF NOT EXISTS academic_terms (
            id INT PRIMARY KEY AUTO_INCREMENT,
            academic_year_id INT NOT NULL,
            term_number ENUM('1', '2', '3') NOT NULL,
            term_name VARCHAR(50) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active', 'completed', 'upcoming') DEFAULT 'upcoming',
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            UNIQUE KEY unique_term_per_year (academic_year_id, term_number)
        )",

        // Student Promotions table
        "CREATE TABLE IF NOT EXISTS student_promotions (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            from_academic_year_id INT NOT NULL,
            to_academic_year_id INT NOT NULL,
            from_class_id INT NOT NULL,
            to_class_id INT NOT NULL,
            promotion_status ENUM('promoted', 'repeated', 'transferred', 'graduated') NOT NULL,
            promotion_date DATE NOT NULL,
            remarks TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (from_academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            FOREIGN KEY (to_academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            FOREIGN KEY (from_class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (to_class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
        )",

        // Student Academic Records table
        "CREATE TABLE IF NOT EXISTS student_academic_records (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            academic_term_id INT NOT NULL,
            class_id INT NOT NULL,
            subject_id INT NOT NULL,
            continuous_assessment DECIMAL(5,2) DEFAULT 0.00,
            exam_score DECIMAL(5,2) DEFAULT 0.00,
            total_score DECIMAL(5,2) DEFAULT 0.00,
            grade VARCHAR(5),
            remarks TEXT,
            teacher_id INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
            FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_student_subject_term (student_id, academic_year_id, academic_term_id, subject_id)
        )",

        // Term Reports table
        "CREATE TABLE IF NOT EXISTS term_reports (
            id INT PRIMARY KEY AUTO_INCREMENT,
            student_id INT NOT NULL,
            academic_year_id INT NOT NULL,
            academic_term_id INT NOT NULL,
            class_id INT NOT NULL,
            total_subjects INT DEFAULT 0,
            total_score DECIMAL(8,2) DEFAULT 0.00,
            average_score DECIMAL(5,2) DEFAULT 0.00,
            position_in_class INT,
            class_size INT,
            attendance_days INT DEFAULT 0,
            attendance_present INT DEFAULT 0,
            conduct_grade VARCHAR(5),
            teacher_remarks TEXT,
            principal_remarks TEXT,
            next_term_begins DATE,
            report_generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            generated_by INT,
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (generated_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_student_term_report (student_id, academic_year_id, academic_term_id)
        )",

        // Academic Settings table
        "CREATE TABLE IF NOT EXISTS academic_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(100) UNIQUE NOT NULL,
            setting_value TEXT,
            description TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
        )"
    ];

    foreach ($tables as $index => $sql) {
        try {
            $db->exec($sql);
            echo "✓ Created table " . ($index + 1) . "\n";
        } catch (PDOException $e) {
            echo "⚠ Warning creating table " . ($index + 1) . ": " . $e->getMessage() . "\n";
        }
    }

    // Insert default academic settings
    try {
        $settings_sql = "INSERT IGNORE INTO academic_settings (setting_key, setting_value, description) VALUES
            ('current_academic_year_id', NULL, 'ID of the currently active academic year'),
            ('current_academic_term_id', NULL, 'ID of the currently active academic term'),
            ('auto_promotion_enabled', 'false', 'Whether to enable automatic student promotion'),
            ('minimum_pass_score', '50', 'Minimum score required to pass a subject'),
            ('grading_system', 'percentage', 'Grading system: percentage, letter, or points')";
        $db->exec($settings_sql);
        echo "✓ Inserted default academic settings\n";
    } catch (PDOException $e) {
        echo "⚠ Warning inserting settings: " . $e->getMessage() . "\n";
    }

    // Create indexes for better performance
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_academic_records_student_year_term ON student_academic_records(student_id, academic_year_id, academic_term_id)",
        "CREATE INDEX IF NOT EXISTS idx_promotions_student_year ON student_promotions(student_id, from_academic_year_id)",
        "CREATE INDEX IF NOT EXISTS idx_terms_year_status ON academic_terms(academic_year_id, status)",
        "CREATE INDEX IF NOT EXISTS idx_years_status ON academic_years(status)"
    ];

    foreach ($indexes as $index => $sql) {
        try {
            $db->exec($sql);
            echo "✓ Created index " . ($index + 1) . "\n";
        } catch (PDOException $e) {
            echo "⚠ Warning creating index " . ($index + 1) . ": " . $e->getMessage() . "\n";
        }
    }
    
    // Create default academic year and terms for current year
    $current_year = date('Y');
    $next_year = $current_year + 1;
    $academic_year_name = $current_year . '-' . $next_year;
    
    // Check if current academic year exists
    $check_year = "SELECT id FROM academic_years WHERE year_name = :year_name";
    $stmt = $db->prepare($check_year);
    $stmt->bindParam(':year_name', $academic_year_name);
    $stmt->execute();
    
    if (!$stmt->fetch()) {
        // Create current academic year
        $insert_year = "INSERT INTO academic_years (year_name, start_date, end_date, status, description) 
                       VALUES (:year_name, :start_date, :end_date, 'active', 'Current Academic Year')";
        $stmt = $db->prepare($insert_year);
        $start_date = $current_year . '-09-01';
        $end_date = $next_year . '-06-30';
        $stmt->bindParam(':year_name', $academic_year_name);
        $stmt->bindParam(':start_date', $start_date);
        $stmt->bindParam(':end_date', $end_date);
        $stmt->execute();
        
        $academic_year_id = $db->lastInsertId();
        echo "✓ Created academic year: $academic_year_name\n";
        
        // Create three terms for the academic year
        $terms = [
            ['1', 'First Term', $current_year . '-09-01', $current_year . '-12-15'],
            ['2', 'Second Term', ($current_year + 1) . '-01-15', ($current_year + 1) . '-04-15'],
            ['3', 'Third Term', ($current_year + 1) . '-04-30', ($current_year + 1) . '-06-30']
        ];
        
        foreach ($terms as $term) {
            $insert_term = "INSERT INTO academic_terms (academic_year_id, term_number, term_name, start_date, end_date, status) 
                           VALUES (:year_id, :term_number, :term_name, :start_date, :end_date, :status)";
            $stmt = $db->prepare($insert_term);
            $stmt->bindParam(':year_id', $academic_year_id);
            $stmt->bindParam(':term_number', $term[0]);
            $stmt->bindParam(':term_name', $term[1]);
            $stmt->bindParam(':start_date', $term[2]);
            $stmt->bindParam(':end_date', $term[3]);
            
            // Set first term as active, others as upcoming
            $status = ($term[0] == '1') ? 'active' : 'upcoming';
            $stmt->bindParam(':status', $status);
            $stmt->execute();
            
            if ($term[0] == '1') {
                $first_term_id = $db->lastInsertId();
            }
            
            echo "✓ Created {$term[1]} ({$term[2]} to {$term[3]})\n";
        }
        
        // Update academic settings
        $update_settings = [
            ['current_academic_year_id', $academic_year_id],
            ['current_academic_term_id', $first_term_id ?? null]
        ];
        
        foreach ($update_settings as $setting) {
            $update_sql = "UPDATE academic_settings SET setting_value = :value WHERE setting_key = :key";
            $stmt = $db->prepare($update_sql);
            $stmt->bindParam(':value', $setting[1]);
            $stmt->bindParam(':key', $setting[0]);
            $stmt->execute();
            echo "✓ Updated setting: {$setting[0]} = {$setting[1]}\n";
        }
        
    } else {
        echo "✓ Academic year $academic_year_name already exists\n";
    }
    
    echo "\n✅ Academic Term/Year Management System setup completed successfully!\n";
    echo "</pre>\n";
    
} catch (Exception $e) {
    echo "<pre>❌ Error: " . $e->getMessage() . "</pre>\n";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Academic System Setup</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        pre { background: #f5f5f5; padding: 15px; border-radius: 5px; }
        .success { color: green; }
        .error { color: red; }
        .warning { color: orange; }
    </style>
</head>
<body>
    <h1>Academic Term/Year Management System Setup</h1>
    <p>This script sets up the database tables and initial data for the academic term and year management system.</p>
    
    <div style="margin-top: 80px;">
        <a href="academic/settings/" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">
            Go to Academic Settings
        </a>
        <a href="index.php" style="background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-left: 10px;">
            Back to Dashboard
        </a>
    </div>
</body>
</html>
