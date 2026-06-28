<?php
/**
 * Staff Management Module - Database Setup Script
 * Creates all necessary tables for the staff management system
 * Run this once to set up the database schema
 */

session_start();

require_once dirname(__DIR__) . '/config/database.php';
$database = new Database();
$db = $database->getConnection();

$results = [];
$errors = [];

try {
    // 1. Create staff_departments table
    $sql = "CREATE TABLE IF NOT EXISTS staff_departments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        name VARCHAR(100) NOT NULL,
        head_id INT DEFAULT NULL,
        description TEXT,
        status ENUM('active','inactive') DEFAULT 'active',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (head_id) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: staff_departments";

    // Insert default departments
    $checkDepts = $db->query("SELECT COUNT(*) FROM staff_departments")->fetchColumn();
    if ($checkDepts == 0) {
        $db->exec("INSERT INTO staff_departments (name, description) VALUES 
            ('Mathematics', 'Mathematics and Statistics Department'),
            ('Science', 'Natural Sciences Department'),
            ('English', 'English Language and Literature Department'),
            ('Social Studies', 'Social Studies and History Department'),
            ('ICT', 'Information and Communication Technology Department'),
            ('Physical Education', 'Physical Education and Sports Department'),
            ('Arts', 'Creative and Performing Arts Department'),
            ('Administration', 'School Administration and Support Staff'),
            ('Library', 'Library Services Department'),
            ('Health', 'Health and Counseling Services')
        ");
        $results[] = "✅ Inserted default departments";
    }

    // 2. Create staff_attendance table
    $sql = "CREATE TABLE IF NOT EXISTS staff_attendance (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id INT NOT NULL,
        date DATE NOT NULL,
        check_in TIME DEFAULT NULL,
        check_out TIME DEFAULT NULL,
        status ENUM('present','absent','late','half_day','on_leave') NOT NULL DEFAULT 'present',
        notes TEXT,
        marked_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY unique_staff_date (staff_id, date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: staff_attendance";

    // 3. Create staff_evaluations table
    $sql = "CREATE TABLE IF NOT EXISTS staff_evaluations (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id INT NOT NULL,
        evaluator_id INT NOT NULL,
        evaluation_period VARCHAR(50),
        academic_year VARCHAR(20),
        teaching_quality TINYINT DEFAULT NULL,
        punctuality TINYINT DEFAULT NULL,
        communication TINYINT DEFAULT NULL,
        professionalism TINYINT DEFAULT NULL,
        teamwork TINYINT DEFAULT NULL,
        innovation TINYINT DEFAULT NULL,
        overall_rating DECIMAL(3,2) DEFAULT NULL,
        strengths TEXT,
        areas_for_improvement TEXT,
        goals TEXT,
        comments TEXT,
        status ENUM('draft','submitted','reviewed','acknowledged') DEFAULT 'draft',
        evaluated_at DATE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (evaluator_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: staff_evaluations";

    // 4. Create staff_qualifications table
    $sql = "CREATE TABLE IF NOT EXISTS staff_qualifications (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id INT NOT NULL,
        type ENUM('degree','diploma','certification','license','training','other') NOT NULL,
        title VARCHAR(255) NOT NULL,
        institution VARCHAR(255),
        date_obtained DATE,
        expiry_date DATE DEFAULT NULL,
        document_path VARCHAR(255) DEFAULT NULL,
        status ENUM('active','expired','pending_renewal') DEFAULT 'active',
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: staff_qualifications";

    // 5. Create staff_schedules table
    $sql = "CREATE TABLE IF NOT EXISTS staff_schedules (
        id INT PRIMARY KEY AUTO_INCREMENT,
        staff_id INT NOT NULL,
        day_of_week ENUM('monday','tuesday','wednesday','thursday','friday','saturday','sunday') NOT NULL,
        shift_start TIME NOT NULL,
        shift_end TIME NOT NULL,
        break_start TIME DEFAULT NULL,
        break_end TIME DEFAULT NULL,
        location VARCHAR(100) DEFAULT NULL,
        effective_from DATE DEFAULT NULL,
        effective_to DATE DEFAULT NULL,
        notes TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (staff_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    $db->exec($sql);
    $results[] = "✅ Created table: staff_schedules";

    // 6. Extend teacher_profiles table with additional columns (if they don't exist)
    $existingColumns = [];
    $cols = $db->query("SHOW COLUMNS FROM teacher_profiles");
    while ($col = $cols->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $col['Field'];
    }

    $newColumns = [
        'department_id' => "ADD COLUMN department_id INT DEFAULT NULL AFTER department",
        'position' => "ADD COLUMN position VARCHAR(100) DEFAULT NULL",
        'employment_status' => "ADD COLUMN employment_status ENUM('active','on_leave','suspended','terminated','retired') DEFAULT 'active'",
        'national_id' => "ADD COLUMN national_id VARCHAR(50) DEFAULT NULL",
        'tax_id' => "ADD COLUMN tax_id VARCHAR(50) DEFAULT NULL",
        'marital_status' => "ADD COLUMN marital_status ENUM('single','married','divorced','widowed') DEFAULT NULL",
        'nationality' => "ADD COLUMN nationality VARCHAR(100) DEFAULT NULL",
        'city' => "ADD COLUMN city VARCHAR(100) DEFAULT NULL",
        'state_region' => "ADD COLUMN state_region VARCHAR(100) DEFAULT NULL",
        'postal_code' => "ADD COLUMN postal_code VARCHAR(20) DEFAULT NULL",
        'emergency_contact_name' => "ADD COLUMN emergency_contact_name VARCHAR(100) DEFAULT NULL",
        'emergency_contact_phone' => "ADD COLUMN emergency_contact_phone VARCHAR(20) DEFAULT NULL",
        'emergency_contact_relation' => "ADD COLUMN emergency_contact_relation VARCHAR(50) DEFAULT NULL",
        'bank_name' => "ADD COLUMN bank_name VARCHAR(100) DEFAULT NULL",
        'bank_account' => "ADD COLUMN bank_account VARCHAR(50) DEFAULT NULL",
        'bank_branch' => "ADD COLUMN bank_branch VARCHAR(100) DEFAULT NULL",
        'specialization' => "ADD COLUMN specialization VARCHAR(255) DEFAULT NULL",
        'contract_type' => "ADD COLUMN contract_type ENUM('full_time','part_time','contract','temporary') DEFAULT 'full_time'",
        'contract_end_date' => "ADD COLUMN contract_end_date DATE DEFAULT NULL",
        'bio' => "ADD COLUMN bio TEXT DEFAULT NULL",
    ];

    foreach ($newColumns as $colName => $alterSql) {
        if (!in_array($colName, $existingColumns)) {
            try {
                $db->exec("ALTER TABLE teacher_profiles $alterSql");
                $results[] = "✅ Added column: teacher_profiles.$colName";
            } catch (PDOException $e) {
                // Column might already exist or other issue
                $errors[] = "⚠️ Could not add column $colName: " . $e->getMessage();
            }
        } else {
            $results[] = "ℹ️ Column already exists: teacher_profiles.$colName";
        }
    }

    // Add foreign key for department_id if not exists
    try {
        $db->exec("ALTER TABLE teacher_profiles ADD FOREIGN KEY (department_id) REFERENCES staff_departments(id) ON DELETE SET NULL");
        $results[] = "✅ Added FK: teacher_profiles.department_id → staff_departments";
    } catch (PDOException $e) {
        // FK might already exist
        $results[] = "ℹ️ FK for department_id may already exist";
    }

} catch (PDOException $e) {
    $errors[] = "❌ Error: " . $e->getMessage();
}

// Output results
$title = "Staff Module - Database Setup";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Module - Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-8">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full p-8">
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-database text-blue-600 text-3xl"></i>
            </div>
            <h1 class="text-3xl font-bold text-gray-800">Staff Module Setup</h1>
            <p class="text-gray-500 mt-2">Database tables and schema setup</p>
        </div>

        <div class="space-y-2 mb-6">
            <?php foreach ($results as $result): ?>
            <div class="flex items-center p-3 bg-gray-50 rounded-lg">
                <span class="text-sm"><?php echo $result; ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="space-y-2 mb-6">
            <h3 class="font-semibold text-red-600">Errors:</h3>
            <?php foreach ($errors as $error): ?>
            <div class="flex items-center p-3 bg-red-50 rounded-lg">
                <span class="text-sm text-red-700"><?php echo $error; ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-8">
            <a href="index.php" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-3 rounded-xl font-medium transition-colors">
                <i class="fas fa-arrow-right mr-2"></i> Go to Staff Directory
            </a>
        </div>
    </div>
</body>
</html>
