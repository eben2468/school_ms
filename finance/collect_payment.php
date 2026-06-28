<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';
require_once 'includes/finance_functions.php';
require_once 'includes/invoice_functions.php';
require_once 'includes/payment_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Handle payment verification callback if simulated online payment
if (isset($_GET['simulate_success']) && isset($_GET['reference'])) {
    $ref = filter_input(INPUT_GET, 'reference', FILTER_SANITIZE_STRING);
    $inv_id = filter_input(INPUT_GET, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
    $amount = filter_input(INPUT_GET, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    
    if ($ref && $inv_id && $amount) {
        $result = recordPayment($inv_id, $amount, 'online', $ref, 'Simulated Online Payment Successful', $db);
        if ($result['success']) {
            $success = "Online Payment verified! Receipt generated: " . $result['receipt_number'];
        } else {
            $error = "Error recording online payment: " . $result['message'];
        }
    }
}

// Prefill from invoice_id query string
$prefill_invoice = null;
$prefill_invoice_id = filter_input(INPUT_GET, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
if ($prefill_invoice_id) {
    $prefill_invoice = getInvoiceDetails($prefill_invoice_id, $db);
}

// Handle payment collection form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_GET['simulate_success'])) {
    $invoice_id = filter_input(INPUT_POST, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $payment_method = filter_input(INPUT_POST, 'payment_method', FILTER_SANITIZE_STRING);
    $reference_number = filter_input(INPUT_POST, 'reference_number', FILTER_SANITIZE_STRING) ?: '';
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING) ?: '';
    
    // Reject methods that have been disabled in settings
    $method_setting_map = [
        'cash' => 'cash', 'mobile_money' => 'mobile', 'bank_transfer' => 'bank', 'online' => 'card'
    ];
    if ($payment_method && isset($method_setting_map[$payment_method]) && !isPaymentMethodEnabled($method_setting_map[$payment_method])) {
        $error = "The selected payment method is currently disabled. Please choose another method.";
        $payment_method = '';
    }

    if (!$error && $invoice_id && $amount && $payment_method) {
        // Fetch invoice to verify remaining balance
        $invoice = getInvoiceDetails($invoice_id, $db);
        if ($invoice) {
            $outstanding = $invoice['grand_total'] - $invoice['amount_paid'];
            if ($amount <= 0) {
                $error = "Amount must be greater than zero.";
            } else {
                if ($payment_method === 'online') {
                    // Simulated online payment gateway redirect
                    $email = 'student@school.com'; // Simple fallback
                    $res = initializePaystackPayment($invoice_id, $amount, $email, $db);
                    if ($res['success']) {
                        header("Location: " . $res['payment_url']);
                        exit();
                    } else {
                        $error = "Failed to initialize payment gateway.";
                    }
                } else {
                    // Manual Cash/Momo/Bank Payment
                    $result = recordPayment($invoice_id, $amount, $payment_method, $reference_number, $notes, $db);
                    if ($result['success']) {
                        $success = "Payment successfully recorded! Receipt Number: " . $result['receipt_number'];
                        // Refresh details if prefilled
                        if ($prefill_invoice_id == $invoice_id) {
                            $prefill_invoice = getInvoiceDetails($prefill_invoice_id, $db);
                        }
                    } else {
                        $error = "Failed to record payment: " . $result['message'];
                    }
                }
            }
        } else {
            $error = "Invoice not found.";
        }
    } else {
        $error = "Please fill in all required fields.";
    }
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
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Collect Fee Payment</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Record manual cash/mobile money payments or initiate online processing</p>
                    </div>
                    <a href="payments.php" class="inline-flex items-center text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 font-semibold px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-700 transition">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Ledger
                    </a>
                </div>

                <?php if ($success): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 text-emerald-800 p-4 rounded-xl shadow-sm mb-6 dark:bg-emerald-950/20 dark:text-emerald-300 flex items-center gap-3">
                    <i class="fas fa-check-circle text-emerald-500 text-lg"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="bg-rose-50 border-l-4 border-rose-500 text-rose-800 p-4 rounded-xl shadow-sm mb-6 dark:bg-rose-950/20 dark:text-rose-300 flex items-center gap-3">
                    <i class="fas fa-exclamation-circle text-rose-500 text-lg"></i>
                    <span class="font-medium"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left: Payment Form -->
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 p-6 md:p-8">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white mb-6">Payment Form</h2>
                            
                            <form method="POST" id="collectForm" class="space-y-6">
                                <?php if ($prefill_invoice): ?>
                                    <!-- Prefilled Student Info -->
                                    <div class="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-200 dark:border-gray-700 flex justify-between items-center mb-6">
                                        <div>
                                            <span class="text-xs text-gray-400 font-bold block uppercase tracking-wider">Student Details</span>
                                            <span class="font-bold text-gray-800 dark:text-white text-lg"><?php echo htmlspecialchars($prefill_invoice['student_name']); ?></span>
                                            <span class="text-sm text-gray-500 dark:text-gray-400 ml-2">(<?php echo htmlspecialchars($prefill_invoice['student_reg_no']); ?>)</span>
                                            <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars($prefill_invoice['class_name'] . ' • ' . $prefill_invoice['year_name']); ?></div>
                                        </div>
                                        <div class="text-right">
                                            <span class="text-xs text-gray-400 font-bold block uppercase tracking-wider">Invoice Balance</span>
                                            <span class="font-extrabold text-2xl text-rose-500"><?php echo formatFinanceCurrency($prefill_invoice['grand_total'] - $prefill_invoice['amount_paid'], $db); ?></span>
                                        </div>
                                    </div>
                                    <input type="hidden" name="invoice_id" value="<?php echo $prefill_invoice['id']; ?>">
                                <?php else: ?>
                                    <!-- Student Selector Autocomplete Search (Prefills Invoice) -->
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Search Student</label>
                                        <div class="relative">
                                            <input type="text" id="student_search" autocomplete="off" placeholder="Type student name or ID..." class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                            <div id="autocomplete_results" class="absolute left-0 right-0 mt-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-xl overflow-hidden hidden z-10"></div>
                                        </div>
                                    </div>
                                    <input type="hidden" name="invoice_id" id="selected_invoice_id">
                                    
                                    <!-- Selected Student / Invoice Info -->
                                    <div id="student_info_block" class="bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-200 dark:border-gray-700 hidden">
                                        <div class="flex justify-between items-center">
                                            <div>
                                                <span class="font-bold text-gray-800 dark:text-white block" id="student_lbl_name"></span>
                                                <span class="text-xs text-gray-400 font-bold uppercase tracking-wider block" id="student_lbl_id"></span>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-xs text-gray-400 font-bold block uppercase tracking-wider">Balance Due</span>
                                                <span class="font-extrabold text-2xl text-rose-500" id="student_lbl_bal"></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Amount Inputs -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Payment Amount *</label>
                                    <div class="relative rounded-xl shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 dark:text-gray-400 text-sm">₵</span>
                                        </div>
                                        <input type="number" step="0.01" min="0.01" name="amount" id="pay_amount" value="<?php if ($prefill_invoice) { $bal = $prefill_invoice['grand_total'] - $prefill_invoice['amount_paid']; echo $bal > 0 ? htmlspecialchars(number_format($bal, 2, '.', '')) : ''; } ?>" required class="w-full pl-8 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="0.00">
                                    </div>
                                </div>

                                <!-- Payment Method -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Payment Method *</label>
                                    <select name="payment_method" id="payment_method" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                        <?php if (isPaymentMethodEnabled('cash')): ?>
                                        <option value="cash">Cash</option>
                                        <?php endif; ?>
                                        <?php if (isPaymentMethodEnabled('mobile')): ?>
                                        <option value="mobile_money">Mobile Money</option>
                                        <?php endif; ?>
                                        <?php if (isPaymentMethodEnabled('bank')): ?>
                                        <option value="bank_transfer">Bank Transfer</option>
                                        <?php endif; ?>
                                        <?php if (isPaymentMethodEnabled('card')): ?>
                                        <option value="online">Online Payment (Gateway sandbox)</option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <!-- Reference / Transaction Number -->
                                <div id="ref_block">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Reference / Transaction Number <span class="text-gray-400 font-normal">(auto-generated if left blank)</span></label>
                                    <input type="text" name="reference_number" placeholder="e.g., MMO-82947194 or Bank Check #" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                </div>

                                <!-- Notes -->
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Payment Notes</label>
                                    <textarea name="notes" rows="2" placeholder="e.g. Paid by Parent, Term 2 installment..." class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition"></textarea>
                                </div>

                                <button type="submit" class="w-full bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold py-3.5 rounded-xl shadow-lg hover:shadow-xl transition flex justify-center items-center gap-2">
                                    <i class="fas fa-money-bill-wave"></i> Confirm and Record Payment
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Right: Invoice Details Preview -->
                    <div class="lg:col-span-1">
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 p-6 sticky top-6">
                            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-6 border-b border-gray-100 dark:border-gray-700/50 pb-4">Invoice Ledger Summary</h3>
                            
                            <?php if ($prefill_invoice): ?>
                                <div class="space-y-4 text-sm">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Invoice Number:</span>
                                        <span class="font-bold text-gray-800 dark:text-white"><?php echo htmlspecialchars($prefill_invoice['invoice_number']); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Total Structural Charge:</span>
                                        <span class="font-semibold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($prefill_invoice['total_amount'], $db); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400 text-amber-500">Penalties (+):</span>
                                        <span class="font-semibold text-amber-600"><?php echo formatFinanceCurrency($prefill_invoice['penalty_amount'], $db); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400 text-emerald-500">Discounts (-):</span>
                                        <span class="font-semibold text-emerald-600"><?php echo formatFinanceCurrency($prefill_invoice['discount_amount'], $db); ?></span>
                                    </div>
                                    <div class="flex justify-between border-t border-gray-100 dark:border-gray-700/50 pt-3">
                                        <span class="text-gray-500 font-semibold">Grand Total:</span>
                                        <span class="font-bold text-gray-900 dark:text-white"><?php echo formatFinanceCurrency($prefill_invoice['grand_total'], $db); ?></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-emerald-500 font-semibold">Amount Paid:</span>
                                        <span class="font-bold text-emerald-600"><?php echo formatFinanceCurrency($prefill_invoice['amount_paid'], $db); ?></span>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="text-center py-12 text-gray-400" id="empty_ledger_lbl">
                                    <i class="fas fa-file-invoice text-4xl mb-3"></i>
                                    <p class="text-sm">Search and select a student to load invoice structure</p>
                                </div>
                                <div class="space-y-4 text-sm hidden" id="ledger_preview_block">
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Invoice Number:</span>
                                        <span class="font-bold text-gray-800 dark:text-white" id="lbl_inv_no"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-gray-400">Grand Total:</span>
                                        <span class="font-bold text-gray-800 dark:text-white" id="lbl_total"></span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="text-emerald-500 font-semibold">Paid To Date:</span>
                                        <span class="font-bold text-emerald-600" id="lbl_paid"></span>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
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

<script>

// Student Autocomplete Logic
var searchInput = document.getElementById('student_search');
if (searchInput) {
    searchInput.addEventListener('input', function() {
        var query = this.value;
        var resultsBox = document.getElementById('autocomplete_results');
        
        if (query.length < 2) {
            resultsBox.classList.add('hidden');
            return;
        }
        
        fetch('ajax/search_students.php?query=' + encodeURIComponent(query))
            .then(response => response.json())
            .then(data => {
                resultsBox.innerHTML = '';
                if (data.length === 0) {
                    resultsBox.innerHTML = '<div class="p-3 text-sm text-gray-500">No students with outstanding invoices found</div>';
                    resultsBox.classList.remove('hidden');
                    return;
                }
                
                data.forEach(student => {
                    var item = document.createElement('div');
                    item.className = 'p-3 hover:bg-gray-100 dark:hover:bg-gray-700 cursor-pointer text-sm transition';
                    item.innerHTML = '<strong>' + student.name + '</strong> (' + student.student_id + ')<br><span class="text-xs text-gray-400">Class: ' + student.class_name + ' • Unpaid Invoice: ' + student.invoice_number + '</span>';
                    
                    item.addEventListener('click', function() {
                        // Prefill Student
                        document.getElementById('selected_invoice_id').value = student.id;
                        document.getElementById('student_lbl_name').innerText = student.name;
                        document.getElementById('student_lbl_id').innerText = student.student_id + ' • ' + student.class_name;
                        document.getElementById('student_lbl_bal').innerText = '₵' + student.outstanding_balance;
                        
                        // Load ledger
                        document.getElementById('lbl_inv_no').innerText = student.invoice_number;
                        document.getElementById('lbl_total').innerText = '₵' + student.grand_total;
                        document.getElementById('lbl_paid').innerText = '₵' + student.amount_paid;
                        
                        document.getElementById('student_info_block').classList.remove('hidden');
                        document.getElementById('ledger_preview_block').classList.remove('hidden');
                        document.getElementById('empty_ledger_lbl').classList.add('hidden');
                        
                        document.getElementById('pay_amount').removeAttribute('max');
                        document.getElementById('pay_amount').value = student.outstanding_balance > 0 ? student.outstanding_balance : '';
                        
                        resultsBox.classList.add('hidden');
                        searchInput.value = '';
                    });
                    
                    resultsBox.appendChild(item);
                });
                resultsBox.classList.remove('hidden');
            });
    });
}
</script>