<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get filter parameters
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: 'all';
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$date_from = filter_input(INPUT_GET, 'date_from', FILTER_SANITIZE_STRING) ?: '';
$date_to = filter_input(INPUT_GET, 'date_to', FILTER_SANITIZE_STRING) ?: '';
$page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_NUMBER_INT) ?: 1;
$limit = 15;
$offset = ($page - 1) * $limit;

// Build where conditions
$where_conditions = [];
$params = [];

// Role-based filtering
if ($user_role === 'student') {
    $where_conditions[] = "sp.student_id = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($user_role === 'parent') {
    $where_conditions[] = "sp.student_id IN (SELECT user_id FROM student_profiles WHERE parent_id = :user_id)";
    $params[':user_id'] = $user_id;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "sp.payment_status = :status";
    $params[':status'] = $status_filter;
}

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR fs.fee_type LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($date_from) {
    $where_conditions[] = "sp.created_at >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "sp.created_at <= :date_to";
    $params[':date_to'] = $date_to . ' 23:59:59';
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Count total payments
$count_query = "SELECT COUNT(*) as total 
                FROM student_payments sp
                JOIN fee_structures fs ON sp.fee_structure_id = fs.id
                JOIN users u ON sp.student_id = u.id
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_payments = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_payments / $limit);

// Fetch payments
$query = "SELECT sp.*, fs.fee_type, fs.amount as fee_amount, fs.academic_year, fs.academic_term,
          u.name as student_name, c.name as class_name, c.grade_level,
          DATEDIFF(CURDATE(), sp.due_date) as days_overdue
          FROM student_payments sp
          JOIN fee_structures fs ON sp.fee_structure_id = fs.id
          JOIN users u ON sp.student_id = u.id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY sp.created_at DESC
          LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment statistics
$stats_where = $user_role === 'student' ? "WHERE sp.student_id = $user_id" : 
              ($user_role === 'parent' ? "WHERE sp.student_id IN (SELECT user_id FROM student_profiles WHERE parent_id = $user_id)" : "");

$stats_query = "SELECT 
                COUNT(*) as total_payments,
                COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as paid_payments,
                COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_payments,
                COUNT(CASE WHEN payment_status = 'overdue' THEN 1 END) as overdue_payments,
                SUM(CASE WHEN payment_status = 'paid' THEN amount_paid ELSE 0 END) as total_paid,
                SUM(CASE WHEN payment_status IN ('pending', 'overdue') THEN fs.amount ELSE 0 END) as total_outstanding
                FROM student_payments sp
                JOIN fee_structures fs ON sp.fee_structure_id = fs.id
                $stats_where";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8" style="margin-top: 30px;">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">
                                    <?php echo ($user_role === 'student' || $user_role === 'parent') ? 'Payment History' : 'Payment Management'; ?>
                                </h1>
                                <p class="text-blue-100 text-lg">
                                    <?php echo ($user_role === 'student' || $user_role === 'parent') ? 'View your payment records and transaction history' : 'Manage student payments and financial records'; ?>
                                </p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-credit-card mr-2"></i>
                                        Payment Tracking
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-chart-line mr-2"></i>
                                        Financial Records
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-money-bill-wave text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <div class="flex justify-between items-center mb-6">
                <div></div>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Finance
                    </a>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                    <a href="collect_payment.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Collect Payment
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Statistics -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-blue-100">
                            <i class="fas fa-receipt text-blue-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Total Payments</p>
                            <p class="text-lg font-semibold text-gray-900"><?php echo $stats['total_payments']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-green-100">
                            <i class="fas fa-check text-green-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Paid</p>
                            <p class="text-lg font-semibold text-green-600">₵<?php echo number_format($stats['total_paid'], 2); ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-yellow-100">
                            <i class="fas fa-clock text-yellow-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Pending</p>
                            <p class="text-lg font-semibold text-yellow-600"><?php echo $stats['pending_payments']; ?></p>
                        </div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-4">
                    <div class="flex items-center">
                        <div class="p-2 rounded-full bg-red-100">
                            <i class="fas fa-exclamation-triangle text-red-600"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm font-medium text-gray-500">Outstanding</p>
                            <p class="text-lg font-semibold text-red-600">₵<?php echo number_format($stats['total_outstanding'], 2); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search student or fee type..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                            </select>
                        </div>
                        <div>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payments List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <?php if (!in_array($user_role, ['student', 'parent'])): ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <?php endif; ?>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fee Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Due Date</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Date</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <?php if (!in_array($user_role, ['student', 'parent'])): ?>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?></div>
                                    </div>
                                </td>
                                <?php endif; ?>
                                <td class="px-6 py-4">
                                    <div>
                                        <div class="text-sm font-medium text-gray-900"><?php echo ucfirst(htmlspecialchars($payment['fee_type'])); ?></div>
                                        <div class="text-sm text-gray-500"><?php echo htmlspecialchars($payment['academic_year']); ?> - <?php echo ucfirst($payment['academic_term']); ?> Term</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">₵<?php echo number_format($payment['fee_amount'], 2); ?></div>
                                    <?php if ($payment['payment_status'] === 'paid'): ?>
                                    <div class="text-sm text-green-600">Paid: ₵<?php echo number_format($payment['amount_paid'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <div class="<?php echo $payment['days_overdue'] > 0 && $payment['payment_status'] !== 'paid' ? 'text-red-600 font-semibold' : 'text-gray-900'; ?>">
                                        <?php echo date('M j, Y', strtotime($payment['due_date'])); ?>
                                    </div>
                                    <?php if ($payment['days_overdue'] > 0 && $payment['payment_status'] !== 'paid'): ?>
                                    <div class="text-xs text-red-500">
                                        <?php echo $payment['days_overdue']; ?> day(s) overdue
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                        <?php 
                                        switch($payment['payment_status']) {
                                            case 'paid': echo 'bg-green-100 text-green-800'; break;
                                            case 'pending': echo 'bg-yellow-100 text-yellow-800'; break;
                                            case 'overdue': echo 'bg-red-100 text-red-800'; break;
                                        }
                                        ?>">
                                        <?php echo ucfirst($payment['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                    <?php echo $payment['payment_date'] ? date('M j, Y', strtotime($payment['payment_date'])) : '-'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <a href="view_payment.php?id=<?php echo $payment['id']; ?>" 
                                        class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                    <?php if ($payment['payment_status'] !== 'paid' && in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                                    <a href="collect_payment.php?payment_id=<?php echo $payment['id']; ?>" 
                                        class="text-green-600 hover:text-green-900">Collect</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($payments)): ?>
            <div class="text-center py-12">
                <i class="fas fa-receipt text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No payments found</h3>
                <p class="text-gray-500">
                    <?php if ($search || $status_filter !== 'all' || $date_from || $date_to): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No payment records have been created yet.
                    <?php endif; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="mt-8 flex justify-center">
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? "&search=$search" : ''; ?><?php echo $status_filter !== 'all' ? "&status=$status_filter" : ''; ?><?php echo $date_from ? "&date_from=$date_from" : ''; ?><?php echo $date_to ? "&date_to=$date_to" : ''; ?>" 
                        class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 
                        <?php echo $i === $page ? 'z-10 bg-blue-50 border-blue-500 text-blue-600' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                    <?php endfor; ?>
                </nav>
            </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
