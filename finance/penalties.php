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

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'create_penalty') {
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING) ?: 'late_payment';
            $calculation_type = filter_input(INPUT_POST, 'calculation_type', FILTER_SANITIZE_STRING);
            $value = filter_input(INPUT_POST, 'value', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            $grace_period = filter_input(INPUT_POST, 'grace_period_days', FILTER_SANITIZE_NUMBER_INT) ?: 0;
            
            if ($name && $calculation_type && $value !== false) {
                try {
                    $stmt = $db->prepare("INSERT INTO finance_penalties (name, type, calculation_type, value, grace_period_days, status) 
                                          VALUES (:name, :type, :calculation_type, :value, :grace_period, 'active')");
                    $stmt->execute([
                        ':name' => $name,
                        ':type' => $type,
                        ':calculation_type' => $calculation_type,
                        ':value' => $value,
                        ':grace_period' => $grace_period
                    ]);
                    $pen_id = $db->lastInsertId();
                    logFinanceAudit('Create Penalty Rule', 'Penalties', $pen_id, "Created penalty rule: $name", $db);
                    $success = "Penalty rule created successfully!";
                } catch (PDOException $e) {
                    $error = "Error creating penalty rule: " . $e->getMessage();
                }
            } else {
                $error = "Please fill in all fields.";
            }
        }
    }
}

// Fetch penalties
$penalties = $db->query("SELECT * FROM finance_penalties ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch applied student penalties
$applied_penalties = $db->query("SELECT sp.*, p.name as penalty_name, i.invoice_number, u.name as student_name
                                 FROM finance_student_penalties sp
                                 JOIN finance_penalties p ON sp.penalty_id = p.id
                                 JOIN finance_invoices i ON sp.invoice_id = i.id
                                 JOIN users u ON i.student_id = u.id
                                 ORDER BY sp.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Penalties Management</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure and manage late payment levies or administrative penalty guidelines</p>
                    </div>
                    <button onclick="openModal('penaltyModal')" class="bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition flex items-center gap-2">
                        <i class="fas fa-plus"></i> Create Penalty Rule
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

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left: Penalty rules -->
                    <div class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Levy & Penalty Guidelines</h2>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700 space-y-4">
                            <?php foreach ($penalties as $p): ?>
                            <div class="pt-4 flex justify-between items-center">
                                <div>
                                    <span class="font-bold text-gray-850 dark:text-white block"><?php echo htmlspecialchars($p['name']); ?></span>
                                    <span class="text-xs text-gray-400 capitalize">
                                        <?php echo $p['calculation_type'] === 'percentage' ? $p['value'] . '% fee' : '₵' . $p['value'] . ' late levy'; ?>
                                        • Grace: <?php echo $p['grace_period_days']; ?> days
                                    </span>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $p['status'] === 'active' ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/30' : 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo ucfirst($p['status']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right: Applied penalties list -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Applied Penalties Ledger</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                        <th class="p-3">Student</th>
                                        <th class="p-3">Invoice</th>
                                        <th class="p-3">Penalty Rule</th>
                                        <th class="p-3">Applied Levy</th>
                                        <th class="p-3">Date Applied</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                    <?php foreach ($applied_penalties as $ap): ?>
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                        <td class="p-3 font-semibold text-gray-850 dark:text-white"><?php echo htmlspecialchars($ap['student_name']); ?></td>
                                        <td class="p-3 font-semibold text-blue-600"><?php echo htmlspecialchars($ap['invoice_number']); ?></td>
                                        <td class="p-3 text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($ap['penalty_name']); ?></td>
                                        <td class="p-3 font-bold text-rose-500"><?php echo formatFinanceCurrency($ap['amount'], $db); ?></td>
                                        <td class="p-3 text-gray-500"><?php echo date('M d, Y', strtotime($ap['applied_date'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
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

<!-- Modal -->
<div id="penaltyModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="create_penalty">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Create Penalty Rule</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Penalty Name</label>
                        <input type="text" name="name" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="e.g. 5% Late Payment Levy">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Penalty Scope</label>
                        <select name="type" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="late_payment">Late Payment</option>
                            <option value="late_registration">Late Registration</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Calculation Type</label>
                        <select name="calculation_type" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="fixed_amount">Fixed Amount Levy (₵)</option>
                            <option value="percentage">Percentage Levy (%)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Penalty Value</label>
                        <input type="number" step="0.01" min="0" name="value" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="e.g. 5 or 25.00">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Grace Period (Days)</label>
                        <input type="number" min="0" name="grace_period_days" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="e.g. 5">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('penaltyModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition">Create</button>
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

