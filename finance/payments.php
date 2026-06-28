<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get filter parameters
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$payment_method = filter_input(INPUT_GET, 'method', FILTER_SANITIZE_STRING) ?: '';
$date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_STRING) ?: '';
$date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_STRING) ?: '';

// Build where conditions
$where = [];
$params = [];

// Role-based filtering
if ($user_role === 'student') {
    $where[] = "i.student_id = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($user_role === 'parent') {
    $where[] = "i.student_id IN (SELECT user_id FROM student_profiles WHERE parent_id = :user_id)";
    $params[':user_id'] = $user_id;
}

if ($search) {
    $where[] = "(u.name LIKE :search OR sp.student_id LIKE :search OR p.receipt_number LIKE :search OR p.reference_number LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($payment_method) {
    $where[] = "p.payment_method = :method";
    $params[':method'] = $payment_method;
}

if ($date_from) {
    $where[] = "p.payment_date >= :date_from";
    $params[':date_from'] = $date_from . ' 00:00:00';
}

if ($date_to) {
    $where[] = "p.payment_date <= :date_to";
    $params[':date_to'] = $date_to . ' 23:59:59';
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Fetch payments
$query = "SELECT p.*, i.invoice_number, u.name as student_name, sp.student_id as student_reg_no, c.name as class_name
          FROM finance_payments p
          JOIN finance_invoices i ON p.invoice_id = i.id
          JOIN users u ON i.student_id = u.id
          JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY p.payment_date DESC";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals for stats
$total_collected = 0.00;
foreach ($payments as $p) {
    $total_collected += (float)$p['amount'];
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Payments History</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review all student fee payment transactions and ledger records</p>
                    </div>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                    <a href="collect_payment.php" class="bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg hover:shadow-xl transition duration-300 flex items-center gap-2">
                        <i class="fas fa-plus"></i> Collect Fee Payment
                    </a>
                    <?php endif; ?>
                </div>

                <!-- Stats Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/30 rounded-2xl flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                            <i class="fas fa-coins text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Total Collected (Filtered)</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($total_collected, $db); ?></span>
                        </div>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i class="fas fa-receipt text-lg"></i>
                        </div>
                        <div>
                            <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block">Transactions Count</span>
                            <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo count($payments); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <form action="" method="GET" class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search student, receipt, reference..." class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <select name="method" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Payment Methods</option>
                                <option value="cash" <?php echo $payment_method === 'cash' ? 'selected' : ''; ?>>Cash</option>
                                <option value="bank_transfer" <?php echo $payment_method === 'bank_transfer' ? 'selected' : ''; ?>>Bank Transfer</option>
                                <option value="mobile_money" <?php echo $payment_method === 'mobile_money' ? 'selected' : ''; ?>>Mobile Money</option>
                                <option value="online" <?php echo $payment_method === 'online' ? 'selected' : ''; ?>>Online</option>
                            </select>
                        </div>
                        <div>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow-lg transition duration-200">
                                Filter Ledger
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Ledger List -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Receipt #</th>
                                    <th class="p-4">Student</th>
                                    <th class="p-4">Class / Invoice</th>
                                    <th class="p-4">Method / Ref</th>
                                    <th class="p-4">Amount</th>
                                    <th class="p-4">Date</th>
                                    <th class="p-4 text-center">Receipt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50 text-sm">
                                <?php foreach ($payments as $p): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition duration-150">
                                    <td class="p-4 font-bold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($p['receipt_number']); ?></td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($p['student_name']); ?></div>
                                        <div class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($p['student_reg_no']); ?></div>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($p['class_name'] ?: 'N/A'); ?></div>
                                        <div class="text-xs text-blue-600 dark:text-blue-400 font-semibold"><?php echo htmlspecialchars($p['invoice_number']); ?></div>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white capitalize"><?php echo str_replace('_', ' ', $p['payment_method']); ?></div>
                                        <div class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($p['reference_number'] ?: $p['receipt_number']); ?></div>
                                    </td>
                                    <td class="p-4 font-bold text-emerald-600"><?php echo formatFinanceCurrency($p['amount'], $db); ?></td>
                                    <td class="p-4 text-gray-500 dark:text-gray-400 font-medium"><?php echo date('M d, Y H:i', strtotime($p['payment_date'])); ?></td>
                                    <td class="p-4 text-center">
                                        <a href="receipts.php?payment_id=<?php echo $p['id']; ?>" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-white text-xs font-bold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1.5">
                                            <i class="fas fa-print"></i> Print
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (empty($payments)): ?>
                <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 mt-8">
                    <i class="fas fa-credit-card text-gray-300 dark:text-gray-600 text-6xl mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-1">No payment transactions found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400">Search with filters or collect payments from students to record.</p>
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
