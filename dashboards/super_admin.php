<?php
// Super Admin Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM users WHERE role = 'parent') as total_parents,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM users WHERE DATE(created_at) = CURDATE()) as new_today,
        (SELECT COUNT(*) FROM users WHERE role = 'school_admin') as total_admins,
        (SELECT COUNT(*) FROM academic_years) as academic_years,
        (SELECT COUNT(*) FROM subjects) as total_subjects";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback values if query fails
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_parents' => 0,
        'total_classes' => 0,
        'new_today' => 0,
        'total_admins' => 0,
        'academic_years' => 0,
        'total_subjects' => 0
    ];
}

// Get system analytics
try {
    $analytics_query = "SELECT
        DATE_FORMAT(created_at, '%Y-%m') as month,
        COUNT(*) as count,
        role
        FROM users
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m'), role
        ORDER BY month";
    $analytics_stmt = $db->query($analytics_query);
    $analytics_data = $analytics_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $analytics_data = [];
}

// Get recent system activities
try {
    $activities_query = "SELECT
        u.name, u.role, u.created_at, 'user_created' as activity_type
        FROM users u
        ORDER BY u.created_at DESC LIMIT 8";
    $activities_stmt = $db->query($activities_query);
    $recent_activities = $activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_activities = [];
}
?>

<!-- Super Admin Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Super Admin Control Center</h1>
                <p class="text-blue-100 text-lg">Complete system oversight and management</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-shield-alt mr-2"></i>
                        System Administrator
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-clock mr-2"></i>
                        <span id="current-time"><?php echo date('g:i A'); ?></span>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-crown text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Super Admin Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Users -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Users</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white">
                    <?php echo ($stats['total_students'] + $stats['total_teachers'] + $stats['total_parents'] + $stats['total_admins']); ?>
                </p>
                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    <i class="fas fa-users mr-1"></i>
                    All system users
                </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- System Admins -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">System Admins</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_admins']; ?></p>
                <p class="text-sm text-red-600 dark:text-red-400 mt-1">
                    <i class="fas fa-user-shield mr-1"></i>
                    Administrative users
                </p>
            </div>
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-shield text-red-600 dark:text-red-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Academic Years -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Academic Years</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['academic_years']; ?></p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-calendar-alt mr-1"></i>
                    System records
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-calendar-alt text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- New Today -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">New Today</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['new_today']; ?></p>
                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                    <i class="fas fa-plus mr-1"></i>
                    New registrations
                </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-plus text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- System Management Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- System Analytics -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Growth</h3>
            <div class="flex items-center space-x-2">
                <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                <span class="text-sm text-gray-600 dark:text-gray-400">Last 6 months</span>
            </div>
        </div>
        <div class="h-64" style="min-height: 256px;">
            <canvas id="systemGrowthChart"></canvas>
        </div>

        <!-- Growth Insights & Quick Actions -->
        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="grid grid-cols-2 gap-4 mb-4">
                <!-- Growth Rate -->
                <div class="text-center">
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">+12.5%</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Student Growth</div>
                </div>
                <!-- Peak Month -->
                <div class="text-center">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">Jan 25</div>
                    <div class="text-xs text-gray-600 dark:text-gray-400">Peak Month</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="flex space-x-2">
                <button class="flex-1 bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400 px-3 py-2 rounded-lg text-xs font-medium hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                    <i class="fas fa-chart-line mr-1"></i>
                    View Details
                </button>
                <button class="flex-1 bg-green-50 dark:bg-green-900/20 text-green-600 dark:text-green-400 px-3 py-2 rounded-lg text-xs font-medium hover:bg-green-100 dark:hover:bg-green-900/30 transition-colors">
                    <i class="fas fa-download mr-1"></i>
                    Export Data
                </button>
            </div>
        </div>
    </div>

    <!-- Recent System Activities -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Activities</h3>
            <a href="admin/logs.php" class="text-sm text-red-600 dark:text-red-400 hover:text-red-800">View logs</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($recent_activities)): ?>
                <?php foreach ($recent_activities as $activity): ?>
                <div class="flex items-start space-x-3 p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200">
                    <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-user-plus text-red-600 dark:text-red-400 text-sm"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-gray-900 dark:text-white">
                            New <?php echo $activity['role']; ?>: <?php echo htmlspecialchars($activity['name']); ?>
                        </p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo date('M j, Y g:i A', strtotime($activity['created_at'])); ?>
                        </p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-inbox text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No recent activities</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Super Admin Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Administration</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Administrative tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="admin/users.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-red-200 dark:group-hover:bg-red-800 transition-colors duration-200">
                <i class="fas fa-users-cog text-red-600 dark:text-red-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">User Management</span>
        </a>
        <a href="admin/system_settings.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-cogs text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">System Settings</span>
        </a>
        <a href="admin/backup.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-database text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Backup & Restore</span>
        </a>
        <a href="admin/security.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-shield-alt text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Security</span>
        </a>
        <a href="admin/logs.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-file-alt text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">System Logs</span>
        </a>
        <a href="academic/settings/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-graduation-cap text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Settings</span>
        </a>
    </div>
</div>

<!-- System Health -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">System Health</h3>
        <div class="flex items-center space-x-2">
            <div class="w-3 h-3 bg-green-500 rounded-full animate-pulse"></div>
            <span class="text-sm text-green-600 dark:text-green-400 font-medium">All systems operational</span>
        </div>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-database text-green-600 dark:text-green-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Database</p>
                <p class="text-xs text-green-600 dark:text-green-400">Connected</p>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-server text-blue-600 dark:text-blue-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Server</p>
                <p class="text-xs text-blue-600 dark:text-blue-400">Online</p>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-shield-alt text-purple-600 dark:text-purple-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Security</p>
                <p class="text-xs text-purple-600 dark:text-purple-400">Protected</p>
            </div>
        </div>
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chart-line text-orange-600 dark:text-orange-400"></i>
            </div>
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-white">Performance</p>
                <p class="text-xs text-orange-600 dark:text-orange-400">Optimal</p>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// System Growth Chart
document.addEventListener('DOMContentLoaded', function() {
    const ctx = document.getElementById('systemGrowthChart');
    if (ctx) {
        // Sample data for the last 6 months - you can replace this with real data from PHP
        const currentDate = new Date();
        const months = [];
        const studentData = [];
        const teacherData = [];
        const classData = [];

        // Generate last 6 months
        for (let i = 5; i >= 0; i--) {
            const date = new Date(currentDate.getFullYear(), currentDate.getMonth() - i, 1);
            months.push(date.toLocaleDateString('en-US', { month: 'short', year: '2-digit' }));

            // Sample growth data - replace with actual database queries
            studentData.push(Math.floor(Math.random() * 50) + (100 + i * 10)); // Growing trend
            teacherData.push(Math.floor(Math.random() * 5) + (20 + i * 2)); // Slower growth
            classData.push(Math.floor(Math.random() * 3) + (15 + i * 1)); // Steady growth
        }

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: months,
                datasets: [
                    {
                        label: 'Students',
                        data: studentData,
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#3b82f6',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    },
                    {
                        label: 'Teachers',
                        data: teacherData,
                        borderColor: '#10b981',
                        backgroundColor: 'rgba(16, 185, 129, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#10b981',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    },
                    {
                        label: 'Classes',
                        data: classData,
                        borderColor: '#f59e0b',
                        backgroundColor: 'rgba(245, 158, 11, 0.1)',
                        borderWidth: 3,
                        fill: true,
                        tension: 0.4,
                        pointBackgroundColor: '#f59e0b',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                aspectRatio: 1,
                layout: {
                    padding: {
                        top: 10,
                        bottom: 10,
                        left: 10,
                        right: 10
                    }
                },
                plugins: {
                    legend: {
                        position: 'bottom',
                        align: 'center',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            boxWidth: 12,
                            boxHeight: 12,
                            font: {
                                size: 11
                            }
                        }
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        borderColor: '#3b82f6',
                        borderWidth: 1,
                        cornerRadius: 8,
                        displayColors: true
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#6b7280'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(107, 114, 128, 0.1)',
                            drawBorder: false
                        },
                        ticks: {
                            font: {
                                size: 11
                            },
                            color: '#6b7280',
                            callback: function(value) {
                                return value;
                            }
                        }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                },
                elements: {
                    point: {
                        hoverRadius: 8
                    }
                }
            }
        });
    }
});
</script>
