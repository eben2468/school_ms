<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'finance_manager'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get date filters
$start_date = filter_input(INPUT_GET, 'start_date', FILTER_SANITIZE_STRING) ?: date('Y-m-01');
$end_date = filter_input(INPUT_GET, 'end_date', FILTER_SANITIZE_STRING) ?: date('Y-m-t');

// Get financial statistics
try {
    $stats_query = "SELECT 
        SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END) as total_collected,
        SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END) as total_pending,
        SUM(CASE WHEN status = 'overdue' THEN amount ELSE 0 END) as total_overdue,
        COUNT(CASE WHEN status = 'paid' THEN 1 END) as paid_count,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_count,
        COUNT(CASE WHEN status = 'overdue' THEN 1 END) as overdue_count
        FROM fee_payments 
        WHERE payment_date BETWEEN :start_date AND :end_date";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':start_date', $start_date);
    $stats_stmt->bindParam(':end_date', $end_date);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [
        'total_collected' => 0,
        'total_pending' => 0,
        'total_overdue' => 0,
        'paid_count' => 0,
        'pending_count' => 0,
        'overdue_count' => 0
    ];
}

// Get monthly collection data for chart
try {
    $monthly_query = "SELECT 
        DATE_FORMAT(payment_date, '%Y-%m') as month,
        SUM(amount) as total_amount,
        COUNT(*) as payment_count
        FROM fee_payments 
        WHERE status = 'paid' 
        AND payment_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(payment_date, '%Y-%m')
        ORDER BY month";
    $monthly_stmt = $db->query($monthly_query);
    $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $monthly_data = [];
}

// Get fee structure breakdown
try {
    $breakdown_query = "SELECT 
        fs.fee_name,
        SUM(CASE WHEN fp.status = 'paid' THEN fp.amount ELSE 0 END) as collected,
        SUM(CASE WHEN fp.status = 'pending' THEN fp.amount ELSE 0 END) as pending,
        COUNT(fp.id) as total_payments
        FROM fee_structures fs
        LEFT JOIN fee_payments fp ON fs.id = fp.fee_structure_id
        WHERE fp.payment_date BETWEEN :start_date AND :end_date
        GROUP BY fs.id, fs.fee_name
        ORDER BY collected DESC";
    $breakdown_stmt = $db->prepare($breakdown_query);
    $breakdown_stmt->bindParam(':start_date', $start_date);
    $breakdown_stmt->bindParam(':end_date', $end_date);
    $breakdown_stmt->execute();
    $fee_breakdown = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $fee_breakdown = [];
}

// Get class-wise collection
try {
    $class_query = "SELECT 
        c.name as class_name,
        c.grade_level,
        SUM(CASE WHEN fp.status = 'paid' THEN fp.amount ELSE 0 END) as collected,
        SUM(CASE WHEN fp.status = 'pending' THEN fp.amount ELSE 0 END) as pending,
        COUNT(DISTINCT s.id) as student_count
        FROM classes c
        LEFT JOIN students s ON c.id = s.class_id
        LEFT JOIN fee_payments fp ON s.id = fp.student_id
        WHERE fp.payment_date BETWEEN :start_date AND :end_date
        GROUP BY c.id, c.name, c.grade_level
        ORDER BY c.grade_level, c.name";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->bindParam(':start_date', $start_date);
    $class_stmt->bindParam(':end_date', $end_date);
    $class_stmt->execute();
    $class_data = $class_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $class_data = [];
}

$title = "Financial Reports";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800">Financial Reports</h1>
                    <div class="flex space-x-3">
                        <a href="index.php" class="text-blue-600 hover:text-blue-800">
                            <i class="fas fa-arrow-left mr-2"></i>Back to Finance
                        </a>
                        <button onclick="exportReport()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-download mr-2"></i>Export PDF
                        </button>
                    </div>
                </div>

                <!-- Date Filter -->
                <div class="bg-white rounded-lg shadow p-6 mb-6">
                    <form method="GET" class="flex flex-wrap gap-4 items-end">
                        <div>
                            <label for="start_date" class="block text-sm font-medium text-gray-700">Start Date</label>
                            <input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        <div>
                            <label for="end_date" class="block text-sm font-medium text-gray-700">End Date</label>
                            <input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md">
                        </div>
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-filter mr-2"></i>Apply Filter
                        </button>
                    </form>
                </div>

                <!-- Summary Statistics -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-green-100">
                                <i class="fas fa-money-bill-wave text-green-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Total Collected</p>
                                <p class="text-2xl font-semibold text-green-600">₵<?php echo number_format($stats['total_collected'], 2); ?></p>
                                <p class="text-sm text-gray-500"><?php echo $stats['paid_count']; ?> payments</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-yellow-100">
                                <i class="fas fa-clock text-yellow-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Pending</p>
                                <p class="text-2xl font-semibold text-yellow-600">₵<?php echo number_format($stats['total_pending'], 2); ?></p>
                                <p class="text-sm text-gray-500"><?php echo $stats['pending_count']; ?> payments</p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-lg shadow p-6">
                        <div class="flex items-center">
                            <div class="p-3 rounded-full bg-red-100">
                                <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-500">Overdue</p>
                                <p class="text-2xl font-semibold text-red-600">₵<?php echo number_format($stats['total_overdue'], 2); ?></p>
                                <p class="text-sm text-gray-500"><?php echo $stats['overdue_count']; ?> payments</p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- Monthly Collection Chart -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Monthly Collection Trend</h2>
                        <div class="h-64">
                            <canvas id="monthlyChart"></canvas>
                        </div>
                    </div>

                    <!-- Fee Structure Breakdown -->
                    <div class="bg-white rounded-lg shadow p-6">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Fee Structure Breakdown</h2>
                        <div class="space-y-4">
                            <?php if (!empty($fee_breakdown)): ?>
                                <?php foreach ($fee_breakdown as $fee): ?>
                                <div class="flex justify-between items-center p-3 border border-gray-200 rounded-lg">
                                    <div>
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($fee['fee_name']); ?></p>
                                        <p class="text-sm text-gray-500"><?php echo $fee['total_payments']; ?> payments</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-semibold text-green-600">₵<?php echo number_format($fee['collected'], 2); ?></p>
                                        <?php if ($fee['pending'] > 0): ?>
                                        <p class="text-sm text-yellow-600">₵<?php echo number_format($fee['pending'], 2); ?> pending</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                            <p class="text-gray-500 text-center py-8">No fee data available for the selected period.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Class-wise Collection -->
                <div class="mt-8 bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800">Class-wise Collection</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Students</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Collected</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pending</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Collection Rate</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (!empty($class_data)): ?>
                                    <?php foreach ($class_data as $class): ?>
                                    <?php 
                                    $total = $class['collected'] + $class['pending'];
                                    $rate = $total > 0 ? ($class['collected'] / $total) * 100 : 0;
                                    ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['class_name']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                            <?php echo $class['student_count']; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-green-600">
                                            ₵<?php echo number_format($class['collected'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-yellow-600">
                                            ₵<?php echo number_format($class['pending'], 2); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-16 bg-gray-200 rounded-full h-2 mr-2">
                                                    <div class="bg-green-600 h-2 rounded-full" style="width: <?php echo $rate; ?>%"></div>
                                                </div>
                                                <span class="text-sm text-gray-900"><?php echo number_format($rate, 1); ?>%</span>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                <tr>
                                    <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                        No class data available for the selected period.
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Summary Report -->
                <div class="mt-8 bg-white rounded-lg shadow p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-4">Report Summary</h2>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Collection Performance</h3>
                            <ul class="text-sm text-gray-600 space-y-1">
                                <li>• Total Revenue: ₵<?php echo number_format($stats['total_collected'], 2); ?></li>
                                <li>• Outstanding Amount: ₵<?php echo number_format($stats['total_pending'] + $stats['total_overdue'], 2); ?></li>
                                <li>• Collection Rate: <?php echo $stats['total_collected'] + $stats['total_pending'] > 0 ? number_format(($stats['total_collected'] / ($stats['total_collected'] + $stats['total_pending'])) * 100, 1) : 0; ?>%</li>
                                <li>• Total Transactions: <?php echo $stats['paid_count'] + $stats['pending_count'] + $stats['overdue_count']; ?></li>
                            </ul>
                        </div>
                        <div>
                            <h3 class="font-medium text-gray-900 mb-2">Period Information</h3>
                            <ul class="text-sm text-gray-600 space-y-1">
                                <li>• Report Period: <?php echo date('M j, Y', strtotime($start_date)); ?> - <?php echo date('M j, Y', strtotime($end_date)); ?></li>
                                <li>• Generated On: <?php echo date('M j, Y g:i A'); ?></li>
                                <li>• Generated By: <?php echo htmlspecialchars($_SESSION['name']); ?></li>
                                <li>• Report Type: Financial Summary</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Monthly Collection Chart
const ctx = document.getElementById('monthlyChart').getContext('2d');
const monthlyChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: [<?php echo "'" . implode("','", array_column($monthly_data, 'month')) . "'"; ?>],
        datasets: [{
            label: 'Monthly Collection (₵)',
            data: [<?php echo implode(',', array_column($monthly_data, 'total_amount')); ?>],
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '₵' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

function exportReport() {
    window.print();
}
</script>
