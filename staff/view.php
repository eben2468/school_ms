<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$staff_id) {
    header("Location: index.php");
    exit();
}

// Fetch staff details
$stmt = $db->prepare("
    SELECT u.id as user_id, u.name, u.first_name, u.other_names, u.last_name, u.email, u.role, u.status, u.created_at, u.profile_picture,
           tp.employee_id, tp.qualification, tp.experience_years, tp.joining_date, 
           tp.salary, tp.department, tp.phone, tp.date_of_birth, tp.gender, tp.address
    FROM users u
    LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
    WHERE u.id = :id
");
$stmt->execute(['id' => $staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header("Location: index.php");
    exit();
}

$first_name = !empty($staff['first_name']) ? $staff['first_name'] : '';
$other_names = !empty($staff['other_names']) ? $staff['other_names'] : '';
$last_name = !empty($staff['last_name']) ? $staff['last_name'] : '';

if (empty($first_name) && empty($last_name) && !empty($staff['name'])) {
    $fullName = trim($staff['name']);
    $parts = preg_split('/\s+/', $fullName);
    $num_parts = count($parts);
    if ($num_parts === 1) {
        $first_name = $parts[0];
    } elseif ($num_parts === 2) {
        $first_name = $parts[0];
        $last_name = $parts[1];
    } else {
        $first_name = $parts[0];
        $last_name = $parts[$num_parts - 1];
        $other_names = implode(' ', array_slice($parts, 1, $num_parts - 2));
    }
}

// Fetch Attendance
$att_stmt = $db->prepare("SELECT * FROM staff_attendance WHERE staff_id = :id AND date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) ORDER BY date DESC");
$att_stmt->execute(['id' => $staff_id]);
$attendance_records = $att_stmt->fetchAll(PDO::FETCH_ASSOC);

$att_summary = ['present' => 0, 'absent' => 0, 'late' => 0, 'half_day' => 0, 'on_leave' => 0];
foreach($attendance_records as $ar) {
    $status = strtolower($ar['status']);
    if (isset($att_summary[$status])) {
        $att_summary[$status]++;
    }
}

// Fetch Evaluations
$eval_stmt = $db->prepare("SELECT * FROM staff_evaluations WHERE staff_id = :id ORDER BY evaluated_at DESC");
$eval_stmt->execute(['id' => $staff_id]);
$evaluations = $eval_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Qualifications
$qual_stmt = $db->prepare("SELECT * FROM staff_qualifications WHERE staff_id = :id ORDER BY date_obtained DESC");
$qual_stmt->execute(['id' => $staff_id]);
$qualifications = $qual_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Schedule
$sched_stmt = $db->prepare("SELECT * FROM staff_schedules WHERE staff_id = :id ORDER BY day_of_week, shift_start");
$sched_stmt->execute(['id' => $staff_id]);
$schedules = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);

$timetable = [];
if ($staff['role'] === 'teacher') {
    $tt_stmt = $db->prepare("SELECT * FROM timetable WHERE teacher_id = :id ORDER BY day_of_week, start_time");
    $tt_stmt->execute(['id' => $staff_id]);
    $timetable = $tt_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch Leaves
$leave_stmt = $db->prepare("SELECT * FROM leave_requests WHERE user_id = :id ORDER BY start_date DESC");
$leave_stmt->execute(['id' => $staff_id]);
$leaves = $leave_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Staff Profile: " . htmlspecialchars($staff['name']);
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
            <div class="w-full" x-data="{ activeTab: 'personal' }">
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Staff Profile</h1>
                                <p class="text-blue-100 text-lg">Detailed view and performance metrics</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-id-card-clip text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile Header Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8">
                    <div class="flex flex-col md:flex-row items-start md:items-center justify-between gap-6">
                        <div class="flex items-center gap-6">
                            <div class="w-24 h-24 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center text-blue-600 dark:text-blue-300 text-3xl font-bold shadow-inner shrink-0 overflow-hidden">
                                <?php if(!empty($staff['profile_picture'])): ?>
                                    <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($staff['profile_picture']); ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <?php 
                                        $names = explode(' ', $staff['name']);
                                        echo strtoupper(substr($names[0], 0, 1) . (isset($names[1]) ? substr($names[1], 0, 1) : ''));
                                    ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-1"><?= htmlspecialchars($staff['name']) ?></h2>
                                <p class="text-gray-500 dark:text-gray-400 mb-2">Emp ID: <?= htmlspecialchars($staff['employee_id'] ?? 'N/A') ?></p>
                                <div class="flex flex-wrap gap-2">
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                        <?= htmlspecialchars(formatRoleName($staff['role'])) ?>
                                    </span>
                                    <span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                        <?= htmlspecialchars($staff['department'] ?? 'No Dept') ?>
                                    </span>
                                    <?php if($staff['status'] === 'active'): ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">Active</span>
                                    <?php else: ?>
                                        <span class="px-3 py-1 rounded-full text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">Inactive</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex flex-col sm:flex-row gap-3 w-full md:w-auto">
                            <a href="edit.php?id=<?= $staff_id ?>" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg flex items-center justify-center gap-2 transition-colors">
                                <i class="fas fa-edit"></i> Edit Profile
                            </a>
                            <a href="index.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 px-4 py-2 rounded-lg flex items-center justify-center gap-2 transition-colors">
                                <i class="fas fa-arrow-left"></i> Back
                            </a>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6 pt-6 border-t border-gray-100 dark:border-gray-700">
                        <div class="flex items-center gap-3 text-gray-600 dark:text-gray-300">
                            <i class="fas fa-envelope text-gray-400 w-5"></i>
                            <a href="mailto:<?= htmlspecialchars($staff['email']) ?>" class="hover:text-blue-600 truncate"><?= htmlspecialchars($staff['email']) ?></a>
                        </div>
                        <div class="flex items-center gap-3 text-gray-600 dark:text-gray-300">
                            <i class="fas fa-phone text-gray-400 w-5"></i>
                            <a href="tel:<?= htmlspecialchars($staff['phone'] ?? '') ?>" class="hover:text-blue-600"><?= htmlspecialchars($staff['phone'] ?? 'N/A') ?></a>
                        </div>
                    </div>
                </div>

                <!-- Tabs Navigation -->
                <div class="flex overflow-x-auto hide-scrollbar mb-6 bg-white dark:bg-gray-800 rounded-xl shadow-sm p-2 gap-2">
                    <button @click="activeTab = 'personal'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'personal', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'personal'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-user"></i> Personal Info
                    </button>
                    <button @click="activeTab = 'employment'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'employment', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'employment'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-briefcase"></i> Employment
                    </button>
                    <button @click="activeTab = 'attendance'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'attendance', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'attendance'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-calendar-check"></i> Attendance
                    </button>
                    <button @click="activeTab = 'performance'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'performance', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'performance'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-chart-line"></i> Performance
                    </button>
                    <button @click="activeTab = 'qualifications'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'qualifications', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'qualifications'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-graduation-cap"></i> Qualifications
                    </button>
                    <button @click="activeTab = 'schedule'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'schedule', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'schedule'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-clock"></i> Schedule
                    </button>
                    <button @click="activeTab = 'leaves'" :class="{'bg-blue-50 text-blue-600 dark:bg-blue-900/50 dark:text-blue-300': activeTab === 'leaves', 'text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700': activeTab !== 'leaves'}" class="px-4 py-2.5 rounded-lg font-medium text-sm whitespace-nowrap transition-colors flex items-center gap-2">
                        <i class="fas fa-plane-departure"></i> Leaves
                    </button>
                </div>

                <!-- Tab Content: Personal Information -->
                <div x-show="activeTab === 'personal'" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 border-b border-gray-100 dark:border-gray-700 pb-3">Personal Information</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-6 gap-x-8">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">First Name</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($first_name) ?></p>
                        </div>
                        <?php if (!empty($other_names)): ?>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Other Name(s)</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($other_names) ?></p>
                        </div>
                        <?php endif; ?>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Last Name</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($last_name) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Full Name</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['name']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Email Address</p>
                            <p class="font-medium text-gray-900 dark:text-white truncate" title="<?= htmlspecialchars($staff['email']) ?>"><?= htmlspecialchars($staff['email']) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Phone Number</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['phone'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Date of Birth</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= !empty($staff['date_of_birth']) ? date('M d, Y', strtotime($staff['date_of_birth'])) : 'N/A' ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Gender</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars(ucfirst($staff['gender'] ?? 'N/A')) ?></p>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Address</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['address'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Employment Details -->
                <div x-show="activeTab === 'employment'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-6 border-b border-gray-100 dark:border-gray-700 pb-3">Employment Details</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-y-6 gap-x-8">
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Employee ID</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['employee_id'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Role</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars(formatRoleName($staff['role'])) ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Department</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['department'] ?? 'N/A') ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Joining Date</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= !empty($staff['joining_date']) ? date('M d, Y', strtotime($staff['joining_date'])) : 'N/A' ?></p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Experience</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['experience_years'] ?? '0') ?> years</p>
                        </div>
                        <div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Base Salary</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= !empty($staff['salary']) ? htmlspecialchars(getSchoolSetting('currency_symbol', '₵')) . number_format($staff['salary'], 2) : 'N/A' ?></p>
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <p class="text-sm text-gray-500 dark:text-gray-400 mb-1">Highest Qualification</p>
                            <p class="font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($staff['qualification'] ?? 'N/A') ?></p>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Attendance -->
                <div x-show="activeTab === 'attendance'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">
                    <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                        <!-- Present -->
                        <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/50 border-l-4 border-l-emerald-500 dark:border-l-emerald-500 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Present</p>
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?= $att_summary['present'] ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-emerald-50 dark:bg-emerald-950/30 flex items-center justify-center text-emerald-600 dark:text-emerald-400 shrink-0 shadow-sm border border-emerald-100/30 dark:border-emerald-900/30">
                                <i class="fas fa-calendar-check text-xl"></i>
                            </div>
                        </div>

                        <!-- Absent -->
                        <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/50 border-l-4 border-l-rose-500 dark:border-l-rose-500 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Absent</p>
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?= $att_summary['absent'] ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-rose-50 dark:bg-rose-950/30 flex items-center justify-center text-rose-600 dark:text-rose-400 shrink-0 shadow-sm border border-rose-100/30 dark:border-rose-900/30">
                                <i class="fas fa-calendar-times text-xl"></i>
                            </div>
                        </div>

                        <!-- Late -->
                        <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/50 border-l-4 border-l-amber-500 dark:border-l-amber-500 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Late</p>
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?= $att_summary['late'] ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center text-amber-600 dark:text-amber-400 shrink-0 shadow-sm border border-amber-100/30 dark:border-amber-900/30">
                                <i class="fas fa-clock text-xl"></i>
                            </div>
                        </div>

                        <!-- Half Day -->
                        <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/50 border-l-4 border-l-orange-500 dark:border-l-orange-500 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">Half Day</p>
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?= $att_summary['half_day'] ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-orange-50 dark:bg-orange-950/30 flex items-center justify-center text-orange-600 dark:text-orange-400 shrink-0 shadow-sm border border-orange-100/30 dark:border-orange-900/30">
                                <i class="fas fa-adjust text-xl"></i>
                            </div>
                        </div>

                        <!-- On Leave -->
                        <div class="bg-white dark:bg-gray-800 p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700/50 border-l-4 border-l-blue-500 dark:border-l-blue-500 hover:shadow-md hover:-translate-y-1 transition-all duration-200 flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">On Leave</p>
                                <p class="text-3xl font-extrabold text-gray-900 dark:text-white"><?= $att_summary['on_leave'] ?></p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-blue-50 dark:bg-blue-950/30 flex items-center justify-center text-blue-600 dark:text-blue-400 shrink-0 shadow-sm border border-blue-100/30 dark:border-blue-900/30">
                                <i class="fas fa-plane-departure text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Attendance (Last 30 Days)</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Check In</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Check Out</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Notes</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($attendance_records)): ?>
                                        <tr>
                                            <td colspan="5" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No attendance records found for the last 30 days.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($attendance_records as $record): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?= date('M d, Y', strtotime($record['date'])) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <?php
                                                    $bg = 'bg-gray-100 text-gray-800';
                                                    if($record['status'] == 'Present') $bg = 'bg-green-100 text-green-800';
                                                    if($record['status'] == 'Absent') $bg = 'bg-red-100 text-red-800';
                                                    if($record['status'] == 'Late') $bg = 'bg-yellow-100 text-yellow-800';
                                                    if($record['status'] == 'Half_Day') $bg = 'bg-orange-100 text-orange-800';
                                                    if($record['status'] == 'On_Leave') $bg = 'bg-blue-100 text-blue-800';
                                                    ?>
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $bg ?>">
                                                        <?= htmlspecialchars($record['status']) ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= $record['check_in'] ? date('h:i A', strtotime($record['check_in'])) : '-' ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= $record['check_out'] ? date('h:i A', strtotime($record['check_out'])) : '-' ?></td>
                                                <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($record['notes'] ?? '') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Performance -->
                <div x-show="activeTab === 'performance'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">
                    <?php if(!empty($evaluations)): $latest = $evaluations[0]; ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Latest Evaluation (<?= htmlspecialchars($latest['evaluation_period']) ?>)</h3>
                            <div class="flex items-center gap-2 mb-6">
                                <span class="text-3xl font-bold text-gray-900 dark:text-white"><?= number_format($latest['overall_rating'], 1) ?></span>
                                <div class="flex text-yellow-400 text-xl">
                                    <?php for($i=1; $i<=5; $i++): ?>
                                        <i class="fas fa-star <?= $i <= round($latest['overall_rating']) ? '' : 'text-gray-300 dark:text-gray-600' ?>"></i>
                                    <?php endfor; ?>
                                </div>
                                <span class="ml-4 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"><?= htmlspecialchars($latest['status']) ?></span>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white mb-2">Strengths</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 bg-green-50 dark:bg-green-900/20 p-3 rounded-lg border border-green-100 dark:border-green-800"><?= nl2br(htmlspecialchars($latest['strengths'] ?? 'None recorded')) ?></p>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white mb-2">Areas for Improvement</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400 bg-orange-50 dark:bg-orange-900/20 p-3 rounded-lg border border-orange-100 dark:border-orange-800"><?= nl2br(htmlspecialchars($latest['areas_for_improvement'] ?? 'None recorded')) ?></p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Evaluation History</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Period</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Rating</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($evaluations)): ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No evaluations found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($evaluations as $eval): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($eval['evaluation_period']) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= date('M d, Y', strtotime($eval['evaluated_at'])) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white font-semibold"><?= number_format($eval['overall_rating'], 1) ?> / 5.0</td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800"><?= htmlspecialchars($eval['status']) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Tab Content: Qualifications -->
                <div x-show="activeTab === 'qualifications'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Qualifications & Certifications</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Title</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Institution</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date Obtained</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if(empty($qualifications)): ?>
                                    <tr>
                                        <td colspan="5" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No qualifications recorded.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($qualifications as $qual): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200">
                                                    <?= htmlspecialchars($qual['type'] ?? 'Degree') ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($qual['title']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($qual['institution']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= date('M Y', strtotime($qual['date_obtained'])) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php $status = $qual['status'] ?? 'Active'; ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $status === 'Active' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                                    <?= htmlspecialchars($status) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Tab Content: Schedule -->
                <div x-show="activeTab === 'schedule'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Working Schedule</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Day</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Shift Start</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Shift End</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Location</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php if(empty($schedules)): ?>
                                        <tr>
                                            <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No schedule found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach($schedules as $sched): ?>
                                            <tr>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($sched['day_of_week']) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= date('h:i A', strtotime($sched['shift_start'])) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= date('h:i A', strtotime($sched['shift_end'])) ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($sched['location'] ?? 'Main Campus') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <?php if($staff['role'] === 'teacher' && !empty($timetable)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden mt-6">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Teaching Timetable</h3>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Day</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Time</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach($timetable as $tt): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($tt['day_of_week']) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= date('h:i A', strtotime($tt['start_time'])) ?> - <?= date('h:i A', strtotime($tt['end_time'])) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white"><?= htmlspecialchars($tt['subject'] ?? 'N/A') ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400"><?= htmlspecialchars($tt['class_id'] ?? 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Tab Content: Leave History -->
                <div x-show="activeTab === 'leaves'" style="display: none;" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Leave History</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Leave Type</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Duration</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Reason</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php if(empty($leaves)): ?>
                                    <tr>
                                        <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No leave requests found.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach($leaves as $leave): ?>
                                        <tr>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $leave['leave_type']))) ?></td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                                                <?= date('M d, Y', strtotime($leave['start_date'])) ?> to <?= date('M d, Y', strtotime($leave['end_date'])) ?>
                                            </td>
                                            <td class="px-6 py-4 text-sm text-gray-500 dark:text-gray-400 truncate max-w-xs" title="<?= htmlspecialchars($leave['reason']) ?>">
                                                <?= htmlspecialchars($leave['reason']) ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                <?php
                                                $bg = 'bg-gray-100 text-gray-800';
                                                if($leave['status'] == 'approved') $bg = 'bg-green-100 text-green-800';
                                                if($leave['status'] == 'rejected') $bg = 'bg-red-100 text-red-800';
                                                if($leave['status'] == 'pending') $bg = 'bg-yellow-100 text-yellow-800';
                                                ?>
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $bg ?>">
                                                    <?= htmlspecialchars(ucfirst($leave['status'])) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<style>
/* Hide scrollbar for Chrome, Safari and Opera */
.hide-scrollbar::-webkit-scrollbar {
  display: none;
}
/* Hide scrollbar for IE, Edge and Firefox */
.hide-scrollbar {
  -ms-overflow-style: none;  /* IE and Edge */
  scrollbar-width: none;  /* Firefox */
}
</style>
