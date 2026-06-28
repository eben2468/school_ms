<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hostel_warden'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle allocation status updates
if (isset($_POST['update_status']) && isset($_POST['allocation_id'])) {
    $allocation_id = filter_input(INPUT_POST, 'allocation_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = filter_input(INPUT_POST, 'new_status', FILTER_SANITIZE_STRING);
    
    $query = "UPDATE hostel_allocations SET status = :status WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':status', $new_status);
    $stmt->bindParam(':id', $allocation_id);
    
    if ($stmt->execute()) {
        $success_message = "Allocation status updated successfully!";
    } else {
        $error_message = "Error updating allocation status.";
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$block_filter = isset($_GET['block']) ? $_GET['block'] : '';

// Build where conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(s.name LIKE :search OR hr.room_number LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "ha.status = :status";
    $params[':status'] = $status_filter;
}

if ($block_filter) {
    $where_conditions[] = "hb.id = :block_id";
    $params[':block_id'] = $block_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch allocations
$query = "SELECT ha.*, s.name as student_name, hr.room_number,
          hb.name as block_name,
          sp.student_id as student_number
          FROM hostel_allocations ha
          JOIN users s ON ha.student_id = s.id
          LEFT JOIN student_profiles sp ON s.id = sp.user_id
          JOIN hostel_rooms hr ON ha.room_id = hr.id
          JOIN hostel_blocks hb ON hr.block_id = hb.id
          $where_clause
          ORDER BY ha.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$allocations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get blocks for filter
$blocks_query = "SELECT id, name as block_name FROM hostel_blocks ORDER BY name";
$blocks_stmt = $db->query($blocks_query);
$blocks = $blocks_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get allocation statistics
$stats_query = "SELECT 
    COUNT(*) as total_allocations,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_allocations,
    COUNT(CASE WHEN status = 'checked_out' THEN 1 END) as checked_out,
    COUNT(CASE WHEN status = 'transferred' THEN 1 END) as transferred
    FROM hostel_allocations";
$stats_stmt = $db->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$title = "Hostel Allocations";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Hostel Allocations</h1>
                                <p class="text-blue-100 text-lg">Manage student room assignments and accommodations</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        <?php echo number_format($stats['total_allocations']); ?> Total
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-check-circle mr-2"></i>
                                        <?php echo number_format($stats['active_allocations']); ?> Active
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-bed text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="flex justify-between items-center mb-6">
                    <div class="flex flex-wrap items-center gap-3 no-stack">
                        <a href="../index.php" class="inline-flex items-center whitespace-nowrap bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 font-medium px-4 py-2 rounded-lg shadow-sm hover:shadow-md transition-all duration-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Hostel
                        </a>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                        <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg hover:shadow-xl transition-all duration-200 inline-flex items-center whitespace-nowrap">
                            <i class="fas fa-plus mr-2"></i>New Allocation
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="flex space-x-2">
                        <button onclick="exportAllocations()" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg flex items-center">
                            <i class="fas fa-download mr-2"></i>Export
                        </button>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Allocations</p>
                                <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_allocations']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active</p>
                                <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['active_allocations']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Checked Out</p>
                                <p class="text-3xl font-bold text-orange-600 dark:text-orange-400"><?php echo number_format($stats['checked_out']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-sign-out-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Transferred</p>
                                <p class="text-3xl font-bold text-purple-600 dark:text-purple-400"><?php echo number_format($stats['transferred']); ?></p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-exchange-alt text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form action="" method="GET" class="flex gap-4 flex-wrap">
                            <div class="flex-grow min-w-64">
                                <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                    placeholder="Search by student name or room number..." 
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                            <div class="w-48">
                                <select name="block" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Blocks</option>
                                    <?php foreach ($blocks as $block): ?>
                                    <option value="<?php echo $block['id']; ?>" <?php echo $block_filter == $block['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($block['block_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="w-48">
                                <select name="status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="checked_out" <?php echo $status_filter === 'checked_out' ? 'selected' : ''; ?>>Checked Out</option>
                                    <option value="transferred" <?php echo $status_filter === 'transferred' ? 'selected' : ''; ?>>Transferred</option>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg font-medium transition-colors duration-200">
                                <i class="fas fa-search mr-2"></i>Filter
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Allocations Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <?php if (!empty($allocations)): ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Block & Room</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Allocation Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($allocations as $allocation): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold mr-3">
                                                <?php echo strtoupper(substr($allocation['student_name'], 0, 1)); ?>
                                            </div>
                                            <div>
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['student_name']); ?></div>
                                                <?php if ($allocation['student_number']): ?>
                                                <div class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($allocation['student_number']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($allocation['block_name']); ?></div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">Room <?php echo htmlspecialchars($allocation['room_number']); ?></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-300">
                                        <?php echo date('M j, Y', strtotime($allocation['allocation_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                            <?php 
                                            switch($allocation['status']) {
                                                case 'active': echo 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'; break;
                                                case 'checked_out': echo 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200'; break;
                                                case 'transferred': echo 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200'; break;
                                                default: echo 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200';
                                            }
                                            ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $allocation['status'])); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                        <div class="flex justify-end space-x-2">
                                            <a href="view.php?id=<?php echo $allocation['id']; ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 dark:bg-blue-900 dark:text-blue-200 dark:hover:bg-blue-800 transition-colors duration-200">
                                                <i class="fas fa-eye mr-1"></i>View
                                            </a>
                                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                                            <a href="edit.php?id=<?php echo $allocation['id']; ?>"
                                                class="inline-flex items-center px-3 py-1 border border-transparent text-sm leading-4 font-medium rounded-md text-indigo-700 bg-indigo-100 hover:bg-indigo-200 dark:bg-indigo-900 dark:text-indigo-200 dark:hover:bg-indigo-800 transition-colors duration-200">
                                                <i class="fas fa-edit mr-1"></i>Edit
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-12">
                        <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-bed text-gray-400 text-4xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No allocations found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mb-4">
                            <?php if ($search || $status_filter || $block_filter): ?>
                                Try adjusting your search criteria.
                            <?php else: ?>
                                Get started by creating your first allocation.
                            <?php endif; ?>
                        </p>
                        <?php if (in_array($user_role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
                        <a href="create.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm font-medium">
                            <i class="fas fa-plus mr-2"></i>Create First Allocation
                        </a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function exportAllocations() {
    // Create CSV content
    let csvContent = "data:text/csv;charset=utf-8,";
    csvContent += "Student Name,Student ID,Block,Room,Allocation Date,Status\n";
    
    <?php foreach ($allocations as $allocation): ?>
    csvContent += "<?php echo addslashes($allocation['student_name']); ?>,";
    csvContent += "<?php echo addslashes($allocation['student_number'] ?? ''); ?>,";
    csvContent += "<?php echo addslashes($allocation['block_name']); ?>,";
    csvContent += "<?php echo addslashes($allocation['room_number']); ?>,";
    csvContent += "<?php echo date('Y-m-d', strtotime($allocation['allocation_date'])); ?>,";
    csvContent += "<?php echo addslashes(ucfirst(str_replace('_', ' ', $allocation['status']))); ?>\n";
    <?php endforeach; ?>
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "hostel_allocations_" + new Date().toISOString().split('T')[0] + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php if (isset($success_message)): ?>
<script>
    // Show success message
    setTimeout(() => {
        const alert = document.createElement('div');
        alert.className = 'fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50';
        alert.textContent = '<?php echo addslashes($success_message); ?>';
        document.body.appendChild(alert);
        setTimeout(() => alert.remove(), 3000);
    }, 100);
</script>
<?php endif; ?>

<?php if (isset($error_message)): ?>
<script>
    // Show error message
    setTimeout(() => {
        const alert = document.createElement('div');
        alert.className = 'fixed top-4 right-4 bg-red-500 text-white px-6 py-3 rounded-lg shadow-lg z-50';
        alert.textContent = '<?php echo addslashes($error_message); ?>';
        document.body.appendChild(alert);
        setTimeout(() => alert.remove(), 3000);
    }, 100);
</script>
<?php endif; ?>
