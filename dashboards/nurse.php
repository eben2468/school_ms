<?php
// Nurse Dashboard Content
// Included by /dashboard.php (provides $db). Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Nurse';
$user_email = $_SESSION['email'] ?? '';
$pdo = $db;

// Get health statistics
try {
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM students WHERE status = 'active'");
    $total_students = $stmt->fetch()['total_students'];
    
    // Health records
    $stmt = $pdo->query("SELECT COUNT(*) as health_records FROM health_records");
    $health_records = $stmt->fetch()['health_records'];
    
    // Today's visits
    $stmt = $pdo->query("SELECT COUNT(*) as todays_visits FROM health_records WHERE visit_date = CURDATE() AND complaint IS NOT NULL");
    $todays_visits = $stmt->fetch()['todays_visits'];
    
    // Active clinic cases (pending observation/clearance)
    $stmt = $pdo->query("SELECT COUNT(*) as pending_clearances FROM health_records WHERE status = 'active'");
    $pending_clearances = $stmt->fetch()['pending_clearances'];
    
    // Students with allergies
    $stmt = $pdo->query("SELECT COUNT(*) as allergy_students FROM health_records WHERE allergies IS NOT NULL AND allergies != ''");
    $allergy_students = $stmt->fetch()['allergy_students'];
    
    // Recent visits
    $stmt = $pdo->prepare("
        SELECT hr.*, s.name as student_name, s.student_id 
        FROM health_records hr
        JOIN students s ON hr.student_id = s.id
        WHERE hr.complaint IS NOT NULL
        ORDER BY hr.visit_date DESC, hr.visit_time DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_visits = $stmt->fetchAll();

    // Clinic visits per day for the last 7 days (chart)
    $by_date = [];
    foreach ($pdo->query("SELECT visit_date, COUNT(*) AS cnt
        FROM health_records
        WHERE complaint IS NOT NULL AND visit_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY visit_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by_date[$r['visit_date']] = (int)$r['cnt'];
    }
    $weekly_visits = ['labels' => [], 'data' => []];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $weekly_visits['labels'][] = date('D', strtotime($d));
        $weekly_visits['data'][]   = $by_date[$d] ?? 0;
    }
} catch (PDOException $e) {
    // Set default values if database fails
    $total_students = 0;
    $health_records = 0;
    $todays_visits = 0;
    $pending_clearances = 0;
    $allergy_students = 0;
    $recent_visits = [];
    $weekly_visits = ['labels' => [], 'data' => []];
}
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
];
?>

<!-- Page Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-heartbeat mr-1.5"></i> Health Office</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Monitor student health and manage medical records.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-stethoscope text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Cards -->
<section class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6" aria-label="Health statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Students', 'value' => $total_students, 'icon' => 'fa-users', 'color' => 'blue'],
        ['label' => 'Health Records', 'value' => $health_records, 'icon' => 'fa-file-medical', 'color' => 'emerald'],
        ['label' => "Today's Visits", 'value' => $todays_visits, 'icon' => 'fa-stethoscope', 'color' => 'violet'],
        ['label' => 'Pending Clearances', 'value' => $pending_clearances, 'icon' => 'fa-clipboard-check', 'color' => 'amber'],
        ['label' => 'Allergy Alerts', 'value' => $allergy_students, 'icon' => 'fa-exclamation-triangle', 'color' => 'rose'],
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
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Health Overview -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Recent Visits -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-notes-medical text-blue-500"></i> Recent Health Visits
        </h3>
        <?php if (empty($recent_visits)): ?>
            <div class="text-center py-8">
                <i class="fas fa-stethoscope text-gray-300 dark:text-gray-600 text-4xl mb-3"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">No recent visits recorded</p>
            </div>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($recent_visits as $visit): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-blue-600 dark:text-blue-400 text-sm"></i>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($visit['student_name']); ?></p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($visit['student_id']); ?></p>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo date('M j', strtotime($visit['visit_date'])); ?></p>
                            <p class="text-[11px] text-gray-400 dark:text-gray-500"><?php echo date('g:i A', strtotime($visit['visit_time'])); ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Health Alerts -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-bell text-amber-500"></i> Health Alerts &amp; Reminders
        </h3>
        <div class="space-y-3">
            <div class="flex items-center gap-3 p-3 bg-rose-50 dark:bg-rose-900/20 rounded-xl">
                <div class="w-9 h-9 bg-rose-100 dark:bg-rose-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-exclamation text-rose-600 dark:text-rose-400 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Allergy Alerts</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $allergy_students; ?> students with known allergies</p>
                </div>
            </div>
            <div class="flex items-center gap-3 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-xl">
                <div class="w-9 h-9 bg-amber-100 dark:bg-amber-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-clock text-amber-600 dark:text-amber-400 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Pending Clearances</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $pending_clearances; ?> medical clearances pending</p>
                </div>
            </div>
            <div class="flex items-center gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
                <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar text-blue-600 dark:text-blue-400 text-sm"></i>
                </div>
                <div>
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Vaccination Schedule</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Check upcoming vaccination dates</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Health Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Health analytics">
    <!-- Weekly Clinic Visits -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-line text-blue-500"></i> Weekly Clinic Visits
            </h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">Last 7 days</span>
        </div>
        <div class="h-64"><canvas id="nurseVisitsChart"></canvas></div>
    </div>
    <!-- Health Snapshot -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-pie text-violet-500"></i> Health Snapshot
            </h3>
        </div>
        <div class="h-64"><canvas id="nurseSnapshotChart"></canvas></div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => '/health/records/index.php', 'icon' => 'fa-file-medical', 'color' => 'blue', 'title' => 'Health Records', 'desc' => 'Manage health records'],
        ['href' => '/health/medical_records/index.php', 'icon' => 'fa-stethoscope', 'color' => 'emerald', 'title' => 'Health Visits', 'desc' => 'Record and track visits'],
        ['href' => '/health/emergency/index.php', 'icon' => 'fa-briefcase-medical', 'color' => 'violet', 'title' => 'Emergency', 'desc' => 'Emergency contacts & cases'],
        ['href' => '/health/reports/index.php', 'icon' => 'fa-chart-line', 'color' => 'amber', 'title' => 'Health Reports', 'desc' => 'Generate health reports'],
    ];
    foreach ($quick_actions as $qa):
        $c = $color_map[$qa['color']];
    ?>
    <a href="<?php echo $qa['href']; ?>" class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-6 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200 text-center">
        <div class="w-14 h-14 <?php echo $c['bg']; ?> rounded-2xl flex items-center justify-center mx-auto mb-3">
            <i class="fas <?php echo $qa['icon']; ?> <?php echo $c['text']; ?> text-2xl"></i>
        </div>
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1"><?php echo $qa['title']; ?></h3>
        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $qa['desc']; ?></p>
    </a>
    <?php endforeach; ?>
</section>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const NURSE_SNAPSHOT = <?php echo json_encode([
    'labels' => ["Today's Visits", 'Pending Clearances', 'Allergy Alerts'],
    'data'   => [(int)$todays_visits, (int)$pending_clearances, (int)$allergy_students]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const VISITS = <?php echo json_encode($weekly_visits, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let visitsChart = null, snapChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const vc = document.getElementById('nurseVisitsChart');
        if (vc) {
            if (visitsChart) visitsChart.destroy();
            visitsChart = new Chart(vc, {
                type: 'line',
                data: { labels: VISITS.labels.length ? VISITS.labels : ['No data'], datasets: [{ label: 'Visits', data: VISITS.data.length ? VISITS.data : [0],
                        borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.12)', borderWidth: 3, fill: true, tension: 0.4,
                        pointBackgroundColor: '#3b82f6', pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4, pointHoverRadius: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false } },
                    interaction: { intersect: false, mode: 'index' },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                    }
                }
            });
        }

        const sc = document.getElementById('nurseSnapshotChart');
        if (sc) {
            const hasData = NURSE_SNAPSHOT.data.some(v => v > 0);
            if (snapChart) snapChart.destroy();
            snapChart = new Chart(sc, {
                type: 'doughnut',
                data: { labels: NURSE_SNAPSHOT.labels, datasets: [{ data: hasData ? NURSE_SNAPSHOT.data : [1, 0, 0],
                        backgroundColor: ['#8b5cf6', '#f59e0b', '#f43f5e'], borderColor: isDark ? '#1f2937' : '#fff',
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
