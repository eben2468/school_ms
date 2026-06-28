<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle driver status toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status']) && isset($_POST['driver_id'])) {
    $driver_id = filter_input(INPUT_POST, 'driver_id', FILTER_SANITIZE_NUMBER_INT);
    if (!empty($driver_id)) {
        try {
            $query = "UPDATE transport_drivers SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = :driver_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':driver_id', $driver_id);
            if ($stmt->execute()) {
                $success_message = "Driver status updated successfully!";
            } else {
                $error_message = "Error updating driver status.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    }
}

// Get filter parameters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

// Build conditions
$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(name LIKE :search OR license_number LIKE :search OR phone LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch drivers
$drivers_query = "SELECT * FROM transport_drivers $where_clause ORDER BY name";
$drivers_stmt = $db->prepare($drivers_query);
foreach ($params as $key => $value) {
    $drivers_stmt->bindValue($key, $value);
}
$drivers_stmt->execute();
$drivers = $drivers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total_drivers,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_drivers,
    COUNT(CASE WHEN status = 'inactive' THEN 1 END) as inactive_drivers
    FROM transport_drivers";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

$title = "Drivers Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Drivers']
];

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
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4 mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Drivers Management</h1>
                <div class="flex flex-wrap items-center gap-3 no-stack">
                    <a href="../index.php" class="inline-flex items-center whitespace-nowrap text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                    <a href="create.php" class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg shadow hover:shadow-lg transition">
                        <i class="fas fa-plus mr-2"></i>Add Driver
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

            <!-- Stats Cards -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Registered Drivers</p>
                        <p class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo number_format($stats['total_drivers']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                        <i class="fas fa-id-card text-blue-600 dark:text-blue-400 text-xl"></i>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Drivers</p>
                        <p class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo number_format($stats['active_drivers']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Inactive Drivers</p>
                        <p class="text-3xl font-bold text-red-600 dark:text-red-400"><?php echo number_format($stats['inactive_drivers']); ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                        <i class="fas fa-times-circle text-red-600 dark:text-red-400 text-xl"></i>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 mb-6 p-4">
                <form action="" method="GET" class="flex flex-col md:flex-row gap-4">
                    <div class="flex-grow">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                            placeholder="Search by name, license number, or phone..." 
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                    </div>
                    <div class="w-full md:w-48">
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">All Statuses</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                        Apply Filters
                    </button>
                </form>
            </div>

            <!-- Drivers Grid List -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                <?php foreach ($drivers as $driver): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden hover:shadow-lg transition duration-200 flex flex-col">
                    <div class="p-6 flex-grow">
                        <div class="flex justify-between items-start mb-4">
                            <div>
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($driver['name']); ?></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($driver['phone']); ?></p>
                            </div>
                            <span class="px-2 py-0.5 inline-flex text-xs leading-5 font-semibold rounded-full 
                                <?php echo $driver['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-950 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300'; ?>">
                                <?php echo ucfirst($driver['status']); ?>
                            </span>
                        </div>

                        <div class="space-y-2 text-sm text-gray-600 dark:text-gray-400 mb-4">
                            <div class="flex items-center">
                                <i class="fas fa-id-card text-blue-500 mr-2 w-4"></i>
                                <span class="font-medium">License:</span>
                                <span class="ml-1"><?php echo htmlspecialchars($driver['license_number']); ?></span>
                            </div>
                            <div class="flex items-center">
                                <i class="fas fa-calendar-alt text-purple-500 mr-2 w-4"></i>
                                <span class="font-medium">Expires:</span>
                                <span class="ml-1 <?php echo (strtotime($driver['license_expiry']) < time()) ? 'text-red-600 font-bold' : ''; ?>">
                                    <?php echo date('Y-m-d', strtotime($driver['license_expiry'])); ?>
                                    <?php if (strtotime($driver['license_expiry']) < time()): ?>
                                        (Expired)
                                    <?php endif; ?>
                                </span>
                            </div>
                            <?php if (!empty($driver['notes'])): ?>
                            <div class="mt-3 bg-gray-50 dark:bg-gray-750 p-2.5 rounded-lg border border-gray-100 dark:border-gray-700">
                                <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Notes:</div>
                                <div class="text-xs text-gray-600 dark:text-gray-300 italic"><?php echo htmlspecialchars($driver['notes']); ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-750/50 border-t border-gray-100 dark:border-gray-700 flex justify-end items-center">
                        <form action="" method="POST" class="inline">
                            <input type="hidden" name="driver_id" value="<?php echo $driver['id']; ?>">
                            <button type="submit" name="toggle_status" 
                                class="inline-flex items-center px-3 py-1.5 rounded text-xs font-semibold <?php echo $driver['status'] === 'active' ? 'bg-red-100 hover:bg-red-200 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-green-100 hover:bg-green-200 text-green-700 dark:bg-green-950 dark:text-green-300'; ?> transition duration-200">
                                <i class="fas fa-power-off mr-1"></i>
                                <?php echo $driver['status'] === 'active' ? 'Deactivate' : 'Activate'; ?>
                            </button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>

                <?php if (empty($drivers)): ?>
                <div class="col-span-full bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-12 text-center">
                    <i class="fas fa-user-slash text-gray-400 text-6xl mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No drivers found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-4">Get started by creating your first transport driver profile.</p>
                    <a href="create.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                        Add First Driver
                    </a>
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
