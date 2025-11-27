<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has hostel_warden role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'hostel_warden') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get hostel statistics
try {
    // Total hostel blocks
    $stmt = $pdo->query("SELECT COUNT(*) as total_blocks FROM hostel_blocks WHERE status = 'active'");
    $total_blocks = $stmt->fetch()['total_blocks'];
    
    // Total rooms
    $stmt = $pdo->query("SELECT COUNT(*) as total_rooms FROM hostel_rooms WHERE status = 'active'");
    $total_rooms = $stmt->fetch()['total_rooms'];
    
    // Occupied rooms
    $stmt = $pdo->query("SELECT COUNT(DISTINCT room_id) as occupied_rooms FROM hostel_allocations WHERE status = 'active'");
    $occupied_rooms = $stmt->fetch()['occupied_rooms'];
    
    // Available rooms
    $available_rooms = $total_rooms - $occupied_rooms;
    
    // Total allocated students
    $stmt = $pdo->query("SELECT COUNT(*) as allocated_students FROM hostel_allocations WHERE status = 'active'");
    $allocated_students = $stmt->fetch()['allocated_students'];
    
    // Pending maintenance requests
    $stmt = $pdo->query("SELECT COUNT(*) as pending_requests FROM hostel_maintenance WHERE status = 'pending'");
    $pending_requests = $stmt->fetch()['pending_requests'];
    
    // Recent allocations
    $stmt = $pdo->prepare("
        SELECT ha.*, s.name as student_name, s.student_id, hr.room_number, hb.block_name 
        FROM hostel_allocations ha
        JOIN students s ON ha.student_id = s.id
        JOIN hostel_rooms hr ON ha.room_id = hr.id
        JOIN hostel_blocks hb ON hr.block_id = hb.id
        WHERE ha.status = 'active'
        ORDER BY ha.created_at DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_allocations = $stmt->fetchAll();
    
} catch (PDOException $e) {
    // Set default values if tables don't exist
    $total_blocks = 0;
    $total_rooms = 0;
    $occupied_rooms = 0;
    $available_rooms = 0;
    $allocated_students = 0;
    $pending_requests = 0;
    $recent_allocations = [];
}

$occupancy_rate = $total_rooms > 0 ? round(($occupied_rooms / $total_rooms) * 100, 1) : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hostel Warden Dashboard - School Management System</title>
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
                        <h1 class="text-2xl font-bold">Hostel Warden Dashboard</h1>
                        <p class="text-white/80 mt-1">Manage hostel blocks, rooms, and student accommodations</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white/70">Welcome back,</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Blocks -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Blocks</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $total_blocks; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-building text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Rooms -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Rooms</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $total_rooms; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-door-open text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Occupied Rooms -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Occupied Rooms</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $occupied_rooms; ?></p>
                            <p class="text-xs text-gray-500"><?php echo $occupancy_rate; ?>% occupancy</p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-bed text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Available Rooms -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Available Rooms</p>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $available_rooms; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-door-closed text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Additional Stats Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
                <!-- Allocated Students -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Allocated Students</p>
                            <p class="text-3xl font-bold text-indigo-600"><?php echo $allocated_students; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-indigo-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Pending Maintenance -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Maintenance</p>
                            <p class="text-3xl font-bold text-red-600"><?php echo $pending_requests; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tools text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Allocations -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-100 mb-8">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Recent Room Allocations</h3>
                </div>
                <div class="p-6">
                    <?php if (empty($recent_allocations)): ?>
                        <div class="text-center py-8">
                            <i class="fas fa-bed text-gray-300 text-4xl mb-4"></i>
                            <p class="text-gray-500">No recent allocations found</p>
                        </div>
                    <?php else: ?>
                        <div class="space-y-4">
                            <?php foreach ($recent_allocations as $allocation): ?>
                                <div class="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center">
                                            <i class="fas fa-user text-blue-600"></i>
                                        </div>
                                        <div>
                                            <p class="font-medium text-gray-900"><?php echo htmlspecialchars($allocation['student_name']); ?></p>
                                            <p class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($allocation['student_id']); ?></p>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p class="font-medium text-gray-900"><?php echo htmlspecialchars($allocation['block_name']); ?> - Room <?php echo htmlspecialchars($allocation['room_number']); ?></p>
                                        <p class="text-sm text-gray-500"><?php echo date('M j, Y', strtotime($allocation['created_at'])); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <a href="../hostel/blocks.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-building text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Manage Blocks</h3>
                        <p class="text-gray-600 text-sm">Add, edit, and manage hostel blocks</p>
                    </div>
                </a>

                <a href="../hostel/allocations.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-bed text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Room Allocations</h3>
                        <p class="text-gray-600 text-sm">Assign students to rooms</p>
                    </div>
                </a>

                <a href="../hostel/maintenance.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-tools text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Maintenance</h3>
                        <p class="text-gray-600 text-sm">Handle maintenance requests</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
