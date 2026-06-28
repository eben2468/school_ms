<?php
session_start();
// Financial report — restricted to finance roles.
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

$today = date('Y-m-d');

// ---- Filters ----
$selected_class = isset($_GET['class_id']) && $_GET['class_id'] !== '' ? (int)$_GET['class_id'] : '';
$selected_year  = isset($_GET['year_id']) && $_GET['year_id'] !== '' ? (int)$_GET['year_id'] : '';
$overdue_only   = isset($_GET['overdue']) && $_GET['overdue'] === '1';

$years = $db->query("SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name FROM classes WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Balance expression reused everywhere
$bal_expr = "(i.total_amount + i.penalty_amount - i.discount_amount - i.amount_paid)";

// One active class per student (avoid row multiplication when a student has multiple enrollments)
$from_join = "
    FROM finance_invoices i
    JOIN users u ON i.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    LEFT JOIN (SELECT student_id, MIN(class_id) AS class_id FROM student_classes WHERE status = 'active' GROUP BY student_id) scx ON u.id = scx.student_id
    LEFT JOIN classes c ON scx.class_id = c.id
";

$where = " WHERE i.status != 'cancelled' AND $bal_expr > 0.005 ";
$params = [];
if ($selected_year !== '')  { $where .= " AND i.academic_year_id = :year_id "; $params[':year_id'] = $selected_year; }
if ($selected_class !== '') { $where .= " AND scx.class_id = :class_id "; $params[':class_id'] = $selected_class; }
if ($overdue_only)          { $where .= " AND i.due_date < :today "; $params[':today'] = $today; }

// ---- Outstanding invoices (the actionable list) ----
$inv_stmt = $db->prepare("
    SELECT i.invoice_number, i.total_amount, i.amount_paid, i.discount_amount, i.penalty_amount,
           i.due_date, i.status, $bal_expr AS balance,
           u.name AS student_name, sp.student_id AS roll_number, c.name AS class_name
    $from_join
    $where
    ORDER BY balance DESC, i.due_date ASC
    LIMIT 1000
");
$inv_stmt->execute($params);
$invoices = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Summary ----
$sum_stmt = $db->prepare("
    SELECT COUNT(*) AS invoice_count,
           COUNT(DISTINCT i.student_id) AS students_owing,
           COALESCE(SUM($bal_expr),0) AS total_outstanding,
           COALESCE(SUM(CASE WHEN i.due_date < :today THEN $bal_expr ELSE 0 END),0) AS overdue_amount
    $from_join
    $where
");
$sum_params = array_merge($params, [':today' => $today]);
// Avoid duplicate :today bind if overdue_only already added it
$sum_stmt->execute($sum_params);
$summary = $sum_stmt->fetch(PDO::FETCH_ASSOC);

$invoice_count = (int)$summary['invoice_count'];
$students_owing = (int)$summary['students_owing'];
$total_outstanding = (float)$summary['total_outstanding'];
$overdue_amount = (float)$summary['overdue_amount'];

// ---- Outstanding by class (bar) ----
$class_stmt = $db->prepare("
    SELECT COALESCE(c.name, 'Unassigned') AS class_name, SUM($bal_expr) AS balance
    $from_join
    $where
    GROUP BY c.name
    HAVING balance > 0
    ORDER BY balance DESC
    LIMIT 10
");
$class_stmt->execute($params);
$class_data = $class_stmt->fetchAll(PDO::FETCH_ASSOC);

// ---- Aging buckets (computed from the fetched invoices) ----
$aging = ['current' => 0.0, 'd30' => 0.0, 'd60' => 0.0, 'd60plus' => 0.0];
function aging_bucket($due_date, $today)
{
    if ($due_date >= $today) return 'current';
    $days = (strtotime($today) - strtotime($due_date)) / 86400;
    if ($days <= 30) return 'd30';
    if ($days <= 60) return 'd60';
    return 'd60plus';
}
foreach ($invoices as $inv) {
    $aging[aging_bucket($inv['due_date'], $today)] += (float)$inv['balance'];
}

// Selected names
$selected_class_name = 'All Classes';
foreach ($classes as $c) { if ($c['id'] == $selected_class) { $selected_class_name = $c['name']; break; } }
$selected_year_name = 'All Years';
foreach ($years as $y) { if ((int)$y['id'] === $selected_year) { $selected_year_name = $y['year_name']; break; } }

// Status / aging badge helpers
function aging_label($due_date, $today)
{
    if ($due_date >= $today) return ['Current', 'text-blue-800 bg-blue-100 dark:bg-blue-900/40 dark:text-blue-300'];
    $days = (int)((strtotime($today) - strtotime($due_date)) / 86400);
    if ($days <= 30) return [$days . 'd overdue', 'text-amber-800 bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300'];
    if ($days <= 60) return [$days . 'd overdue', 'text-orange-800 bg-orange-100 dark:bg-orange-900/40 dark:text-orange-300'];
    return [$days . 'd overdue', 'text-red-800 bg-red-100 dark:bg-red-900/40 dark:text-red-300'];
}

// ---- CSV export ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outstanding_fees_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Outstanding Fees Report']);
    fputcsv($out, ['Academic Year', $selected_year_name]);
    fputcsv($out, ['Class', $selected_class_name]);
    fputcsv($out, ['Filter', $overdue_only ? 'Overdue only' : 'All outstanding']);
    fputcsv($out, ['Total Outstanding', number_format($total_outstanding, 2)]);
    fputcsv($out, ['Generated', date('Y-m-d H:i')]);
    fputcsv($out, []);
    fputcsv($out, ['Invoice #', 'Student', 'Student ID', 'Class', 'Charged', 'Paid', 'Balance', 'Due Date', 'Status']);
    foreach ($invoices as $inv) {
        $charged = (float)$inv['total_amount'] + (float)$inv['penalty_amount'] - (float)$inv['discount_amount'];
        fputcsv($out, [
            $inv['invoice_number'], $inv['student_name'], $inv['roll_number'] ?? 'N/A',
            $inv['class_name'] ?? 'N/A',
            number_format($charged, 2), number_format((float)$inv['amount_paid'], 2),
            number_format((float)$inv['balance'], 2), $inv['due_date'],
            ucwords(str_replace('_', ' ', $inv['status'])),
        ]);
    }
    fclose($out);
    exit();
}

$export_qs = http_build_query([
    'year_id' => $selected_year, 'class_id' => $selected_class,
    'overdue' => $overdue_only ? '1' : '', 'export' => 'csv',
]);

$title = "Outstanding Fees Report";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Reports', 'url' => 'index.php'],
    ['title' => 'Outstanding Fees Report']
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
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Outstanding Fees Report</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track unpaid balances, aging, and students owing across classes.</p>
                    </div>
                    <div class="flex gap-3">
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Reports
                        </a>
                        <a href="?<?php echo htmlspecialchars($export_qs); ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($invoices) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-download mr-2"></i>Export CSV
                        </a>
                        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg shadow-sm transition flex items-center <?php echo empty($invoices) ? 'opacity-50 pointer-events-none' : ''; ?>">
                            <i class="fas fa-print mr-2"></i>Print Report
                        </button>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Report Filters</h2>
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                            <select name="year_id" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Years</option>
                                <?php foreach ($years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $selected_year === (int)$year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?><?php echo $year['status'] === 'active' ? ' (Current)' : ''; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

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
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Show</label>
                            <select name="overdue" class="w-full border border-gray-300 dark:border-gray-650 rounded-lg px-3 py-2 bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 transition">
                                <option value="">All Outstanding</option>
                                <option value="1" <?php echo $overdue_only ? 'selected' : ''; ?>>Overdue Only</option>
                            </select>
                        </div>

                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow transition flex items-center justify-center">
                                <i class="fas fa-search mr-2"></i>Generate
                            </button>
                        </div>
                    </form>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-3">
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_year_name); ?></span> &bull;
                        <span class="font-semibold"><?php echo htmlspecialchars($selected_class_name); ?></span> &bull;
                        <span class="font-semibold"><?php echo $overdue_only ? 'Overdue only' : 'All outstanding'; ?></span>
                    </p>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Total Outstanding</p>
                            <h3 class="text-2xl font-bold text-rose-600 dark:text-rose-400 mt-1"><?php echo formatFinanceCurrency($total_outstanding, $db); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-rose-100 dark:bg-rose-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-rose-600 dark:text-rose-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Overdue Amount</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo formatFinanceCurrency($overdue_amount, $db); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-amber-600 dark:text-amber-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Outstanding Invoices</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($invoice_count); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-invoice-dollar text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">Students Owing</p>
                            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?php echo number_format($students_owing); ?></h3>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-clock text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <?php if ($invoice_count > 0): ?>
                <!-- Graphical Insights -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Outstanding by Class</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Classes with the highest unpaid balances</p>
                        <div class="relative" style="height: 280px;">
                            <canvas id="classChart"></canvas>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-1">Aging Breakdown</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400 mb-4">Balance by how long it has been due</p>
                        <div class="relative flex items-center justify-center" style="height: 280px;">
                            <canvas id="agingChart"></canvas>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Results Table -->
                <?php if (!empty($invoices)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 overflow-hidden mb-6">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700">
                        <h2 class="text-lg font-bold text-gray-900 dark:text-white">Outstanding Invoices</h2>
                        <p class="text-xs text-gray-550 dark:text-gray-400">
                            <?php echo count($invoices); ?> invoice(s) &bull; <?php echo htmlspecialchars($selected_class_name); ?>
                            &bull; <?php echo htmlspecialchars($selected_year_name); ?>
                        </p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-250 dark:divide-gray-750">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Invoice #</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Charged</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Paid</th>
                                    <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Balance</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Aging</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($invoices as $inv):
                                    $charged = (float)$inv['total_amount'] + (float)$inv['penalty_amount'] - (float)$inv['discount_amount'];
                                    list($age_label, $age_class) = aging_label($inv['due_date'], $today);
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition duration-150">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($inv['student_name']); ?>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($inv['roll_number'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($inv['class_name'] ?? 'N/A'); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-600 dark:text-gray-350"><?php echo formatFinanceCurrency($charged, $db); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-emerald-600 dark:text-emerald-400"><?php echo formatFinanceCurrency((float)$inv['amount_paid'], $db); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-right font-bold text-rose-600 dark:text-rose-400"><?php echo formatFinanceCurrency((float)$inv['balance'], $db); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center text-gray-600 dark:text-gray-350"><?php echo date('M j, Y', strtotime($inv['due_date'])); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-center">
                                        <span class="inline-flex px-2.5 py-1 text-xs font-bold rounded-full <?php echo $age_class; ?>"><?php echo $age_label; ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <td colspan="5" class="px-6 py-3 text-right text-sm font-bold text-gray-700 dark:text-gray-300">Total Outstanding</td>
                                    <td class="px-6 py-3 text-right text-sm font-extrabold text-rose-600 dark:text-rose-400"><?php echo formatFinanceCurrency($total_outstanding, $db); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <!-- Empty Results -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-150 dark:border-gray-700 p-12 text-center">
                    <div class="w-20 h-20 bg-green-50 dark:bg-green-900/20 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-check-circle text-green-400 text-3xl"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Outstanding Fees</h3>
                    <p class="text-gray-500 dark:text-gray-400 max-w-md mx-auto">No unpaid balances match the selected filters. Try a different class, year, or clear the overdue filter.</p>
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
<?php if (!empty($invoices)): ?>
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

        <div class="print-title-banner"><h2>Outstanding Fees Report</h2></div>

        <div class="print-meta-grid">
            <div class="print-meta-item"><span class="print-meta-label">Academic Year:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_year_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Class:</span><span class="print-meta-value"><?php echo htmlspecialchars($selected_class_name); ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Invoices:</span><span class="print-meta-value"><?php echo $invoice_count; ?></span></div>
            <div class="print-meta-item"><span class="print-meta-label">Total Outstanding:</span><span class="print-meta-value"><?php echo formatFinanceCurrency($total_outstanding, $db); ?></span></div>
        </div>

        <div class="print-section-title">Outstanding Invoices</div>
        <table class="print-table">
            <thead>
                <tr>
                    <th>Invoice #</th>
                    <th style="text-align:left">Student</th>
                    <th>Class</th>
                    <th>Charged</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv):
                    $charged = (float)$inv['total_amount'] + (float)$inv['penalty_amount'] - (float)$inv['discount_amount'];
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                    <td style="text-align:left;font-weight:600"><?php echo htmlspecialchars($inv['student_name']); ?></td>
                    <td><?php echo htmlspecialchars($inv['class_name'] ?? 'N/A'); ?></td>
                    <td><?php echo formatFinanceCurrency($charged, $db); ?></td>
                    <td><?php echo formatFinanceCurrency((float)$inv['amount_paid'], $db); ?></td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency((float)$inv['balance'], $db); ?></td>
                    <td><?php echo date('M j, Y', strtotime($inv['due_date'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;font-weight:700">Total Outstanding</td>
                    <td class="pct-cell"><?php echo formatFinanceCurrency($total_outstanding, $db); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <?php echo signatureRow(['Prepared By (Accountant)', 'Bursar', 'Headmaster/Headmistress']); ?>

        <div class="print-footer">
            <p>Confidential financial document. &bull; <?php echo htmlspecialchars($school_name); ?> &bull; Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
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
    .print-title-banner { text-align: center; background: linear-gradient(135deg,#9f1239,#e11d48); color: #fff; padding: 7px 20px; border-radius: 5px; margin-bottom: 12px; }
    .print-title-banner h2 { font-size: 14px; font-weight: 700; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
    .print-meta-grid { display: grid; grid-template-columns: repeat(4,1fr); border: 1px solid #d1d5db; border-radius: 5px; overflow: hidden; margin-bottom: 14px; }
    .print-meta-item { padding: 6px 12px; border-right: 1px solid #e5e7eb; background: #f8fafc; }
    .print-meta-item:last-child { border-right: none; }
    .print-meta-label { font-size: 8.5px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.4px; display: block; }
    .print-meta-value { font-size: 11px; font-weight: 700; color: #9f1239; display: block; }
    .print-section-title { font-size: 11px; font-weight: 700; color: #9f1239; text-transform: uppercase; letter-spacing: 0.6px; padding: 5px 10px; background: #fff1f2; border-left: 4px solid #e11d48; border-radius: 0 4px 4px 0; margin: 12px 0 8px; }
    .print-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; font-size: 10px; }
    .print-table thead th { background: linear-gradient(135deg,#9f1239,#e11d48); color: #fff; padding: 6px 8px; text-align: center; font-weight: 600; font-size: 9px; text-transform: uppercase; letter-spacing: 0.4px; border: 1px solid #881337; }
    .print-table tbody td { padding: 5px 8px; text-align: center; border: 1px solid #e5e7eb; font-size: 10px; }
    .print-table tbody tr:nth-child(even) { background: #f9fafb; }
    .print-table tfoot td { padding: 6px 8px; border: 1px solid #e5e7eb; background: #fff1f2; }
    .pct-cell { font-weight: 700; color: #9f1239; }
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
<?php if ($invoice_count > 0): ?>
document.addEventListener("DOMContentLoaded", function() {
    const isDark = document.documentElement.classList.contains('dark');
    const labelColor = isDark ? '#9ca3af' : '#4b5563';
    const gridColor = isDark ? '#374151' : '#f3f4f6';

    // Outstanding by class (bar)
    const classCanvas = document.getElementById('classChart');
    if (classCanvas) {
        new Chart(classCanvas.getContext('2d'), {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(fn($r) => $r['class_name'], $class_data)); ?>,
                datasets: [{
                    label: 'Outstanding',
                    data: <?php echo json_encode(array_map(fn($r) => round((float)$r['balance'], 2), $class_data)); ?>,
                    backgroundColor: 'rgba(225, 29, 72, 0.85)',
                    borderColor: 'rgb(225, 29, 72)', borderWidth: 1, borderRadius: 5
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { ticks: { color: labelColor, font: { size: 10 } }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: labelColor }, grid: { color: gridColor } }
                }
            }
        });
    }

    // Aging breakdown (doughnut)
    const agingCanvas = document.getElementById('agingChart');
    if (agingCanvas) {
        new Chart(agingCanvas.getContext('2d'), {
            type: 'doughnut',
            data: {
                labels: ['Current (not due)', '1-30 days', '31-60 days', '60+ days'],
                datasets: [{
                    data: [<?php echo round($aging['current'], 2); ?>, <?php echo round($aging['d30'], 2); ?>, <?php echo round($aging['d60'], 2); ?>, <?php echo round($aging['d60plus'], 2); ?>],
                    backgroundColor: ['rgba(59,130,246,0.85)','rgba(245,158,11,0.85)','rgba(249,115,22,0.85)','rgba(239,68,68,0.85)'],
                    borderColor: isDark ? '#1f2937' : '#ffffff',
                    borderWidth: 2
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom', labels: { color: labelColor, padding: 14, font: { size: 11 } } } }
            }
        });
    }
});
<?php endif; ?>
</script>
