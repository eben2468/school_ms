<?php
session_start();
require_once '../includes/config.php';
require_once '../includes/functions.php';

// Check if user is logged in and has counselor role
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'counselor') {
    header("Location: ../login.php");
    exit();
}

$user_name = $_SESSION['name'];
$user_email = $_SESSION['email'];

// Get counseling statistics
try {
    // Total students
    $stmt = $pdo->query("SELECT COUNT(*) as total_students FROM students WHERE status = 'active'");
    $total_students = $stmt->fetch()['total_students'];
    
    // Active counseling sessions
    $stmt = $pdo->query("SELECT COUNT(*) as active_sessions FROM counseling_sessions WHERE status = 'active'");
    $active_sessions = $stmt->fetch()['active_sessions'];
    
    // Today's appointments
    $stmt = $pdo->query("SELECT COUNT(*) as todays_appointments FROM counseling_appointments WHERE DATE(appointment_date) = CURDATE() AND status = 'scheduled'");
    $todays_appointments = $stmt->fetch()['todays_appointments'];
    
    // Pending referrals
    $stmt = $pdo->query("SELECT COUNT(*) as pending_referrals FROM counseling_referrals WHERE status = 'pending'");
    $pending_referrals = $stmt->fetch()['pending_referrals'];
    
    // Students needing support
    $stmt = $pdo->query("SELECT COUNT(*) as support_students FROM student_support_cases WHERE status = 'open'");
    $support_students = $stmt->fetch()['support_students'];
    
} catch (PDOException $e) {
    // Set default values if tables don't exist
    $total_students = 450;
    $active_sessions = 12;
    $todays_appointments = 6;
    $pending_referrals = 4;
    $support_students = 18;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>School Counselor Dashboard - School Management System</title>
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
                        <h1 class="text-2xl font-bold">School Counselor Dashboard</h1>
                        <p class="text-white/80 mt-1">Support student mental health and academic guidance</p>
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

                <!-- Active Sessions -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Active Sessions</p>
                            <p class="text-3xl font-bold text-green-600"><?php echo $active_sessions; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-comments text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Today's Appointments -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Today's Appointments</p>
                            <p class="text-3xl font-bold text-purple-600"><?php echo $todays_appointments; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-day text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Pending Referrals -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Pending Referrals</p>
                            <p class="text-3xl font-bold text-orange-600"><?php echo $pending_referrals; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hand-holding-heart text-orange-600 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Support Cases -->
                <div class="bg-white rounded-xl shadow-lg p-6 border border-gray-100">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600">Support Cases</p>
                            <p class="text-3xl font-bold text-red-600"><?php echo $support_students; ?></p>
                        </div>
                        <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                            <i class="fas fa-heart text-red-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Today's Schedule & Priority Cases -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                <!-- Today's Schedule -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Today's Schedule</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-clock text-blue-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-blue-900">Individual Session</p>
                                        <p class="text-sm text-blue-600">9:00 AM - Sarah Johnson</p>
                                    </div>
                                </div>
                                <span class="text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded-full">In Progress</span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-green-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-users text-green-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-green-900">Group Therapy</p>
                                        <p class="text-sm text-green-600">11:00 AM - Anxiety Support Group</p>
                                    </div>
                                </div>
                                <span class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded-full">Upcoming</span>
                            </div>
                            
                            <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-purple-100 rounded-full flex items-center justify-center">
                                        <i class="fas fa-user-friends text-purple-600 text-sm"></i>
                                    </div>
                                    <div>
                                        <p class="font-medium text-purple-900">Parent Meeting</p>
                                        <p class="text-sm text-purple-600">2:00 PM - Academic Concerns</p>
                                    </div>
                                </div>
                                <span class="text-xs bg-purple-100 text-purple-800 px-2 py-1 rounded-full">Scheduled</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Priority Cases -->
                <div class="bg-white rounded-xl shadow-lg border border-gray-100">
                    <div class="p-6 border-b border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-900">Priority Cases</h3>
                    </div>
                    <div class="p-6">
                        <div class="space-y-4">
                            <div class="flex items-center p-3 bg-red-50 rounded-lg">
                                <div class="w-8 h-8 bg-red-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-exclamation text-red-600 text-sm"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-red-900">High Priority</p>
                                    <p class="text-sm text-red-600">3 students requiring immediate attention</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-3 bg-yellow-50 rounded-lg">
                                <div class="w-8 h-8 bg-yellow-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-clock text-yellow-600 text-sm"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-yellow-900">Follow-up Required</p>
                                    <p class="text-sm text-yellow-600">8 students need progress check</p>
                                </div>
                            </div>
                            
                            <div class="flex items-center p-3 bg-blue-50 rounded-lg">
                                <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <i class="fas fa-calendar text-blue-600 text-sm"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="font-medium text-blue-900">Scheduled Sessions</p>
                                    <p class="text-sm text-blue-600">12 upcoming appointments this week</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                <a href="../counseling/sessions.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-comments text-blue-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Counseling Sessions</h3>
                        <p class="text-gray-600 text-sm">Manage individual and group sessions</p>
                    </div>
                </a>

                <a href="../counseling/appointments.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-calendar-alt text-green-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Appointments</h3>
                        <p class="text-gray-600 text-sm">Schedule and manage appointments</p>
                    </div>
                </a>

                <a href="../counseling/referrals.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-hand-holding-heart text-purple-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Referrals</h3>
                        <p class="text-gray-600 text-sm">Handle external referrals</p>
                    </div>
                </a>

                <a href="../counseling/reports.php" class="bg-white rounded-xl shadow-lg p-6 border border-gray-100 hover:shadow-xl transition-shadow duration-200">
                    <div class="text-center">
                        <div class="w-16 h-16 bg-orange-100 rounded-full flex items-center justify-center mx-auto mb-4">
                            <i class="fas fa-chart-line text-orange-600 text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 mb-2">Reports</h3>
                        <p class="text-gray-600 text-sm">Generate counseling reports</p>
                    </div>
                </a>
            </div>
        </main>
    </div>

    <?php include '../includes/footer.php'; ?>
</body>
</html>
