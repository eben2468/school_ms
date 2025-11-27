<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'];

// Get today's classes from timetable
$today = strtolower(date('l')); // Get day name (Monday, Tuesday, etc.) and convert to lowercase
$current_time = date('H:i:s');
$current_date = date('Y-m-d');

// Initialize variables
$classes_today = [];
$upcoming_classes = [];

if ($role === 'student') {
    // Get student's class
    $student_class_query = "SELECT c.id, c.name FROM student_classes sc
                          JOIN classes c ON sc.class_id = c.id
                          WHERE sc.student_id = :student_id AND sc.status = 'active'";
    $stmt = $db->prepare($student_class_query);
    $stmt->bindParam(':student_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $class_result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($class_result)) {
        $class = $class_result[0];
        $class_id = $class['id'];

        // Get today's schedule for this class
        $schedule_query = "SELECT t.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code
                         FROM timetable t
                         JOIN subjects s ON t.subject_id = s.id
                         JOIN users u ON t.teacher_id = u.id
                         WHERE t.class_id = :class_id AND t.day_of_week = :today AND t.status = 'active'
                         ORDER BY t.start_time";
        $stmt = $db->prepare($schedule_query);
        $stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
        $stmt->bindParam(':today', $today, PDO::PARAM_STR);
        $stmt->execute();
        $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get upcoming classes for the week
        $upcoming_query = "SELECT t.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code,
                          t.day_of_week
                          FROM timetable t
                          JOIN subjects s ON t.subject_id = s.id
                          JOIN users u ON t.teacher_id = u.id
                          WHERE t.class_id = :class_id AND t.status = 'active'
                          ORDER BY FIELD(t.day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'), t.start_time";
        $stmt = $db->prepare($upcoming_query);
        $stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
        $stmt->execute();
        $upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($role === 'teacher') {
    // Get teacher's classes for today
    $schedule_query = "SELECT t.*, s.name as subject_name, c.name as class_name, s.code as subject_code
                     FROM timetable t
                     JOIN subjects s ON t.subject_id = s.id
                     JOIN classes c ON t.class_id = c.id
                     WHERE t.teacher_id = :teacher_id AND t.day_of_week = :today AND t.status = 'active'
                     ORDER BY t.start_time";
    $stmt = $db->prepare($schedule_query);
    $stmt->bindParam(':teacher_id', $user_id, PDO::PARAM_INT);
    $stmt->bindParam(':today', $today, PDO::PARAM_STR);
    $stmt->execute();
    $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get upcoming classes for the week
    $upcoming_query = "SELECT t.*, s.name as subject_name, c.name as class_name, s.code as subject_code,
                      t.day_of_week
                      FROM timetable t
                      JOIN subjects s ON t.subject_id = s.id
                      JOIN classes c ON t.class_id = c.id
                      WHERE t.teacher_id = :teacher_id AND t.status = 'active'
                      ORDER BY FIELD(t.day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'), t.start_time";
    $stmt = $db->prepare($upcoming_query);
    $stmt->bindParam(':teacher_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} elseif (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
    // Admin can see all today's classes
    $schedule_query = "SELECT t.*, s.name as subject_name, c.name as class_name, u.name as teacher_name, s.code as subject_code
                     FROM timetable t
                     JOIN subjects s ON t.subject_id = s.id
                     JOIN classes c ON t.class_id = c.id
                     JOIN users u ON t.teacher_id = u.id
                     WHERE t.day_of_week = :today AND t.status = 'active'
                     ORDER BY t.start_time";
    $stmt = $db->prepare($schedule_query);
    $stmt->bindParam(':today', $today, PDO::PARAM_STR);
    $stmt->execute();
    $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Virtual Classroom";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Virtual Classroom</h1>
                                <p class="text-blue-100 text-lg">
                                    Join or manage virtual classroom sessions, <?php echo htmlspecialchars($user_name); ?>!
                                </p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-video mr-2"></i>
                                        <span>Live Sessions</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar mr-2"></i>
                                        <span>Scheduled Classes</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-users mr-2"></i>
                                        <span>Interactive Learning</span>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden lg:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-video text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6 flex space-x-3">
                            <a href="index.php" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition-colors duration-200 backdrop-blur-sm">
                                <i class="fas fa-arrow-left mr-2"></i>Back
                            </a>
                            <?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
                            <button onclick="showCreateModal()" class="bg-white/20 hover:bg-white/30 text-white px-4 py-2 rounded-lg transition-colors duration-200 backdrop-blur-sm">
                                <i class="fas fa-plus mr-2"></i>Create Session
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Platform Integration Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-video text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Zoom Integration</h3>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Host virtual classrooms with Zoom video conferencing</p>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                            <i class="fas fa-check mr-1"></i>Connected
                        </span>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fab fa-google text-green-600 dark:text-green-400"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Google Meet</h3>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Integrate with Google Workspace for Education</p>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                            <i class="fas fa-check mr-1"></i>Connected
                        </span>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3 mb-4">
                            <i class="fab fa-microsoft text-blue-600 text-2xl"></i>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Microsoft Teams</h3>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Collaborate with Microsoft 365 Education</p>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200">
                            <i class="fas fa-clock mr-1"></i>Available
                        </span>
                    </div>
                </div>

                <!-- Today's Classes -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Live & Upcoming Classes -->
                    <div class="lg:col-span-2">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Today's Classes</h2>

                            <?php if (empty($classes_today)): ?>
                            <div class="text-center py-8">
                                <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
                                <p class="text-gray-500 dark:text-gray-400">No classes scheduled for today</p>
                            </div>
                            <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($classes_today as $class):
                                    $is_current = ($current_time >= $class['start_time'] && $current_time <= $class['end_time']);
                                    $is_upcoming = ($current_time < $class['start_time']);
                                    $is_past = ($current_time > $class['end_time']);

                                    if ($is_current) {
                                        $status_class = 'border-green-500 bg-green-50 dark:bg-green-900/20';
                                        $status_text = 'Live Now';
                                        $status_icon = 'fas fa-circle text-green-500';
                                    } elseif ($is_upcoming) {
                                        $status_class = 'border-blue-500 bg-blue-50 dark:bg-blue-900/20';
                                        $status_text = 'Upcoming';
                                        $status_icon = 'fas fa-clock text-blue-500';
                                    } else {
                                        $status_class = 'border-gray-300 bg-gray-50 dark:bg-gray-700';
                                        $status_text = 'Completed';
                                        $status_icon = 'fas fa-check text-gray-500';
                                    }
                                ?>
                                <div class="border-2 <?php echo $status_class; ?> rounded-lg p-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <div class="flex items-center space-x-3">
                                            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-purple-600 rounded-lg flex items-center justify-center text-white font-bold">
                                                <?php echo strtoupper(substr($class['subject_code'] ?? $class['subject_name'], 0, 2)); ?>
                                            </div>
                                            <div>
                                                <h3 class="font-semibold text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['subject_name']); ?></h3>
                                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                                    <?php echo $role === 'student' ? 'with ' . htmlspecialchars($class['teacher_name']) : htmlspecialchars($class['class_name']); ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            <div class="flex items-center space-x-2 mb-1">
                                                <i class="<?php echo $status_icon; ?>"></i>
                                                <span class="text-sm font-medium"><?php echo $status_text; ?></span>
                                            </div>
                                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                                <?php echo date('g:i A', strtotime($class['start_time'])); ?> -
                                                <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                            </p>
                                            <?php if ($class['room_number']): ?>
                                            <p class="text-xs text-gray-500">Room: <?php echo htmlspecialchars($class['room_number']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center space-x-4 text-sm text-gray-600 dark:text-gray-400">
                                            <span><i class="fas fa-users mr-1"></i>Virtual Session</span>
                                            <span><i class="fas fa-video mr-1"></i>Video Enabled</span>
                                        </div>

                                        <?php if ($is_current || $is_upcoming): ?>
                                        <button onclick="joinClass('<?php echo $class['id']; ?>', '<?php echo htmlspecialchars($class['subject_name']); ?>')"
                                                class="<?php echo $is_current ? 'bg-green-600 hover:bg-green-700' : 'bg-blue-600 hover:bg-blue-700'; ?> text-white px-4 py-2 rounded-lg transition-colors">
                                            <i class="fas fa-video mr-2"></i>
                                            <?php echo $is_current ? 'Join Now' : 'Join Class'; ?>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Weekly Schedule -->
                        <?php if (!empty($upcoming_classes)): ?>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Weekly Schedule</h2>
                            <div class="space-y-4">
                                <?php
                                $days_order = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                                $grouped_classes = [];
                                foreach ($upcoming_classes as $class) {
                                    $grouped_classes[$class['day_of_week']][] = $class;
                                }

                                foreach ($days_order as $day):
                                    if (isset($grouped_classes[$day])):
                                ?>
                                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                                    <h3 class="font-semibold text-gray-900 dark:text-white mb-3"><?php echo ucfirst($day); ?></h3>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                        <?php foreach ($grouped_classes[$day] as $class): ?>
                                        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                            <div>
                                                <p class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['subject_name']); ?></p>
                                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                                    <?php echo $role === 'student' ? htmlspecialchars($class['teacher_name']) : htmlspecialchars($class['class_name']); ?>
                                                </p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo date('g:i A', strtotime($class['start_time'])); ?>
                                                </p>
                                                <?php if ($class['room_number']): ?>
                                                <p class="text-xs text-gray-500"><?php echo htmlspecialchars($class['room_number']); ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php
                                    endif;
                                endforeach;
                                ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar Info -->
                    <div class="space-y-6">
                        <!-- Quick Stats -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Today's Summary</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Total Classes</span>
                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($classes_today); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Live Now</span>
                                    <span class="font-semibold text-green-600">
                                        <?php
                                        $live_count = 0;
                                        foreach ($classes_today as $class) {
                                            if ($current_time >= $class['start_time'] && $current_time <= $class['end_time']) {
                                                $live_count++;
                                            }
                                        }
                                        echo $live_count;
                                        ?>
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Upcoming</span>
                                    <span class="font-semibold text-blue-600">
                                        <?php
                                        $upcoming_count = 0;
                                        foreach ($classes_today as $class) {
                                            if ($current_time < $class['start_time']) {
                                                $upcoming_count++;
                                            }
                                        }
                                        echo $upcoming_count;
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>
                            <div class="space-y-3">
                                <?php if ($role === 'teacher'): ?>
                                <button onclick="createInstantMeeting()" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                    <i class="fas fa-plus mr-2"></i>Create Instant Meeting
                                </button>
                                <?php endif; ?>
                                <a href="../academics/timetable.php" class="w-full bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors block text-center">
                                    <i class="fas fa-calendar mr-2"></i>View Full Timetable
                                </a>
                                <a href="../academics/assignments.php" class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors block text-center">
                                    <i class="fas fa-tasks mr-2"></i>View Assignments
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



<script>
function joinClass(classId, subjectName) {
    // Generate a meeting room URL (in a real implementation, this would be integrated with actual video conferencing APIs)
    const meetingId = 'class-' + classId + '-' + Date.now();
    const meetingUrl = `https://meet.google.com/${meetingId}`;

    // Show confirmation dialog
    if (confirm(`Join ${subjectName} virtual classroom?\n\nThis will open in a new window.`)) {
        // In a real implementation, you would:
        // 1. Create/join the actual meeting room
        // 2. Log the attendance
        // 3. Send notifications to participants

        window.open(meetingUrl, '_blank', 'width=1200,height=800');

        // Log attendance (simplified)
        logAttendance(classId);
    }
}

function createInstantMeeting() {
    // For teachers to create ad-hoc meetings
    const meetingId = 'meeting-' + Date.now();
    const meetingUrl = `https://meet.google.com/${meetingId}`;

    if (confirm('Create a new virtual meeting?\n\nThis will open in a new window.')) {
        window.open(meetingUrl, '_blank', 'width=1200,height=800');
    }
}

function logAttendance(classId) {
    // In a real implementation, this would make an AJAX call to log attendance
    fetch('../api/log_virtual_attendance.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            class_id: classId,
            timestamp: new Date().toISOString()
        })
    }).catch(error => {
        console.log('Attendance logging not available yet');
    });
}

// Auto-refresh the page every 5 minutes to update class status
setInterval(function() {
    location.reload();
}, 300000); // 5 minutes

// Show notification for upcoming classes
function checkUpcomingClasses() {
    const now = new Date();
    const currentTime = now.toTimeString().slice(0, 8);

    // Check if any class is starting in the next 5 minutes
    <?php foreach ($classes_today as $class): ?>
    const classStart = '<?php echo $class['start_time']; ?>';
    const classSubject = '<?php echo htmlspecialchars($class['subject_name']); ?>';

    // Calculate time difference
    const startTime = new Date();
    const [hours, minutes, seconds] = classStart.split(':');
    startTime.setHours(hours, minutes, seconds);

    const timeDiff = (startTime - now) / 1000 / 60; // difference in minutes

    if (timeDiff > 0 && timeDiff <= 5) {
        // Show notification for class starting soon
        if (Notification.permission === 'granted') {
            new Notification(`Class Starting Soon`, {
                body: `${classSubject} starts in ${Math.round(timeDiff)} minutes`,
                icon: '/favicon.ico'
            });
        }
    }
    <?php endforeach; ?>
}

// Request notification permission
if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
}

// Check for upcoming classes every minute
setInterval(checkUpcomingClasses, 60000);
</script>
