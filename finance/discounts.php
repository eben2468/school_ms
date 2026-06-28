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
        if ($_POST['action'] === 'create_discount') {
            $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
            $type = filter_input(INPUT_POST, 'type', FILTER_SANITIZE_STRING);
            $value = filter_input(INPUT_POST, 'value', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
            
            if ($name && $type && $value !== false) {
                try {
                    $stmt = $db->prepare("INSERT INTO finance_discounts (name, type, value, status) VALUES (:name, :type, :value, 'active')");
                    $stmt->execute([
                        ':name' => $name,
                        ':type' => $type,
                        ':value' => $value
                    ]);
                    $disc_id = $db->lastInsertId();
                    logFinanceAudit('Create Discount Rule', 'Discounts', $disc_id, "Created discount: $name ($type - $value)", $db);
                    $success = "Discount rules created successfully!";
                } catch (PDOException $e) {
                    $error = "Error creating discount rule: " . $e->getMessage();
                }
            } else {
                $error = "Please fill in all fields.";
            }
        } elseif ($_POST['action'] === 'assign_discount') {
            $student_id = filter_input(INPUT_POST, 'student_id', FILTER_SANITIZE_NUMBER_INT);
            $discount_id = filter_input(INPUT_POST, 'discount_id', FILTER_SANITIZE_NUMBER_INT);
            $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
            
            // Make sure the selected student actually exists (prevents FK violation)
            $student_ok = false;
            if ($student_id) {
                $vs = $db->prepare("SELECT id FROM users WHERE id = :id AND role = 'student'");
                $vs->execute([':id' => $student_id]);
                $student_ok = (bool)$vs->fetchColumn();
            }

            if (!$student_id || !$discount_id || !$academic_year_id) {
                $error = "Please fill in all fields.";
            } elseif (!$student_ok) {
                $error = "The selected student could not be found. Please choose a valid student.";
            } else {
                try {
                    // Check if already assigned for this year
                    $check = $db->prepare("SELECT id FROM finance_student_discounts WHERE student_id = :student_id AND academic_year_id = :academic_year_id AND discount_id = :discount_id");
                    $check->execute([
                        ':student_id' => $student_id,
                        ':academic_year_id' => $academic_year_id,
                        ':discount_id' => $discount_id
                    ]);
                    
                    if ($check->fetch()) {
                        $error = "Discount has already been assigned to this student for this academic year.";
                    } else {
                        $stmt = $db->prepare("INSERT INTO finance_student_discounts (student_id, discount_id, academic_year_id, approved_by) VALUES (:student_id, :discount_id, :academic_year_id, :approved_by)");
                        $stmt->execute([
                            ':student_id' => $student_id,
                            ':discount_id' => $discount_id,
                            ':academic_year_id' => $academic_year_id,
                            ':approved_by' => $user_id
                        ]);
                        $assign_id = $db->lastInsertId();
                        logFinanceAudit('Assign Discount', 'Discounts', $assign_id, "Assigned discount ID $discount_id to student ID $student_id", $db);
                        $success = "Discount successfully assigned to student!";
                    }
                } catch (PDOException $e) {
                    $error = "Error assigning discount: " . $e->getMessage();
                }
            }
        }
    }
}

// Fetch lists
$discounts = $db->query("SELECT * FROM finance_discounts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);

// Students for the assign dropdown (value = users.id to satisfy the FK)
$students = $db->query("SELECT u.id, u.name, sp.student_id AS reg_no, c.name AS class_name, c.section
                        FROM users u
                        LEFT JOIN student_profiles sp ON u.id = sp.user_id
                        LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
                        LEFT JOIN classes c ON sc.class_id = c.id
                        WHERE u.role = 'student'
                        ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);

$student_discounts = $db->query("SELECT sd.*, d.name as discount_name, d.type as discount_type, d.value as discount_value,
                                         u.name as student_name, sp.student_id as student_reg_no, ay.year_name
                                  FROM finance_student_discounts sd
                                  JOIN finance_discounts d ON sd.discount_id = d.id
                                  JOIN users u ON sd.student_id = u.id
                                  JOIN student_profiles sp ON u.id = sp.user_id
                                  JOIN academic_years ay ON sd.academic_year_id = ay.id
                                  ORDER BY sd.id DESC")->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Discounts & Scholarships</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure student scholarships, partial fee waivers, and academic grant discounts</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <button onclick="openModal('assignModal')" class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition">
                            <i class="fas fa-user-plus mr-2"></i> Assign Student Waiver
                        </button>
                        <button onclick="openModal('discountModal')" class="inline-flex items-center whitespace-nowrap bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2.5 rounded-xl shadow transition">
                            <i class="fas fa-percent mr-2"></i> Create Waiver Rule
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

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Left: Rules list -->
                    <div class="lg:col-span-1 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Waiver & Discount Rules</h2>
                        <div class="divide-y divide-gray-100 dark:divide-gray-700 space-y-4">
                            <?php foreach ($discounts as $d): ?>
                            <div class="pt-4 flex justify-between items-center">
                                <div>
                                    <span class="font-bold text-gray-850 dark:text-white block"><?php echo htmlspecialchars($d['name']); ?></span>
                                    <span class="text-xs text-gray-400 capitalize"><?php echo $d['type'] === 'percentage' ? $d['value'] . '% off' : '₵' . $d['value'] . ' flat discount'; ?></span>
                                </div>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $d['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/30' : 'bg-gray-100 text-gray-600'; ?>">
                                    <?php echo ucfirst($d['status']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Right: Active assignments ledger -->
                    <div class="lg:col-span-2 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6">
                        <h2 class="text-lg font-bold text-gray-800 dark:text-white mb-4">Waiver Assignments Ledger</h2>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left border-collapse text-sm">
                                <thead>
                                    <tr class="bg-gray-50 dark:bg-gray-900 border-b border-gray-100 dark:border-gray-700 text-xs font-bold text-gray-400 uppercase tracking-wider">
                                        <th class="p-3">Student</th>
                                        <th class="p-3">Waiver Name</th>
                                        <th class="p-3">Value</th>
                                        <th class="p-3">Academic Year</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-700/50">
                                    <?php foreach ($student_discounts as $sd): ?>
                                    <tr class="hover:bg-gray-50/50 dark:hover:bg-gray-700/20">
                                        <td class="p-3">
                                            <div class="font-semibold text-gray-850 dark:text-white"><?php echo htmlspecialchars($sd['student_name']); ?></div>
                                            <div class="text-xs text-gray-400"><?php echo htmlspecialchars($sd['student_reg_no']); ?></div>
                                        </td>
                                        <td class="p-3 font-semibold text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($sd['discount_name']); ?></td>
                                        <td class="p-3 font-bold text-emerald-600">
                                            <?php echo $sd['discount_type'] === 'percentage' ? $sd['discount_value'] . '%' : '₵' . $sd['discount_value']; ?>
                                        </td>
                                        <td class="p-3 text-gray-500"><?php echo htmlspecialchars($sd['year_name']); ?></td>
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

<!-- Modal configurations -->
<div id="discountModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="create_discount">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Create Waiver Rule</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Discount/Scholarship Name</label>
                        <input type="text" name="name" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="e.g. Sports Scholarship 50%">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Type</label>
                        <select name="type" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="percentage">Percentage Waiver (%)</option>
                            <option value="fixed_amount">Fixed Amount Waiver (₵)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Value</label>
                        <input type="number" step="0.01" min="0" name="value" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="Value (e.g. 50 or 250.00)">
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('discountModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-xl transition">Create</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div id="assignModal" class="fixed inset-0 z-50 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen px-4">
        <div class="fixed inset-0 bg-gray-900/60 backdrop-blur-sm"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl p-6 max-w-md w-full border border-gray-100 dark:border-gray-700">
            <form action="" method="POST">
                <input type="hidden" name="action" value="assign_discount">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">Assign Student Waiver</h3>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Student</label>
                        <select name="student_id" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <option value="">-- Select a student --</option>
                            <?php foreach ($students as $stu): ?>
                            <option value="<?php echo $stu['id']; ?>">
                                <?php
                                echo htmlspecialchars($stu['name']);
                                if (!empty($stu['reg_no'])) echo ' (' . htmlspecialchars($stu['reg_no']) . ')';
                                if (!empty($stu['class_name'])) echo ' — ' . htmlspecialchars($stu['class_name'] . ($stu['section'] ? ' ' . $stu['section'] : ''));
                                ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($students)): ?>
                        <p class="text-xs text-red-500 mt-1">No students found. Add students before assigning waivers.</p>
                        <?php endif; ?>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Select Waiver Rule</label>
                        <select name="discount_id" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($discounts as $d): ?>
                            <option value="<?php echo $d['id']; ?>"><?php echo htmlspecialchars($d['name']); ?> (<?php echo $d['type'] === 'percentage' ? $d['value'] . '%' : '₵' . $d['value']; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                        <select name="academic_year_id" required class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                            <?php foreach ($academic_years as $year): ?>
                            <option value="<?php echo $year['id']; ?>"><?php echo htmlspecialchars($year['year_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="flex justify-end space-x-3 mt-6">
                    <button type="button" onclick="closeModal('assignModal')" class="px-4 py-2 rounded-xl text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition">Cancel</button>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white font-semibold rounded-xl transition">Assign Waiver</button>
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

