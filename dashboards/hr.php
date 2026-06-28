<?php
// Human Resource (HR) Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
try {
    $staff_roles_list = "'teacher', 'librarian', 'accountant', 'nurse', 'counselor', 'transport_officer', 'hostel_warden', 'canteen_manager', 'hr'";
    
    // Stats Query. Leaves live in `leave_requests` (the canonical staff leave
    // table used by staff/leaves.php), not `staff_leaves`.
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE status = 'active' AND role IN ($staff_roles_list)) as total_staff,
        (SELECT COUNT(*) FROM staff_departments WHERE status = 'active') as total_departments,
        (SELECT COUNT(*) FROM leave_requests lr JOIN users u ON lr.user_id = u.id
            WHERE lr.status = 'pending' AND u.role IN ($staff_roles_list)) as pending_leaves,
        (SELECT COALESCE(SUM(tp.salary),0) FROM teacher_profiles tp JOIN users u ON tp.user_id = u.id
            WHERE u.status = 'active' AND u.role IN ($staff_roles_list)) as total_monthly_payroll";

    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$stats) {
        $stats = [
            'total_staff' => 0,
            'total_departments' => 0,
            'pending_leaves' => 0,
            'total_monthly_payroll' => 0
        ];
    }
} catch (PDOException $e) {
    $stats = [
        'total_staff' => 0,
        'total_departments' => 0,
        'pending_leaves' => 0,
        'total_monthly_payroll' => 0
    ];
}

// Recent Hires
try {
    $recent_hires_query = "SELECT u.id, u.name, u.role, tp.employee_id, tp.department,
            COALESCE(tp.joining_date, u.created_at) AS joined_on
        FROM users u
        LEFT JOIN teacher_profiles tp ON u.id = tp.user_id
        WHERE u.status = 'active' AND u.role IN ($staff_roles_list)
        ORDER BY joined_on DESC LIMIT 5";
    $recent_hires_stmt = $db->query($recent_hires_query);
    $recent_hires = $recent_hires_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_hires = [];
}

// Pending Leave Requests
try {
    $pending_leaves_query = "SELECT lr.id, u.name as staff_name, lr.leave_type, lr.start_date, lr.end_date, lr.status, lr.reason
        FROM leave_requests lr
        JOIN users u ON lr.user_id = u.id
        WHERE lr.status = 'pending' AND u.role IN ($staff_roles_list)
        ORDER BY lr.created_at DESC LIMIT 5";
    $pending_leaves_stmt = $db->query($pending_leaves_query);
    $pending_leaves = $pending_leaves_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pending_leaves = [];
}

// Workforce distribution by role (for the chart)
try {
    $role_dist_stmt = $db->query("SELECT role, COUNT(*) as cnt FROM users
        WHERE status = 'active' AND role IN ($staff_roles_list)
        GROUP BY role ORDER BY cnt DESC");
    $role_distribution = $role_dist_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $role_distribution = [];
}

// Leave requests grouped by status (for the chart)
try {
    $leave_status_stmt = $db->query("SELECT lr.status, COUNT(*) as cnt
        FROM leave_requests lr JOIN users u ON lr.user_id = u.id
        WHERE u.role IN ($staff_roles_list)
        GROUP BY lr.status");
    $leave_status_rows = $leave_status_stmt->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {
    $leave_status_rows = [];
}

$currency = getSchoolSetting('currency_symbol', '₵');
$role_labels = [
    'teacher'           => 'Teacher',
    'librarian'         => 'Librarian',
    'accountant'        => 'Accountant',
    'nurse'             => 'Nurse',
    'counselor'         => 'Counselor',
    'transport_officer' => 'Transport Officer',
    'hostel_warden'     => 'Hostel Warden',
    'canteen_manager'   => 'Canteen Manager',
    'hr'                => 'Human Resource (HR)'
];
?>

<?php
$color_map = [
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
];
?>
<!-- HR Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-users-cog mr-1.5"></i> Human Resources Office</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'HR'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Manage school workforce, talent, attendance and leaves.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-user-tie mr-2"></i><?php echo $stats['total_staff']; ?> active staff</span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-address-card text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- HR Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="HR statistics">
    <?php
    $summary_cards = [
        ['label' => 'Total Workforce', 'value' => $stats['total_staff'], 'icon' => 'fa-users', 'color' => 'blue', 'hint' => 'Active staff members'],
        ['label' => 'Departments', 'value' => $stats['total_departments'], 'icon' => 'fa-sitemap', 'color' => 'violet', 'hint' => 'Org structure'],
        ['label' => 'Pending Leaves', 'value' => $stats['pending_leaves'], 'icon' => 'fa-calendar-times', 'color' => 'amber', 'hint' => 'Action required'],
        ['label' => 'Monthly Payroll', 'value' => htmlspecialchars($currency) . number_format($stats['total_monthly_payroll'] ?? 0, 2), 'icon' => 'fa-money-bill-wave', 'color' => 'emerald', 'hint' => 'Total base salary'],
    ];
    foreach ($summary_cards as $card):
        $c = $color_map[$card['color']];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-5 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 <?php echo $c['bg']; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?php echo $card['label']; ?></p>
                <p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- HR Charts & Analytics -->
<?php
$role_chart = ['labels' => [], 'counts' => []];
foreach ($role_distribution as $rd) {
    $role_chart['labels'][] = $role_labels[$rd['role']] ?? formatRoleName($rd['role']);
    $role_chart['counts'][] = (int)$rd['cnt'];
}
$leave_chart = [
    'labels' => ['Pending', 'Approved', 'Rejected'],
    'data'   => [
        (int)($leave_status_rows['pending'] ?? 0),
        (int)($leave_status_rows['approved'] ?? 0),
        (int)($leave_status_rows['rejected'] ?? 0),
    ],
];
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Workforce by Role -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Workforce by Role</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Active staff</span>
        </div>
        <div class="h-64"><canvas id="hrRoleChart"></canvas></div>
    </div>
    <!-- Leave Requests -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Leave Requests</h3>
        </div>
        <div class="h-64"><canvas id="hrLeaveChart"></canvas></div>
    </div>
</div>

<!-- Lists and Hires Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- Recent Hires -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Hires</h3>
            <a href="staff/index.php" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800">View Directory</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($recent_hires)): ?>
                <?php foreach ($recent_hires as $hire): 
                    $role_display = $role_labels[$hire['role']] ?? formatRoleName($hire['role']);
                    $dept_display = $hire['department'] ?: 'Unassigned';
                ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-100 dark:border-gray-700">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-gradient-to-r from-blue-400 to-indigo-500 rounded-full flex items-center justify-center text-white font-bold">
                            <?php echo strtoupper(substr($hire['name'], 0, 1)); ?>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($hire['name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $role_display; ?> (ID: <?php echo htmlspecialchars($hire['employee_id'] ?? 'N/A'); ?>)</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($dept_display); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Joined: <?php echo date('M j, Y', strtotime($hire['joined_on'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-user-friends text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No recent hires found</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pending Leave Requests -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Active Leave Requests</h3>
            <a href="staff/leaves.php" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800">Leave Portal</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($pending_leaves)): ?>
                <?php foreach ($pending_leaves as $leave): ?>
                <div class="flex flex-col p-4 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-100 dark:border-gray-700">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($leave['staff_name']); ?></p>
                        <span class="px-2 py-0.5 bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300 text-xs font-semibold rounded-full uppercase">
                            <?php echo htmlspecialchars($leave['leave_type']); ?>
                        </span>
                    </div>
                    <p class="text-xs text-gray-600 dark:text-gray-400 italic mb-2">"<?php echo htmlspecialchars($leave['reason'] ?? 'No reason provided'); ?>"</p>
                    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Duration: <?php echo date('M j', strtotime($leave['start_date'])); ?> to <?php echo date('M j, Y', strtotime($leave['end_date'])); ?></span>
                        <a href="staff/leaves.php" class="text-blue-600 hover:underline">Review & Action</a>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar-check text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No pending leave requests</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- HR Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">HR Quick Actions</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">HR Toolkit</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <a href="staff/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-user-plus text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Staff</span>
        </a>
        <a href="staff/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-800 transition-colors duration-200">
                <i class="fas fa-address-book text-emerald-600 dark:text-emerald-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Staff Directory</span>
        </a>
        <a href="staff/leaves.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-amber-200 dark:group-hover:bg-amber-800 transition-colors duration-200">
                <i class="fas fa-plane-departure text-amber-600 dark:text-amber-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Leaves</span>
        </a>
        <a href="staff/salaries.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-coins text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Salary Allocation</span>
        </a>
        <a href="staff/performance.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-star text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Performance</span>
        </a>
        <a href="staff/departments.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-pink-100 dark:bg-pink-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-pink-200 dark:group-hover:bg-pink-800 transition-colors duration-200">
                <i class="fas fa-sitemap text-pink-600 dark:text-pink-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Departments</span>
        </a>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const HR_ROLES = <?php echo json_encode($role_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const HR_LEAVES = <?php echo json_encode($leave_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let roleChart = null, leaveChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const rc = document.getElementById('hrRoleChart');
        if (rc) {
            const labels = HR_ROLES.labels.length ? HR_ROLES.labels : ['No staff'];
            const counts = HR_ROLES.counts.length ? HR_ROLES.counts : [0];
            if (roleChart) roleChart.destroy();
            roleChart = new Chart(rc, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Staff', data: counts,
                        backgroundColor: 'rgba(99,102,241,0.7)', hoverBackgroundColor: 'rgba(99,102,241,0.95)',
                        borderRadius: 8, maxBarThickness: 36 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 10 }, maxRotation: 45, minRotation: 0 } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                    }
                }
            });
        }

        const lc = document.getElementById('hrLeaveChart');
        if (lc) {
            const hasData = HR_LEAVES.data.some(v => v > 0);
            if (leaveChart) leaveChart.destroy();
            leaveChart = new Chart(lc, {
                type: 'doughnut',
                data: { labels: HR_LEAVES.labels, datasets: [{ data: hasData ? HR_LEAVES.data : [1, 0, 0],
                        backgroundColor: ['#f59e0b', '#10b981', '#ef4444'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }] },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, boxWidth: 10, color: tick, font: { size: 11 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());
</script>
