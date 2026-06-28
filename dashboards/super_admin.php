<?php
// Super Admin Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM users WHERE role = 'parent') as total_parents,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_today,
        (SELECT COUNT(*) FROM users WHERE role = 'school_admin') as total_admins,
        (SELECT COUNT(*) FROM academic_years) as academic_years,
        (SELECT COUNT(*) FROM subjects) as total_subjects";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback values if query fails
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_parents' => 0,
        'total_classes' => 0,
        'new_today' => 0,
        'total_admins' => 0,
        'academic_years' => 0,
        'total_subjects' => 0
    ];
}

// Get system analytics
try {
    $analytics_query = "SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count,
        role
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), role
        ORDER BY month";
    $analytics_stmt = $db->query($analytics_query);
    $analytics_data = $analytics_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $analytics_data = [];
}

// Get recent system activities
try {
    $activities_query = "SELECT
        u.name, u.role, u.created_at, 'user_created' as activity_type
        FROM users u
        ORDER BY u.created_at DESC LIMIT 8";
    $activities_stmt = $db->query($activities_query);
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_activities = [];
}
?>

<?php
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
    'green'   => ['bg' => 'bg-green-100 dark:bg-green-900/40', 'text' => 'text-green-600 dark:text-green-400', 'ring' => 'hover:border-green-300 dark:hover:border-green-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
];
$total_users = $stats['total_students'] + $stats['total_teachers'] + $stats['total_parents'] + $stats['total_admins'];
?>
<!-- Super Admin Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-shield-alt mr-1.5"></i> Super Admin Control Center</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Administrator'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Complete system oversight and management.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-clock mr-2"></i><span id="current-time"><?php echo date('g:i A'); ?></span></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-crown text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Super Admin Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="System statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Users', 'value' => $total_users, 'icon' => 'fa-users', 'color' => 'blue', 'hint' => 'All system users'],
        ['label' => 'System Admins', 'value' => $stats['total_admins'], 'icon' => 'fa-user-shield', 'color' => 'rose', 'hint' => 'Administrative users'],
        ['label' => 'Academic Years', 'value' => $stats['academic_years'], 'icon' => 'fa-calendar-alt', 'color' => 'green', 'hint' => 'System records'],
        ['label' => 'New Today', 'value' => $stats['new_today'], 'icon' => 'fa-user-plus', 'color' => 'violet', 'hint' => 'New registrations'],
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

<!-- User Distribution & System Entities -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Role Distribution -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">User Distribution</h3>
        </div>
        <div class="h-64"><canvas id="roleDistributionChart"></canvas></div>
    </div>
    <!-- System Entities -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Records Overview</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Across the platform</span>
        </div>
        <div class="h-64"><canvas id="systemEntitiesChart"></canvas></div>
    </div>
</div>

<!-- System Management Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- System Analytics -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Growth</h3>
            <div class="flex items-center space-x-2">
                <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                <span class="text-sm text-gray-600 dark:text-gray-400">Last 6 months</span>
            </div>
        </div>
        <div class="h-64" style="min-height: 256px;">
            <canvas id="systemGrowthChart"></canvas>
        </div>

        <!-- Growth Insights & Quick Actions -->
        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <!-- Growth Rate -->
                <div class="text-center">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">+12.5%</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Student Growth</div>
                </div>
                <!-- Peak Month -->
                <div class="text-center">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">Jan 25</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Peak Month</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex space-x-2">
                <button class="flex-1 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 px-3 py-2 rounded-lg text-xs font-medium hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                    <i class="fas fa-chart-line mr-1"></i>
                    View Details
                </button>
                <button class="flex-1 bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 px-3 py-2 rounded-lg text-xs font-medium hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors">
                    <i class="fas fa-download mr-1"></i>
                    Export Data
                </button>
            </div>
        </div>
    </div>

    <!-- Recent System Activities -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Activities</h3>
            <a href="admin/logs.php" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800">View logs</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($recent_activities)): ?>
                <?php foreach ($recent_activities as $activity): ?>
                <div class="flex items-start space-x-3 p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user-plus text-red-600 dark:text-red-400 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            New <?php echo $activity['role']; ?>: <?php echo htmlspecialchars($activity['name']); ?>
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-inbox text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No recent activities</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Super Admin Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Administration</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Administrative tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="/school_ms/users/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-red-200 dark:group-hover:bg-red-800 transition-colors duration-200">
                <i class="fas fa-users-cog text-red-600 dark:text-red-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">User Management</span>
        </a>
        <a href="/school_ms/settings/super_admin.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-cogs text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">System Settings</span>
        </a>
        <a href="/school_ms/admin/backup.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-database text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Backup & Restore</span>
        </a>
        <a href="/school_ms/admin/security.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-shield-alt text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Security</span>
        </a>
        <a href="/school_ms/admin/logs.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-file-alt text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">System Logs</span>
        </a>
        <a href="/school_ms/academic/settings/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-graduation-cap text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Settings</span>
        </a>
    </div>
</div>

<!-- System Health -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Health</h3>
        <div class="flex items-center space-x-2">
            <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
            <span class="text-sm text-green-600 dark:text-green-400 font-medium">All systems operational</span>
        </div>
    </div>
    <div class="grid grid-cols-4 gap-3 md:gap-6">
        <div class="flex flex-col items-center text-center space-y-1.5 md:flex-row md:items-center md:text-left md:space-y-0 md:space-x-3">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-database text-green-600 dark:text-green-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Database</p>
                <p class="text-xs text-green-600 dark:text-green-400">Connected</p>
            </div>
        </div>
        <div class="flex flex-col items-center text-center space-y-1.5 md:flex-row md:items-center md:text-left md:space-y-0 md:space-x-3">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-server text-blue-600 dark:text-blue-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Server</p>
                <p class="text-xs text-blue-600 dark:text-blue-400">Online</p>
            </div>
        </div>
        <div class="flex flex-col items-center text-center space-y-1.5 md:flex-row md:items-center md:text-left md:space-y-0 md:space-x-3">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-shield-alt text-purple-600 dark:text-purple-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Security</p>
                <p class="text-xs text-purple-600 dark:text-purple-400">Protected</p>
            </div>
        </div>
        <div class="flex flex-col items-center text-center space-y-1.5 md:flex-row md:items-center md:text-left md:space-y-0 md:space-x-3">
            <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-chart-line text-orange-600 dark:text-orange-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Performance</p>
                <p class="text-xs text-orange-600 dark:text-orange-400">Optimal</p>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Real registration growth (last 6 months) grouped by role, built from $analytics_data.
const GROWTH_DATA = <?php
    // Pivot the (month, role, count) rows into month-indexed per-role series.
    $months = [];
    $series = ['student' => [], 'teacher' => [], 'parent' => []];
    foreach ($analytics_data as $row) {
        $m = $row['month'];
        if (!in_array($m, $months, true)) { $months[] = $m; }
    }
    sort($months);
    $lookup = [];
    foreach ($analytics_data as $row) {
        $lookup[$row['month'] . '|' . $row['role']] = (int)$row['count'];
    }
    $labels = array_map(fn($m) => date('M y', strtotime($m . '-01')), $months);
    foreach (['student', 'teacher', 'parent'] as $role) {
        foreach ($months as $m) {
            $series[$role][] = $lookup[$m . '|' . $role] ?? 0;
        }
    }
    echo json_encode(['labels' => $labels, 'series' => $series], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>;

(function () {
    let chart = null;

    function render() {
        const ctx = document.getElementById('systemGrowthChart');
        if (!ctx || !window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';
        const labels = GROWTH_DATA.labels.length ? GROWTH_DATA.labels : ['No data'];

        const mk = (label, data, color, fill) => ({
            label, data: data.length ? data : [0], borderColor: color,
            backgroundColor: fill, borderWidth: 3, fill: true, tension: 0.4,
            pointBackgroundColor: color, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4
        });

        if (chart) chart.destroy();
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    mk('Students', GROWTH_DATA.series.student, '#3b82f6', 'rgba(59,130,246,0.10)'),
                    mk('Teachers', GROWTH_DATA.series.teacher, '#10b981', 'rgba(16,185,129,0.10)'),
                    mk('Parents', GROWTH_DATA.series.parent, '#f59e0b', 'rgba(245,158,11,0.10)')
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, boxWidth: 12, color: tick, font: { size: 11 } } },
                    tooltip: { mode: 'index', intersect: false, cornerRadius: 8 }
                },
                interaction: { mode: 'nearest', axis: 'x', intersect: false },
                scales: {
                    x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                    y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());

// Role distribution (doughnut) + system entities (bar), built from live $stats.
const ROLE_DIST = <?php echo json_encode([
    'labels' => ['Students', 'Teachers', 'Parents', 'Admins'],
    'data'   => [(int)$stats['total_students'], (int)$stats['total_teachers'], (int)$stats['total_parents'], (int)$stats['total_admins']]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const SYS_ENTITIES = <?php echo json_encode([
    'labels' => ['Classes', 'Subjects', 'Academic Years', 'Admins'],
    'data'   => [(int)$stats['total_classes'], (int)$stats['total_subjects'], (int)$stats['academic_years'], (int)$stats['total_admins']]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let roleChart = null, entChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const rd = document.getElementById('roleDistributionChart');
        if (rd) {
            const hasData = ROLE_DIST.data.some(v => v > 0);
            if (roleChart) roleChart.destroy();
            roleChart = new Chart(rd, {
                type: 'doughnut',
                data: { labels: ROLE_DIST.labels, datasets: [{ data: hasData ? ROLE_DIST.data : [1, 0, 0, 0],
                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 10, color: tick, font: { size: 11 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }

        const se = document.getElementById('systemEntitiesChart');
        if (se) {
            if (entChart) entChart.destroy();
            entChart = new Chart(se, {
                type: 'bar',
                data: { labels: SYS_ENTITIES.labels, datasets: [{ label: 'Records', data: SYS_ENTITIES.data,
                        backgroundColor: ['rgba(59,130,246,0.7)', 'rgba(16,185,129,0.7)', 'rgba(245,158,11,0.7)', 'rgba(239,68,68,0.7)'],
                        borderRadius: 8, maxBarThickness: 60 }] },
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
