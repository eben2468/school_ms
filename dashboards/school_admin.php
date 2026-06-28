<?php
// School Admin Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM users WHERE role = 'parent') as total_parents,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_today,
        (SELECT COUNT(*) FROM subjects) as total_subjects,
        (SELECT COUNT(*) FROM attendance WHERE DATE(date) = CURDATE()) as today_attendance,
        (SELECT COUNT(*) FROM assignments) as active_assignments";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_parents' => 0,
        'total_classes' => 0,
        'new_today' => 0,
        'total_subjects' => 0,
        'today_attendance' => 0,
        'active_assignments' => 0
    ];
}

// Get enrollment trends
try {
    $enrollment_query = "SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count
        FROM users
        WHERE role = 'student'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month";
    $enrollment_stmt = $db->query($enrollment_query);
    $enrollment_data = $enrollment_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $enrollment_data = [];
}

// Get recent activities
try {
    $activities_query = "SELECT
        u.name, u.role, u.created_at, 'enrollment' as activity_type
        FROM users u
        WHERE u.role IN ('student', 'teacher')
        ORDER BY u.created_at DESC LIMIT 6";
    $activities_stmt = $db->query($activities_query);
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_activities = [];
}

// Get pending tasks
try {
    $pending_query = "SELECT
        (SELECT COUNT(*) FROM assignments WHERE due_date < NOW()) as overdue_assignments";
    $pending_stmt = $db->query($pending_query);
    $pending_tasks = $pending_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pending_tasks) {
        $pending_tasks = ['overdue_assignments' => 0];
    }
    $pending_tasks['pending_approvals'] = 0;
    $pending_tasks['pending_fees'] = 0;
} catch (PDOException $e) {
    $pending_tasks = [
        'pending_approvals' => 0,
        'overdue_assignments' => 0,
        'pending_fees' => 0
    ];
}
?>

<?php
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'green'   => ['bg' => 'bg-green-100 dark:bg-green-900/40', 'text' => 'text-green-600 dark:text-green-400', 'ring' => 'hover:border-green-300 dark:hover:border-green-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
];
?>
<!-- School Admin Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-school mr-1.5"></i> School Administration</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Administrator'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Manage school operations and academic excellence.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($academic_context['year_name']); ?> &middot; <?php echo htmlspecialchars($academic_context['term_name']); ?></span>
                    <a href="academic/settings/" class="flex items-center hover:text-white transition-colors"><i class="fas fa-cog mr-2"></i>Settings</a>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-university text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- School Admin Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="School statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Students', 'value' => $stats['total_students'], 'icon' => 'fa-user-graduate', 'color' => 'blue', 'hint' => 'Active enrollments'],
        ['label' => 'Faculty Members', 'value' => $stats['total_teachers'], 'icon' => 'fa-chalkboard-teacher', 'color' => 'green', 'hint' => 'Active teachers'],
        ['label' => 'Active Classes', 'value' => $stats['total_classes'], 'icon' => 'fa-chalkboard', 'color' => 'violet', 'hint' => 'This session'],
        ['label' => "Today's Attendance", 'value' => $stats['today_attendance'], 'icon' => 'fa-calendar-check', 'color' => 'amber', 'hint' => 'Records taken'],
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

<!-- Management Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Enrollment Trends -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Enrollment</h3>
            <div class="flex items-center space-x-2">
                <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                <span class="text-sm text-gray-600 dark:text-gray-400">Last 6 months</span>
            </div>
        </div>
        <div class="h-64">
            <canvas id="enrollmentChart"></canvas>
        </div>
    </div>

    <!-- Pending Tasks -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pending Tasks</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Requires attention</span>
        </div>
        <div class="space-y-4">
            <div class="flex items-center justify-between p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-clock text-yellow-600 dark:text-yellow-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Pending Approvals</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">User registrations</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-yellow-600 dark:text-yellow-400"><?php echo $pending_tasks['pending_approvals'] ?? 0; ?></span>
            </div>
            
            <div class="flex items-center justify-between p-4 bg-red-50 dark:bg-red-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Overdue Assignments</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Need attention</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-red-600 dark:text-red-400"><?php echo $pending_tasks['overdue_assignments'] ?? 0; ?></span>
            </div>
            
            <div class="flex items-center justify-between p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-blue-600 dark:text-blue-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Pending Fees</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Payment processing</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-blue-600 dark:text-blue-400"><?php echo $pending_tasks['pending_fees'] ?? 0; ?></span>
            </div>
        </div>
    </div>
</div>

<!-- Community & Academic Snapshot Charts -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Community Composition -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">School Community</h3>
        </div>
        <div class="h-64"><canvas id="communityChart"></canvas></div>
    </div>
    <!-- Academic Snapshot -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Academic Snapshot</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Current session</span>
        </div>
        <div class="h-64"><canvas id="academicSnapshotChart"></canvas></div>
    </div>
</div>

<!-- School Admin Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">School Management</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Administrative tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="students/enroll.php" class="flex flex-col items-center p-4 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-user-plus text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Enroll Student</span>
        </a>
        <a href="academic/classes/create.php" class="flex flex-col items-center p-4 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-chalkboard text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Create Class</span>
        </a>
        <a href="finance/fee_structures.php" class="flex flex-col items-center p-4 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-money-bill-wave text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Manage Fees</span>
        </a>
        <a href="reports/index.php" class="flex flex-col items-center p-4 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-chart-bar text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Reports</span>
        </a>
        <a href="communication/announcements.php" class="flex flex-col items-center p-4 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-bullhorn text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Announcements</span>
        </a>
    </div>
</div>

<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Real enrollment data from the server (last 6 months); falls back gracefully if empty.
const ENROLLMENT_DATA = <?php echo json_encode(array_map(function ($r) {
    return ['label' => date('M Y', strtotime($r['month'] . '-01')), 'value' => (int)$r['count']];
}, $enrollment_data), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

(function () {
    let chart = null;

    function render() {
        const canvas = document.getElementById('enrollmentChart');
        if (!canvas || !window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const labels = ENROLLMENT_DATA.map(d => d.label);
        const values = ENROLLMENT_DATA.map(d => d.value);

        if (chart) chart.destroy();
        chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels.length ? labels : ['No data'],
                datasets: [{
                    label: 'New Enrollments',
                    data: values.length ? values : [0],
                    borderColor: 'rgb(59, 130, 246)',
                    backgroundColor: 'rgba(59, 130, 246, 0.12)',
                    borderWidth: 3, fill: true, tension: 0.4,
                    pointBackgroundColor: 'rgb(59, 130, 246)', pointBorderColor: '#fff',
                    pointBorderWidth: 2, pointRadius: 5, pointHoverRadius: 7
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        cornerRadius: 8, displayColors: false,
                        callbacks: { label: c => `${c.parsed.y} new students enrolled` }
                    }
                },
                interaction: { intersect: false, mode: 'index' },
                scales: {
                    x: { grid: { display: false }, ticks: { color: tick, font: { size: 12 } } },
                    y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 12 }, precision: 0 } }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());

// Community composition (doughnut) + academic snapshot (bar) from live $stats.
const SA_COMMUNITY = <?php echo json_encode([
    'labels' => ['Students', 'Teachers', 'Parents'],
    'data'   => [(int)$stats['total_students'], (int)$stats['total_teachers'], (int)$stats['total_parents']]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const SA_SNAPSHOT = <?php echo json_encode([
    'labels' => ['Classes', 'Subjects', 'Assignments', "Today's Attendance"],
    'data'   => [(int)$stats['total_classes'], (int)$stats['total_subjects'], (int)$stats['active_assignments'], (int)$stats['today_attendance']]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let commChart = null, snapChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const cc = document.getElementById('communityChart');
        if (cc) {
            const hasData = SA_COMMUNITY.data.some(v => v > 0);
            if (commChart) commChart.destroy();
            commChart = new Chart(cc, {
                type: 'doughnut',
                data: { labels: SA_COMMUNITY.labels, datasets: [{ data: hasData ? SA_COMMUNITY.data : [1, 0, 0],
                        backgroundColor: ['#3b82f6', '#10b981', '#8b5cf6'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 10, color: tick, font: { size: 11 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }

        const sc = document.getElementById('academicSnapshotChart');
        if (sc) {
            if (snapChart) snapChart.destroy();
            snapChart = new Chart(sc, {
                type: 'bar',
                data: { labels: SA_SNAPSHOT.labels, datasets: [{ label: 'Count', data: SA_SNAPSHOT.data,
                        backgroundColor: ['rgba(99,102,241,0.7)', 'rgba(16,185,129,0.7)', 'rgba(245,158,11,0.7)', 'rgba(236,72,153,0.7)'],
                        borderRadius: 8, maxBarThickness: 56 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                    }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());
</script>
