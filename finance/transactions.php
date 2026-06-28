<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Build unified timeline memory list (combining 3 tables)
// We will query each table and union them with identical schemas
$query = "(SELECT 'fee_payment' as type, p.payment_date as date, p.receipt_number as reference, 
                 u.name as description, p.amount as credit, 0.00 as debit
          FROM finance_payments p
          JOIN finance_invoices i ON p.invoice_id = i.id
          JOIN users u ON i.student_id = u.id)
          UNION ALL
          (SELECT 'other_income' as type, income_date as date, 'INC' as reference,
                 description as description, amount as credit, 0.00 as debit
          FROM finance_income)
          UNION ALL
          (SELECT 'expense' as type, expense_date as date, 'EXP' as reference,
                 CONCAT(vendor, ' - ', description) as description, 0.00 as credit, amount as debit
          FROM finance_expenses
          WHERE status = 'approved')
          ORDER BY date DESC";

$transactions = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);

// Handle CSV export request
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=unified_ledger_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Date', 'Type', 'Reference/Receipt', 'Description/Payee', 'Debit (Expense)', 'Credit (Income)', 'Running Impact']);
    
    foreach ($transactions as $t) {
        $impact = $t['credit'] - $t['debit'];
        fputcsv($output, [
            $t['date'],
            strtoupper(str_replace('_', ' ', $t['type'])),
            $t['reference'],
            $t['description'],
            $t['debit'],
            $t['credit'],
            $impact
        ]);
    }
    
    fclose($output);
    exit();
}

// Calculate summary totals
$total_debits = 0.00;
$total_credits = 0.00;
foreach ($transactions as $t) {
    $total_debits += (float)$t['debit'];
    $total_credits += (float)$t['credit'];
}
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Unified Ledger</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review unified debit and credit cashflows across all sectors</p>
                    </div>
                    <a href="transactions.php?export=csv" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-file-csv"></i> Export CSV Ledger
                    </a>
                </div>

                <!-- Stats -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-2xl flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                            <i class="fas fa-arrow-down text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Total Credits (Income)</span>
                            <span class="text-2xl font-extrabold text-emerald-600"><?php echo formatFinanceCurrency($total_credits, $db); ?></span>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-rose-100 dark:bg-rose-900/30 rounded-2xl flex items-center justify-center text-rose-600 dark:text-rose-400">
                            <i class="fas fa-arrow-up text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Total Debits (Expenses)</span>
                            <span class="text-2xl font-extrabold text-rose-500"><?php echo formatFinanceCurrency($total_debits, $db); ?></span>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i class="fas fa-wallet text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Net Cashflow</span>
                            <span class="text-2xl font-extrabold text-blue-600 dark:text-blue-400"><?php echo formatFinanceCurrency($total_credits - $total_debits, $db); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Ledger Table -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Date</th>
                                    <th class="p-4">Type</th>
                                    <th class="p-4">Reference</th>
                                    <th class="p-4">Description/Payee</th>
                                    <th class="p-4 text-right">Debit (+)</th>
                                    <th class="p-4 text-right">Credit (-)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($transactions as $t): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                    <td class="p-4 text-gray-500 font-medium"><?php echo date('M d, Y H:i', strtotime($t['date'])); ?></td>
                                    <td class="p-4">
                                        <?php if ($t['type'] === 'fee_payment'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/20">Fee Credit</span>
                                        <?php elseif ($t['type'] === 'other_income'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 dark:bg-blue-950/20">Other Rev</span>
                                        <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 dark:bg-rose-950/20 font-bold">Expense</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($t['reference']); ?></td>
                                    <td class="p-4 text-gray-650 dark:text-gray-300"><?php echo htmlspecialchars($t['description']); ?></td>
                                    <td class="p-4 text-right font-bold text-rose-500"><?php echo $t['debit'] > 0 ? formatFinanceCurrency($t['debit'], $db) : '-'; ?></td>
                                    <td class="p-4 text-right font-bold text-emerald-600"><?php echo $t['credit'] > 0 ? formatFinanceCurrency($t['credit'], $db) : '-'; ?></td>
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
