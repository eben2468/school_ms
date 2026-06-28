<?php
// Principal Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM subjects) as total_subjects,
        (SELECT AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 FROM attendance WHERE MONTH(date) = MONTH(NOW())) as attendance_rate,
        (SELECT COUNT(*) FROM assignments) as active_assignments";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = [
            'total_students' => 0,
            'total_teachers' => 0,
            'total_classes' => 0,
            'total_subjects' => 0,
            'attendance_rate' => 0,
            'active_assignments' => 0
        ];
    }
    $stats['upcoming_exams'] = 0;
    $stats['teachers_online_today'] = 0;
} catch (PDOException $e) {
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_classes' => 0,
        'total_subjects' => 0,
        'attendance_rate' => 0,
        'upcoming_exams' => 0,
        'active_assignments' => 0,
        'teachers_online_today' => 0
    ];
}

// Get academic performance overview
try {
    $performance_query = "SELECT
        c.name as class_name,
        c.grade_level,
        COUNT(sc.student_id) as student_count,
        AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_rate
        FROM classes c
        LEFT JOIN student_classes sc ON c.id = sc.class_id
        LEFT JOIN attendance a ON sc.student_id = a.student_id AND MONTH(a.date) = MONTH(NOW())
        GROUP BY c.id, c.name, c.grade_level
        ORDER BY c.grade_level";
    $performance_stmt = $db->query($performance_query);
    $class_performance = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $class_performance = [];
}

// Get recent achievements and events (placeholder)
$recent_achievements = [];

// Get teacher performance metrics
try {
    // Teacher↔class relationships live in the class_teachers junction (classes has
    // no teacher_id column), so derive classes taught and attendance from there.
    $teacher_metrics_query = "SELECT
        u.name as teacher_name,
        COUNT(DISTINCT ct.class_id) as classes_taught,
        COUNT(DISTINCT a.id) as assignments_given,
        AVG(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) * 100 as class_attendance_rate
        FROM users u
        LEFT JOIN class_teachers ct ON u.id = ct.teacher_id
        LEFT JOIN assignments a ON u.id = a.teacher_id
        LEFT JOIN attendance att ON ct.class_id = att.class_id AND MONTH(att.date) = MONTH(NOW())
        WHERE u.role = 'teacher'
        GROUP BY u.id, u.name
        ORDER BY classes_taught DESC
        LIMIT 5";
    $teacher_metrics_stmt = $db->query($teacher_metrics_query);
    $teacher_metrics = $teacher_metrics_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $teacher_metrics = [];
}
?>

<?php
$color_map = [
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'green'   => ['bg' => 'bg-green-100 dark:bg-green-900/40', 'text' => 'text-green-600 dark:text-green-400', 'ring' => 'hover:border-green-300 dark:hover:border-green-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
];
?>
<!-- Principal Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-medal mr-1.5"></i> Headmaster/Headmistress's Office</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Headmaster'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Leading academic excellence and institutional growth.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($academic_context['year_name']); ?> &middot; <?php echo htmlspecialchars($academic_context['term_name']); ?></span>
                    <a href="academic/settings/" class="flex items-center hover:text-white transition-colors"><i class="fas fa-cog mr-2"></i>Settings</a>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-user-tie text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Principal Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="School statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Students', 'value' => $stats['total_students'], 'icon' => 'fa-user-graduate', 'color' => 'emerald', 'hint' => 'Enrolled students'],
        ['label' => 'Faculty Strength', 'value' => $stats['total_teachers'], 'icon' => 'fa-chalkboard-teacher', 'color' => 'blue', 'hint' => 'Teaching staff'],
        ['label' => 'Attendance Rate', 'value' => number_format($stats['attendance_rate'] ?? 0, 1) . '%', 'icon' => 'fa-calendar-check', 'color' => 'green', 'hint' => 'This month'],
        ['label' => 'Active Programs', 'value' => $stats['total_subjects'], 'icon' => 'fa-book', 'color' => 'violet', 'hint' => 'Subject offerings'],
    ];
    foreach ($summary_cards as $card):
        $c = $color_map[$card['color']];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-5 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 <?php echo $c['bg']; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?php echo $card['label']; ?></p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Principal Charts & Analytics -->
<?php
// Attendance rate per class (top 8) from the live performance query.
$attn_chart = ['labels' => [], 'rates' => []];
foreach (array_slice($class_performance, 0, 8) as $cl) {
    $attn_chart['labels'][] = $cl['class_name'];
    $attn_chart['rates'][] = round((float)($cl['attendance_rate'] ?? 0), 1);
}
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Attendance by Class -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Attendance Rate by Class</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">This month</span>
        </div>
        <div class="h-64"><canvas id="principalAttendanceChart"></canvas></div>
    </div>
    <!-- Community Composition -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">School Community</h3>
        </div>
        <div class="h-64"><canvas id="principalCommunityChart"></canvas></div>
    </div>
</div>

<!-- Academic Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Class Performance Overview -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Class Performance</h3>
            <a href="academic/classes/" class="text-sm text-emerald-600 dark:text-emerald-400 hover:text-emerald-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($class_performance)): ?>
                <?php foreach (array_slice($class_performance, 0, 5) as $class): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center">
                            <span class="text-emerald-600 dark:text-emerald-400 font-bold text-sm"><?php echo $class['grade_level']; ?></span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['class_name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $class['student_count']; ?> students</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format($class['attendance_rate'] ?? 0, 1); ?>%</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Attendance</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-chalkboard text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No class data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Teacher Performance -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Faculty Performance</h3>
            <a href="reports/teacher_performance.php" class="text-sm text-emerald-600 dark:text-emerald-400 hover:text-emerald-800">View report</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($teacher_metrics)): ?>
                <?php foreach ($teacher_metrics as $teacher): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-tie text-blue-600 dark:text-blue-400"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($teacher['teacher_name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $teacher['classes_taught']; ?> classes, <?php echo $teacher['assignments_given']; ?> assignments</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format($teacher['class_attendance_rate'] ?? 0, 1); ?>%</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Class attendance</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-user-tie text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No teacher data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Principal Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Leadership Actions</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Headmaster/Headmistress tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="academic/exams/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-800 transition-colors duration-200">
                <i class="fas fa-file-alt text-emerald-600 dark:text-emerald-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Schedule Exam</span>
        </a>
        <a href="reports/academic.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-chart-line text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Reports</span>
        </a>
        <a href="communication/announcements.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-bullhorn text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Announcements</span>
        </a>
        <a href="academic/class-management.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-user-friends text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Class Management</span>
        </a>
        <a href="academic/promotions/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-level-up-alt text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Student Promotion</span>
        </a>
        <a href="finance/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-wallet text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Finance Overview</span>
        </a>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const PRINCIPAL_ATTN = <?php echo json_encode($attn_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const PRINCIPAL_COMMUNITY = <?php echo json_encode([
    'labels' => ['Students', 'Teachers', 'Subjects', 'Classes'],
    'data'   => [(int)$stats['total_students'], (int)$stats['total_teachers'], (int)$stats['total_subjects'], (int)$stats['total_classes']]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let attnChart = null, commChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const ac = document.getElementById('principalAttendanceChart');
        if (ac) {
            const labels = PRINCIPAL_ATTN.labels.length ? PRINCIPAL_ATTN.labels : ['No data'];
            const rates = PRINCIPAL_ATTN.rates.length ? PRINCIPAL_ATTN.rates : [0];
            if (attnChart) attnChart.destroy();
            attnChart = new Chart(ac, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Attendance %', data: rates,
                        backgroundColor: rates.map(r => r >= 75 ? 'rgba(16,185,129,0.7)' : r >= 50 ? 'rgba(245,158,11,0.7)' : 'rgba(244,63,94,0.7)'),
                        borderRadius: 8, maxBarThickness: 38 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false,
                               callbacks: { label: c => c.parsed.y + '% attendance' } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, max: 100, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, callback: v => v + '%' } }
                    }
                }
            });
        }

        const cc = document.getElementById('principalCommunityChart');
        if (cc) {
            if (commChart) commChart.destroy();
            commChart = new Chart(cc, {
                type: 'doughnut',
                data: { labels: PRINCIPAL_COMMUNITY.labels, datasets: [{ data: PRINCIPAL_COMMUNITY.data,
                        backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 10, color: tick, font: { size: 11 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());
</script>
