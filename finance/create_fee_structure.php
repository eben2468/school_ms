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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academic_year_id = filter_input(INPUT_POST, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT);
    $term_id = filter_input(INPUT_POST, 'term_id', FILTER_SANITIZE_NUMBER_INT);
    $class_ids = $_POST['class_ids'] ?? [];
    $category_id = filter_input(INPUT_POST, 'category_id', FILTER_SANITIZE_NUMBER_INT);
    $student_type = filter_input(INPUT_POST, 'student_type', FILTER_SANITIZE_STRING) ?: 'all';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $is_mandatory = isset($_POST['is_mandatory']) ? 1 : 0;

    if ($academic_year_id && $term_id && !empty($class_ids) && $category_id && $amount !== false) {
        try {
            $db->beginTransaction();
            
            $stmt = $db->prepare("INSERT INTO finance_fee_structures (academic_year_id, term_id, class_id, category_id, student_type, amount, is_mandatory) 
                                  VALUES (:academic_year_id, :term_id, :class_id, :category_id, :student_type, :amount, :is_mandatory)");
            
            $count = 0;
            foreach ($class_ids as $class_id) {
                // Check if already exists to prevent duplicate category charges
                $check = $db->prepare("SELECT id FROM finance_fee_structures 
                                       WHERE academic_year_id = :academic_year_id 
                                         AND term_id = :term_id 
                                         AND class_id = :class_id 
                                         AND category_id = :category_id 
                                         AND student_type = :student_type");
                $check->execute([
                    ':academic_year_id' => $academic_year_id,
                    ':term_id' => $term_id,
                    ':class_id' => $class_id,
                    ':category_id' => $category_id,
                    ':student_type' => $student_type
                ]);
                
                if (!$check->fetch()) {
                    $stmt->execute([
                        ':academic_year_id' => $academic_year_id,
                        ':term_id' => $term_id,
                        ':class_id' => $class_id,
                        ':category_id' => $category_id,
                        ':student_type' => $student_type,
                        ':amount' => $amount,
                        ':is_mandatory' => $is_mandatory
                    ]);
                    $count++;
                }
            }
            
            $db->commit();
            logFinanceAudit('Create Fee Structure', 'Fee Structures', 0, "Created fee structures for $count classes of category ID: $category_id", $db);
            $success = "Successfully created fee structures for $count classes!";
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            $error = "Error creating fee structure: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields and select at least one class.";
    }
}

// Fetch drop-down data
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$academic_terms = $db->query("SELECT * FROM academic_terms ORDER BY term_number")->fetchAll(PDO::FETCH_ASSOC);
$classes = $db->query("SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
$categories = $db->query("SELECT id, name FROM finance_fee_categories WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
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
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Create Fee Structure</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Add new termly structural charges for classes</p>
                    </div>
                    <a href="fee_structures.php" class="inline-flex items-center text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-800 font-semibold px-4 py-2 rounded-xl border border-gray-300 dark:border-gray-700 transition">
                        <i class="fas fa-arrow-left mr-2"></i> Back
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

                <!-- Form Card -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 overflow-hidden">
                    <div class="p-6 md:p-8">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Academic Year *</label>
                                    <select name="academic_year_id" id="fee_year" required onchange="filterTerms()" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                        <option value="">Select Academic Year</option>
                                        <?php foreach ($academic_years as $year): ?>
                                        <option value="<?php echo $year['id']; ?>">
                                            <?php echo htmlspecialchars($year['year_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Term *</label>
                                    <select name="term_id" id="fee_term" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                        <option value="">Select Term</option>
                                        <?php foreach ($academic_terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" data-year-id="<?php echo $term['academic_year_id']; ?>">
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="md:col-span-2">
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Target Classes * (Select all that apply)</label>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 bg-gray-50 dark:bg-gray-900/40 p-4 rounded-xl border border-gray-200 dark:border-gray-700 max-h-48 overflow-y-auto">
                                        <?php foreach ($classes as $class): ?>
                                        <label class="flex items-center space-x-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer hover:text-green-600 dark:hover:text-green-400">
                                            <input type="checkbox" name="class_ids[]" value="<?php echo $class['id']; ?>" class="rounded text-green-600 focus:ring-green-500 border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                            <span><?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?></span>
                                        </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="mt-2 flex space-x-4">
                                        <button type="button" onclick="toggleClasses(true)" class="text-xs text-green-600 dark:text-green-400 font-semibold hover:underline">Select All</button>
                                        <button type="button" onclick="toggleClasses(false)" class="text-xs text-gray-500 font-semibold hover:underline">Deselect All</button>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Fee Category *</label>
                                    <select name="category_id" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                        <option value="">Select Fee Category</option>
                                        <?php foreach ($categories as $cat): ?>
                                        <option value="<?php echo $cat['id']; ?>">
                                            <?php echo htmlspecialchars($cat['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Student Type Scope *</label>
                                    <select name="student_type" required class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                        <option value="all">Applicable to All Students</option>
                                        <option value="day">Day Students Only</option>
                                        <option value="boarding">Boarding Students Only</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Amount *</label>
                                    <div class="relative rounded-xl shadow-sm">
                                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                            <span class="text-gray-500 dark:text-gray-400 text-sm">₵</span>
                                        </div>
                                        <input type="number" step="0.01" min="0.00" name="amount" required class="w-full pl-8 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition" placeholder="0.00">
                                    </div>
                                </div>

                                <div class="flex items-center pt-8">
                                    <label class="flex items-center space-x-3 cursor-pointer">
                                        <input type="checkbox" name="is_mandatory" value="1" checked class="rounded w-5 h-5 text-green-600 focus:ring-green-500 border-gray-300 dark:border-gray-600 dark:bg-gray-700">
                                        <div>
                                            <span class="text-sm font-semibold text-gray-700 dark:text-gray-300 block">Mandatory Charge</span>
                                            <span class="text-xs text-gray-400">If unchecked, this charge will be optional for student invoices.</span>
                                        </div>
                                    </label>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3 pt-6 border-t border-gray-100 dark:border-gray-700">
                                <a href="fee_structures.php" class="px-5 py-2.5 rounded-xl border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700 transition font-semibold">
                                    Cancel
                                </a>
                                <button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold rounded-xl shadow-lg transition">
                                    <i class="fas fa-save mr-2"></i> Save Fee Structure
                                </button>
                            </div>
                        </form>
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
function toggleClasses(source) {
    const checkboxes = document.getElementsByName('class_ids[]');
    for (let i = 0; i < checkboxes.length; i++) {
        checkboxes[i].checked = source;
    }
}

function filterTerms() {
    const yearSelect = document.getElementById('fee_year');
    const termSelect = document.getElementById('fee_term');
    if (!yearSelect || !termSelect) return;

    const selectedYearId = yearSelect.value;
    const options = termSelect.options;
    let firstVisible = null;
    const prevValue = termSelect.value;
    let prevStillVisible = false;

    for (let i = 0; i < options.length; i++) {
        const opt = options[i];
        const yid = opt.getAttribute('data-year-id');
        if (!yid) {
            // Placeholder "Select Term" option - show only when no year chosen
            opt.style.display = selectedYearId ? 'none' : '';
            continue;
        }
        if (yid === selectedYearId) {
            opt.style.display = '';
            if (opt.value === prevValue) prevStillVisible = true;
            if (!firstVisible) firstVisible = opt;
        } else {
            opt.style.display = 'none';
        }
    }

    if (!prevStillVisible) {
        termSelect.value = firstVisible ? firstVisible.value : '';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    filterTerms();
});
</script>