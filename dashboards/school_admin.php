<?php
// School Admin Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM users WHERE role = 'parent') as total_parents,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_today,
        (SELECT COUNT(*) FROM subjects) as total_subjects,
        (SELECT COUNT(*) FROM attendance WHERE DATE(date) = CURDATE()) as today_attendance,
        (SELECT COUNT(*) FROM assignments) as active_assignments";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_parents' => 0,
        'total_classes' => 0,
        'new_today' => 0,
        'total_subjects' => 0,
        'today_attendance' => 0,
        'active_assignments' => 0
    ];
}

// Get enrollment trends
try {
    $enrollment_query = "SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count
        FROM users
        WHERE role = 'student'
        AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month";
    $enrollment_stmt = $db->query($enrollment_query);
    $enrollment_data = $enrollment_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $enrollment_data = [];
}

// Get recent activities
try {
    $activities_query = "SELECT
        u.name, u.role, u.created_at, 'enrollment' as activity_type
        FROM users u
        WHERE u.role IN ('student', 'teacher')
        ORDER BY u.created_at DESC LIMIT 6";
    $activities_stmt = $db->query($activities_query);
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_activities = [];
}

// Get pending tasks
try {
    $pending_query = "SELECT
        (SELECT COUNT(*) FROM assignments WHERE due_date < NOW()) as overdue_assignments";
    $pending_stmt = $db->query($pending_query);
    $pending_tasks = $pending_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$pending_tasks) {
        $pending_tasks = ['overdue_assignments' => 0];
    }
    $pending_tasks['pending_approvals'] = 0;
    $pending_tasks['pending_fees'] = 0;
} catch (PDOException $e) {
    $pending_tasks = [
        'pending_approvals' => 0,
        'overdue_assignments' => 0,
        'pending_fees' => 0
    ];
}
?>

<!-- School Admin Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">School Administration</h1>
                <p class="text-blue-100 text-lg">Manage school operations and academic excellence</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-school mr-2"></i>
                        Greenwood Academy
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>
                <!-- Academic Context -->
                <div class="mt-4 p-4 bg-white/10 rounded-lg backdrop-blur-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-blue-100 mb-1">Current Academic Session</p>
                            <p class="text-lg font-semibold text-white">
                                <?php echo htmlspecialchars($academic_context['year_name']); ?> - <?php echo htmlspecialchars($academic_context['term_name']); ?>
                            </p>
                        </div>
                        <a href="academic/settings/" class="text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-cog text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-university text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- School Admin Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Students -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Students</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_students']; ?></p>
                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    <i class="fas fa-arrow-up mr-1"></i>
                    Active enrollments
                </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Total Teachers -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Faculty Members</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_teachers']; ?></p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-check mr-1"></i>
                    Active teachers
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chalkboard-teacher text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Active Classes -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Classes</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_classes']; ?></p>
                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                    <i class="fas fa-calendar mr-1"></i>
                    This semester
                </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chalkboard text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Today's Attendance -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Today's Attendance</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['today_attendance']; ?></p>
                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                    <i class="fas fa-calendar-check mr-1"></i>
                    Records taken
                </p>
            </div>
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-calendar-check text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Management Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- Enrollment Trends -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Enrollment</h3>
            <div class="flex items-center space-x-2">
                <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                <span class="text-sm text-gray-600 dark:text-gray-400">Last 6 months</span>
            </div>
        </div>
        <div class="h-64">
            <canvas id="enrollmentChart"></canvas>
        </div>
    </div>

    <!-- Pending Tasks -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Pending Tasks</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Requires attention</span>
        </div>
        <div class="space-y-4">
            <div class="flex items-center justify-between p-4 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-yellow-100 dark:bg-yellow-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-user-clock text-yellow-600 dark:text-yellow-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Pending Approvals</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">User registrations</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-yellow-600 dark:text-yellow-400"><?php echo $pending_tasks['pending_approvals'] ?? 0; ?></span>
            </div>
            
            <div class="flex items-center justify-between p-4 bg-red-50 dark:bg-red-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-exclamation-triangle text-red-600 dark:text-red-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Overdue Assignments</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Need attention</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-red-600 dark:text-red-400"><?php echo $pending_tasks['overdue_assignments'] ?? 0; ?></span>
            </div>
            
            <div class="flex items-center justify-between p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                <div class="flex items-center space-x-3">
                    <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                        <i class="fas fa-money-bill-wave text-blue-600 dark:text-blue-400 text-sm"></i>
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-900 dark:text-white">Pending Fees</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Payment processing</p>
                    </div>
                </div>
                <span class="text-lg font-bold text-blue-600 dark:text-blue-400"><?php echo $pending_tasks['pending_fees'] ?? 0; ?></span>
            </div>
        </div>
    </div>
</div>

<!-- School Admin Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">School Management</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Administrative tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="students/enroll.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-user-plus text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Enroll Student</span>
        </a>
        <a href="users/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-user-tie text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Staff</span>
        </a>
        <a href="academic/classes/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-chalkboard text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Create Class</span>
        </a>
        <a href="finance/fees.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-money-bill-wave text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Manage Fees</span>
        </a>
        <a href="reports/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-chart-bar text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Reports</span>
        </a>
        <a href="communication/announcements.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-bullhorn text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Announcements</span>
        </a>
    </div>
</div>

<!-- Chart.js Script -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Student Enrollment Chart
const enrollmentCtx = document.getElementById('enrollmentChart').getContext('2d');
const enrollmentChart = new Chart(enrollmentCtx, {
    type: 'line',
    data: {
        labels: ['January', 'February', 'March', 'April', 'May', 'June'],
        datasets: [{
            label: 'New Enrollments',
            data: [45, 52, 38, 67, 43, 58],
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointBackgroundColor: 'rgb(59, 130, 246)',
            pointBorderColor: '#fff',
            pointBorderWidth: 2,
            pointRadius: 6,
            pointHoverRadius: 8
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                backgroundColor: 'rgba(0, 0, 0, 0.8)',
                titleColor: '#fff',
                bodyColor: '#fff',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1,
                cornerRadius: 8,
                displayColors: false,
                callbacks: {
                    title: function(context) {
                        return context[0].label;
                    },
                    label: function(context) {
                        return `${context.parsed.y} new students enrolled`;
                    }
                }
            }
        },
        scales: {
            x: {
                grid: {
                    display: false
                },
                ticks: {
                    color: '#6b7280',
                    font: {
                        size: 12
                    }
                }
            },
            y: {
                beginAtZero: true,
                grid: {
                    color: 'rgba(107, 114, 128, 0.1)'
                },
                ticks: {
                    color: '#6b7280',
                    font: {
                        size: 12
                    },
                    callback: function(value) {
                        return value + ' students';
                    }
                }
            }
        },
        interaction: {
            intersect: false,
            mode: 'index'
        },
        elements: {
            point: {
                hoverBackgroundColor: 'rgb(59, 130, 246)'
            }
        }
    }
});

// Add animation on load
enrollmentChart.update('active');
</script>
