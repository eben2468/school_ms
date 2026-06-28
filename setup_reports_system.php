<?php
if (PHP_SAPI !== 'cli') {
    session_start();
    if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
        header("Location: index.php");
        exit();
    }
} else {
    $_POST['run_migration'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'POST';
}

require_once 'config/database.php';
$database = new Database();
$db = $database->getConnection();

$is_cli = (PHP_SAPI === 'cli');

$migration_results = [];
$run_migration = false;

if ($is_cli) {
    $_POST['run_migration'] = 1;
    $_SERVER['REQUEST_METHOD'] = 'POST';
} else {
    $title = "Database Migration - Reports System";
    include 'includes/header.php';
    include 'includes/sidebar.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $run_migration = true;
    
    // Helper function to check if column exists
    function columnExists($db, $table, $column) {
        try {
            $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }

    // Helper to log results
    function logResult(&$results, $task, $success, $message) {
        $results[] = [
            'task' => $task,
            'success' => $success,
            'message' => $message
        ];
    }

    try {
        // 1. Create grading_scales table
        $sql_grading_scales = "CREATE TABLE IF NOT EXISTS grading_scales (
            id INT PRIMARY KEY AUTO_INCREMENT,
            min_score DECIMAL(5,2) NOT NULL,
            max_score DECIMAL(5,2) NOT NULL,
            grade VARCHAR(5) NOT NULL,
            grade_point DECIMAL(3,1),
            interpretation VARCHAR(50),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($sql_grading_scales);
        logResult($migration_results, "Create grading_scales table", true, "Table structure verified/created successfully.");

        // Insert default WASSEC / Standard grading scales if empty
        $count = $db->query("SELECT COUNT(*) FROM grading_scales")->fetchColumn();
        if ($count == 0) {
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
            logResult($migration_results, "Seed default grading scales", true, "Seeded 9 default grading scale ranges (A1 to F9).");
        } else {
            logResult($migration_results, "Seed default grading scales", true, "Grading scales already exist. Seeding skipped.");
        }

        // 2. Create conduct_records table
        $sql_conduct = "CREATE TABLE IF NOT EXISTS conduct_records (
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
            FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
            FOREIGN KEY (academic_term_id) REFERENCES academic_terms(id) ON DELETE CASCADE,
            FOREIGN KEY (class_id) REFERENCES classes(id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL,
            UNIQUE KEY unique_student_term_conduct (student_id, academic_year_id, academic_term_id)
        )";
        $db->exec($sql_conduct);
        logResult($migration_results, "Create conduct_records table", true, "Table structure verified/created successfully.");

        // 3. Add columns to term_reports table
        $cols_to_add = [
            'overall_grade' => "ALTER TABLE term_reports ADD COLUMN overall_grade VARCHAR(5) DEFAULT NULL",
            'total_marks_obtained' => "ALTER TABLE term_reports ADD COLUMN total_marks_obtained DECIMAL(8,2) DEFAULT NULL",
            'total_marks_possible' => "ALTER TABLE term_reports ADD COLUMN total_marks_possible DECIMAL(8,2) DEFAULT NULL",
            'promoted' => "ALTER TABLE term_reports ADD COLUMN promoted BOOLEAN DEFAULT NULL",
            'class_average' => "ALTER TABLE term_reports ADD COLUMN class_average DECIMAL(5,2) DEFAULT NULL",
            'interest' => "ALTER TABLE term_reports ADD COLUMN interest VARCHAR(50) DEFAULT NULL",
            'attitude' => "ALTER TABLE term_reports ADD COLUMN attitude VARCHAR(50) DEFAULT NULL"
        ];

        foreach ($cols_to_add as $col => $alter_query) {
            if (!columnExists($db, 'term_reports', $col)) {
                $db->exec($alter_query);
                logResult($migration_results, "Add column '$col' to term_reports", true, "Column added successfully.");
            } else {
                logResult($migration_results, "Add column '$col' to term_reports", true, "Column already exists.");
            }
        }

        // 4. Create academic_settings if it doesn't exist
        $sql_academic_settings = "CREATE TABLE IF NOT EXISTS academic_settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        $db->exec($sql_academic_settings);
        logResult($migration_results, "Verify academic_settings table", true, "Table verified/created.");

        // Insert defaults into academic_settings
        $settings_defaults = [
            ['report_template_style', 'classic'],
            ['school_motto', 'Excellence in Character and Knowledge'],
            ['school_postal', 'P.O. Box GP 1234, Accra']
        ];
        $stmt_settings = $db->prepare("INSERT INTO academic_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_key=setting_key");
        foreach ($settings_defaults as $setting) {
            $stmt_settings->execute($setting);
        }
        logResult($migration_results, "Seed default academic settings", true, "School motto, postal box, and report styles seeded/verified.");

        logResult($migration_results, "Database Migration Complete", true, "All database structures and seeds verified successfully.");
    } catch (Exception $e) {
        logResult($migration_results, "Database Migration Complete", false, "Migration failed with error: " . $e->getMessage());
    }
}

if ($is_cli) {
    echo "--- MIGRATION RESULTS ---\n";
    foreach ($migration_results as $res) {
        echo ($res['success'] ? "[SUCCESS] " : "[FAILURE] ") . $res['task'] . ": " . $res['message'] . "\n";
    }
    exit(0);
}
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-700 via-indigo-700 to-purple-800 rounded-xl p-6 text-white shadow-lg">
                        <h1 class="text-3xl font-bold mb-2">System Database Migration</h1>
                        <p class="text-indigo-100">Setup and optimize database structures for the new Terminal Reporting Module.</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-8">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4">Required Database Updates</h2>
                    <p class="text-gray-600 dark:text-gray-400 mb-6">
                        This script will check and upgrade your database schema to support comprehensive report cards:
                    </p>
                    <ul class="list-disc pl-5 text-gray-600 dark:text-gray-400 space-y-2 mb-6">
                        <li>Creates a <strong>configurable grading scales</strong> table (A1-F9 WASSCE standard default).</li>
                        <li>Creates a <strong>conduct records</strong> table for student attitudes, interests, and behavior remarks.</li>
                        <li>Adds required reporting statistics columns to the <code>term_reports</code> table (e.g., class average, total marks possible/obtained, promoted status).</li>
                        <li>Seeds academic settings like the school motto and postal address.</li>
                    </ul>

                    <?php if (!$run_migration): ?>
                    <form method="POST" action="">
                        <input type="hidden" name="run_migration" value="1">
                        <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold py-3 px-6 rounded-lg transition duration-200 flex items-center justify-center">
                            <i class="fas fa-play-circle mr-2 text-lg"></i> Run Migration
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="space-y-4">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white border-b pb-2">Migration Execution Results</h3>
                        
                        <?php foreach ($migration_results as $res): ?>
                        <div class="flex items-start p-3 rounded-lg <?php echo $res['success'] ? 'bg-green-50 dark:bg-green-900/10 border border-green-200 dark:border-green-800/30' : 'bg-red-50 dark:bg-red-900/10 border border-red-200 dark:border-red-800/30'; ?>">
                            <div class="flex-shrink-0 mt-0.5">
                                <?php if ($res['success']): ?>
                                <i class="fas fa-check-circle text-green-500 text-lg"></i>
                                <?php else: ?>
                                <i class="fas fa-times-circle text-red-500 text-lg"></i>
                                <?php endif; ?>
                            </div>
                            <div class="ml-3">
                                <h4 class="text-sm font-semibold <?php echo $res['success'] ? 'text-green-800 dark:text-green-200' : 'text-red-800 dark:text-red-200'; ?>">
                                    <?php echo htmlspecialchars($res['task']); ?>
                                </h4>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    <?php echo htmlspecialchars($res['message']); ?>
                                </p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="mt-6 flex space-x-4">
                            <a href="academic/reports/generate.php" class="flex-1 bg-green-600 hover:bg-green-700 text-white font-semibold py-2.5 px-4 rounded-lg text-center transition duration-200">
                                Go to Report Generator <i class="fas fa-arrow-right ml-1"></i>
                            </a>
                            <a href="setup_reports_system.php" class="bg-gray-200 hover:bg-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-800 dark:text-white font-semibold py-2.5 px-4 rounded-lg text-center transition duration-200">
                                Run Again
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>
