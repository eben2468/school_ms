<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'accountant'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get current academic year (using default if settings table doesn't exist)
try {
    $current_year_query = "SELECT value as setting_value FROM settings WHERE key_name = 'academic_year' LIMIT 1";
    $current_year_stmt = $db->query($current_year_query);
    $current_year_result = $current_year_stmt->fetch(PDO::FETCH_ASSOC);
    $current_academic_year = $current_year_result ? $current_year_result['setting_value'] : date('Y') . '-' . (date('Y') + 1);
} catch (PDOException $e) {
    // Fallback if settings table doesn't exist
    $current_academic_year = date('Y') . '-' . (date('Y') + 1);
}

// Get financial statistics
$stats_query = "SELECT 
    COUNT(DISTINCT fs.id) as total_fee_structures,
    COUNT(DISTINCT sp.id) as total_students,
    SUM(CASE WHEN sp.payment_status = 'paid' THEN fs.amount ELSE 0 END) as total_collected,
    SUM(CASE WHEN sp.payment_status = 'pending' THEN fs.amount ELSE 0 END) as total_pending,
    SUM(CASE WHEN sp.payment_status = 'overdue' THEN fs.amount ELSE 0 END) as total_overdue
    FROM fee_structures fs
    LEFT JOIN student_payments sp ON fs.id = sp.fee_structure_id
    WHERE fs.academic_year = :academic_year";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':academic_year', $current_academic_year);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Get recent payments
$recent_payments_query = "SELECT sp.*, fs.fee_type, fs.amount, u.name as student_name, c.name as class_name
    FROM student_payments sp
    JOIN fee_structures fs ON sp.fee_structure_id = fs.id
    JOIN users u ON sp.student_id = u.id
    LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    ORDER BY sp.payment_date DESC
    LIMIT 10";
$recent_payments_stmt = $db->query($recent_payments_query);
$recent_payments = $recent_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get overdue payments
$overdue_payments_query = "SELECT sp.*, fs.fee_type, fs.amount, u.name as student_name, c.name as class_name,
    DATEDIFF(CURDATE(), sp.due_date) as days_overdue
    FROM student_payments sp
    JOIN fee_structures fs ON sp.fee_structure_id = fs.id
    JOIN users u ON sp.student_id = u.id
    LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    LEFT JOIN classes c ON sc.class_id = c.id
    WHERE sp.payment_status = 'overdue'
    ORDER BY sp.due_date ASC
    LIMIT 10";
$overdue_payments_stmt = $db->query($overdue_payments_query);
$overdue_payments = $overdue_payments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly collection data for chart
$monthly_data_query = "SELECT 
    DATE_FORMAT(sp.payment_date, '%Y-%m') as month,
    SUM(sp.amount_paid) as total_collected
    FROM student_payments sp
    WHERE sp.payment_status = 'paid' 
    AND sp.payment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(sp.payment_date, '%Y-%m')
    ORDER BY month";
$monthly_data_stmt = $db->query($monthly_data_query);
$monthly_data = $monthly_data_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Finance Management</h1>
                <div class="flex space-x-3">
                    <a href="reports.php" class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-chart-line mr-2"></i>Reports
                    </a>
                    <a href="fee_structures.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-cog mr-2"></i>Fee Structures
                    </a>
                    <a href="payments.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-money-bill-wave mr-2"></i>Payments
                    </a>
                </div>
            </div>

            <!-- Financial Overview -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100">
                            <i class="fas fa-dollar-sign text-green-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Collected</p>
                            <p class="text-2xl font-semibold text-green-600">₵<?php echo number_format($stats['total_collected'] ?? 0, 2); ?></p>
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
                            <p class="text-2xl font-semibold text-yellow-600">₵<?php echo number_format($stats['total_pending'] ?? 0, 2); ?></p>
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
                            <p class="text-2xl font-semibold text-red-600">₵<?php echo number_format($stats['total_overdue'] ?? 0, 2); ?></p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                        <div class="ml-4">
                            <p class="text-sm font-medium text-gray-500">Total Students</p>
                            <p class="text-2xl font-semibold text-blue-600"><?php echo number_format($stats['total_students'] ?? 0); ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Recent Payments -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Recent Payments</h2>
                        <a href="payments.php" class="text-blue-600 hover:text-blue-800 text-sm">View All</a>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_payments)): ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_payments as $payment): ?>
                            <div class="flex justify-between items-center py-3 border-b border-gray-100 last:border-0">
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($payment['fee_type']); ?> - <?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="text-xs text-gray-400">
                                        <?php echo date('M j, Y', strtotime($payment['payment_date'])); ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-green-600">₵<?php echo number_format($payment['amount_paid'], 2); ?></div>
                                    <div class="text-xs text-gray-500"><?php echo ucfirst($payment['payment_method']); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-receipt text-gray-400 text-3xl mb-2"></i>
                            <p class="text-gray-500">No recent payments</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Overdue Payments -->
                <div class="bg-white rounded-lg shadow">
                    <div class="px-6 py-4 border-b border-gray-200 flex justify-between items-center">
                        <h2 class="text-lg font-semibold text-gray-800">Overdue Payments</h2>
                        <a href="payments.php?status=overdue" class="text-red-600 hover:text-red-800 text-sm">View All</a>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($overdue_payments)): ?>
                        <div class="space-y-4">
                            <?php foreach ($overdue_payments as $payment): ?>
                            <div class="flex justify-between items-center py-3 border-b border-gray-100 last:border-0">
                                <div>
                                    <div class="font-medium text-gray-900"><?php echo htmlspecialchars($payment['student_name']); ?></div>
                                    <div class="text-sm text-gray-500">
                                        <?php echo htmlspecialchars($payment['fee_type']); ?> - <?php echo htmlspecialchars($payment['class_name'] ?? 'N/A'); ?>
                                    </div>
                                    <div class="text-xs text-red-500">
                                        <?php echo $payment['days_overdue']; ?> day(s) overdue
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-semibold text-red-600">₵<?php echo number_format($payment['amount'], 2); ?></div>
                                    <div class="text-xs text-gray-500">Due: <?php echo date('M j', strtotime($payment['due_date'])); ?></div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-check-circle text-green-400 text-3xl mb-2"></i>
                            <p class="text-gray-500">No overdue payments</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Monthly Collection Chart -->
            <?php if (!empty($monthly_data)): ?>
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Monthly Collection Trend</h2>
                </div>
                <div class="p-6">
                    <canvas id="monthlyChart" width="400" height="200"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <!-- Quick Actions -->
            <div class="mt-8 bg-white rounded-lg shadow">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-800">Quick Actions</h2>
                </div>
                <div class="p-6">
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <a href="collect_payment.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-green-100 rounded-lg mr-3">
                                <i class="fas fa-money-bill-wave text-green-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Collect Payment</div>
                                <div class="text-sm text-gray-500">Record a new payment</div>
                            </div>
                        </a>
                        
                        <a href="generate_invoice.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-blue-100 rounded-lg mr-3">
                                <i class="fas fa-file-invoice text-blue-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Generate Invoice</div>
                                <div class="text-sm text-gray-500">Create fee invoices</div>
                            </div>
                        </a>
                        
                        <a href="send_reminders.php" class="flex items-center p-4 border border-gray-200 rounded-lg hover:bg-gray-50">
                            <div class="p-2 bg-yellow-100 rounded-lg mr-3">
                                <i class="fas fa-bell text-yellow-600"></i>
                            </div>
                            <div>
                                <div class="font-medium text-gray-900">Send Reminders</div>
                                <div class="text-sm text-gray-500">Notify overdue payments</div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($monthly_data)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const ctx = document.getElementById('monthlyChart').getContext('2d');
const monthlyChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: <?php echo json_encode(array_map(function($item) { return date('M Y', strtotime($item['month'] . '-01')); }, $monthly_data)); ?>,
        datasets: [{
            label: 'Monthly Collections ($)',
            data: <?php echo json_encode(array_map(function($item) { return floatval($item['total_collected']); }, $monthly_data)); ?>,
            borderColor: 'rgb(59, 130, 246)',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.1,
            fill: true
        }]
    },
    options: {
        responsive: true,
        plugins: {
            legend: {
                display: false
            }
        },
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
</script>
<?php endif; ?>

            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
