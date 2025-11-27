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

// Get filter parameters
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-01');
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');
$report_type = filter_input(INPUT_GET, 'report_type', FILTER_SANITIZE_STRING) ?: 'summary';

// Fetch classes based on user role
if ($user_role === 'teacher') {
    $classes_query = "SELECT DISTINCT c.id, c.name, c.grade_level 
                     FROM classes c 
                     JOIN class_teachers ct ON c.id = ct.class_id 
                     WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
                     ORDER BY c.grade_level, c.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':teacher_id', $user_id);
    $classes_stmt->execute();
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $classes_stmt = $db->query($classes_query);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate report data based on type
$report_data = [];
if ($selected_class_id && $report_type === 'summary') {
    // Summary report: attendance statistics per student
    $summary_query = "SELECT u.id, u.name, sp.student_id as roll_number,
                     COUNT(a.id) as total_days,
                     SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_days,
                     SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_days,
                     SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_days,
                     ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 1) as attendance_percentage
                     FROM users u
                     JOIN student_classes sc ON u.id = sc.student_id
                     LEFT JOIN student_profiles sp ON u.id = sp.user_id
                     LEFT JOIN attendance a ON u.id = a.student_id AND a.class_id = :class_id 
                         AND a.date BETWEEN :start_date AND :end_date
                     WHERE sc.class_id = :class_id AND sc.status = 'active' AND u.role = 'student'
                     GROUP BY u.id
                     ORDER BY u.name";
    $summary_stmt = $db->prepare($summary_query);
    $summary_stmt->bindParam(':class_id', $selected_class_id);
    $summary_stmt->bindParam(':start_date', $start_date);
    $summary_stmt->bindParam(':end_date', $end_date);
    $summary_stmt->execute();
    $report_data = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($selected_class_id && $report_type === 'daily') {
    // Daily report: attendance for each day
    $daily_query = "SELECT a.date,
                   COUNT(a.id) as total_marked,
                   SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) as present_count,
                   SUM(CASE WHEN a.status = 'absent' THEN 1 ELSE 0 END) as absent_count,
                   SUM(CASE WHEN a.status = 'late' THEN 1 ELSE 0 END) as late_count,
                   ROUND((SUM(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) / COUNT(a.id)) * 100, 1) as attendance_percentage
                   FROM attendance a
                   WHERE a.class_id = :class_id AND a.date BETWEEN :start_date AND :end_date
                   GROUP BY a.date
                   ORDER BY a.date DESC";
    $daily_stmt = $db->prepare($daily_query);
    $daily_stmt->bindParam(':class_id', $selected_class_id);
    $daily_stmt->bindParam(':start_date', $start_date);
    $daily_stmt->bindParam(':end_date', $end_date);
    $daily_stmt->execute();
    $report_data = $daily_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get selected class details
$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $class) {
        if ($class['id'] == $selected_class_id) {
            $selected_class = $class;
            break;
        }
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Attendance Reports</h1>
                <div class="flex space-x-3">
                    <?php if ($selected_class_id && !empty($report_data)): ?>
                    <button onclick="exportReport()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i>Export CSV
                    </button>
                    <button onclick="printReport()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-print mr-2"></i>Print
                    </button>
                    <?php endif; ?>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Attendance
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-1">Class</label>
                            <select id="class_id" name="class_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Class</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label for="report_type" class="block text-sm font-medium text-gray-700 mb-1">Report Type</label>
                            <select id="report_type" name="report_type"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="summary" <?php echo $report_type === 'summary' ? 'selected' : ''; ?>>Student Summary</option>
                                <option value="daily" <?php echo $report_type === 'daily' ? 'selected' : ''; ?>>Daily Summary</option>
                            </select>
                        </div>
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                Generate Report
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($selected_class_id && !empty($report_data)): ?>
            <!-- Report Content -->
            <div id="report-content" class="bg-white rounded-lg shadow overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">
                        <?php echo ucfirst($report_type); ?> Report - <?php echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']); ?>
                    </h2>
                    <p class="text-sm text-gray-600">
                        Period: <?php echo date('M j, Y', strtotime($start_date)); ?> to <?php echo date('M j, Y', strtotime($end_date)); ?>
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <?php if ($report_type === 'summary'): ?>
                    <!-- Student Summary Report -->
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student Name</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Roll Number</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Total Days</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Late</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data as $student): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                    <?php echo $student['total_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-center font-semibold">
                                    <?php echo $student['present_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 text-center font-semibold">
                                    <?php echo $student['absent_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 text-center font-semibold">
                                    <?php echo $student['late_days']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?php echo $student['attendance_percentage'] >= 90 ? 'bg-green-100 text-green-800' : 
                                            ($student['attendance_percentage'] >= 75 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                        <?php echo $student['attendance_percentage'] ?? 0; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <!-- Daily Summary Report -->
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Total Marked</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Present</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Absent</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Late</th>
                                <th class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Attendance %</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($report_data as $day): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                    <?php echo date('M j, Y (D)', strtotime($day['date'])); ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 text-center">
                                    <?php echo $day['total_marked']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 text-center font-semibold">
                                    <?php echo $day['present_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-red-600 text-center font-semibold">
                                    <?php echo $day['absent_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600 text-center font-semibold">
                                    <?php echo $day['late_count']; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full
                                        <?php echo $day['attendance_percentage'] >= 90 ? 'bg-green-100 text-green-800' : 
                                            ($day['attendance_percentage'] >= 75 ? 'bg-yellow-100 text-yellow-800' : 'bg-red-100 text-red-800'); ?>">
                                        <?php echo $day['attendance_percentage'] ?? 0; ?>%
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($selected_class_id && empty($report_data)): ?>
            <div class="text-center py-12">
                <i class="fas fa-chart-line text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No Data Found</h3>
                <p class="text-gray-500">No attendance data found for the selected criteria.</p>
            </div>
            <?php else: ?>
            <div class="text-center py-12">
                <i class="fas fa-chart-bar text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">Generate Report</h3>
                <p class="text-gray-500">Select a class and date range to generate attendance reports.</p>
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

<script>
function exportReport() {
    // Simple CSV export functionality
    const table = document.querySelector('#report-content table');
    if (!table) return;
    
    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    for (let i = 0; i < rows.length; i++) {
        const row = [];
        const cols = rows[i].querySelectorAll('td, th');
        
        for (let j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    
    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'attendance_report_' + new Date().toISOString().split('T')[0] + '.csv';
    a.click();
    window.URL.revokeObjectURL(url);
}

function printReport() {
    const printContent = document.getElementById('report-content').innerHTML;
    const originalContent = document.body.innerHTML;
    
    document.body.innerHTML = `
        <div style="padding: 20px;">
            <h1>Attendance Report</h1>
            ${printContent}
        </div>
    `;
    
    window.print();
    document.body.innerHTML = originalContent;
    location.reload();
}
</script>
