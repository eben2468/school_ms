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

// Handle income entry
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'record_income') {
        $category = filter_input(INPUT_POST, 'category', FILTER_SANITIZE_STRING);
        $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
        $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
        $income_date = filter_input(INPUT_POST, 'income_date', FILTER_SANITIZE_STRING);
        
        if ($category && $amount !== false && $income_date) {
            try {
                $stmt = $db->prepare("INSERT INTO finance_income (category, amount, description, income_date, recorded_by) 
                                      VALUES (:category, :amount, :description, :income_date, :recorded_by)");
                $stmt->execute([
                    ':category' => $category,
                    ':amount' => $amount,
                    ':description' => $description,
                    ':income_date' => $income_date,
                    ':recorded_by' => $user_id
                ]);
                $inc_id = $db->lastInsertId();
                logFinanceAudit('Record Non-Fee Income', 'Income', $inc_id, "Recorded non-fee income: $category (₵$amount)", $db);
                $success = "Income successfully recorded!";
            } catch (PDOException $e) {
                $error = "Error recording income: " . $e->getMessage();
            }
        } else {
            $error = "Please fill in all fields.";
        }
    }
}

// Fetch income entries
$incomes = $db->query("SELECT i.*, u.name as recorded_by_name 
                       FROM finance_income i 
                       JOIN users u ON i.recorded_by = u.id 
                       ORDER BY i.income_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals by category for stats
$categories_total = [];
$total_income = 0.00;
foreach ($incomes as $inc) {
    $cat = $inc['category'];
    $categories_total[$cat] = ($categories_total[$cat] ?? 0.00) + (float)$inc['amount'];
    $total_income += (float)$inc['amount'];
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Other Income Tracking</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Track and manage non-fee institutional revenue sources</p>
                    </div>
                    <button onclick="openModal('incomeModal')" class="bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-plus"></i> Record Other Income
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
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Total Other Income</span>
                        <span class="text-2xl font-extrabold text-green-600"><?php echo formatFinanceCurrency($total_income, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Donations</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['donations'] ?? 0.00, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Uniform Sales</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['uniform_sales'] ?? 0.00, $db); ?></span>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <span class="text-xs font-semibold text-gray-400 uppercase tracking-wider block mb-1">Canteen Sales</span>
                        <span class="text-2xl font-extrabold text-gray-800 dark:text-white"><?php echo formatFinanceCurrency($categories_total['canteen'] ?? 0.00, $db); ?></span>
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
                                    <th class="p-4">Description</th>
                                    <th class="p-4 text-right">Amount</th>
                                    <th class="p-4">Recorded By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                <?php foreach ($incomes as $inc): ?>
                                <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                    <td class="p-4 text-gray-500 font-medium"><?php echo date('M d, Y', strtotime($inc['income_date'])); ?></td>
                                    <td class="p-4 font-semibold text-gray-700 dark:text-gray-300 capitalize"><?php echo str_replace('_', ' ', $inc['category']); ?></td>
                                    <td class="p-4 text-gray-600 dark:text-gray-405"><?php echo htmlspecialchars($inc['description'] ?: '-'); ?></td>
                                    <td class="p-4 text-right font-bold text-emerald-600"><?php echo formatFinanceCurrency($inc['amount'], $db); ?></td>
                                    <td class="p-4 text-gray-500 font-medium"><?php echo htmlspecialchars($inc['recorded_by_name']); ?></td>
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
<div id="incomeModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="record_income">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Record Other Income</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Category</label>
                        <select name="category" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="donations">Donations</option>
                            <option value="fundraising">Fundraising</option>
                            <option value="uniform_sales">Uniform Sales</option>
                            <option value="canteen">Canteen Orders</option>
                            <option value="other">Other Sales</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Amount</label>
                        <input type="number" step="0.01" min="0" name="amount" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="₵ 0.00">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Income Date</label>
                        <input type="date" name="income_date" value="<?php echo date('Y-m-d'); ?>" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Description</label>
                        <textarea name="description" rows="2" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="Provide source details..."></textarea>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('incomeModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition">Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openModal(id) {
    document.getElementById(id).classList.remove('hidden');
}
function closeModal(id) {
    document.getElementById(id).classList.add('hidden');
}
</script>

