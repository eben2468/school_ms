<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once '../includes/signature_helper.php';
require_once '../finance/includes/finance_functions.php';
$database = new Database();
$db = $database->getConnection();

// School settings for the printable report
$school_name = getSchoolSetting('school_name', 'Greenwood Academy');
$school_address = getSchoolSetting('school_address', '');
$school_phone = getSchoolSetting('school_phone', '');
$school_email = getSchoolSetting('school_email', '');
$logo_url = getSchoolLogo();

// Payment method display + colors
$method_labels = [
    'cash' => 'Cash',
    'bank_transfer' => 'Bank Transfer',
    'mobile_money' => 'Mobile Money',
    'online' => 'Online',
    'other' => 'Other',
];
$method_badge = [
    'cash' => 'text-green-800 bg-green-100 dark:bg-green-900/40 dark:text-green-300',
    'bank_transfer' => 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300',
    'mobile_money' => 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300',
    'online' => 'text-purple-800 bg-purple-100 dark:bg-purple-900/40 dark:text-purple-300',
    'other' => 'text-gray-800 bg-gray-100 dark:bg-gray-700 dark:text-gray-300',
];

// ---- Filters ----
$selected_class  = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : '';
$selected_method = isset($_GET['method']) && isset($method_labels[$_GET['method']]) ? $_GET['method'] : '';

// Available payment date range for sensible defaults
$range_stmt = $db->query("SELECT MIN(DATE(payment_date)) AS mn, MAX(DATE(payment_date)) AS mx FROM finance_payments");
$range = $range_stmt->fetch(PDO::FETCH_ASSOC);
$data_min = $range['mn'] ?? date('Y-m-01');
$data_max = $range['mx'] ?? date('Y-m-d');

$from_date = isset($_GET['from_date']) && $_GET['from_date'] !== '' ? $_GET['from_date'] : $data_min;
$to_date   = isset($_GET['to_date']) && $_GET['to_date'] !== '' ? $_GET['to_date'] : $data_max;

// Validate dates
$d1 = DateTime::createFromFormat('Y-m-d', $from_date);
$d2 = DateTime::createFromFormat('Y-m-d', $to_date);
if (!$d1 || $d1->format('Y-m-d') !== $from_date) { $from_date = $data_min; }
if (!$d2 || $d2->format('Y-m-d') !== $to_date) { $to_date = $data_max; }
if ($from_date > $to_date) { $tmp = $from_date; $from_date = $to_date; $to_date = $tmp; }

// Classes for the filter dropdown
$classes = $db->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Shared FROM/WHERE for every query
$from_join = "
    FROM finance_payments p
    JOIN finance_invoices i ON p.invoice_id = i.id
    JOIN users u ON i.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN classes c ON sc.class_id = c.id
";
$where = " WHERE DATE(p.payment_date) BETWEEN :from_date AND :to_date ";
$params = [':from_date' => $from_date, ':to_date' => $to_date];
if ($selected_method !== '') { $where .= " AND p.payment_method = :method "; $params[':method'] = $selected_method; }
if ($selected_class !== '')  { $where .= " AND sc.class_id = :class_id "; $params[':class_id'] = $selected_class; }

// ---- Summary ----
$sum_stmt = $db->prepare("SELECT COUNT(*) AS payment_count, COALESCE(SUM(p.amount),0) AS total_collected,
    COUNT(DISTINCT i.student_id) AS students_paid $from_join $where");
$sum_stmt->execute($params);
$summary = $sum_stmt->fetch(PDO::FETCH_ASSOC);
$payment_count   = (int)$summary['payment_count'];
$total_collected = (float)$summary['total_collected'];
$students_paid   = (int)$summary['students_paid'];
$avg_payment     = $payment_count > 0 ? $total_collected / $payment_count : 0;

// ---- Daily collection trend ----
$trend_stmt = $db->prepare("SELECT DATE(p.payment_date) AS pay_date, SUM(p.amount) AS total $from_join $where
    GROUP BY DATE(p.payment_date) ORDER BY pay_date ASC");
$trend_stmt->execute($params);
$trend_data = $trend_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Collection by method ----
$method_stmt = $db->prepare("SELECT p.payment_method, COUNT(*) AS cnt, SUM(p.amount) AS total $from_join $where
    GROUP BY p.payment_method ORDER BY total DESC");
$method_stmt->execute($params);
$method_data = $method_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Transactions list ----
$txn_stmt = $db->prepare("SELECT p.receipt_number, p.payment_date, p.amount, p.payment_method, p.reference_number,
    i.invoice_number, u.name AS student_name, sp.student_id AS roll_number, c.name AS class_name
    $from_join $where
    ORDER BY p.payment_date DESC
    LIMIT 500");
$txn_stmt->execute($params);
$transactions = $txn_stmt->fetchAll(PDO::FETCH_ASSOC);

// Selected class name
$selected_class_name = 'All Classes';
foreach ($classes as $c) { if ($c['id'] == $selected_class) { $selected_class_name = $c['name']; break; } }
$selected_method_name = $selected_method !== '' ? $method_labels[$selected_method] : 'All Methods';

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fee_collection_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Fee Collection Report']);
    fputcsv($out, ['Class', $selected_class_name]);
    fputcsv($out, ['Method', $selected_method_name]);
    fputcsv($out, ['Period', $from_date . ' to ' . $to_date]);
    fputcsv($out, ['Total Collected', number_format($total_collected, 2)]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Date', 'Receipt #', 'Invoice #', 'Student', 'Student ID', 'Class', 'Method', 'Amount']);
    foreach ($transactions as $t) {
        fputcsv($out, [
            date('Y-m-d H:i', strtotime($t['payment_date'])),
            $t['receipt_number'],
            $t['invoice_number'],
            $t['student_name'],
            $t['roll_number'] ?? 'N/A',
            $t['class_name'] ?? 'N/A',
            $method_labels[$t['payment_method']] ?? $t['payment_method'],
            number_format((float)$t['amount'], 2),
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'class_id' => $selected_class,
    'method' => $selected_method,
    'from_date' => $from_date,
    'to_date' => $to_date,
    'export' => 'csv',
]);

$title = "Fee Collection Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Fee Collection Report']
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div id="web-layout" class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Fee Collection Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track fee payments received across methods, classes, and time.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($transactions) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($transactions) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                            <select name="class_id" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $selected_class === (int)$class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                            <select name="method" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Methods</option>
                                <?php foreach ($method_labels as $mkey => $mlabel): ?>
                                <option value="<?php echo $mkey; ?>" <?php echo $selected_method === $mkey ? 'selected' : ''; ?>><?php echo $mlabel; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">From Date</label>
                            <input type="date" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">To Date</label>
                            <input type="date" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>"
                                   class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_class_name); ?></span> &bull;
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_method_name); ?></span> &bull;
                        <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Collected</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo formatFinanceCurrency($total_collected, $db); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-money-bill-wave text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Payments</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($payment_count); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-receipt text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Average Payment</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo formatFinanceCurrency($avg_payment, $db); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calculator text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Students Paid</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($students_paid); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-check text-amber-600 dark:text-amber-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($payment_count > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Collection Trend</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Total amount collected per day</p>
                        <div class="relative" style="height: 260px;">
                            <canvas id="trendChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Collection by Method</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Share of payments by method</p>
                        <div class="relative flex items-center justify-center" style="height: 260px;">
                            <canvas id="methodChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Transactions Table -->
                <?php if (!empty($transactions)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Payment Transactions</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo count($transactions); ?> payment(s) &bull; <?php echo htmlspecialchars($selected_class_name); ?>
                            &bull; <?php echo htmlspecialchars($from_date); ?> to <?php echo htmlspecialchars($to_date); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Receipt #</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Method</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($transactions as $t): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-350">
                                        <?php echo date('M j, Y', strtotime($t['payment_date'])); ?>
                                        <div class="text-xs text-gray-400"><?php echo date('g:i A', strtotime($t['payment_date'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($t['receipt_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($t['student_name']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($t['roll_number'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($t['class_name'] ?? 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $method_badge[$t['payment_method']] ?? $method_badge['other']; ?>">
                                            <?php echo $method_labels[$t['payment_method']] ?? htmlspecialchars($t['payment_method']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-green-600 dark:text-green-400"><?php echo formatFinanceCurrency((float)$t['amount'], $db); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <td colspan="5" class="px-6 py-3 text-right text-sm font-bold text-gray-700 dark:text-gray-300">Total</td>
                                    <td class="px-6 py-3 text-right text-sm font-extrabold text-gray-900 dark:text-white"><?php echo formatFinanceCurrency($total_collected, $db); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-money-check-alt text-gray-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Payments Found</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No fee payments match the selected filters. Try widening the date range, choosing a different class, or clearing the method filter.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- PRINT REPORT TEMPLATE                                        -->
<!-- ============================================================ -->
<?php if (!empty($transactions)): ?>
<div id="print-report" class="print-report-container">
    <div class="print-page">
        <div class="print-header">
            <div class="print-header-inner">
                <div class="print-logo">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="School Logo" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                    <div class="print-logo-fallback" style="display:none"><?php echo strtoupper(substr($school_name, 0, 1)); ?></div>
                </div>
                <div class="print-school-info">
                    <h1 class="print-school-name"><?php echo htmlspecialchars($school_name); ?></h1>
                    <p class="print-contact-line">
                        <?php if ($school_address): ?><?php echo htmlspecialchars($school_address); ?><?php endif; ?>
                        <?php if ($school_phone): ?> &bull; Tel: <?php echo htmlspecialchars($school_phone); ?><?php endif; ?>
                        <?php if ($school_email): ?> &bull; <?php echo htmlspecialchars($school_email); ?><?php endif; ?>
                    </p>
                </div>
            </div>
            <div class="print-header-divider"></div>
        </div>

        <div class="print-title-banner"><h2>Fee Collection Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Class:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_class_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Method:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_method_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Period:</span><span class="print-meta-value"><?php echo htmlspecialchars($from_date . ' – ' . $to_date); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Total Collected:</span><span class="print-meta-value"><?php echo formatFinanceCurrency($total_collected, $db); ?></span></div>
        </div>

        <div class="print-section-title">Collection by Method</div>
        <table class="print-table">
            <thead><tr><th style="text-align:left">Method</th><th>Payments</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach ($method_data as $m): ?>
                <tr>
                    <td style="text-align:left;font-weight:600"><?php echo $method_labels[$m['payment_method']] ?? htmlspecialchars($m['payment_method']); ?></td>
                    <td><?php echo (int)$m['cnt']; ?></td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency((float)$m['total'], $db); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="print-section-title">Payment Transactions</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th style="text-align:left">Date</th>
                    <th>Receipt #</th>
                    <th style="text-align:left">Student</th>
                    <th>Class</th>
                    <th>Method</th>
                    <th>Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td style="text-align:left"><?php echo date('M j, Y', strtotime($t['payment_date'])); ?></td>
                    <td><?php echo htmlspecialchars($t['receipt_number']); ?></td>
                    <td style="text-align:left"><?php echo htmlspecialchars($t['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($t['class_name'] ?? 'N/A'); ?></td>
                    <td><?php echo $method_labels[$t['payment_method']] ?? htmlspecialchars($t['payment_method']); ?></td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency((float)$t['amount'], $db); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;font-weight:700">Total</td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency($total_collected, $db); ?></td>
                </tr>
            </tfoot>
        </table>

        <?php echo signatureRow(['Prepared By (Accountant)', 'Bursar', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>This is a computer-generated document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .print-report-container { display: none; }
    @media print {
        header, #sidebar, #web-layout, .search-overlay { display: none !important; }
        .print-report-container { display: block !important; }
        body, main {
            display: block !important; margin: 0 !important; padding: 0 !important;
            background: white !important; min-height: auto !important; height: auto !important;
            -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important;
        }
        @page { size: A4 portrait; margin: 10mm; }
    }
    .print-page { font-family: 'Inter','Segoe UI',sans-serif; font-size: 10.5px; line-height: 1.45; color: #1a1a2e; max-width: 210mm; margin: 0 auto; }
    .print-header-inner { display: flex; align-items: center; gap: 16px; padding-bottom: 10px; }
    .print-logo img, .print-logo-fallback { width: 60px; height: 60px; object-fit: contain; }
    .print-logo-fallback { background: linear-gradient(135deg,#1e3a5f,#2563eb); border-radius:50%; display:flex; align-items:center; justify-content:center; color:#fff; font-size:26px; font-weight:800; }
    .print-school-name { font-size: 22px; font-weight: 800; color: #1e3a5f; letter-spacing: 1.2px; text-transform: uppercase; margin: 0 0 2px 0; }
    .print-contact-line { font-size: 9px; color: #6b7280; margin: 0; }
    .print-header-divider { height: 3px; background: linear-gradient(to right,#1e3a5f,#2563eb,#7c3aed); border-radius: 3px; margin-bottom: 12px; }
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#065f46,#16a34a); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #065f46; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #065f46; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #ecfdf5; border-left: 4px solid #16a34a; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#065f46,#16a34a); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #064e3b; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .print-table tfoot td { padding: 6px 8px; border: 1px solid #e5e7eb; background: #ecfdf5; }
    .pct-cell { font-weight: 700; color: #065f46; }
    .print-signatures { display: grid; grid-template-columns: repeat(3,1fr); gap: 30px; margin-top: 28px; margin-bottom: 16px; }
    .print-signature-block { text-align: center; }
    .print-signature-block .signature-line { border-top: 1.5px solid #374151; margin-top: 36px; padding-top: 4px; }
    .signature-title { font-size: 10px; font-weight: 700; color: #1e3a5f; }
    .print-footer { text-align: center; padding-top: 10px; border-top: 1px solid #e5e7eb; margin-top: 10px; }
    .print-footer p { font-size: 8px; color: #9ca3af; margin: 0; font-style: italic; }
</style>

<!-- Load Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if ($payment_count > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Collection trend (line)
    const trendCanvas = document.getElementById('trendChart');
    if (trendCanvas) {
        new Chart(trendCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => date('M j', strtotime($r['pay_date'])), $trend_data)); ?>,
                datasets: [{
                    label: 'Collected',
                    data: <?php echo json_encode(array_map(fn($r) => round((float)$r['total'], 2), $trend_data)); ?>,
                    borderColor: 'rgb(22, 163, 74)',
                    backgroundColor: 'rgba(22, 163, 74, 0.12)',
                    borderWidth: 2, tension: 0.35, fill: true, pointRadius: 3
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: labelColor, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor }, grid: { color: gridColor } }
                }
            }
        });
    }

    // Collection by method (doughnut)
    const methodCanvas = document.getElementById('methodChart');
    if (methodCanvas) {
        new Chart(methodCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $method_labels[$r['payment_method']] ?? $r['payment_method'], $method_data)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(fn($r) => round((float)$r['total'], 2), $method_data)); ?>,
                    backgroundColor: ['rgba(22,163,74,0.85)','rgba(59,130,246,0.85)','rgba(245,158,11,0.85)','rgba(168,85,247,0.85)','rgba(107,114,128,0.85)'],
                    borderColor: isDark ? '#1f2937' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, padding: 15, font: { size: 11 } } } }
            }
        });
    }
});
<?php endif; ?>
</script>
