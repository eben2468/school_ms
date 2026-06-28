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

// Check if a single student statement view is requested
$view_student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);

if ($view_student_id) {
    // Security check
    if ($user_role === 'student' && $view_student_id != $user_id) {
        die("Access denied.");
    } elseif ($user_role === 'parent') {
        // A child is linked either via student_profiles.parent_id or the parent_students table.
        $check = $db->prepare("SELECT
                (SELECT COUNT(*) FROM student_profiles WHERE user_id = :sid1 AND parent_id = :pid1)
              + (SELECT COUNT(*) FROM parent_students WHERE student_id = :sid2 AND parent_id = :pid2) AS linked");
        $check->execute([':sid1' => $view_student_id, ':pid1' => $user_id, ':sid2' => $view_student_id, ':pid2' => $user_id]);
        if ($check->fetchColumn() == 0) {
            die("Access denied.");
        }
    }
    
    // Fetch student profile details
    $stmt = $db->prepare("SELECT u.name as student_name, sp.student_id as student_reg_no, sp.student_type, c.name as class_name
                          FROM users u
                          JOIN student_profiles sp ON u.id = sp.user_id
                          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                          LEFT JOIN classes c ON sc.class_id = c.id
                          WHERE u.id = :student_id LIMIT 1");
    $stmt->execute([':student_id' => $view_student_id]);
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        die("Student not found.");
    }

    // Fetch invoices, payments, penalties, and discounts to build a comprehensive timeline ledger
    $invoices = getStudentInvoices($view_student_id, $db);
    
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
            'desc' => "Invoice Charged (" . $inv['invoice_number'] . " - " . $inv['term_name'] . ", " . $inv['year_name'] . ")",
            'debit' => $inv['total_amount'],
            'credit' => 0.00
        ];
        $total_charged += (float)$inv['total_amount'];
        
        // Add discounts if any
        if ($inv['discount_amount'] > 0) {
            $ledger[] = [
                'date' => $inv['created_at'],
                'type' => 'discount',
                'desc' => "Scholarship/Discount Applied (" . $inv['invoice_number'] . ")",
                'debit' => 0.00,
                'credit' => $inv['discount_amount']
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
                'credit' => 0.00
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
                'credit' => $p['amount']
            ];
            $total_paid += (float)$p['amount'];
        }
    }
    
    // Sort timeline by date
    usort($ledger, function($a, $b) {
        return strcmp($a['date'], $b['date']);
    });
    
    include 'student_balances_statement.php';
    exit();
}

// Balance List dashboard
$search = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: '';

$where = ["u.role = 'student'"];
$params = [];

if ($user_role === 'student') {
    $where[] = "u.id = :user_id";
    $params[':user_id'] = $user_id;
} elseif ($user_role === 'parent') {
    $where[] = "(u.id IN (SELECT user_id FROM student_profiles WHERE parent_id = :user_id_a)
              OR u.id IN (SELECT student_id FROM parent_students WHERE parent_id = :user_id_b))";
    $params[':user_id_a'] = $user_id;
    $params[':user_id_b'] = $user_id;
}

if ($class_filter) {
    $where[] = "sc.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($search) {
    $where[] = "(u.name LIKE :search OR sp.student_id LIKE :search)";
    $params[':search'] = "%$search%";
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT u.id as student_id, u.name as student_name, sp.student_id as student_reg_no, sp.student_type, c.name as class_name,
                 COALESCE(SUM(i.total_amount + i.penalty_amount - i.discount_amount), 0.00) as total_charged,
                 COALESCE(SUM(i.amount_paid), 0.00) as total_paid
          FROM users u
          JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          LEFT JOIN finance_invoices i ON u.id = i.student_id AND i.status != 'cancelled'
          $where_clause
          GROUP BY u.id, u.name, sp.student_id, sp.student_type, c.name
          ORDER BY (COALESCE(SUM(i.total_amount + i.penalty_amount - i.discount_amount), 0.00) - COALESCE(SUM(i.amount_paid), 0.00)) DESC";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch classes for the filter — scoped to what the viewer is allowed to see.
if ($user_role === 'student') {
    // Only the student's own active class(es).
    $cls_stmt = $db->prepare("SELECT DISTINCT c.id, c.name, c.grade_level
                              FROM classes c
                              JOIN student_classes sc ON sc.class_id = c.id
                              WHERE sc.student_id = :uid AND sc.status = 'active' AND c.status = 'active'
                              ORDER BY c.grade_level, c.name");
    $cls_stmt->execute([':uid' => $user_id]);
    $classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif ($user_role === 'parent') {
    // Only the classes that the parent's children belong to.
    $cls_stmt = $db->prepare("SELECT DISTINCT c.id, c.name, c.grade_level
                              FROM classes c
                              JOIN student_classes sc ON sc.class_id = c.id AND sc.status = 'active'
                              WHERE c.status = 'active'
                                AND (sc.student_id IN (SELECT user_id FROM student_profiles WHERE parent_id = :pid_a)
                                  OR sc.student_id IN (SELECT student_id FROM parent_students WHERE parent_id = :pid_b))
                              ORDER BY c.grade_level, c.name");
    $cls_stmt->execute([':pid_a' => $user_id, ':pid_b' => $user_id]);
    $classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = $db->query("SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Account Statement</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Review student account statements and outstanding balances</p>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="md:col-span-2">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search student name or ID..." class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <select name="class_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo $class_filter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['grade_level'] . ' - ' . $c['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow-lg transition duration-200">
                                Apply Filter
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
                                    <th class="p-4">Reg ID</th>
                                    <th class="p-4">Student</th>
                                    <th class="p-4">Class</th>
                                    <th class="p-4">Type</th>
                                    <th class="p-4">Total Charged</th>
                                    <th class="p-4">Total Paid</th>
                                    <th class="p-4">Outstanding Balance</th>
                                    <th class="p-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50 text-sm">
                                <?php foreach ($balances as $b): 
                                    $bal = $b['total_charged'] - $b['total_paid'];
                                ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition duration-150">
                                    <td class="p-4 font-bold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($b['student_reg_no']); ?></td>
                                    <td class="p-4 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($b['student_name']); ?></td>
                                    <td class="p-4 text-gray-600 dark:text-gray-300"><?php echo htmlspecialchars($b['class_name'] ?: 'Unassigned'); ?></td>
                                    <td class="p-4">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold capitalize bg-blue-50 text-blue-700 dark:bg-blue-950/30 dark:text-blue-300">
                                            <?php echo htmlspecialchars($b['student_type'] ?: 'day'); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-gray-600 dark:text-gray-400 font-semibold"><?php echo formatFinanceCurrency($b['total_charged'], $db); ?></td>
                                    <td class="p-4 text-emerald-600 font-semibold"><?php echo formatFinanceCurrency($b['total_paid'], $db); ?></td>
                                    <td class="p-4 font-bold <?php echo $bal > 0 ? 'text-rose-500' : 'text-emerald-500'; ?>">
                                        <?php 
                                        if ($bal > 0) {
                                            echo formatFinanceCurrency($bal, $db);
                                        } elseif ($bal < 0) {
                                            echo formatFinanceCurrency(abs($bal), $db) . ' (Credit)';
                                        } else {
                                            echo formatFinanceCurrency(0.00, $db);
                                        }
                                        ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <a href="student_balances.php?student_id=<?php echo $b['student_id']; ?>" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-white text-xs font-bold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1">
                                            <i class="fas fa-file-invoice"></i> Statement
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
