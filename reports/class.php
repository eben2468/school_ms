<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get selected class
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$report_type = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_STRING) ?: 'overview';

// Get classes (filtered for teachers)
$classes_query = "SELECT DISTINCT c.id, c.name, c.grade_level 
                  FROM classes c";
if ($user_role === 'teacher') {
    $classes_query .= " JOIN class_teachers ct ON c.id = ct.class_id WHERE ct.teacher_id = :user_id";
}
$classes_query .= " ORDER BY c.grade_level, c.name";

$classes_stmt = $db->prepare($classes_query);
if ($user_role === 'teacher') {
    $classes_stmt->bindParam(':user_id', $user_id);
}
$classes_stmt->execute();
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$class_data = null;
$students = [];
$attendance_stats = [];
$assignment_stats = [];
$exam_stats = [];

if ($selected_class_id) {
    // Get class details
    $class_query = "SELECT * FROM classes WHERE id = :class_id";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->bindParam(':class_id', $selected_class_id);
    $class_stmt->execute();
    $class_data = $class_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($class_data) {
        // Get students in class
        $students_query = "SELECT u.id, u.name, u.email, sp.student_id, sp.admission_date
                          FROM users u
                          JOIN student_classes sc ON u.id = sc.student_id
                          LEFT JOIN student_profiles sp ON u.id = sp.user_id
                          WHERE sc.class_id = :class_id AND sc.status = 'active'
                          ORDER BY u.name";
        $students_stmt = $db->prepare($students_query);
        $students_stmt->bindParam(':class_id', $selected_class_id);
        $students_stmt->execute();
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get attendance statistics
        $attendance_query = "SELECT 
                            COUNT(*) as total_days,
                            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
                            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
                            COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days
                            FROM attendance 
                            WHERE class_id = :class_id 
                            AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
        $attendance_stmt = $db->prepare($attendance_query);
        $attendance_stmt->bindParam(':class_id', $selected_class_id);
        $attendance_stmt->execute();
        $attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get assignment statistics
        $assignment_query = "SELECT 
                            COUNT(*) as total_assignments,
                            COUNT(CASE WHEN due_date >= CURDATE() THEN 1 END) as active_assignments,
                            COUNT(CASE WHEN due_date < CURDATE() THEN 1 END) as past_assignments
                            FROM assignments 
                            WHERE class_id = :class_id";
        $assignment_stmt = $db->prepare($assignment_query);
        $assignment_stmt->bindParam(':class_id', $selected_class_id);
        $assignment_stmt->execute();
        $assignment_stats = $assignment_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get exam statistics
        $exam_query = "SELECT 
                      COUNT(*) as total_exams,
                      COUNT(CASE WHEN date >= CURDATE() THEN 1 END) as upcoming_exams,
                      COUNT(CASE WHEN date < CURDATE() THEN 1 END) as completed_exams,
                      AVG(CASE WHEN date < CURDATE() THEN 
                          (SELECT AVG(marks_obtained) FROM exam_results WHERE exam_id = e.id)
                      END) as average_score
                      FROM exams e
                      WHERE class_id = :class_id";
        $exam_stmt = $db->prepare($exam_query);
        $exam_stmt->bindParam(':class_id', $selected_class_id);
        $exam_stmt->execute();
        $exam_stats = $exam_stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Class Reports</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                </a>
            </div>

            <!-- Class Selection -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="flex gap-4">
                        <div class="flex-grow">
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-2">Select Class</label>
                            <select id="class_id" name="class_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="">Choose a class...</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - 
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <?php if ($selected_class_id): ?>
                        <div class="w-48">
                            <label for="type" class="block text-sm font-medium text-gray-700 mb-2">Report Type</label>
                            <select id="type" name="type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Overview</option>
                                <option value="attendance" <?php echo $report_type === 'attendance' ? 'selected' : ''; ?>>Attendance</option>
                                <option value="academic" <?php echo $report_type === 'academic' ? 'selected' : ''; ?>>Academic Performance</option>
                            </select>
                            <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id && $class_data): ?>
            
            <!-- Class Header -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="text-2xl font-bold text-gray-900">
                                Grade <?php echo htmlspecialchars($class_data['grade_level']); ?> - 
                                <?php echo htmlspecialchars($class_data['name']); ?>
                            </h2>
                            <p class="text-gray-600">Academic Year: <?php echo htmlspecialchars($class_data['academic_year']); ?></p>
                        </div>
                        <div class="text-right">
                            <div class="text-2xl font-bold text-blue-600"><?php echo count($students); ?></div>
                            <div class="text-sm text-gray-600">Total Students</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($report_type === 'overview'): ?>
            <!-- Overview Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
                <!-- Students -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Students</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo count($students); ?></p>
                        </div>
                    </div>
                </div>

                <!-- Assignments -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100">
                            <i class="fas fa-tasks text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Assignments</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $assignment_stats['total_assignments'] ?? 0; ?></p>
                            <p class="text-xs text-gray-500"><?php echo $assignment_stats['active_assignments'] ?? 0; ?> active</p>
                        </div>
                    </div>
                </div>

                <!-- Exams -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-purple-100">
                            <i class="fas fa-file-alt text-purple-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Exams</p>
                            <p class="text-2xl font-semibold text-gray-900"><?php echo $exam_stats['total_exams'] ?? 0; ?></p>
                            <p class="text-xs text-gray-500"><?php echo $exam_stats['upcoming_exams'] ?? 0; ?> upcoming</p>
                        </div>
                    </div>
                </div>

                <!-- Average Score -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100">
                            <i class="fas fa-chart-line text-yellow-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Avg Score</p>
                            <p class="text-2xl font-semibold text-gray-900">
                                <?php echo $exam_stats['average_score'] ? number_format($exam_stats['average_score'], 1) . '%' : 'N/A'; ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Students List -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Class Students</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student ID</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Admission Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($students as $student): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                        <div class="ml-4">
                                            <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($student['name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $student['admission_date'] ? date('M j, Y', strtotime($student['admission_date'])) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="../students/profile.php?id=<?php echo $student['id']; ?>" 
                                       class="text-blue-600 hover:text-blue-900">View Profile</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($report_type === 'attendance'): ?>
            <!-- Attendance Report -->
            <div class="bg-white rounded-lg shadow">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Attendance Summary (Last 30 Days)</h3>
                </div>
                <div class="p-6">
                    <?php if ($attendance_stats['total_days'] > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-green-600">
                                <?php echo round(($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-sm text-gray-600">Present</div>
                            <div class="text-xs text-gray-500"><?php echo $attendance_stats['present_days']; ?> days</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-600">
                                <?php echo round(($attendance_stats['absent_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-sm text-gray-600">Absent</div>
                            <div class="text-xs text-gray-500"><?php echo $attendance_stats['absent_days']; ?> days</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-yellow-600">
                                <?php echo round(($attendance_stats['late_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-sm text-gray-600">Late</div>
                            <div class="text-xs text-gray-500"><?php echo $attendance_stats['late_days']; ?> days</div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-8">
                        <i class="fas fa-calendar-times text-gray-400 text-4xl mb-4"></i>
                        <p class="text-gray-500">No attendance data available for the last 30 days.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($report_type === 'academic'): ?>
            <!-- Academic Performance Report -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Assignment Performance -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Assignment Performance</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Total Assignments:</span>
                                <span class="font-semibold"><?php echo $assignment_stats['total_assignments'] ?? 0; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Active Assignments:</span>
                                <span class="font-semibold text-green-600"><?php echo $assignment_stats['active_assignments'] ?? 0; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Past Assignments:</span>
                                <span class="font-semibold text-blue-600"><?php echo $assignment_stats['past_assignments'] ?? 0; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Exam Performance -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Exam Performance</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex justify-between">
                                <span class="text-gray-600">Total Exams:</span>
                                <span class="font-semibold"><?php echo $exam_stats['total_exams'] ?? 0; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Upcoming Exams:</span>
                                <span class="font-semibold text-yellow-600"><?php echo $exam_stats['upcoming_exams'] ?? 0; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Completed Exams:</span>
                                <span class="font-semibold text-blue-600"><?php echo $exam_stats['completed_exams'] ?? 0; ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Average Score:</span>
                                <span class="font-semibold text-purple-600">
                                    <?php echo $exam_stats['average_score'] ? number_format($exam_stats['average_score'], 1) . '%' : 'N/A'; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php elseif ($selected_class_id): ?>
            <div class="bg-white rounded-lg shadow p-6">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Class Not Found</h3>
                    <p class="text-gray-500">The selected class could not be found or you don't have permission to view it.</p>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
