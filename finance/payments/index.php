<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'finance_officer'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle payment status update
if (isset($_POST['update_status']) && isset($_POST['payment_id']) && isset($_POST['status'])) {
    $payment_id = filter_input(INPUT_POST, 'payment_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE student_payments SET payment_status = :status WHERE id = :payment_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':payment_id', $payment_id);
    
    if ($stmt->execute()) {
        $success_message = "Payment status updated successfully!";
    } else {
        $error_message = "Error updating payment status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$payment_method_filter = isset($_GET['payment_method']) ? $_GET['payment_method'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.student_id LIKE :search OR sp.receipt_number LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "sp.payment_status = :status";
    $params[':status'] = $status_filter;
}

if ($payment_method_filter) {
    $where_conditions[] = "sp.payment_method = :payment_method";
    $params[':payment_method'] = $payment_method_filter;
}

if ($date_from) {
    $where_conditions[] = "sp.payment_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "sp.payment_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch payments
$query = "SELECT sp.*, u.name as student_name, u.student_id, u.class, u.section,
          fs.structure_name, fs.total_amount as fee_amount
          FROM student_payments sp
          JOIN users u ON sp.student_id = u.id
          LEFT JOIN fee_structures fs ON sp.fee_structure_id = fs.id
          $where_clause
          ORDER BY sp.payment_date DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payment methods for filter
$methods_query = "SELECT DISTINCT payment_method FROM student_payments WHERE payment_method IS NOT NULL ORDER BY payment_method";
$methods_stmt = $db->query($methods_query);
$payment_methods = $methods_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get payment statistics
$stats_query = "SELECT 
    COUNT(*) as total_payments,
    SUM(amount_paid) as total_collected,
    COUNT(CASE WHEN payment_status = 'paid' THEN 1 END) as completed_payments,
    COUNT(CASE WHEN payment_status = 'pending' THEN 1 END) as pending_payments,
    COUNT(CASE WHEN payment_status = 'failed' THEN 1 END) as failed_payments
    FROM student_payments";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Payments Management";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Payments Management</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Finance
                    </a>
                    <a href="create.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Record Payment
                    </a>
                    <a href="bulk_import.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-upload mr-2"></i>Bulk Import
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Payments</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_payments']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-receipt text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Collected</p>
                            <p class="text-2xl font-bold text-green-600">₵<?php echo number_format($stats['total_collected'], 0); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Completed</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['completed_payments']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-check-circle text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['pending_payments']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-clock text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Failed</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['failed_payments']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by student name, ID, or receipt..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="failed" <?php echo $status_filter === 'failed' ? 'selected' : ''; ?>>Failed</option>
                                <option value="refunded" <?php echo $status_filter === 'refunded' ? 'selected' : ''; ?>>Refunded</option>
                            </select>
                        </div>
                        <div>
                            <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Methods</option>
                                <?php foreach ($payment_methods as $method): ?>
                                <option value="<?php echo htmlspecialchars($method); ?>" <?php echo $payment_method_filter === $method ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($method)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                placeholder="From Date" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                placeholder="To Date" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Payments Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Payment Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fee Structure</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Amount</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Method</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($payments as $payment): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                    <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($payment['student_id']); ?></div>
                                    <?php if ($payment['class'] && $payment['section']): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($payment['class'] . ' - ' . $payment['section']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900">Receipt: <?php echo htmlspecialchars($payment['receipt_number']); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo date('M j, Y', strtotime($payment['payment_date'])); ?></div>
                                    <?php if ($payment['transaction_id']): ?>
                                    <div class="text-xs text-gray-400">TXN: <?php echo htmlspecialchars($payment['transaction_id']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($payment['structure_name'] ?? 'N/A'); ?></div>
                                    <?php if ($payment['fee_amount']): ?>
                                    <div class="text-sm text-gray-500">Total: ₵<?php echo number_format($payment['fee_amount'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900">₵<?php echo number_format($payment['amount_paid'], 2); ?></div>
                                    <?php if ($payment['discount_amount'] > 0): ?>
                                    <div class="text-sm text-green-600">Discount: ₵<?php echo number_format($payment['discount_amount'], 2); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars(ucfirst($payment['payment_method'])); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_classes = [
                                        'paid' => 'bg-green-100 text-green-800',
                                        'pending' => 'bg-yellow-100 text-yellow-800',
                                        'failed' => 'bg-red-100 text-red-800',
                                        'refunded' => 'bg-blue-100 text-blue-800'
                                    ];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$payment['payment_status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($payment['payment_status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="view.php?id=<?php echo $payment['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                    <a href="receipt.php?id=<?php echo $payment['id']; ?>" class="text-green-600 hover:text-green-900 mr-3">Receipt</a>
                                    
                                    <?php if ($payment['payment_status'] === 'pending'): ?>
                                    <form action="" method="POST" class="inline mr-2">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <input type="hidden" name="status" value="paid">
                                        <button type="submit" name="update_status" class="text-green-600 hover:text-green-900">Confirm</button>
                                    </form>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="payment_id" value="<?php echo $payment['id']; ?>">
                                        <input type="hidden" name="status" value="failed">
                                        <button type="submit" name="update_status" class="text-red-600 hover:text-red-900" 
                                                onclick="return confirm('Are you sure you want to mark this payment as failed?')">Fail</button>
                                    </form>
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
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $payment_method_filter || $date_from || $date_to): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No payments have been recorded yet.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                    Record First Payment
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
