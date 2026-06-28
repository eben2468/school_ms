<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
$database = new Database();
$db = $database->getConnection();

// Fetch school settings for print report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();
$school_motto = '';
try {
    $motto_stmt = $db->prepare("SELECT setting_value FROM academic_settings WHERE setting_key = 'school_motto'");
    $motto_stmt->execute();
    $motto_result = $motto_stmt->fetch(PDO::FETCH_ASSOC);
    if ($motto_result) $school_motto = $motto_result['setting_value'];
} catch (PDOException $e) {
    // Not available
}

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
$academic_stats = [
    'class_average' => 0,
    'total_records' => 0,
    'subject_averages' => [],
    'grade_counts' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
    'student_standings' => []
];

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
        
        // Get attendance statistics (last 365 days to capture June 2025 records)
        $attendance_query = "SELECT 
                            COUNT(*) as total_days,
                            COUNT(CASE WHEN status = 'present' THEN 1 END) as present_days,
                            COUNT(CASE WHEN status = 'absent' THEN 1 END) as absent_days,
                            COUNT(CASE WHEN status = 'late' THEN 1 END) as late_days
                            FROM attendance 
                            WHERE class_id = :class_id 
                            AND date >= DATE_SUB(CURDATE(), INTERVAL 365 DAY)";
        $attendance_stmt = $db->prepare($attendance_query);
        $attendance_stmt->bindParam(':class_id', $selected_class_id);
        $attendance_stmt->execute();
        $attendance_stats = $attendance_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Fetch academic records statistics from student_academic_records (source of truth)
        $academic_records_query = "
            SELECT sar.total_score, sar.grade, s.name as subject_name, u.name as student_name, sp.student_id as roll_number
            FROM student_academic_records sar
            JOIN subjects s ON sar.subject_id = s.id
            JOIN users u ON sar.student_id = u.id
            LEFT JOIN student_profiles sp ON u.id = sp.user_id
            WHERE sar.class_id = :class_id
        ";
        $academic_records_stmt = $db->prepare($academic_records_query);
        $academic_records_stmt->bindParam(':class_id', $selected_class_id);
        $academic_records_stmt->execute();
        $academic_records = $academic_records_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($academic_records) > 0) {
            $total_score = 0;
            $subject_scores = [];
            $student_scores = [];
            
            foreach ($academic_records as $rec) {
                $score = (float)$rec['total_score'];
                $total_score += $score;
                
                // Track subject averages
                $subj = $rec['subject_name'];
                if (!isset($subject_scores[$subj])) {
                    $subject_scores[$subj] = ['total' => 0, 'count' => 0];
                }
                $subject_scores[$subj]['total'] += $score;
                $subject_scores[$subj]['count']++;
                
                // Track student averages
                $stud = $rec['student_name'];
                if (!isset($student_scores[$stud])) {
                    $student_scores[$stud] = [
                        'name' => $stud,
                        'roll_number' => $rec['roll_number'],
                        'total' => 0,
                        'count' => 0
                    ];
                }
                $student_scores[$stud]['total'] += $score;
                $student_scores[$stud]['count']++;
                
                // Track grade counts
                $g = strtoupper(substr(trim($rec['grade'] ?? ''), 0, 1));
                if (isset($academic_stats['grade_counts'][$g])) {
                    $academic_stats['grade_counts'][$g]++;
                } else {
                    if ($score >= 80) $academic_stats['grade_counts']['A']++;
                    elseif ($score >= 70) $academic_stats['grade_counts']['B']++;
                    elseif ($score >= 60) $academic_stats['grade_counts']['C']++;
                    elseif ($score >= 50) $academic_stats['grade_counts']['D']++;
                    else $academic_stats['grade_counts']['F']++;
                }
            }
            
            $academic_stats['total_records'] = count($academic_records);
            $academic_stats['class_average'] = $total_score / count($academic_records);
            
            // Build subject averages
            foreach ($subject_scores as $subj => $data) {
                $academic_stats['subject_averages'][] = [
                    'subject' => $subj,
                    'average' => $data['total'] / $data['count']
                ];
            }
            // Sort subject averages descending
            usort($academic_stats['subject_averages'], function($a, $b) {
                return $b['average'] <=> $a['average'];
            });
            
            // Build student standings
            foreach ($student_scores as $stud => $data) {
                $academic_stats['student_standings'][] = [
                    'name' => $data['name'],
                    'roll_number' => $data['roll_number'],
                    'subjects_count' => $data['count'],
                    'average' => $data['total'] / $data['count']
                ];
            }
            // Sort student standings descending
            usort($academic_stats['student_standings'], function($a, $b) {
                return $b['average'] <=> $a['average'];
            });
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div id="web-layout" class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                <div>
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Class Reports</h1>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Generate diagnostic profiles, attendance rates, and grading standings for specific classes.</p>
                </div>
                <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                </a>
            </div>

            <!-- Class Selection -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                        <div class="flex-grow">
                            <label for="class_id" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Select Class</label>
                            <select id="class_id" name="class_id" required
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
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
                        
                        <?php if ($selected_class_id && $class_data): ?>
                        <div class="w-full md:w-64">
                            <label for="type" class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Report Sub-Type</label>
                            <select id="type" name="type"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500"
                                onchange="this.form.submit()">
                                <option value="overview" <?php echo $report_type === 'overview' ? 'selected' : ''; ?>>Class Overview</option>
                                <option value="attendance" <?php echo $report_type === 'attendance' ? 'selected' : ''; ?>>Attendance Rate</option>
                                <option value="academic" <?php echo $report_type === 'academic' ? 'selected' : ''; ?>>Academic Performance</option>
                            </select>
                            <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id && $class_data): ?>
            
            <!-- Class Header Card -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 mb-6 p-6">
                <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white">
                            Grade <?php echo htmlspecialchars($class_data['grade_level']); ?> - 
                            <?php echo htmlspecialchars($class_data['name']); ?>
                        </h2>
                        <div class="flex items-center gap-4 mt-2">
                            <span class="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-gray-700 px-2 py-1 rounded">
                                Academic Year ID: <?php echo htmlspecialchars($class_data['academic_year_id'] ?? 'N/A'); ?>
                            </span>
                            <span class="text-xs text-indigo-700 dark:text-indigo-350 bg-indigo-50 dark:bg-indigo-900/30 px-2 py-1 rounded font-semibold">
                                <?php echo htmlspecialchars($class_data['status'] ?? 'Active'); ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-left md:text-right">
                        <div class="text-3xl font-extrabold text-indigo-600 dark:text-indigo-400"><?php echo count($students); ?></div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-1 font-semibold uppercase tracking-wider">Total Enrolled Students</div>
                    </div>
                </div>
            </div>

            <?php if ($report_type === 'overview'): ?>
            <!-- Overview Statistics Dashboard -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-6">
                <!-- Students Widget -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Class Size</p>
                        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo count($students); ?> Students</h3>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-blue-600 dark:text-blue-450 text-xl"></i>
                    </div>
                </div>

                <!-- Attendance Average Widget -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">365-Day Attendance Rate</p>
                        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php 
                            if (($attendance_stats['total_days'] ?? 0) > 0) {
                                echo round(($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100, 1) . '%';
                            } else {
                                echo 'N/A';
                            }
                            ?>
                        </h3>
                    </div>
                    <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calendar-check text-green-600 dark:text-green-450 text-xl"></i>
                    </div>
                </div>

                <!-- Academic Average Widget -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-semibold text-gray-500 dark:text-gray-400">Class Score Average</p>
                        <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1">
                            <?php echo $academic_stats['total_records'] > 0 ? number_format($academic_stats['class_average'], 1) . '%' : 'N/A'; ?>
                        </h3>
                    </div>
                    <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center">
                        <i class="fas fa-graduation-cap text-indigo-650 dark:text-indigo-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Students List -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active Student Roster</h3>
                    <span class="bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 text-xs px-2.5 py-1 rounded font-bold">
                        Count: <?php echo count($students); ?>
                    </span>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                        <thead class="bg-gray-50 dark:bg-gray-750">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student ID</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Email</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Admission Date</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Profile</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php foreach ($students as $student): ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-9 h-9 bg-indigo-100 dark:bg-indigo-900/40 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-indigo-650 dark:text-indigo-400"></i>
                                        </div>
                                        <div class="ml-3">
                                            <div class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['name']); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-medium">
                                    <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo $student['admission_date'] ? date('M j, Y', strtotime($student['admission_date'])) : 'N/A'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-bold">
                                    <a href="../students/profile.php?id=<?php echo $student['id']; ?>" 
                                       class="text-indigo-600 dark:text-indigo-400 hover:text-indigo-850 dark:hover:text-indigo-300 transition">View Profile</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($report_type === 'attendance'): ?>
            <!-- Attendance Rate Report with Analytics -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
                <!-- Left: Attendance Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Attendance Distribution</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Distribution of present, absent, and late tallies</p>
                    </div>
                    <?php if (($attendance_stats['total_days'] ?? 0) > 0): ?>
                    <div class="my-6 relative flex items-center justify-center" style="height: 200px;">
                        <canvas id="attendanceDoughnutChart"></canvas>
                    </div>
                    <div class="border-t border-gray-100 dark:border-gray-700 pt-4 flex justify-around text-xs text-gray-500 dark:text-gray-400">
                        <div class="text-center"><span class="font-bold text-green-600">Present:</span> <?php echo $attendance_stats['present_days']; ?></div>
                        <div class="text-center"><span class="font-bold text-red-650">Absent:</span> <?php echo $attendance_stats['absent_days']; ?></div>
                        <div class="text-center"><span class="font-bold text-yellow-600">Late:</span> <?php echo $attendance_stats['late_days']; ?></div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-gray-400 text-3xl mb-2"></i>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No chart data available</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right: Stats cards and breakdown table -->
                <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Class Attendance Profile (Last 365 Days)</h3>
                    
                    <?php if (($attendance_stats['total_days'] ?? 0) > 0): ?>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
                        <div class="text-center bg-gray-50 dark:bg-gray-750 p-4 rounded-xl">
                            <div class="text-3xl font-extrabold text-green-600">
                                <?php echo round(($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Overall Present</div>
                            <div class="text-xs text-gray-400 dark:text-gray-550 mt-0.5"><?php echo $attendance_stats['present_days']; ?> marked days</div>
                        </div>
                        <div class="text-center bg-gray-50 dark:bg-gray-750 p-4 rounded-xl">
                            <div class="text-3xl font-extrabold text-red-650">
                                <?php echo round(($attendance_stats['absent_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Overall Absent</div>
                            <div class="text-xs text-gray-400 dark:text-gray-550 mt-0.5"><?php echo $attendance_stats['absent_days']; ?> marked days</div>
                        </div>
                        <div class="text-center bg-gray-50 dark:bg-gray-750 p-4 rounded-xl">
                            <div class="text-3xl font-extrabold text-yellow-600">
                                <?php echo round(($attendance_stats['late_days'] / $attendance_stats['total_days']) * 100, 1); ?>%
                            </div>
                            <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 mt-1 uppercase tracking-wider">Overall Late</div>
                            <div class="text-xs text-gray-400 dark:text-gray-550 mt-0.5"><?php echo $attendance_stats['late_days']; ?> marked days</div>
                        </div>
                    </div>
                    
                    <div class="border-t border-gray-150 dark:border-gray-700 pt-6">
                        <h4 class="text-sm font-bold text-gray-800 dark:text-white mb-2">Diagnostic Notes</h4>
                        <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                            The student attendance rate for <strong>Grade <?php echo htmlspecialchars($class_data['grade_level'] . ' - ' . $class_data['name']); ?></strong> is currently at <strong><?php echo round(($attendance_stats['present_days'] / $attendance_stats['total_days']) * 100, 1); ?>%</strong>. Attendance metrics are compiled across all active students over a trailing 365-day period to capture comprehensive histories.
                        </p>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <i class="fas fa-calendar-times text-gray-400 text-5xl mb-4"></i>
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">No Attendance Registered</h3>
                        <p class="text-gray-500 dark:text-gray-400 max-w-sm mx-auto">There are no attendance registers recorded for this class within the past 365 days.</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php elseif ($report_type === 'academic'): ?>
            <!-- Academic Performance Report with Visual Charts -->
            <?php if ($academic_stats['total_records'] > 0): ?>
            
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
                <!-- Subject Performance Bar Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Subject Performance Averages</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Class score average in each registered subject</p>
                    </div>
                    <div class="my-6 relative flex items-center justify-center" style="height: 240px;">
                        <canvas id="subjectAveragesChart"></canvas>
                    </div>
                </div>

                <!-- Grade Distribution Doughnut Chart -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Class Grade Distribution</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Breakdown of compiled academic records</p>
                    </div>
                    <div class="my-6 relative flex items-center justify-center" style="height: 240px;">
                        <canvas id="classGradeDistributionChart"></canvas>
                    </div>
                </div>
            </div>

            <!-- Student Rankings Standings -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                    <div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">Student Standing Leaderboard</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">Students ranked by overall subject percentage averages</p>
                    </div>
                    <button onclick="printRanks()" class="bg-indigo-600 hover:bg-indigo-700 text-white text-xs px-3 py-2 rounded-lg font-semibold transition shadow-sm flex items-center">
                        <i class="fas fa-print mr-1.5"></i>Print Ranks
                    </button>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                        <thead class="bg-gray-50 dark:bg-gray-750">
                            <tr>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rank</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student Name</th>
                                <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Roll/ID Number</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subjects Graded</th>
                                <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider font-bold">Average Percentage</th>
                                <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rating</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                            <?php 
                            $standing_rank = 1;
                            foreach ($academic_stats['student_standings'] as $row): 
                                $avg = (float)$row['average'];
                            ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-extrabold text-gray-900 dark:text-white">
                                    #<?php echo $standing_rank; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo htmlspecialchars($row['roll_number'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-650 dark:text-gray-350 text-center font-medium">
                                    <?php echo $row['subjects_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-extrabold text-gray-950 dark:text-white">
                                    <?php echo number_format($avg, 1); ?>%
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    <?php if ($avg >= 80): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-bold rounded bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">Excellent</span>
                                    <?php elseif ($avg >= 65): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-bold rounded bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300 font-semibold">Good</span>
                                    <?php elseif ($avg >= 50): ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-bold rounded bg-orange-100 text-orange-850 dark:bg-orange-900/40 dark:text-orange-355 font-semibold">Average</span>
                                    <?php else: ?>
                                        <span class="inline-flex px-2 py-0.5 text-xs font-bold rounded bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300 font-semibold">Needs Help</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php 
                            $standing_rank++;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <?php else: ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-graduation-cap text-gray-400 text-3xl"></i>
                </div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Academic Grades Recorded</h3>
                <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">There are no compiled grades registered in student academic records for this class yet.</p>
            </div>
            <?php endif; ?>
            
            <?php endif; ?>

            <?php elseif ($selected_class_id): ?>
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 text-center">
                <div class="text-center">
                    <i class="fas fa-exclamation-triangle text-yellow-500 text-4xl mb-4"></i>
                    <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Class Not Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">The selected class could not be found or you do not have appropriate permissions to access it.</p>
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

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php if ($selected_class_id && $class_data): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const isDarkMode = document.documentElement.classList.contains('dark');
    const labelColor = isDarkMode ? '#9ca3af' : '#4b5563';
    const gridColor = isDarkMode ? '#374151' : '#f3f4f6';

    <?php if ($report_type === 'attendance' && ($attendance_stats['total_days'] ?? 0) > 0): ?>
    // Attendance doughnut chart
    const attCtx = document.getElementById('attendanceDoughnutChart').getContext('2d');
    new Chart(attCtx, {
        type: 'doughnut',
        data: {
            labels: ['Present days', 'Absent days', 'Late days'],
            datasets: [{
                data: [
                    <?php echo $attendance_stats['present_days']; ?>,
                    <?php echo $attendance_stats['absent_days']; ?>,
                    <?php echo $attendance_stats['late_days']; ?>
                ],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.85)',
                    'rgba(239, 68, 68, 0.85)',
                    'rgba(234, 179, 8, 0.85)'
                ],
                borderColor: isDarkMode ? '#1f2937' : '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: labelColor
                    }
                }
            }
        }
    });

    <?php elseif ($report_type === 'academic' && $academic_stats['total_records'] > 0): ?>
    // Subject averages chart
    <?php
    $subjects_labels = [];
    $subjects_pcts = [];
    foreach ($academic_stats['subject_averages'] as $sa) {
        $subjects_labels[] = $sa['subject'];
        $subjects_pcts[] = (float)$sa['average'];
    }
    ?>

    const subCtx = document.getElementById('subjectAveragesChart').getContext('2d');
    new Chart(subCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($subjects_labels); ?>,
            datasets: [{
                label: 'Class Average %',
                data: <?php echo json_encode($subjects_pcts); ?>,
                backgroundColor: 'rgba(99, 102, 241, 0.85)',
                borderColor: 'rgb(99, 102, 241)',
                borderWidth: 1,
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    max: 100,
                    beginAtZero: true,
                    ticks: {
                        color: labelColor
                    },
                    grid: {
                        color: gridColor
                    }
                },
                x: {
                    ticks: {
                        color: labelColor,
                        font: {
                            size: 10
                        }
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Grade distribution doughnut chart
    const gradeCtx = document.getElementById('classGradeDistributionChart').getContext('2d');
    new Chart(gradeCtx, {
        type: 'doughnut',
        data: {
            labels: ['A', 'B', 'C', 'D', 'F'],
            datasets: [{
                data: [
                    <?php echo $academic_stats['grade_counts']['A']; ?>,
                    <?php echo $academic_stats['grade_counts']['B']; ?>,
                    <?php echo $academic_stats['grade_counts']['C']; ?>,
                    <?php echo $academic_stats['grade_counts']['D']; ?>,
                    <?php echo $academic_stats['grade_counts']['F']; ?>
                ],
                backgroundColor: [
                    'rgba(34, 197, 94, 0.85)',
                    'rgba(59, 130, 246, 0.85)',
                    'rgba(20, 184, 166, 0.85)',
                    'rgba(249, 115, 22, 0.85)',
                    'rgba(239, 68, 68, 0.85)'
                ],
                borderColor: isDarkMode ? '#1f2937' : '#ffffff',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        color: labelColor,
                        padding: 15
                    }
                }
            }
        }
    });
    <?php endif; ?>
});

function printRanks() {
    document.body.classList.add('printing-ranks');
    setTimeout(function() {
        window.print();
        document.body.classList.remove('printing-ranks');
    }, 100);
}
</script>

<?php if ($report_type === 'academic' && $academic_stats['total_records'] > 0): ?>
<!-- ============================================================ -->
<!-- PRINT RANKS TEMPLATE (Hidden on screen, shown during print) -->
<!-- ============================================================ -->
<?php
$excellent_count = 0;
$good_count = 0;
$average_count = 0;
$needs_help_count = 0;
foreach ($academic_stats['student_standings'] as $row) {
    $avg = (float)$row['average'];
    if ($avg >= 80) {
        $excellent_count++;
    } elseif ($avg >= 65) {
        $good_count++;
    } elseif ($avg >= 50) {
        $average_count++;
    } else {
        $needs_help_count++;
    }
}
$total_ranked = count($academic_stats['student_standings']);
$highest_average = !empty($academic_stats['student_standings']) ? $academic_stats['student_standings'][0]['average'] : 0;
$lowest_average = !empty($academic_stats['student_standings']) ? end($academic_stats['student_standings'])['average'] : 0;
?>
<div id="print-ranks" class="print-ranks-container">
    <div class="print-page">
        <!-- School Letterhead -->
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="print-logo-fallback" style="display:none">
                        <?php echo strtoupper(substr($school_name, 0, 1)); ?>
                    </div>
                </div>
                <div class="print-school-info">
                    <h1 class="print-school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                    <?php if ($school_motto): ?>
                    <p class="print-motto">"<?php echo htmlspecialchars($school_motto); ?>"</p>
                    <?php endif; ?>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <!-- Report Title Banner -->
        <div class="print-title-banner">
            <h2>Class Standings & Ranks</h2>
        </div>

        <!-- Report Meta Information -->
        <div class="print-meta-grid">
            <div class="print-meta-item">
                <span class="print-meta-label">Class Name:</span>
                <span class="print-meta-value"><?php echo htmlspecialchars($class_data['name']); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Grade Level:</span>
                <span class="print-meta-value">Grade <?php echo htmlspecialchars($class_data['grade_level']); ?></span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Enrolled Students:</span>
                <span class="print-meta-value"><?php echo count($students); ?> Students</span>
            </div>
            <div class="print-meta-item">
                <span class="print-meta-label">Date Generated:</span>
                <span class="print-meta-value"><?php echo date('F j, Y'); ?></span>
            </div>
        </div>

        <!-- Summary Statistics -->
        <div class="print-section-title">Performance Summary</div>
        <div class="print-summary-grid">
            <div class="print-summary-card print-summary-blue">
                <div class="print-summary-value"><?php echo $total_ranked; ?></div>
                <div class="print-summary-label">Total Ranked</div>
            </div>
            <div class="print-summary-card print-summary-green">
                <div class="print-summary-value"><?php echo number_format($academic_stats['class_average'], 1); ?>%</div>
                <div class="print-summary-label">Class Average</div>
            </div>
            <div class="print-summary-card print-summary-purple">
                <div class="print-summary-value"><?php echo number_format($highest_average, 1); ?>%</div>
                <div class="print-summary-label">Highest Avg Score</div>
            </div>
            <div class="print-summary-card print-summary-red">
                <div class="print-summary-value"><?php echo number_format($lowest_average, 1); ?>%</div>
                <div class="print-summary-label">Lowest Avg Score</div>
            </div>
        </div>

        <!-- Performance Distribution -->
        <div class="print-section-title">Performance Distribution</div>
        <div class="print-distribution-bar">
            <?php if ($total_ranked > 0): ?>
            <?php $pct_excellent = ($excellent_count / $total_ranked) * 100; ?>
            <?php $pct_good = ($good_count / $total_ranked) * 100; ?>
            <?php $pct_average = ($average_count / $total_ranked) * 100; ?>
            <?php $pct_needs = ($needs_help_count / $total_ranked) * 100; ?>
            <?php if ($pct_excellent > 0): ?><div class="dist-segment dist-excellent" style="width:<?php echo $pct_excellent; ?>%"><span><?php echo $excellent_count; ?></span></div><?php endif; ?>
            <?php if ($pct_good > 0): ?><div class="dist-segment dist-good" style="width:<?php echo $pct_good; ?>%"><span><?php echo $good_count; ?></span></div><?php endif; ?>
            <?php if ($pct_average > 0): ?><div class="dist-segment dist-average" style="width:<?php echo $pct_average; ?>%"><span><?php echo $average_count; ?></span></div><?php endif; ?>
            <?php if ($pct_needs > 0): ?><div class="dist-segment dist-needs" style="width:<?php echo $pct_needs; ?>%"><span><?php echo $needs_help_count; ?></span></div><?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="print-dist-legend">
            <span class="legend-item"><span class="legend-dot" style="background:#059669"></span>Excellent (80%+)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#2563eb"></span>Good (65–79%)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#d97706"></span>Average (50–64%)</span>
            <span class="legend-item"><span class="legend-dot" style="background:#dc2626"></span>Needs Support (&lt;50%)</span>
        </div>

        <!-- Standing Leaderboard Table -->
        <div class="print-section-title">Student Rankings Standings</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="width:60px">Rank</th>
                    <th style="text-align:left">Student Name</th>
                    <th>Student ID</th>
                    <th>Subjects Graded</th>
                    <th>Average Score</th>
                    <th>Rating</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $print_rank = 1;
                foreach ($academic_stats['student_standings'] as $row):
                    $avg = (float)$row['average'];
                    $rating = '';
                    $rating_class = '';
                    if ($avg >= 80) {
                        $rating = 'Excellent';
                        $rating_class = 'rating-excellent';
                    } elseif ($avg >= 65) {
                        $rating = 'Good';
                        $rating_class = 'rating-very-good';
                    } elseif ($avg >= 50) {
                        $rating = 'Average';
                        $rating_class = 'rating-average';
                    } else {
                        $rating = 'Needs Help';
                        $rating_class = 'rating-needs-help';
                    }
                ?>
                <tr<?php echo $print_rank <= 3 ? ' class="top-three"' : ''; ?>>
                    <td class="rank-cell">
                        <?php if ($print_rank <= 3): ?>
                        <span class="rank-badge rank-<?php echo $print_rank; ?>"><?php echo $print_rank; ?></span>
                        <?php else: ?>
                        <?php echo $print_rank; ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($row['name']); ?></td>
                    <td><?php echo htmlspecialchars($row['roll_number'] ?? 'N/A'); ?></td>
                    <td><?php echo $row['subjects_count']; ?></td>
                    <td class="pct-cell"><?php echo number_format($avg, 1); ?>%</td>
                    <td class="rating-cell <?php echo $rating_class; ?>"><?php echo $rating; ?></td>
                </tr>
                <?php $print_rank++; endforeach; ?>
            </tbody>
        </table>

        <!-- Signatures Section -->
        <?php echo signatureRow(['Class Teacher', 'Head of Department', 'Headmaster/Headmistress']); ?>

        <!-- Footer -->
        <div class="print-footer">
            <p>This is a computer-generated document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
</div>

<!-- Print Report Styles -->
<style>
    /* ===== PRINT RANKS ON-SCREEN: HIDDEN ===== */
    .print-ranks-container {
        display: none;
    }

    /* ===== PRINT MEDIA STYLES ===== */
    @media print {
        /* Hide screen-only elements */
        header,
        #sidebar,
        #web-layout,
        .search-overlay,
        footer,
        .sidebar-spacer {
            display: none !important;
        }
        
        /* Ensure the print container is visible */
        .print-ranks-container {
            display: block !important;
        }
        
        /* Reset body and main element layout for printing */
        body, main {
            display: block !important;
            margin: 0 !important;
            padding: 0 !important;
            background: white !important;
            min-height: auto !important;
            height: auto !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            color-adjust: exact !important;
        }
        
        @page {
            size: A4 portrait;
            margin: 8mm 10mm;
        }
    }

    /* ===== Print Page Layout ===== */
    .print-page {
        font-family: 'Inter', 'Segoe UI', -apple-system, sans-serif;
        font-size: 10.5px;
        line-height: 1.45;
        color: #1a1a2e;
        max-width: 210mm;
        margin: 0 auto;
    }

    /* ===== School Header / Letterhead ===== */
    .print-header {
        margin-bottom: 4px;
    }
    .print-header-inner {
        display: flex;
        align-items: center;
        gap: 16px;
        padding-bottom: 10px;
    }
    .print-logo {
        flex-shrink: 0;
    }
    .print-logo img {
        width: 62px;
        height: 62px;
        object-fit: contain;
    }
    .print-logo-fallback {
        width: 62px;
        height: 62px;
        background: linear-gradient(135deg, #1e3a5f, #2563eb);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 28px;
        font-weight: 800;
    }
    .print-school-info {
        flex: 1;
    }
    .print-school-name {
        font-size: 22px;
        font-weight: 800;
        color: #1e3a5f;
        letter-spacing: 1.2px;
        text-transform: uppercase;
        margin: 0 0 2px 0;
        line-height: 1.2;
    }
    .print-motto {
        font-size: 10.5px;
        font-style: italic;
        color: #2563eb;
        font-weight: 500;
        margin: 0 0 3px 0;
    }
    .print-contact-line {
        font-size: 9px;
        color: #6b7280;
        margin: 0;
    }
    .print-header-divider {
        height: 3px;
        background: linear-gradient(to right, #1e3a5f, #2563eb, #7c3aed);
        border-radius: 3px;
        margin-bottom: 12px;
    }

    /* ===== Title Banner ===== */
    .print-title-banner {
        text-align: center;
        background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
        color: white;
        padding: 7px 20px;
        border-radius: 5px;
        margin-bottom: 12px;
    }
    .print-title-banner h2 {
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 2.5px;
        text-transform: uppercase;
        margin: 0;
    }

    /* ===== Report Meta Grid ===== */
    .print-meta-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 0;
        border: 1px solid #d1d5db;
        border-radius: 5px;
        overflow: hidden;
        margin-bottom: 14px;
    }
    .print-meta-item {
        padding: 6px 12px;
        border-right: 1px solid #e5e7eb;
        background: #f8fafc;
    }
    .print-meta-item:last-child {
        border-right: none;
    }
    .print-meta-label {
        font-size: 8.5px;
        font-weight: 600;
        color: #6b7280;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        display: block;
    }
    .print-meta-value {
        font-size: 11px;
        font-weight: 700;
        color: #1e3a5f;
        display: block;
    }

    /* ===== Section Titles ===== */
    .print-section-title {
        font-size: 11px;
        font-weight: 700;
        color: #1e3a5f;
        text-transform: uppercase;
        letter-spacing: 0.6px;
        padding: 5px 10px;
        background: #eef2f7;
        border-left: 4px solid #1e3a5f;
        border-radius: 0 4px 4px 0;
        margin-bottom: 8px;
    }

    /* ===== Summary Cards ===== */
    .print-summary-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 10px;
        margin-bottom: 14px;
    }
    .print-summary-card {
        text-align: center;
        padding: 10px 8px;
        border-radius: 6px;
        border: 1px solid #e5e7eb;
    }
    .print-summary-value {
        font-size: 20px;
        font-weight: 800;
        line-height: 1.2;
    }
    .print-summary-label {
        font-size: 8.5px;
        text-transform: uppercase;
        letter-spacing: 0.3px;
        margin-top: 2px;
        font-weight: 600;
    }
    .print-summary-blue {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    .print-summary-blue .print-summary-value { color: #1d4ed8; }
    .print-summary-blue .print-summary-label { color: #3b82f6; }
    .print-summary-green {
        background: #f0fdf4;
        border-color: #bbf7d0;
    }
    .print-summary-green .print-summary-value { color: #15803d; }
    .print-summary-green .print-summary-label { color: #22c55e; }
    .print-summary-purple {
        background: #faf5ff;
        border-color: #e9d5ff;
    }
    .print-summary-purple .print-summary-value { color: #7e22ce; }
    .print-summary-purple .print-summary-label { color: #a855f7; }
    .print-summary-red {
        background: #fef2f2;
        border-color: #fecaca;
    }
    .print-summary-red .print-summary-value { color: #b91c1c; }
    .print-summary-red .print-summary-label { color: #ef4444; }

    /* ===== Distribution Bar ===== */
    .print-distribution-bar {
        display: flex;
        height: 22px;
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 6px;
        border: 1px solid #d1d5db;
    }
    .dist-segment {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
    }
    .dist-segment span {
        font-size: 9px;
        font-weight: 700;
        color: white;
    }
    .dist-excellent { background: #059669; }
    .dist-good { background: #2563eb; }
    .dist-average { background: #d97706; }
    .dist-needs { background: #dc2626; }
    .print-dist-legend {
        display: flex;
        gap: 16px;
        margin-bottom: 14px;
        flex-wrap: wrap;
    }
    .legend-item {
        font-size: 8.5px;
        color: #4b5563;
        display: flex;
        align-items: center;
        gap: 4px;
    }
    .legend-dot {
        width: 8px;
        height: 8px;
        border-radius: 2px;
        display: inline-block;
    }

    /* ===== Performance Table ===== */
    .print-table {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 14px;
        font-size: 10px;
    }
    .print-table thead th {
        background: linear-gradient(135deg, #1e3a5f, #2d5a8e);
        color: white;
        padding: 7px 8px;
        text-align: center;
        font-weight: 600;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border: 1px solid #1a3455;
    }
    .print-table tbody td {
        padding: 5px 8px;
        text-align: center;
        border: 1px solid #e5e7eb;
        font-size: 10px;
    }
    .print-table tbody tr:nth-child(even) {
        background: #f9fafb;
    }
    .print-table tbody tr.top-three {
        background: #fffbeb;
    }
    .print-table tbody tr.top-three:nth-child(1) {
        background: #fefce8;
    }
    .rank-cell {
        font-weight: 700;
    }
    .rank-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 22px;
        height: 22px;
        border-radius: 50%;
        font-size: 10px;
        font-weight: 800;
        color: white;
    }
    .rank-1 { background: linear-gradient(135deg, #f59e0b, #d97706); }
    .rank-2 { background: linear-gradient(135deg, #9ca3af, #6b7280); }
    .rank-3 { background: linear-gradient(135deg, #b45309, #92400e); }
    .pct-cell {
        font-weight: 700;
        color: #1e3a5f;
    }
    .rating-cell {
        font-weight: 600;
        font-size: 9px;
    }
    .rating-excellent { color: #059669; }
    .rating-very-good { color: #2563eb; }
    .rating-average { color: #d97706; }
    .rating-needs-help { color: #dc2626; }

    /* ===== Signatures ===== */
    .print-signatures {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 30px;
        margin-top: 24px;
        margin-bottom: 16px;
    }
    .print-signature-block {
        text-align: center;
    }
    .print-signature-block .signature-line {
        border-top: 1.5px solid #374151;
        margin-top: 36px;
        padding-top: 4px;
    }
    .signature-title {
        font-size: 10px;
        font-weight: 700;
        color: #1e3a5f;
    }
    .signature-date {
        font-size: 8.5px;
        color: #6b7280;
        margin-top: 2px;
    }

    /* ===== Footer ===== */
    .print-footer {
        text-align: center;
        padding-top: 10px;
        border-top: 1px solid #e5e7eb;
        margin-top: 10px;
    }
    .print-footer p {
        font-size: 8px;
        color: #9ca3af;
        margin: 0;
        font-style: italic;
    }
</style>
<?php endif; ?>

<?php endif; ?>
