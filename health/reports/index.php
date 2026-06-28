<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'nurse', 'doctor', 'counselor'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// --- 1. General Health Statistics ---
$stats_query = "SELECT
    COUNT(*) as total_visits,
    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_cases,
    COUNT(CASE WHEN status = 'resolved' THEN 1 END) as resolved_cases,
    COUNT(CASE WHEN status = 'referred' THEN 1 END) as referred_cases
    FROM health_records
    WHERE visit_date IS NOT NULL";
$stmt = $db->query($stats_query);
$health_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total_visits = $health_stats['total_visits'] ?: 0;
$active_cases = $health_stats['active_cases'] ?: 0;
$resolved_cases = $health_stats['resolved_cases'] ?: 0;
$referred_cases = $health_stats['referred_cases'] ?: 0;

$resolution_rate = $total_visits > 0 ? ($resolved_cases / $total_visits) * 100 : 0;

// --- 2. Counseling Statistics ---
$counseling_query = "SELECT
    COUNT(*) as total_sessions,
    COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed_sessions,
    COUNT(CASE WHEN status = 'scheduled' THEN 1 END) as scheduled_sessions,
    COUNT(CASE WHEN status = 'cancelled' THEN 1 END) as cancelled_sessions
    FROM counseling_sessions";
$stmt = $db->query($counseling_query);
$counseling_stats = $stmt->fetch(PDO::FETCH_ASSOC);

$total_sessions = $counseling_stats['total_sessions'] ?: 0;
$completed_sessions = $counseling_stats['completed_sessions'] ?: 0;
$scheduled_sessions = $counseling_stats['scheduled_sessions'] ?: 0;

// --- 3. Category distribution (illness, injury, checkup, etc) ---
$cat_query = "SELECT record_type, COUNT(*) as count 
              FROM health_records 
              WHERE visit_date IS NOT NULL AND record_type IS NOT NULL
              GROUP BY record_type";
$stmt = $db->query($cat_query);
$categories_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$cat_labels = [];
$cat_counts = [];
foreach ($categories_data as $row) {
    $cat_labels[] = ucfirst($row['record_type']);
    $cat_counts[] = (int)$row['count'];
}

// --- 4. Counseling type distribution (academic, behavioral, etc) ---
$counseling_type_query = "SELECT session_type, COUNT(*) as count 
                         FROM counseling_sessions 
                         GROUP BY session_type";
$stmt = $db->query($counseling_type_query);
$counseling_type_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

$counsel_labels = [];
$counsel_counts = [];
foreach ($counseling_type_data as $row) {
    $counsel_labels[] = ucfirst($row['session_type']);
    $counsel_counts[] = (int)$row['count'];
}

// --- 5. Weekly visits trend ---
$weekly_trend_query = "SELECT visit_date, COUNT(*) as count 
                       FROM health_records 
                       WHERE visit_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND visit_date IS NOT NULL
                       GROUP BY visit_date 
                       ORDER BY visit_date ASC";
$stmt = $db->query($weekly_trend_query);
$weekly_trend = $stmt->fetchAll(PDO::FETCH_ASSOC);

$trend_labels = [];
$trend_counts = [];
// Pre-populate last 7 days
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trend_labels[$d] = date('M j', strtotime("-$i days"));
    $trend_counts[$d] = 0;
}
foreach ($weekly_trend as $row) {
    if (isset($trend_counts[$row['visit_date']])) {
        $trend_counts[$row['visit_date']] = (int)$row['count'];
    }
}

// --- 6. Top Common Complaints ---
$complaints_query = "SELECT complaint, COUNT(*) as count 
                     FROM health_records 
                     WHERE complaint IS NOT NULL AND complaint != ''
                     GROUP BY complaint 
                     ORDER BY count DESC 
                     LIMIT 5";
$stmt = $db->query($complaints_query);
$top_complaints = $stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Health Reports & Analytics";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-7xl mx-auto">
                
                <!-- Page Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Health Reports & Analytics</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Wellness insights, clinic traffic trends, and counseling analytics</p>
                    </div>
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Health
                    </a>
                </div>

                <!-- Stats summary grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <!-- Total Visits -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase">Total Clinic Visits</p>
                                <h3 class="text-2xl font-extrabold text-blue-650 dark:text-blue-400 mt-1"><?php echo $total_visits; ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center text-blue-600">
                                <i class="fas fa-stethoscope text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Active Cases -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase">Active Observations</p>
                                <h3 class="text-2xl font-extrabold text-yellow-600 dark:text-yellow-400 mt-1"><?php echo $active_cases; ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-yellow-50 dark:bg-yellow-900/20 flex items-center justify-center text-yellow-600">
                                <i class="fas fa-hourglass-half text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Total Counseling -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase">Counseling Sessions</p>
                                <h3 class="text-2xl font-extrabold text-purple-650 dark:text-purple-400 mt-1"><?php echo $total_sessions; ?></h3>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center text-purple-600">
                                <i class="fas fa-comments text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Resolution Rate -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-100 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-xs font-semibold text-gray-400 uppercase">Resolution Rate</p>
                                <h3 class="text-2xl font-extrabold text-emerald-600 dark:text-emerald-400 mt-1"><?php echo number_format($resolution_rate, 1); ?>%</h3>
                            </div>
                            <div class="w-12 h-12 rounded-full bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center text-emerald-600">
                                <i class="fas fa-check-circle text-xl"></i>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Charts layout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Weekly Trend Line Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 lg:col-span-2">
                        <h3 class="text-md font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-chart-line text-blue-500 mr-2"></i>Clinic Visits - Last 7 Days</h3>
                        <div class="relative h-64">
                            <canvas id="weeklyTrendChart"></canvas>
                        </div>
                    </div>

                    <!-- Visit Status Pie Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6">
                        <h3 class="text-md font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-chart-pie text-emerald-500 mr-2"></i>Visits Status Breakdown</h3>
                        <div class="relative h-64 flex items-center justify-center">
                            <canvas id="statusChart"></canvas>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Clinic Visit Categories Bar Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 col-span-1">
                        <h3 class="text-md font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-list text-indigo-500 mr-2"></i>Visits by Category</h3>
                        <div class="relative h-64">
                            <?php if (!empty($cat_counts)): ?>
                                <canvas id="categoriesChart"></canvas>
                            <?php else: ?>
                                <div class="h-full flex items-center justify-center text-gray-400 text-sm">No visit records found</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Counseling Sessions Categories Bar Chart -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 col-span-1">
                        <h3 class="text-md font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-graduation-cap text-purple-500 mr-2"></i>Counseling Type</h3>
                        <div class="relative h-64">
                            <?php if (!empty($counsel_counts)): ?>
                                <canvas id="counselingChart"></canvas>
                            <?php else: ?>
                                <div class="h-full flex items-center justify-center text-gray-400 text-sm">No counseling records found</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Top Complaints list -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-100 dark:border-gray-700 p-6 col-span-1">
                        <h3 class="text-md font-bold text-gray-800 dark:text-white mb-4"><i class="fas fa-exclamation-triangle text-amber-500 mr-2"></i>Top Chief Complaints</h3>
                        <div class="space-y-4">
                            <?php if (!empty($top_complaints)): ?>
                                <?php foreach ($top_complaints as $index => $comp): ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700/25 rounded-lg text-sm">
                                        <div class="flex items-center space-x-3">
                                            <span class="w-6 h-6 rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400 flex items-center justify-center text-xs font-bold"><?php echo $index + 1; ?></span>
                                            <span class="font-medium text-gray-800 dark:text-gray-200"><?php echo htmlspecialchars($comp['complaint']); ?></span>
                                        </div>
                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300"><?php echo $comp['count']; ?> cases</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="h-full flex items-center justify-center text-gray-400 text-sm py-12">No complaints logged yet</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </main>
        
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Load Chart.js from CDN -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    // Colors helper
    const isDark = document.documentElement.classList.contains('dark');
    const gridColor = isDark ? '#374151' : '#E5E7EB';
    const textColor = isDark ? '#9CA3AF' : '#4B5563';

    // 1. Weekly Trend Chart
    const trendCtx = document.getElementById('weeklyTrendChart').getContext('2d');
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode(array_values($trend_labels)); ?>,
            datasets: [{
                label: 'Clinic Visits',
                data: <?php echo json_encode(array_values($trend_counts)); ?>,
                borderColor: '#3B82F6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                fill: true,
                tension: 0.3,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { grid: { color: gridColor }, ticks: { color: textColor } },
                y: { grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1 } }
            }
        }
    });

    // 2. Status Pie Chart
    const statusCtx = document.getElementById('statusChart').getContext('2d');
    new Chart(statusCtx, {
        type: 'doughnut',
        data: {
            labels: ['Active', 'Resolved', 'Referred'],
            datasets: [{
                data: [<?php echo "$active_cases, $resolved_cases, $referred_cases"; ?>],
                backgroundColor: ['#F59E0B', '#10B981', '#3B82F6'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: textColor }
                }
            }
        }
    });

    // 3. Category Bar Chart
    <?php if (!empty($cat_counts)): ?>
    const catCtx = document.getElementById('categoriesChart').getContext('2d');
    new Chart(catCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($cat_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($cat_counts); ?>,
                backgroundColor: '#6366F1',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: textColor } },
                y: { grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1 } }
            }
        }
    });
    <?php endif; ?>

    // 4. Counseling Sessions Bar Chart
    <?php if (!empty($counsel_counts)): ?>
    const counselCtx = document.getElementById('counselingChart').getContext('2d');
    new Chart(counselCtx, {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($counsel_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($counsel_counts); ?>,
                backgroundColor: '#8B5CF6',
                borderRadius: 5
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: textColor } },
                y: { grid: { color: gridColor }, ticks: { color: textColor, stepSize: 1 } }
            }
        }
    });
    <?php endif; ?>
});
</script>
