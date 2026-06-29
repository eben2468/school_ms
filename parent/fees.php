<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
require_once '../includes/paystack_helper.php';
$database = new Database();
$db = $database->getConnection();

// Heal tenant DBs that predate the finance module.
ensureFinanceTables($db);

// Show online "Pay" buttons when Paystack is the active gateway.
$paystack_enabled = isPaystackEnabled();

$parent_id = $_SESSION['user_id'];
$student_id = $_GET['student_id'] ?? null;

// Verify parent has access to this student
if ($student_id) {
    $access_query = "SELECT COUNT(*) as count FROM parent_students WHERE parent_id = :parent_id AND student_id = :student_id";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':parent_id', $parent_id);
    $access_stmt->bindParam(':student_id', $student_id);
    $access_stmt->execute();
    $access = $access_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($access['count'] == 0) {
        header("Location: index.php");
        exit();
    }
}

// Get parent's children if no specific student selected
if (!$student_id) {
    $children_query = "
        SELECT u.id, u.name 
        FROM users u
        JOIN parent_students ps ON u.id = ps.student_id
        WHERE ps.parent_id = :parent_id AND u.status = 'active'
        ORDER BY u.name
    ";
    $children_stmt = $db->prepare($children_query);
    $children_stmt->bindParam(':parent_id', $parent_id);
    $children_stmt->execute();
    $children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($children) == 1) {
        $student_id = $children[0]['id'];
    }
}

$student_info = null;
$fees = [];
$fee_summary = null;

if ($student_id) {
    // Get student information
    $student_query = "
        SELECT u.name, sp.student_id, c.name as class_name, c.grade_level, c.academic_year
        FROM users u
        LEFT JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
        LEFT JOIN classes c ON sc.class_id = c.id
        WHERE u.id = :student_id
    ";
    $student_stmt = $db->prepare($student_query);
    $student_stmt->bindParam(':student_id', $student_id);
    $student_stmt->execute();
    $student_info = $student_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get fee records for the student
    $current_context = $database->getCurrentAcademicContext();

    // Years that actually exist for this school (newest first).
    try {
        $valid_years = $db->query("SELECT year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $valid_years = [];
    }

    $academic_year = $_GET['year'] ?? ($current_context['year_name'] ?? '');
    // getCurrentAcademicContext() can return a date-based fallback year that the
    // school has not actually created. If the chosen year isn't a real one,
    // default to the most recent year that exists (the one holding invoices).
    if (!empty($valid_years) && !in_array($academic_year, $valid_years, true)) {
        $academic_year = $valid_years[0];
    }

    $fees_query = "
        SELECT i.id, 
               CONCAT('Invoice #', i.invoice_number) as fee_type,
               CONCAT(t.term_name, ' School Fees') as description,
               (i.total_amount + i.penalty_amount - i.discount_amount) as amount,
               i.amount_paid as paid_amount,
               i.due_date,
               i.status,
               (SELECT MAX(payment_date) FROM finance_payments WHERE invoice_id = i.id) as payment_date
        FROM finance_invoices i
        JOIN academic_years ay ON i.academic_year_id = ay.id
        JOIN academic_terms t ON i.term_id = t.id
        WHERE i.student_id = :student_id 
        AND ay.year_name = :academic_year
        AND i.status != 'cancelled'
        ORDER BY i.due_date ASC, i.invoice_number ASC
    ";
    $fees = [];
    try {
        $fees_stmt = $db->prepare($fees_query);
        $fees_stmt->bindParam(':student_id', $student_id);
        $fees_stmt->bindParam(':academic_year', $academic_year);
        $fees_stmt->execute();
        $fees = $fees_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Finance module/tables may not be provisioned for this tenant yet.
        error_log("parent fees query failed: " . $e->getMessage());
    }

    // Calculate fee summary
    if (!empty($fees)) {
        $total_amount = array_sum(array_column($fees, 'amount'));
        $total_paid = array_sum(array_column($fees, 'paid_amount'));
        $total_pending = $total_amount - $total_paid;
        
        $fee_summary = [
            'total_fees' => count($fees),
            'total_amount' => $total_amount,
            'total_paid' => $total_paid,
            'total_pending' => $total_pending,
            'paid_count' => count(array_filter($fees, function($fee) { return $fee['status'] === 'paid'; })),
            'pending_count' => count(array_filter($fees, function($fee) { return in_array($fee['status'], ['pending', 'partial', 'partially_paid']); })),
            'overdue_count' => count(array_filter($fees, function($fee) { return $fee['status'] === 'overdue'; }))
        ];
    }
}

function getStatusColor($status) {
    switch($status) {
        case 'paid': return 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
        case 'partial':
        case 'partially_paid': return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200';
        case 'overdue': return 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200';
        default: return 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
    }
}

function getStatusIcon($status) {
    switch($status) {
        case 'paid': return 'check-circle';
        case 'partial':
        case 'partially_paid': return 'clock';
        case 'overdue': return 'exclamation-triangle';
        default: return 'hourglass-half';
    }
}

$title = "Student Fees";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Student Fees</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">View your child's fee status and payment history</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <div class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg">
                            <div class="flex items-center mb-3">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <span class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($child['name']); ?></span>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a href="?student_id=<?php echo $child['id']; ?>" class="inline-flex items-center gap-1 bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                                    <i class="fas fa-money-bill-wave"></i> View Fees
                                </a>
                                <a href="/school_ms/finance/student_balances.php?student_id=<?php echo $child['id']; ?>" target="_blank" rel="noopener" class="inline-flex items-center gap-1 bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-semibold px-3 py-1.5 rounded-lg transition">
                                    <i class="fas fa-file-invoice"></i> Statement
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_info): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($student_info['name']); ?></h3>
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    <?php echo htmlspecialchars($student_info['class_name'] ?? 'No Class Assigned'); ?>
                                    <?php if ($student_info['student_id']): ?>
                                    • ID: <?php echo htmlspecialchars($student_info['student_id']); ?>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                        
                        <!-- Actions: Statement + Academic Year Selector -->
                        <div class="flex flex-col items-end gap-3">
                            <a href="/school_ms/finance/student_balances.php?student_id=<?php echo $student_id; ?>" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-lg shadow transition">
                                <i class="fas fa-file-invoice"></i> View / Download Statement
                            </a>
                            <form method="GET" class="flex items-center space-x-2">
                                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year:</label>
                                <select name="year" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php
                                    try {
                                        $years_stmt = $db->query("SELECT year_name FROM academic_years ORDER BY year_name DESC");
                                        $db_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
                                    } catch (Exception $e) {
                                        $db_years = [];
                                    }
                                    if (empty($db_years)) {
                                        $current_year = date('Y');
                                        for ($i = -2; $i <= 1; $i++) {
                                            $db_years[] = ($current_year + $i) . '-' . ($current_year + $i + 1);
                                        }
                                    }
                                    foreach ($db_years as $yr) {
                                        $selected = ($yr === $academic_year) ? 'selected' : '';
                                        echo "<option value=\"" . htmlspecialchars($yr) . "\" $selected>" . htmlspecialchars($yr) . "</option>";
                                    }
                                    ?>
                                </select>
                                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                    <i class="fas fa-search"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Fee Summary -->
                <?php if ($fee_summary): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-invoice-dollar text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Amount</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">₵<?php echo number_format($fee_summary['total_amount'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Paid</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400">₵<?php echo number_format($fee_summary['total_paid'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exclamation-circle text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pending</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400">₵<?php echo number_format($fee_summary['total_pending'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-list text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Fees</h3>
                                <p class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?php echo $fee_summary['total_fees']; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Fees Table -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Fee Details</h3>
                        <p class="text-sm text-gray-600 dark:text-gray-400">Academic Year: <?php echo $academic_year; ?></p>
                    </div>

                    <?php if (empty($fees)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-receipt text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Fee Records</h3>
                        <p class="text-gray-500 dark:text-gray-400">No fee records found for the selected academic year.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Fee Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Paid</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Balance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Payment Date</th>
                                    <?php if ($paystack_enabled): ?>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($fees as $fee): ?>
                                <?php $balance = $fee['amount'] - $fee['paid_amount']; ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3">
                                                <i class="fas fa-money-bill text-blue-600 dark:text-blue-400 text-sm"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($fee['fee_type']); ?>
                                                </div>
                                                <?php if ($fee['description']): ?>
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    <?php echo htmlspecialchars($fee['description']); ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        ₵<?php echo number_format($fee['amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400">
                                        ₵<?php echo number_format($fee['paid_amount'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo $balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'; ?>">
                                        <?php if ($balance < 0): ?>
                                            ₵<?php echo number_format(abs($balance), 2); ?> (Credit)
                                        <?php else: ?>
                                            ₵<?php echo number_format($balance, 2); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, Y', strtotime($fee['due_date'])); ?>
                                        <?php if (strtotime($fee['due_date']) < time() && $fee['status'] !== 'paid'): ?>
                                        <span class="text-red-500 text-xs">(Overdue)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo getStatusColor($fee['status']); ?>">
                                            <i class="fas fa-<?php echo getStatusIcon($fee['status']); ?> mr-1"></i>
                                            <?php echo ucfirst($fee['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo $fee['payment_date'] ? date('M j, Y', strtotime($fee['payment_date'])) : '-'; ?>
                                    </td>
                                    <?php if ($paystack_enabled): ?>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($balance > 0): ?>
                                        <button type="button"
                                            onclick="openPaystackPayment({invoiceId: <?php echo (int)$fee['id']; ?>, amount: <?php echo (float)$balance; ?>, label: '<?php echo addslashes($fee['fee_type']); ?>'})"
                                            class="inline-flex items-center px-3 py-1.5 bg-green-600 hover:bg-green-700 text-white rounded-lg text-xs font-semibold transition-colors">
                                            <i class="fas fa-credit-card mr-1.5"></i> Pay
                                        </button>
                                        <?php else: ?>
                                        <span class="text-green-600 dark:text-green-400 text-xs font-medium">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
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

<?php include '../includes/paystack_inline.php'; // Paystack checkout (renders only when enabled) ?>
