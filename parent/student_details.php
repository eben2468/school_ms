<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.email, u.student_id, u.phone, u.address, u.date_of_birth, u.gender,
           c.name as class_name, c.section, u.admission_date, u.profile_picture
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

// Get additional student information
$student_details = [];
if ($student_id) {
    // Get subjects for the student
    $subjects_query = "
        SELECT s.name, s.code, u.name as teacher_name
        FROM subjects s
        LEFT JOIN users u ON s.teacher_id = u.id
        WHERE s.class_id = (SELECT class_id FROM users WHERE id = :student_id)
        ORDER BY s.name
    ";
    $subjects_stmt = $db->prepare($subjects_query);
    $subjects_stmt->bindParam(':student_id', $student_id);
    $subjects_stmt->execute();
    $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get recent attendance summary
    $attendance_query = "
        SELECT 
            COUNT(*) as total_days,
            SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) as present_days,
            SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) as absent_days,
            SUM(CASE WHEN status = 'late' THEN 1 ELSE 0 END) as late_days
        FROM attendance 
        WHERE student_id = :student_id 
        AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ";
    $attendance_stmt = $db->prepare($attendance_query);
    $attendance_stmt->bindParam(':student_id', $student_id);
    $attendance_stmt->execute();
    $attendance_summary = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get recent grades
    $grades_query = "
        SELECT g.marks_obtained, g.total_marks, g.percentage, g.grade_letter,
               s.name as subject_name, e.title as exam_title, e.exam_date
        FROM grades g
        LEFT JOIN exams e ON g.exam_id = e.id
        LEFT JOIN subjects s ON e.subject_id = s.id
        WHERE g.student_id = :student_id
        ORDER BY e.exam_date DESC
        LIMIT 5
    ";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':student_id', $student_id);
    $grades_stmt->execute();
    $recent_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get emergency contacts
    $emergency_query = "
        SELECT name, relationship, phone, email
        FROM emergency_contacts
        WHERE student_id = :student_id
        ORDER BY priority ASC
    ";
    $emergency_stmt = $db->prepare($emergency_query);
    $emergency_stmt->bindParam(':student_id', $student_id);
    $emergency_stmt->execute();
    $emergency_contacts = $emergency_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $student_details = [
        'subjects' => $subjects,
        'attendance_summary' => $attendance_summary,
        'recent_grades' => $recent_grades,
        'emergency_contacts' => $emergency_contacts
    ];
}

$title = "Student Details";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Student Details</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">View detailed information about your children</p>
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
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3">
                                    <?php if ($child['profile_picture']): ?>
                                    <img src="../<?php echo htmlspecialchars($child['profile_picture']); ?>" alt="Profile" class="w-12 h-12 rounded-full object-cover">
                                    <?php else: ?>
                                    <i class="fas fa-user-graduate text-blue-500"></i>
                                    <?php endif; ?>
                                </div>
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
                <!-- Student Profile Card -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-start space-x-6">
                        <div class="flex-shrink-0">
                            <div class="w-24 h-24 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                <?php if ($selected_student['profile_picture']): ?>
                                <img src="../<?php echo htmlspecialchars($selected_student['profile_picture']); ?>" alt="Profile" class="w-24 h-24 rounded-full object-cover">
                                <?php else: ?>
                                <i class="fas fa-user-graduate text-blue-500 text-3xl"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex-1">
                            <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($selected_student['name']); ?></h2>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
                                <div>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Student ID:</strong> <?php echo htmlspecialchars($selected_student['student_id']); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Class:</strong> <?php echo htmlspecialchars($selected_student['class_name']); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Section:</strong> <?php echo htmlspecialchars($selected_student['section']); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Gender:</strong> <?php echo ucfirst($selected_student['gender']); ?></p>
                                </div>
                                <div>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Date of Birth:</strong> <?php echo date('M j, Y', strtotime($selected_student['date_of_birth'])); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Admission Date:</strong> <?php echo date('M j, Y', strtotime($selected_student['admission_date'])); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Email:</strong> <?php echo htmlspecialchars($selected_student['email']); ?></p>
                                    <p class="text-gray-600 dark:text-gray-400"><strong>Phone:</strong> <?php echo htmlspecialchars($selected_student['phone']); ?></p>
                                </div>
                            </div>
                            <?php if ($selected_student['address']): ?>
                            <div class="mt-4">
                                <p class="text-gray-600 dark:text-gray-400"><strong>Address:</strong> <?php echo htmlspecialchars($selected_student['address']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats -->
                <?php if (!empty($student_details['attendance_summary'])): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Days</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?php echo $student_details['attendance_summary']['total_days']; ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Last 30 days</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Present</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400"><?php echo $student_details['attendance_summary']['present_days']; ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Days attended</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Absent</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400"><?php echo $student_details['attendance_summary']['absent_days']; ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Days missed</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-clock text-yellow-600 dark:text-yellow-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Late</h3>
                                <p class="text-2xl font-bold text-yellow-600 dark:text-yellow-400"><?php echo $student_details['attendance_summary']['late_days']; ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">Times late</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Subjects -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Subjects</h3>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($student_details['subjects'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($student_details['subjects'] as $subject): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($subject['name']); ?></h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400">Code: <?php echo htmlspecialchars($subject['code']); ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($subject['teacher_name']); ?></p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-600 dark:text-gray-400">No subjects assigned.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Recent Grades -->
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Grades</h3>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($student_details['recent_grades'])): ?>
                            <div class="space-y-3">
                                <?php foreach ($student_details['recent_grades'] as $grade): ?>
                                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                    <div>
                                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($grade['subject_name']); ?></h4>
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($grade['exam_title']); ?></p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900 dark:text-white"><?php echo $grade['marks_obtained']; ?>/<?php echo $grade['total_marks']; ?></p>
                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo number_format($grade['percentage'], 1); ?>% (<?php echo $grade['grade_letter']; ?>)</p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-600 dark:text-gray-400">No recent grades available.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Emergency Contacts -->
                <?php if (!empty($student_details['emergency_contacts'])): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 mt-6">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Emergency Contacts</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <?php foreach ($student_details['emergency_contacts'] as $contact): ?>
                            <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                                <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($contact['name']); ?></h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($contact['relationship']); ?></p>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Phone: <?php echo htmlspecialchars($contact['phone']); ?></p>
                                <?php if ($contact['email']): ?>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Email: <?php echo htmlspecialchars($contact['email']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
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
