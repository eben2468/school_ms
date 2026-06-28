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

$today = strtolower(date('l'));
$current_time = date('H:i:s');
$current_date = date('Y-m-d');

$classes_today = [];
$upcoming_classes = [];
$virtual_sessions_today = [];
$virtual_sessions_upcoming = [];

$success_message = '';
$error_message = '';

// Handle creating virtual sessions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_session' && in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
    $session_title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT) ?: null;
    $scheduled_date = filter_input(INPUT_POST, 'scheduled_date', FILTER_DEFAULT);
    $start_time = filter_input(INPUT_POST, 'start_time', FILTER_DEFAULT);
    $end_time = filter_input(INPUT_POST, 'end_time', FILTER_DEFAULT);
    $platform = filter_input(INPUT_POST, 'platform', FILTER_SANITIZE_STRING) ?: 'zoom';
    $meeting_url = filter_input(INPUT_POST, 'meeting_url', FILTER_SANITIZE_URL);
    $meeting_id = filter_input(INPUT_POST, 'meeting_id', FILTER_SANITIZE_STRING);
    $meeting_password = filter_input(INPUT_POST, 'meeting_password', FILTER_SANITIZE_STRING);

    if ($session_title && $scheduled_date && $start_time && $end_time) {
        try {
            $duration_minutes = round((strtotime($end_time) - strtotime($start_time)) / 60);
            if ($duration_minutes <= 0) {
                $duration_minutes = 60;
            }

            $query = "INSERT INTO virtual_classrooms (title, description, teacher_id, class_id, subject_id, meeting_url, meeting_id, meeting_password, platform, scheduled_date, start_time, end_time, duration_minutes, status, created_at)
                      VALUES (:title, :description, :teacher_id, :class_id, :subject_id, :meeting_url, :meeting_id, :meeting_password, :platform, :scheduled_date, :start_time, :end_time, :duration_minutes, 'scheduled', NOW())";
            $stmt = $db->prepare($query);
            $stmt->execute([
                ':title' => $session_title,
                ':description' => $description ?: null,
                ':teacher_id' => $user_id,
                ':class_id' => $class_id,
                ':subject_id' => $subject_id,
                ':meeting_url' => $meeting_url ?: null,
                ':meeting_id' => $meeting_id ?: null,
                ':meeting_password' => $meeting_password ?: null,
                ':platform' => $platform,
                ':scheduled_date' => $scheduled_date,
                ':start_time' => $start_time,
                ':end_time' => $end_time,
                ':duration_minutes' => $duration_minutes
            ]);

            // Notify students if class is assigned
            if ($class_id) {
                $stu_stmt = $db->prepare("SELECT student_id FROM student_classes WHERE class_id = :class_id AND status = 'active'");
                $stu_stmt->execute([':class_id' => $class_id]);
                $student_ids = $stu_stmt->fetchAll(PDO::FETCH_COLUMN);

                if (!empty($student_ids)) {
                    $notif_stmt = $db->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                                                VALUES (:user_id, :title, :message, 'info', 0, NOW())");
                    $notif_msg = "New Virtual Class: " . $session_title . " scheduled for " . date('M j, Y', strtotime($scheduled_date)) . " at " . date('g:i A', strtotime($start_time));
                    foreach ($student_ids as $sid) {
                        $notif_stmt->execute([
                            ':user_id' => $sid,
                            ':title' => 'Virtual Class Scheduled',
                            ':message' => $notif_msg
                        ]);
                    }
                }
            }

            $success_message = "Virtual session created successfully!";
        } catch (PDOException $e) {
            $error_message = "Error creating session: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Fetch classes and subjects for creation modal
$classes = [];
$subjects = [];
if (in_array($role, ['super_admin', 'school_admin', 'teacher'])) {
    try {
        $classes = $db->query("SELECT id, name FROM classes ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $subjects = $db->query("SELECT id, name, class_id FROM subjects ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
}

// Load data depending on user role
try {
    if ($role === 'student') {
        // Student class id
        $stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $stmt->execute([':student_id' => $user_id]);
        $student_class_id = $stmt->fetchColumn();

        if ($student_class_id) {
            // Get today's school timetable
            $stmt = $db->prepare("SELECT t.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code, NULL as room_number
                                  FROM timetable t
                                  JOIN subjects s ON t.subject_id = s.id
                                  JOIN users u ON t.teacher_id = u.id
                                  WHERE t.class_id = :class_id AND t.day_of_week = :today AND t.status = 'active'
                                  ORDER BY t.start_time");
            $stmt->execute([':class_id' => $student_class_id, ':today' => $today]);
            $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get weekly schedule
            $stmt = $db->prepare("SELECT t.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code, NULL as room_number, t.day_of_week
                                  FROM timetable t
                                  JOIN subjects s ON t.subject_id = s.id
                                  JOIN users u ON t.teacher_id = u.id
                                  WHERE t.class_id = :class_id AND t.status = 'active'
                                  ORDER BY FIELD(t.day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'), t.start_time");
            $stmt->execute([':class_id' => $student_class_id]);
            $upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get today's virtual sessions
            $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code
                                  FROM virtual_classrooms vc
                                  LEFT JOIN subjects s ON vc.subject_id = s.id
                                  LEFT JOIN users u ON vc.teacher_id = u.id
                                  WHERE vc.class_id = :class_id AND vc.scheduled_date = :current_date
                                  ORDER BY vc.start_time");
            $stmt->execute([':class_id' => $student_class_id, ':current_date' => $current_date]);
            $virtual_sessions_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get upcoming virtual sessions
            $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, u.name as teacher_name, s.code as subject_code
                                  FROM virtual_classrooms vc
                                  LEFT JOIN subjects s ON vc.subject_id = s.id
                                  LEFT JOIN users u ON vc.teacher_id = u.id
                                  WHERE vc.class_id = :class_id AND vc.scheduled_date > :current_date
                                  ORDER BY vc.scheduled_date, vc.start_time");
            $stmt->execute([':class_id' => $student_class_id, ':current_date' => $current_date]);
            $virtual_sessions_upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } elseif ($role === 'teacher') {
        // Teacher classes
        $stmt = $db->prepare("SELECT t.*, s.name as subject_name, c.name as class_name, s.code as subject_code, NULL as room_number
                              FROM timetable t
                              JOIN subjects s ON t.subject_id = s.id
                              JOIN classes c ON t.class_id = c.id
                              WHERE t.teacher_id = :teacher_id AND t.day_of_week = :today AND t.status = 'active'
                              ORDER BY t.start_time");
        $stmt->execute([':teacher_id' => $user_id, ':today' => $today]);
        $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get weekly schedule
        $stmt = $db->prepare("SELECT t.*, s.name as subject_name, c.name as class_name, s.code as subject_code, NULL as room_number, t.day_of_week
                              FROM timetable t
                              JOIN subjects s ON t.subject_id = s.id
                              JOIN classes c ON t.class_id = c.id
                              WHERE t.teacher_id = :teacher_id AND t.status = 'active'
                              ORDER BY FIELD(t.day_of_week, 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'), t.start_time");
        $stmt->execute([':teacher_id' => $user_id]);
        $upcoming_classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get today's virtual sessions
        $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, c.name as class_name, s.code as subject_code
                              FROM virtual_classrooms vc
                              LEFT JOIN subjects s ON vc.subject_id = s.id
                              LEFT JOIN classes c ON vc.class_id = c.id
                              WHERE vc.teacher_id = :teacher_id AND vc.scheduled_date = :current_date
                              ORDER BY vc.start_time");
        $stmt->execute([':teacher_id' => $user_id, ':current_date' => $current_date]);
        $virtual_sessions_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get upcoming virtual sessions
        $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, c.name as class_name, s.code as subject_code
                              FROM virtual_classrooms vc
                              LEFT JOIN subjects s ON vc.subject_id = s.id
                              LEFT JOIN classes c ON vc.class_id = c.id
                              WHERE vc.teacher_id = :teacher_id AND vc.scheduled_date > :current_date
                              ORDER BY vc.scheduled_date, vc.start_time");
        $stmt->execute([':teacher_id' => $user_id, ':current_date' => $current_date]);
        $virtual_sessions_upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Admin
        $stmt = $db->prepare("SELECT t.*, s.name as subject_name, c.name as class_name, u.name as teacher_name, s.code as subject_code, NULL as room_number
                              FROM timetable t
                              JOIN subjects s ON t.subject_id = s.id
                              JOIN classes c ON t.class_id = c.id
                              JOIN users u ON t.teacher_id = u.id
                              WHERE t.day_of_week = :today AND t.status = 'active'
                              ORDER BY t.start_time");
        $stmt->execute([':today' => $today]);
        $classes_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get today's virtual sessions
        $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, c.name as class_name, u.name as teacher_name, s.code as subject_code
                              FROM virtual_classrooms vc
                              LEFT JOIN subjects s ON vc.subject_id = s.id
                              LEFT JOIN classes c ON vc.class_id = c.id
                              LEFT JOIN users u ON vc.teacher_id = u.id
                              WHERE vc.scheduled_date = :current_date
                              ORDER BY vc.start_time");
        $stmt->execute([':current_date' => $current_date]);
        $virtual_sessions_today = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get upcoming virtual sessions
        $stmt = $db->prepare("SELECT vc.*, s.name as subject_name, c.name as class_name, u.name as teacher_name, s.code as subject_code
                              FROM virtual_classrooms vc
                              LEFT JOIN subjects s ON vc.subject_id = s.id
                              LEFT JOIN classes c ON vc.class_id = c.id
                              LEFT JOIN users u ON vc.teacher_id = u.id
                              WHERE vc.scheduled_date > :current_date
                              ORDER BY vc.scheduled_date, vc.start_time");
        $stmt->execute([':current_date' => $current_date]);
        $virtual_sessions_upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {}

$title = "Virtual Classroom";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
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
                                <i class="fas fa-plus mr-2"></i>Schedule Session
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Messages -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-green-100 border border-green-200 text-green-800 dark:bg-green-900 dark:border-green-800 dark:text-green-200">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 rounded-lg bg-red-100 border border-red-200 text-red-800 dark:bg-red-900 dark:border-red-800 dark:text-red-200">
                    <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- Platform Integration Cards -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/30 rounded-lg flex items-center justify-center">
                                <i class="fas fa-video text-blue-600 dark:text-blue-400"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Zoom Integration</h3>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Host virtual classrooms with Zoom video conferencing</p>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                            <i class="fas fa-check mr-1"></i>Connected
                        </span>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3 mb-4">
                            <div class="w-8 h-8 bg-green-100 dark:bg-green-900/30 rounded-lg flex items-center justify-center">
                                <i class="fab fa-google text-green-600 dark:text-green-400"></i>
                            </div>
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Google Meet</h3>
                        </div>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Integrate with Google Workspace for Education</p>
                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                            <i class="fas fa-check mr-1"></i>Connected
                        </span>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
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

                <!-- Classroom Scheduler & Timetables Layout -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- Virtual Classroom Sessions -->
                    <div class="lg:col-span-2 space-y-8">
                        <!-- TODAY'S SESSIONS -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Today's Virtual Classroom Sessions</h2>
                            <?php if (empty($virtual_sessions_today)): ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
                                    <p class="text-gray-500 dark:text-gray-400">No custom virtual sessions scheduled for today.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($virtual_sessions_today as $vs):
                                        $is_current = ($current_time >= $vs['start_time'] && $current_time <= $vs['end_time']);
                                        $is_upcoming = ($current_time < $vs['start_time']);
                                        
                                        if ($is_current) {
                                            $border = 'border-green-500 bg-green-50 dark:bg-green-950/20';
                                            $badge = 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200';
                                            $badge_text = 'Live Now';
                                        } else {
                                            $border = 'border-blue-500 bg-blue-50 dark:bg-blue-950/20';
                                            $badge = 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200';
                                            $badge_text = 'Scheduled';
                                        }
                                    ?>
                                        <div class="border-2 <?php echo $border; ?> rounded-xl p-5 transition hover:shadow-md">
                                            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                                                <div class="flex items-start space-x-3">
                                                    <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-blue-600 rounded-lg flex items-center justify-center text-white font-bold flex-shrink-0">
                                                        <?php echo strtoupper(substr($vs['subject_code'] ?? $vs['subject_name'] ?? 'VS', 0, 2)); ?>
                                                    </div>
                                                    <div>
                                                        <h3 class="font-bold text-gray-900 dark:text-white text-lg"><?php echo htmlspecialchars($vs['title']); ?></h3>
                                                        <p class="text-sm text-gray-600 dark:text-gray-400"><?php echo htmlspecialchars($vs['description'] ?? 'No description'); ?></p>
                                                        <span class="text-xs text-gray-500 dark:text-gray-500">
                                                            Subject: <?php echo htmlspecialchars($mat_sub = $vs['subject_name'] ?? 'General'); ?> • 
                                                            <?php echo ($role === 'student') ? 'Teacher: ' . htmlspecialchars($vs['teacher_name']) : 'Class: ' . htmlspecialchars($vs['class_name'] ?? 'All Classes'); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="text-right self-stretch md:self-auto flex md:flex-col justify-between md:justify-center items-center md:items-end">
                                                    <div class="mb-2">
                                                        <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?php echo $badge; ?>">
                                                            <?php echo $badge_text; ?>
                                                        </span>
                                                    </div>
                                                    <p class="text-sm text-gray-700 dark:text-gray-300 font-semibold mb-2">
                                                        <?php echo date('g:i A', strtotime($vs['start_time'])); ?> - <?php echo date('g:i A', strtotime($vs['end_time'])); ?>
                                                    </p>
                                                    <?php if ($vs['meeting_url']): ?>
                                                        <a href="<?php echo htmlspecialchars($vs['meeting_url']); ?>" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2 rounded-lg text-sm transition">
                                                            <i class="fas fa-video mr-1"></i> Join Meeting
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- UPCOMING SESSIONS -->
                        <?php if (!empty($virtual_sessions_upcoming)): ?>
                            <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                                <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-6">Upcoming Virtual Sessions</h2>
                                <div class="space-y-4">
                                    <?php foreach ($virtual_sessions_upcoming as $vs): ?>
                                        <div class="border border-gray-200 dark:border-gray-700 rounded-xl p-4 bg-gray-50 dark:bg-gray-800/50">
                                            <div class="flex justify-between items-center">
                                                <div>
                                                    <h3 class="font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($vs['title']); ?></h3>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        Date: <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo date('M d, Y', strtotime($vs['scheduled_date'])); ?></span> • 
                                                        Time: <span class="font-semibold text-gray-800 dark:text-gray-200"><?php echo date('g:i A', strtotime($vs['start_time'])); ?> - <?php echo date('g:i A', strtotime($vs['end_time'])); ?></span>
                                                    </p>
                                                </div>
                                                <span class="text-xs font-semibold px-2 py-1 bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200 rounded capitalize">
                                                    <?php echo htmlspecialchars($vs['platform']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Standard Timetable Classes -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Today's Timetable Classes</h2>
                            <?php if (empty($classes_today)): ?>
                                <div class="text-center py-8">
                                    <i class="fas fa-calendar-times text-4xl text-gray-400 mb-4"></i>
                                    <p class="text-gray-500 dark:text-gray-400">No standard timetable classes scheduled for today.</p>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($classes_today as $class):
                                        $is_current = ($current_time >= $class['start_time'] && $current_time <= $class['end_time']);
                                        $is_upcoming = ($current_time < $class['start_time']);
                                        
                                        if ($is_current) {
                                            $status_class = 'border-green-500 bg-green-50 dark:bg-green-950/20';
                                            $status_text = 'Active';
                                        } else {
                                            $status_class = 'border-gray-200 bg-white dark:bg-gray-800';
                                            $status_text = 'Timetable';
                                        }
                                    ?>
                                        <div class="border <?php echo $status_class; ?> rounded-xl p-4 flex justify-between items-center">
                                            <div class="flex items-center space-x-3">
                                                <div class="w-10 h-10 bg-gray-100 dark:bg-gray-700 rounded-lg flex items-center justify-center font-bold text-gray-800 dark:text-gray-200">
                                                    <?php echo strtoupper(substr($class['subject_code'] ?? $class['subject_name'], 0, 2)); ?>
                                                </div>
                                                <div>
                                                    <h4 class="font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['subject_name']); ?></h4>
                                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                                        <?php echo $role === 'student' ? 'with ' . htmlspecialchars($class['teacher_name']) : htmlspecialchars($class['class_name']); ?>
                                                    </p>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <span class="text-xs font-semibold px-2 py-0.5 bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded mb-1 inline-block"><?php echo $status_text; ?></span>
                                                <p class="text-xs text-gray-600 dark:text-gray-400">
                                                    <?php echo date('g:i A', strtotime($class['start_time'])); ?> - <?php echo date('g:i A', strtotime($class['end_time'])); ?>
                                                </p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Sidebar Summaries & Actions -->
                    <div class="space-y-6">
                        <!-- Today's Summary -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Today's Summary</h3>
                            <div class="space-y-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Timetable Classes</span>
                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($classes_today); ?></span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-600 dark:text-gray-400">Virtual Classroom Sessions</span>
                                    <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($virtual_sessions_today); ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Quick Actions -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6">
                            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>
                            <div class="space-y-3">
                                <?php if ($role === 'teacher'): ?>
                                <button onclick="createInstantMeeting()" class="w-full bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700 transition-colors">
                                    <i class="fas fa-plus mr-2"></i>Create Instant Meeting
                                </button>
                                <?php endif; ?>
                                <?php
                                    $timetable_url = '../academic/timetable/index.php';
                                    if ($role === 'student') {
                                        $timetable_url = '../academic/timetable/student.php';
                                    } elseif ($role === 'teacher') {
                                        $timetable_url = '../academic/timetable/teacher.php';
                                    }
                                ?>
                                <a href="<?php echo $timetable_url; ?>" class="w-full bg-gray-600 text-white px-4 py-2 rounded-lg hover:bg-gray-700 transition-colors block text-center">
                                    <i class="fas fa-calendar mr-2"></i>View Full Timetable
                                </a>
                                <a href="submissions.php" class="w-full bg-purple-600 text-white px-4 py-2 rounded-lg hover:bg-purple-700 transition-colors block text-center">
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

<!-- Create Session Modal -->
<?php if (in_array($role, ['super_admin', 'school_admin', 'teacher'])): ?>
<div id="createModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full" style="max-height: 85vh; overflow-y: auto;">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Schedule Virtual Session</h3>
                <button onclick="hideCreateModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="create_session">
                
                <div>
                    <label for="session_title" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Session Title *</label>
                    <input type="text" id="session_title" name="title" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter session title (e.g. Algebra Review)">
                </div>
                <div>
                    <label for="session_description" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Description</label>
                    <textarea id="session_description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Enter session description/agenda"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="session_class" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class</label>
                        <select id="session_class" name="class_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Class (Optional)</option>
                            <?php foreach ($classes as $cls): ?>
                                <option value="<?php echo $cls['id']; ?>"><?php echo htmlspecialchars($cls['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="session_subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Subject</label>
                        <select id="session_subject" name="subject_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            <option value="">Select Subject (Optional)</option>
                            <?php foreach ($subjects as $sub): ?>
                                <option value="<?php echo $sub['id']; ?>" data-class-id="<?php echo $sub['class_id']; ?>"><?php echo htmlspecialchars($sub['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="session_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Scheduled Date *</label>
                        <input type="date" id="session_date" name="scheduled_date" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label for="session_start_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Time *</label>
                        <input type="time" id="session_start_time" name="start_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>
                    <div>
                        <label for="session_end_time" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Time *</label>
                        <input type="time" id="session_end_time" name="end_time" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                    </div>
                </div>
                <div>
                    <label for="session_platform" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Platform</label>
                    <select id="session_platform" name="platform" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                        <option value="zoom">Zoom</option>
                        <option value="google_meet">Google Meet</option>
                        <option value="teams">Microsoft Teams</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div>
                    <label for="session_url" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Meeting Link URL *</label>
                    <input type="url" id="session_url" name="meeting_url" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="https://meet.google.com/abc-defg-hij">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="session_meeting_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Meeting ID (Optional)</label>
                        <input type="text" id="session_meeting_id" name="meeting_id" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="123 456 7890">
                    </div>
                    <div>
                        <label for="session_password" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Passcode (Optional)</label>
                        <input type="text" id="session_password" name="meeting_password" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white" placeholder="Passcode">
                    </div>
                </div>

                <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button type="button" onclick="hideCreateModal()" class="px-4 py-2 text-gray-600 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200">
                        Cancel
                    </button>
                    <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                        <i class="fas fa-calendar-check mr-2"></i>Schedule Class
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
function showCreateModal() {
    document.getElementById('createModal').classList.remove('hidden');
}

function hideCreateModal() {
    document.getElementById('createModal').classList.add('hidden');
}

// Close modal when clicking outside
document.getElementById('createModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideCreateModal();
    }
});

function setupDynamicSubjectFilter(classSelectId, subjectSelectId) {
    const classSelect = document.getElementById(classSelectId);
    const subjectSelect = document.getElementById(subjectSelectId);
    if (!classSelect || !subjectSelect) return;

    const originalOptions = Array.from(subjectSelect.options).map(opt => ({
        value: opt.value,
        text: opt.text,
        classId: opt.getAttribute('data-class-id')
    }));

    function updateSubjects() {
        const selectedClassId = classSelect.value;
        const currentSelectedValue = subjectSelect.value;
        
        subjectSelect.innerHTML = '';
        
        originalOptions.forEach(opt => {
            if (!selectedClassId || !opt.classId || opt.classId == selectedClassId || opt.value === '') {
                const newOpt = document.createElement('option');
                newOpt.value = opt.value;
                newOpt.textContent = opt.text;
                if (opt.classId) {
                    newOpt.setAttribute('data-class-id', opt.classId);
                }
                if (opt.value === currentSelectedValue) {
                    newOpt.selected = true;
                }
                subjectSelect.appendChild(newOpt);
            }
        });
    }

    classSelect.addEventListener('change', updateSubjects);
    updateSubjects();
}

document.addEventListener('DOMContentLoaded', function() {
    setupDynamicSubjectFilter('session_class', 'session_subject');
});

function joinClass(classId, subjectName) {
    const meetingId = 'class-' + classId + '-' + Date.now();
    const meetingUrl = `https://meet.google.com/${meetingId}`;

    if (confirm(`Join ${subjectName} virtual classroom?\n\nThis will open in a new window.`)) {
        window.open(meetingUrl, '_blank', 'width=1200,height=800');
        logAttendance(classId);
    }
}

function createInstantMeeting() {
    const meetingId = 'meeting-' + Date.now();
    const meetingUrl = `https://meet.google.com/${meetingId}`;

    if (confirm('Create a new virtual meeting?\n\nThis will open in a new window.')) {
        window.open(meetingUrl, '_blank', 'width=1200,height=800');
    }
}

function logAttendance(classId) {
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
</script>
