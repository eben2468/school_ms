<?php
// Canteen Manager Dashboard Content
// Included by /dashboard.php. Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Canteen Manager';
$user_email = $_SESSION['email'] ?? '';

// Get canteen statistics (live data)
$total_menu_items = 0;
$todays_orders = 0;
$revenue_today = 0;
$pending_orders = 0;
$inventory_alerts = 0;
$popular_items = [];
$low_stock_items = [];
$weekly_sales = ['labels' => [], 'revenue' => [], 'orders' => []];
$category_sales = ['labels' => [], 'data' => []];
try {
    $total_menu_items = (int)$db->query("SELECT COUNT(*) FROM canteen_menu WHERE date = CURDATE() AND status = 'available'")->fetchColumn();
    $todays_orders    = (int)$db->query("SELECT COUNT(*) FROM canteen_orders WHERE order_date = CURDATE()")->fetchColumn();
    $revenue_today    = (float)($db->query("SELECT COALESCE(SUM(total_price),0) FROM canteen_orders WHERE order_date = CURDATE()")->fetchColumn());
    $pending_orders   = (int)$db->query("SELECT COUNT(*) FROM canteen_orders WHERE order_date = CURDATE() AND status = 'pending'")->fetchColumn();
    $inventory_alerts = (int)$db->query("SELECT COUNT(*) FROM canteen_inventory WHERE quantity <= 10")->fetchColumn();

    // Most-ordered menu items today
    $popular_items = $db->query("SELECT cm.item_name, cm.price, cm.meal_type,
            COUNT(co.id) AS order_count, COALESCE(SUM(co.quantity),0) AS qty_sold
        FROM canteen_menu cm
        JOIN canteen_orders co ON co.menu_id = cm.id AND co.order_date = CURDATE()
        GROUP BY cm.id, cm.item_name, cm.price, cm.meal_type
        ORDER BY order_count DESC, qty_sold DESC
        LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

    // Low-stock inventory items needing attention
    $low_stock_items = $db->query("SELECT item_name, quantity, COALESCE(unit, '') AS unit
        FROM canteen_inventory
        WHERE quantity <= 10
        ORDER BY quantity ASC
        LIMIT 4")->fetchAll(PDO::FETCH_ASSOC);

    // Last 7 days revenue + order counts (chart). Build a continuous 7-day
    // series so days with no orders still render as zero.
    $by_date = [];
    foreach ($db->query("SELECT order_date, COUNT(*) AS orders, COALESCE(SUM(total_price),0) AS revenue
        FROM canteen_orders
        WHERE order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY order_date")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $by_date[$r['order_date']] = $r;
    }
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i day"));
        $weekly_sales['labels'][]  = date('D', strtotime($d));
        $weekly_sales['orders'][]  = isset($by_date[$d]) ? (int)$by_date[$d]['orders'] : 0;
        $weekly_sales['revenue'][] = isset($by_date[$d]) ? (float)$by_date[$d]['revenue'] : 0;
    }

    // Orders by meal type this week (chart)
    foreach ($db->query("SELECT cm.meal_type, COUNT(co.id) AS cnt
        FROM canteen_orders co
        JOIN canteen_menu cm ON co.menu_id = cm.id
        WHERE co.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        GROUP BY cm.meal_type
        ORDER BY cnt DESC")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $category_sales['labels'][] = ucfirst($r['meal_type'] ?: 'Other');
        $category_sales['data'][]   = (int)$r['cnt'];
    }
} catch (PDOException $e) {
    error_log('Canteen dashboard data error: ' . $e->getMessage());
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
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-utensils mr-1.5"></i> Canteen Services</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Manage food services, orders and inventory.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-hamburger text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Statistics Cards -->
<section class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6" aria-label="Canteen statistics">
    <?php
    $summary_cards = [
        ['label' => 'Menu Items', 'value' => $total_menu_items, 'icon' => 'fa-utensils', 'color' => 'blue'],
        ['label' => "Today's Orders", 'value' => $todays_orders, 'icon' => 'fa-shopping-cart', 'color' => 'emerald'],
        ['label' => 'Revenue Today', 'value' => '₵' . number_format($revenue_today, 2), 'icon' => 'fa-money-bill-wave', 'color' => 'violet'],
        ['label' => 'Pending Orders', 'value' => $pending_orders, 'icon' => 'fa-clock', 'color' => 'amber'],
        ['label' => 'Inventory Alerts', 'value' => $inventory_alerts, 'icon' => 'fa-exclamation-triangle', 'color' => 'rose'],
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

<!-- Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Canteen analytics">
    <!-- Weekly Sales -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-area text-emerald-500"></i> Weekly Sales &amp; Orders
            </h3>
            <span class="text-xs text-gray-500 dark:text-gray-400">Last 7 days</span>
        </div>
        <div class="h-64"><canvas id="canteenSalesChart"></canvas></div>
    </div>
    <!-- Sales by Category -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-chart-pie text-violet-500"></i> Sales by Category
            </h3>
        </div>
        <div class="h-64"><canvas id="canteenCategoryChart"></canvas></div>
    </div>
</section>

<!-- Popular Items & Inventory Alerts -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Popular Items -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-fire text-amber-500"></i> Popular Items Today
        </h3>
        <div class="space-y-3">
            <?php if (!empty($popular_items)): ?>
            <?php
            $meal_icons = ['breakfast' => 'fa-mug-hot', 'lunch' => 'fa-utensils', 'dinner' => 'fa-drumstick-bite', 'snack' => 'fa-cookie-bite', 'drink' => 'fa-coffee'];
            $meal_colors = ['blue', 'amber', 'rose', 'emerald'];
            foreach ($popular_items as $i => $item):
                $c = $color_map[$meal_colors[$i % count($meal_colors)]];
                $icon = $meal_icons[strtolower($item['meal_type'] ?? '')] ?? 'fa-utensils';
            ?>
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 <?php echo $c['bg']; ?> rounded-lg flex items-center justify-center">
                        <i class="fas <?php echo $icon; ?> <?php echo $c['text']; ?>"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($item['item_name']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">₵<?php echo number_format((float)$item['price'], 2); ?></p>
                    </div>
                </div>
                <span class="text-sm font-semibold text-emerald-600 dark:text-emerald-400"><?php echo (int)$item['qty_sold']; ?> sold</span>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="text-center py-8">
                <i class="fas fa-utensils text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">No orders recorded today yet</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Inventory Alerts -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                <i class="fas fa-boxes text-rose-500"></i> Inventory Alerts
            </h3>
            <a href="/canteen/inventory/index.php" class="text-xs text-rose-600 dark:text-rose-400 hover:underline">Manage</a>
        </div>
        <div class="space-y-3">
            <?php if (!empty($low_stock_items)): ?>
                <?php foreach ($low_stock_items as $inv):
                    $qty = (int)$inv['quantity'];
                    $bg = $qty === 0 ? 'bg-rose-50 dark:bg-rose-900/20' : 'bg-amber-50 dark:bg-amber-900/20';
                    $iconBg = $qty === 0 ? 'bg-rose-100 dark:bg-rose-900/50' : 'bg-amber-100 dark:bg-amber-900/50';
                    $iconText = $qty === 0 ? 'text-rose-600 dark:text-rose-400' : 'text-amber-600 dark:text-amber-400';
                    $label = $qty === 0 ? 'Out of Stock' : 'Low Stock';
                ?>
                <div class="flex items-center gap-3 p-3 <?php echo $bg; ?> rounded-xl">
                    <div class="w-9 h-9 <?php echo $iconBg; ?> rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-exclamation <?php echo $iconText; ?> text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo $label; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($inv['item_name']); ?> &mdash; <?php echo $qty; ?> <?php echo htmlspecialchars($inv['unit'] ?: 'units'); ?> left
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-check-circle text-4xl text-green-300 dark:text-green-600 mb-3"></i>
                    <p class="text-sm text-gray-500 dark:text-gray-400">All inventory items are well stocked</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => '/canteen/menu/index.php', 'icon' => 'fa-utensils', 'color' => 'blue', 'title' => 'Manage Menu', 'desc' => 'Add and organize items'],
        ['href' => '/canteen/orders/index.php', 'icon' => 'fa-shopping-cart', 'color' => 'emerald', 'title' => 'View Orders', 'desc' => 'Process and track orders'],
        ['href' => '/canteen/inventory/index.php', 'icon' => 'fa-boxes', 'color' => 'violet', 'title' => 'Inventory', 'desc' => 'Manage stock and supplies'],
        ['href' => '/canteen/reports/index.php', 'icon' => 'fa-chart-bar', 'color' => 'amber', 'title' => 'Sales Reports', 'desc' => 'View sales and analytics'],
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
// Live last-7-days sales and meal-type breakdown from the server.
const SALES = <?php echo json_encode($weekly_sales, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const CATEGORIES = <?php echo json_encode([
    'labels' => $category_sales['labels'],
    'data'   => $category_sales['data'],
    'colors' => ['#3b82f6', '#f59e0b', '#10b981', '#ec4899', '#8b5cf6']
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let salesChart = null, catChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const sales = document.getElementById('canteenSalesChart');
        if (sales) {
            if (salesChart) salesChart.destroy();
            salesChart = new Chart(sales, {
                data: {
                    labels: SALES.labels,
                    datasets: [
                        { type: 'line', label: 'Revenue (₵)', data: SALES.revenue, borderColor: '#10b981',
                          backgroundColor: 'rgba(16,185,129,0.12)', borderWidth: 3, fill: true, tension: 0.4,
                          pointBackgroundColor: '#10b981', pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 4, yAxisID: 'y' },
                        { type: 'bar', label: 'Orders', data: SALES.orders, backgroundColor: 'rgba(59,130,246,0.55)',
                          borderRadius: 6, maxBarThickness: 22, yAxisID: 'y1' }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 15, boxWidth: 12, color: tick, font: { size: 11 } } },
                               tooltip: { mode: 'index', intersect: false, cornerRadius: 8 } },
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { position: 'left', beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 } } },
                        y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                    }
                }
            });
        }

        const cat = document.getElementById('canteenCategoryChart');
        if (cat) {
            if (catChart) catChart.destroy();
            catChart = new Chart(cat, {
                type: 'doughnut',
                data: { labels: CATEGORIES.labels.length ? CATEGORIES.labels : ['No orders'],
                        datasets: [{ data: CATEGORIES.data.length ? CATEGORIES.data : [1],
                        backgroundColor: CATEGORIES.labels.length ? CATEGORIES.colors : ['#e5e7eb'],
                        borderColor: isDark ? '#1f2937' : '#fff', borderWidth: 3, hoverOffset: 6 }] },
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
