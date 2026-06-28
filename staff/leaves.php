<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$staff_roles = ['teacher','librarian','accountant','nurse','counselor','transport_officer','hostel_warden','canteen_manager','hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

// Setup leave_requests table if missing (if not created by other modules)
try {
    $db->exec("CREATE TABLE IF NOT EXISTS leave_requests (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        leave_type VARCHAR(50) NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        reason TEXT,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        action_by INT,
        action_date TIMESTAMP NULL,
        action_reason TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        FOREIGN KEY (action_by) REFERENCES users(id) ON DELETE SET NULL
    )");
} catch (PDOException $e) {}

// Handle Form Submission: Request/Action Leave
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'create') {
        $staff_id = filter_input(INPUT_POST, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
        $leave_type = filter_input(INPUT_POST, 'leave_type', FILTER_SANITIZE_STRING);
        $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
        $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);
        $reason = $_POST['reason'] ?? '';
        
        try {
            $stmt = $db->prepare("INSERT INTO leave_requests (user_id, leave_type, start_date, end_date, reason, status) VALUES (:uid, :type, :start, :end, :reason, 'pending')");
            $stmt->execute([':uid' => $staff_id, ':type' => $leave_type, ':start' => $start_date, ':end' => $end_date, ':reason' => $reason]);
            $success_msg = "Leave request submitted successfully.";
        } catch (PDOException $e) {
            $error_msg = "Error submitting request: " . $e->getMessage();
        }
    } elseif ($action === 'approve' || $action === 'reject') {
        $req_id = filter_input(INPUT_POST, 'request_id', FILTER_SANITIZE_NUMBER_INT);
        $action_reason = $_POST['action_reason'] ?? '';
        $new_status = $action === 'approve' ? 'approved' : 'rejected';
        
        try {
            $stmt = $db->prepare("UPDATE leave_requests SET status = :status, action_by = :aid, action_date = NOW(), action_reason = :areason WHERE id = :id");
            $stmt->execute([':status' => $new_status, ':aid' => $_SESSION['user_id'], ':areason' => $action_reason, ':id' => $req_id]);
            $success_msg = "Leave request $new_status.";
        } catch (PDOException $e) {
            $error_msg = "Error updating request: " . $e->getMessage();
        }
    }
}

// Fetch active staff for dropdown
$staff_list = $db->query("SELECT id, name FROM users WHERE role IN ($staff_roles_in) AND status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Filters
$status_filter = isset($_GET['status']) && in_array($_GET['status'], ['all', 'pending', 'approved', 'rejected']) ? $_GET['status'] : 'pending';
$status_where = $status_filter !== 'all' ? "AND lr.status = :status" : "";

// Fetch Leave Requests
$query = "
    SELECT lr.*, u.name as staff_name, tp.department, u.role
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE u.role IN ($staff_roles_in)
    $status_where
    ORDER BY lr.created_at DESC
";
$stmt = $db->prepare($query);
if ($status_filter !== 'all') $stmt->bindValue(':status', $status_filter);
$stmt->execute();
$leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN lr.status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN lr.status = 'approved' AND lr.start_date <= CURDATE() AND lr.end_date >= CURDATE() THEN 1 ELSE 0 END) as active_now
    FROM leave_requests lr
    JOIN users u ON lr.user_id = u.id
    WHERE u.role IN ($staff_roles_in)
")->fetch(PDO::FETCH_ASSOC);

$title = "Leave Management";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;" x-data="{ showModal: false, actionModal: false, selectedReq: null }">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Leave Management</h1>
                                <p class="text-blue-100 text-lg">Manage and approve staff leave requests</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-bed text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success_msg)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_msg); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error_msg)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_msg); ?>
                </div>
                <?php endif; ?>

                <!-- Stats Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-yellow-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Pending Requests</p>
                        <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $stats['pending'] ?? 0; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-green-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">On Leave Today</p>
                        <h3 class="text-3xl font-bold text-green-600 dark:text-green-400"><?php echo $stats['active_now'] ?? 0; ?></h3>
                    </div>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border-l-4 border-blue-500">
                        <p class="text-sm font-medium text-gray-500 mb-1">Total Requests (All Time)</p>
                        <h3 class="text-3xl font-bold text-blue-600 dark:text-blue-400"><?php echo $stats['total'] ?? 0; ?></h3>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-100 dark:border-gray-700 mb-8 p-6 flex flex-col sm:flex-row justify-between items-center gap-4">
                    <div class="flex flex-wrap items-center gap-2 no-stack">
                        <a href="?status=pending" class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $status_filter === 'pending' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300'; ?>">Pending</a>
                        <a href="?status=approved" class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $status_filter === 'approved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300'; ?>">Approved</a>
                        <a href="?status=rejected" class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $status_filter === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300'; ?>">Rejected</a>
                        <a href="?status=all" class="whitespace-nowrap px-4 py-2 rounded-lg text-sm font-medium transition-colors <?php echo $status_filter === 'all' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300'; ?>">All</a>
                    </div>
                    <button @click="showModal = true" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded-lg shadow font-medium transition-colors">
                        <i class="fas fa-plus mr-2"></i>New Request
                    </button>
                </div>

                <!-- Leaves Table -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-left whitespace-nowrap">
                            <thead class="bg-gray-50 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 text-sm uppercase">
                                <tr>
                                    <th class="px-6 py-4 font-semibold">Staff Member</th>
                                    <th class="px-6 py-4 font-semibold">Type & Dates</th>
                                    <th class="px-6 py-4 font-semibold">Duration</th>
                                    <th class="px-6 py-4 font-semibold">Status</th>
                                    <th class="px-6 py-4 font-semibold text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if(empty($leaves)): ?>
                                <tr><td colspan="5" class="px-6 py-8 text-center text-gray-500">No leave requests found.</td></tr>
                                <?php else: foreach($leaves as $l): 
                                    $days = (strtotime($l['end_date']) - strtotime($l['start_date'])) / (60 * 60 * 24) + 1;
                                ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition">
                                    <td class="px-6 py-4">
                                        <div class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($l['staff_name']); ?></div>
                                        <div class="text-xs text-gray-500"><?php echo htmlspecialchars($l['department'] ?? formatRoleName($l['role'])); ?></div>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="font-semibold text-gray-800 dark:text-gray-200 mb-1"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $l['leave_type']))); ?></div>
                                        <div class="text-sm text-gray-600 dark:text-gray-400">
                                            <?php echo date('M d, Y', strtotime($l['start_date'])); ?> - <?php echo date('M d, Y', strtotime($l['end_date'])); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 font-medium text-gray-800 dark:text-gray-200"><?php echo $days; ?> day(s)</td>
                                    <td class="px-6 py-4">
                                        <?php if($l['status'] === 'approved'): ?>
                                            <span class="bg-green-100 text-green-800 px-2.5 py-1 rounded-full text-xs font-bold">Approved</span>
                                        <?php elseif($l['status'] === 'rejected'): ?>
                                            <span class="bg-red-100 text-red-800 px-2.5 py-1 rounded-full text-xs font-bold">Rejected</span>
                                        <?php else: ?>
                                            <span class="bg-yellow-100 text-yellow-800 px-2.5 py-1 rounded-full text-xs font-bold">Pending</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 text-right">
                                        <?php if($l['status'] === 'pending'): ?>
                                        <button @click="selectedReq = <?php echo htmlspecialchars(json_encode($l)); ?>; actionModal = true" class="text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3 py-1.5 rounded-lg transition-colors text-sm font-medium">
                                            Review
                                        </button>
                                        <?php else: ?>
                                        <button @click="alert('Reason: <?php echo addslashes($l['reason']); ?>\n\nAction Note: <?php echo addslashes($l['action_reason'] ?? ''); ?>')" class="text-gray-600 hover:text-gray-800 bg-gray-100 hover:bg-gray-200 px-3 py-1.5 rounded-lg transition-colors text-sm font-medium">
                                            View Details
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Create Request Modal -->
                <div x-show="showModal" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div x-show="showModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="showModal = false"></div>
                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div x-show="showModal" class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                            <form action="" method="POST">
                                <input type="hidden" name="action" value="create">
                                <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                    <h3 class="text-lg leading-6 font-bold text-gray-900 dark:text-white mb-4 border-b pb-2">New Leave Request</h3>
                                    <div class="space-y-4">
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Staff Member *</label>
                                            <select name="staff_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                                <option value="">-- Select --</option>
                                                <?php foreach($staff_list as $st): ?>
                                                    <option value="<?php echo $st['id']; ?>"><?php echo htmlspecialchars($st['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Leave Type *</label>
                                            <select name="leave_type" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                                <option value="sick_leave">Sick Leave</option>
                                                <option value="casual_leave">Casual Leave</option>
                                                <option value="annual_leave">Annual Leave</option>
                                                <option value="maternity_leave">Maternity/Paternity Leave</option>
                                                <option value="unpaid_leave">Unpaid Leave</option>
                                            </select>
                                        </div>
                                        <div class="grid grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Start Date *</label>
                                                <input type="date" name="start_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">End Date *</label>
                                                <input type="date" name="end_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                        </div>
                                        <div>
                                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Reason *</label>
                                            <textarea name="reason" required rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white"></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800/80 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-200 dark:border-gray-700">
                                    <button type="submit" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">Submit Request</button>
                                    <button type="button" @click="showModal = false" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-700 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Review Action Modal -->
                <div x-show="actionModal" style="display: none;" class="fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
                    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                        <div x-show="actionModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="actionModal = false"></div>
                        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
                        <div x-show="actionModal" class="inline-block align-bottom bg-white dark:bg-gray-800 rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg w-full">
                            <form action="" method="POST">
                                <input type="hidden" name="request_id" :value="selectedReq?.id">
                                <div class="bg-white dark:bg-gray-800 px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                                    <h3 class="text-lg leading-6 font-bold text-gray-900 dark:text-white mb-4 border-b pb-2">Review Leave Request</h3>
                                    <div class="bg-gray-50 dark:bg-gray-700/50 p-4 rounded-lg mb-4 text-sm text-gray-700 dark:text-gray-300">
                                        <p><strong>Staff:</strong> <span x-text="selectedReq?.staff_name"></span></p>
                                        <p><strong>Type:</strong> <span x-text="selectedReq?.leave_type"></span></p>
                                        <p><strong>Dates:</strong> <span x-text="selectedReq?.start_date + ' to ' + selectedReq?.end_date"></span></p>
                                        <p><strong>Reason:</strong> <span x-text="selectedReq?.reason"></span></p>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Action Note (Optional)</label>
                                        <textarea name="action_reason" rows="2" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Reason for approval/rejection..."></textarea>
                                    </div>
                                </div>
                                <div class="bg-gray-50 dark:bg-gray-800/80 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse border-t border-gray-200 dark:border-gray-700">
                                    <button type="submit" name="action" value="approve" class="w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-green-600 text-base font-medium text-white hover:bg-green-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">Approve</button>
                                    <button type="submit" name="action" value="reject" class="mt-3 sm:mt-0 w-full inline-flex justify-center rounded-xl border border-transparent shadow-sm px-4 py-2 bg-red-600 text-base font-medium text-white hover:bg-red-700 focus:outline-none sm:ml-3 sm:w-auto sm:text-sm">Reject</button>
                                    <button type="button" @click="actionModal = false" class="mt-3 w-full inline-flex justify-center rounded-xl border border-gray-300 dark:border-gray-600 shadow-sm px-4 py-2 bg-white dark:bg-gray-700 text-base font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 focus:outline-none sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

            </div>
        </main>
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
