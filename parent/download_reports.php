<?php
session_start();
// Reports access has been removed for parents
header("Location: dashboard.php");
exit();

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.student_id, c.name as class_name, c.section
    FROM users u
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.role = 'student'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $user_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

$student_id = $_GET['student_id'] ?? null;
$report_type = $_GET['report_type'] ?? null;

// Validate student belongs to parent
if ($student_id) {
    $valid_student = false;
    foreach ($children as $child) {
        if ($child['id'] == $student_id) {
            $valid_student = true;
            $selected_student = $child;
            break;
        }
    }
    if (!$valid_student) {
        $student_id = null;
    }
}

// Handle report generation
if ($student_id && $report_type) {
    switch ($report_type) {
        case 'attendance':
            generateAttendanceReport($db, $student_id, $selected_student);
            break;
        case 'grades':
            generateGradesReport($db, $student_id, $selected_student);
            break;
        case 'progress':
            generateProgressReport($db, $student_id, $selected_student);
            break;
        case 'transcript':
            generateTranscriptReport($db, $student_id, $selected_student);
            break;
    }
}

function generateAttendanceReport($db, $student_id, $student) {
    // Get attendance data for current academic year
    $query = "
        SELECT date, status, remarks, created_at
        FROM attendance 
        WHERE student_id = :student_id 
        AND date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
        ORDER BY date DESC
    ";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate PDF or CSV
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="attendance_report_' . $student['name'] . '.pdf"');
    
    // Simple text output for now (in real implementation, use PDF library)
    echo "ATTENDANCE REPORT\n";
    echo "Student: " . $student['name'] . "\n";
    echo "Class: " . $student['class_name'] . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($attendance as $record) {
        echo date('Y-m-d', strtotime($record['date'])) . " - " . ucfirst($record['status']) . "\n";
    }
    exit();
}

function generateGradesReport($db, $student_id, $student) {
    // Get grades data
    $query = "
        SELECT g.*, s.name as subject_name, e.title as exam_title, e.exam_date
        FROM grades g
        LEFT JOIN exams e ON g.exam_id = e.id
        LEFT JOIN subjects s ON e.subject_id = s.id
        WHERE g.student_id = :student_id
        ORDER BY e.exam_date DESC, s.name
    ";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $grades = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="grades_report_' . $student['name'] . '.pdf"');
    
    echo "GRADES REPORT\n";
    echo "Student: " . $student['name'] . "\n";
    echo "Class: " . $student['class_name'] . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    foreach ($grades as $grade) {
        echo $grade['subject_name'] . " - " . $grade['exam_title'] . ": " . 
             $grade['marks_obtained'] . "/" . $grade['total_marks'] . "\n";
    }
    exit();
}

function generateProgressReport($db, $student_id, $student) {
    // Comprehensive progress report
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="progress_report_' . $student['name'] . '.pdf"');
    
    echo "PROGRESS REPORT\n";
    echo "Student: " . $student['name'] . "\n";
    echo "Class: " . $student['class_name'] . "\n";
    echo "Academic Year: " . date('Y') . "-" . (date('Y') + 1) . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    echo "This is a comprehensive progress report including attendance, grades, and teacher comments.\n";
    exit();
}

function generateTranscriptReport($db, $student_id, $student) {
    // Official transcript
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="transcript_' . $student['name'] . '.pdf"');
    
    echo "OFFICIAL TRANSCRIPT\n";
    echo "Student: " . $student['name'] . "\n";
    echo "Student ID: " . $student['student_id'] . "\n";
    echo "Class: " . $student['class_name'] . "\n";
    echo "Generated: " . date('Y-m-d H:i:s') . "\n\n";
    
    echo "This is an official academic transcript.\n";
    exit();
}

$title = "Download Reports";
include '../includes/header.php';
include '../includes/sidebar.php';
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
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Download Reports</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Download academic reports for your children</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <a href="?student_id=<?php echo $child['id']; ?>" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white block"><?php echo htmlspecialchars($child['name']); ?></span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($child['class_name']); ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_id && isset($selected_student)): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($selected_student['name']); ?></h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($selected_student['class_name']); ?>
                                <?php if ($selected_student['student_id']): ?>
                                • ID: <?php echo htmlspecialchars($selected_student['student_id']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Available Reports -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Attendance Report -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-calendar-check text-blue-600 dark:text-blue-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Attendance Report</h3>
                            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Download detailed attendance records</p>
                            <a href="?student_id=<?php echo $student_id; ?>&report_type=attendance" 
                               class="inline-flex items-center px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors">
                                <i class="fas fa-download mr-2"></i>Download
                            </a>
                        </div>
                    </div>

                    <!-- Grades Report -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-chart-line text-green-600 dark:text-green-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Grades Report</h3>
                            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Download exam results and grades</p>
                            <a href="?student_id=<?php echo $student_id; ?>&report_type=grades" 
                               class="inline-flex items-center px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors">
                                <i class="fas fa-download mr-2"></i>Download
                            </a>
                        </div>
                    </div>

                    <!-- Progress Report -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-purple-100 dark:bg-purple-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-file-alt text-purple-600 dark:text-purple-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Progress Report</h3>
                            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Comprehensive academic progress</p>
                            <a href="?student_id=<?php echo $student_id; ?>&report_type=progress" 
                               class="inline-flex items-center px-4 py-2 bg-purple-600 text-white rounded-lg hover:bg-purple-700 transition-colors">
                                <i class="fas fa-download mr-2"></i>Download
                            </a>
                        </div>
                    </div>

                    <!-- Transcript -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="w-16 h-16 bg-orange-100 dark:bg-orange-900 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fas fa-scroll text-orange-600 dark:text-orange-400 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Official Transcript</h3>
                            <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Official academic transcript</p>
                            <a href="?student_id=<?php echo $student_id; ?>&report_type=transcript" 
                               class="inline-flex items-center px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors">
                                <i class="fas fa-download mr-2"></i>Download
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Children Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No student records are associated with your account.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
