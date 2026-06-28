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

// Handle fee structure deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $fee_id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Since we redesigned the invoices table, check if this category has been invoiced
        $check_query = "SELECT COUNT(*) FROM finance_invoice_items ii
                        JOIN finance_fee_structures fs ON ii.category_id = fs.category_id
                        WHERE fs.id = :fee_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':fee_id', $fee_id);
        $check_stmt->execute();
        $usage = $check_stmt->fetchColumn();
        
        if ($usage > 0) {
            $error = "Cannot delete fee structure. Student invoices have already been generated with this fee item.";
        } else {
            $query = "DELETE FROM finance_fee_structures WHERE id = :fee_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':fee_id', $fee_id);
            $stmt->execute();
            logFinanceAudit('Delete Fee Structure', 'Fee Structures', $fee_id, "Deleted fee structure ID: $fee_id", $db);
            $success = "Fee structure deleted successfully.";
        }
    } catch (PDOException $e) {
        $error = "Error deleting fee structure: " . $e->getMessage();
    }
}

// Get filter parameters
$academic_year_filter = filter_input(INPUT_GET, 'academic_year_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$term_filter = filter_input(INPUT_GET, 'term_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$category_filter = filter_input(INPUT_GET, 'category_id', FILTER_SANITIZE_NUMBER_INT) ?: '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($academic_year_filter) {
    $where_conditions[] = "fs.academic_year_id = :academic_year_id";
    $params[':academic_year_id'] = $academic_year_filter;
}

if ($term_filter) {
    $where_conditions[] = "t.term_number = :term_number";
    $params[':term_number'] = $term_filter;
}

if ($class_filter) {
    $where_conditions[] = "fs.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($category_filter) {
    $where_conditions[] = "fs.category_id = :category_id";
    $params[':category_id'] = $category_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch fee structures
$query = "SELECT fs.*, c.name as class_name, c.grade_level, ay.year_name, t.term_name, fc.name as category_name
          FROM finance_fee_structures fs
          LEFT JOIN classes c ON fs.class_id = c.id
          LEFT JOIN academic_years ay ON fs.academic_year_id = ay.id
          LEFT JOIN academic_terms t ON fs.term_id = t.id
          LEFT JOIN finance_fee_categories fc ON fs.category_id = fc.id
          $where_clause
          ORDER BY ay.year_name DESC, t.term_number, c.grade_level, fc.name";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$fee_structures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$academic_years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$academic_terms = $db->query("SELECT MIN(id) as id, term_number, term_name FROM academic_terms GROUP BY term_number, term_name ORDER BY term_number")->fetchAll(PDO::FETCH_ASSOC);
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
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Fee Structures</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure class billing amounts per term</p>
                    </div>
                    <div class="flex space-x-3">
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                        <a href="create_fee_structure.php" class="bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg hover:shadow-xl transition duration-300 flex items-center gap-2">
                            <i class="fas fa-plus"></i> Create Fee Structure
                        </a>
                        <?php endif; ?>
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

                <!-- Filters Panel -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700 p-6 mb-8">
                    <h2 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Filter Fee Structures</h2>
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <select name="academic_year_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Academic Years</option>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo $year['id']; ?>" <?php echo $academic_year_filter == $year['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="term_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Terms</option>
                                <?php foreach ($academic_terms as $term): ?>
                                <option value="<?php echo $term['term_number']; ?>" <?php echo $term_filter == $term['term_number'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($term['term_name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="class_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="category_id" class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-green-500 dark:bg-gray-700 dark:text-white transition">
                                <option value="">All Categories</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo $cat['id']; ?>" <?php echo $category_filter == $cat['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="md:col-span-4 flex justify-end">
                            <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-6 py-2.5 rounded-xl transition duration-200">
                                Apply Filters
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Fee Structures Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <?php foreach ($fee_structures as $fee): ?>
                    <?php $is_mandatory = !empty($fee['is_mandatory']); ?>
                    <div class="group relative flex flex-col bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700/60 shadow-sm hover:shadow-2xl hover:shadow-emerald-500/10 hover:-translate-y-1.5 transition-all duration-300 overflow-hidden">
                        <!-- Gradient header -->
                        <div class="relative px-6 pt-6 pb-8 overflow-hidden" style="background: var(--primary-gradient, linear-gradient(135deg, #059669 0%, #16a34a 50%, #0d9488 100%));">
                            <!-- Decorative glow circles -->
                            <div class="absolute -top-10 -right-8 w-32 h-32 rounded-full" style="background: rgba(255,255,255,0.12); filter: blur(24px);"></div>
                            <div class="absolute -bottom-12 -left-6 w-28 h-28 rounded-full" style="background: rgba(94,234,212,0.25); filter: blur(24px);"></div>

                            <div class="relative flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[11px] font-semibold text-white truncate max-w-full" style="background: rgba(255,255,255,0.18); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35);">
                                        <i class="fas fa-file-invoice-dollar text-[10px]"></i>
                                        <?php echo htmlspecialchars($fee['category_name']); ?>
                                    </span>
                                    <h3 class="text-xl font-bold text-white mt-3 leading-snug truncate" style="text-shadow: 0 1px 2px rgba(0,0,0,0.15);" title="<?php echo htmlspecialchars($fee['grade_level'] . ' - ' . $fee['class_name']); ?>">
                                        <?php echo htmlspecialchars($fee['grade_level'] . ' - ' . $fee['class_name']); ?>
                                    </h3>
                                </div>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[10px] font-bold uppercase tracking-wide text-white flex-shrink-0" style="background: rgba(255,255,255,0.18); box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35);">
                                    <i class="fas fa-user-tag text-[9px]"></i>
                                    <?php echo htmlspecialchars($fee['student_type']); ?>
                                </span>
                            </div>
                        </div>

                        <div class="px-6 pt-0 pb-6 flex-1">
                            <!-- Amount (floats over the gradient seam) -->
                            <div class="relative -mt-5 mb-5 bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 shadow-sm px-4 py-3.5">
                                <p class="text-[10px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-bold mb-0.5">Amount Payable</p>
                                <div class="text-3xl font-extrabold tracking-tight" style="color: var(--theme-primary, #059669);">
                                    <?php echo formatFinanceCurrency($fee['amount'], $db); ?>
                                </div>
                            </div>

                            <!-- Academic period -->
                            <div class="flex items-center flex-wrap gap-x-2 gap-y-1 text-xs text-gray-500 dark:text-gray-400 mb-4">
                                <i class="far fa-calendar-alt text-emerald-500/70 dark:text-emerald-400/70"></i>
                                <span class="font-medium"><?php echo htmlspecialchars($fee['year_name']); ?></span>
                                <span class="w-1 h-1 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                <span class="font-medium"><?php echo htmlspecialchars($fee['term_name']); ?></span>
                            </div>

                            <!-- Badges -->
                            <div class="flex flex-wrap gap-2 text-xs">
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg font-semibold <?php echo $is_mandatory ? 'bg-amber-50 text-amber-700 dark:bg-amber-950/20 dark:text-amber-300 ring-1 ring-amber-200/60 dark:ring-amber-800/40' : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300'; ?>">
                                    <i class="fas <?php echo $is_mandatory ? 'fa-exclamation-circle' : 'fa-info-circle'; ?>"></i>
                                    <?php echo $is_mandatory ? 'Mandatory' : 'Optional'; ?>
                                </span>
                            </div>
                        </div>

                        <!-- Footer -->
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                        <div class="px-6 py-3 bg-gray-50/70 dark:bg-gray-900/30 border-t border-gray-100 dark:border-gray-700 flex items-center justify-between">
                            <span class="text-[11px] font-medium text-gray-400 dark:text-gray-500">Structure #<?php echo (int)$fee['id']; ?></span>
                            <button onclick="confirmDelete(<?php echo $fee['id']; ?>)"
                                class="inline-flex items-center gap-1.5 text-xs font-semibold text-rose-600 dark:text-rose-400 bg-rose-50 dark:bg-rose-950/20 hover:bg-rose-600 hover:text-white dark:hover:bg-rose-600 px-3 py-1.5 rounded-lg transition-colors duration-200"
                                title="Delete fee structure">
                                <i class="fas fa-trash-alt"></i> Delete
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($fee_structures)): ?>
                <div class="text-center py-16 bg-white dark:bg-gray-800 rounded-2xl shadow-md border border-gray-100 dark:border-gray-700">
                    <i class="fas fa-wallet text-gray-300 dark:text-gray-600 text-6xl mb-4"></i>
                    <h3 class="text-xl font-bold text-gray-800 dark:text-white mb-1">No structures found</h3>
                    <p class="text-sm text-gray-500 dark:text-gray-400 mb-6">Create structural term charges to allow student invoicing.</p>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                    <a href="create_fee_structure.php" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-5 py-2.5 rounded-xl shadow-lg transition">
                        Create First Structure
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<form id="deleteForm" action="" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
function confirmDelete(id) {
    if (confirm("Are you sure you want to delete this fee structure? This action cannot be undone.")) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

