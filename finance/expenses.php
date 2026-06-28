<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Handle expense entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'record_expense') {
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $vendor = filter_input(INPUT_POST, 'vendor', FILTER_SANITIZE_STRING);
        $expense_date = filter_input(INPUT_POST, 'expense_date', FILTER_SANITIZE_STRING);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?: 'approved';
        
        if ($category && $amount !== false && $expense_date) {
            try {
                $stmt = $db->prepare("INSERT INTO finance_expenses (category, amount, description, vendor, expense_date, recorded_by, status, approved_by) 
                                      VALUES (:category, :amount, :description, :vendor, :expense_date, :recorded_by, :status, :approved_by)");
                $stmt->execute([
                    ':category' => $category,
                    ':amount' => $amount,
                    ':description' => $description,
                    ':vendor' => $vendor,
                    ':expense_date' => $expense_date,
                    ':recorded_by' => $user_id,
                    ':status' => $status,
                    ':approved_by' => $status === 'approved' ? $user_id : null
                ]);
                $exp_id = $db->lastInsertId();
                logFinanceAudit('Record Expense', 'Expenses', $exp_id, "Recorded expense: $category (₵$amount) - Status: $status", $db);
                $success = "Expense successfully recorded!";
            } catch (PDOException $e) {
                $error = "Error recording expense: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all fields.";
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'approve_expense') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        if ($id) {
            try {
                $stmt = $db->prepare("UPDATE finance_expenses SET status = 'approved', approved_by = :approved_by WHERE id = :id");
                $stmt->execute([':approved_by' => $user_id, ':id' => $id]);
                logFinanceAudit('Approve Expense', 'Expenses', $id, "Approved expense ID: $id", $db);
                $success = "Expense successfully approved!";
            } catch (PDOException $e) {
                $error = "Error approving expense: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'decline_expense') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        if ($id) {
            try {
                $stmt = $db->prepare("UPDATE finance_expenses SET status = 'rejected', approved_by = :approved_by WHERE id = :id");
                $stmt->execute([':approved_by' => $user_id, ':id' => $id]);
                logFinanceAudit('Decline Expense', 'Expenses', $id, "Declined expense ID: $id", $db);
                $success = "Expense successfully declined!";
            } catch (PDOException $e) {
                $error = "Error declining expense: " . $e->getMessage();
            }
        }
    }
}

// Fetch expenses
$expenses = $db->query("SELECT e.*, u.name as recorded_by_name, app.name as approved_by_name 
                        FROM finance_expenses e 
                        JOIN users u ON e.recorded_by = u.id 
                        LEFT JOIN users app ON e.approved_by = app.id 
                        ORDER BY e.expense_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals by category for stats
$categories_total = [];
$total_expense = 0.00;
foreach ($expenses as $exp) {
    if ($exp['status'] === 'approved') {
        $cat = $exp['category'];
        $categories_total[$cat] = ($categories_total[$cat] ?? 0.00) + (float)$exp['amount'];
        $total_expense += (float)$exp['amount'];
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
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Expenses Tracking</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Record and analyze operational institutional expenditures</p>
                    </div>
                    <button onclick="openModal('expenseModal')" class="bg-gradient-to-r from-rose-600 to-red-500 hover:from-rose-700 hover:to-red-600 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-plus"></i> Record Expense
                    </button>
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

                <!-- Stats Panel -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Total Expenses (Approved)</span>
                        <span class="text-2xl font-extrabold text-rose-500"><?php echo formatFinanceCurrency($total_expense, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Utilities</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['utilities'] ?? 0.00, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Salaries</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['Salaries'] ?? 0.00, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Teaching Materials</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['Teaching Materials'] ?? 0.00, $db); ?></span>
                    </div>
                </div>

                <!-- Ledger -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left border-collapse text-sm">
                            <thead>
                                <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                    <th class="p-4">Date</th>
                                    <th class="p-4">Category</th>
                                    <th class="p-4">Vendor / Payee</th>
                                    <th class="p-4">Description</th>
                                    <th class="p-4 text-right">Amount</th>
                                    <th class="p-4">Status</th>
                                    <th class="p-4 text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($expenses as $exp): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                    <td class="p-4 text-gray-500 font-medium"><?php echo date('M d, Y', strtotime($exp['expense_date'])); ?></td>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300 capitalize"><?php echo str_replace('_', ' ', $exp['category']); ?></td>
                                    <td class="p-4 font-semibold text-gray-800 dark:text-white"><?php echo htmlspecialchars($exp['vendor'] ?: 'Unassigned'); ?></td>
                                    <td class="p-4 text-gray-600 dark:text-gray-405"><?php echo htmlspecialchars($exp['description'] ?: '-'); ?></td>
                                    <td class="p-4 text-right font-bold text-rose-500"><?php echo formatFinanceCurrency($exp['amount'], $db); ?></td>
                                    <td class="p-4">
                                         <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php 
                                             if ($exp['status'] === 'approved') {
                                                 echo 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30';
                                             } elseif ($exp['status'] === 'rejected') {
                                                 echo 'bg-rose-50 text-rose-700 dark:bg-rose-950/30';
                                             } else {
                                                 echo 'bg-amber-50 text-amber-700 dark:bg-amber-950/30';
                                             }
                                         ?>">
                                             <?php echo ucfirst($exp['status'] === 'rejected' ? 'declined' : $exp['status']); ?>
                                         </span>
                                    </td>
                                    <td class="p-4 text-center">
                                        <?php if ($exp['status'] === 'pending' && in_array($user_role, ['super_admin', 'school_admin'])): ?>
                                        <div class="flex items-center gap-2 justify-center">
                                            <button onclick="approveExpense(<?php echo $exp['id']; ?>)" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow transition inline-flex items-center gap-1">
                                                <i class="fas fa-check"></i> Approve
                                            </button>
                                            <button onclick="declineExpense(<?php echo $exp['id']; ?>)" class="bg-red-600 hover:bg-red-700 text-white text-xs font-bold px-3 py-1.5 rounded-lg shadow transition inline-flex items-center gap-1">
                                                <i class="fas fa-times"></i> Decline
                                            </button>
                                        </div>
                                        <?php elseif ($exp['status'] === 'rejected'): ?>
                                        <span class="text-xs text-gray-400 font-semibold"><i class="fas fa-times-circle mr-1 text-rose-500"></i> Declined</span>
                                        <?php else: ?>
                                        <span class="text-xs text-gray-400 font-semibold"><i class="fas fa-check-double mr-1 text-emerald-500"></i> Approved</span>
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

<!-- Modal -->
<div id="expenseModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4 py-6">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm" onclick="closeModal('expenseModal')"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700 max-h-[90vh] overflow-y-auto">
            <form action="" method="POST">
                <input type="hidden" name="action" value="record_expense">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Record Expense</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category</label>
                        <select name="category" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="utilities">Utilities</option>
                            <option value="maintenance">Maintenance</option>
                            <option value="Teaching Materials">Teaching Materials</option>
                            <option value="Admin">Admin</option>
                            <option value="Transport">Transport</option>
                            <option value="Salaries">Salaries</option>
                            <option value="other">Other Expenses</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Amount</label>
                        <input type="number" step="0.01" min="0" name="amount" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="₵ 0.00">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Vendor / Payee</label>
                        <input type="text" name="vendor" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="e.g. Electric Power Grid">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Expense Date</label>
                        <input type="date" name="expense_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Status Workflow</label>
                        <select name="status" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="approved">Approved & Disbursed</option>
                            <option value="pending">Pending Approval</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea name="description" rows="2" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="Provide expenditure details..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <button type="button" onclick="closeModal('expenseModal')" class="px-5 py-2.5 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 border border-gray-300 dark:border-gray-600 font-semibold transition">Cancel</button>
                    <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold rounded-xl shadow-lg transition">
                        <i class="fas fa-save mr-1"></i> Record
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="approveForm" action="" method="POST" class="hidden">
    <input type="hidden" name="action" value="approve_expense">
    <input type="hidden" name="id" id="approveId">
</form>

<form id="declineForm" action="" method="POST" class="hidden">
    <input type="hidden" name="action" value="decline_expense">
    <input type="hidden" name="id" id="declineId">
</form>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
function approveExpense(id) {
    if (confirm("Are you sure you want to approve and disburse this expense?")) {
        document.getElementById('approveId').value = id;
        document.getElementById('approveForm').submit();
    }
}
function declineExpense(id) {
    if (confirm("Are you sure you want to decline this expense request?")) {
        document.getElementById('declineId').value = id;
        document.getElementById('declineForm').submit();
    }
}
</script>

