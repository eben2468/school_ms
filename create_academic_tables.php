<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: auth/login.php");
    exit();
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$messages = [];
$action = $_GET['action'] ?? '';

if ($action === 'create_academic_tables') {
    try {
        $tables_created = 0;
        
        // Create subjects table
        $check_subjects = "SHOW TABLES LIKE 'subjects'";
        $result = $db->query($check_subjects);
        if ($result->rowCount() == 0) {
            $subjects_sql = "
            CREATE TABLE subjects (
                id INT PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(100) NOT NULL,
                code VARCHAR(20) UNIQUE NOT NULL,
                description TEXT,
                grade_level VARCHAR(20),
                credits INT DEFAULT 1,
                status ENUM('active', 'inactive') DEFAULT 'active',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $db->exec($subjects_sql);
            $messages[] = ['success', '✅ subjects table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ subjects table already exists'];
        }
        
        // Create exams table
        $check_exams = "SHOW TABLES LIKE 'exams'";
        $result = $db->query($check_exams);
        if ($result->rowCount() == 0) {
            $exams_sql = "
            CREATE TABLE exams (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                subject_id INT NOT NULL,
                teacher_id INT,
                exam_date DATE NOT NULL,
                start_time TIME,
                end_time TIME,
                total_marks DECIMAL(5,2) NOT NULL,
                passing_marks DECIMAL(5,2),
                exam_type ENUM('quiz', 'midterm', 'final', 'assignment', 'project') DEFAULT 'quiz',
                instructions TEXT,
                status ENUM('scheduled', 'ongoing', 'completed', 'cancelled') DEFAULT 'scheduled',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
                FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE SET NULL
            )";
            $db->exec($exams_sql);
            $messages[] = ['success', '✅ exams table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ exams table already exists'];
        }
        
        // Create attendance table
        $check_attendance = "SHOW TABLES LIKE 'attendance'";
        $result = $db->query($check_attendance);
        if ($result->rowCount() == 0) {
            $attendance_sql = "
            CREATE TABLE attendance (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                class_id INT,
                date DATE NOT NULL,
                status ENUM('present', 'absent', 'late', 'excused') NOT NULL,
                remarks TEXT,
                marked_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE SET NULL,
                FOREIGN KEY (marked_by) REFERENCES users(id) ON DELETE SET NULL,
                UNIQUE KEY unique_student_date (student_id, date)
            )";
            $db->exec($attendance_sql);
            $messages[] = ['success', '✅ attendance table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ attendance table already exists'];
        }
        
        if ($tables_created > 0) {
            $messages[] = ['success', "🎉 Created $tables_created academic tables successfully!"];
        } else {
            $messages[] = ['info', '📋 All academic tables already exist in the database'];
        }
        
    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
    }
}

if ($action === 'create_sample_academic_data') {
    try {
        // Check if subjects table has grade_level column
        $check_grade_level = "SHOW COLUMNS FROM subjects LIKE 'grade_level'";
        $grade_level_result = $db->query($check_grade_level);
        $has_grade_level = $grade_level_result->rowCount() > 0;

        // Create sample subjects
        $sample_subjects = [
            ['name' => 'Mathematics', 'code' => 'MATH101', 'description' => 'Basic Mathematics'],
            ['name' => 'English Language', 'code' => 'ENG101', 'description' => 'English Language and Literature'],
            ['name' => 'Science', 'code' => 'SCI101', 'description' => 'General Science'],
            ['name' => 'History', 'code' => 'HIST101', 'description' => 'World History'],
            ['name' => 'Geography', 'code' => 'GEO101', 'description' => 'Physical and Human Geography']
        ];

        $subjects_created = 0;
        foreach ($sample_subjects as $subject) {
            if ($has_grade_level) {
                $subject_query = "INSERT IGNORE INTO subjects (name, code, description, grade_level) VALUES (:name, :code, :description, :grade_level)";
                $subject_stmt = $db->prepare($subject_query);
                $subject_stmt->bindParam(':name', $subject['name']);
                $subject_stmt->bindParam(':code', $subject['code']);
                $subject_stmt->bindParam(':description', $subject['description']);
                $grade_level = 'Grade 10';
                $subject_stmt->bindParam(':grade_level', $grade_level);
            } else {
                $subject_query = "INSERT IGNORE INTO subjects (name, code, description) VALUES (:name, :code, :description)";
                $subject_stmt = $db->prepare($subject_query);
                $subject_stmt->bindParam(':name', $subject['name']);
                $subject_stmt->bindParam(':code', $subject['code']);
                $subject_stmt->bindParam(':description', $subject['description']);
            }
            $subject_stmt->execute();
            if ($subject_stmt->rowCount() > 0) {
                $subjects_created++;
            }
        }
        
        if ($subjects_created > 0) {
            $messages[] = ['success', "✅ Created $subjects_created sample subjects"];
        }
        
        // Create sample exams
        $subjects_query = "SELECT id, name FROM subjects LIMIT 5";
        $subjects_result = $db->query($subjects_query);
        $subjects = $subjects_result->fetchAll(PDO::FETCH_ASSOC);
        
        $teacher_query = "SELECT id FROM users WHERE role = 'teacher' LIMIT 1";
        $teacher_result = $db->query($teacher_query);
        $teacher = $teacher_result->fetch(PDO::FETCH_ASSOC);
        $teacher_id = $teacher ? $teacher['id'] : null;
        
        $exams_created = 0;
        foreach ($subjects as $subject) {
            $exam_types = ['quiz', 'midterm', 'final'];
            foreach ($exam_types as $type) {
                $exam_date = date('Y-m-d', strtotime('+' . rand(1, 30) . ' days'));
                $total_marks = $type === 'quiz' ? 20 : ($type === 'midterm' ? 50 : 100);
                
                $exam_query = "INSERT IGNORE INTO exams (title, subject_id, teacher_id, exam_date, total_marks, passing_marks, exam_type) VALUES (:title, :subject_id, :teacher_id, :exam_date, :total_marks, :passing_marks, :exam_type)";
                $exam_stmt = $db->prepare($exam_query);
                $title = $subject['name'] . ' ' . ucfirst($type);
                $exam_stmt->bindParam(':title', $title);
                $exam_stmt->bindParam(':subject_id', $subject['id']);
                $exam_stmt->bindParam(':teacher_id', $teacher_id);
                $exam_stmt->bindParam(':exam_date', $exam_date);
                $exam_stmt->bindParam(':total_marks', $total_marks);
                $passing_marks = $total_marks * 0.4; // 40% passing
                $exam_stmt->bindParam(':passing_marks', $passing_marks);
                $exam_stmt->bindParam(':exam_type', $type);
                $exam_stmt->execute();
                if ($exam_stmt->rowCount() > 0) {
                    $exams_created++;
                }
            }
        }
        
        if ($exams_created > 0) {
            $messages[] = ['success', "✅ Created $exams_created sample exams"];
        }
        
        // Create sample attendance records
        $students_query = "SELECT id FROM users WHERE role = 'student' LIMIT 10";
        $students_result = $db->query($students_query);
        $students = $students_result->fetchAll(PDO::FETCH_ASSOC);
        
        $attendance_created = 0;
        foreach ($students as $student) {
            // Create attendance for last 30 days
            for ($i = 30; $i >= 1; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                // Skip weekends
                if (date('N', strtotime($date)) >= 6) continue;
                
                $status = rand(1, 100) <= 85 ? 'present' : (rand(1, 100) <= 10 ? 'late' : 'absent');
                
                $attendance_query = "INSERT IGNORE INTO attendance (student_id, date, status, marked_by) VALUES (:student_id, :date, :status, :marked_by)";
                $attendance_stmt = $db->prepare($attendance_query);
                $attendance_stmt->bindParam(':student_id', $student['id']);
                $attendance_stmt->bindParam(':date', $date);
                $attendance_stmt->bindParam(':status', $status);
                $attendance_stmt->bindParam(':marked_by', $_SESSION['user_id']);
                $attendance_stmt->execute();
                if ($attendance_stmt->rowCount() > 0) {
                    $attendance_created++;
                }
            }
        }
        
        if ($attendance_created > 0) {
            $messages[] = ['success', "✅ Created $attendance_created sample attendance records"];
        }
        
    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Error creating sample academic data: ' . $e->getMessage()];
    }
}

$title = "Create Academic Tables";
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Create Academic Tables</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Create missing academic tables for grades, attendance, and exams</p>
                    </div>
                    <a href="fix_parent_tables.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Parent Fix
                    </a>
                </div>

                <!-- Messages -->
                <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $message[0] === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : ($message[0] === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : ($message[0] === 'warning' ? 'bg-yellow-100 border border-yellow-400 text-yellow-700' : 'bg-blue-100 border border-blue-400 text-blue-700')); ?>">
                    <?php echo htmlspecialchars($message[1]); ?>
                </div>
                <?php endforeach; ?>

                <!-- Info -->
                <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-info-circle text-blue-500 mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-2">Academic System Tables</h4>
                            <p class="text-sm text-blue-700 dark:text-blue-300 mb-3">
                                These tables are needed for the parent portal to display grades and attendance:
                            </p>
                            <ul class="text-sm text-blue-700 dark:text-blue-300 list-disc list-inside space-y-1">
                                <li><strong>subjects</strong> - Academic subjects (Math, English, etc.)</li>
                                <li><strong>exams</strong> - Exam records and details</li>
                                <li><strong>grades</strong> - Student exam results</li>
                                <li><strong>attendance</strong> - Daily attendance records</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Action Steps -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-database text-blue-500 mr-2"></i>
                            Create Academic Tables
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Create the subjects, exams, grades, and attendance tables needed for the academic system.
                        </p>
                        <a href="?action=create_academic_tables" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i>Create Tables
                        </a>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-seedling text-green-500 mr-2"></i>
                            Create Sample Data
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Create sample subjects, exams, grades, and attendance records for testing.
                        </p>
                        <a href="?action=create_sample_academic_data" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-magic mr-2"></i>Create Sample Data
                        </a>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="mt-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-6">
                    <h4 class="text-lg font-semibold text-green-900 dark:text-green-200 mb-2">
                        <i class="fas fa-check-circle mr-2"></i>
                        After Creating Tables
                    </h4>
                    <p class="text-sm text-green-700 dark:text-green-300 mb-3">
                        Once you've created the academic tables:
                    </p>
                    <ol class="text-sm text-green-700 dark:text-green-300 list-decimal list-inside space-y-1">
                        <li>Go back to the parent tables fix and create sample data</li>
                        <li>Test the parent portal grades and attendance pages</li>
                        <li>Create real academic data as needed</li>
                        <li>Set up parent-student relationships</li>
                    </ol>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>
