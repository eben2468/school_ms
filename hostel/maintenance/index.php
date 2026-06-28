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
$success_message = '';
$error_message = '';

// Handle status updates or assignment updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_request'])) {
        $request_id = filter_input(INPUT_POST, 'request_id', FILTER_SANITIZE_NUMBER_INT);
        $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
        $assigned_to = filter_input(INPUT_POST, 'assigned_to', FILTER_SANITIZE_NUMBER_INT) ?: null;
        
        if ($request_id && $status) {
            try {
                $resolved_date = ($status === 'resolved') ? date('Y-m-d') : null;
                $query = "UPDATE hostel_maintenance 
                          SET status = :status, assigned_to = :assigned_to, resolved_date = :resolved_date 
                          WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':status', $status);
                $stmt->bindParam(':assigned_to', $assigned_to);
                $stmt->bindParam(':resolved_date', $resolved_date);
                $stmt->bindParam(':id', $request_id);
                
                if ($stmt->execute()) {
                    $success_message = "Maintenance request updated successfully!";
                } else {
                    $error_message = "Error updating maintenance request.";
                }
            } catch (PDOException $e) {
                $error_message = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Filters
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$priority_filter = isset($_GET['priority']) ? $_GET['priority'] : '';

$where_conditions = [];
$params = [];

if ($search) {
    $where_conditions[] = "(hm.title LIKE :search OR hm.description LIKE :search OR hr.room_number LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status_filter) {
    $where_conditions[] = "hm.status = :status";
    $params[':status'] = $status_filter;
}

if ($priority_filter) {
    $where_conditions[] = "hm.priority = :priority";
    $params[':priority'] = $priority_filter;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Fetch maintenance requests
$query = "SELECT hm.*, hr.room_number, hb.name as block_name, ur.name as reporter_name, ua.name as assignee_name
          FROM hostel_maintenance hm
          JOIN hostel_rooms hr ON hm.room_id = hr.id
          JOIN hostel_blocks hb ON hr.block_id = hb.id
          JOIN users ur ON hm.reported_by = ur.id
          LEFT JOIN users ua ON hm.assigned_to = ua.id
          $where_clause
          ORDER BY hm.created_at DESC";
$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch technicians/staff for assignment
$staff_stmt = $db->query("SELECT id, name FROM users WHERE role IN ('hostel_warden', 'super_admin', 'school_admin') ORDER BY name");
$staff_list = $staff_stmt->fetchAll(PDO::FETCH_ASSOC);

// Statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
    COUNT(CASE WHEN status = 'in_progress' THEN 1 END) as in_progress,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved
    FROM hostel_maintenance";
$stats = $db->query($stats_query)->fetch(PDO::FETCH_ASSOC);

$title = "Hostel Maintenance Management";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Hostel', 'url' => '../index.php'],
    ['title' => 'Maintenance']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-8 flex-grow">
            <div class="max-w-7xl mx-auto">
            <!-- Header Section -->
            <div class="mb-8">
                <div class="bg-gradient-to-r from-orange-500 via-red-500 to-pink-600 rounded-2xl p-8 text-white shadow-xl">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Hostel Maintenance</h1>
                            <p class="text-orange-100 text-lg">Manage facility repairs, room inspections, and service requests</p>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-tools text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="flex justify-between items-center mb-6">
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800 flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Hostel
                    </a>
                    <a href="create.php" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg flex items-center">
                        <i class="fas fa-plus mr-2"></i>Report Repair Issue
                    </a>
                </div>
            </div>

            <?php if ($success_message): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
            <?php endif; ?>

            <?php if ($error_message): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
            <?php endif; ?>

            <!-- Stats grid -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Requests</p>
                            <p class="text-2xl font-bold text-gray-800"><?php echo number_format($stats['total']); ?></p>
                        </div>
                        <div class="p-3 bg-gray-100 rounded-full text-gray-600"><i class="fas fa-list-alt text-xl"></i></div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending</p>
                            <p class="text-2xl font-bold text-red-600"><?php echo number_format($stats['pending']); ?></p>
                        </div>
                        <div class="p-3 bg-red-100 rounded-full text-red-600"><i class="fas fa-clock text-xl"></i></div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">In Progress</p>
                            <p class="text-2xl font-bold text-blue-600"><?php echo number_format($stats['in_progress']); ?></p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-full text-blue-600"><i class="fas fa-spinner text-xl animate-spin"></i></div>
                    </div>
                </div>
                <div class="bg-white rounded-lg shadow p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Resolved</p>
                            <p class="text-2xl font-bold text-green-600"><?php echo number_format($stats['resolved']); ?></p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-full text-green-600"><i class="fas fa-check-circle text-xl"></i></div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow border border-gray-200 mb-8 p-4">
                <form method="GET" class="flex flex-wrap gap-4">
                    <div class="flex-grow min-w-[200px]">
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search description, room, title..."
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                    </div>
                    <div class="w-48">
                        <select name="status" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">All Statuses</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="in_progress" <?php echo $status_filter === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                            <option value="resolved" <?php echo $status_filter === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                            <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        </select>
                    </div>
                    <div class="w-48">
                        <select name="priority" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            <option value="">All Priorities</option>
                            <option value="low" <?php echo $priority_filter === 'low' ? 'selected' : ''; ?>>Low</option>
                            <option value="medium" <?php echo $priority_filter === 'medium' ? 'selected' : ''; ?>>Medium</option>
                            <option value="high" <?php echo $priority_filter === 'high' ? 'selected' : ''; ?>>High</option>
                        </select>
                    </div>
                    <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg transition">Filter</button>
                </form>
            </div>

            <!-- Requests Table -->
            <div class="bg-white rounded-lg shadow border border-gray-200 overflow-hidden">
                <?php if (empty($requests)): ?>
                <div class="text-center py-12">
                    <i class="fas fa-check-circle text-gray-400 text-5xl mb-3"></i>
                    <p class="text-gray-500 text-lg">No maintenance requests found.</p>
                </div>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Room / Block</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Issue Description</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Priority</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assigned To</th>
                                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($requests as $req): ?>
                            <tr class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-gray-900">Room <?php echo htmlspecialchars($req['room_number']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo htmlspecialchars($req['block_name']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($req['title']); ?></div>
                                    <div class="text-xs text-gray-500 max-w-sm truncate" title="<?php echo htmlspecialchars($req['description']); ?>">
                                        <?php echo htmlspecialchars($req['description']); ?>
                                    </div>
                                    <div class="text-[10px] text-gray-400 mt-1">Reported by: <?php echo htmlspecialchars($req['reporter_name']); ?> on <?php echo date('M j, Y', strtotime($req['created_at'])); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php 
                                        if ($req['priority'] === 'high') echo 'bg-red-100 text-red-800';
                                        elseif ($req['priority'] === 'medium') echo 'bg-orange-100 text-orange-800';
                                        else echo 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?php echo ucfirst($req['priority']); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                        <?php 
                                        if ($req['status'] === 'resolved') echo 'bg-green-100 text-green-800';
                                        elseif ($req['status'] === 'in_progress') echo 'bg-blue-100 text-blue-800';
                                        elseif ($req['status'] === 'cancelled') echo 'bg-red-100 text-red-800';
                                        else echo 'bg-gray-100 text-gray-800';
                                        ?>">
                                        <?php echo ucfirst(str_replace('_', ' ', $req['status'])); ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                    <?php echo $req['assignee_name'] ? htmlspecialchars($req['assignee_name']) : '<span class="text-gray-400 italic">Unassigned</span>'; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <!-- Inline update action form -->
                                    <form method="POST" class="inline-flex space-x-1 items-center">
                                        <input type="hidden" name="request_id" value="<?php echo $req['id']; ?>">
                                        <select name="status" required class="text-xs border rounded p-1">
                                            <option value="pending" <?php echo $req['status'] === 'pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="in_progress" <?php echo $req['status'] === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="resolved" <?php echo $req['status'] === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                                            <option value="cancelled" <?php echo $req['status'] === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                        <select name="assigned_to" class="text-xs border rounded p-1 w-24">
                                            <option value="">Assign To...</option>
                                            <?php foreach ($staff_list as $s): ?>
                                                <option value="<?php echo $s['id']; ?>" <?php echo $req['assigned_to'] == $s['id'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($s['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" name="update_request" class="bg-blue-500 hover:bg-blue-600 text-white text-[10px] px-2 py-1.5 rounded transition">
                                            Save
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
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