<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('staff');

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check for session flash messages
if (isset($_SESSION['success'])) {
    $success = $_SESSION['success'];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $error = $_SESSION['error'];
    unset($_SESSION['error']);
}

// Staff role list
$staff_roles = ['teacher','librarian','accountant','nurse','counselor','transport_officer','hostel_warden','canteen_manager','hr'];
$staff_roles_in = "'" . implode("','", $staff_roles) . "'";

// ── Handle staff status toggle ─────────────────────────────────────────
if (isset($_POST['update_status']) && isset($_POST['staff_id'])) {
    $staff_id   = filter_input(INPUT_POST, 'staff_id', FILTER_SANITIZE_NUMBER_INT);
    $new_status = ($_POST['new_status'] === 'active') ? 'active' : 'inactive';

    try {
        $stmt = $db->prepare("UPDATE users SET status = :status WHERE id = :id AND role IN ($staff_roles_in)");
        $stmt->bindParam(':status', $new_status);
        $stmt->bindParam(':id', $staff_id);
        $stmt->execute();
        $success = "Staff status updated successfully.";
    } catch (PDOException $e) {
        $error = "Error updating staff status.";
    }
}

// ── CSV Export ──────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $csv_query = "SELECT u.name, u.email, u.role, u.status,
                  tp.employee_id, tp.phone, tp.qualification, tp.department,
                  tp.experience_years, tp.joining_date, tp.salary, tp.gender
                  FROM users u
                  LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
                  WHERE u.role IN ($staff_roles_in)
                  ORDER BY u.name";
    $csv_stmt = $db->query($csv_query);
    $csv_data = $csv_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=staff_directory_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    if (!empty($csv_data)) {
        fputcsv($output, array_keys($csv_data[0]));
        foreach ($csv_data as $row) {
            fputcsv($output, $row);
        }
    }
    fclose($output);
    exit();
}

// ── Stats Cards ────────────────────────────────────────────────────────
$total_staff = $db->query("SELECT COUNT(*) FROM users WHERE role IN ($staff_roles_in)")->fetchColumn();
$active_staff = $db->query("SELECT COUNT(*) FROM users WHERE role IN ($staff_roles_in) AND status = 'active'")->fetchColumn();

// On-leave count: users with an active leave request covering today
$on_leave_query = "SELECT COUNT(DISTINCT lr.user_id) FROM leave_requests lr
                   INNER JOIN users u ON lr.user_id = u.id
                   WHERE u.role IN ($staff_roles_in)
                   AND lr.status = 'approved'
                   AND CURDATE() BETWEEN lr.start_date AND lr.end_date";
try {
    $on_leave = $db->query($on_leave_query)->fetchColumn();
} catch (PDOException $e) {
    $on_leave = 0;
}

try {
    $total_departments = $db->query("SELECT COUNT(*) FROM staff_departments WHERE status = 'active'")->fetchColumn();
} catch (PDOException $e) {
    $total_departments = 0;
}

// ── Filters ────────────────────────────────────────────────────────────
$search        = isset($_GET['search'])     ? trim($_GET['search'])     : '';
$dept_filter   = isset($_GET['department']) ? $_GET['department']       : '';
$role_filter   = isset($_GET['role'])       ? $_GET['role']             : '';
$status_filter = isset($_GET['status'])     ? $_GET['status']           : '';

$where = ["u.role IN ($staff_roles_in)"];
$params = [];

if ($search !== '') {
    $where[]           = "(u.name LIKE :search OR u.email LIKE :search OR tp.employee_id LIKE :search)";
    $params[':search'] = "%$search%";
}
if ($dept_filter !== '') {
    $where[]               = "tp.department = :department";
    $params[':department']  = $dept_filter;
}
if ($role_filter !== '') {
    $where[]          = "u.role = :role";
    $params[':role']  = $role_filter;
}
if ($status_filter !== '') {
    $where[]            = "u.status = :status";
    $params[':status']  = $status_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// ── Pagination ─────────────────────────────────────────────────────────
$page   = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$limit  = 12;
$offset = ($page - 1) * $limit;

$count_stmt = $db->prepare("SELECT COUNT(DISTINCT u.id) FROM users u LEFT JOIN teacher_profiles tp ON u.id = tp.user_id $where_clause");
foreach ($params as $k => $v) { $count_stmt->bindValue($k, $v); }
$count_stmt->execute();
$total_records = $count_stmt->fetchColumn();
$total_pages   = max(1, ceil($total_records / $limit));

// ── Fetch Staff ────────────────────────────────────────────────────────
$query = "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at, u.profile_picture,
          tp.employee_id, tp.phone, tp.qualification, tp.department,
          tp.experience_years, tp.joining_date, tp.gender
          FROM users u
          LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
          $where_clause
          ORDER BY u.name
          LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
$stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
$stmt->execute();
$staff_members = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Departments for filter dropdown ────────────────────────────────────
try {
    $departments = $db->query("SELECT id, name FROM staff_departments WHERE status = 'active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $departments = [];
}

// ── Role display labels ────────────────────────────────────────────────
$role_labels = [
    'teacher'           => 'Teacher',
    'librarian'         => 'Librarian',
    'accountant'        => 'Accountant',
    'nurse'             => 'Nurse',
    'counselor'         => 'Counselor',
    'transport_officer' => 'Transport Officer',
    'hostel_warden'     => 'Hostel Warden',
    'canteen_manager'   => 'Canteen Manager',
    'hr'                => 'Human Resource',
];

$role_colors = [
    'teacher'           => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300',
    'librarian'         => 'bg-purple-100 text-purple-800 dark:bg-purple-950/40 dark:text-purple-300',
    'accountant'        => 'bg-green-100 text-green-800 dark:bg-green-950/40 dark:text-green-300',
    'nurse'             => 'bg-pink-100 text-pink-800 dark:bg-pink-950/40 dark:text-pink-300',
    'counselor'         => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-950/40 dark:text-yellow-300',
    'transport_officer' => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300',
    'hostel_warden'     => 'bg-indigo-100 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-300',
    'canteen_manager'   => 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300',
    'hr'                => 'bg-teal-100 text-teal-800 dark:bg-teal-950/40 dark:text-teal-300',
];

// Build query-string helper for pagination links
function buildQs($page_num) {
    $qs = $_GET;
    $qs['page'] = $page_num;
    return '?' . http_build_query($qs);
}

$title = "Staff Directory";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">

                <!-- ═══════════════ Page Header ═══════════════ -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Staff Directory</h1>
                                <p class="text-blue-100 text-lg">Manage all staff members in one place</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-briefcase mr-2"></i>
                                        Staff records &amp; profiles
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-briefcase text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════ Stats Cards ═══════════════ -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Staff -->
                    <div class="relative overflow-hidden bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 group hover:shadow-xl transition-shadow duration-300">
                        <div class="absolute -top-6 -right-6 w-24 h-24 bg-gradient-to-br from-blue-400 to-blue-600 rounded-full opacity-20 group-hover:opacity-30 transition-opacity"></div>
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Total Staff</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $total_staff; ?></h3>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-users text-white text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <!-- Active Staff -->
                    <div class="relative overflow-hidden bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 group hover:shadow-xl transition-shadow duration-300">
                        <div class="absolute -top-6 -right-6 w-24 h-24 bg-gradient-to-br from-green-400 to-green-600 rounded-full opacity-20 group-hover:opacity-30 transition-opacity"></div>
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Active Staff</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $active_staff; ?></h3>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-green-500 to-green-600 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-user-check text-white text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <!-- On Leave -->
                    <div class="relative overflow-hidden bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 group hover:shadow-xl transition-shadow duration-300">
                        <div class="absolute -top-6 -right-6 w-24 h-24 bg-gradient-to-br from-yellow-400 to-yellow-600 rounded-full opacity-20 group-hover:opacity-30 transition-opacity"></div>
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">On Leave</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $on_leave; ?></h3>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-yellow-500 to-yellow-650 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-calendar-times text-white text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <!-- Departments -->
                    <div class="relative overflow-hidden bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 group hover:shadow-xl transition-shadow duration-300">
                        <div class="absolute -top-6 -right-6 w-24 h-24 bg-gradient-to-br from-purple-400 to-purple-600 rounded-full opacity-20 group-hover:opacity-30 transition-opacity"></div>
                        <div class="flex items-center justify-between relative z-10">
                            <div>
                                <p class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-1">Departments</p>
                                <h3 class="text-3xl font-bold text-gray-800 dark:text-white"><?php echo $total_departments; ?></h3>
                            </div>
                            <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
                                <i class="fas fa-building text-white text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ═══════════════ Action Buttons ═══════════════ -->
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6" x-data="{ showFilters: <?php echo ($search || $dept_filter || $role_filter || $status_filter) ? 'true' : 'false'; ?> }">
                    <div class="flex flex-wrap gap-3">
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hr'])): ?>
                        <a href="create.php" class="inline-flex items-center bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-5 py-2.5 rounded-xl font-medium shadow-lg shadow-blue-500/25 transition-all duration-200">
                            <i class="fas fa-user-plus mr-2"></i>Add Staff
                        </a>
                        <a href="bulk_import.php" class="inline-flex items-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 px-5 py-2.5 rounded-xl font-medium transition-all duration-200">
                            <i class="fas fa-file-import mr-2 text-blue-500"></i>Bulk Import
                        </a>
                        <?php endif; ?>
                        <a href="?export=csv" class="inline-flex items-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 px-5 py-2.5 rounded-xl font-medium transition-all duration-200">
                            <i class="fas fa-file-csv mr-2 text-emerald-500"></i>Export CSV
                        </a>
                        <button @click="showFilters = !showFilters" class="inline-flex items-center bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 px-5 py-2.5 rounded-xl font-medium transition-all duration-200">
                            <i class="fas fa-filter mr-2 text-blue-500"></i>
                            <span x-text="showFilters ? 'Hide Filters' : 'Filters'"></span>
                        </button>
                    </div>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Showing <span class="font-semibold text-gray-800 dark:text-white"><?php echo count($staff_members); ?></span> of
                        <span class="font-semibold text-gray-800 dark:text-white"><?php echo $total_records; ?></span> staff
                    </p>

                <!-- ═══════════════ Search & Filter Bar ═══════════════ -->
                <div x-show="showFilters" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="w-full mt-4">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                            <!-- Text Search -->
                            <div class="lg:col-span-2">
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Search</label>
                                <div class="relative">
                                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>"
                                        placeholder="Name, email or employee ID…"
                                        class="w-full pl-10 pr-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white placeholder-gray-400 transition">
                                </div>
                            </div>
                            <!-- Department -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Department</label>
                                <select name="department" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                                    <option value="">All Departments</option>
                                    <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo $dept_filter === $dept['name'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($dept['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Role -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Role</label>
                                <select name="role" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                                    <option value="">All Roles</option>
                                    <?php foreach ($role_labels as $rv => $rl): ?>
                                    <option value="<?php echo $rv; ?>" <?php echo $role_filter === $rv ? 'selected' : ''; ?>>
                                        <?php echo $rl; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <!-- Status -->
                            <div>
                                <label class="block text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1.5">Status</label>
                                <select name="status" class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-xl focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white transition">
                                    <option value="">All Status</option>
                                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active</option>
                                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                                </select>
                            </div>
                            <!-- Buttons -->
                            <div class="lg:col-span-5 flex gap-3 pt-2">
                                <button type="submit" class="inline-flex items-center bg-blue-600 hover:bg-blue-700 text-white px-6 py-2.5 rounded-xl font-medium transition-colors shadow">
                                    <i class="fas fa-search mr-2"></i>Apply Filters
                                </button>
                                <a href="index.php" class="inline-flex items-center bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-6 py-2.5 rounded-xl font-medium transition-colors">
                                    <i class="fas fa-undo mr-2"></i>Reset
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                </div><!-- /action-buttons wrapper -->

                <!-- ═══════════════ Flash Messages ═══════════════ -->
                <?php if (isset($success)): ?>
                <div class="bg-green-100 dark:bg-green-900/30 border border-green-400 dark:border-green-600 text-green-700 dark:text-green-300 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                </div>
                <?php endif; ?>
                <?php if (isset($error)): ?>
                <div class="bg-red-100 dark:bg-red-900/30 border border-red-400 dark:border-red-600 text-red-700 dark:text-red-300 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <!-- ═══════════════ Staff Grid ═══════════════ -->
                <?php if (!empty($staff_members)): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($staff_members as $member):
                        $initials = '';
                        $name_parts = explode(' ', $member['name']);
                        foreach ($name_parts as $np) { if ($np) $initials .= strtoupper($np[0]); }
                        $initials = substr($initials, 0, 2);

                        $role_badge  = $role_colors[$member['role']] ?? 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300';
                        $role_label  = $role_labels[$member['role']] ?? formatRoleName($member['role']);
                        $is_active   = ($member['status'] === 'active');

                        // Random but deterministic avatar gradient based on user id
                        $avatar_gradients = [
                            'from-blue-500 to-blue-650',
                            'from-green-500 to-green-650',
                            'from-purple-500 to-purple-650',
                            'from-red-500 to-red-650',
                            'from-yellow-500 to-yellow-600',
                            'from-indigo-500 to-indigo-650',
                            'from-blue-400 to-blue-500',
                            'from-pink-500 to-pink-650',
                        ];
                        $avatar_gradient = $avatar_gradients[$member['id'] % count($avatar_gradients)];
                    ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden hover:shadow-xl transition-all duration-300 group border border-gray-100 dark:border-gray-700">
                        <!-- Card Top Accent -->
                        <div class="h-1.5 bg-gradient-to-r <?php echo $avatar_gradient; ?>"></div>

                        <div class="p-6">
                            <!-- Avatar + Name + Status -->
                            <div class="flex items-start justify-between mb-4">
                                <div class="flex items-center space-x-3">
                                    <div class="w-12 h-12 bg-gradient-to-br <?php echo $avatar_gradient; ?> rounded-full flex items-center justify-center shadow-md flex-shrink-0 overflow-hidden">
                                        <?php if(!empty($member['profile_picture'])): ?>
                                            <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($member['profile_picture']); ?>" alt="Profile" class="w-full h-full object-cover">
                                        <?php else: ?>
                                            <span class="text-white font-bold text-sm"><?php echo $initials; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="min-w-0">
                                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white truncate"><?php echo htmlspecialchars($member['name']); ?></h3>
                                        <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($member['employee_id'] ?? 'No ID'); ?></p>
                                    </div>
                                </div>
                                <span class="px-3 py-1 rounded-full text-xs font-semibold flex-shrink-0 <?php echo $is_active ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300' : 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300'; ?>">
                                    <?php echo ucfirst($member['status']); ?>
                                </span>
                            </div>

                            <!-- Role Badge -->
                            <div class="mb-4">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $role_badge; ?>">
                                    <i class="fas fa-id-badge mr-1"></i><?php echo $role_label; ?>
                                </span>
                            </div>

                            <!-- Details -->
                            <div class="space-y-2.5 text-sm text-gray-600 dark:text-gray-400">
                                <?php if (!empty($member['department'])): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-building w-5 text-gray-400 dark:text-gray-500 mr-2"></i>
                                    <span class="truncate"><?php echo htmlspecialchars($member['department']); ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="flex items-center">
                                    <i class="fas fa-envelope w-5 text-gray-400 dark:text-gray-500 mr-2"></i>
                                    <span class="truncate"><?php echo htmlspecialchars($member['email']); ?></span>
                                </div>
                                <?php if (!empty($member['phone'])): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-phone w-5 text-gray-400 dark:text-gray-500 mr-2"></i>
                                    <span><?php echo htmlspecialchars($member['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($member['qualification'])): ?>
                                <div class="flex items-center">
                                    <i class="fas fa-graduation-cap w-5 text-gray-400 dark:text-gray-500 mr-2"></i>
                                    <span class="truncate"><?php echo htmlspecialchars($member['qualification']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>

                            <!-- Divider -->
                            <div class="border-t border-gray-200 dark:border-gray-700 my-4"></div>

                            <!-- Action Buttons -->
                            <div class="flex items-center justify-between">
                                <div class="flex space-x-2">
                                    <a href="view.php?id=<?php echo $member['id']; ?>" class="inline-flex items-center px-3 py-1.5 bg-blue-50 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 rounded-lg hover:bg-blue-100 dark:hover:bg-blue-900/50 transition-colors text-sm font-medium" title="View Profile">
                                        <i class="fas fa-eye mr-1.5"></i>View
                                    </a>
                                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hr'])): ?>
                                    <a href="edit.php?id=<?php echo $member['id']; ?>" class="inline-flex items-center px-3 py-1.5 bg-amber-50 dark:bg-amber-900/30 text-amber-600 dark:text-amber-400 rounded-lg hover:bg-amber-100 dark:hover:bg-amber-900/50 transition-colors text-sm font-medium" title="Edit Staff">
                                        <i class="fas fa-edit mr-1.5"></i>Edit
                                    </a>
                                    <?php endif; ?>
                                </div>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hr'])): ?>
                                <form action="" method="POST" class="inline">
                                    <input type="hidden" name="staff_id" value="<?php echo $member['id']; ?>">
                                    <input type="hidden" name="new_status" value="<?php echo $is_active ? 'inactive' : 'active'; ?>">
                                    <button type="submit" name="update_status"
                                        class="inline-flex items-center px-3 py-1.5 rounded-lg text-sm font-medium transition-colors
                                        <?php echo $is_active
                                            ? 'bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50'
                                            : 'bg-green-50 dark:bg-green-900/30 text-green-600 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/50'; ?>"
                                        title="<?php echo $is_active ? 'Deactivate' : 'Activate'; ?>"
                                        onclick="return confirm('Are you sure you want to <?php echo $is_active ? 'deactivate' : 'activate'; ?> this staff member?')">
                                        <i class="fas fa-<?php echo $is_active ? 'ban' : 'check-circle'; ?> mr-1.5"></i>
                                        <?php echo $is_active ? 'Deactivate' : 'Activate'; ?>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- ═══════════════ Pagination ═══════════════ -->
                <?php if ($total_pages > 1): ?>
                <div class="mt-8 flex justify-center">
                    <nav class="inline-flex rounded-xl shadow-sm bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 overflow-hidden" aria-label="Pagination">
                        <!-- Previous -->
                        <a href="<?php echo $page > 1 ? buildQs($page - 1) : '#'; ?>"
                           class="relative inline-flex items-center px-4 py-2.5 text-sm font-medium border-r border-gray-200 dark:border-gray-700
                           <?php echo $page <= 1 ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'; ?>">
                            <i class="fas fa-chevron-left text-xs mr-1"></i>Prev
                        </a>
                        <?php
                        // Show smart page range
                        $range = 2;
                        $start_page = max(1, $page - $range);
                        $end_page   = min($total_pages, $page + $range);

                        if ($start_page > 1): ?>
                            <a href="<?php echo buildQs(1); ?>" class="relative inline-flex items-center px-4 py-2.5 border-r border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">1</a>
                            <?php if ($start_page > 2): ?>
                            <span class="relative inline-flex items-center px-3 py-2.5 border-r border-gray-200 dark:border-gray-700 text-sm text-gray-400">…</span>
                            <?php endif;
                        endif;

                        for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <a href="<?php echo buildQs($i); ?>"
                           class="relative inline-flex items-center px-4 py-2.5 border-r border-gray-200 dark:border-gray-700 text-sm font-medium transition-colors
                           <?php echo $i === $page
                               ? 'bg-blue-600 text-white'
                               : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'; ?>">
                            <?php echo $i; ?>
                        </a>
                        <?php endfor;

                        if ($end_page < $total_pages):
                            if ($end_page < $total_pages - 1): ?>
                            <span class="relative inline-flex items-center px-3 py-2.5 border-r border-gray-200 dark:border-gray-700 text-sm text-gray-400">…</span>
                            <?php endif; ?>
                            <a href="<?php echo buildQs($total_pages); ?>" class="relative inline-flex items-center px-4 py-2.5 border-r border-gray-200 dark:border-gray-700 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700"><?php echo $total_pages; ?></a>
                        <?php endif; ?>
                        <!-- Next -->
                        <a href="<?php echo $page < $total_pages ? buildQs($page + 1) : '#'; ?>"
                           class="relative inline-flex items-center px-4 py-2.5 text-sm font-medium
                           <?php echo $page >= $total_pages ? 'text-gray-300 dark:text-gray-600 cursor-not-allowed' : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700'; ?>">
                            Next<i class="fas fa-chevron-right text-xs ml-1"></i>
                        </a>
                    </nav>
                </div>
                <?php endif; ?>

                <?php else: ?>
                <!-- ═══════════════ Empty State ═══════════════ -->
                <div class="text-center py-20">
                    <div class="inline-flex items-center justify-center w-24 h-24 bg-gray-100 dark:bg-gray-800 rounded-full mb-6">
                        <i class="fas fa-user-tie text-gray-400 dark:text-gray-500 text-5xl"></i>
                    </div>
                    <h3 class="text-xl font-semibold text-gray-800 dark:text-white mb-2">No staff members found</h3>
                    <p class="text-gray-500 dark:text-gray-400 mb-6 max-w-md mx-auto">
                        <?php if ($search || $dept_filter || $role_filter || $status_filter): ?>
                            No staff match your current filters. Try adjusting your search criteria.
                        <?php else: ?>
                            Get started by adding your first staff member to the system.
                        <?php endif; ?>
                    </p>
                    <div class="flex justify-center gap-3">
                        <?php if ($search || $dept_filter || $role_filter || $status_filter): ?>
                        <a href="index.php" class="inline-flex items-center bg-gray-200 dark:bg-gray-700 hover:bg-gray-300 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 px-5 py-2.5 rounded-xl font-medium transition-colors">
                            <i class="fas fa-undo mr-2"></i>Clear Filters
                        </a>
                        <?php endif; ?>
                        <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hr'])): ?>
                        <a href="create.php" class="inline-flex items-center bg-gradient-to-r from-blue-600 to-blue-700 hover:from-blue-700 hover:to-blue-800 text-white px-6 py-2.5 rounded-xl font-medium shadow-lg shadow-blue-500/25 transition-all duration-200">
                            <i class="fas fa-user-plus mr-2"></i>Add Staff
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
