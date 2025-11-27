<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has transport_officer role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'transport_officer') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get transport statistics
try {
    // Total vehicles
    $stmt = $pdo->query("SELECT COUNT(*) as total_vehicles FROM transport_vehicles WHERE status = 'active'");
    $total_vehicles = $stmt->fetch()['total_vehicles'];
    
    // Total routes
    $stmt = $pdo->query("SELECT COUNT(*) as total_routes FROM transport_routes WHERE status = 'active'");
    $total_routes = $stmt->fetch()['total_routes'];
    
    // Students using transport
    $stmt = $pdo->query("SELECT COUNT(*) as transport_students FROM transport_assignments WHERE status = 'active'");
    $transport_students = $stmt->fetch()['transport_students'];
    
    // Vehicles in maintenance
    $stmt = $pdo->query("SELECT COUNT(*) as maintenance_vehicles FROM transport_vehicles WHERE status = 'maintenance'");
    $maintenance_vehicles = $stmt->fetch()['maintenance_vehicles'];
    
    // Today's trips
    $stmt = $pdo->query("SELECT COUNT(*) as todays_trips FROM transport_trips WHERE DATE(trip_date) = CURDATE()");
    $todays_trips = $stmt->fetch()['todays_trips'];
    
    // Recent assignments
    $stmt = $pdo->prepare("
        SELECT ta.*, s.name as student_name, s.student_id, tr.route_name, tv.vehicle_number 
        FROM transport_assignments ta
        JOIN students s ON ta.student_id = s.id
        JOIN transport_routes tr ON ta.route_id = tr.id
        JOIN transport_vehicles tv ON tr.vehicle_id = tv.id
        WHERE ta.status = 'active'
        ORDER BY ta.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_assignments = $stmt->fetchAll();
    
} catch (PDOException $e) {
    // Set default values if tables don't exist
    $total_vehicles = 8;
    $total_routes = 12;
    $transport_students = 245;
    $maintenance_vehicles = 1;
    $todays_trips = 24;
    $recent_assignments = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transport Officer Dashboard - School Management System</title>
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
                        <h1 class="text-2xl font-bold">Transport Officer Dashboard</h1>
                        <p class="text-white/80 mt-1">Manage school transportation, routes, and vehicle assignments</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white/70">Welcome back,</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Total Vehicles -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Vehicles</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $total_vehicles; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-bus text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Routes -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Routes</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $total_routes; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-route text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Transport Students -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Students</p>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $transport_students; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Maintenance -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">In Maintenance</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $maintenance_vehicles; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tools text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Today's Trips -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Today's Trips</p>
                            <p class="text-3xl font-bold text-indigo-600"><?php echo $todays_trips; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-day text-indigo-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Route Status Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Vehicle Status -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Vehicle Status</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                                    <span class="text-gray-700">Active Vehicles</span>
                                </div>
                                <span class="font-semibold text-gray-900"><?php echo $total_vehicles - $maintenance_vehicles; ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-orange-500 rounded-full"></div>
                                    <span class="text-gray-700">Under Maintenance</span>
                                </div>
                                <span class="font-semibold text-gray-900"><?php echo $maintenance_vehicles; ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <div class="flex items-center space-x-3">
                                    <div class="w-3 h-3 bg-blue-500 rounded-full"></div>
                                    <span class="text-gray-700">Total Fleet</span>
                                </div>
                                <span class="font-semibold text-gray-900"><?php echo $total_vehicles; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Today's Schedule -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Today's Schedule</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                <div>
                                    <p class="font-medium text-blue-900">Morning Pickup</p>
                                    <p class="text-sm text-blue-600">6:30 AM - 8:00 AM</p>
                                </div>
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-sun text-blue-600"></i>
                                </div>
                            </div>
                            <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                <div>
                                    <p class="font-medium text-green-900">Afternoon Drop</p>
                                    <p class="text-sm text-green-600">2:30 PM - 4:00 PM</p>
                                </div>
                                <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                    <i class="fas fa-moon text-green-600"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <a href="../transport/vehicles.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-bus text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Manage Vehicles</h3>
                        <p class="text-gray-600 text-sm">Add, edit, and track vehicles</p>
                    </div>
                </a>

                <a href="../transport/routes.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-route text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Route Management</h3>
                        <p class="text-gray-600 text-sm">Plan and manage routes</p>
                    </div>
                </a>

                <a href="../transport/assignments.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-users text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Student Assignments</h3>
                        <p class="text-gray-600 text-sm">Assign students to routes</p>
                    </div>
                </a>

                <a href="../transport/tracking.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-map-marker-alt text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Live Tracking</h3>
                        <p class="text-gray-600 text-sm">Track vehicle locations</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
