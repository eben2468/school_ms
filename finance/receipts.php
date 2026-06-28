<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'student', 'parent'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';
require_once 'includes/invoice_functions.php';

$database = new Database();
$db = $database->getConnection();
$pdo = $db;
require_once '../includes/settings_helper.php';

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// If single receipt print requested
if (isset($_GET['payment_id'])) {
    $payment_id = filter_input(INPUT_GET, 'payment_id', FILTER_SANITIZE_NUMBER_INT);
    
    // Fetch payment details
    $stmt = $db->prepare("SELECT p.*, i.invoice_number, i.student_id, i.total_amount, i.discount_amount, i.penalty_amount, i.amount_paid as total_paid_to_date,
                                 u.name as student_name, sp.student_id as student_reg_no, c.name as class_name,
                                 ay.year_name, t.term_name, rec.name as recorded_by_name
                          FROM finance_payments p
                          JOIN finance_invoices i ON p.invoice_id = i.id
                          JOIN users u ON i.student_id = u.id
                          JOIN student_profiles sp ON u.id = sp.user_id
                          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                          LEFT JOIN classes c ON sc.class_id = c.id
                          LEFT JOIN users rec ON p.recorded_by = rec.id
                          JOIN academic_years ay ON i.academic_year_id = ay.id
                          JOIN academic_terms t ON i.term_id = t.id
                          WHERE p.id = :payment_id LIMIT 1");
    $stmt->execute([':payment_id' => $payment_id]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$payment) {
        die("Receipt not found.");
    }
    
    // Enforce security for student / parents
    if ($user_role === 'student' && $payment['student_id'] != $user_id) {
        die("Access denied.");
    } elseif ($user_role === 'parent') {
        $check = $db->prepare("SELECT COUNT(*) FROM student_profiles WHERE user_id = :student_id AND parent_id = :parent_id");
        $check->execute([':student_id' => $payment['student_id'], ':parent_id' => $user_id]);
        if ($check->fetchColumn() == 0) {
            die("Access denied.");
        }
    }
    
    // Check print format: thermal (80mm) vs A4
    $format = isset($_GET['format']) && $_GET['format'] === 'thermal' ? 'thermal' : 'a4';
    
    if ($format === 'thermal') {
        include 'receipts_thermal.php';
        exit();
    } else {
        include 'receipts_a4.php';
        exit();
    }
}

// Fetch all receipts
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';

$where = [];
$params = [];

if ($user_role === 'student') {
    $where[] = "i.student_id = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($user_role === 'parent') {
    $where[] = "i.student_id IN (SELECT user_id FROM student_profiles WHERE parent_id = :user_id)";
    $params[':user_id'] = $user_id;
}

if ($search) {
    $where[] = "(p.receipt_number LIKE :search OR u.name LIKE :search OR i.invoice_number LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT r.*, p.amount, p.payment_date, p.payment_method, i.invoice_number, 
                 u.name as student_name, sp.student_id as student_reg_no
          FROM finance_receipts r
          JOIN finance_payments p ON r.payment_id = p.id
          JOIN finance_invoices i ON p.invoice_id = i.id
          JOIN users u ON i.student_id = u.id
          JOIN student_profiles sp ON u.id = sp.user_id
          $where_clause
          ORDER BY r.id DESC";
$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Receipts Ledger</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Reprint or view receipts generated for payments</p>
                    </div>
                </div>

                <!-- Search -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-3">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search by receipt number, student name or invoice..." class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow-lg transition duration-200">
                                Search Receipts
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Receipt #</th>
                                    <th class="p-4">Student</th>
                                    <th class="p-4">Invoice Ref</th>
                                    <th class="p-4">Method</th>
                                    <th class="p-4">Amount Paid</th>
                                    <th class="p-4">Date</th>
                                    <th class="p-4 text-center">Print Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50 text-sm">
                                <?php foreach ($receipts as $r): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition duration-150">
                                    <td class="p-4 font-bold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($r['receipt_number']); ?></td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($r['student_name']); ?></div>
                                        <div class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($r['student_reg_no']); ?></div>
                                    </td>
                                    <td class="p-4 font-semibold text-blue-600 dark:text-blue-400"><?php echo htmlspecialchars($r['invoice_number']); ?></td>
                                    <td class="p-4 capitalize text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($r['payment_method']); ?></td>
                                    <td class="p-4 font-bold text-emerald-600"><?php echo formatFinanceCurrency($r['amount'], $db); ?></td>
                                    <td class="p-4 text-gray-500 dark:text-gray-400 font-medium"><?php echo date('M d, Y H:i', strtotime($r['payment_date'])); ?></td>
                                    <td class="p-4 flex items-center justify-center gap-2">
                                        <a href="receipts.php?payment_id=<?php echo $r['payment_id']; ?>&format=a4" target="_blank" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-white text-xs font-bold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1">
                                            <i class="fas fa-file-pdf"></i> A4 Format
                                        </a>
                                        <a href="receipts.php?payment_id=<?php echo $r['payment_id']; ?>&format=thermal" target="_blank" class="bg-blue-500 hover:bg-blue-600 text-white text-xs font-bold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1">
                                            <i class="fas fa-print"></i> Thermal
                                        </a>
                                    </td>
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
