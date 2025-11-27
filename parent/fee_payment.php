<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get parent's children
$children_query = "
    SELECT u.id, u.name, u.student_id, c.name as class_name, c.section
    FROM users u
    LEFT JOIN parent_students ps ON u.id = ps.student_id
    LEFT JOIN classes c ON u.class_id = c.id
    WHERE ps.parent_id = :parent_id AND u.role = 'student'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $user_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

$student_id = $_GET['student_id'] ?? null;

// Validate student belongs to parent
if ($student_id) {
    $valid_student = false;
    foreach ($children as $child) {
        if ($child['id'] == $student_id) {
            $valid_student = true;
            $selected_student = $child;
            break;
        }
    }
    if (!$valid_student) {
        $student_id = null;
    }
}

// Get fee information for selected student
$fees = [];
$fee_summary = [];
if ($student_id) {
    // Get fees for the student
    $fees_query = "
        SELECT f.*, ft.fee_type, ft.amount as fee_amount, ft.description
        FROM fees f
        LEFT JOIN fee_structures ft ON f.fee_structure_id = ft.id
        WHERE f.student_id = :student_id
        ORDER BY f.due_date DESC
    ";
    $fees_stmt = $db->prepare($fees_query);
    $fees_stmt->bindParam(':student_id', $student_id);
    $fees_stmt->execute();
    $fees = $fees_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate fee summary
    $total_due = 0;
    $total_paid = 0;
    $overdue_amount = 0;
    
    foreach ($fees as $fee) {
        $total_due += $fee['amount_due'];
        $total_paid += $fee['amount_paid'];
        
        if ($fee['status'] === 'overdue') {
            $overdue_amount += ($fee['amount_due'] - $fee['amount_paid']);
        }
    }
    
    $fee_summary = [
        'total_due' => $total_due,
        'total_paid' => $total_paid,
        'balance' => $total_due - $total_paid,
        'overdue_amount' => $overdue_amount
    ];
}

// Handle payment processing
if ($_POST && isset($_POST['pay_fee'])) {
    $fee_id = $_POST['fee_id'];
    $payment_amount = $_POST['payment_amount'];
    $payment_method = $_POST['payment_method'];
    
    // Process payment (simplified)
    try {
        $update_query = "
            UPDATE fees 
            SET amount_paid = amount_paid + :payment_amount,
                status = CASE 
                    WHEN (amount_paid + :payment_amount) >= amount_due THEN 'paid'
                    ELSE 'partial'
                END,
                last_payment_date = NOW()
            WHERE id = :fee_id
        ";
        $update_stmt = $db->prepare($update_query);
        $update_stmt->bindParam(':payment_amount', $payment_amount);
        $update_stmt->bindParam(':fee_id', $fee_id);
        $update_stmt->execute();
        
        $success_message = "Payment of ₵" . number_format($payment_amount, 2) . " processed successfully!";
        
        // Refresh the page to show updated data
        header("Location: fee_payment.php?student_id=" . $student_id . "&success=1");
        exit();
    } catch (PDOException $e) {
        $error_message = "Payment processing failed. Please try again.";
    }
}

$title = "Fee Payment";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Fee Payment</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Manage and pay school fees for your children</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <!-- Success Message -->
                <?php if (isset($_GET['success'])): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-check-circle mr-2"></i>Payment processed successfully!
                </div>
                <?php endif; ?>

                <!-- Error Message -->
                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo $error_message; ?>
                </div>
                <?php endif; ?>

                <!-- Student Selection -->
                <?php if (!$student_id && !empty($children)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Select Child</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <?php foreach ($children as $child): ?>
                        <a href="?student_id=<?php echo $child['id']; ?>" class="p-4 border border-gray-200 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            <div class="flex items-center">
                                <i class="fas fa-user-graduate text-blue-500 mr-3"></i>
                                <div>
                                    <span class="font-medium text-gray-900 dark:text-white block"><?php echo htmlspecialchars($child['name']); ?></span>
                                    <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($child['class_name']); ?></span>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($student_id && isset($selected_student)): ?>
                <!-- Student Info -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <div class="flex items-center">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-4">
                            <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($selected_student['name']); ?></h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                <?php echo htmlspecialchars($selected_student['class_name']); ?>
                                <?php if ($selected_student['student_id']): ?>
                                • ID: <?php echo htmlspecialchars($selected_student['student_id']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Fee Summary -->
                <?php if (!empty($fee_summary)): ?>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-money-bill text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Due</h3>
                                <p class="text-2xl font-bold text-blue-600 dark:text-blue-400">₵<?php echo number_format($fee_summary['total_due'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Total Paid</h3>
                                <p class="text-2xl font-bold text-green-600 dark:text-green-400">₵<?php echo number_format($fee_summary['total_paid'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-balance-scale text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Balance</h3>
                                <p class="text-2xl font-bold text-orange-600 dark:text-orange-400">₵<?php echo number_format($fee_summary['balance'], 2); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Overdue</h3>
                                <p class="text-2xl font-bold text-red-600 dark:text-red-400">₵<?php echo number_format($fee_summary['overdue_amount'], 2); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Fee Details -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Fee Details</h3>
                    </div>

                    <?php if (empty($fees)): ?>
                    <div class="text-center py-12">
                        <i class="fas fa-money-bill-wave text-gray-400 text-6xl mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Fee Records</h3>
                        <p class="text-gray-500 dark:text-gray-400">No fee records found for this student.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Fee Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount Due</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Amount Paid</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Balance</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Action</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($fees as $fee): ?>
                                <?php $balance = $fee['amount_due'] - $fee['amount_paid']; ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                        <?php echo ucfirst(str_replace('_', ' ', $fee['fee_type'] ?? 'General Fee')); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        ₵<?php echo number_format($fee['amount_due'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        ₵<?php echo number_format($fee['amount_paid'], 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium <?php echo $balance > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'; ?>">
                                        ₵<?php echo number_format($balance, 2); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                        <?php echo date('M j, Y', strtotime($fee['due_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php
                                            switch($fee['status']) {
                                                case 'paid': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'partial': echo 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'; break;
                                                case 'overdue': echo 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; break;
                                                default: echo 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200'; break;
                                            }
                                            ?>">
                                            <?php echo ucfirst($fee['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm">
                                        <?php if ($balance > 0): ?>
                                        <button onclick="openPaymentModal(<?php echo $fee['id']; ?>, <?php echo $balance; ?>, '<?php echo addslashes($fee['fee_type'] ?? 'General Fee'); ?>')" 
                                                class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium">
                                            Pay Now
                                        </button>
                                        <?php else: ?>
                                        <span class="text-green-600 dark:text-green-400">Paid</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-user-graduate text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Children Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No student records are associated with your account.</p>
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

<!-- Payment Modal -->
<div id="paymentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden z-50">
    <div class="flex items-center justify-center min-h-screen p-4">
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full">
            <div class="p-6">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Make Payment</h3>
                <form method="POST">
                    <input type="hidden" id="modal_fee_id" name="fee_id">
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Fee Type</label>
                        <p id="modal_fee_type" class="text-gray-900 dark:text-white"></p>
                    </div>
                    
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Amount to Pay</label>
                        <input type="number" id="modal_payment_amount" name="payment_amount" step="0.01" min="0.01" 
                               class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                    </div>
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Payment Method</label>
                        <select name="payment_method" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                            <option value="">Select Payment Method</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="mobile_money">Mobile Money</option>
                            <option value="card">Credit/Debit Card</option>
                        </select>
                    </div>
                    
                    <div class="flex justify-end space-x-3">
                        <button type="button" onclick="closePaymentModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                            Cancel
                        </button>
                        <button type="submit" name="pay_fee" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                            Process Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function openPaymentModal(feeId, balance, feeType) {
    document.getElementById('modal_fee_id').value = feeId;
    document.getElementById('modal_payment_amount').value = balance.toFixed(2);
    document.getElementById('modal_payment_amount').max = balance.toFixed(2);
    document.getElementById('modal_fee_type').textContent = feeType.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    document.getElementById('paymentModal').classList.remove('hidden');
}

function closePaymentModal() {
    document.getElementById('paymentModal').classList.add('hidden');
}
</script>
