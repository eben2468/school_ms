<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Grade Reports";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Grade Reports']
];

// Get classes for filter
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subjects for filter
$subjects_query = "SELECT id, name FROM subjects ORDER BY name";
$subjects_stmt = $db->query($subjects_query);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

$class_filter = isset($_GET['class_id']) ? $_GET['class_id'] : '';
$term_filter = isset($_GET['term']) ? $_GET['term'] : '';

// Build Query to fetch from term_reports just like academic/reports/index.php
$query = "SELECT 
    tr.id,
    tr.average_score as total_score,
    tr.overall_grade as grade,
    tr.position_in_class,
    tr.class_size,
    u.name as student_name,
    c.name as class_name, c.grade_level,
    at.term_name,
    at.term_number
FROM term_reports tr
JOIN users u ON tr.student_id = u.id
JOIN classes c ON tr.class_id = c.id
JOIN academic_terms at ON tr.academic_term_id = at.id
WHERE u.role = 'student'";

$params = [];
if ($class_filter) {
    $query .= " AND tr.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}
if ($term_filter) {
    $query .= " AND at.term_number = :term_number";
    $params[':term_number'] = $term_filter;
}

$query .= " ORDER BY tr.report_generated_at DESC, tr.average_score DESC";

// Limit to 200 rows by default if no filters are selected for performance
if (!$class_filter && !$term_filter) {
    $query .= " LIMIT 200";
}

$stmt = $db->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Stats
$stats = [
    'total_students' => 0,
    'average_score' => 0,
    'highest_score' => 0,
    'lowest_score' => 0
];
$grade_counts = [
    'A' => 0,
    'B' => 0,
    'C' => 0,
    'D' => 0,
    'F' => 0
];

if (count($records) > 0) {
    $total_score = 0;
    $scores = [];
    $unique_students = [];
    foreach ($records as $r) {
        $score = (float)$r['total_score'];
        $total_score += $score;
        $scores[] = $score;
        $unique_students[$r['student_name']] = true;
        
        // Count grades for chart
        $g = strtoupper(substr(trim($r['grade'] ?? ''), 0, 1));
        if (isset($grade_counts[$g])) {
            $grade_counts[$g]++;
        } else {
            // Fallback grade categorization based on score
            if ($score >= 80) $grade_counts['A']++;
            elseif ($score >= 70) $grade_counts['B']++;
            elseif ($score >= 60) $grade_counts['C']++;
            elseif ($score >= 50) $grade_counts['D']++;
            else $grade_counts['F']++;
        }
    }
    $stats['total_students'] = count($unique_students);
    $stats['average_score'] = $total_score / count($records);
    $stats['highest_score'] = max($scores);
    $stats['lowest_score'] = min($scores);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Spacer -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Title -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Grade Reports</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Analyze academic performance metrics across classes, subjects, and terms.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                    </div>
                </div>

                <!-- Filters Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 mb-6 transition-all">
                    <div class="p-6">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                                <select name="class_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                                <select name="term" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-650 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-indigo-500 focus:outline-none transition">
                                    <option value="">All Terms</option>
                                    <option value="1" <?php echo $term_filter === '1' ? 'selected' : ''; ?>>Term 1</option>
                                    <option value="2" <?php echo $term_filter === '2' ? 'selected' : ''; ?>>Term 2</option>
                                    <option value="3" <?php echo $term_filter === '3' ? 'selected' : ''; ?>>Term 3</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                    <i class="fas fa-filter mr-2"></i>Generate Report
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (count($records) > 0): ?>
                <!-- KPI Statistics Widgets -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Students Graded -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between relative overflow-hidden group">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-blue-150 dark:bg-blue-900/20 rounded-full transition-all group-hover:scale-110 duration-300"></div>
                        <div class="relative z-10">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Graded Students</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo $stats['total_students']; ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center relative z-10">
                            <i class="fas fa-user-graduate text-blue-600 dark:text-blue-450 text-xl"></i>
                        </div>
                    </div>
                    <!-- Overall Class Average -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between relative overflow-hidden group">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-green-150 dark:bg-green-900/20 rounded-full transition-all group-hover:scale-110 duration-300"></div>
                        <div class="relative z-10">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Class Average</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($stats['average_score'], 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center relative z-10">
                            <i class="fas fa-chart-bar text-green-600 dark:text-green-450 text-xl"></i>
                        </div>
                    </div>
                    <!-- Highest Score -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between relative overflow-hidden group">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-purple-150 dark:bg-purple-900/20 rounded-full transition-all group-hover:scale-110 duration-300"></div>
                        <div class="relative z-10">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Highest Score</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($stats['highest_score'], 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center relative z-10">
                            <i class="fas fa-award text-purple-600 dark:text-purple-450 text-xl"></i>
                        </div>
                    </div>
                    <!-- Lowest Score -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between relative overflow-hidden group">
                        <div class="absolute -right-6 -top-6 w-24 h-24 bg-red-150 dark:bg-red-900/20 rounded-full transition-all group-hover:scale-110 duration-300"></div>
                        <div class="relative z-10">
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Lowest Score</p>
                            <h3 class="text-3xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($stats['lowest_score'], 1); ?>%</h3>
                        </div>
                        <div class="w-12 h-12 bg-red-100 dark:bg-red-900/40 rounded-lg flex items-center justify-center relative z-10">
                            <i class="fas fa-arrow-down text-red-650 dark:text-red-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Visuals and Table Layout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Left: Grade Distribution Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex flex-col justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Grade Distribution</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Visualization of letter grade frequency</p>
                        </div>
                        <div class="my-6 relative flex items-center justify-center" style="height: 240px;">
                            <canvas id="gradeDistributionChart"></canvas>
                        </div>
                        <div class="border-t border-gray-100 dark:border-gray-700 pt-4 flex justify-around text-xs text-gray-500 dark:text-gray-400">
                            <div class="text-center"><span class="font-bold text-indigo-650">A:</span> <?php echo $grade_counts['A']; ?></div>
                            <div class="text-center"><span class="font-bold text-blue-500">B:</span> <?php echo $grade_counts['B']; ?></div>
                            <div class="text-center"><span class="font-bold text-teal-500">C:</span> <?php echo $grade_counts['C']; ?></div>
                            <div class="text-center"><span class="font-bold text-orange-500">D:</span> <?php echo $grade_counts['D']; ?></div>
                            <div class="text-center"><span class="font-bold text-red-500">F:</span> <?php echo $grade_counts['F']; ?></div>
                        </div>
                    </div>

                    <!-- Right: Table -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden flex flex-col justify-between">
                        <div>
                            <div class="p-6 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
                                <div>
                                    <h3 class="text-lg font-bold text-gray-900 dark:text-white">Grade Details Table</h3>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Showing graded academic achievements</p>
                                </div>
                                <button onclick="window.print()" class="bg-indigo-50 hover:bg-indigo-100 dark:bg-gray-750 dark:hover:bg-gray-700 text-indigo-700 dark:text-indigo-300 font-semibold px-4 py-2 rounded-lg shadow-sm border border-indigo-150 dark:border-gray-650 transition flex items-center text-sm">
                                    <i class="fas fa-print mr-2"></i>Print Report
                                </button>
                            </div>
                            <div class="overflow-x-auto max-h-[380px] overflow-y-auto">
                                <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                                    <thead class="bg-gray-50 dark:bg-gray-750 sticky top-0 z-10">
                                        <tr>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                            <th class="px-6 py-3.5 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                            <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Position</th>
                                            <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                            <th class="px-6 py-3.5 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Average Score</th>
                                            <th class="px-6 py-3.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Term</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($records as $row): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-755 transition duration-150">
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($row['student_name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-350">
                                                <?php echo htmlspecialchars($row['grade_level'] . ' - ' . $row['class_name']); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350">
                                                #<?php echo htmlspecialchars($row['position_in_class']); ?> <span class="text-xs text-gray-400">/ <?php echo htmlspecialchars($row['class_size']); ?></span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                                <?php
                                                $gr = trim($row['grade'] ?? 'F');
                                                $badge_color = 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300';
                                                if (strpos($gr, 'A') !== false) {
                                                    $badge_color = 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300';
                                                } elseif (strpos($gr, 'B') !== false) {
                                                    $badge_color = 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300';
                                                } elseif (strpos($gr, 'C') !== false) {
                                                    $badge_color = 'bg-teal-100 text-teal-800 dark:bg-teal-900/40 dark:text-teal-300';
                                                } elseif (strpos($gr, 'D') !== false) {
                                                    $badge_color = 'bg-orange-100 text-orange-800 dark:bg-orange-900/40 dark:text-orange-300';
                                                }
                                                ?>
                                                <span class="px-2.5 py-1 text-xs font-bold rounded-full <?php echo $badge_color; ?>">
                                                    <?php echo htmlspecialchars($gr); ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-gray-900 dark:text-white">
                                                <?php echo number_format($row['total_score'], 1); ?>%
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-500 dark:text-gray-400">
                                                <?php echo htmlspecialchars($row['term_name']); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="p-4 bg-gray-50 dark:bg-gray-750 text-right text-xs text-gray-500 dark:text-gray-400 border-t border-gray-100 dark:border-gray-700">
                                Showing <?php echo count($records); ?> compiled grade records.
                            </div>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty State -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-folder-open text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Grade Records Found</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto mb-6">We couldn't find any student grade data matching the selected filters. Please verify your search inputs and try again.</p>
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

<!-- Load Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php if (count($records) > 0): ?>
<script>
document.addEventListener("DOMContentLoaded", function() {
    const ctx = document.getElementById('gradeDistributionChart').getContext('2d');
    
    // Set chart defaults for dark mode if active
    const isDarkMode = document.documentElement.classList.contains('dark');
    const labelColor = isDarkMode ? '#9ca3af' : '#4b5563';
    const gridColor = isDarkMode ? '#374151' : '#f3f4f6';

    const gradeData = {
        labels: ['A', 'B', 'C', 'D', 'F'],
        datasets: [{
            label: 'Students Count',
            data: [
                <?php echo $grade_counts['A']; ?>,
                <?php echo $grade_counts['B']; ?>,
                <?php echo $grade_counts['C']; ?>,
                <?php echo $grade_counts['D']; ?>,
                <?php echo $grade_counts['F']; ?>
            ],
            backgroundColor: [
                'rgba(34, 197, 94, 0.8)',   // Green for A
                'rgba(59, 130, 246, 0.8)',   // Blue for B
                'rgba(20, 184, 166, 0.8)',   // Teal for C
                'rgba(249, 115, 22, 0.8)',   // Orange for D
                'rgba(239, 68, 68, 0.8)'     // Red for F
            ],
            borderColor: [
                'rgb(34, 197, 94)',
                'rgb(59, 130, 246)',
                'rgb(20, 184, 166)',
                'rgb(249, 115, 22)',
                'rgb(239, 68, 68)'
            ],
            borderWidth: 1.5,
            borderRadius: 6
        }]
    };

    new Chart(ctx, {
        type: 'bar',
        data: gradeData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    backgroundColor: isDarkMode ? '#1f2937' : '#ffffff',
                    titleColor: isDarkMode ? '#ffffff' : '#111827',
                    bodyColor: isDarkMode ? '#d1d5db' : '#374151',
                    borderColor: isDarkMode ? '#374151' : '#e5e7eb',
                    borderWidth: 1,
                    cornerRadius: 8,
                    displayColors: false,
                    padding: 10
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: labelColor,
                        stepSize: 1
                    },
                    grid: {
                        color: gridColor
                    }
                },
                x: {
                    ticks: {
                        color: labelColor
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });
});
</script>
<?php endif; ?>
