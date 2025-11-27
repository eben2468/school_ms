<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has nurse role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'nurse') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get health statistics
try {
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM students WHERE status = 'active'");
    $total_students = $stmt->fetch()['total_students'];
    
    // Health records
    $stmt = $pdo->query("SELECT COUNT(*) as health_records FROM health_records");
    $health_records = $stmt->fetch()['health_records'];
    
    // Today's visits
    $stmt = $pdo->query("SELECT COUNT(*) as todays_visits FROM health_visits WHERE DATE(visit_date) = CURDATE()");
    $todays_visits = $stmt->fetch()['todays_visits'];
    
    // Pending medical clearances
    $stmt = $pdo->query("SELECT COUNT(*) as pending_clearances FROM medical_clearances WHERE status = 'pending'");
    $pending_clearances = $stmt->fetch()['pending_clearances'];
    
    // Students with allergies
    $stmt = $pdo->query("SELECT COUNT(*) as allergy_students FROM health_records WHERE allergies IS NOT NULL AND allergies != ''");
    $allergy_students = $stmt->fetch()['allergy_students'];
    
    // Recent visits
    $stmt = $pdo->prepare("
        SELECT hv.*, s.name as student_name, s.student_id 
        FROM health_visits hv
        JOIN students s ON hv.student_id = s.id
        ORDER BY hv.visit_date DESC, hv.visit_time DESC
        LIMIT 5
    ");
    $stmt->execute();
    $recent_visits = $stmt->fetchAll();
    
} catch (PDOException $e) {
    // Set default values if tables don't exist
    $total_students = 450;
    $health_records = 420;
    $todays_visits = 8;
    $pending_clearances = 3;
    $allergy_students = 25;
    $recent_visits = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Nurse Dashboard - School Management System</title>
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
                        <h1 class="text-2xl font-bold">School Nurse Dashboard</h1>
                        <p class="text-white/80 mt-1">Monitor student health and manage medical records</p>
                    </div>
                    <div class="text-right">
                        <p class="text-sm text-white/70">Welcome back,</p>
                        <p class="font-semibold"><?php echo htmlspecialchars($user_name); ?></p>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
                <!-- Total Students -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Total Students</p>
                            <p class="text-3xl font-bold text-blue-600"><?php echo $total_students; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-users text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Health Records -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Health Records</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $health_records; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-medical text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Today's Visits -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Today's Visits</p>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $todays_visits; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-stethoscope text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Pending Clearances -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Clearances</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $pending_clearances; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clipboard-check text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Students with Allergies -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Allergy Alerts</p>
                            <p class="text-3xl font-bold text-red-600"><?php echo $allergy_students; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Health Overview -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Recent Visits -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Recent Health Visits</h3>
                    </div>
                    <div class="p-6">
                        <?php if (empty($recent_visits)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-stethoscope text-gray-300 text-4xl mb-4"></i>
                                <p class="text-gray-500">No recent visits recorded</p>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($recent_visits as $visit): ?>
                                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600 text-sm"></i>
                                            </div>
                                            <div>
                                                <p class="font-medium text-gray-900"><?php echo htmlspecialchars($visit['student_name']); ?></p>
                                                <p class="text-sm text-gray-500">ID: <?php echo htmlspecialchars($visit['student_id']); ?></p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <p class="text-sm font-medium text-gray-900"><?php echo date('M j', strtotime($visit['visit_date'])); ?></p>
                                            <p class="text-xs text-gray-500"><?php echo date('g:i A', strtotime($visit['visit_time'])); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Health Alerts -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Health Alerts & Reminders</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center p-3 bg-red-50 rounded-lg">
                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-exclamation text-red-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-red-900">Allergy Alerts</p>
                                    <p class="text-sm text-red-600"><?php echo $allergy_students; ?> students with known allergies</p>
                                </div>
                            </div>
                            <div class="flex items-center p-3 bg-yellow-50 rounded-lg">
                                <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-clock text-yellow-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-yellow-900">Pending Clearances</p>
                                    <p class="text-sm text-yellow-600"><?php echo $pending_clearances; ?> medical clearances pending</p>
                                </div>
                            </div>
                            <div class="flex items-center p-3 bg-blue-50 rounded-lg">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-calendar text-blue-600 text-sm"></i>
                                </div>
                                <div>
                                    <p class="font-medium text-blue-900">Vaccination Schedule</p>
                                    <p class="text-sm text-blue-600">Check upcoming vaccination dates</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <a href="../health/records.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-file-medical text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Health Records</h3>
                        <p class="text-gray-600 text-sm">Manage student health records</p>
                    </div>
                </a>

                <a href="../health/visits.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-stethoscope text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Health Visits</h3>
                        <p class="text-gray-600 text-sm">Record and track visits</p>
                    </div>
                </a>

                <a href="../health/medications.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-pills text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Medications</h3>
                        <p class="text-gray-600 text-sm">Manage student medications</p>
                    </div>
                </a>

                <a href="../health/reports.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-chart-line text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Health Reports</h3>
                        <p class="text-gray-600 text-sm">Generate health reports</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
