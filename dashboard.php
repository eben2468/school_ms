<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

require_once 'config/database.php';
require_once 'includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

// Add proper role check
$role = isset($_SESSION['role']) ? $_SESSION['role'] : 'student';
$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_id = $_SESSION['user_id'];
$title = "Dashboard";
$breadcrumbs = [
    ['title' => 'Dashboard']
];

// Get current academic context
$academic_context = $database->getCurrentAcademicContext();

// Get dashboard statistics based on user role
$stats = [];

try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Admin dashboard statistics
        $stats_query = "SELECT 
            (SELECT COUNT(*) FROM users WHERE role = 'student' AND status = 'active') as total_students,
            (SELECT COUNT(*) FROM users WHERE role = 'teacher' AND status = 'active') as total_teachers,
            (SELECT COUNT(*) FROM classes WHERE status = 'active') as total_classes,
            (SELECT COUNT(*) FROM users WHERE role IN ('student', 'teacher') AND status = 'active' AND DATE(created_at) = CURDATE()) as new_today";
        $stats_stmt = $db->query($stats_query);
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

        // Get recent enrollments
        $recent_query = "SELECT u.name, u.created_at, 'student' as type FROM users u 
                        WHERE u.role = 'student' AND u.status = 'active' 
                        ORDER BY u.created_at DESC LIMIT 5";
        $recent_stmt = $db->query($recent_query);
        $recent_activities = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get monthly enrollment data for chart
        $monthly_query = "SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            COUNT(*) as count
            FROM users 
            WHERE role = 'student' AND status = 'active' 
            AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY DATE_FORMAT(created_at, '%Y-%m')
            ORDER BY month";
        $monthly_stmt = $db->query($monthly_query);
        $monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

    } elseif ($role === 'teacher') {
        // Teacher dashboard statistics
        $stats_query = "SELECT 
            (SELECT COUNT(*) FROM student_classes sc 
             JOIN classes c ON sc.class_id = c.id 
             WHERE c.teacher_id = :user_id) as my_students,
            (SELECT COUNT(*) FROM classes WHERE teacher_id = :user_id AND status = 'active') as my_classes,
            (SELECT COUNT(*) FROM assignments WHERE teacher_id = :user_id AND status = 'active') as my_assignments,
            (SELECT COUNT(*) FROM attendance a 
             JOIN classes c ON a.class_id = c.id 
             WHERE c.teacher_id = :user_id AND DATE(a.date) = CURDATE()) as today_attendance";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->bindParam(':user_id', $user_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

    } elseif ($role === 'student') {
        // Student dashboard statistics
        $stats_query = "SELECT 
            (SELECT COUNT(*) FROM student_classes WHERE student_id = :user_id) as my_classes,
            (SELECT COUNT(*) FROM assignments a 
             JOIN student_classes sc ON a.class_id = sc.class_id 
             WHERE sc.student_id = :user_id AND a.status = 'active') as pending_assignments,
            (SELECT COUNT(*) FROM attendance WHERE student_id = :user_id AND status = 'present' AND MONTH(date) = MONTH(NOW())) as attendance_this_month,
            (SELECT COUNT(*) FROM book_loans WHERE borrower_id = :user_id AND status = 'borrowed') as borrowed_books";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->bindParam(':user_id', $user_id);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    // Handle database errors gracefully
    $stats = [];
}

include 'includes/header.php';
include 'includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Dynamic width based on sidebar state) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <?php
                // Include role-specific dashboard content
                $dashboard_file = "dashboards/{$role}.php";
                if (file_exists($dashboard_file)) {
                    include $dashboard_file;
                } else {
                    // Fallback to generic dashboard
                    ?>
                    <!-- Generic Dashboard Header -->
                    <div class="mb-8">
                        <div class="dashboard-card-gradient rounded-2xl p-8 text-white shadow-xl" style="background: var(--primary-gradient);">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h1 class="text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                                    <p class="text-blue-100 text-lg">Here's what's happening at <?php echo htmlspecialchars(getSchoolSetting('school_name', 'Greenwood Academy')); ?> today</p>
                                    <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                        <div class="flex items-center">
                                            <i class="fas fa-calendar-alt mr-2"></i>
                                            <?php echo date('l, F j, Y'); ?>
                                        </div>
                                        <div class="flex items-center">
                                            <i class="fas fa-clock mr-2"></i>
                                            <span id="current-time"><?php echo date('g:i A'); ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="hidden md:block">
                                    <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                        <i class="fas fa-graduation-cap text-6xl text-white/80"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php
                }
                ?>







            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Update current time
function updateTime() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    const timeElement = document.getElementById('current-time');
    if (timeElement) {
        timeElement.textContent = timeString;
    }
}

// Update time immediately and then every second
updateTime();
setInterval(updateTime, 1000);
</script>
