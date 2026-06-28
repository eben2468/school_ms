<?php
// Accountant Dashboard Content
// Included by /dashboard.php. Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
$user_name = $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Accountant';
$user_email = $_SESSION['email'] ?? '';

// ---- Real financial statistics (from the finance_* tables) ----
if (!function_exists('acc_time_ago')) {
    function acc_time_ago($datetime) {
        $ts = strtotime($datetime);
        if (!$ts) return '';
        $diff = time() - $ts;
        if ($diff < 60) return 'just now';
        if ($diff < 3600) return floor($diff / 60) . ' min ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        if ($diff < 172800) return 'yesterday';
        if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
        return date('M j, Y', $ts);
    }
}

$cur_month  = date('Y-m');
$last_month = date('Y-m', strtotime('first day of last month'));

// Defaults so the dashboard still renders if the finance module has no data/tables.
$total_revenue = $monthly_revenue = $last_month_revenue = 0.0;
$pending_payments = $outstanding_fees = $processed_today = 0.0;
$expenses_month = $last_expenses_month = $total_expenses = 0.0;
$pending_count = $processed_today_count = $students_owing = 0;
$chart_labels = $chart_income = $chart_expense = '[]';
$method_labels = $method_values = '[]';
$recent_tx = [];

try {
    // Revenue (all collected payments) and month-over-month change
    $total_revenue       = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_payments")->fetchColumn();
    $monthly_revenue     = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_payments WHERE DATE_FORMAT(payment_date,'%Y-%m') = '$cur_month'")->fetchColumn();
    $last_month_revenue  = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_payments WHERE DATE_FORMAT(payment_date,'%Y-%m') = '$last_month'")->fetchColumn();

    // Payments processed today
    $processed_today       = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();
    $processed_today_count = (int)$db->query("SELECT COUNT(*) FROM finance_payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();

    // Pending payments = outstanding balance on unpaid/partial/overdue invoices
    $pending_payments = (float)$db->query("SELECT COALESCE(SUM(total_amount + penalty_amount - discount_amount - amount_paid),0) FROM finance_invoices WHERE status IN ('pending','partially_paid','overdue')")->fetchColumn();
    $pending_count    = (int)$db->query("SELECT COUNT(*) FROM finance_invoices WHERE status IN ('pending','partially_paid','overdue')")->fetchColumn();

    // Outstanding fees across all active invoices + number of students owing
    $outstanding_fees = (float)$db->query("SELECT COALESCE(SUM(total_amount + penalty_amount - discount_amount - amount_paid),0) FROM finance_invoices WHERE status != 'cancelled'")->fetchColumn();
    $students_owing   = (int)$db->query("SELECT COUNT(DISTINCT student_id) FROM finance_invoices WHERE status IN ('pending','partially_paid','overdue')")->fetchColumn();

    // Expenses (approved) — this month, last month, all-time
    $expenses_month      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE status='approved' AND DATE_FORMAT(expense_date,'%Y-%m') = '$cur_month'")->fetchColumn();
    $last_expenses_month = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE status='approved' AND DATE_FORMAT(expense_date,'%Y-%m') = '$last_month'")->fetchColumn();
    $total_expenses      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM finance_expenses WHERE status='approved'")->fetchColumn();

    // ---- Chart 1 & 2: last 6 months income vs expenses ----
    $months = [];
    for ($i = 5; $i >= 0; $i--) {
        $key = date('Y-m', strtotime("first day of -$i month"));
        $months[$key] = ['label' => date('M', strtotime($key . '-01')), 'income' => 0.0, 'expense' => 0.0];
    }
    foreach ($db->query("SELECT DATE_FORMAT(payment_date,'%Y-%m') ym, SUM(amount) t FROM finance_payments GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($months[$r['ym']])) $months[$r['ym']]['income'] = (float)$r['t'];
    }
    foreach ($db->query("SELECT DATE_FORMAT(expense_date,'%Y-%m') ym, SUM(amount) t FROM finance_expenses WHERE status='approved' GROUP BY ym")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($months[$r['ym']])) $months[$r['ym']]['expense'] = (float)$r['t'];
    }
    $chart_labels  = json_encode(array_column(array_values($months), 'label'));
    $chart_income  = json_encode(array_column(array_values($months), 'income'));
    $chart_expense = json_encode(array_column(array_values($months), 'expense'));

    // ---- Chart 3: payment method distribution ----
    $method_data   = $db->query("SELECT payment_method, SUM(amount) total FROM finance_payments GROUP BY payment_method")->fetchAll(PDO::FETCH_ASSOC);
    $method_labels = json_encode(array_map(fn($m) => ucwords(str_replace('_', ' ', $m)), array_column($method_data, 'payment_method')));
    $method_values = json_encode(array_map('floatval', array_column($method_data, 'total')));

    // ---- Recent transactions: latest payments (in) + expenses (out), merged ----
    $rows = $db->query("SELECT p.amount, p.payment_date AS dt, u.name AS who, sp.student_id AS reg_no
                        FROM finance_payments p
                        JOIN finance_invoices i ON p.invoice_id = i.id
                        JOIN users u ON i.student_id = u.id
                        LEFT JOIN student_profiles sp ON u.id = sp.user_id
                        ORDER BY p.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $recent_tx[] = [
            'type' => 'in', 'title' => 'Fee Payment',
            'sub'  => trim($r['who'] . ' - ' . ($r['reg_no'] ?? '')),
            'amt'  => '+₵' . number_format((float)$r['amount'], 2),
            'time' => acc_time_ago($r['dt']), 'ts' => strtotime($r['dt']),
        ];
    }
    foreach ($db->query("SELECT amount, expense_date AS dt, category, vendor FROM finance_expenses WHERE status='approved' ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $recent_tx[] = [
            'type' => 'out', 'title' => ucfirst($r['category']),
            'sub'  => $r['vendor'] ?: 'Operational expense',
            'amt'  => '-₵' . number_format((float)$r['amount'], 2),
            'time' => acc_time_ago($r['dt']), 'ts' => strtotime($r['dt']),
        ];
    }
    usort($recent_tx, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $recent_tx = array_slice($recent_tx, 0, 5);
} catch (PDOException $e) {
    error_log('Accountant dashboard finance error: ' . $e->getMessage());
}

// Derived figures
$net_profit = $total_revenue - $total_expenses;
$rev_change = $last_month_revenue > 0
    ? round((($monthly_revenue - $last_month_revenue) / $last_month_revenue) * 100, 1)
    : ($monthly_revenue > 0 ? 100 : 0);
$exp_change = $last_expenses_month > 0
    ? round((($expenses_month - $last_expenses_month) / $last_expenses_month) * 100, 1)
    : ($expenses_month > 0 ? 100 : 0);
$fmt_pct = fn($v) => ($v >= 0 ? '+' : '') . $v . '% from last month';
?>

<!-- Page Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-white/80 text-sm font-medium mb-1"><i class="fas fa-coins mr-1.5"></i> Finance Office</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-white/85 text-sm sm:text-base">Manage school finances, payments and accounting.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/85">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-calculator text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Financial Overview Cards -->
<section class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-6" aria-label="Financial summary">
    <?php
    $summary_cards = [
        ['label' => 'Total Revenue', 'value' => '₵' . number_format($total_revenue, 2), 'icon' => 'fa-chart-line', 'color' => 'emerald', 'hint' => $fmt_pct($rev_change) . ' (revenue)'],
        ['label' => 'Pending Payments', 'value' => '₵' . number_format($pending_payments, 2), 'icon' => 'fa-clock', 'color' => 'amber', 'hint' => number_format($pending_count) . ' invoices'],
        ['label' => 'Monthly Expenses', 'value' => '₵' . number_format($expenses_month, 2), 'icon' => 'fa-money-bill-wave', 'color' => 'rose', 'hint' => $fmt_pct($exp_change)],
        ['label' => 'Outstanding Fees', 'value' => '₵' . number_format($outstanding_fees, 2), 'icon' => 'fa-exclamation-triangle', 'color' => 'violet', 'hint' => number_format($students_owing) . ' students'],
        ['label' => 'Processed Today', 'value' => '₵' . number_format($processed_today, 2), 'icon' => 'fa-check-circle', 'color' => 'blue', 'hint' => number_format($processed_today_count) . ' transactions'],
    ];
    $color_map = [
        'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
        'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
        'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
        'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
        'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
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
                <p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Financial Summary & Recent Transactions -->
<section class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Monthly Financial Summary -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-chart-pie text-blue-500"></i> Monthly Financial Summary
        </h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between p-3 bg-emerald-50 dark:bg-emerald-900/20 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-emerald-100 dark:bg-emerald-900/50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-up text-emerald-600 dark:text-emerald-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Total Income</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Tuition &amp; Other Fees</p>
                    </div>
                </div>
                <span class="text-base font-bold text-emerald-600 dark:text-emerald-400">₵<?php echo number_format($total_revenue, 2); ?></span>
            </div>
            <div class="flex items-center justify-between p-3 bg-rose-50 dark:bg-rose-900/20 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-rose-100 dark:bg-rose-900/50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-arrow-down text-rose-600 dark:text-rose-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Total Expenses</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Operational Costs</p>
                    </div>
                </div>
                <span class="text-base font-bold text-rose-600 dark:text-rose-400">₵<?php echo number_format($total_expenses, 2); ?></span>
            </div>
            <div class="flex items-center justify-between p-3 bg-blue-50 dark:bg-blue-900/20 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 bg-blue-100 dark:bg-blue-900/50 rounded-lg flex items-center justify-center">
                        <i class="fas fa-calculator text-blue-600 dark:text-blue-400"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Net Profit</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">This Month</p>
                    </div>
                </div>
                <span class="text-base font-bold text-blue-600 dark:text-blue-400">₵<?php echo number_format($net_profit, 2); ?></span>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
            <i class="fas fa-receipt text-violet-500"></i> Recent Transactions
        </h3>
        <div class="space-y-3">
            <?php if (empty($recent_tx)): ?>
            <div class="text-center py-8">
                <i class="fas fa-receipt text-4xl text-gray-300 dark:text-gray-600 mb-3"></i>
                <p class="text-sm text-gray-500 dark:text-gray-400">No transactions recorded yet</p>
            </div>
            <?php endif; ?>
            <?php
            foreach ($recent_tx as $tx):
                $isIn = $tx['type'] === 'in';
            ?>
            <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/40 rounded-xl">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 <?php echo $isIn ? 'bg-emerald-100 dark:bg-emerald-900/50' : 'bg-rose-100 dark:bg-rose-900/50'; ?> rounded-full flex items-center justify-center">
                        <i class="fas <?php echo $isIn ? 'fa-plus text-emerald-600 dark:text-emerald-400' : 'fa-minus text-rose-600 dark:text-rose-400'; ?> text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($tx['title']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($tx['sub']); ?></p>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm font-semibold <?php echo $isIn ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>"><?php echo $tx['amt']; ?></p>
                    <p class="text-[11px] text-gray-400 dark:text-gray-500"><?php echo $tx['time']; ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- Charts & Analytics -->
<section class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6" aria-label="Financial analytics">
    <!-- Income vs Expenses (Bar) -->
    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-chart-column text-emerald-500"></i> Income vs Expenses
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Collections compared with operational expenses over the last 6 months</p>
        <div class="relative h-72">
            <canvas id="incomeExpenseChart"></canvas>
        </div>
    </div>
    <!-- Payment Methods (Doughnut) -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-wallet text-violet-500"></i> Payment Methods
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Share of revenue by channel</p>
        <div class="relative h-72">
            <canvas id="paymentMethodChart"></canvas>
        </div>
    </div>
</section>

<!-- Revenue Trend (Line) -->
<section class="mb-6" aria-label="Revenue trend">
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6">
        <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-1 flex items-center gap-2">
            <i class="fas fa-chart-line text-blue-500"></i> Revenue Collection Trend
        </h3>
        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Monthly fee collections over the last 6 months</p>
        <div class="relative h-72">
            <canvas id="revenueTrendChart"></canvas>
        </div>
    </div>
</section>

<!-- Quick Actions -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4" aria-label="Quick actions">
    <?php
    $quick_actions = [
        ['href' => 'finance/collect_payment.php', 'icon' => 'fa-money-bill-wave', 'color' => 'emerald', 'title' => 'Process Payments', 'desc' => 'Handle fee payments'],
        ['href' => 'finance/expenses.php', 'icon' => 'fa-receipt', 'color' => 'rose', 'title' => 'Manage Expenses', 'desc' => 'Track and record expenses'],
        ['href' => 'finance/reports.php', 'icon' => 'fa-chart-bar', 'color' => 'blue', 'title' => 'Financial Reports', 'desc' => 'Generate statements'],
        ['href' => 'finance/fee_structures.php', 'icon' => 'fa-calculator', 'color' => 'violet', 'title' => 'Budget Planning', 'desc' => 'Plan fees &amp; budgets'],
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
    function buildCharts() {
        if (typeof Chart === 'undefined') { setTimeout(buildCharts, 100); return; }

        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var tickColor = isDark ? '#9ca3af' : '#6b7280';
        Chart.defaults.font.family = "'Inter', system-ui, sans-serif";
        Chart.defaults.color = tickColor;

        var cedi = function (v) { return '₵' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

        // Income vs Expenses (grouped bar)
        var ieEl = document.getElementById('incomeExpenseChart');
        if (ieEl) {
            new Chart(ieEl.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo $chart_labels; ?>,
                    datasets: [
                        { label: 'Income', data: <?php echo $chart_income; ?>, backgroundColor: '#10b981', borderRadius: 6, maxBarThickness: 26 },
                        { label: 'Expenses', data: <?php echo $chart_expense; ?>, backgroundColor: '#f43f5e', borderRadius: 6, maxBarThickness: 26 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'top', labels: { usePointStyle: true, boxWidth: 8 } },
                        tooltip: { callbacks: { label: function (c) { return c.dataset.label + ': ' + cedi(c.parsed.y); } } }
                    },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₵' + Number(v).toLocaleString(); } } }
                    }
                }
            });
        }

        // Payment methods (doughnut)
        var pmEl = document.getElementById('paymentMethodChart');
        if (pmEl) {
            var pmLabels = <?php echo $method_labels; ?>;
            if (pmLabels.length) {
                new Chart(pmEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: pmLabels,
                        datasets: [{ data: <?php echo $method_values; ?>, backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#06b6d4'], borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff' }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '62%',
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                            tooltip: { callbacks: { label: function (c) { return c.label + ': ' + cedi(c.parsed); } } }
                        }
                    }
                });
            } else {
                pmEl.parentElement.innerHTML = '<div class="flex flex-col items-center justify-center h-full text-gray-400"><i class="fas fa-wallet text-3xl mb-2"></i><p class="text-sm">No payment data</p></div>';
            }
        }

        // Revenue trend (line)
        var rtEl = document.getElementById('revenueTrendChart');
        if (rtEl) {
            var ctx = rtEl.getContext('2d');
            var grad = ctx.createLinearGradient(0, 0, 0, 280);
            grad.addColorStop(0, 'rgba(59,130,246,0.25)');
            grad.addColorStop(1, 'rgba(59,130,246,0)');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo $chart_labels; ?>,
                    datasets: [{ label: 'Collections', data: <?php echo $chart_income; ?>, borderColor: '#3b82f6', backgroundColor: grad, borderWidth: 3, fill: true, tension: 0.4, pointBackgroundColor: '#3b82f6', pointRadius: 4, pointHoverRadius: 6 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return cedi(c.parsed.y); } } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tickColor } },
                        y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₵' + Number(v).toLocaleString(); } } }
                    }
                }
            });
        }
    }
    buildCharts();
})();
</script>
