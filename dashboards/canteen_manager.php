<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has canteen_manager role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'canteen_manager') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get canteen statistics
$total_menu_items = 45;
$todays_orders = 156;
$revenue_today = 2340;
$pending_orders = 12;
$inventory_alerts = 5;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Canteen Manager Dashboard - School Management System</title>
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
                        <h1 class="text-2xl font-bold">Canteen Manager Dashboard</h1>
                        <p class="text-white/80 mt-1">Manage food services, orders, and inventory</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white/70">Welcome back,</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Menu Items -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Menu Items</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $total_menu_items; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-utensils text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Today's Orders -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Today's Orders</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $todays_orders; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-shopping-cart text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Revenue Today -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Revenue Today</p>
                            <p class="text-3xl font-bold text-purple-600">₵<?php echo number_format($revenue_today); ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-money-bill-wave text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Pending Orders -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Orders</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $pending_orders; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Inventory Alerts -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Inventory Alerts</p>
                            <p class="text-3xl font-bold text-red-600"><?php echo $inventory_alerts; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Popular Items & Recent Orders -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Popular Items -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Popular Items Today</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-yellow-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-hamburger text-yellow-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Chicken Burger</p>
                                        <p class="text-sm text-gray-500">₵15.00</p>
                                    </div>
                                </div>
                                <span class="text-sm font-semibold text-green-600">42 sold</span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-pizza-slice text-red-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Margherita Pizza</p>
                                        <p class="text-sm text-gray-500">₵25.00</p>
                                    </div>
                                </div>
                                <span class="text-sm font-semibold text-green-600">38 sold</span>
                            </div>
                            
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center">
                                        <i class="fas fa-coffee text-blue-600"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-gray-900">Fresh Juice</p>
                                        <p class="text-sm text-gray-500">₵8.00</p>
                                    </div>
                                </div>
                                <span class="text-sm font-semibold text-green-600">65 sold</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Inventory Alerts -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Inventory Alerts</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center p-3 bg-red-50 rounded-lg">
                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-exclamation text-red-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-red-900">Low Stock</p>
                                    <p class="text-sm text-red-600">Chicken patties - 5 units left</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-3 bg-yellow-50 rounded-lg">
                                <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-clock text-yellow-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-yellow-900">Expiring Soon</p>
                                    <p class="text-sm text-yellow-600">Fresh vegetables - 2 days left</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-3 bg-orange-50 rounded-lg">
                                <div class="w-8 h-8 bg-orange-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-shopping-cart text-orange-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-orange-900">Reorder Required</p>
                                    <p class="text-sm text-orange-600">Bread rolls - Order now</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <a href="../canteen/menu.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-utensils text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Manage Menu</h3>
                        <p class="text-gray-600 text-sm">Add, edit, and organize menu items</p>
                    </div>
                </a>

                <a href="../canteen/orders.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-shopping-cart text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">View Orders</h3>
                        <p class="text-gray-600 text-sm">Process and track orders</p>
                    </div>
                </a>

                <a href="../canteen/inventory.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-boxes text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Inventory</h3>
                        <p class="text-gray-600 text-sm">Manage stock and supplies</p>
                    </div>
                </a>

                <a href="../canteen/reports.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-chart-bar text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Sales Reports</h3>
                        <p class="text-gray-600 text-sm">View sales and analytics</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
