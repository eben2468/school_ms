<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once '../includes/settings_helper.php';

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

$staff_roles = ['teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

// Handle bulk salary save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_salaries') {
    $salaries = $_POST['salaries'] ?? [];
    $updated = 0;
    
    try {
        $db->beginTransaction();
        
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM teacher_profiles WHERE user_id = ?");
        $updateStmt = $db->prepare("UPDATE teacher_profiles SET salary = ? WHERE user_id = ?");
        $insertStmt = $db->prepare("INSERT INTO teacher_profiles (user_id, salary, employee_id, joining_date, contract_type) VALUES (?, ?, ?, CURDATE(), 'full_time')");
        
        foreach ($salaries as $uid => $salary) {
            $uid = (int)$uid;
            $salary = ($salary === '' || $salary === null) ? 0.00 : floatval($salary);
            
            $checkStmt->execute([$uid]);
            $exists = $checkStmt->fetchColumn() > 0;
            
            if ($exists) {
                $updateStmt->execute([$salary, $uid]);
            } else {
                $employee_id = 'EMP' . date('Y') . str_pad($uid, 4, '0', STR_PAD_LEFT);
                $insertStmt->execute([$uid, $salary, $employee_id]);
            }
            $updated++;
        }
        
        $db->commit();
        $message = "Base salaries successfully updated for $updated staff members.";
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = "Failed to save salaries: " . $e->getMessage();
    }
}

// Filter inputs
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dept_filter = isset($_GET['department']) ? $_GET['department'] : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';

$where = ["u.status = 'active'", "u.role IN ($staff_roles_in)"];
$params = [];

if ($search !== '') {
    $where[] = "(u.name LIKE :search OR tp.employee_id LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($dept_filter !== '') {
    $where[] = "tp.department = :department";
    $params[':department'] = $dept_filter;
}
if ($role_filter !== '') {
    $where[] = "u.role = :role";
    $params[':role'] = $role_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// Fetch filtered staff list
$query = "
    SELECT u.id, u.name, u.role, tp.employee_id, tp.department, tp.position, tp.salary
    FROM users u
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    $where_clause
    ORDER BY u.name ASC
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$staff_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Ensure the salary components table exists (per-tenant safe) and load a summary
// of each staff member's earnings/deductions for display in the table.
$component_summary = [];
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS salary_components (
            id INT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NOT NULL,
            type ENUM('earning','deduction') NOT NULL,
            name VARCHAR(100) NOT NULL,
            amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_user_type (user_id, type)
        )
    ");
    $cs = $db->query("SELECT user_id, type, SUM(amount) AS total, COUNT(*) AS cnt FROM salary_components GROUP BY user_id, type")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cs as $row) {
        $component_summary[$row['user_id']][$row['type']] = ['total' => (float)$row['total'], 'cnt' => (int)$row['cnt']];
    }
} catch (PDOException $e) {
    $component_summary = [];
}

// Fetch active departments for dropdown
try {
    $departments = $db->query("SELECT id, name FROM staff_departments WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// Calculate general stats (unfiltered active staff)
$statsQuery = $db->query("
    SELECT COUNT(u.id) as staff_count, SUM(tp.salary) as total_salary, AVG(NULLIF(tp.salary, 0)) as avg_salary
    FROM users u
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE u.status = 'active' AND u.role IN ($staff_roles_in)
");
$stats = $statsQuery->fetch(PDO::FETCH_ASSOC);
$allStaffCount = $stats['staff_count'] ?? 0;
$allTotalSalary = floatval($stats['total_salary'] ?? 0);
$allAvgSalary = floatval($stats['avg_salary'] ?? 0);

$role_labels = [
    'teacher'           => 'Teacher',
    'librarian'         => 'Librarian',
    'accountant'        => 'Accountant',
    'nurse'             => 'Nurse',
    'counselor'         => 'Counselor',
    'transport_officer' => 'Transport Officer',
    'hostel_warden'     => 'Hostel Warden',
    'canteen_manager'   => 'Canteen Manager',
    'hr'                => 'Human Resource',
];

$title = "Staff Salary Allocation";
include '../includes/header.php';
include '../includes/sidebar.php';

$currency = getSchoolSetting('currency_symbol', '₵');
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;"
     x-data="salaryComponents('<?= htmlspecialchars($currency, ENT_QUOTES) ?>')">
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
                                <h1 class="text-3xl font-bold mb-2">Salary Allocation</h1>
                                <p class="text-blue-100 text-lg">Define and update base salaries for all school staff categories</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-coins text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Alert Messages -->
                <?php if ($message): ?>
                <div class="mb-6 bg-green-150 border border-green-400 text-green-700 px-4 py-3 rounded-xl flex items-center shadow-sm" style="background-color:#d1fae5">
                    <i class="fas fa-check-circle mr-2 text-lg"></i>
                    <?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="mb-6 bg-red-150 border border-red-400 text-red-700 px-4 py-3 rounded-xl flex items-center shadow-sm" style="background-color:#fee2e2">
                    <i class="fas fa-exclamation-circle mr-2 text-lg"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <!-- Stats Overview Row -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between border border-gray-100 dark:border-gray-700">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Active Staff Count</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?= $allStaffCount ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/50 rounded-full flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i class="fas fa-users text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between border border-gray-100 dark:border-gray-700">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Total Monthly Obligation</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?= htmlspecialchars($currency) ?><?= number_format($allTotalSalary, 2) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900/50 rounded-full flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                            <i class="fas fa-wallet text-xl"></i>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 flex items-center justify-between border border-gray-100 dark:border-gray-700">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 font-medium">Average Base Salary</p>
                            <p class="text-3xl font-bold text-gray-800 dark:text-white mt-1"><?= htmlspecialchars($currency) ?><?= number_format($allAvgSalary, 2) ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/50 rounded-full flex items-center justify-center text-purple-600 dark:text-purple-400">
                            <i class="fas fa-calculator text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Filters Control Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-100 dark:border-gray-700">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Search Staff</label>
                            <div class="relative">
                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name or Employee ID..."
                                       class="w-full pl-10 pr-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Department</label>
                            <select name="department" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Departments</option>
                                <?php foreach ($departments as $dept): ?>
                                <option value="<?= htmlspecialchars($dept['name']) ?>" <?= $dept_filter === $dept['name'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-2">Staff Role</label>
                            <select name="role" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Roles</option>
                                <?php foreach ($role_labels as $rv => $rl): ?>
                                <option value="<?= $rv ?>" <?= $role_filter === $rv ? 'selected' : '' ?>><?= $rl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2.5 rounded-xl transition-colors shadow-md flex items-center justify-center">
                                <i class="fas fa-filter mr-2"></i> Filter
                            </button>
                            <a href="salaries.php" class="bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-4 py-2.5 rounded-xl transition-colors flex items-center justify-center" title="Reset Filters">
                                <i class="fas fa-undo"></i>
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Salary Allocation Form -->
                <form method="POST" class="w-full">
                    <input type="hidden" name="action" value="save_salaries">
                    
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-100 dark:border-gray-700 mb-6">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex justify-between items-center">
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Active Staff Salaries</h2>
                            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-6 py-2.5 rounded-xl transition-colors shadow-md font-bold flex items-center">
                                <i class="fas fa-save mr-2"></i> Save Salaries
                            </button>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Staff Details</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Role & Department</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Current Salary</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-64">Set Base Salary (<?= htmlspecialchars($currency) ?>)</th>
                                        <th class="px-6 py-4 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Earnings &amp; Deductions</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if (empty($staff_list)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-8 text-center text-gray-500 dark:text-gray-400">
                                            <i class="fas fa-user-slash text-3xl mb-3 block text-gray-300"></i>
                                            No active staff members found matching criteria.
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($staff_list as $staff): 
                                            $role_display = $role_labels[$staff['role']] ?? formatRoleName($staff['role']);
                                            $dept_display = $staff['department'] ?: 'Unassigned';
                                            $salary_val = $staff['salary'] !== null ? floatval($staff['salary']) : '';
                                        ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-750 transition-colors">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10 bg-gradient-to-r from-blue-400 to-indigo-500 rounded-full flex items-center justify-center text-white font-extrabold shadow-sm">
                                                        <?= strtoupper(substr($staff['name'], 0, 1)) ?>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-semibold text-gray-900 dark:text-white"><?= htmlspecialchars($staff['name']) ?></div>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400">ID: <?= htmlspecialchars($staff['employee_id'] ?? 'N/A') ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?= $role_display ?></div>
                                                <div class="text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($dept_display) ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                                <?= $salary_val !== '' ? htmlspecialchars($currency) . number_format($salary_val, 2) : '<span class="italic text-gray-400 font-normal">Not set</span>' ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="relative rounded-xl shadow-sm max-w-xs">
                                                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                                                        <span class="text-gray-500 text-sm font-semibold"><?= htmlspecialchars($currency) ?></span>
                                                    </div>
                                                    <input type="number" step="0.01" min="0" name="salaries[<?= $staff['id'] ?>]" value="<?= $salary_val ?>"
                                                           placeholder="0.00"
                                                           class="w-full pl-8 pr-4 py-2 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 dark:bg-gray-700 dark:text-white font-medium transition">
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <?php
                                                $cs_earn = $component_summary[$staff['id']]['earning'] ?? null;
                                                $cs_ded  = $component_summary[$staff['id']]['deduction'] ?? null;
                                                $has_components = $cs_earn || $cs_ded;
                                                $net_val = ($cs_earn['total'] ?? 0) - ($cs_ded['total'] ?? 0);
                                                $item_count = ($cs_earn['cnt'] ?? 0) + ($cs_ded['cnt'] ?? 0);
                                                ?>
                                                <div class="flex items-center gap-3">
                                                    <button type="button"
                                                        @click='openComponents(<?= json_encode(["id" => (int)$staff["id"], "name" => $staff["name"], "salary" => (float)($staff["salary"] ?? 0)], JSON_HEX_APOS) ?>)'
                                                        class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm font-semibold transition-colors <?= $has_components ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-600 hover:text-white dark:bg-emerald-900/30 dark:text-emerald-300' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-600 hover:text-white dark:bg-indigo-900/30 dark:text-indigo-300' ?>">
                                                        <i class="fas fa-sliders-h"></i>
                                                        <?= $has_components ? 'Edit' : 'Set up' ?>
                                                    </button>
                                                    <?php if ($has_components): ?>
                                                    <div class="text-xs leading-tight">
                                                        <div class="font-bold text-gray-800 dark:text-white">Net: <?= htmlspecialchars($currency) . number_format($net_val, 2) ?></div>
                                                        <div class="text-gray-400"><?= $item_count ?> item<?= $item_count === 1 ? '' : 's' ?></div>
                                                    </div>
                                                    <?php else: ?>
                                                    <span class="text-xs text-gray-400 italic">Standard template</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Sticky Save Button Footer -->
                    <?php if (!empty($staff_list)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-100 dark:border-gray-700 flex justify-between items-center">
                        <span class="text-sm text-gray-500 dark:text-gray-400">
                            Editing salaries for <span class="font-bold text-gray-800 dark:text-white"><?= count($staff_list) ?></span> filtered staff members.
                        </span>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-8 py-3 rounded-xl transition-colors shadow-md font-bold flex items-center text-lg">
                            <i class="fas fa-save mr-2"></i> Save Salaries
                        </button>
                    </div>
                    <?php endif; ?>
                </form>

            </div>
        </main>

        <!-- ============ Salary Components Modal ============ -->
        <div x-show="modalOpen" x-cloak
             class="fixed inset-0 z-50 flex items-center justify-center p-4"
             style="display:none;">
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" @click="closeModal()"></div>

            <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col"
                 @click.stop x-show="modalOpen"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-4"
                 x-transition:enter-end="opacity-100 translate-y-0">

                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800 dark:text-white">Salary Components</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400" x-text="staffName"></p>
                    </div>
                    <button type="button" @click="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 w-9 h-9 rounded-lg flex items-center justify-center hover:bg-gray-100 dark:hover:bg-gray-700">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>

                <!-- Body -->
                <div class="p-6 overflow-y-auto flex-1 space-y-6">
                    <div class="flex justify-end">
                        <button type="button" @click="loadTemplate()" class="text-xs font-semibold text-blue-600 dark:text-blue-400 hover:underline inline-flex items-center gap-1">
                            <i class="fas fa-magic"></i> Load standard template from base salary
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Earnings -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-bold text-emerald-600 dark:text-emerald-400 flex items-center gap-2"><i class="fas fa-plus-circle"></i> Earnings</h4>
                                <button type="button" @click="addEarning()" class="text-xs font-semibold bg-emerald-50 dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 px-2.5 py-1 rounded-lg hover:bg-emerald-100">
                                    <i class="fas fa-plus mr-1"></i> Add
                                </button>
                            </div>
                            <div class="space-y-2">
                                <template x-for="(item, i) in earnings" :key="'e'+i">
                                    <div class="flex items-center gap-2">
                                        <input type="text" x-model="item.name" placeholder="e.g. Housing Allowance"
                                               class="flex-1 min-w-0 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-emerald-500">
                                        <input type="number" step="0.01" min="0" x-model="item.amount" placeholder="0.00"
                                               class="w-28 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-right focus:ring-2 focus:ring-emerald-500">
                                        <button type="button" @click="removeEarning(i)" class="text-rose-500 hover:text-rose-700 w-8 h-8 flex-shrink-0 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-900/20">
                                            <i class="fas fa-trash-alt text-sm"></i>
                                        </button>
                                    </div>
                                </template>
                                <p x-show="earnings.length === 0" class="text-xs text-gray-400 italic py-2">No earnings yet. Click "Add".</p>
                            </div>
                            <div class="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700 flex justify-between text-sm font-bold text-gray-700 dark:text-gray-200">
                                <span>Gross Pay</span>
                                <span x-text="money(grossTotal)"></span>
                            </div>
                        </div>

                        <!-- Deductions -->
                        <div>
                            <div class="flex items-center justify-between mb-3">
                                <h4 class="font-bold text-rose-600 dark:text-rose-400 flex items-center gap-2"><i class="fas fa-minus-circle"></i> Deductions</h4>
                                <button type="button" @click="addDeduction()" class="text-xs font-semibold bg-rose-50 dark:bg-rose-900/30 text-rose-700 dark:text-rose-300 px-2.5 py-1 rounded-lg hover:bg-rose-100">
                                    <i class="fas fa-plus mr-1"></i> Add
                                </button>
                            </div>
                            <div class="space-y-2">
                                <template x-for="(item, i) in deductions" :key="'d'+i">
                                    <div class="flex items-center gap-2">
                                        <input type="text" x-model="item.name" placeholder="e.g. Income Tax (PAYE)"
                                               class="flex-1 min-w-0 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white focus:ring-2 focus:ring-rose-500">
                                        <input type="number" step="0.01" min="0" x-model="item.amount" placeholder="0.00"
                                               class="w-28 px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white text-right focus:ring-2 focus:ring-rose-500">
                                        <button type="button" @click="removeDeduction(i)" class="text-rose-500 hover:text-rose-700 w-8 h-8 flex-shrink-0 rounded-lg hover:bg-rose-50 dark:hover:bg-rose-900/20">
                                            <i class="fas fa-trash-alt text-sm"></i>
                                        </button>
                                    </div>
                                </template>
                                <p x-show="deductions.length === 0" class="text-xs text-gray-400 italic py-2">No deductions yet. Click "Add".</p>
                            </div>
                            <div class="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700 flex justify-between text-sm font-bold text-gray-700 dark:text-gray-200">
                                <span>Total Deductions</span>
                                <span x-text="'-' + money(dedTotal)"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Net summary -->
                    <div class="bg-gray-50 dark:bg-gray-900/40 rounded-xl p-4 flex items-center justify-between">
                        <span class="font-bold text-gray-700 dark:text-gray-200">Net Pay (take-home)</span>
                        <span class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400" x-text="money(netTotal)"></span>
                    </div>
                    <p class="text-xs text-gray-400">Saving updates this staff member's gross salary (sum of earnings) and flows into Payroll and finance records automatically.</p>
                </div>

                <!-- Footer -->
                <div class="p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
                    <button type="button" @click="closeModal()" class="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold hover:bg-gray-50 dark:hover:bg-gray-700">Cancel</button>
                    <button type="button" @click="save()" :disabled="saving"
                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-bold shadow-md flex items-center gap-2 disabled:opacity-60">
                        <i class="fas" :class="saving ? 'fa-spinner fa-spin' : 'fa-save'"></i>
                        <span x-text="saving ? 'Saving...' : 'Save Components'"></span>
                    </button>
                </div>
            </div>
        </div>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<style>[x-cloak]{display:none !important;}</style>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('salaryComponents', (currency) => ({
        currency: currency || '₵',
        modalOpen: false,
        saving: false,
        staffId: null,
        staffName: '',
        baseSalary: 0,
        earnings: [],
        deductions: [],

        money(v) {
            return this.currency + (parseFloat(v) || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        },
        get grossTotal() { return this.earnings.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0); },
        get dedTotal() { return this.deductions.reduce((s, d) => s + (parseFloat(d.amount) || 0), 0); },
        get netTotal() { return this.grossTotal - this.dedTotal; },

        async openComponents(staff) {
            this.staffId = staff.id;
            this.staffName = staff.name;
            this.baseSalary = staff.salary || 0;
            this.earnings = [];
            this.deductions = [];
            this.modalOpen = true;
            try {
                const res = await fetch('salary_components_api.php?user_id=' + staff.id);
                const data = await res.json();
                if (data.success) {
                    this.earnings = data.earnings || [];
                    this.deductions = data.deductions || [];
                    if (data.base_salary) this.baseSalary = data.base_salary;
                }
            } catch (e) { /* leave empty on error */ }
        },
        closeModal() { this.modalOpen = false; },
        addEarning() { this.earnings.push({ name: '', amount: '' }); },
        addDeduction() { this.deductions.push({ name: '', amount: '' }); },
        removeEarning(i) { this.earnings.splice(i, 1); },
        removeDeduction(i) { this.deductions.splice(i, 1); },
        loadTemplate() {
            const b = parseFloat(this.baseSalary) || 0;
            this.earnings = [
                { name: 'Basic Pay', amount: (b * 0.60).toFixed(2) },
                { name: 'Housing Allowance', amount: (b * 0.15).toFixed(2) },
                { name: 'Transport Allowance', amount: (b * 0.10).toFixed(2) },
                { name: 'Medical Allowance', amount: (b * 0.05).toFixed(2) },
                { name: 'Responsibility Allowance', amount: (b * 0.10).toFixed(2) },
            ];
            this.deductions = [
                { name: 'Income Tax (PAYE)', amount: (b * 0.05).toFixed(2) },
                { name: 'Pension Contribution (SSNIT)', amount: (b * 0.055).toFixed(2) },
                { name: 'Social Security Levy', amount: (b * 0.01).toFixed(2) },
            ];
        },
        async save() {
            this.saving = true;
            try {
                const res = await fetch('salary_components_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user_id: this.staffId, earnings: this.earnings, deductions: this.deductions })
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    this.saving = false;
                    alert(data.message || 'Could not save components.');
                }
            } catch (e) {
                this.saving = false;
                alert('Network error while saving components.');
            }
        }
    }));
});
</script>
