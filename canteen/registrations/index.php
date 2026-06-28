<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle registration deletion
if (isset($_POST['delete_registration']) && isset($_POST['registration_id'])) {
    $reg_id = filter_input(INPUT_POST, 'registration_id', FILTER_SANITIZE_NUMBER_INT);
    $query = "DELETE FROM canteen_registrations WHERE id = :reg_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':reg_id', $reg_id);
    if ($stmt->execute()) {
        $success_message = "Registration deleted successfully!";
    } else {
        $error_message = "Error deleting registration.";
    }
}

// Handle registration status update
if (isset($_POST['update_status']) && isset($_POST['registration_id']) && isset($_POST['status'])) {
    $reg_id = filter_input(INPUT_POST, 'registration_id', FILTER_SANITIZE_NUMBER_INT);
    $status = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if (in_array($status, ['active', 'inactive', 'expired'])) {
        $query = "UPDATE canteen_registrations SET status = :status WHERE id = :reg_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':status', $status);
        $stmt->bindParam(':reg_id', $reg_id);
        if ($stmt->execute()) {
            $success_message = "Registration status updated successfully!";
        } else {
            $error_message = "Error updating registration status.";
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : '';
$type_filter = isset($_GET['type']) ? trim($_GET['type']) : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.student_id LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "cr.status = :status";
    $params[':status'] = $status_filter;
}

if ($type_filter) {
    $where_conditions[] = "cr.registration_type = :type";
    $params[':type'] = $type_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch registrations
$query = "SELECT cr.*, u.name as student_name, u.student_id as admission_number,
                 (SELECT c.name 
                  FROM student_classes sc 
                  JOIN classes c ON sc.class_id = c.id 
                  WHERE sc.student_id = u.id AND sc.status = 'active' 
                  LIMIT 1) as class_name
          FROM canteen_registrations cr
          JOIN users u ON cr.student_id = u.id
          $where_clause
          ORDER BY cr.created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get registration statistics
$stats_query = "SELECT
    COUNT(*) as total_registrations,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_registrations,
    COUNT(CASE WHEN status = 'expired' THEN 1 END) as expired_registrations,
    SUM(amount_paid) as total_revenue
    FROM canteen_registrations";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Canteen Registrations";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Meal Plan Registrations</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Manage and track student meal plan subscriptions</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Canteen
                        </a>
                        <a href="create.php" class="inline-flex items-center whitespace-nowrap bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition-colors">
                            <i class="fas fa-plus mr-2"></i>Register Student
                        </a>
                    </div>
                </div>

                <!-- Navigation breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Registrations</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Subscriptions</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_registrations']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Plans</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['active_registrations']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-check text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Expired Plans</p>
                                <p class="text-3xl font-bold text-red-600 dark:text-red-400"><?php echo number_format($stats['expired_registrations']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-user-times text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Subscription Revenue</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400">₵<?php echo number_format($stats['total_revenue'] ?? 0, 2); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-coins text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-4">
                        <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                            <div class="flex-grow">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                    placeholder="Search by student name or admission ID..." 
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="w-full md:w-48">
                                <select name="status" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Statuses</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                    <option value="expired" <?php echo $status_filter === 'expired' ? 'selected' : ''; ?>>Expired</option>
                                </select>
                            </div>
                            <div class="w-full md:w-48">
                                <select name="type" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Types</option>
                                    <option value="daily" <?php echo $type_filter === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                    <option value="weekly" <?php echo $type_filter === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                    <option value="monthly" <?php echo $type_filter === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                    <option value="term" <?php echo $type_filter === 'term' ? 'selected' : ''; ?>>Term</option>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg transition-colors">
                                Filter
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-750">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Student Details</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Plan Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Validity Period</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Amount Paid</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($registrations as $reg): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($reg['student_name']); ?></div>
                                        <div class="text-xs text-gray-500 dark:text-gray-400">ID: <?php echo htmlspecialchars($reg['admission_number'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($reg['class_name'] ?? 'N/A'); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 dark:bg-blue-900/30 text-blue-800 dark:text-blue-300">
                                            <?php echo ucfirst($reg['registration_type']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <?php echo date('M d, Y', strtotime($reg['start_date'])); ?> - <?php echo date('M d, Y', strtotime($reg['end_date'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white">₵<?php echo number_format($reg['amount_paid'], 2); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $status_classes = [
                                            'active' => 'bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-300',
                                            'inactive' => 'bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-300',
                                            'expired' => 'bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-300'
                                        ];
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$reg['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                            <?php echo ucfirst($reg['status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-3">
                                            <?php if ($reg['status'] === 'active'): ?>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <input type="hidden" name="status" value="inactive">
                                                <button type="submit" name="update_status" class="text-yellow-600 hover:text-yellow-900" title="Deactivate Plan">
                                                    Deactivate
                                                </button>
                                            </form>
                                            <?php elseif ($reg['status'] === 'inactive'): ?>
                                            <form action="" method="POST" class="inline">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <input type="hidden" name="status" value="active">
                                                <button type="submit" name="update_status" class="text-green-600 hover:text-green-900" title="Activate Plan">
                                                    Activate
                                                </button>
                                            </form>
                                            <?php endif; ?>
                                            
                                            <form action="" method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this subscription?')">
                                                <input type="hidden" name="registration_id" value="<?php echo $reg['id']; ?>">
                                                <button type="submit" name="delete_registration" class="text-red-600 hover:text-red-900" title="Delete">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if (empty($registrations)): ?>
                <div class="text-center py-12 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 mt-6">
                    <i class="fas fa-user-check text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No registrations found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">No meal plan registrations match the filters or search query.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>