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
    header("Location: index.php");
    exit();
}

// Check access permissions
if ($user_role === 'student' && $student_id != $user_id) {
    header("Location: ../dashboard.php");
    exit();
}

if ($user_role === 'parent') {
    // Check if this parent is linked to this student
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

// Fetch student details
$query = "SELECT u.*, sp.*, c.name as class_name, c.grade_level,
          p.name as parent_name, p.email as parent_email
          FROM users u
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN users p ON sp.parent_id = p.id
          WHERE u.id = :student_id AND u.role = 'student'";
$stmt = $db->prepare($query);
$stmt->bindParam(':student_id', $student_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: index.php");
    exit();
}

$first_name = !empty($student['first_name']) ? $student['first_name'] : '';
$other_names = !empty($student['other_names']) ? $student['other_names'] : '';
$last_name = !empty($student['last_name']) ? $student['last_name'] : '';

if (empty($first_name) && empty($last_name) && !empty($student['name'])) {
    $fullName = trim($student['name']);
    $parts = preg_split('/\s+/', $fullName);
    $num_parts = count($parts);
    if ($num_parts === 1) {
        $first_name = $parts[0];
    } elseif ($num_parts === 2) {
        $first_name = $parts[0];
        $last_name = $parts[1];
    } else {
        $first_name = $parts[0];
        $last_name = $parts[$num_parts - 1];
        $other_names = implode(' ', array_slice($parts, 1, $num_parts - 2));
    }
}

// Fetch recent assignments for this student
$assignments_query = "SELECT a.*, s.name as subject_name, u.name as teacher_name,
                     sa.submitted_at, sa.grade, sa.feedback
                     FROM assignments a
                     JOIN subjects s ON a.subject_id = s.id
                     JOIN users u ON a.teacher_id = u.id
                     LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :student_id
                     WHERE a.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active')
                     ORDER BY a.due_date DESC
                     LIMIT 5";
$assignments_stmt = $db->prepare($assignments_query);
$assignments_stmt->bindParam(':student_id', $student_id);
$assignments_stmt->execute();
$assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent attendance
$attendance_query = "SELECT a.*, c.name as class_name
                    FROM attendance a
                    JOIN classes c ON a.class_id = c.id
                    WHERE a.student_id = :student_id
                    ORDER BY a.date DESC
                    LIMIT 10";
$attendance_stmt = $db->prepare($attendance_query);
$attendance_stmt->bindParam(':student_id', $student_id);
$attendance_stmt->execute();
$attendance_records = $attendance_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate attendance statistics
$attendance_stats_query = "SELECT 
                          COUNT(*) as total_days,
                          SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
                          SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                          SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
                          FROM attendance 
                          WHERE student_id = :student_id 
                          AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
$attendance_stats_stmt = $db->prepare($attendance_stats_query);
$attendance_stats_stmt->bindParam(':student_id', $student_id);
$attendance_stats_stmt->execute();
$attendance_stats = $attendance_stats_stmt->fetch(PDO::FETCH_ASSOC);

$attendance_percentage = $attendance_stats['total_days'] > 0 ? 
    round(($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100, 1) : 0;

// Fetch recent exam results
$exam_results_query = "SELECT er.*, e.name as exam_name, s.name as subject_name, e.total_marks
                      FROM exam_results er
                      JOIN exams e ON er.exam_id = e.id
                      JOIN subjects s ON e.subject_id = s.id
                      WHERE er.student_id = :student_id
                      ORDER BY e.date DESC
                      LIMIT 5";
$exam_results_stmt = $db->prepare($exam_results_query);
$exam_results_stmt->bindParam(':student_id', $student_id);
$exam_results_stmt->execute();
$exam_results = $exam_results_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white mb-4">Student Profile</h1>
                    <div class="flex gap-3">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin'])): ?>
                        <a href="edit.php?id=<?php echo $student_id; ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg flex items-center justify-center whitespace-nowrap">
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
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                        <div class="p-6">
                            <div class="text-center mb-6">
                                <div class="w-24 h-24 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-4 overflow-hidden border-4 border-white shadow-lg">
                                    <?php if(!empty($student['profile_picture'])): ?>
                                        <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($student['profile_picture']); ?>" class="w-full h-full object-cover">
                                    <?php else: ?>
                                        <i class="fas fa-user text-blue-600 dark:text-blue-400 text-3xl"></i>
                                    <?php endif; ?>
                                </div>
                                <h2 class="text-xl font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($student['name']); ?></h2>
                                <p class="text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($student['student_id'] ?? 'No ID'); ?></p>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                    <?php echo $student['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                    <?php echo ucfirst($student['status']); ?>
                                </span>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Basic Information</h3>
                                    <div class="mt-2 space-y-2 text-sm">
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">First Name:</span>
                                            <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($first_name); ?></span>
                                        </div>
                                        <?php if (!empty($other_names)): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Other Name(s):</span>
                                            <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($other_names); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Last Name:</span>
                                            <span class="text-gray-900 font-semibold"><?php echo htmlspecialchars($last_name); ?></span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Email:</span>
                                            <span class="text-gray-900"><?php echo htmlspecialchars($student['email']); ?></span>
                                        </div>
                                        <?php if ($student['date_of_birth']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Date of Birth:</span>
                                            <span class="text-gray-900"><?php echo date('M j, Y', strtotime($student['date_of_birth'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['gender']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Gender:</span>
                                            <span class="text-gray-900"><?php echo ucfirst($student['gender']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['blood_group']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Blood Group:</span>
                                            <span class="text-gray-900"><?php echo htmlspecialchars($student['blood_group']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Academic Information</h3>
                                    <div class="mt-2 space-y-2 text-sm">
                                        <?php if ($student['class_name']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Class:</span>
                                            <span class="text-gray-900"><?php echo htmlspecialchars($student['grade_level'] . ' - ' . $student['class_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['admission_date']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Admission Date:</span>
                                            <span class="text-gray-900"><?php echo date('M j, Y', strtotime($student['admission_date'])); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div>
                                    <h3 class="text-sm font-medium text-gray-500">Contact Information</h3>
                                    <div class="mt-2 space-y-2 text-sm">
                                        <?php if ($student['phone']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600">Phone:</span>
                                            <span class="text-gray-900"><?php echo htmlspecialchars($student['phone']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['address']): ?>
                                        <div>
                                            <span class="text-gray-600">Address:</span>
                                            <p class="text-gray-900 mt-1"><?php echo nl2br(htmlspecialchars($student['address'])); ?></p>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <?php if ($student['parent_name'] || $student['guardian_name'] || $student['parent_email'] || $student['guardian_email']): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Parent/Guardian Information</h3>
                                    <div class="mt-2 space-y-2 text-sm">
                                        <?php if ($student['parent_name']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Parent Name:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['parent_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['parent_email']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Parent Email:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['parent_email']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['guardian_name']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Guardian Name:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['guardian_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['guardian_phone']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Guardian Phone:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['guardian_phone']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['guardian_email']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Guardian Email:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['guardian_email']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Emergency Contact Information -->
                                <?php if ($student['emergency_contact_name'] || $student['emergency_contact_phone']): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Emergency Contact</h3>
                                    <div class="mt-2 space-y-2 text-sm">
                                        <?php if ($student['emergency_contact_name']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Contact Name:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['emergency_contact_name']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                        <?php if ($student['emergency_contact_phone']): ?>
                                        <div class="flex justify-between">
                                            <span class="text-gray-600 dark:text-gray-400">Contact Phone:</span>
                                            <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['emergency_contact_phone']); ?></span>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Medical Information -->
                                <?php if ($student['medical_conditions']): ?>
                                <div>
                                    <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400">Medical Information</h3>
                                    <div class="mt-2 text-sm">
                                        <span class="text-gray-600 dark:text-gray-400">Medical Conditions:</span>
                                        <p class="text-gray-900 dark:text-white mt-1"><?php echo nl2br(htmlspecialchars($student['medical_conditions'])); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Performance -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Attendance Summary -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Attendance Summary (Last 30 Days)</h2>
                        </div>
                        <div class="p-6">
                            <div class="grid grid-cols-4 gap-2 md:gap-4 mb-6">
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-green-600"><?php echo $attendance_percentage; ?>%</div>
                                    <div class="text-sm text-gray-600">Attendance Rate</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-blue-600"><?php echo $attendance_stats['present_days']; ?></div>
                                    <div class="text-sm text-gray-600">Present Days</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-red-600"><?php echo $attendance_stats['absent_days']; ?></div>
                                    <div class="text-sm text-gray-600">Absent Days</div>
                                </div>
                                <div class="text-center">
                                    <div class="text-2xl font-bold text-yellow-600"><?php echo $attendance_stats['late_days']; ?></div>
                                    <div class="text-sm text-gray-600">Late Days</div>
                                </div>
                            </div>

                            <div class="space-y-2">
                                <h3 class="font-medium text-gray-800">Recent Attendance</h3>
                                <?php foreach (array_slice($attendance_records, 0, 5) as $record): ?>
                                <div class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0">
                                    <span class="text-sm text-gray-600"><?php echo date('M j, Y', strtotime($record['date'])); ?></span>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?php 
                                        switch($record['status']) {
                                            case 'present': echo 'bg-green-100 text-green-800'; break;
                                            case 'absent': echo 'bg-red-100 text-red-800'; break;
                                            case 'late': echo 'bg-yellow-100 text-yellow-800'; break;
                                        }
                                        ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Assignments -->
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Recent Assignments</h2>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <?php foreach ($assignments as $assignment): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="font-medium text-gray-800"><?php echo htmlspecialchars($assignment['title']); ?></h3>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full
                                            <?php echo $assignment['submitted_at'] ? 'bg-green-100 text-green-800' : 
                                                (strtotime($assignment['due_date']) < time() ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'); ?>">
                                            <?php echo $assignment['submitted_at'] ? 'Submitted' : 
                                                (strtotime($assignment['due_date']) < time() ? 'Overdue' : 'Pending'); ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600 mb-2">
                                        <span><?php echo htmlspecialchars($assignment['subject_name']); ?></span> • 
                                        <span><?php echo htmlspecialchars($assignment['teacher_name']); ?></span>
                                    </div>
                                    <div class="text-sm text-gray-500">
                                        Due: <?php echo date('M j, Y g:i A', strtotime($assignment['due_date'])); ?>
                                    </div>
                                    <?php if ($assignment['grade']): ?>
                                    <div class="mt-2 text-sm">
                                        <span class="font-medium text-gray-700">Grade: </span>
                                        <span class="text-green-600 font-semibold"><?php echo $assignment['grade']; ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Exam Results -->
                    <?php if (!empty($exam_results)): ?>
                    <div class="bg-white rounded-lg shadow">
                        <div class="px-6 py-4 border-b border-gray-200">
                            <h2 class="text-xl font-semibold text-gray-800">Recent Exam Results</h2>
                        </div>
                        <div class="p-6">
                            <div class="space-y-4">
                                <?php foreach ($exam_results as $result): ?>
                                <div class="border border-gray-200 rounded-lg p-4">
                                    <div class="flex justify-between items-start mb-2">
                                        <h3 class="font-medium text-gray-800"><?php echo htmlspecialchars($result['exam_name']); ?></h3>
                                        <span class="text-lg font-semibold text-blue-600">
                                            <?php echo $result['marks_obtained']; ?>/<?php echo $result['total_marks']; ?>
                                        </span>
                                    </div>
                                    <div class="text-sm text-gray-600 mb-2">
                                        Subject: <?php echo htmlspecialchars($result['subject_name']); ?>
                                    </div>
                                    <?php if ($result['grade']): ?>
                                    <div class="text-sm">
                                        <span class="font-medium text-gray-700">Grade: </span>
                                        <span class="text-green-600 font-semibold"><?php echo htmlspecialchars($result['grade']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($result['remarks']): ?>
                                    <div class="text-sm text-gray-600 mt-2">
                                        <?php echo htmlspecialchars($result['remarks']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
