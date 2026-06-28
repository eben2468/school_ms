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

if ($action === 'check_tables') {
    try {
        $required_tables = ['parent_students', 'announcements', 'notifications', 'fees'];
        $existing_tables = [];
        $missing_tables = [];

        foreach ($required_tables as $table) {
            $check_query = "SHOW TABLES LIKE '$table'";
            $result = $db->query($check_query);
            if ($result->rowCount() > 0) {
                $existing_tables[] = $table;

                // Get table structure
                $structure_query = "DESCRIBE $table";
                $structure_result = $db->query($structure_query);
                $columns = $structure_result->fetchAll(PDO::FETCH_ASSOC);
                $messages[] = ['success', "✅ Table '$table' exists with " . count($columns) . " columns"];
            } else {
                $missing_tables[] = $table;
                $messages[] = ['error', "❌ Table '$table' is missing"];
            }
        }

        if (empty($missing_tables)) {
            $messages[] = ['success', '🎉 All required tables exist in the database!'];
        } else {
            $messages[] = ['warning', '⚠️ Missing tables: ' . implode(', ', $missing_tables)];
        }

    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
    }
}

if ($action === 'create_tables') {
    try {
        $tables_created = 0;

        // Check and create parent_students table
        $check_parent_students = "SHOW TABLES LIKE 'parent_students'";
        $result = $db->query($check_parent_students);
        if ($result->rowCount() == 0) {
            $parent_students_sql = "
            CREATE TABLE parent_students (
                id INT PRIMARY KEY AUTO_INCREMENT,
                parent_id INT NOT NULL,
                student_id INT NOT NULL,
                relationship ENUM('father', 'mother', 'guardian', 'other') NOT NULL,
                is_primary BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                UNIQUE KEY unique_parent_student (parent_id, student_id)
            )";
            $db->exec($parent_students_sql);
            $messages[] = ['success', '✅ parent_students table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ parent_students table already exists'];
        }

        // Check and update announcements table
        $check_announcements = "SHOW TABLES LIKE 'announcements'";
        $result = $db->query($check_announcements);
        if ($result->rowCount() == 0) {
            $announcements_sql = "
            CREATE TABLE announcements (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(255) NOT NULL,
                content TEXT NOT NULL,
                priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
                target_audience ENUM('all', 'students', 'teachers', 'parents', 'staff') DEFAULT 'all',
                status ENUM('draft', 'published', 'archived') DEFAULT 'draft',
                publish_date DATETIME,
                expiry_date DATETIME,
                created_by INT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
            )";
            $db->exec($announcements_sql);
            $messages[] = ['success', '✅ announcements table created successfully'];
            $tables_created++;
        } else {
            // Check if created_by column exists
            $check_column = "SHOW COLUMNS FROM announcements LIKE 'created_by'";
            $column_result = $db->query($check_column);
            if ($column_result->rowCount() == 0) {
                $add_column_sql = "ALTER TABLE announcements ADD COLUMN created_by INT NULL";
                $db->exec($add_column_sql);
                $messages[] = ['success', '✅ Added created_by column to announcements table'];
                $tables_created++;
            } else {
                $messages[] = ['info', 'ℹ️ announcements table already exists with all required columns'];
            }
        }

        // Check and create notifications table
        $check_notifications = "SHOW TABLES LIKE 'notifications'";
        $result = $db->query($check_notifications);
        if ($result->rowCount() == 0) {
            $notifications_sql = "
            CREATE TABLE notifications (
                id INT PRIMARY KEY AUTO_INCREMENT,
                user_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                message TEXT NOT NULL,
                type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
                is_read BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            $db->exec($notifications_sql);
            $messages[] = ['success', '✅ notifications table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ notifications table already exists'];
        }

        // Check and create fees table
        $check_fees = "SHOW TABLES LIKE 'fees'";
        $result = $db->query($check_fees);
        if ($result->rowCount() == 0) {
            $fees_sql = "
            CREATE TABLE fees (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                fee_type VARCHAR(100) NOT NULL,
                amount DECIMAL(10,2) NOT NULL,
                due_date DATE NOT NULL,
                paid_amount DECIMAL(10,2) DEFAULT 0.00,
                payment_date DATE,
                status ENUM('pending', 'partial', 'paid', 'overdue') DEFAULT 'pending',
                academic_year VARCHAR(9) NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
            )";
            $db->exec($fees_sql);
            $messages[] = ['success', '✅ fees table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ fees table already exists'];
        }

        // Check and create grades table
        $check_grades = "SHOW TABLES LIKE 'grades'";
        $result = $db->query($check_grades);
        if ($result->rowCount() == 0) {
            $grades_sql = "
            CREATE TABLE grades (
                id INT PRIMARY KEY AUTO_INCREMENT,
                student_id INT NOT NULL,
                exam_id INT,
                subject_id INT,
                marks_obtained DECIMAL(5,2) NOT NULL,
                total_marks DECIMAL(5,2) NOT NULL,
                percentage DECIMAL(5,2) GENERATED ALWAYS AS ((marks_obtained / total_marks) * 100) STORED,
                grade_letter VARCHAR(5),
                remarks TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE SET NULL,
                FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE SET NULL
            )";
            $db->exec($grades_sql);
            $messages[] = ['success', '✅ grades table created successfully'];
            $tables_created++;
        } else {
            $messages[] = ['info', 'ℹ️ grades table already exists'];
        }

        if ($tables_created > 0) {
            $messages[] = ['success', "🎉 Created $tables_created new tables successfully!"];
        } else {
            $messages[] = ['info', '📋 All required tables already exist in the database'];
        }

    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Database error: ' . $e->getMessage()];
    }
}

if ($action === 'create_sample_data') {
    try {
        // Create sample parent-student relationships
        $parent_query = "SELECT id FROM users WHERE role = 'parent' LIMIT 5";
        $parent_stmt = $db->query($parent_query);
        $parents = $parent_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $student_query = "SELECT id FROM users WHERE role = 'student' LIMIT 10";
        $student_stmt = $db->query($student_query);
        $students = $student_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($parents) && !empty($students)) {
            $relationships = ['father', 'mother', 'guardian'];
            $created_count = 0;
            
            foreach ($parents as $index => $parent) {
                // Assign 1-3 children to each parent
                $num_children = rand(1, min(3, count($students)));
                $assigned_students = array_slice($students, $index * 2, $num_children);
                
                foreach ($assigned_students as $student_index => $student) {
                    $relationship = $relationships[array_rand($relationships)];
                    $is_primary = $student_index === 0; // First relationship is primary
                    
                    $insert_query = "INSERT IGNORE INTO parent_students (parent_id, student_id, relationship, is_primary) VALUES (:parent_id, :student_id, :relationship, :is_primary)";
                    $insert_stmt = $db->prepare($insert_query);
                    $insert_stmt->bindParam(':parent_id', $parent['id']);
                    $insert_stmt->bindParam(':student_id', $student['id']);
                    $insert_stmt->bindParam(':relationship', $relationship);
                    $insert_stmt->bindParam(':is_primary', $is_primary);
                    $insert_stmt->execute();
                    
                    if ($insert_stmt->rowCount() > 0) {
                        $created_count++;
                    }
                }
            }
            
            $messages[] = ['success', "✅ Created $created_count parent-student relationships"];
        } else {
            $messages[] = ['warning', '⚠️ No parents or students found to create relationships'];
        }
        
        // Create sample announcements
        $sample_announcements = [
            ['title' => 'Parent-Teacher Meeting', 'content' => 'Annual parent-teacher meeting scheduled for next week.', 'priority' => 'high', 'target_audience' => 'parents'],
            ['title' => 'School Holiday Notice', 'content' => 'School will be closed for national holiday.', 'priority' => 'medium', 'target_audience' => 'all'],
            ['title' => 'Fee Payment Reminder', 'content' => 'Please ensure all fees are paid by the due date.', 'priority' => 'urgent', 'target_audience' => 'parents']
        ];

        // Check which author column exists in announcements table
        $check_created_by = "SHOW COLUMNS FROM announcements LIKE 'created_by'";
        $created_by_result = $db->query($check_created_by);
        $has_created_by = $created_by_result->rowCount() > 0;

        $check_author_id = "SHOW COLUMNS FROM announcements LIKE 'author_id'";
        $author_id_result = $db->query($check_author_id);
        $has_author_id = $author_id_result->rowCount() > 0;

        $admin_id = $_SESSION['user_id'];
        foreach ($sample_announcements as $announcement) {
            if ($has_created_by) {
                $ann_query = "INSERT INTO announcements (title, content, priority, target_audience, status, publish_date, created_by) VALUES (:title, :content, :priority, :target_audience, 'published', NOW(), :author_id)";
                $ann_stmt = $db->prepare($ann_query);
                $ann_stmt->bindParam(':title', $announcement['title']);
                $ann_stmt->bindParam(':content', $announcement['content']);
                $ann_stmt->bindParam(':priority', $announcement['priority']);
                $ann_stmt->bindParam(':target_audience', $announcement['target_audience']);
                $ann_stmt->bindParam(':author_id', $admin_id);
            } elseif ($has_author_id) {
                $ann_query = "INSERT INTO announcements (title, content, priority, target_audience, status, publish_date, author_id) VALUES (:title, :content, :priority, :target_audience, 'published', NOW(), :author_id)";
                $ann_stmt = $db->prepare($ann_query);
                $ann_stmt->bindParam(':title', $announcement['title']);
                $ann_stmt->bindParam(':content', $announcement['content']);
                $ann_stmt->bindParam(':priority', $announcement['priority']);
                $ann_stmt->bindParam(':target_audience', $announcement['target_audience']);
                $ann_stmt->bindParam(':author_id', $admin_id);
            } else {
                $ann_query = "INSERT INTO announcements (title, content, priority, target_audience, status, publish_date) VALUES (:title, :content, :priority, :target_audience, 'published', NOW())";
                $ann_stmt = $db->prepare($ann_query);
                $ann_stmt->bindParam(':title', $announcement['title']);
                $ann_stmt->bindParam(':content', $announcement['content']);
                $ann_stmt->bindParam(':priority', $announcement['priority']);
                $ann_stmt->bindParam(':target_audience', $announcement['target_audience']);
            }
            $ann_stmt->execute();
        }

        $messages[] = ['success', '✅ Created sample announcements'];
        
        // Create sample fees for students
        if (!empty($students)) {
            $fee_types = ['Tuition Fee', 'Library Fee', 'Sports Fee', 'Lab Fee'];
            $current_year = date('Y') . '-' . (date('Y') + 1);
            
            foreach ($students as $student) {
                foreach ($fee_types as $fee_type) {
                    $amount = rand(500, 2000);
                    $due_date = date('Y-m-d', strtotime('+' . rand(30, 90) . ' days'));
                    
                    $fee_query = "INSERT IGNORE INTO fees (student_id, fee_type, amount, due_date, academic_year, description) VALUES (:student_id, :fee_type, :amount, :due_date, :academic_year, :description)";
                    $fee_stmt = $db->prepare($fee_query);
                    $fee_stmt->bindParam(':student_id', $student['id']);
                    $fee_stmt->bindParam(':fee_type', $fee_type);
                    $fee_stmt->bindParam(':amount', $amount);
                    $fee_stmt->bindParam(':due_date', $due_date);
                    $fee_stmt->bindParam(':academic_year', $current_year);
                    $fee_stmt->bindParam(':description', $fee_type);
                    $fee_stmt->execute();
                }
            }
            
            $messages[] = ['success', '✅ Created sample fee records'];
        }

        // Create sample grades for students
        if (!empty($students)) {
            // First check if we have subjects and exams tables
            $subjects_exist = $db->query("SHOW TABLES LIKE 'subjects'")->rowCount() > 0;
            $exams_exist = $db->query("SHOW TABLES LIKE 'exams'")->rowCount() > 0;

            if ($subjects_exist && $exams_exist) {
                // Get some subjects
                $subjects_query = "SELECT id, name FROM subjects LIMIT 5";
                $subjects_result = $db->query($subjects_query);
                $subjects = $subjects_result->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($subjects)) {
                    foreach ($students as $student) {
                        foreach ($subjects as $subject) {
                            // Create a sample grade
                            $marks_obtained = rand(40, 100);
                            $total_marks = 100;
                            $grade_letter = $marks_obtained >= 90 ? 'A+' : ($marks_obtained >= 80 ? 'A' : ($marks_obtained >= 70 ? 'B+' : ($marks_obtained >= 60 ? 'B' : ($marks_obtained >= 50 ? 'C+' : ($marks_obtained >= 40 ? 'C' : 'F')))));

                            $grade_query = "INSERT IGNORE INTO grades (student_id, subject_id, marks_obtained, total_marks, grade_letter, remarks) VALUES (:student_id, :subject_id, :marks_obtained, :total_marks, :grade_letter, :remarks)";
                            $grade_stmt = $db->prepare($grade_query);
                            $grade_stmt->bindParam(':student_id', $student['id']);
                            $grade_stmt->bindParam(':subject_id', $subject['id']);
                            $grade_stmt->bindParam(':marks_obtained', $marks_obtained);
                            $grade_stmt->bindParam(':total_marks', $total_marks);
                            $grade_stmt->bindParam(':grade_letter', $grade_letter);
                            $remarks = $marks_obtained >= 70 ? 'Good performance' : ($marks_obtained >= 50 ? 'Satisfactory' : 'Needs improvement');
                            $grade_stmt->bindParam(':remarks', $remarks);
                            $grade_stmt->execute();
                        }
                    }
                    $messages[] = ['success', '✅ Created sample grade records'];
                } else {
                    $messages[] = ['warning', '⚠️ No subjects found to create grade records'];
                }
            } else {
                $messages[] = ['info', 'ℹ️ Skipped grade creation - subjects or exams table missing'];
            }
        }

    } catch (PDOException $e) {
        $messages[] = ['error', '❌ Error creating sample data: ' . $e->getMessage()];
    }
}

$title = "Fix Parent Tables";
include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Fix Parent Portal Tables</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Create missing database tables for parent portal functionality</p>
                    </div>
                    <a href="dashboard.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                    </a>
                </div>

                <!-- Messages -->
                <?php foreach ($messages as $message): ?>
                <div class="mb-4 p-4 rounded-lg <?php echo $message[0] === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : ($message[0] === 'error' ? 'bg-red-100 border border-red-400 text-red-700' : 'bg-yellow-100 border border-yellow-400 text-yellow-700'); ?>">
                    <?php echo htmlspecialchars($message[1]); ?>
                </div>
                <?php endforeach; ?>

                <!-- Problem Description -->
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6 mb-6">
                    <div class="flex items-start">
                        <i class="fas fa-exclamation-triangle text-red-500 mt-1 mr-3"></i>
                        <div>
                            <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-2">Missing Database Tables</h4>
                            <p class="text-sm text-red-700 dark:text-red-300 mb-3">
                                The parent portal requires several database tables that may not exist in your current setup:
                            </p>
                            <ul class="text-sm text-red-700 dark:text-red-300 list-disc list-inside space-y-1">
                                <li><strong>parent_students</strong> - Links parents to their children</li>
                                <li><strong>announcements</strong> - School announcements for parents</li>
                                <li><strong>notifications</strong> - Individual notifications</li>
                                <li><strong>fees</strong> - Student fee records</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- Action Steps -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-search text-purple-500 mr-2"></i>
                            Check Current Status
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Check which tables exist and their current structure.
                        </p>
                        <a href="?action=check_tables" class="w-full bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-check mr-2"></i>Check Tables
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-database text-blue-500 mr-2"></i>
                            Step 1: Create Missing Tables
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Create all the required database tables for the parent portal functionality.
                        </p>
                        <a href="?action=create_tables" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-plus mr-2"></i>Create Tables
                        </a>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-seedling text-green-500 mr-2"></i>
                            Step 2: Create Sample Data
                        </h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
                            Create sample parent-student relationships and test data for the portal.
                        </p>
                        <a href="?action=create_sample_data" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg flex items-center justify-center">
                            <i class="fas fa-magic mr-2"></i>Create Sample Data
                        </a>
                    </div>
                </div>

                <!-- Next Steps -->
                <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-6">
                    <h4 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-2">
                        <i class="fas fa-lightbulb mr-2"></i>
                        After Creating Tables
                    </h4>
                    <p class="text-sm text-blue-700 dark:text-blue-300 mb-3">
                        Once you've created the tables and sample data:
                    </p>
                    <ol class="text-sm text-blue-700 dark:text-blue-300 list-decimal list-inside space-y-1">
                        <li>Test the parent portal by logging in as a parent user</li>
                        <li>Verify that parent-student relationships are working</li>
                        <li>Check that attendance, grades, and fees pages load correctly</li>
                        <li>Create additional parent-student relationships as needed</li>
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
