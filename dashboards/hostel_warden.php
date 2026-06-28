<?php
// Hostel Warden Dashboard Content
// Included by /dashboard.php (provides $db). Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Hostel Warden';
$user_email = $_SESSION['email'] ?? '';
$pdo = $db;

// Defaults so the dashboard still renders if the hostel tables are missing.
$total_blocks = $total_rooms = $occupied_rooms = $available_rooms = 0;
$allocated_students = $pending_requests = $total_capacity = 0;
$recent_allocations = [];
$block_labels = $block_capacity = $block_occupied = '[]';
$type_labels = $type_values = '[]';

try {
    // Active hostel blocks
    $total_blocks = (int)$pdo->query("SELECT COUNT(*) FROM hostel_blocks WHERE status = 'active'")->fetchColumn();

    // Rooms: hostel_rooms.status is available/occupied/maintenance/reserved (no
    // 'active'), so count every room as the total stock + its total bed capacity.
    $room_row = $pdo->query("SELECT COUNT(*) AS rooms, COALESCE(SUM(capacity),0) AS cap FROM hostel_rooms")->fetch(PDO::FETCH_ASSOC);
    $total_rooms = (int)$room_row['rooms'];
    $total_capacity = (int)$room_row['cap'];

    // Occupied rooms = distinct rooms with an active allocation
    $occupied_rooms = (int)$pdo->query("SELECT COUNT(DISTINCT room_id) FROM hostel_allocations WHERE status = 'active'")->fetchColumn();
    $available_rooms = max(0, $total_rooms - $occupied_rooms);

    // Students currently allocated a bed
    $allocated_students = (int)$pdo->query("SELECT COUNT(*) FROM hostel_allocations WHERE status = 'active'")->fetchColumn();

    // Pending maintenance requests
    $pending_requests = (int)$pdo->query("SELECT COUNT(*) FROM hostel_maintenance WHERE status = 'pending'")->fetchColumn();

    // Chart 1: capacity vs allocated students, per block
    $block_rows = $pdo->query("SELECT hb.name,
                                      COALESCE(SUM(hr.capacity),0) AS cap,
                                      COUNT(ha.id) AS allocated
                               FROM hostel_blocks hb
                               LEFT JOIN hostel_rooms hr ON hb.id = hr.block_id
                               LEFT JOIN hostel_allocations ha ON hr.id = ha.room_id AND ha.status = 'active'
                               GROUP BY hb.id, hb.name
                               ORDER BY hb.name")->fetchAll(PDO::FETCH_ASSOC);
    $block_labels   = json_encode(array_column($block_rows, 'name'));
    $block_capacity = json_encode(array_map('intval', array_column($block_rows, 'cap')));
    $block_occupied = json_encode(array_map('intval', array_column($block_rows, 'allocated')));

    // Chart 2: blocks by type
    $type_rows = $pdo->query("SELECT block_type, COUNT(*) AS cnt FROM hostel_blocks GROUP BY block_type")->fetchAll(PDO::FETCH_ASSOC);
    $type_labels = json_encode(array_map('ucfirst', array_column($type_rows, 'block_type')));
    $type_values = json_encode(array_map('intval', array_column($type_rows, 'cnt')));

    // Recent allocations
    $stmt = $pdo->prepare("
        SELECT ha.*, s.name as student_name, s.student_id, hr.room_number, hb.name as block_name
        FROM hostel_allocations ha
        JOIN students s ON ha.student_id = s.id
        JOIN hostel_rooms hr ON ha.room_id = hr.id
        JOIN hostel_blocks hb ON hr.block_id = hb.id
        WHERE ha.status = 'active'
        ORDER BY ha.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_allocations = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Hostel dashboard error: ' . $e->getMessage());
}

// Bed-level occupancy across the whole hostel
$occupied_beds = $allocated_students;
$free_beds = max(0, $total_capacity - $occupied_beds);
$occupancy_rate = $total_capacity > 0 ? round(($occupied_beds / $total_capacity) * 100, 1) : 0;
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
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
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-building mr-1.5"></i> Hostel Management</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Manage hostel blocks, rooms and student accommodations.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-chart-pie mr-2"></i><?php echo $occupancy_rate; ?>% occupancy</span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-bed text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Cards -->
<section class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4 mb-6" aria-label="Hostel statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Blocks', 'value' => $total_blocks, 'icon' => 'fa-building', 'color' => 'blue', 'hint' => ''],
        ['label' => 'Total Rooms', 'value' => $total_rooms, 'icon' => 'fa-door-open', 'color' => 'emerald', 'hint' => ''],
        ['label' => 'Occupied Rooms', 'value' => $occupied_rooms, 'icon' => 'fa-bed', 'color' => 'amber', 'hint' => $occupancy_rate . '% occupancy'],
        ['label' => 'Available Rooms', 'value' => $available_rooms, 'icon' => 'fa-door-closed', 'color' => 'violet', 'hint' => ''],
        ['label' => 'Allocated Students', 'value' => $allocated_students, 'icon' => 'fa-users', 'color' => 'indigo', 'hint' => ''],
        ['label' => 'Pending Maintenance', 'value' => $pending_requests, 'icon' => 'fa-tools', 'color' => 'rose', 'hint' => ''],
    ];
    foreach ($summary_cards as $card):
        $c = $color_map[$card['color']];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-5 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200">
        <div class="flex items-center gap-3">
            <div class="w-11 h-11 <?php echo $c['bg']; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-lg"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?php echo $card['label']; ?></p>
                <p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <?php if ($card['hint']): ?><p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p><?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Recent Allocations -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
    <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
        <i class="fas fa-bed text-blue-500"></i> Recent Room Allocations
    </h3>
    <?php if (empty($recent_allocations)): ?>
        <div class="text-center py-8">
            <i class="fas fa-bed text-gray-300 dark:text-gray-600 text-4xl mb-3"></i>
            <p class="text-sm text-gray-500 dark:text-gray-400">No recent allocations found</p>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($recent_allocations as $allocation): ?>
                <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center">
                            <i class="fas fa-user text-blue-600 dark:text-blue-400"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['student_name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($allocation['student_id']); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['block_name']); ?> &middot; Room <?php echo htmlspecialchars($allocation['room_number']); ?></p>
                        <p class="text-[11px] text-gray-400 dark:text-gray-500"><?php echo date('M j, Y', strtotime($allocation['created_at'])); ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Hostel analytics">
    <!-- Capacity vs Allocated per Block -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-building text-blue-500"></i> Capacity vs Allocated by Block
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Total bed capacity against students currently allocated</p>
        <div class="relative h-72"><canvas id="blockOccupancyChart"></canvas></div>
    </div>
    <!-- Blocks by Type -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-venus-mars text-violet-500"></i> Blocks by Type
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Distribution of hostel blocks</p>
        <div class="relative h-72"><canvas id="blockTypeChart"></canvas></div>
    </div>
</section>

<!-- Bed Occupancy -->
<section class="mb-6" aria-label="Bed occupancy">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex flex-col md:flex-row md:items-center gap-6">
            <div class="md:w-1/3">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
                    <i class="fas fa-bed text-emerald-500"></i> Overall Bed Occupancy
                </h3>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Across all <?php echo number_format($total_capacity); ?> beds</p>
                <div class="space-y-2">
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="w-3 h-3 rounded-full bg-emerald-500"></span> Occupied</span>
                        <span class="font-bold text-gray-900 dark:text-white"><?php echo number_format($occupied_beds); ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 text-gray-600 dark:text-gray-300"><span class="w-3 h-3 rounded-full bg-gray-300 dark:bg-gray-600"></span> Available</span>
                        <span class="font-bold text-gray-900 dark:text-white"><?php echo number_format($free_beds); ?></span>
                    </div>
                    <div class="flex items-center justify-between text-sm pt-2 border-t border-gray-100 dark:border-gray-700">
                        <span class="text-gray-600 dark:text-gray-300">Occupancy rate</span>
                        <span class="font-bold text-emerald-600 dark:text-emerald-400"><?php echo $occupancy_rate; ?>%</span>
                    </div>
                </div>
            </div>
            <div class="md:w-2/3 relative h-64"><canvas id="bedOccupancyChart"></canvas></div>
        </div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-1 sm:grid-cols-3 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => 'hostel/blocks/index.php', 'icon' => 'fa-building', 'color' => 'blue', 'title' => 'Manage Blocks', 'desc' => 'Add, edit and manage blocks'],
        ['href' => 'hostel/allocations/index.php', 'icon' => 'fa-bed', 'color' => 'emerald', 'title' => 'Room Allocations', 'desc' => 'Assign students to rooms'],
        ['href' => 'hostel/maintenance/index.php', 'icon' => 'fa-tools', 'color' => 'amber', 'title' => 'Maintenance', 'desc' => 'Handle maintenance requests'],
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
    function buildHostelCharts() {
        if (typeof Chart === 'undefined') { setTimeout(buildHostelCharts, 100); return; }
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

        // Capacity vs Allocated by block (grouped bar)
        var boEl = document.getElementById('blockOccupancyChart');
        var boLabels = <?php echo $block_labels; ?>;
        if (boEl) {
            if (!boLabels.length) {
                noData(boEl, 'fa-building', 'No block data');
            } else {
                new Chart(boEl.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels: boLabels,
                        datasets: [
                            { label: 'Capacity', data: <?php echo $block_capacity; ?>, backgroundColor: '#bfdbfe', borderRadius: 6, maxBarThickness: 30 },
                            { label: 'Allocated', data: <?php echo $block_occupied; ?>, backgroundColor: '#3b82f6', borderRadius: 6, maxBarThickness: 30 }
                        ]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: tickColor } },
                            y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, precision: 0 } }
                        }
                    }
                });
            }
        }

        // Blocks by type (doughnut)
        var btEl = document.getElementById('blockTypeChart');
        var btValues = <?php echo $type_values; ?>;
        if (btEl) {
            if (!btValues.length) {
                noData(btEl, 'fa-venus-mars', 'No block data');
            } else {
                new Chart(btEl.getContext('2d'), {
                    type: 'doughnut',
                    data: { labels: <?php echo $type_labels; ?>, datasets: [{ data: btValues, backgroundColor: ['#3b82f6', '#ec4899', '#8b5cf6', '#f59e0b'], borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff' }] },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '62%',
                        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } } }
                    }
                });
            }
        }

        // Overall bed occupancy (doughnut)
        var bedEl = document.getElementById('bedOccupancyChart');
        if (bedEl) {
            new Chart(bedEl.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: ['Occupied', 'Available'],
                    datasets: [{ data: [<?php echo (int)$occupied_beds; ?>, <?php echo (int)$free_beds; ?>], backgroundColor: ['#10b981', isDark ? '#374151' : '#e5e7eb'], borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff' }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '70%',
                    plugins: { legend: { position: 'right', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } } }
                }
            });
        }
    }
    buildHostelCharts();
})();
</script>
