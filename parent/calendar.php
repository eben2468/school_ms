<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$parent_id = $_SESSION['user_id'];
$current_month = $_GET['month'] ?? date('Y-m');
$current_date = date('Y-m-d');

// Get parent's children
$children_query = "
    SELECT u.id, u.name 
    FROM users u
    JOIN parent_students ps ON u.id = ps.student_id
    WHERE ps.parent_id = :parent_id AND u.status = 'active'
    ORDER BY u.name
";
$children_stmt = $db->prepare($children_query);
$children_stmt->bindParam(':parent_id', $parent_id);
$children_stmt->execute();
$children = $children_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get upcoming events (announcements with dates)
$events_query = "
    SELECT * FROM announcements 
    WHERE (target_audience = 'all' OR target_audience = 'parents') 
    AND status = 'published' 
    AND publish_date >= :current_date
    ORDER BY publish_date ASC 
    LIMIT 10
";
$events_stmt = $db->prepare($events_query);
$events_stmt->bindParam(':current_date', $current_date);
$events_stmt->execute();
$upcoming_events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get exams for children in the current month
$exams = [];
if (!empty($children)) {
    $child_ids = array_column($children, 'id');
    $placeholders = str_repeat('?,', count($child_ids) - 1) . '?';
    
    $exam_query = "
        SELECT e.*, s.name as subject_name, u.name as student_name
        FROM exams e
        LEFT JOIN subjects s ON e.subject_id = s.id
        LEFT JOIN users u ON u.id IN ($placeholders)
        WHERE e.exam_date LIKE :month
        AND e.status != 'cancelled'
        ORDER BY e.exam_date ASC
    ";
    
    $exam_stmt = $db->prepare($exam_query);
    foreach ($child_ids as $index => $child_id) {
        $exam_stmt->bindValue($index + 1, $child_id);
    }
    $exam_stmt->bindValue(count($child_ids) + 1, $current_month . '%');
    $exam_stmt->execute();
    $exams = $exam_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Generate calendar
function generateCalendar($year, $month) {
    $firstDay = mktime(0, 0, 0, $month, 1, $year);
    $monthName = date('F Y', $firstDay);
    $daysInMonth = date('t', $firstDay);
    $dayOfWeek = date('w', $firstDay);
    
    return [
        'name' => $monthName,
        'days' => $daysInMonth,
        'start_day' => $dayOfWeek,
        'year' => $year,
        'month' => $month
    ];
}

$calendar_date = explode('-', $current_month);
$calendar = generateCalendar($calendar_date[0], $calendar_date[1]);

$title = "Calendar & Events";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Calendar & Events</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">View school events, exams, and important dates</p>
                    </div>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Portal
                    </a>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Calendar -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <!-- Calendar Header -->
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white"><?php echo $calendar['name']; ?></h3>
                                <div class="flex space-x-2">
                                    <a href="?month=<?php echo date('Y-m', strtotime($current_month . '-01 -1 month')); ?>" 
                                       class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                    <a href="?month=<?php echo date('Y-m'); ?>" 
                                       class="px-3 py-1 text-sm bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200 rounded">
                                        Today
                                    </a>
                                    <a href="?month=<?php echo date('Y-m', strtotime($current_month . '-01 +1 month')); ?>" 
                                       class="p-2 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                </div>
                            </div>

                            <!-- Calendar Grid -->
                            <div class="grid grid-cols-7 gap-1 mb-2">
                                <?php 
                                $days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
                                foreach ($days as $day): 
                                ?>
                                <div class="p-2 text-center text-sm font-medium text-gray-500 dark:text-gray-400">
                                    <?php echo $day; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="grid grid-cols-7 gap-1">
                                <?php
                                // Empty cells for days before month starts
                                for ($i = 0; $i < $calendar['start_day']; $i++) {
                                    echo '<div class="p-2 h-20"></div>';
                                }

                                // Days of the month
                                for ($day = 1; $day <= $calendar['days']; $day++) {
                                    $date = sprintf('%04d-%02d-%02d', $calendar['year'], $calendar['month'], $day);
                                    $is_today = ($date === $current_date);
                                    $has_events = false;
                                    
                                    // Check for events on this day
                                    foreach ($upcoming_events as $event) {
                                        if (date('Y-m-d', strtotime($event['publish_date'])) === $date) {
                                            $has_events = true;
                                            break;
                                        }
                                    }
                                    
                                    // Check for exams on this day
                                    foreach ($exams as $exam) {
                                        if ($exam['exam_date'] === $date) {
                                            $has_events = true;
                                            break;
                                        }
                                    }
                                    
                                    $cell_class = 'p-2 h-20 border border-gray-100 dark:border-gray-700 relative';
                                    if ($is_today) {
                                        $cell_class .= ' bg-blue-100 dark:bg-blue-900';
                                    }
                                    if ($has_events) {
                                        $cell_class .= ' bg-yellow-50 dark:bg-yellow-900/20';
                                    }
                                    
                                    echo "<div class=\"$cell_class\">";
                                    echo "<span class=\"text-sm font-medium text-gray-900 dark:text-white\">$day</span>";
                                    if ($has_events) {
                                        echo '<div class="absolute bottom-1 left-1 w-2 h-2 bg-blue-500 rounded-full"></div>';
                                    }
                                    echo '</div>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>

                    <!-- Events Sidebar -->
                    <div class="space-y-6">
                        <!-- Upcoming Events -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                <i class="fas fa-calendar-alt text-blue-500 mr-2"></i>
                                Upcoming Events
                            </h3>
                            
                            <?php if (empty($upcoming_events)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No upcoming events scheduled.</p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($upcoming_events, 0, 5) as $event): ?>
                                <div class="p-3 border border-gray-200 dark:border-gray-600 rounded-lg">
                                    <div class="flex items-start">
                                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mr-3 mt-1">
                                            <i class="fas fa-calendar text-blue-600 dark:text-blue-400 text-xs"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($event['title']); ?>
                                            </h4>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <?php echo date('M j, Y', strtotime($event['publish_date'])); ?>
                                            </p>
                                            <?php if ($event['priority'] === 'urgent' || $event['priority'] === 'high'): ?>
                                            <span class="inline-block mt-1 px-2 py-1 text-xs bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200 rounded">
                                                <?php echo ucfirst($event['priority']); ?>
                                            </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Upcoming Exams -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                                <i class="fas fa-graduation-cap text-green-500 mr-2"></i>
                                Upcoming Exams
                            </h3>
                            
                            <?php if (empty($exams)): ?>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No exams scheduled this month.</p>
                            <?php else: ?>
                            <div class="space-y-3">
                                <?php foreach (array_slice($exams, 0, 5) as $exam): ?>
                                <div class="p-3 border border-gray-200 dark:border-gray-600 rounded-lg">
                                    <div class="flex items-start">
                                        <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-full flex items-center justify-center mr-3 mt-1">
                                            <i class="fas fa-pencil-alt text-green-600 dark:text-green-400 text-xs"></i>
                                        </div>
                                        <div class="flex-1">
                                            <h4 class="text-sm font-medium text-gray-900 dark:text-white">
                                                <?php echo htmlspecialchars($exam['title']); ?>
                                            </h4>
                                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                                <?php echo htmlspecialchars($exam['subject_name'] ?? 'Subject'); ?>
                                            </p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                                <?php echo date('M j, Y', strtotime($exam['exam_date'])); ?>
                                                <?php if ($exam['start_time']): ?>
                                                at <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Links -->
                        <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Quick Links</h3>
                            <div class="space-y-2">
                                <a href="attendance.php" class="block p-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded">
                                    <i class="fas fa-calendar-check mr-2"></i>View Attendance
                                </a>
                                <a href="grades.php" class="block p-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded">
                                    <i class="fas fa-chart-line mr-2"></i>View Grades
                                </a>
                                <a href="fees.php" class="block p-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded">
                                    <i class="fas fa-money-bill mr-2"></i>View Fees
                                </a>
                                <a href="messages.php" class="block p-2 text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 hover:bg-blue-50 dark:hover:bg-blue-900/20 rounded">
                                    <i class="fas fa-envelope mr-2"></i>Messages
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
