<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';
require_once 'includes/invoice_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Handle invoice generation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'generate_student') {
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
            $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_SANITIZE_NUMBER_INT);
            
            if ($student_id && $academic_year_id && $term_id) {
                $res = generateStudentInvoice($student_id, $academic_year_id, $term_id, $db);
                if ($res) {
                    $success = "Invoice successfully generated for student!";
                } else {
                    $error = "Could not generate invoice. Student might already be invoiced or there is no fee structure configured for their class.";
                }
            } else {
                $error = "Please fill in all fields.";
            }
        } elseif ($_POST['action'] === 'generate_class') {
            $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
            $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_SANITIZE_NUMBER_INT);
            
            if ($class_id && $academic_year_id && $term_id) {
                $count = generateClassInvoices($class_id, $academic_year_id, $term_id, $db);
                if ($count > 0) {
                    $success = "Successfully generated $count invoice(s) for the selected class!";
                } else {
                    $error = "No invoices generated. Students might already be invoiced or there is no fee structure configured.";
                }
            } else {
                $error = "Please fill in all fields.";
            }
        } elseif ($_POST['action'] === 'generate_school') {
            $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
            $term_id = filter_input(INPUT_POST, 'term_id', FILTER_SANITIZE_NUMBER_INT);
            
            if ($academic_year_id && $term_id) {
                $count = generateSchoolInvoices($academic_year_id, $term_id, $db);
                if ($count > 0) {
                    $success = "Successfully generated $count invoice(s) school-wide!";
                } else {
                    $error = "No invoices generated. All students might already be invoiced or no fee structures configured.";
                }
            } else {
                $error = "Please fill in all fields.";
            }
        } elseif ($_POST['action'] === 'cancel_invoice') {
            $inv_id = filter_input(INPUT_POST, 'invoice_id', FILTER_SANITIZE_NUMBER_INT);
            if ($inv_id) {
                try {
                    $stmt = $db->prepare("UPDATE finance_invoices SET status = 'cancelled' WHERE id = :id");
                    $stmt->execute([':id' => $inv_id]);
                    logFinanceAudit('Cancel Invoice', 'Invoices', $inv_id, "Cancelled invoice ID: $inv_id", $db);
                    $success = "Invoice successfully cancelled.";
                } catch (PDOException $e) {
                    $error = "Error cancelling invoice: " . $e->getMessage();
                }
            }
        }
    }
}

// Mark overdue invoices first
markOverdueInvoices($db);

// Filter params
$status_filter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_STRING) ?: '';
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$search_filter = filter_input(INPUT_GET, 'search', FILTER_SANITIZE_STRING) ?: '';

// Build list query
$where = [];
$params = [];

if ($status_filter) {
    $where[] = "i.status = :status";
    $params[':status'] = $status_filter;
}

if ($class_filter) {
    $where[] = "sc.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($search_filter) {
    $where[] = "(u.name LIKE :search OR sp.student_id LIKE :search OR i.invoice_number LIKE :search)";
    $params[':search'] = '%' . $search_filter . '%';
}

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

$query = "SELECT i.*, u.name as student_name, sp.student_id as student_reg_no, c.name as class_name, 
                 ay.year_name, t.term_name,
                 (i.total_amount + i.penalty_amount - i.discount_amount) as grand_total
          FROM finance_invoices i
          JOIN users u ON i.student_id = u.id
          JOIN student_profiles sp ON u.id = sp.user_id
          JOIN academic_years ay ON i.academic_year_id = ay.id
          JOIN academic_terms t ON i.term_id = t.id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY i.id DESC";

$stmt = $db->prepare($query);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v);
}
$stmt->execute();
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filters lists
$classes = $db->query("SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$academic_terms = $db->query("SELECT * FROM academic_terms ORDER BY term_number")->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Student Invoices</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Generate and manage terms student invoices</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button onclick="openModal('schoolModal')" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2.5 rounded-xl transition flex items-center gap-2">
                            <i class="fas fa-school"></i> School Invoices
                        </button>
                        <button onclick="openModal('classModal')" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-xl transition flex items-center gap-2">
                            <i class="fas fa-users"></i> Class Invoices
                        </button>
                        <button onclick="openModal('studentModal')" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2.5 rounded-xl transition flex items-center gap-2">
                            <i class="fas fa-user-plus"></i> Single Invoice
                        </button>
                    </div>
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

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search_filter); ?>" placeholder="Search student name, ID or invoice..." class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                        </div>
                        <div>
                            <select name="status" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Statuses</option>
                                <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                <option value="partially_paid" <?php echo $status_filter === 'partially_paid' ? 'selected' : ''; ?>>Partially Paid</option>
                                <option value="paid" <?php echo $status_filter === 'paid' ? 'selected' : ''; ?>>Paid</option>
                                <option value="overdue" <?php echo $status_filter === 'overdue' ? 'selected' : ''; ?>>Overdue</option>
                                <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            </select>
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

                <!-- Table Card -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Invoice #</th>
                                    <th class="p-4">Student</th>
                                    <th class="p-4">Class / Term</th>
                                    <th class="p-4">Total Amount</th>
                                    <th class="p-4">Amount Paid</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50 text-sm">
                                <?php foreach ($invoices as $inv): 
                                    $outstanding = $inv['grand_total'] - $inv['amount_paid'];
                                ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20 transition duration-150">
                                    <td class="p-4 font-bold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($inv['invoice_number']); ?></td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($inv['student_name']); ?></div>
                                        <div class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($inv['student_reg_no']); ?></div>
                                    </td>
                                    <td class="p-4">
                                        <div class="font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($inv['class_name'] ?: 'N/A'); ?></div>
                                        <div class="text-xs text-gray-400 font-medium"><?php echo htmlspecialchars($inv['year_name'] . ' • ' . $inv['term_name']); ?></div>
                                    </td>
                                    <td class="p-4 font-bold text-gray-900 dark:text-white"><?php echo formatFinanceCurrency($inv['grand_total'], $db); ?></td>
                                    <td class="p-4 text-emerald-600 font-semibold"><?php echo formatFinanceCurrency($inv['amount_paid'], $db); ?></td>
                                    <td class="p-4">
                                        <?php 
                                        $badge = 'bg-gray-100 text-gray-800';
                                        if ($inv['status'] === 'paid') $badge = 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300';
                                        elseif ($inv['status'] === 'partially_paid') $badge = 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300';
                                        elseif ($inv['status'] === 'pending') $badge = 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300';
                                        elseif ($inv['status'] === 'overdue') $badge = 'bg-rose-100 text-rose-800 dark:bg-rose-950/40 dark:text-rose-300';
                                        ?>
                                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold <?php echo $badge; ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $inv['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="p-4 flex items-center justify-center gap-2">
                                        <?php if ($inv['status'] !== 'paid' && $inv['status'] !== 'cancelled'): ?>
                                        <a href="collect_payment.php?invoice_id=<?php echo $inv['id']; ?>" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow transition">Pay</a>
                                        <?php endif; ?>
                                        <a href="print_invoice.php?invoice_id=<?php echo $inv['id']; ?>" target="_blank" class="bg-blue-50 hover:bg-blue-100 text-blue-700 dark:bg-blue-950/20 dark:hover:bg-blue-950/40 dark:text-blue-300 text-xs font-bold px-3 py-1.5 rounded-lg transition inline-flex items-center gap-1" title="Print Invoice">
                                            <i class="fas fa-print"></i> Print
                                        </a>
                                        <?php if ($inv['status'] !== 'cancelled'): ?>
                                        <button onclick="confirmCancel(<?php echo $inv['id']; ?>)" class="text-rose-600 hover:bg-rose-50 dark:hover:bg-rose-950/20 p-2 rounded-lg transition" title="Cancel Invoice"><i class="fas fa-ban"></i></button>
                                        <?php endif; ?>
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

<!-- Modal Structure for School / Class / Student Invoices -->
<div id="studentModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="generate_student">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Generate Student Invoice</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Student ID (User ID)</label>
                        <input type="number" name="student_id" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                        <select name="academic_year_id" id="student_year" onchange="filterTerms('student')" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                        <select name="term_id" id="student_term" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>" data-year-id="<?php echo $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('studentModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition">Generate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="classModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="generate_class">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Generate Class Invoices</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Class</label>
                        <select name="class_id" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($classes as $c): ?>
                            <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['grade_level'] . ' - ' . $c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                        <select name="academic_year_id" id="class_year" onchange="filterTerms('class')" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                        <select name="term_id" id="class_term" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>" data-year-id="<?php echo $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('classModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition">Generate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="schoolModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="generate_school">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Generate School-Wide Invoices</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                        <select name="academic_year_id" id="school_year" onchange="filterTerms('school')" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term</label>
                        <select name="term_id" id="school_term" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_terms as $term): ?>
                            <option value="<?php echo $term['id']; ?>" data-year-id="<?php echo $term['academic_year_id']; ?>"><?php echo htmlspecialchars($term['term_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('schoolModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-gray-800 hover:bg-gray-950 text-white font-semibold rounded-xl transition">Generate All</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="cancelForm" action="" method="POST" class="hidden">
    <input type="hidden" name="action" value="cancel_invoice">
    <input type="hidden" name="invoice_id" id="cancelId">
</form>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function confirmCancel(id) {
    if (confirm("Are you sure you want to cancel this invoice? This will prevent payments from being recorded against it.")) {
        document.getElementById('cancelId').value = id;
        document.getElementById('cancelForm').submit();
    }
}

function filterTerms(prefix) {
    const yearSelect = document.getElementById(prefix + '_year');
    const termSelect = document.getElementById(prefix + '_term');
    if (!yearSelect || !termSelect) return;
    
    const selectedYearId = yearSelect.value;
    const options = termSelect.options;
    
    let firstVisibleOption = null;
    let currentlySelectedStillVisible = false;
    const prevValue = termSelect.value;
    
    for (let i = 0; i < options.length; i++) {
        const option = options[i];
        const optionYearId = option.getAttribute('data-year-id');
        if (optionYearId === selectedYearId) {
            option.style.display = '';
            if (option.value === prevValue) {
                currentlySelectedStillVisible = true;
            }
            if (!firstVisibleOption) {
                firstVisibleOption = option;
            }
        } else {
            option.style.display = 'none';
        }
    }
    
    if (!currentlySelectedStillVisible && firstVisibleOption) {
        termSelect.value = firstVisibleOption.value;
    }
}

// Run on window load to set initial state for all modals
window.addEventListener('DOMContentLoaded', () => {
    filterTerms('student');
    filterTerms('class');
    filterTerms('school');
});
</script>

