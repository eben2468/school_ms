<?php
// Counselor Dashboard Content
// Included by /dashboard.php (provides $db). Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Counselor';
$user_email = $_SESSION['email'] ?? '';
$pdo = $db;

// Get counseling statistics
try {
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM students WHERE status = 'active'");
    $total_students = $stmt->fetch()['total_students'];
    
    // Scheduled counseling sessions (active)
    $stmt = $pdo->query("SELECT COUNT(*) as active_sessions FROM counseling_sessions WHERE status = 'scheduled'");
    $active_sessions = $stmt->fetch()['active_sessions'];
    
    // Today's appointments
    $stmt = $pdo->query("SELECT COUNT(*) as todays_appointments FROM counseling_sessions WHERE session_date = CURDATE() AND status = 'scheduled'");
    $todays_appointments = $stmt->fetch()['todays_appointments'];
    
    // Sessions requiring follow-up
    $stmt = $pdo->query("SELECT COUNT(*) as pending_referrals FROM counseling_sessions WHERE follow_up_required = 'yes'");
    $pending_referrals = $stmt->fetch()['pending_referrals'];
    
    // Students who received counseling
    $stmt = $pdo->query("SELECT COUNT(DISTINCT student_id) as support_students FROM counseling_sessions");
    $support_students = $stmt->fetch()['support_students'];

    // Today's scheduled sessions (real list)
    $stmt = $pdo->query("SELECT cs.session_time, cs.session_type, cs.status, u.name AS student_name
        FROM counseling_sessions cs
        JOIN users u ON cs.student_id = u.id
        WHERE cs.session_date = CURDATE()
        ORDER BY cs.session_time ASC
        LIMIT 5");
    $today_sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Cases flagged for follow-up (priority list)
    $stmt = $pdo->query("SELECT cs.session_type, cs.session_date, u.name AS student_name
        FROM counseling_sessions cs
        JOIN users u ON cs.student_id = u.id
        WHERE cs.follow_up_required = 'yes'
        ORDER BY cs.session_date DESC
        LIMIT 5");
    $followup_cases = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Sessions per day for the last 7 days (chart)
    $by_date = [];
    foreach ($pdo->query("SELECT session_date, COUNT(*) AS cnt
        FROM counseling_sessions
        WHERE session_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY session_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by_date[$r['session_date']] = (int)$r['cnt'];
    }
    $weekly_sessions = ['labels' => [], 'data' => []];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $weekly_sessions['labels'][] = date('D', strtotime($d));
        $weekly_sessions['data'][]   = $by_date[$d] ?? 0;
    }
} catch (PDOException $e) {
    // Set default values if database fails
    $total_students = 0;
    $active_sessions = 0;
    $todays_appointments = 0;
    $pending_referrals = 0;
    $support_students = 0;
    $today_sessions = [];
    $followup_cases = [];
    $weekly_sessions = ['labels' => [], 'data' => []];
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
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-hands-helping mr-1.5"></i> Guidance &amp; Counseling</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Support student wellbeing and academic guidance.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-comments text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Cards -->
<section class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6" aria-label="Counseling statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Students', 'value' => $total_students, 'icon' => 'fa-users', 'color' => 'blue'],
        ['label' => 'Active Sessions', 'value' => $active_sessions, 'icon' => 'fa-comments', 'color' => 'emerald'],
        ['label' => "Today's Appointments", 'value' => $todays_appointments, 'icon' => 'fa-calendar-day', 'color' => 'violet'],
        ['label' => 'Pending Referrals', 'value' => $pending_referrals, 'icon' => 'fa-hand-holding-heart', 'color' => 'amber'],
        ['label' => 'Support Cases', 'value' => $support_students, 'icon' => 'fa-heart', 'color' => 'rose'],
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

<!-- Today's Schedule & Priority Cases -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Today's Schedule -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-calendar-day text-blue-500"></i> Today's Schedule
        </h3>
        <div class="space-y-3">
            <?php if (!empty($today_sessions)): ?>
                <?php foreach ($today_sessions as $s):
                    $st = strtolower($s['status'] ?? 'scheduled');
                    $badge = $st === 'completed' ? 'bg-emerald-100 dark:bg-emerald-900/50 text-emerald-700 dark:text-emerald-300'
                           : ($st === 'cancelled' ? 'bg-rose-100 dark:bg-rose-900/50 text-rose-700 dark:text-rose-300'
                           : 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300');
                ?>
                <div class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center">
                            <i class="fas fa-clock text-blue-600 dark:text-blue-400 text-sm"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars(ucfirst($s['session_type'] ?? 'Session')); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                <?php echo $s['session_time'] ? date('g:i A', strtotime($s['session_time'])) : 'Time TBA'; ?> &middot; <?php echo htmlspecialchars($s['student_name']); ?>
                            </p>
                        </div>
                    </div>
                    <span class="text-[11px] font-medium <?php echo $badge; ?> px-2 py-1 rounded-full"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar-day text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No sessions scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Priority Cases -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-flag text-rose-500"></i> Priority Cases
        </h3>
        <div class="space-y-3">
            <div class="flex items-center gap-3 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-xl">
                <div class="w-9 h-9 bg-amber-100 dark:bg-amber-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-hand-holding-heart text-amber-600 dark:text-amber-400 text-sm"></i>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Follow-up Required</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo (int)$pending_referrals; ?> case<?php echo $pending_referrals == 1 ? '' : 's'; ?> need a progress check</p>
                </div>
            </div>
            <div class="flex items-center gap-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
                <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-calendar text-blue-600 dark:text-blue-400 text-sm"></i>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-gray-900 dark:text-white">Scheduled Sessions</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo (int)$active_sessions; ?> upcoming appointment<?php echo $active_sessions == 1 ? '' : 's'; ?></p>
                </div>
            </div>
            <?php if (!empty($followup_cases)): ?>
                <?php foreach (array_slice($followup_cases, 0, 3) as $fc): ?>
                <div class="flex items-center gap-3 p-3 bg-rose-50 dark:bg-rose-900/20 rounded-xl">
                    <div class="w-9 h-9 bg-rose-100 dark:bg-rose-900/50 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user text-rose-600 dark:text-rose-400 text-sm"></i>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($fc['student_name']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars(ucfirst($fc['session_type'] ?? 'Session')); ?> &middot; <?php echo date('M j', strtotime($fc['session_date'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Counseling Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Counseling analytics">
    <!-- Weekly Sessions -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-line text-blue-500"></i> Sessions This Week
            </h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">Individual &amp; group</span>
        </div>
        <div class="h-64"><canvas id="counselorSessionsChart"></canvas></div>
    </div>
    <!-- Caseload Breakdown -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-pie text-violet-500"></i> Caseload Breakdown
            </h3>
        </div>
        <div class="h-64"><canvas id="counselorCaseloadChart"></canvas></div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => '/health/counseling/index.php', 'icon' => 'fa-comments', 'color' => 'blue', 'title' => 'Counseling Sessions', 'desc' => 'Individual &amp; group sessions'],
        ['href' => '/health/counseling/create.php', 'icon' => 'fa-calendar-plus', 'color' => 'emerald', 'title' => 'New Session', 'desc' => 'Schedule an appointment'],
        ['href' => '/health/counseling/index.php?status=scheduled', 'icon' => 'fa-hand-holding-heart', 'color' => 'violet', 'title' => 'Follow-ups', 'desc' => 'Handle guidance cases'],
        ['href' => '/health/reports/index.php', 'icon' => 'fa-chart-line', 'color' => 'amber', 'title' => 'Reports', 'desc' => 'Generate counseling reports'],
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
const COUNSELOR_CASELOAD = <?php echo json_encode([
    'labels' => ['Active Sessions', "Today's Appts", 'Pending Referrals'],
    'data'   => [(int)$active_sessions, (int)$todays_appointments, (int)$pending_referrals]
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const SESSIONS = <?php echo json_encode($weekly_sessions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let sessionsChart = null, caseChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const sc = document.getElementById('counselorSessionsChart');
        if (sc) {
            if (sessionsChart) sessionsChart.destroy();
            sessionsChart = new Chart(sc, {
                type: 'bar',
                data: { labels: SESSIONS.labels.length ? SESSIONS.labels : ['No data'],
                        datasets: [{ label: 'Sessions', data: SESSIONS.data.length ? SESSIONS.data : [0],
                            backgroundColor: 'rgba(59,130,246,0.7)', hoverBackgroundColor: 'rgba(59,130,246,0.95)',
                            borderRadius: 6, maxBarThickness: 34 }] },
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

        const cc = document.getElementById('counselorCaseloadChart');
        if (cc) {
            const hasData = COUNSELOR_CASELOAD.data.some(v => v > 0);
            if (caseChart) caseChart.destroy();
            caseChart = new Chart(cc, {
                type: 'doughnut',
                data: { labels: COUNSELOR_CASELOAD.labels, datasets: [{ data: hasData ? COUNSELOR_CASELOAD.data : [1, 0, 0],
                        backgroundColor: ['#10b981', '#8b5cf6', '#f59e0b'], borderColor: isDark ? '#1f2937' : '#fff',
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
