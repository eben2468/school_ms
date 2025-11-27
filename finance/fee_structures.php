<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Handle fee structure deletion
if (isset($_POST['delete_fee']) && isset($_POST['fee_id'])) {
    $fee_id = filter_input(INPUT_POST, 'fee_id', FILTER_SANITIZE_NUMBER_INT);
    
    try {
        // Check if fee structure is being used
        $check_query = "SELECT COUNT(*) as count FROM student_payments WHERE fee_structure_id = :fee_id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':fee_id', $fee_id);
        $check_stmt->execute();
        $usage = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usage['count'] > 0) {
            $error = "Cannot delete fee structure. It has associated payment records.";
        } else {
            $query = "DELETE FROM fee_structures WHERE id = :fee_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':fee_id', $fee_id);
            $stmt->execute();
            $success = "Fee structure deleted successfully.";
        }
    } catch (PDOException $e) {
        $error = "Error deleting fee structure. Please try again.";
    }
}

// Get filter parameters
$academic_year_filter = filter_input(INPUT_GET, 'academic_year', FILTER_SANITIZE_STRING) ?: '';
$class_filter = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: '';
$fee_type_filter = filter_input(INPUT_GET, 'fee_type', FILTER_SANITIZE_STRING) ?: '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($academic_year_filter) {
    $where_conditions[] = "fs.academic_year = :academic_year";
    $params[':academic_year'] = $academic_year_filter;
}

if ($class_filter) {
    $where_conditions[] = "fs.class_id = :class_id";
    $params[':class_id'] = $class_filter;
}

if ($fee_type_filter) {
    $where_conditions[] = "fs.fee_type = :fee_type";
    $params[':fee_type'] = $fee_type_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch fee structures
$query = "SELECT fs.*, c.name as class_name, c.grade_level,
          COUNT(sp.id) as payment_count,
          SUM(CASE WHEN sp.payment_status = 'paid' THEN sp.amount_paid ELSE 0 END) as total_collected
          FROM fee_structures fs
          LEFT JOIN classes c ON fs.class_id = c.id
          LEFT JOIN student_payments sp ON fs.id = sp.fee_structure_id
          $where_clause
          GROUP BY fs.id
          ORDER BY fs.academic_year DESC, c.grade_level, fs.fee_type";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$fee_structures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$academic_years_query = "SELECT DISTINCT academic_year FROM fee_structures ORDER BY academic_year DESC";
$academic_years_stmt = $db->query($academic_years_query);
$academic_years = $academic_years_stmt->fetchAll(PDO::FETCH_COLUMN);

$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$fee_types = ['tuition', 'library', 'laboratory', 'transport', 'hostel', 'examination', 'other'];
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Fee Structures</h1>
                <div class="flex space-x-3">
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Finance
                    </a>
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                    <a href="create_fee_structure.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Create Fee Structure
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <select name="academic_year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Academic Years</option>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $academic_year_filter === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="class_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo $class['id']; ?>" <?php echo $class_filter == $class['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="fee_type" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Fee Types</option>
                                <?php foreach ($fee_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $fee_type_filter === $type ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($type); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Fee Structures Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($fee_structures as $fee): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800 mb-1">
                                    <?php echo ucfirst(htmlspecialchars($fee['fee_type'])); ?>
                                </h3>
                                <p class="text-sm text-gray-600">
                                    <?php echo htmlspecialchars($fee['class_name'] ? $fee['grade_level'] . ' - ' . $fee['class_name'] : 'All Classes'); ?>
                                </p>
                                <p class="text-xs text-gray-500">
                                    <?php echo htmlspecialchars($fee['academic_year']); ?> • <?php echo ucfirst($fee['academic_term']); ?> Term
                                </p>
                            </div>
                            <div class="text-right">
                                <div class="text-2xl font-bold text-blue-600">₵<?php echo number_format($fee['amount'], 2); ?></div>
                            </div>
                        </div>

                        <?php if ($fee['description']): ?>
                        <p class="text-sm text-gray-600 mb-4"><?php echo htmlspecialchars($fee['description']); ?></p>
                        <?php endif; ?>

                        <div class="grid grid-cols-2 gap-4 mb-4 text-sm">
                            <div>
                                <span class="text-gray-500">Payments:</span>
                                <span class="font-semibold"><?php echo $fee['payment_count']; ?></span>
                            </div>
                            <div>
                                <span class="text-gray-500">Collected:</span>
                                <span class="font-semibold text-green-600">₵<?php echo number_format($fee['total_collected'], 2); ?></span>
                            </div>
                        </div>

                        <div class="flex justify-between items-center">
                            <a href="view_fee_structure.php?id=<?php echo $fee['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                            <div class="flex space-x-2">
                                <a href="edit_fee_structure.php?id=<?php echo $fee['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline" 
                                    onsubmit="return confirm('Are you sure you want to delete this fee structure?')">
                                    <input type="hidden" name="fee_id" value="<?php echo $fee['id']; ?>">
                                    <button type="submit" name="delete_fee" 
                                        class="text-red-600 hover:text-red-800">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($fee_structures)): ?>
            <div class="text-center py-12">
                <i class="fas fa-money-bill-wave text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No fee structures found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($academic_year_filter || $class_filter || $fee_type_filter): ?>
                        Try adjusting your filter criteria.
                    <?php else: ?>
                        Get started by creating your first fee structure.
                    <?php endif; ?>
                </p>
                <?php if (in_array($user_role, ['super_admin', 'school_admin', 'accountant'])): ?>
                <a href="create_fee_structure.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create Fee Structure
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Summary Statistics -->
            <?php if (!empty($fee_structures)): ?>
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Summary</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo count($fee_structures); ?></div>
                            <div class="text-sm text-gray-600">Fee Structures</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600">
                                ₵<?php echo number_format(array_sum(array_column($fee_structures, 'amount')), 2); ?>
                            </div>
                            <div class="text-sm text-gray-600">Total Amount</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-purple-600">
                                <?php echo array_sum(array_column($fee_structures, 'payment_count')); ?>
                            </div>
                            <div class="text-sm text-gray-600">Total Payments</div>
                        </div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-orange-600">
                                ₵<?php echo number_format(array_sum(array_column($fee_structures, 'total_collected')), 2); ?>
                            </div>
                            <div class="text-sm text-gray-600">Total Collected</div>
                        </div>
                    </div>
                </div>
            </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
