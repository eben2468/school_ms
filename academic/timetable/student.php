<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Student';

try {
    // Get student's active class
    $class_query = "SELECT c.id, c.name, c.grade_level
                    FROM student_classes sc
                    JOIN classes c ON sc.class_id = c.id
                    WHERE sc.student_id = :user_id AND sc.status = 'active'
                    LIMIT 1";
    $class_stmt = $db->prepare($class_query);
    $class_stmt->bindParam(':user_id', $user_id);
    $class_stmt->execute();
    $student_class = $class_stmt->fetch(PDO::FETCH_ASSOC);

    $schedules = [];
    $time_slots = [];

    if ($student_class) {
        // Get class schedule
        $schedule_query = "SELECT cs.*, s.name as subject_name, u.name as teacher_name
                          FROM class_schedule cs
                          LEFT JOIN subjects s ON cs.subject_id = s.id
                          LEFT JOIN users u ON cs.teacher_id = u.id
                          WHERE cs.class_id = :class_id
                          ORDER BY cs.time_slot, FIELD(cs.day, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday')";
        $schedule_stmt = $db->prepare($schedule_query);
        $schedule_stmt->bindParam(':class_id', $student_class['id']);
        $schedule_stmt->execute();
        $schedules = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get unique time slots
        $time_slots_query = "SELECT DISTINCT time_slot FROM class_schedule WHERE class_id = :class_id ORDER BY time_slot";
        $ts_stmt = $db->prepare($time_slots_query);
        $ts_stmt->bindParam(':class_id', $student_class['id']);
        $ts_stmt->execute();
        $time_slots = $ts_stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // Get today's schedule
    $today = date('l'); // e.g. "Monday"
    $today_classes = array_filter($schedules, function($s) use ($today) {
        return $s['day'] === $today && !$s['is_break'];
    });

} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
}

// Helper to get schedule for a specific day and time slot
function getScheduleForSlot($schedules, $day, $time_slot) {
    foreach ($schedules as $s) {
        if ($s['day'] === $day && $s['time_slot'] === $time_slot) {
            return $s;
        }
    }
    return null;
}

// Generate color for subject (consistent based on name)
function getSubjectColor($subject_name) {
    $colors = [
        ['from-blue-500', 'to-blue-600', 'bg-blue-50', 'text-blue-700', 'border-blue-200'],
        ['from-purple-500', 'to-purple-600', 'bg-purple-50', 'text-purple-700', 'border-purple-200'],
        ['from-green-500', 'to-green-600', 'bg-green-50', 'text-green-700', 'border-green-200'],
        ['from-orange-500', 'to-orange-600', 'bg-orange-50', 'text-orange-700', 'border-orange-200'],
        ['from-pink-500', 'to-pink-600', 'bg-pink-50', 'text-pink-700', 'border-pink-200'],
        ['from-cyan-500', 'to-cyan-600', 'bg-cyan-50', 'text-cyan-700', 'border-cyan-200'],
        ['from-indigo-500', 'to-indigo-600', 'bg-indigo-50', 'text-indigo-700', 'border-indigo-200'],
        ['from-teal-500', 'to-teal-600', 'bg-teal-50', 'text-teal-700', 'border-teal-200'],
    ];
    $index = abs(crc32($subject_name ?? '')) % count($colors);
    return $colors[$index];
}

// Data for the shared printable timetable
$print_schedules = $schedules;
$print_time_slots = $time_slots;
$print_class_label = $student_class
    ? ('Grade ' . $student_class['grade_level'] . ' - ' . $student_class['name'])
    : 'Timetable';

$title = "My Timetable";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Title -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-4 mb-8 text-white">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-calendar-alt mr-3"></i>
                                My Timetable
                            </h1>
                            <p class="text-blue-100">Your weekly class schedule</p>
                        </div>
                        <?php if ($student_class): ?>
                        <div class="text-right">
                            <div class="text-lg font-bold"><?= htmlspecialchars($student_class['grade_level'] . ' - ' . $student_class['name']) ?></div>
                            <div class="text-sm text-blue-100"><?= count($today_classes) ?> classes today (<?= date('l') ?>)</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!$student_class): ?>
                    <!-- No class assigned -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-12 text-center border border-gray-200 dark:border-gray-700">
                        <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-calendar-times text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Class Assigned</h3>
                        <p class="text-gray-600 dark:text-gray-400">You are not currently enrolled in any class. Please contact your school administration.</p>
                    </div>
                <?php elseif (empty($schedules)): ?>
                    <!-- No schedule set -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-12 text-center border border-gray-200 dark:border-gray-700">
                        <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-calendar text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Schedule Available</h3>
                        <p class="text-gray-600 dark:text-gray-400">The timetable for your class has not been set up yet. Please check back later.</p>
                    </div>
                <?php else: ?>
                    <!-- Today's Classes -->
                    <?php if (!empty($today_classes)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 mb-8 border border-gray-200 dark:border-gray-700">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                            <i class="fas fa-sun mr-2 text-yellow-500"></i>
                            Today's Classes (<?= date('l, F j') ?>)
                        </h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                            <?php foreach ($today_classes as $class): 
                                $color = getSubjectColor($class['subject_name']);
                            ?>
                            <div class="rounded-xl p-4 border <?= $color[4] ?> <?= $color[2] ?> dark:bg-gray-700 dark:border-gray-600 hover:shadow-md transition-shadow">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-xs font-bold <?= $color[3] ?> dark:text-gray-300 uppercase tracking-wide"><?= htmlspecialchars($class['time_slot']) ?></span>
                                </div>
                                <h3 class="font-semibold text-gray-900 dark:text-white text-sm"><?= htmlspecialchars($class['subject_name'] ?? 'Free Period') ?></h3>
                                <?php if ($class['teacher_name']): ?>
                                <p class="text-xs text-gray-600 dark:text-gray-400 mt-1">
                                    <i class="fas fa-user-tie mr-1"></i> <?= htmlspecialchars($class['teacher_name']) ?>
                                </p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Weekly Timetable -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-table mr-2"></i>
                                Weekly Schedule
                            </h2>
                            <button onclick="printTimetable()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm flex items-center" title="Print timetable">
                                <i class="fas fa-print mr-2"></i>Print Timetable
                            </button>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider w-28">Time</th>
                                        <?php
                                        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'];
                                        foreach ($days as $day):
                                            $is_today = ($day === $today);
                                        ?>
                                        <th class="px-4 py-3 text-center text-xs font-semibold uppercase tracking-wider <?= $is_today ? 'text-blue-600 dark:text-blue-400 bg-blue-50 dark:bg-blue-900/30' : 'text-gray-500 dark:text-gray-300' ?>">
                                            <?= $day ?>
                                            <?php if ($is_today): ?>
                                            <span class="block text-[10px] font-normal normal-case text-blue-500">(Today)</span>
                                            <?php endif; ?>
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($time_slots as $time_slot):
                                        // Check if this is a break row
                                        $sample = getScheduleForSlot($schedules, 'Monday', $time_slot);
                                        $is_break = $sample && $sample['is_break'];
                                    ?>
                                    <tr class="<?= $is_break ? 'bg-purple-50 dark:bg-purple-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-gray-700 dark:text-gray-300">
                                            <?= htmlspecialchars($time_slot) ?>
                                        </td>
                                        <?php if ($is_break): ?>
                                            <td colspan="5" class="px-4 py-3 text-center">
                                                <span class="text-purple-700 dark:text-purple-300 font-semibold text-sm">
                                                    <i class="fas fa-mug-hot mr-2"></i>
                                                    <?= htmlspecialchars($sample['break_name'] ?? 'Break') ?>
                                                </span>
                                            </td>
                                        <?php else: ?>
                                            <?php foreach ($days as $day):
                                                $slot = getScheduleForSlot($schedules, $day, $time_slot);
                                                $is_today_col = ($day === $today);
                                                $color = $slot && $slot['subject_name'] ? getSubjectColor($slot['subject_name']) : null;
                                            ?>
                                            <td class="px-2 py-2 text-center <?= $is_today_col ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' ?>">
                                                <?php if ($slot && $slot['subject_name']): ?>
                                                <div class="rounded-lg p-2 <?= $color[2] ?> dark:bg-gray-700 border <?= $color[4] ?> dark:border-gray-600">
                                                    <div class="text-xs font-bold <?= $color[3] ?> dark:text-white truncate"><?= htmlspecialchars($slot['subject_name']) ?></div>
                                                    <?php if ($slot['teacher_name']): ?>
                                                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-0.5 truncate"><?= htmlspecialchars($slot['teacher_name']) ?></div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php else: ?>
                                                <span class="text-gray-300 dark:text-gray-600">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<?php if (!empty($schedules)) include '_print_timetable.php'; ?>
