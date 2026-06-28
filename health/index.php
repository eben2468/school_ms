<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('health');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('health'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Get health statistics
$health_stats_query = "SELECT
    COUNT(DISTINCT hr.id) as total_records,
    COUNT(DISTINCT CASE WHEN COALESCE(hr.visit_date, hr.record_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN hr.id END) as recent_visits,
    COUNT(DISTINCT CASE WHEN hr.status = 'active' THEN hr.id END) as active_cases,
    COUNT(DISTINCT hr.student_id) as students_with_records
    FROM health_records hr";
$health_stats_stmt = $db->query($health_stats_query);
$health_stats = $health_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get counseling statistics
$counseling_stats_query = "SELECT 
    COUNT(DISTINCT cs.id) as total_sessions,
    COUNT(DISTINCT CASE WHEN cs.session_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN cs.id END) as recent_sessions,
    COUNT(DISTINCT CASE WHEN cs.status = 'scheduled' THEN cs.id END) as scheduled_sessions,
    COUNT(DISTINCT cs.student_id) as students_counseled
    FROM counseling_sessions cs";
$counseling_stats_stmt = $db->query($counseling_stats_query);
$counseling_stats = $counseling_stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent health visits
$recent_visits_query = "SELECT hr.*, s.name as student_name, s.student_id,
    hr.created_at as visit_date,
    COALESCE(hr.complaint, hr.description, 'General checkup') as complaint,
    COALESCE(hr.status, 'completed') as status
    FROM health_records hr
    JOIN students s ON hr.student_id = s.id
    WHERE hr.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY hr.created_at DESC
    LIMIT 5";
$recent_visits_stmt = $db->query($recent_visits_query);
$recent_visits = $recent_visits_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming counseling sessions
$upcoming_sessions_query = "SELECT cs.*, u.name as student_name, sp.student_id
    FROM counseling_sessions cs
    JOIN users u ON cs.student_id = u.id
    LEFT JOIN student_profiles sp ON u.id = sp.user_id
    WHERE cs.session_date >= CURDATE() AND cs.status = 'scheduled'
    ORDER BY cs.session_date ASC, cs.session_time ASC
    LIMIT 5";
$upcoming_sessions_stmt = $db->query($upcoming_sessions_query);
$upcoming_sessions = $upcoming_sessions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get common health issues
$common_issues_query = "SELECT
    COALESCE(complaint, description, 'General checkup') as complaint,
    COUNT(*) as count
    FROM health_records
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY COALESCE(complaint, description, 'General checkup')
    ORDER BY count DESC
    LIMIT 5";
$common_issues_stmt = $db->query($common_issues_query);
$common_issues = $common_issues_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Health & Counseling Dashboard";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-full mx-auto" style="margin-top: 40px; padding-left: 20px; padding-right: 20px;">
            <!-- Header Section -->
            <div class="mb-8">
                <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Health & Counseling</h1>
                            <p class="text-blue-100 text-lg">Comprehensive health management and counseling services</p>
                            <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                <div class="flex items-center">
                                    <i class="fas fa-file-medical mr-2"></i>
                                    <span>Medical Records</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-comments mr-2"></i>
                                    <span>Counseling Sessions</span>
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-heart mr-2"></i>
                                    <span>Student Wellness</span>
                                </div>
                            </div>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-heartbeat text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex justify-between items-center mb-6">
                <div class="flex space-x-3">
                    <a href="medical_records/create.php" class="bg-emerald-500 hover:bg-emerald-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                        <i class="fas fa-stethoscope mr-2"></i>Log Clinic Visit
                    </a>
                    <a href="records/create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg font-medium text-sm">
                        <i class="fas fa-file-medical mr-2"></i>New Vital Assessment
                    </a>
                    <a href="counseling/create.php" class="bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg font-medium text-sm">
                        <i class="fas fa-calendar-plus mr-2"></i>Schedule Counseling Session
                    </a>
                </div>
            </div>

            <!-- Health Statistics -->
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Health Statistics</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Health Records</p>
                                <p class="text-2xl font-bold text-blue-600"><?php echo number_format($health_stats['total_records']); ?></p>
                            </div>
                            <div class="p-3 bg-blue-100 rounded-full">
                                <i class="fas fa-file-medical text-blue-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Recent Visits (30 days)</p>
                                <p class="text-2xl font-bold text-green-600"><?php echo number_format($health_stats['recent_visits']); ?></p>
                            </div>
                            <div class="p-3 bg-green-100 rounded-full">
                                <i class="fas fa-user-md text-green-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Active Cases</p>
                                <p class="text-2xl font-bold text-yellow-600"><?php echo number_format($health_stats['active_cases']); ?></p>
                            </div>
                            <div class="p-3 bg-yellow-100 rounded-full">
                                <i class="fas fa-exclamation-triangle text-yellow-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Students with Records</p>
                                <p class="text-2xl font-bold text-purple-600"><?php echo number_format($health_stats['students_with_records']); ?></p>
                            </div>
                            <div class="p-3 bg-purple-100 rounded-full">
                                <i class="fas fa-users text-purple-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Counseling Statistics -->
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Counseling Statistics</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Total Sessions</p>
                                <p class="text-2xl font-bold text-indigo-600"><?php echo number_format($counseling_stats['total_sessions']); ?></p>
                            </div>
                            <div class="p-3 bg-indigo-100 rounded-full">
                                <i class="fas fa-comments text-indigo-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Recent Sessions (30 days)</p>
                                <p class="text-2xl font-bold text-teal-600"><?php echo number_format($counseling_stats['recent_sessions']); ?></p>
                            </div>
                            <div class="p-3 bg-teal-100 rounded-full">
                                <i class="fas fa-calendar-check text-teal-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Scheduled Sessions</p>
                                <p class="text-2xl font-bold text-orange-600"><?php echo number_format($counseling_stats['scheduled_sessions']); ?></p>
                            </div>
                            <div class="p-3 bg-orange-100 rounded-full">
                                <i class="fas fa-clock text-orange-600 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600">Students Counseled</p>
                                <p class="text-2xl font-bold text-pink-600"><?php echo number_format($counseling_stats['students_counseled']); ?></p>
                            </div>
                            <div class="p-3 bg-pink-100 rounded-full">
                                <i class="fas fa-heart text-pink-600 text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="mb-8">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Quick Actions</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <a href="medical_records/" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="p-3 bg-blue-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <i class="fas fa-file-medical text-blue-600 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Medical Records</h3>
                            <p class="text-gray-600 text-sm">Manage student health records and medical history</p>
                        </div>
                    </a>

                    <a href="counseling/" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="p-3 bg-green-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <i class="fas fa-comments text-green-600 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Counseling Sessions</h3>
                            <p class="text-gray-600 text-sm">Schedule and manage counseling appointments</p>
                        </div>
                    </a>

                    <a href="emergency/" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="p-3 bg-red-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <i class="fas fa-ambulance text-red-600 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Emergency Contacts</h3>
                            <p class="text-gray-600 text-sm">Manage emergency contact information</p>
                        </div>
                    </a>

                    <a href="reports/" class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition-shadow">
                        <div class="text-center">
                            <div class="p-3 bg-purple-100 rounded-full w-16 h-16 mx-auto mb-4 flex items-center justify-center">
                                <i class="fas fa-chart-bar text-purple-600 text-2xl"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-800 mb-2">Health Reports</h3>
                            <p class="text-gray-600 text-sm">Generate health and wellness reports</p>
                        </div>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Recent Health Visits -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Recent Health Visits</h3>
                            <a href="medical_records/" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_visits)): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_visits as $visit): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($visit['student_name']); ?></div>
                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($visit['complaint']); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo date('M j, Y', strtotime($visit['visit_date'])); ?></div>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full 
                                    <?php echo $visit['status'] === 'active' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800'; ?>">
                                    <?php echo ucfirst($visit['status']); ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-center py-4">No recent health visits</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Upcoming Counseling Sessions -->
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex justify-between items-center">
                            <h3 class="text-lg font-semibold text-gray-800">Upcoming Counseling Sessions</h3>
                            <a href="counseling/" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($upcoming_sessions)): ?>
                        <div class="space-y-4">
                            <?php foreach ($upcoming_sessions as $session): ?>
                            <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($session['student_name']); ?></div>
                                    <div class="text-sm text-gray-600"><?php echo htmlspecialchars($session['session_type']); ?></div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo date('M j, Y', strtotime($session['session_date'])); ?> at 
                                        <?php echo date('g:i A', strtotime($session['session_time'])); ?>
                                    </div>
                                </div>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">
                                    Scheduled
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-center py-4">No upcoming counseling sessions</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Common Health Issues -->
            <?php if (!empty($common_issues)): ?>
            <div class="mt-8">
                <div class="bg-white rounded-lg shadow">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800">Common Health Issues (Last 30 Days)</h3>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                            <?php foreach ($common_issues as $issue): ?>
                            <div class="bg-gray-50 rounded-lg p-4">
                                <div class="text-lg font-bold text-blue-600"><?php echo $issue['count']; ?></div>
                                <div class="text-sm text-gray-600"><?php echo htmlspecialchars($issue['complaint']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
