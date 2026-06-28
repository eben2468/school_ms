<?php
// Transport Officer Dashboard Content
// Included by /dashboard.php (provides $db). Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Transport Officer';
$user_email = $_SESSION['email'] ?? '';
$pdo = $db;

// Defaults so the dashboard still renders if the transport tables are missing.
$total_vehicles = $active_vehicles = $maintenance_vehicles = $inactive_vehicles = 0;
$total_routes = $transport_students = $todays_trips = 0;
$route_labels = $route_values = '[]';
$type_labels = $type_values = '[]';
$maint_labels = $maint_values = '[]';
$schedule_runs = [];

try {
    // Fleet counts by status
    $total_vehicles       = (int)$pdo->query("SELECT COUNT(*) FROM transport_vehicles")->fetchColumn();
    $active_vehicles      = (int)$pdo->query("SELECT COUNT(*) FROM transport_vehicles WHERE status = 'active'")->fetchColumn();
    $maintenance_vehicles = (int)$pdo->query("SELECT COUNT(*) FROM transport_vehicles WHERE status = 'maintenance'")->fetchColumn();
    $inactive_vehicles    = (int)$pdo->query("SELECT COUNT(*) FROM transport_vehicles WHERE status = 'inactive'")->fetchColumn();

    // Active routes
    $total_routes = (int)$pdo->query("SELECT COUNT(*) FROM transport_routes WHERE status = 'active'")->fetchColumn();

    // Students using transport (student_transport links students to routes)
    $transport_students = (int)$pdo->query("SELECT COUNT(*) FROM student_transport WHERE status = 'active'")->fetchColumn();

    // Today's trips: each active scheduled run is a pickup + a drop (×2)
    $active_assignments = (int)$pdo->query("SELECT COUNT(*) FROM transport_assignments WHERE status = 'active'")->fetchColumn();
    $todays_trips = $active_assignments * 2;

    // Chart 1: students per active route
    $route_rows = $pdo->query("SELECT tr.route_name, COUNT(st.id) AS cnt
                               FROM transport_routes tr
                               LEFT JOIN student_transport st ON tr.id = st.route_id AND st.status = 'active'
                               WHERE tr.status = 'active'
                               GROUP BY tr.id, tr.route_name
                               ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC);
    $route_labels = json_encode(array_column($route_rows, 'route_name'));
    $route_values = json_encode(array_map('intval', array_column($route_rows, 'cnt')));

    // Chart 2: fleet composition by vehicle type
    $type_rows = $pdo->query("SELECT vehicle_type, COUNT(*) AS cnt FROM transport_vehicles GROUP BY vehicle_type")->fetchAll(PDO::FETCH_ASSOC);
    $type_labels = json_encode(array_map('ucfirst', array_column($type_rows, 'vehicle_type')));
    $type_values = json_encode(array_map('intval', array_column($type_rows, 'cnt')));

    // Chart 3: maintenance spend over the last 6 months
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("first day of -$i month"));
        $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'total' => 0.0];
    }
    foreach ($pdo->query("SELECT DATE_FORMAT(maintenance_date,'%Y-%m') ym, SUM(cost) total FROM transport_maintenance GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($months[$r['ym']])) $months[$r['ym']]['total'] = (float)$r['total'];
    }
    $maint_labels = json_encode(array_column(array_values($months), 'label'));
    $maint_values = json_encode(array_column(array_values($months), 'total'));

    // Today's scheduled runs (active assignments) for the schedule panel
    $schedule_runs = $pdo->query("SELECT ta.departure_time, ta.return_time, tr.route_name, tv.vehicle_number
                                  FROM transport_assignments ta
                                  JOIN transport_routes tr ON ta.route_id = tr.id
                                  JOIN transport_vehicles tv ON ta.vehicle_id = tv.id
                                  WHERE ta.status = 'active'
                                  ORDER BY ta.departure_time ASC
                                  LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Transport dashboard error: ' . $e->getMessage());
}
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'indigo'  => ['bg' => 'bg-indigo-100 dark:bg-indigo-900/40', 'text' => 'text-indigo-600 dark:text-indigo-400', 'ring' => 'hover:border-indigo-300 dark:hover:border-indigo-700'],
];
?>

<!-- Page Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-bus mr-1.5"></i> Transport Office</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Manage school transportation, routes and vehicle assignments.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-route text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Cards -->
<section class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6" aria-label="Transport statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Vehicles', 'value' => $total_vehicles, 'icon' => 'fa-bus', 'color' => 'blue'],
        ['label' => 'Active Routes', 'value' => $total_routes, 'icon' => 'fa-route', 'color' => 'emerald'],
        ['label' => 'Students', 'value' => $transport_students, 'icon' => 'fa-users', 'color' => 'violet'],
        ['label' => 'In Maintenance', 'value' => $maintenance_vehicles, 'icon' => 'fa-tools', 'color' => 'amber'],
        ['label' => "Today's Trips", 'value' => $todays_trips, 'icon' => 'fa-calendar-day', 'color' => 'indigo'],
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

<!-- Route Status Overview -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Vehicle Status -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-bus text-blue-500"></i> Vehicle Status
        </h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 bg-emerald-500 rounded-full"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">Active Vehicles</span>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $active_vehicles; ?></span>
            </div>
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 bg-amber-500 rounded-full"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">Under Maintenance</span>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $maintenance_vehicles; ?></span>
            </div>
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 bg-blue-500 rounded-full"></span>
                    <span class="text-sm text-gray-700 dark:text-gray-300">Total Fleet</span>
                </div>
                <span class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo $total_vehicles; ?></span>
            </div>
        </div>
    </div>

    <!-- Today's Schedule -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-clock text-emerald-500"></i> Today's Schedule
        </h3>
        <div class="space-y-3 max-h-72 overflow-y-auto">
            <?php if (!empty($schedule_runs)): ?>
                <?php foreach ($schedule_runs as $run):
                    $dep = $run['departure_time'] ? strtotime($run['departure_time']) : null;
                    $is_morning = $dep !== null && (int)date('H', $dep) < 12;
                ?>
                <div class="flex items-center justify-between p-3 <?php echo $is_morning ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-emerald-50 dark:bg-emerald-900/20'; ?> rounded-xl">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate"><?php echo htmlspecialchars($run['route_name']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <i class="fas fa-bus mr-1"></i><?php echo htmlspecialchars($run['vehicle_number']); ?>
                            &middot; <?php echo $dep ? date('g:i A', $dep) : '--'; ?><?php echo $run['return_time'] ? ' - ' . date('g:i A', strtotime($run['return_time'])) : ''; ?>
                        </p>
                    </div>
                    <div class="w-9 h-9 <?php echo $is_morning ? 'bg-blue-100 dark:bg-blue-900/50' : 'bg-emerald-100 dark:bg-emerald-900/50'; ?> rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas <?php echo $is_morning ? 'fa-sun text-blue-600 dark:text-blue-400' : 'fa-moon text-emerald-600 dark:text-emerald-400'; ?>"></i>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar-times text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">No scheduled runs configured</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Transport analytics">
    <!-- Students per Route -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-users text-violet-500"></i> Students per Route
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Active ridership across operating routes</p>
        <div class="relative h-72"><canvas id="routeStudentsChart"></canvas></div>
    </div>
    <!-- Fleet Composition -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-bus text-blue-500"></i> Fleet Composition
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Vehicles by type</p>
        <div class="relative h-72"><canvas id="fleetTypeChart"></canvas></div>
    </div>
</section>

<!-- Maintenance Spend Trend -->
<section class="mb-6" aria-label="Maintenance trend">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-screwdriver-wrench text-amber-500"></i> Maintenance Spend
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Repair &amp; servicing costs over the last 6 months</p>
        <div class="relative h-72"><canvas id="maintCostChart"></canvas></div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => 'transport/vehicles/index.php', 'icon' => 'fa-bus', 'color' => 'blue', 'title' => 'Manage Vehicles', 'desc' => 'Add, edit and track vehicles'],
        ['href' => 'transport/routes/index.php', 'icon' => 'fa-route', 'color' => 'emerald', 'title' => 'Route Management', 'desc' => 'Plan and manage routes'],
        ['href' => 'transport/assignments/index.php', 'icon' => 'fa-users', 'color' => 'violet', 'title' => 'Trip Assignments', 'desc' => 'Assign vehicles, routes & drivers'],
        ['href' => 'transport/maintenance/index.php', 'icon' => 'fa-screwdriver-wrench', 'color' => 'amber', 'title' => 'Maintenance', 'desc' => 'Track servicing & repairs'],
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

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    function buildTransportCharts() {
        if (typeof Chart === 'undefined') { setTimeout(buildTransportCharts, 100); return; }
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var tickColor = isDark ? '#9ca3af' : '#6b7280';
        Chart.defaults.color = tickColor;
        Chart.defaults.font.family = "'Inter', system-ui, sans-serif";

        function noData(canvas, icon, msg) {
            canvas.parentElement.innerHTML =
                '<div class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-gray-500">' +
                '<i class="fas ' + icon + ' text-4xl mb-3"></i><p class="text-sm">' + msg + '</p></div>';
        }

        // Students per Route (horizontal bar)
        var rsEl = document.getElementById('routeStudentsChart');
        var rsLabels = <?php echo $route_labels; ?>;
        var rsValues = <?php echo $route_values; ?>;
        if (rsEl) {
            if (!rsLabels.length) {
                noData(rsEl, 'fa-users', 'No route ridership data');
            } else {
                new Chart(rsEl.getContext('2d'), {
                    type: 'bar',
                    data: { labels: rsLabels, datasets: [{ label: 'Students', data: rsValues, backgroundColor: '#8b5cf6', borderRadius: 6, maxBarThickness: 22 }] },
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, precision: 0 } },
                            y: { grid: { display: false }, ticks: { color: tickColor } }
                        }
                    }
                });
            }
        }

        // Fleet composition (doughnut)
        var ftEl = document.getElementById('fleetTypeChart');
        var ftValues = <?php echo $type_values; ?>;
        if (ftEl) {
            if (!ftValues.length) {
                noData(ftEl, 'fa-bus', 'No vehicle data');
            } else {
                new Chart(ftEl.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: <?php echo $type_labels; ?>, datasets: [{ data: ftValues, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6'], borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff' }] },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '62%',
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } } }
                    }
                });
            }
        }

        // Maintenance spend (line)
        var mcEl = document.getElementById('maintCostChart');
        var mcValues = <?php echo $maint_values; ?>;
        if (mcEl) {
            var ctx = mcEl.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 288);
            grad.addColorStop(0, 'rgba(245,158,11,0.25)');
            grad.addColorStop(1, 'rgba(245,158,11,0)');
            new Chart(ctx, {
                type: 'line',
                data: { labels: <?php echo $maint_labels; ?>, datasets: [{ label: 'Cost', data: mcValues, borderColor: '#f59e0b', backgroundColor: grad, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#f59e0b', pointRadius: 4, pointHoverRadius: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return '₵' + Number(c.parsed.y).toLocaleString(undefined, { minimumFractionDigits: 2 }); } } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₵' + Number(v).toLocaleString(); } } }
                    }
                }
            });
        }
    }
    buildTransportCharts();
})();
</script>
