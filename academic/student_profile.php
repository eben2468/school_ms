<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$student_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!$student_id) {
    header("Location: ../students/index.php");
    exit();
}

// Check access permissions
if ($user_role === 'student' && $student_id != $user_id) {
    header("Location: ../dashboard.php");
    exit();
}

if ($user_role === 'parent') {
    // Check if this parent has access to this student
    $parent_check = "SELECT COUNT(*) as count FROM student_profiles WHERE user_id = :student_id AND parent_id = :parent_id";
    $parent_stmt = $db->prepare($parent_check);
    $parent_stmt->bindParam(':student_id', $student_id);
    $parent_stmt->bindParam(':parent_id', $user_id);
    $parent_stmt->execute();
    $parent_access = $parent_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($parent_access['count'] == 0) {
        header("Location: ../dashboard.php");
        exit();
    }
}

// Fetch student details with academic information
$query = "SELECT u.*, sp.*, c.name as class_name, c.grade_level, c.academic_year,
          p.name as parent_name, p.email as parent_email
          FROM users u
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN users p ON sp.parent_id = p.id
          WHERE u.id = :student_id AND u.role = 'student'";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':student_id', $student_id);
    $stmt->execute();
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error and redirect
    error_log("Student profile query error: " . $e->getMessage());
    header("Location: ../students/index.php?error=Database error occurred");
    exit();
}

if (!$student) {
    header("Location: ../students/index.php");
    exit();
}

// Fetch recent academic performance
$academic_results = [];
try {
    $academic_query = "SELECT
        e.name as exam_name,
        e.exam_type,
        e.total_marks,
        er.marks_obtained,
        er.grade,
        s.name as subject_name,
        e.date as exam_date
        FROM exam_results er
        JOIN exams e ON er.exam_id = e.id
        JOIN subjects s ON e.subject_id = s.id
        WHERE er.student_id = :student_id
        ORDER BY e.date DESC
        LIMIT 10";
    $academic_stmt = $db->prepare($academic_query);
    $academic_stmt->bindParam(':student_id', $student_id);
    $academic_stmt->execute();
    $academic_results = $academic_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Log error but continue - academic results are optional
    error_log("Academic results query error: " . $e->getMessage());
}

// Fetch attendance summary
$attendance_summary = ['total_days' => 0, 'present_days' => 0, 'absent_days' => 0, 'late_days' => 0];
$attendance_percentage = 0;

try {
    $attendance_query = "SELECT
        COUNT(*) as total_days,
        SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
        SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
        SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
        FROM attendance
        WHERE student_id = :student_id
        AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $attendance_stmt = $db->prepare($attendance_query);
    $attendance_stmt->bindParam(':student_id', $student_id);
    $attendance_stmt->execute();
    $attendance_result = $attendance_stmt->fetch(PDO::FETCH_ASSOC);

    if ($attendance_result) {
        $attendance_summary = $attendance_result;
        $attendance_percentage = $attendance_summary['total_days'] > 0
            ? round(($attendance_summary['present_days'] / $attendance_summary['total_days']) * 100, 1)
            : 0;
    }
} catch (PDOException $e) {
    // Log error but continue - attendance is optional
    error_log("Attendance query error: " . $e->getMessage());
}

$title = "Student Profile - " . $student['name'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-white mb-4">Academic Profile</h1>
                    <div class="flex gap-3">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                        <a href="../students/edit.php?id=<?php echo $student_id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap">
                            <i class="fas fa-edit mr-2"></i>Edit Profile
                        </a>
                        <?php endif; ?>
                        <a href="<?php echo $user_role === 'student' ? '../dashboard.php' : 'index.php'; ?>" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Student Information Card -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-lg shadow overflow-hidden">
                            <div class="p-6">
                                <div class="text-center mb-6">
                                    <div class="w-24 h-24 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                        <i class="fas fa-user-graduate text-blue-600 text-3xl"></i>
                                    </div>
                                    <h2 class="text-xl font-semibold text-gray-800"><?php echo htmlspecialchars($student['name']); ?></h2>
                                    <p class="text-gray-600"><?php echo htmlspecialchars($student['student_id'] ?? 'No ID'); ?></p>
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php echo $student['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                        <?php echo ucfirst($student['status']); ?>
                                    </span>
                                </div>

                                <div class="space-y-4">
                                    <div>
                                        <h3 class="text-sm font-medium text-gray-500">Academic Information</h3>
                                        <div class="mt-2 space-y-2">
                                            <?php if ($student['class_name']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Class:</span> <?php echo htmlspecialchars($student['grade_level'] . ' - ' . $student['class_name']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($student['academic_year']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Academic Year:</span> <?php echo htmlspecialchars($student['academic_year']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($student['admission_date']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Admission Date:</span> <?php echo date('M j, Y', strtotime($student['admission_date'])); ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div>
                                        <h3 class="text-sm font-medium text-gray-500">Personal Information</h3>
                                        <div class="mt-2 space-y-2">
                                            <?php if ($student['date_of_birth']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Date of Birth:</span> <?php echo date('M j, Y', strtotime($student['date_of_birth'])); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($student['gender']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Gender:</span> <?php echo ucfirst($student['gender']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($student['blood_group']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Blood Group:</span> <?php echo htmlspecialchars($student['blood_group']); ?>
                                            </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($student['parent_name'] || $student['guardian_name']): ?>
                                    <div>
                                        <h3 class="text-sm font-medium text-gray-500">Parent/Guardian</h3>
                                        <div class="mt-2 space-y-2">
                                            <?php if ($student['parent_name']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Parent:</span> <?php echo htmlspecialchars($student['parent_name']); ?>
                                            </p>
                                            <?php if ($student['parent_email']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Parent Email:</span> <?php echo htmlspecialchars($student['parent_email']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php endif; ?>

                                            <?php if ($student['guardian_name']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Guardian:</span> <?php echo htmlspecialchars($student['guardian_name']); ?>
                                            </p>
                                            <?php if ($student['guardian_phone']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Guardian Phone:</span> <?php echo htmlspecialchars($student['guardian_phone']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php if ($student['guardian_email']): ?>
                                            <p class="text-sm text-gray-900">
                                                <span class="font-medium">Guardian Email:</span> <?php echo htmlspecialchars($student['guardian_email']); ?>
                                            </p>
                                            <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Attendance Summary -->
                        <div class="bg-white rounded-lg shadow overflow-hidden mt-6">
                            <div class="p-6">
                                <h3 class="text-lg font-medium text-gray-900 mb-4">Attendance (Last 30 Days)</h3>
                                <div class="space-y-3">
                                    <div class="flex justify-between">
                                        <span class="text-sm text-gray-600">Attendance Rate</span>
                                        <span class="text-sm font-medium text-gray-900"><?php echo $attendance_percentage; ?>%</span>
                                    </div>
                                    <div class="w-full bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $attendance_percentage; ?>%"></div>
                                    </div>
                                    <div class="grid grid-cols-3 gap-4 text-center">
                                        <div>
                                            <div class="text-lg font-semibold text-green-600"><?php echo $attendance_summary['present_days']; ?></div>
                                            <div class="text-xs text-gray-500">Present</div>
                                        </div>
                                        <div>
                                            <div class="text-lg font-semibold text-red-600"><?php echo $attendance_summary['absent_days']; ?></div>
                                            <div class="text-xs text-gray-500">Absent</div>
                                        </div>
                                        <div>
                                            <div class="text-lg font-semibold text-yellow-600"><?php echo $attendance_summary['late_days']; ?></div>
                                            <div class="text-xs text-gray-500">Late</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Academic Performance -->
                    <div class="lg:col-span-2">
                        <div class="bg-white rounded-lg shadow overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">Recent Academic Performance</h3>
                            </div>
                            <div class="p-6">
                                <?php if (!empty($academic_results)): ?>
                                <div class="overflow-x-auto">
                                    <table class="min-w-full divide-y divide-gray-200">
                                        <thead class="bg-gray-50">
                                            <tr>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Exam</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Score</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Grade</th>
                                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200">
                                            <?php foreach ($academic_results as $result): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                                    <?php echo htmlspecialchars($result['subject_name']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?php echo htmlspecialchars($result['exam_name']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo ucfirst($result['exam_type']); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                                    <?php echo $result['marks_obtained']; ?>/<?php echo $result['total_marks']; ?>
                                                    <span class="text-gray-500">
                                                        (<?php echo round(($result['marks_obtained'] / $result['total_marks']) * 100, 1); ?>%)
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                                        <?php echo htmlspecialchars($result['grade'] ?? 'N/A'); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <?php echo date('M j, Y', strtotime($result['exam_date'])); ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php else: ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-chart-line text-gray-400 text-4xl mb-4"></i>
                                    <h3 class="text-lg font-medium text-gray-900 mb-2">No Academic Records</h3>
                                    <p class="text-gray-500">No exam results found for this student yet.</p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white rounded-lg shadow overflow-hidden mt-6">
                            <div class="px-6 py-4 border-b border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900">Quick Actions</h3>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                    <a href="../attendance/take.php?student_id=<?php echo $student_id; ?>" 
                                       class="flex flex-col items-center p-4 bg-blue-50 rounded-lg hover:bg-blue-100 transition-colors">
                                        <i class="fas fa-calendar-check text-blue-600 text-2xl mb-2"></i>
                                        <span class="text-sm font-medium text-blue-900">Take Attendance</span>
                                    </a>
                                    <a href="../academic/exams/results.php?student_id=<?php echo $student_id; ?>" 
                                       class="flex flex-col items-center p-4 bg-green-50 rounded-lg hover:bg-green-100 transition-colors">
                                        <i class="fas fa-chart-bar text-green-600 text-2xl mb-2"></i>
                                        <span class="text-sm font-medium text-green-900">View Results</span>
                                    </a>
                                    <?php endif; ?>
                                    <a href="../students/profile.php?id=<?php echo $student_id; ?>" 
                                       class="flex flex-col items-center p-4 bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors">
                                        <i class="fas fa-user text-purple-600 text-2xl mb-2"></i>
                                        <span class="text-sm font-medium text-purple-900">Full Profile</span>
                                    </a>
                                    <a href="../messages/compose.php?to=<?php echo $student_id; ?>" 
                                       class="flex flex-col items-center p-4 bg-yellow-50 rounded-lg hover:bg-yellow-100 transition-colors">
                                        <i class="fas fa-envelope text-yellow-600 text-2xl mb-2"></i>
                                        <span class="text-sm font-medium text-yellow-900">Send Message</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
