<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('finance');

require_once '../config/database.php';
require_once 'includes/finance_functions.php';
require_once '../includes/module_access.php';
requireModule('finance'); // block access if disabled for this school

$database = new Database();
$db = $database->getConnection();

// Heal tenant DBs that predate the finance module (idempotent, cheap fast-path).
require_once '../includes/schema_helpers.php';
ensureFinanceTables($db);

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get dynamic academic year and term
$context = $database->getCurrentAcademicContext();
$year_id = $context['year_id'];
$term_id = $context['term_id'];

// Fetch KPI 1: Total Revenue (all payments)
$total_revenue = (float)$db->query("SELECT SUM(amount) FROM finance_payments")->fetchColumn();

// Fetch KPI 2: Total Outstanding Fees (across active invoices)
$outstanding_fees = (float)$db->query("SELECT SUM(total_amount + penalty_amount - discount_amount - amount_paid) 
                                        FROM finance_invoices 
                                        WHERE status != 'cancelled'")->fetchColumn();

// Fetch KPI 3: Students Paid (paid in full this term/year)
$students_paid = 0;
if ($year_id) {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM finance_invoices WHERE status = 'paid' AND academic_year_id = :year_id");
    $stmt->execute([':year_id' => $year_id]);
    $students_paid = (int)$stmt->fetchColumn();
}

// Fetch KPI 4: Students Owing (unpaid/partial/overdue this term/year)
$students_owing = 0;
if ($year_id) {
    $stmt = $db->prepare("SELECT COUNT(DISTINCT student_id) FROM finance_invoices WHERE status IN ('pending', 'partially_paid', 'overdue') AND academic_year_id = :year_id");
    $stmt->execute([':year_id' => $year_id]);
    $students_owing = (int)$stmt->fetchColumn();
}

// Fetch KPI 5: Payments Received Today
$payments_today = (float)$db->query("SELECT SUM(amount) FROM finance_payments WHERE DATE(payment_date) = CURDATE()")->fetchColumn();

// Fetch KPI 6: Monthly Revenue (current calendar month)
$current_month = date('Y-m');
$stmt = $db->prepare("SELECT SUM(amount) FROM finance_payments WHERE DATE_FORMAT(payment_date, '%Y-%m') = :c_month");
$stmt->execute([':c_month' => $current_month]);
$monthly_revenue = (float)$stmt->fetchColumn();

// Fetch KPI 7: Active Invoices count
$active_invoices = (int)$db->query("SELECT COUNT(*) FROM finance_invoices WHERE status != 'cancelled'")->fetchColumn();

// Fetch KPI 8: Collection Rate (%)
$total_invoiced = (float)$db->query("SELECT SUM(total_amount + penalty_amount - discount_amount) FROM finance_invoices WHERE status != 'cancelled'")->fetchColumn();
$collection_rate = $total_invoiced > 0 ? round(($total_revenue / $total_invoiced) * 100, 1) : 0;

// Chart 1: Revenue Trends (Last 12 months)
$trend_data = $db->query("SELECT DATE_FORMAT(payment_date, '%b %Y') as month_label, SUM(amount) as total 
                          FROM finance_payments 
                          GROUP BY DATE_FORMAT(payment_date, '%Y-%m') 
                          ORDER BY payment_date ASC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
$trend_labels = json_encode(array_column($trend_data, 'month_label'));
$trend_values = json_encode(array_column($trend_data, 'total'));

// Chart 2: Fee Allocation by Category
$cat_data = $db->query("SELECT fc.name, SUM(ii.amount) as total 
                        FROM finance_invoice_items ii
                        JOIN finance_fee_categories fc ON ii.category_id = fc.id
                        GROUP BY ii.category_id")->fetchAll(PDO::FETCH_ASSOC);
$cat_labels = json_encode(array_column($cat_data, 'name'));
$cat_values = json_encode(array_column($cat_data, 'total'));

// Chart 3: Payment Method Distribution
$method_data = $db->query("SELECT payment_method, SUM(amount) as total 
                           FROM finance_payments 
                           GROUP BY payment_method")->fetchAll(PDO::FETCH_ASSOC);
$method_labels = json_encode(array_map('ucfirst', array_column($method_data, 'payment_method')));
$method_values = json_encode(array_column($method_data, 'total'));

// Recent Transactions Table (latest 10)
$recent_tx = $db->query("SELECT p.*, i.invoice_number, u.name as student_name, sp.student_id as student_reg_no
                         FROM finance_payments p
                         JOIN finance_invoices i ON p.invoice_id = i.id
                         JOIN users u ON i.student_id = u.id
                         JOIN student_profiles sp ON u.id = sp.user_id
                         ORDER BY p.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Finance Dashboard</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Real-time collections, outstanding balances, and analytics</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 no-stack">
                        <a href="collect_payment.php" class="inline-flex items-center whitespace-nowrap bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-4 py-2.5 rounded-xl shadow shadow-green-500/20 transition">
                            <i class="fas fa-coins mr-2"></i> Collect Payment
                        </a>
                        <a href="invoices.php" class="inline-flex items-center whitespace-nowrap bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2.5 rounded-xl transition">
                            <i class="fas fa-file-invoice-dollar mr-2"></i> Invoices
                        </a>
                    </div>
                </div>

                <!-- 8 KPI Cards Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- KPI 1 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-950/40 rounded-2xl flex items-center justify-center text-emerald-600 dark:text-emerald-450">
                            <i class="fas fa-coins text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Total Revenue</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($total_revenue, $db); ?></span>
                        </div>
                    </div>
                    <!-- KPI 2 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-rose-100 dark:bg-rose-950/40 rounded-2xl flex items-center justify-center text-rose-600 dark:text-rose-455">
                            <i class="fas fa-exclamation-circle text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Outstanding Fees</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($outstanding_fees, $db); ?></span>
                        </div>
                    </div>
                    <!-- KPI 3 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-teal-100 dark:bg-teal-950/40 rounded-2xl flex items-center justify-center text-teal-600 dark:text-teal-450">
                            <i class="fas fa-user-check text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Students Paid</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $students_paid; ?></span>
                        </div>
                    </div>
                    <!-- KPI 4 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-amber-100 dark:bg-amber-950/40 rounded-2xl flex items-center justify-center text-amber-600 dark:text-amber-450">
                            <i class="fas fa-user-times text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Students Owing</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $students_owing; ?></span>
                        </div>
                    </div>
                    <!-- KPI 5 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-950/40 rounded-2xl flex items-center justify-center text-blue-600 dark:text-blue-450">
                            <i class="fas fa-hand-holding-usd text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Received Today</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($payments_today, $db); ?></span>
                        </div>
                    </div>
                    <!-- KPI 6 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-950/40 rounded-2xl flex items-center justify-center text-indigo-600 dark:text-indigo-455">
                            <i class="fas fa-calendar-alt text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Monthly Revenue</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($monthly_revenue, $db); ?></span>
                        </div>
                    </div>
                    <!-- KPI 7 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-950/40 rounded-2xl flex items-center justify-center text-purple-600 dark:text-purple-450">
                            <i class="fas fa-file-invoice text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Active Invoices</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $active_invoices; ?></span>
                        </div>
                    </div>
                    <!-- KPI 8 -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-950/40 rounded-2xl flex items-center justify-center text-yellow-600 dark:text-yellow-450">
                            <i class="fas fa-percentage text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-0.5">Collection Rate</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo $collection_rate; ?>%</span>
                        </div>
                    </div>
                </div>

                <!-- Charts & Quick Actions -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                    <!-- Revenue Trend (Line Chart) -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Revenue Collection Trend</h3>
                        <div class="relative h-72">
                            <canvas id="revenueTrendChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Quick Financial Actions</h3>
                        <div class="grid grid-cols-2 gap-4">
                            <a href="collect_payment.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-coins text-2xl text-green-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Collect Fee</span>
                            </a>
                            <a href="invoices.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-file-invoice-dollar text-2xl text-blue-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Bill Students</span>
                            </a>
                            <a href="fee_categories.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-tags text-2xl text-purple-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Fee Categories</span>
                            </a>
                            <a href="discounts.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-percent text-2xl text-yellow-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Waivers/Schol.</span>
                            </a>
                            <a href="expenses.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-file-invoice text-2xl text-rose-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Expenses</span>
                            </a>
                            <a href="reports.php" class="p-4 bg-gray-50 hover:bg-gray-100 dark:bg-gray-900/40 dark:hover:bg-gray-900 rounded-xl flex flex-col items-center justify-center text-center transition">
                                <i class="fas fa-chart-bar text-2xl text-indigo-500 mb-2"></i>
                                <span class="text-xs font-semibold text-gray-700 dark:text-gray-300">Reports</span>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Secondary Charts Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8 mb-8">
                    <!-- Fee categories distribution (Doughnut Chart) -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Structural Allocation by Category</h3>
                        <div class="relative h-72">
                            <canvas id="categoryAllocationChart"></canvas>
                        </div>
                    </div>
                    
                    <!-- Payment Methods (Bar Chart) -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Payment Method Distribution</h3>
                        <div class="relative h-72">
                            <canvas id="methodDistributionChart"></canvas>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions Table -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="px-6 py-4 border-b border-gray-150 dark:border-gray-700/50 flex justify-between items-center">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white">Recent Transactions</h3>
                        <a href="payments.php" class="text-xs font-bold text-blue-600 hover:underline">View Ledger Ledger</a>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Receipt #</th>
                                    <th class="p-4">Student</th>
                                    <th class="p-4">Invoice #</th>
                                    <th class="p-4">Method</th>
                                    <th class="p-4 text-right">Amount</th>
                                    <th class="p-4">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($recent_tx as $tx): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                    <td class="p-4 font-bold text-gray-750 dark:text-gray-300"><?php echo htmlspecialchars($tx['receipt_number']); ?></td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($tx['student_name']); ?></div>
                                        <div class="text-xs text-gray-400"><?php echo htmlspecialchars($tx['student_reg_no']); ?></div>
                                    </td>
                                    <td class="p-4 font-semibold text-blue-600"><?php echo htmlspecialchars($tx['invoice_number']); ?></td>
                                    <td class="p-4 capitalize"><?php echo htmlspecialchars($tx['payment_method']); ?></td>
                                    <td class="p-4 text-right font-bold text-emerald-600"><?php echo formatFinanceCurrency($tx['amount'], $db); ?></td>
                                    <td class="p-4 text-gray-450"><?php echo date('M d, Y', strtotime($tx['payment_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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

<!-- Load Chart.js CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Line Chart: Revenue Trends
const trendCtx = document.getElementById('revenueTrendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: <?php echo $trend_labels; ?>,
        datasets: [{
            label: 'Collection',
            data: <?php echo $trend_values; ?>,
            borderColor: '#059669',
            backgroundColor: 'rgba(5, 150, 105, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Doughnut Chart: Category Allocation
const catCtx = document.getElementById('categoryAllocationChart').getContext('2d');
new Chart(catCtx, {
    type: 'doughnut',
    data: {
        labels: <?php echo $cat_labels; ?>,
        datasets: [{
            data: <?php echo $cat_values; ?>,
            backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#6b7280', '#06b6d4', '#f97316', '#14b8a6']
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right' } }
    }
});

// Bar Chart: Payment Methods
const methodCtx = document.getElementById('methodDistributionChart').getContext('2d');
new Chart(methodCtx, {
    type: 'bar',
    data: {
        labels: <?php echo $method_labels; ?>,
        datasets: [{
            data: <?php echo $method_values; ?>,
            backgroundColor: '#4f46e5',
            borderRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>

