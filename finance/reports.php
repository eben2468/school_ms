<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';
require_once 'includes/report_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get date filters — default to a rolling 6-month window so the charts always
// have meaningful data (the previous "current month only" default left the
// revenue trend empty whenever no payment had landed yet this month).
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-01', strtotime('-5 months'));
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-t');

// Pick daily vs monthly grouping based on how wide the selected period is, so a
// short range stays granular while a long range stays readable.
$span_days = max(0, (strtotime($end_date) - strtotime($start_date)) / 86400);
$revenue_period = $span_days <= 62 ? 'daily' : 'monthly';

// Get report data
$revenue_data = getRevenueReport($revenue_period, $start_date, $end_date, $db);
$expense_data = getExpenseReport($start_date, $end_date, $db);
$pnl = getIncomeVsExpenseReport($start_date, $end_date, $db);
$students_owing = getStudentReport('owing', $db);

// Chart labels and values (human-friendly labels for the revenue trend)
$revenue_pretty_labels = array_map(function ($row) use ($revenue_period) {
    return $revenue_period === 'monthly'
        ? date('M Y', strtotime($row['label'] . '-01'))
        : date('M j', strtotime($row['label']));
}, $revenue_data);
$revenue_labels = json_encode($revenue_pretty_labels);
$revenue_values = json_encode(array_map('floatval', array_column($revenue_data, 'value')));

$expense_labels = json_encode(array_map(function ($l) { return ucwords(str_replace('_', ' ', $l)); }, array_column($expense_data, 'label')));
$expense_values = json_encode(array_map('floatval', array_column($expense_data, 'value')));

// Handle CSV export of the ledger/reports
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $data_to_export = [];
    if ($_GET['report_type'] === 'pnl') {
        $data_to_export = [
            ['Metric', 'Amount (₵)'],
            ['Fee Income', $pnl['fee_income']],
            ['Other Income', $pnl['other_income']],
            ['Total Income', $pnl['total_income']],
            ['Total Expenses', $pnl['total_expense']],
            ['Net Surplus/Deficit', $pnl['net_profit']]
        ];
    } else { // 'owing'
        $data_to_export[] = ['Student Name', 'Reg ID', 'Class', 'Total Invoiced', 'Total Paid', 'Outstanding Balance'];
        foreach ($students_owing as $s) {
            $data_to_export[] = [
                $s['student_name'],
                $s['student_reg_no'],
                $s['class_name'],
                $s['total_amount'] + $s['penalty_amount'] - $s['discount_amount'],
                $s['amount_paid'],
                $s['outstanding_balance']
            ];
        }
    }
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $_GET['report_type'] . '_report.csv');
    $out = fopen('php://output', 'w');
    foreach ($data_to_export as $line) {
        fputcsv($out, $line);
    }
    fclose($out);
    exit();
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;" x-data="{ currentTab: 'overview' }">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Reports & Analytics</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review school P&L ledger details, fee collection trends, and owing student directories</p>
                    </div>
                </div>

                <!-- Tabs navigation -->
                <div class="border-b border-gray-200 dark:border-gray-700 mb-8 flex space-x-6 text-sm font-semibold">
                    <button @click="currentTab = 'overview'" :class="currentTab === 'overview' ? 'text-green-600 border-b-2 border-green-600 pb-3' : 'text-gray-450 hover:text-gray-700 pb-3'">Collection Overview</button>
                    <button @click="currentTab = 'pnl'" :class="currentTab === 'pnl' ? 'text-green-600 border-b-2 border-green-600 pb-3' : 'text-gray-450 hover:text-gray-700 pb-3'">Profit & Loss (P&L)</button>
                    <button @click="currentTab = 'owing'" :class="currentTab === 'owing' ? 'text-green-600 border-b-2 border-green-600 pb-3' : 'text-gray-450 hover:text-gray-700 pb-3'">Owing Students</button>
                </div>

                <!-- Date Range Filter Card -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-5 py-2 rounded-xl shadow transition">
                            Filter Period
                        </button>
                    </form>
                </div>

                <!-- Tab 1: Collection Overview -->
                <div x-show="currentTab === 'overview'" class="space-y-8">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Revenue Trend (Selected Period)</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4"><?php echo date('M j, Y', strtotime($start_date)); ?> &ndash; <?php echo date('M j, Y', strtotime($end_date)); ?> &middot; <?php echo ucfirst($revenue_period); ?></p>
                            <div class="relative h-72">
                                <canvas id="periodRevenueChart"></canvas>
                            </div>
                        </div>
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Approved Expenses by Category</h3>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mb-4"><?php echo date('M j, Y', strtotime($start_date)); ?> &ndash; <?php echo date('M j, Y', strtotime($end_date)); ?></p>
                            <div class="relative h-72">
                                <canvas id="periodExpenseChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: Profit & Loss (P&L) -->
                <div x-show="currentTab === 'pnl'" class="space-y-6">
                    <div class="flex justify-end gap-2">
                        <a href="print_report.php?report_type=pnl&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition flex items-center gap-1.5">
                            <i class="fas fa-print"></i> Print P&L Statement
                        </a>
                        <a href="reports.php?export=csv&report_type=pnl&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2 rounded-xl text-sm transition">Export CSV P&L</a>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Income Category</th>
                                    <th class="p-4 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <tr>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300">Fee Payment Collections</td>
                                    <td class="p-4 text-right font-bold text-emerald-600"><?php echo formatFinanceCurrency($pnl['fee_income'], $db); ?></td>
                                </tr>
                                <tr>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300">Other Non-Fee Revenue</td>
                                    <td class="p-4 text-right font-bold text-emerald-600"><?php echo formatFinanceCurrency($pnl['other_income'], $db); ?></td>
                                </tr>
                                <tr class="bg-gray-50/50 dark:bg-gray-900/10 font-bold">
                                    <td class="p-4">Total Revenue (A)</td>
                                    <td class="p-4 text-right text-emerald-650"><?php echo formatFinanceCurrency($pnl['total_income'], $db); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Expense Category</th>
                                    <th class="p-4 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($expense_data as $exp): ?>
                                <tr>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300 capitalize"><?php echo str_replace('_', ' ', $exp['label']); ?></td>
                                    <td class="p-4 text-right font-bold text-rose-500"><?php echo formatFinanceCurrency($exp['value'], $db); ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <tr class="bg-gray-50/50 dark:bg-gray-900/10 font-bold">
                                    <td class="p-4">Total Expenditures (B)</td>
                                    <td class="p-4 text-right text-rose-600"><?php echo formatFinanceCurrency($pnl['total_expense'], $db); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex justify-between items-center">
                        <span class="text-lg font-bold text-gray-800 dark:text-white">Net Operating Surplus/Deficit (A - B)</span>
                        <span class="text-2xl font-black <?php echo $pnl['net_profit'] >= 0 ? 'text-emerald-600' : 'text-rose-600'; ?>">
                            <?php echo formatFinanceCurrency($pnl['net_profit'], $db); ?>
                        </span>
                    </div>
                </div>

                <!-- Tab 3: Owing Students -->
                <div x-show="currentTab === 'owing'" class="space-y-6">
                    <div class="flex justify-end gap-2">
                        <a href="print_report.php?report_type=owing" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-xl text-sm transition flex items-center gap-1.5">
                            <i class="fas fa-print"></i> Print Owing List
                        </a>
                        <a href="reports.php?export=csv&report_type=owing" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2 rounded-xl text-sm transition">Export CSV Directory</a>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                        <th class="p-4">Student</th>
                                        <th class="p-4">Class</th>
                                        <th class="p-4">Total Charged</th>
                                        <th class="p-4">Total Paid</th>
                                        <th class="p-4">Outstanding Balance</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                    <?php foreach ($students_owing as $s): ?>
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                        <td class="p-4">
                                            <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($s['student_name']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($s['student_reg_no']); ?></div>
                                        </td>
                                        <td class="p-4 text-gray-750 dark:text-gray-300 font-semibold"><?php echo htmlspecialchars($s['class_name'] ?: 'Unassigned'); ?></td>
                                        <td class="p-4 font-semibold text-gray-655"><?php echo formatFinanceCurrency($s['total_amount'] + $s['penalty_amount'] - $s['discount_amount'], $db); ?></td>
                                        <td class="p-4 font-semibold text-emerald-600"><?php echo formatFinanceCurrency($s['amount_paid'], $db); ?></td>
                                        <td class="p-4 font-bold text-rose-500"><?php echo formatFinanceCurrency($s['outstanding_balance'], $db); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    function init() {
        if (typeof Chart === 'undefined') { setTimeout(init, 100); return; }
        var isDark = document.documentElement.classList.contains('dark');
        var gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        var tickColor = isDark ? '#9ca3af' : '#6b7280';
        Chart.defaults.color = tickColor;
        var cedi = function (v) { return '₵' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

        function emptyState(canvas, icon, msg) {
            canvas.parentElement.innerHTML =
                '<div class="flex flex-col items-center justify-center h-full text-gray-400 dark:text-gray-500">' +
                '<i class="fas ' + icon + ' text-4xl mb-3"></i><p class="text-sm">' + msg + '</p></div>';
        }

        // Line Chart: Period Revenue Trend
        var revEl = document.getElementById('periodRevenueChart');
        var revValues = <?php echo $revenue_values; ?>;
        if (revEl) {
            if (!revValues.length) {
                emptyState(revEl, 'fa-chart-line', 'No collections in this period');
            } else {
                var ctx = revEl.getContext('2d');
                var grad = ctx.createLinearGradient(0, 0, 0, 288);
                grad.addColorStop(0, 'rgba(16,185,129,0.25)');
                grad.addColorStop(1, 'rgba(16,185,129,0)');
                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: <?php echo $revenue_labels; ?>,
                        datasets: [{
                            label: 'Collected', data: revValues,
                            borderColor: '#10b981', backgroundColor: grad,
                            borderWidth: 3, fill: true, tension: 0.4,
                            pointBackgroundColor: '#10b981', pointRadius: 4, pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (c) { return cedi(c.parsed.y); } } } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: tickColor, maxRotation: 0, autoSkip: true } },
                            y: { beginAtZero: true, grid: { color: gridColor }, ticks: { color: tickColor, callback: function (v) { return '₵' + Number(v).toLocaleString(); } } }
                        }
                    }
                });
            }
        }

        // Doughnut Chart: Period Expense Distribution
        var expEl = document.getElementById('periodExpenseChart');
        var expValues = <?php echo $expense_values; ?>;
        if (expEl) {
            if (!expValues.length) {
                emptyState(expEl, 'fa-receipt', 'No approved expenses in this period');
            } else {
                new Chart(expEl.getContext('2d'), {
                    type: 'doughnut',
                    data: {
                        labels: <?php echo $expense_labels; ?>,
                        datasets: [{
                            data: expValues,
                            backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#10b981', '#8b5cf6', '#ec4899', '#06b6d4', '#6b7280'],
                            borderWidth: 2, borderColor: isDark ? '#1f2937' : '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true, maintainAspectRatio: false, cutout: '60%',
                        plugins: {
                            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8, padding: 14 } },
                            tooltip: { callbacks: { label: function (c) { return c.label + ': ' + cedi(c.parsed); } } }
                        }
                    }
                });
            }
        }
    }
    init();
})();
</script>

