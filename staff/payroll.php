<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Create table if not exists (in case it was missed in migration)
$createTableQuery = "
CREATE TABLE IF NOT EXISTS salary_payments (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT NOT NULL,
    month TINYINT NOT NULL,
    year INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('pending', 'partial', 'paid', 'cancelled', 'failed') DEFAULT 'pending',
    payment_date DATE,
    payment_method VARCHAR(50),
    reference_number VARCHAR(100),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)";
$db->exec($createTableQuery);

// Helper function to sync salary payments to finance expenses
function syncSalaryExpense($payment_id, $db) {
    if (!function_exists('logFinanceAudit')) {
        require_once __DIR__ . '/../finance/includes/finance_functions.php';
    }
    
    // Fetch the salary payment details, including staff name
    $stmt = $db->prepare("
        SELECT sp.*, u.name as staff_name 
        FROM salary_payments sp 
        JOIN users u ON sp.user_id = u.id 
        WHERE sp.id = ?
    ");
    $stmt->execute([$payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        return;
    }
    
    $status = $payment['status'];
    $amount = floatval($payment['amount']);
    $staff_name = $payment['staff_name'];
    $monthName = date('F', mktime(0, 0, 0, $payment['month'], 1));
    $year = $payment['year'];
    
    // Check if an expense record is already linked
    $expStmt = $db->prepare("SELECT id FROM finance_expenses WHERE salary_payment_id = ?");
    $expStmt->execute([$payment_id]);
    $existingExpense = $expStmt->fetch(PDO::FETCH_ASSOC);
    
    $recorded_by = $_SESSION['user_id'] ?? 0;
    
    if (in_array($status, ['paid', 'partial'])) {
        $description = "Salary payment for " . $staff_name . " - " . $monthName . " " . $year;
        $vendor = $staff_name;
        $expense_date = $payment['payment_date'] ?: date('Y-m-d');
        
        if ($existingExpense) {
            // Update existing expense
            $updateExp = $db->prepare("
                UPDATE finance_expenses 
                SET category = 'Salaries', amount = :amount, description = :description, vendor = :vendor, expense_date = :expense_date, status = 'approved', approved_by = :approved_by
                WHERE id = :id
            ");
            $updateExp->execute([
                ':amount' => $amount,
                ':description' => $description,
                ':vendor' => $vendor,
                ':expense_date' => $expense_date,
                ':approved_by' => $recorded_by,
                ':id' => $existingExpense['id']
            ]);
            logFinanceAudit('Update Expense', 'Expenses', $existingExpense['id'], "Synced updated salary expense for $staff_name ($monthName $year) to ₵$amount", $db);
        } else {
            // Insert new expense
            $insertExp = $db->prepare("
                INSERT INTO finance_expenses (category, amount, description, vendor, expense_date, recorded_by, status, approved_by, salary_payment_id) 
                VALUES ('Salaries', :amount, :description, :vendor, :expense_date, :recorded_by, 'approved', :approved_by, :salary_payment_id)
            ");
            $insertExp->execute([
                ':amount' => $amount,
                ':description' => $description,
                ':vendor' => $vendor,
                ':expense_date' => $expense_date,
                ':recorded_by' => $recorded_by,
                ':approved_by' => $recorded_by,
                ':salary_payment_id' => $payment_id
            ]);
            $new_exp_id = $db->lastInsertId();
            logFinanceAudit('Record Expense', 'Expenses', $new_exp_id, "Synced salary expense for $staff_name ($monthName $year) of ₵$amount", $db);
        }
    } else {
        // Status is pending, cancelled, or failed -> remove the linked expense if it exists
        if ($existingExpense) {
            $deleteExp = $db->prepare("DELETE FROM finance_expenses WHERE id = ?");
            $deleteExp->execute([$existingExpense['id']]);
            logFinanceAudit('Delete Expense', 'Expenses', $existingExpense['id'], "Removed linked salary expense for $staff_name ($monthName $year) because payment status is $status", $db);
        }
    }
}

$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'record_payment') {
        $staff_id = $_POST['staff_id'];
        $month = $_POST['month'];
        $year = $_POST['year'];
        $amount = $_POST['amount'];
        $status = $_POST['status'];
        $payment_date = $_POST['payment_date'];
        $payment_method = $_POST['payment_method'];
        $reference_number = $_POST['reference_number'];
        $notes = $_POST['notes'];

        // Check if a record already exists
        $checkStmt = $db->prepare("SELECT id FROM salary_payments WHERE user_id = ? AND month = ? AND year = ?");
        $checkStmt->execute([$staff_id, $month, $year]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            $payment_id = $existing['id'];
            $updateStmt = $db->prepare("UPDATE salary_payments SET amount = ?, status = ?, payment_date = ?, payment_method = ?, reference_number = ?, notes = ? WHERE id = ?");
            if ($updateStmt->execute([$amount, $status, $payment_date, $payment_method, $reference_number, $notes, $payment_id])) {
                $message = "Payment recorded successfully.";
                syncSalaryExpense($payment_id, $db);
            } else {
                $error = "Failed to update payment record.";
            }
        } else {
            $insertStmt = $db->prepare("INSERT INTO salary_payments (user_id, month, year, amount, status, payment_date, payment_method, reference_number, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if ($insertStmt->execute([$staff_id, $month, $year, $amount, $status, $payment_date, $payment_method, $reference_number, $notes])) {
                $payment_id = $db->lastInsertId();
                $message = "Payment recorded successfully.";
                syncSalaryExpense($payment_id, $db);
            } else {
                $error = "Failed to record payment.";
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'process_all') {
        $month = $_POST['month'];
        $year = $_POST['year'];
        $payment_date = date('Y-m-d');
        
        // Find all active staff
        $staffQuery = $db->prepare("SELECT u.id, tp.salary FROM users u JOIN teacher_profiles tp ON u.id = tp.user_id WHERE u.status = 'active' AND u.role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr')");
        $staffQuery->execute();
        $staffList = $staffQuery->fetchAll(PDO::FETCH_ASSOC);
        
        $processed = 0;
        foreach ($staffList as $s) {
            $staff_id = $s['id'];
            $amount = $s['salary'] ?? 0;
            if ($amount <= 0) continue;
            
            $checkStmt = $db->prepare("SELECT id, status FROM salary_payments WHERE user_id = ? AND month = ? AND year = ?");
            $checkStmt->execute([$staff_id, $month, $year]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                $insertStmt = $db->prepare("INSERT INTO salary_payments (user_id, month, year, amount, status, payment_date, payment_method, notes) VALUES (?, ?, ?, ?, 'paid', ?, 'bank_transfer', 'Bulk processed')");
                if($insertStmt->execute([$staff_id, $month, $year, $amount, $payment_date])) {
                    $payment_id = $db->lastInsertId();
                    $processed++;
                    syncSalaryExpense($payment_id, $db);
                }
            } elseif ($existing['status'] !== 'paid') {
                $updateStmt = $db->prepare("UPDATE salary_payments SET status = 'paid', payment_date = ?, payment_method = 'bank_transfer', notes = 'Bulk processed' WHERE id = ?");
                if($updateStmt->execute([$payment_date, $existing['id']])) {
                    $processed++;
                    syncSalaryExpense($existing['id'], $db);
                }
            }
        }
        $message = "Processed $processed pending payments successfully.";
    }
}


// Filter values
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Stats Calculation
$totalPayrollStmt = $db->query("SELECT SUM(salary) as total FROM teacher_profiles tp JOIN users u ON tp.user_id = u.id WHERE u.status = 'active' AND u.role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr')");
$totalPayroll = $totalPayrollStmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

$avgSalaryStmt = $db->query("SELECT AVG(salary) as avg FROM teacher_profiles tp JOIN users u ON tp.user_id = u.id WHERE u.status = 'active' AND u.role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr') AND salary > 0");
$avgSalary = $avgSalaryStmt->fetch(PDO::FETCH_ASSOC)['avg'] ?? 0;

$paymentsMadeStmt = $db->prepare("SELECT COUNT(*) as cnt FROM salary_payments WHERE month = ? AND year = ? AND status = 'paid'");
$paymentsMadeStmt->execute([$selected_month, $selected_year]);
$paymentsMade = $paymentsMadeStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

$activeStaffStmt = $db->query("SELECT COUNT(*) as cnt FROM users WHERE status = 'active' AND role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr')");
$activeStaffCount = $activeStaffStmt->fetch(PDO::FETCH_ASSOC)['cnt'];

$pendingPayments = max(0, $activeStaffCount - $paymentsMade);

// Main Payroll Data Query
$query = "
    SELECT 
        u.id as staff_id, u.name, u.role, 
        tp.employee_id, tp.department, tp.salary,
        sp.id as payment_id, sp.status as payment_status, sp.payment_date, sp.payment_method, sp.reference_number, sp.amount as paid_amount
    FROM users u 
    JOIN teacher_profiles tp ON u.id = tp.user_id 
    LEFT JOIN salary_payments sp ON u.id = sp.user_id AND sp.month = :month AND sp.year = :year
    WHERE u.status = 'active' AND u.role IN ('teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr')
    ORDER BY u.name ASC
";
$stmt = $db->prepare($query);
$stmt->bindParam(':month', $selected_month);
$stmt->bindParam(':year', $selected_year);
$stmt->execute();
$staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary calculations
$totalDisbursed = 0;
$totalPendingAmt = 0;
$deptSummary = [];

foreach ($staff_list as $s) {
    $dept = $s['department'] ?: 'Unassigned';
    if (!isset($deptSummary[$dept])) {
        $deptSummary[$dept] = ['disbursed' => 0, 'pending' => 0];
    }
    
    $salary = floatval($s['salary']);
    
    if (($s['payment_status'] ?? 'pending') === 'paid') {
        $amt = floatval($s['paid_amount'] ?: $salary);
        $totalDisbursed += $amt;
        $deptSummary[$dept]['disbursed'] += $amt;
    } else {
        $totalPendingAmt += $salary;
        $deptSummary[$dept]['pending'] += $salary;
    }
}

$title = "Payroll & Compensation";
include '../includes/header.php';
include '../includes/sidebar.php';

$currency = getSchoolSetting('currency_symbol', '₵');
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{
    paymentModalOpen: false,
    selectedStaff: {
        staff_id: '',
        name: '',
        salary: '',
        amount: '',
        status: 'paid',
        payment_date: '<?= date('Y-m-d') ?>',
        payment_method: 'bank_transfer',
        reference_number: ''
    },
    openPaymentModal(staff) {
        this.selectedStaff = Object.assign({}, this.selectedStaff, staff);
        this.paymentModalOpen = true;
    }
}">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Payroll & Compensation</h1>
                                <p class="text-blue-100 text-lg">Manage staff salaries, process payments, and view payroll statistics</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-money-check-alt text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <!-- Stats Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Total Payroll This Month</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-white mt-1"><?= htmlspecialchars($currency) ?><?= number_format($totalPayroll, 2) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i class="fas fa-dollar-sign text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Payments Made</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-white mt-1"><?= $paymentsMade ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900/50 rounded-full flex items-center justify-center text-green-600 dark:text-green-400">
                            <i class="fas fa-check-double text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Pending Payments</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-white mt-1"><?= $pendingPayments ?></p>
                        </div>
                        <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900/50 rounded-full flex items-center justify-center text-yellow-600 dark:text-yellow-400">
                            <i class="fas fa-clock text-xl"></i>
                        </div>
                    </div>
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Average Salary</p>
                            <p class="text-2xl font-bold text-gray-800 dark:text-white mt-1"><?= htmlspecialchars($currency) ?><?= number_format($avgSalary, 2) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center text-purple-600 dark:text-purple-400">
                            <i class="fas fa-chart-pie text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Controls Row -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 flex flex-col md:flex-row items-center justify-between gap-4">
                    <form method="GET" class="flex flex-wrap items-center gap-4 w-full md:w-auto">
                        <div class="flex items-center gap-2">
                            <label class="text-gray-700 dark:text-gray-300 font-medium">Month:</label>
                            <select name="month" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <?php
                                for ($i = 1; $i <= 12; $i++) {
                                    $selected = ($i == $selected_month) ? 'selected' : '';
                                    echo "<option value=\"$i\" $selected>" . date('F', mktime(0, 0, 0, $i, 1)) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <label class="text-gray-700 dark:text-gray-300 font-medium">Year:</label>
                            <select name="year" class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <?php
                                $currentYear = date('Y');
                                for ($y = $currentYear - 5; $y <= $currentYear + 1; $y++) {
                                    $selected = ($y == $selected_year) ? 'selected' : '';
                                    echo "<option value=\"$y\" $selected>$y</option>";
                                }
                                ?>
                            </select>
                        </div>
                        
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors shadow-sm">
                            <i class="fas fa-filter mr-2"></i> Filter
                        </button>
                    </form>
                    
                    <div>
                        <form method="POST" onsubmit="return confirm('Are you sure you want to process all pending payments for this month?');">
                            <input type="hidden" name="action" value="process_all">
                            <input type="hidden" name="month" value="<?= $selected_month ?>">
                            <input type="hidden" name="year" value="<?= $selected_year ?>">
                            <button type="submit" class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition-colors shadow-sm flex items-center">
                                <i class="fas fa-layer-group mr-2"></i> Process All Pending
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Payroll Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-bold text-gray-800 dark:text-white">Staff Payroll List</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Staff Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role & Dept</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Base Salary</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Payment Info</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if(empty($staff_list)): ?>
                                <tr>
                                    <td colspan="6" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No staff found for this period.</td>
                                </tr>
                                <?php else: ?>
                                    <?php foreach($staff_list as $staff): 
                                        $status = $staff['payment_status'] ?? 'pending';
                                        $statusClass = '';
                                        if ($status === 'paid') $statusClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
                                        elseif ($status === 'partial') $statusClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400';
                                        else $statusClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
                                        
                                        $safeStaff = htmlspecialchars(json_encode([
                                            'staff_id' => $staff['staff_id'],
                                            'name' => $staff['name'],
                                            'salary' => $staff['salary'],
                                            'amount' => $staff['paid_amount'] ?: $staff['salary'],
                                            'status' => $status,
                                            'payment_date' => $staff['payment_date'] ?: date('Y-m-d'),
                                            'payment_method' => $staff['payment_method'] ?: 'bank_transfer',
                                            'reference_number' => $staff['reference_number'] ?: ''
                                        ]), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-blue-400 to-blue-600 rounded-full flex items-center justify-center text-white font-bold">
                                                    <?= strtoupper(substr($staff['name'], 0, 1)) ?>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['name']) ?></div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">ID: <?= htmlspecialchars($staff['employee_id'] ?? 'N/A') ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white capitalize"><?= htmlspecialchars(str_replace('_', ' ', $staff['role'])) ?></div>
                                            <div class="text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($staff['department'] ?: 'Unassigned') ?></div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                                            <?= htmlspecialchars($currency) ?><?= number_format((float)$staff['salary'], 2) ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full <?= $statusClass ?>">
                                                <?= ucfirst($status) ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                            <?php if ($status !== 'pending'): ?>
                                                <div class="font-medium text-gray-900 dark:text-white"><?= date('M d, Y', strtotime($staff['payment_date'])) ?></div>
                                                <div class="text-xs uppercase mt-1">
                                                    <?= str_replace('_', ' ', $staff['payment_method']) ?> 
                                                    <?= $staff['reference_number'] ? '(#'.htmlspecialchars($staff['reference_number']).')' : '' ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="italic text-gray-400">Not Processed</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <button @click="openPaymentModal(<?= $safeStaff ?>)" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 bg-blue-50 dark:bg-blue-900/20 px-3 py-1 rounded-md transition-colors mr-2">
                                                <i class="fas fa-edit"></i> Record
                                            </button>
                                            <a href="payslip.php?id=<?= $staff['staff_id'] ?>&month=<?= $selected_month ?>&year=<?= $selected_year ?>" target="_blank" class="text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-300 bg-gray-100 dark:bg-gray-700 px-3 py-1 rounded-md transition-colors inline-flex items-center mr-2">
                                                <i class="fas fa-file-invoice mr-1"></i> Payslip
                                            </a>
                                            <a href="payslip.php?id=<?= $staff['staff_id'] ?>&month=<?= $selected_month ?>&year=<?= $selected_year ?>&format=thermal" target="_blank" class="text-blue-600 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 bg-blue-50 dark:bg-blue-900/20 px-3 py-1 rounded-md transition-colors inline-flex items-center">
                                                <i class="fas fa-receipt mr-1"></i> Thermal
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payroll Summary -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Overview -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Summary Overview</h3>
                        
                        <div class="space-y-4">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600 dark:text-gray-400">Total Disbursed</span>
                                <span class="text-lg font-bold text-green-600 dark:text-green-400"><?= htmlspecialchars($currency) ?><?= number_format($totalDisbursed, 2) ?></span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <?php 
                                    $disbPct = ($totalPayroll > 0) ? ($totalDisbursed / $totalPayroll) * 100 : 0; 
                                    if ($disbPct > 100) $disbPct = 100;
                                ?>
                                <div class="bg-green-500 h-2 rounded-full" style="width: <?= $disbPct ?>%"></div>
                            </div>
                            
                            <div class="flex justify-between items-center mt-4">
                                <span class="text-gray-600 dark:text-gray-400">Total Pending</span>
                                <span class="text-lg font-bold text-yellow-600 dark:text-yellow-400"><?= htmlspecialchars($currency) ?><?= number_format($totalPendingAmt, 2) ?></span>
                            </div>
                            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                                <?php 
                                    $pendPct = ($totalPayroll > 0) ? ($totalPendingAmt / $totalPayroll) * 100 : 0; 
                                    if ($pendPct > 100) $pendPct = 100;
                                ?>
                                <div class="bg-yellow-500 h-2 rounded-full" style="width: <?= $pendPct ?>%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Department Breakdown -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                        <h3 class="text-lg font-bold text-gray-800 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-3 mb-4">Department Breakdown</h3>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead>
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Department</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Disbursed</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Total</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($deptSummary)): ?>
                                    <tr><td colspan="4" class="px-4 py-3 text-center text-sm text-gray-500">No data available</td></tr>
                                    <?php else: ?>
                                        <?php foreach($deptSummary as $dept => $vals): 
                                            $dTotal = $vals['disbursed'] + $vals['pending'];
                                        ?>
                                        <tr>
                                            <td class="px-4 py-3 text-sm font-medium text-gray-900 dark:text-white capitalize"><?= htmlspecialchars($dept) ?></td>
                                            <td class="px-4 py-3 text-sm text-right text-green-600 dark:text-green-400 font-semibold"><?= htmlspecialchars($currency) ?><?= number_format($vals['disbursed'], 2) ?></td>
                                            <td class="px-4 py-3 text-sm text-right text-yellow-600 dark:text-yellow-400 font-semibold"><?= htmlspecialchars($currency) ?><?= number_format($vals['pending'], 2) ?></td>
                                            <td class="px-4 py-3 text-sm text-right text-gray-900 dark:text-white font-bold"><?= htmlspecialchars($currency) ?><?= number_format($dTotal, 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
    
    <!-- Record Payment Modal -->
    <div x-show="paymentModalOpen" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <!-- Background overlay -->
            <div x-show="paymentModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" @click="paymentModalOpen = false"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <!-- Modal panel -->
            <div x-show="paymentModalOpen" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
                
                <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    <input type="hidden" name="staff_id" x-model="selectedStaff.staff_id">
                    <input type="hidden" name="month" value="<?= $selected_month ?>">
                    <input type="hidden" name="year" value="<?= $selected_year ?>">
                    
                    <div class="px-4 pt-5 pb-4 sm:p-6 sm:pb-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="sm:flex sm:items-start">
                            <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 dark:bg-blue-900 sm:mx-0 sm:h-10 sm:w-10">
                                <i class="fas fa-money-check-alt text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                                <h3 class="text-lg leading-6 font-medium text-gray-900 dark:text-white" id="modal-title">
                                    Record Payment - <span x-text="selectedStaff.name"></span>
                                </h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    Base Salary: <?= htmlspecialchars($currency) ?><span x-text="selectedStaff.salary"></span>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="px-6 py-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Amount Paid (<?= htmlspecialchars($currency) ?>)</label>
                            <input type="number" step="0.01" name="amount" x-model="selectedStaff.amount" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Status</label>
                            <select name="status" x-model="selectedStaff.status" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="paid">Paid</option>
                                <option value="partial">Partial</option>
                                <option value="pending">Pending</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Payment Date</label>
                            <input type="date" name="payment_date" x-model="selectedStaff.payment_date" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" required>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Payment Method</label>
                            <select name="payment_method" x-model="selectedStaff.payment_method" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="bank_transfer">Bank Transfer</option>
                                <option value="cash">Cash</option>
                                <option value="cheque">Cheque</option>
                                <option value="mobile_money">Mobile Money</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reference Number / Transaction ID</label>
                            <input type="text" name="reference_number" x-model="selectedStaff.reference_number" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="e.g. TRN123456789">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Notes (Optional)</label>
                            <textarea name="notes" rows="2" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Add any payment notes..."></textarea>
                        </div>
                    </div>
                    
                    <div class="px-4 py-3 bg-gray-50 dark:bg-gray-700/50 sm:px-6 sm:flex sm:flex-row-reverse rounded-b-xl border-t border-gray-200 dark:border-gray-700">
                        <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">
                            Save Payment
                        </button>
                        <button type="button" @click="paymentModalOpen = false" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-800 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
