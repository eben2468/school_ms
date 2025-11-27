<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get report ID from URL
$report_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$report_id) {
    header("Location: index.php");
    exit();
}

// Get report details with student and academic information
$report_sql = "SELECT 
    tr.*,
    u.name as student_name,
    sp.student_id as profile_student_id,
    sp.date_of_birth, sp.gender, sp.guardian_name, sp.guardian_phone,
    c.name as class_name, c.grade_level,
    ay.year_name,
    at.term_name, at.start_date as term_start, at.end_date as term_end,
    generator.name as generated_by_name
FROM term_reports tr
JOIN users u ON tr.student_id = u.id
JOIN student_profiles sp ON u.id = sp.user_id
JOIN classes c ON tr.class_id = c.id
JOIN academic_years ay ON tr.academic_year_id = ay.id
JOIN academic_terms at ON tr.academic_term_id = at.id
LEFT JOIN users generator ON tr.generated_by = generator.id
WHERE tr.id = :report_id";

// Add access control for students
if ($user_role === 'student') {
    $report_sql .= " AND tr.student_id = :user_id";
}

$stmt = $db->prepare($report_sql);
$stmt->bindParam(':report_id', $report_id);
if ($user_role === 'student') {
    $stmt->bindParam(':user_id', $user_id);
}
$stmt->execute();
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header("Location: index.php?error=Report not found");
    exit();
}

// Get detailed academic records for this report
$records_sql = "SELECT 
    sar.*,
    s.name as subject_name, s.code as subject_code,
    teacher.name as teacher_name
FROM student_academic_records sar
JOIN subjects s ON sar.subject_id = s.id
LEFT JOIN users teacher ON sar.teacher_id = teacher.id
WHERE sar.student_id = :student_id 
AND sar.academic_year_id = :year_id 
AND sar.academic_term_id = :term_id
ORDER BY s.name";

$stmt = $db->prepare($records_sql);
$stmt->bindParam(':student_id', $report['student_id']);
$stmt->bindParam(':year_id', $report['academic_year_id']);
$stmt->bindParam(':term_id', $report['academic_term_id']);
$stmt->execute();
$academic_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get grade from score
function getGrade($score) {
    if ($score >= 80) return 'A';
    elseif ($score >= 70) return 'B';
    elseif ($score >= 60) return 'C';
    elseif ($score >= 50) return 'D';
    else return 'F';
}

$title = "Term Report - " . $report['student_name'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-purple-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Term Report Card</h1>
                                <p class="text-blue-100 text-lg"><?php echo htmlspecialchars($report['student_name']); ?></p>
                                <p class="text-blue-200 text-sm"><?php echo htmlspecialchars($report['year_name'] . ' - ' . $report['term_name']); ?></p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-certificate text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400">
                        <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Reports</a>
                        <i class="fas fa-chevron-right text-xs"></i>
                        <span class="text-gray-900 dark:text-white font-medium">View Report</span>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="window.print()" 
                            class="inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                            <i class="fas fa-print mr-2"></i>Print
                        </button>
                        <a href="pdf.php?id=<?php echo $report_id; ?>" 
                            class="inline-flex items-center px-3 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg transition-colors duration-200">
                            <i class="fas fa-download mr-2"></i>Download PDF
                        </a>
                    </div>
                </div>

                <!-- Report Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 print:shadow-none print:border-0">
                    <!-- School Header -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700 text-center">
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Greenwood Academy</h2>
                        <p class="text-gray-600 dark:text-gray-400">School Management System</p>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mt-4">TERMINAL REPORT</h3>
                    </div>

                    <!-- Student Information -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-3">
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Student Name:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($report['student_name']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Student ID:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($report['profile_student_id']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Class:</span>
                                    <span class="text-gray-900 dark:text-white">Grade <?php echo htmlspecialchars($report['grade_level'] . ' - ' . $report['class_name']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Gender:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars(ucfirst($report['gender'])); ?></span>
                                </div>
                            </div>
                            <div class="space-y-3">
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Academic Year:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($report['year_name']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Term:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($report['term_name']); ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Position:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo $report['position_in_class']; ?> of <?php echo $report['class_size']; ?></span>
                                </div>
                                <div class="flex">
                                    <span class="font-medium text-gray-700 dark:text-gray-300 w-32">Guardian:</span>
                                    <span class="text-gray-900 dark:text-white"><?php echo htmlspecialchars($report['guardian_name'] ?: 'N/A'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Academic Performance -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">Academic Performance</h4>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">CA Score</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Exam Score</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Total</th>
                                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Teacher</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($academic_records as $record): ?>
                                    <tr>
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['subject_name']); ?>
                                            <div class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($record['subject_code']); ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-center text-gray-900 dark:text-white">
                                            <?php echo number_format($record['continuous_assessment'], 1); ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-center text-gray-900 dark:text-white">
                                            <?php echo number_format($record['exam_score'], 1); ?>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-center font-medium text-gray-900 dark:text-white">
                                            <?php echo number_format($record['total_score'], 1); ?>
                                        </td>
                                        <td class="px-4 py-3 text-center">
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full 
                                                <?php 
                                                $grade = getGrade($record['total_score']);
                                                switch($grade) {
                                                    case 'A': echo 'bg-green-100 text-green-800'; break;
                                                    case 'B': echo 'bg-blue-100 text-blue-800'; break;
                                                    case 'C': echo 'bg-yellow-100 text-yellow-800'; break;
                                                    case 'D': echo 'bg-orange-100 text-orange-800'; break;
                                                    case 'F': echo 'bg-red-100 text-red-800'; break;
                                                }
                                                ?>">
                                                <?php echo $grade; ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['teacher_name'] ?: 'N/A'); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Summary and Statistics -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-6">Summary</h4>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div class="text-center p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                    <?php echo $report['total_subjects']; ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Total Subjects</div>
                            </div>
                            <div class="text-center p-4 bg-green-50 dark:bg-green-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                                    <?php echo number_format($report['average_score'], 1); ?>%
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Average Score</div>
                            </div>
                            <div class="text-center p-4 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                                    <?php echo $report['position_in_class']; ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Class Position</div>
                            </div>
                            <div class="text-center p-4 bg-orange-50 dark:bg-orange-900/20 rounded-lg">
                                <div class="text-2xl font-bold text-orange-600 dark:text-orange-400">
                                    <?php echo $report['conduct_grade']; ?>
                                </div>
                                <div class="text-sm text-gray-600 dark:text-gray-400">Conduct Grade</div>
                            </div>
                        </div>
                    </div>

                    <!-- Attendance -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Attendance</h4>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-gray-700 dark:text-gray-300">Total School Days:</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo $report['attendance_days']; ?></span>
                            </div>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-gray-700 dark:text-gray-300">Days Present:</span>
                                <span class="font-semibold text-gray-900 dark:text-white"><?php echo $report['attendance_present']; ?></span>
                            </div>
                            <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <span class="text-gray-700 dark:text-gray-300">Attendance Rate:</span>
                                <span class="font-semibold text-gray-900 dark:text-white">
                                    <?php
                                    $attendance_rate = $report['attendance_days'] > 0 ?
                                        ($report['attendance_present'] / $report['attendance_days']) * 100 : 0;
                                    echo number_format($attendance_rate, 1) . '%';
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Remarks -->
                    <div class="p-8 border-b border-gray-200 dark:border-gray-700">
                        <h4 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Remarks</h4>
                        <div class="space-y-4">
                            <div>
                                <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Class Teacher's Remarks:</h5>
                                <p class="text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                    <?php echo htmlspecialchars($report['teacher_remarks'] ?: 'No remarks provided.'); ?>
                                </p>
                            </div>
                            <?php if ($report['principal_remarks']): ?>
                            <div>
                                <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Principal's Remarks:</h5>
                                <p class="text-gray-900 dark:text-white bg-gray-50 dark:bg-gray-700 p-4 rounded-lg">
                                    <?php echo htmlspecialchars($report['principal_remarks']); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Footer Information -->
                    <div class="p-8">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Term Period:</h5>
                                <p class="text-gray-900 dark:text-white">
                                    <?php echo date('M j, Y', strtotime($report['term_start'])); ?> -
                                    <?php echo date('M j, Y', strtotime($report['term_end'])); ?>
                                </p>
                            </div>
                            <?php if ($report['next_term_begins']): ?>
                            <div>
                                <h5 class="font-medium text-gray-700 dark:text-gray-300 mb-2">Next Term Begins:</h5>
                                <p class="text-gray-900 dark:text-white">
                                    <?php echo date('M j, Y', strtotime($report['next_term_begins'])); ?>
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>

                        <div class="mt-6 pt-6 border-t border-gray-200 dark:border-gray-700 text-center text-sm text-gray-600 dark:text-gray-400">
                            <p>Report generated on <?php echo date('M j, Y \a\t g:i A', strtotime($report['report_generated_at'])); ?></p>
                            <?php if ($report['generated_by_name']): ?>
                            <p>Generated by: <?php echo htmlspecialchars($report['generated_by_name']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<style>
@media print {
    .print\:shadow-none { box-shadow: none !important; }
    .print\:border-0 { border: 0 !important; }
    body { background: white !important; }
    .bg-gray-50, .dark\:bg-gray-900 { background: white !important; }
    .text-white { color: black !important; }
    .bg-gradient-to-r { background: #4f46e5 !important; }
}
</style>
