<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'finance_officer'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle fee structure status toggle
if (isset($_POST['toggle_status']) && isset($_POST['structure_id'])) {
    $structure_id = filter_input(INPUT_POST, 'structure_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "UPDATE fee_structures SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :structure_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':structure_id', $structure_id);
    if ($stmt->execute()) {
        $success_message = "Fee structure status updated successfully!";
    } else {
        $error_message = "Error updating fee structure status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$class_filter = isset($_GET['class']) ? $_GET['class'] : '';
$academic_year_filter = isset($_GET['academic_year']) ? $_GET['academic_year'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(structure_name LIKE :search OR class LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($class_filter) {
    $where_conditions[] = "class = :class";
    $params[':class'] = $class_filter;
}

if ($academic_year_filter) {
    $where_conditions[] = "academic_year = :academic_year";
    $params[':academic_year'] = $academic_year_filter;
}

if ($status_filter) {
    $where_conditions[] = "status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch fee structures
$query = "SELECT fs.*, 
          COUNT(DISTINCT sp.id) as student_count,
          SUM(CASE WHEN sp.payment_status = 'paid' THEN fs.total_amount ELSE 0 END) as collected_amount,
          SUM(CASE WHEN sp.payment_status = 'pending' THEN fs.total_amount ELSE 0 END) as pending_amount
          FROM fee_structures fs
          LEFT JOIN student_payments sp ON fs.id = sp.fee_structure_id
          $where_clause
          GROUP BY fs.id
          ORDER BY fs.academic_year DESC, fs.class";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$fee_structures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get classes for filter
$classes_query = "SELECT DISTINCT class FROM fee_structures WHERE class IS NOT NULL ORDER BY class";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get academic years for filter
$years_query = "SELECT DISTINCT academic_year FROM fee_structures WHERE academic_year IS NOT NULL ORDER BY academic_year DESC";
$years_stmt = $db->query($years_query);
$academic_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get fee structure statistics
$stats_query = "SELECT 
    COUNT(*) as total_structures,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_structures,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_structures,
    AVG(total_amount) as avg_fee_amount
    FROM fee_structures";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Fee Structures";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Fee Structures</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Finance
                    </a>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>Create Structure
                    </a>
                </div>
            </div>

            <?php if (isset($success_message)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error_message)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Structures</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_structures']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-file-invoice-dollar text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Structures</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['active_structures']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inactive Structures</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['inactive_structures']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full">
                            <i class="fas fa-times-circle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Average Fee</p>
                            <p class="text-2xl font-bold text-purple-600">$<?php echo number_format($stats['avg_fee_amount'], 0); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-dollar-sign text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-4">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="Search by structure name or class..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="class" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Classes</option>
                                <?php foreach ($classes as $class): ?>
                                <option value="<?php echo htmlspecialchars($class); ?>" <?php echo $class_filter === $class ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($class); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="academic_year" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Years</option>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" <?php echo $academic_year_filter === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Fee Structures Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($fee_structures as $structure): ?>
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="p-6">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-800"><?php echo htmlspecialchars($structure['structure_name']); ?></h3>
                                <p class="text-sm text-blue-600 font-medium"><?php echo htmlspecialchars($structure['class']); ?></p>
                                <p class="text-sm text-gray-600"><?php echo htmlspecialchars($structure['academic_year']); ?></p>
                            </div>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $structure['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                <?php echo ucfirst($structure['status']); ?>
                            </span>
                        </div>

                        <div class="mb-4">
                            <div class="text-2xl font-bold text-green-600 mb-2">$<?php echo number_format($structure['total_amount'], 2); ?></div>
                            <div class="text-sm text-gray-600">Total Fee Amount</div>
                        </div>

                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?php echo $structure['student_count']; ?></div>
                                <div class="text-sm text-gray-600">Students</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-purple-600">
                                    <?php echo $structure['student_count'] > 0 ? number_format(($structure['collected_amount'] / ($structure['collected_amount'] + $structure['pending_amount'])) * 100, 1) : 0; ?>%
                                </div>
                                <div class="text-sm text-gray-600">Collected</div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <div class="flex justify-between text-sm text-gray-600 mb-1">
                                <span>Collection Progress</span>
                                <span>$<?php echo number_format($structure['collected_amount'], 0); ?> / $<?php echo number_format($structure['collected_amount'] + $structure['pending_amount'], 0); ?></span>
                            </div>
                            <div class="w-full bg-gray-200 rounded-full h-2">
                                <?php 
                                $total_expected = $structure['collected_amount'] + $structure['pending_amount'];
                                $collection_rate = $total_expected > 0 ? ($structure['collected_amount'] / $total_expected) * 100 : 0;
                                ?>
                                <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $collection_rate; ?>%"></div>
                            </div>
                        </div>

                        <?php if ($structure['description']): ?>
                        <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                            <div class="text-sm text-gray-600"><?php echo htmlspecialchars($structure['description']); ?></div>
                        </div>
                        <?php endif; ?>

                        <div class="flex justify-between items-center">
                            <a href="view.php?id=<?php echo $structure['id']; ?>" 
                                class="text-blue-600 hover:text-blue-800 text-sm font-medium">
                                View Details
                            </a>
                            <div class="flex space-x-2">
                                <a href="edit.php?id=<?php echo $structure['id']; ?>" 
                                    class="text-gray-600 hover:text-gray-800">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="structure_id" value="<?php echo $structure['id']; ?>">
                                    <button type="submit" name="toggle_status" 
                                        class="text-<?php echo $structure['status'] === 'active' ? 'red' : 'green'; ?>-600 hover:text-<?php echo $structure['status'] === 'active' ? 'red' : 'green'; ?>-800"
                                        title="<?php echo $structure['status'] === 'active' ? 'Deactivate' : 'Activate'; ?> Structure">
                                        <i class="fas fa-<?php echo $structure['status'] === 'active' ? 'ban' : 'check'; ?>"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if (empty($fee_structures)): ?>
            <div class="text-center py-12">
                <i class="fas fa-file-invoice-dollar text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No fee structures found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $class_filter || $academic_year_filter || $status_filter): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        Get started by creating your first fee structure.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                    Create First Structure
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
