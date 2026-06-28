<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle record status update
if (isset($_POST['update_status']) && isset($_POST['record_id']) && isset($_POST['status'])) {
    $record_id = filter_input(INPUT_POST, 'record_id', FILTER_SANITIZE_NUMBER_INT);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE health_records SET status = :status WHERE id = :record_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':record_id', $record_id);
    
    if ($stmt->execute()) {
        $success_message = "Record status updated successfully!";
    } else {
        $error_message = "Error updating record status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : '';
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(u.name LIKE :search OR u.student_id LIKE :search OR hr.complaint LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "hr.status = :status";
    $params[':status'] = $status_filter;
}

if ($date_from) {
    $where_conditions[] = "hr.visit_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $where_conditions[] = "hr.visit_date <= :date_to";
    $params[':date_to'] = $date_to;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch health records
$query = "SELECT hr.*, u.name as student_name, sp.student_id, c.name as class_name
          FROM health_records hr
          JOIN users u ON hr.student_id = u.id
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          $where_clause
          ORDER BY hr.visit_date DESC, hr.visit_time DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get health statistics
$stats_query = "SELECT 
    COUNT(*) as total_records,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_cases,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_cases,
    COUNT(CASE WHEN visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as recent_visits
    FROM health_records";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Medical Records";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-4 lg:p-8 flex-1">
        <div class="max-w-7xl mx-auto">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Medical Records</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Health
                    </a>
                    <a href="create.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-plus mr-2"></i>New Record
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
                            <p class="text-sm font-medium text-gray-600">Total Records</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['total_records']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full">
                            <i class="fas fa-file-medical text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Cases</p>
                            <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['active_cases']); ?></p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-full">
                            <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Resolved Cases</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['resolved_cases']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full">
                            <i class="fas fa-check-circle text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Recent Visits (7 days)</p>
                            <p class="text-2xl font-bold text-purple-600"><?php echo number_format($stats['recent_visits']); ?></p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-full">
                            <i class="fas fa-calendar-day text-purple-600 text-xl"></i>
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
                                placeholder="Search by student name, ID, or complaint..." 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">All Status</option>
                                <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                <option value="referred" <?php echo $status_filter === 'referred' ? 'selected' : ''; ?>>Referred</option>
                            </select>
                        </div>
                        <div>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" 
                                placeholder="From Date" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" 
                                placeholder="To Date" 
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <button type="submit" class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                Filter
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Records Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Student</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Visit Details</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Complaint</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Treatment</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($records as $record): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-gray-900"><?php echo htmlspecialchars($record['student_name']); ?></div>
                                    <div class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($record['student_id'] ?? ''); ?></div>
                                    <?php if (!empty($record['class_name'])): ?>
                                    <div class="text-xs text-gray-400"><?php echo htmlspecialchars($record['class_name']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-900"><?php echo date('M j, Y', strtotime($record['visit_date'])); ?></div>
                                    <div class="text-sm text-gray-500"><?php echo date('g:i A', strtotime($record['visit_time'])); ?></div>
                                    <?php if ($record['temperature']): ?>
                                    <div class="text-xs text-gray-400">Temp: <?php echo $record['temperature']; ?>°F</div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($record['complaint']); ?></div>
                                    <?php if ($record['symptoms']): ?>
                                    <div class="text-sm text-gray-500 mt-1"><?php echo htmlspecialchars($record['symptoms']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-gray-900"><?php echo htmlspecialchars($record['treatment'] ?? 'N/A'); ?></div>
                                    <?php if ($record['medication']): ?>
                                    <div class="text-sm text-gray-500 mt-1">Med: <?php echo htmlspecialchars($record['medication']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <?php
                                    $status_classes = [
                                        'active' => 'bg-yellow-100 text-yellow-800',
                                        'resolved' => 'bg-green-100 text-green-800',
                                        'referred' => 'bg-blue-100 text-blue-800'
                                    ];
                                    ?>
                                    <span class="inline-flex px-2 py-1 text-xs font-semibold rounded-full <?php echo $status_classes[$record['status']] ?? 'bg-gray-100 text-gray-800'; ?>">
                                        <?php echo ucfirst($record['status']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                    <a href="view.php?id=<?php echo $record['id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">View</a>
                                    <a href="edit.php?id=<?php echo $record['id']; ?>" class="text-indigo-600 hover:text-indigo-900 mr-3">Edit</a>
                                    
                                    <?php if ($record['status'] === 'active'): ?>
                                    <form action="" method="POST" class="inline">
                                        <input type="hidden" name="record_id" value="<?php echo $record['id']; ?>">
                                        <input type="hidden" name="status" value="resolved">
                                        <button type="submit" name="update_status" class="text-green-600 hover:text-green-900">Mark Resolved</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (empty($records)): ?>
            <div class="text-center py-12">
                <i class="fas fa-file-medical text-gray-400 text-6xl mb-4"></i>
                <h3 class="text-lg font-medium text-gray-900 mb-2">No medical records found</h3>
                <p class="text-gray-500 mb-4">
                    <?php if ($search || $status_filter || $date_from || $date_to): ?>
                        Try adjusting your search criteria.
                    <?php else: ?>
                        No medical records have been created yet.
                    <?php endif; ?>
                </p>
                <a href="create.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                    Create First Record
                </a>
            </div>
            <?php endif; ?>
                </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

