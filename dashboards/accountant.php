<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has accountant role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'accountant') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get financial statistics
$total_revenue = 125000;
$pending_payments = 18500;
$expenses_month = 45000;
$outstanding_fees = 32000;
$processed_today = 8500;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accountant Dashboard - School Management System</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="../assets/css/dynamic-theme.php">
</head>
<body class="bg-gray-50">
    <?php include '../includes/header.php'; ?>
    
    <div class="flex">
        <?php include '../includes/sidebar.php'; ?>
        
        <main class="flex-1 ml-0 lg:ml-72 p-6" style="margin-top: 80px;">
            <!-- Page Header -->
            <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h1 class="text-2xl font-bold">Accountant Dashboard</h1>
                        <p class="text-white/80 mt-1">Manage school finances, payments, and accounting</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white/70">Welcome back,</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Financial Overview Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Total Revenue -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Revenue</p>
                            <p class="text-3xl font-bold text-green-600">₵<?php echo number_format($total_revenue); ?></p>
                            <p class="text-xs text-green-500">+12% this month</p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chart-line text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Pending Payments -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Payments</p>
                            <p class="text-3xl font-bold text-orange-600">₵<?php echo number_format($pending_payments); ?></p>
                            <p class="text-xs text-orange-500">45 transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Monthly Expenses -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Monthly Expenses</p>
                            <p class="text-3xl font-bold text-red-600">₵<?php echo number_format($expenses_month); ?></p>
                            <p class="text-xs text-red-500">-5% from last month</p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-money-bill-wave text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Outstanding Fees -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Outstanding Fees</p>
                            <p class="text-3xl font-bold text-purple-600">₵<?php echo number_format($outstanding_fees); ?></p>
                            <p class="text-xs text-purple-500">128 students</p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Processed Today -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Processed Today</p>
                            <p class="text-3xl font-bold text-blue-600">₵<?php echo number_format($processed_today); ?></p>
                            <p class="text-xs text-blue-500">23 transactions</p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Financial Summary & Recent Transactions -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Monthly Financial Summary -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Monthly Financial Summary</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-arrow-up text-green-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-green-900">Total Income</p>
                                        <p class="text-sm text-green-600">Tuition & Other Fees</p>
                                    </div>
                                </div>
                                <span class="text-lg font-bold text-green-600">₵<?php echo number_format($total_revenue); ?></span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-arrow-down text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-red-900">Total Expenses</p>
                                        <p class="text-sm text-red-600">Operational Costs</p>
                                    </div>
                                </div>
                                <span class="text-lg font-bold text-red-600">₵<?php echo number_format($expenses_month); ?></span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-calculator text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-blue-900">Net Profit</p>
                                        <p class="text-sm text-blue-600">This Month</p>
                                    </div>
                                </div>
                                <span class="text-lg font-bold text-blue-600">₵<?php echo number_format($total_revenue - $expenses_month); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recent Transactions -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Recent Transactions</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-plus text-green-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Tuition Payment</p>
                                        <p class="text-sm text-gray-500">John Doe - STU20254927</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-green-600">+₵1,200</p>
                                    <p class="text-xs text-gray-500">2 hours ago</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-minus text-red-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Utility Bill</p>
                                        <p class="text-sm text-gray-500">Electricity - June 2025</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-red-600">-₵850</p>
                                    <p class="text-xs text-gray-500">5 hours ago</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-plus text-green-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Library Fee</p>
                                        <p class="text-sm text-gray-500">Sarah Johnson - STU20254928</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-medium text-green-600">+₵50</p>
                                    <p class="text-xs text-gray-500">1 day ago</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <a href="../finance/payments.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-money-bill-wave text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Process Payments</h3>
                        <p class="text-gray-600 text-sm">Handle fee payments and transactions</p>
                    </div>
                </a>

                <a href="../finance/expenses.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-receipt text-red-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Manage Expenses</h3>
                        <p class="text-gray-600 text-sm">Track and record expenses</p>
                    </div>
                </a>

                <a href="../finance/reports.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-chart-bar text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Financial Reports</h3>
                        <p class="text-gray-600 text-sm">Generate financial statements</p>
                    </div>
                </a>

                <a href="../finance/budgets.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calculator text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Budget Planning</h3>
                        <p class="text-gray-600 text-sm">Plan and manage budgets</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
