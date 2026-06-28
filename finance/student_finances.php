<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// If parent is viewing, they need to select which student. Or if parent_id is set, get the first student.
$target_student_id = $user_id;

if ($user_role === 'parent') {
    // Get parent's students
    $students_stmt = $db->prepare("SELECT u.id, u.name FROM users u JOIN student_profiles sp ON u.id = sp.user_id WHERE sp.parent_id = :parent_id");
    $students_stmt->execute([':parent_id' => $user_id]);
    $my_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($my_students)) {
        die("No student profiles linked to this parent account.");
    }
    
    $selected_student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
    if ($selected_student_id) {
        // Validate student belongs to parent
        $check = false;
        foreach ($my_students as $ms) {
            if ($ms['id'] == $selected_student_id) {
                $check = true;
                break;
            }
        }
        if (!$check) {
            die("Access denied.");
        }
        $target_student_id = $selected_student_id;
    } else {
        $target_student_id = $my_students[0]['id'];
    }
}

// Fetch student profile details
$stmt = $db->prepare("SELECT u.name as student_name, sp.student_id as student_reg_no, sp.student_type, c.name as class_name
                      FROM users u
                      JOIN student_profiles sp ON u.id = sp.user_id
                      LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                      LEFT JOIN classes c ON sc.class_id = c.id
                      WHERE u.id = :student_id LIMIT 1");
$stmt->execute([':student_id' => $target_student_id]);
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    die("Student not found.");
}

// Fetch invoices, payments, penalties, and discounts to build a comprehensive timeline ledger
$invoices = getStudentInvoices($target_student_id, $db);

// Construct timeline ledger entries
$ledger = [];
$total_charged = 0.00;
$total_paid = 0.00;
$total_discount = 0.00;
$total_penalty = 0.00;

foreach ($invoices as $inv) {
    // Add structural charge entry
    $ledger[] = [
        'date' => $inv['created_at'],
        'type' => 'invoice',
        'desc' => "Invoice Billed (" . $inv['invoice_number'] . " - " . $inv['term_name'] . ", " . $inv['year_name'] . ")",
        'debit' => $inv['total_amount'],
        'credit' => 0.00,
        'status' => $inv['status']
    ];
    $total_charged += (float)$inv['total_amount'];
    
    // Add discounts if any
    if ($inv['discount_amount'] > 0) {
        $ledger[] = [
            'date' => $inv['created_at'],
            'type' => 'discount',
            'desc' => "Scholarship/Discount Applied (" . $inv['invoice_number'] . ")",
            'debit' => 0.00,
            'credit' => $inv['discount_amount'],
            'status' => 'applied'
        ];
        $total_discount += (float)$inv['discount_amount'];
    }
    
    // Add penalties if any
    if ($inv['penalty_amount'] > 0) {
        $ledger[] = [
            'date' => $inv['created_at'],
            'type' => 'penalty',
            'desc' => "Late Payment Penalty Applied (" . $inv['invoice_number'] . ")",
            'debit' => $inv['penalty_amount'],
            'credit' => 0.00,
            'status' => 'applied'
        ];
        $total_penalty += (float)$inv['penalty_amount'];
    }
    
    // Fetch specific payments for this invoice
    $pay_stmt = $db->prepare("SELECT * FROM finance_payments WHERE invoice_id = :invoice_id ORDER BY payment_date ASC");
    $pay_stmt->execute([':invoice_id' => $inv['id']]);
    $payments = $pay_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($payments as $p) {
        $ledger[] = [
            'date' => $p['payment_date'],
            'type' => 'payment',
            'desc' => "Payment Credit (" . $p['receipt_number'] . " via " . ucfirst(str_replace('_', ' ', $p['payment_method'])) . ")",
            'debit' => 0.00,
            'credit' => $p['amount'],
            'status' => 'paid'
        ];
        $total_paid += (float)$p['amount'];
    }
}

// Sort timeline by date descending (most recent first) for the dashboard view
usort($ledger, function($a, $b) {
    return strcmp($b['date'], $a['date']);
});

$net_balance = ($total_charged + $total_penalty - $total_discount) - $total_paid;

$title = "My Finances";
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
                <!-- Page Title -->
                <div class="bg-gradient-to-r from-green-600 to-emerald-600 rounded-xl p-4 mb-8 text-white shadow-lg">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-wallet mr-3"></i>
                                <?= $user_role === 'parent' ? "Student Finances" : "My Finances" ?>
                            </h1>
                            <p class="text-green-100">View billing invoices, transaction history, and outstanding balance</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <?php if ($user_role === 'parent' && isset($my_students) && count($my_students) > 1): ?>
                            <form method="GET" class="flex items-center gap-2">
                                <label for="student_id" class="text-sm font-semibold text-white whitespace-nowrap">Select Student:</label>
                                <select name="student_id" id="student_id" onchange="this.form.submit()" class="px-3 py-1.5 bg-green-700/50 border border-green-500 rounded-lg text-white font-medium focus:outline-none focus:ring-2 focus:ring-green-400">
                                    <?php foreach ($my_students as $ms): ?>
                                    <option value="<?= $ms['id'] ?>" <?= $ms['id'] == $target_student_id ? 'selected' : '' ?>><?= htmlspecialchars($ms['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </form>
                            <?php endif; ?>
                            <a href="/school_ms/finance/student_balances.php?student_id=<?= $target_student_id ?>" target="_blank" class="bg-white text-green-700 hover:bg-green-50 px-4 py-2 rounded-lg font-bold shadow transition flex items-center gap-2 text-sm whitespace-nowrap">
                                <i class="fas fa-print"></i> Statement of Account
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Profile Summary Banner -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700 mb-8 flex flex-col md:flex-row md:items-center justify-between gap-6">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center text-green-600 dark:text-green-400">
                            <i class="fas fa-user-graduate text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($student['student_name']) ?></h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400">Reg No: <span class="font-semibold text-gray-700 dark:text-gray-300"><?= htmlspecialchars($student['student_reg_no']) ?></span> | Class: <span class="font-semibold text-gray-700 dark:text-gray-300"><?= htmlspecialchars($student['class_name'] ?: 'Unassigned') ?></span></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wider block mr-2">Enrollment Status:</span>
                        <span class="px-3 py-1 rounded-full text-xs font-bold capitalize bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-200">
                            <?= htmlspecialchars($student['student_type'] ?: 'day') ?> student
                        </span>
                    </div>
                </div>

                <!-- Financial Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                    <!-- Net Balance Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 relative overflow-hidden">
                        <div class="absolute right-0 bottom-0 translate-x-2 translate-y-2 opacity-10 text-gray-400">
                            <i class="fas fa-hand-holding-usd text-8xl"></i>
                        </div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                            <?= $net_balance >= 0 ? "Outstanding Balance" : "Credit Balance" ?>
                        </p>
                        <p class="text-3xl font-extrabold mt-2 <?= $net_balance > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-green-600 dark:text-green-400' ?>">
                            <?= formatFinanceCurrency(abs($net_balance), $db) ?>
                        </p>
                        <div class="mt-4 flex items-center text-xs font-semibold">
                            <?php if ($net_balance > 0): ?>
                            <span class="text-rose-500 bg-rose-50 dark:bg-rose-950/30 px-2 py-0.5 rounded">Payment Due</span>
                            <?php else: ?>
                            <span class="text-green-500 bg-green-50 dark:bg-green-950/30 px-2 py-0.5 rounded">Up to Date</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Total Billed Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 relative overflow-hidden">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Billed</p>
                        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-2">
                            <?= formatFinanceCurrency($total_charged, $db) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-4"><i class="fas fa-file-invoice mr-1"></i> Gross invoiced fees</p>
                    </div>

                    <!-- Total Penalties/Discounts Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 relative overflow-hidden">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Scholarships & Adjustments</p>
                        <p class="text-2xl font-bold text-emerald-600 dark:text-emerald-400 mt-2">
                            -<?= formatFinanceCurrency($total_discount, $db) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-4">
                            <?php if ($total_penalty > 0): ?>
                            <span class="text-rose-500">+<?= formatFinanceCurrency($total_penalty, $db) ?> late penalties</span>
                            <?php else: ?>
                            <span>No active penalties</span>
                            <?php endif; ?>
                        </p>
                    </div>

                    <!-- Total Paid Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 relative overflow-hidden">
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Payments Credit</p>
                        <p class="text-2xl font-bold text-green-600 dark:text-green-400 mt-2">
                            <?= formatFinanceCurrency($total_paid, $db) ?>
                        </p>
                        <p class="text-xs text-gray-500 mt-4"><i class="fas fa-receipt mr-1"></i> Cleared receipt credits</p>
                    </div>
                </div>

                <!-- Ledger History Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                            <i class="fas fa-history mr-2 text-green-600"></i>
                            Transaction & Ledger History
                        </h2>
                    </div>

                    <?php if (empty($ledger)): ?>
                    <div class="p-12 text-center">
                        <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-receipt text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Finance Invoices Found</h3>
                        <p class="text-gray-600 dark:text-gray-400">There are no billing or payment records logged to this student profile yet.</p>
                    </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 text-xs font-bold text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                    <th class="px-6 py-4">Date</th>
                                    <th class="px-6 py-4">Transaction Details</th>
                                    <th class="px-6 py-4">Type</th>
                                    <th class="px-6 py-4 text-right">Debit (+)</th>
                                    <th class="px-6 py-4 text-right">Credit (-)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700 text-sm">
                                <?php foreach ($ledger as $entry): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition">
                                    <td class="px-6 py-4 text-gray-500 dark:text-gray-400 whitespace-nowrap">
                                        <?= date('M d, Y H:i', strtotime($entry['date'])) ?>
                                    </td>
                                    <td class="px-6 py-4 font-semibold text-gray-800 dark:text-white">
                                        <?= htmlspecialchars($entry['desc']) ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if ($entry['type'] === 'invoice'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-amber-100 text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">Invoice</span>
                                        <?php elseif ($entry['type'] === 'payment'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-green-100 text-green-800 dark:bg-green-950/30 dark:text-green-300">Payment</span>
                                        <?php elseif ($entry['type'] === 'discount'): ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-800 dark:bg-blue-950/30 dark:text-blue-300">Discount</span>
                                        <?php else: ?>
                                        <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-red-100 text-red-800 dark:bg-red-950/30 dark:text-red-300">Penalty</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right font-semibold text-rose-500">
                                        <?= $entry['debit'] > 0 ? formatFinanceCurrency($entry['debit'], $db) : '-' ?>
                                    </td>
                                    <td class="px-6 py-4 text-right font-semibold text-green-600 dark:text-green-400">
                                        <?= $entry['credit'] > 0 ? formatFinanceCurrency($entry['credit'], $db) : '-' ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
