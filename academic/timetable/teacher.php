<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['name'] ?? 'Teacher';

$schedules = [];
$time_slots = [];
$my_classes = [];
$selected_class = null;
$my_period_count = 0;

try {
    // Classes this teacher is assigned to teach (via the class_teachers junction)
    $cls_stmt = $db->prepare("SELECT DISTINCT c.id, c.name, c.grade_level
                              FROM class_teachers ct
                              JOIN classes c ON ct.class_id = c.id
                              WHERE ct.teacher_id = :tid AND c.status = 'active'
                              ORDER BY c.grade_level, c.name");
    $cls_stmt->bindParam(':tid', $user_id, PDO::PARAM_INT);
    $cls_stmt->execute();
    $my_classes = $cls_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Selected class — must be one the teacher actually teaches
    $requested = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $allowed_ids = array_column($my_classes, 'id');
    if ($requested && in_array((int)$requested, array_map('intval', $allowed_ids))) {
        $selected_class_id = (int)$requested;
    } elseif (!empty($my_classes)) {
        $selected_class_id = (int)$my_classes[0]['id'];
    } else {
        $selected_class_id = 0;
    }

    foreach ($my_classes as $c) {
        if ((int)$c['id'] === $selected_class_id) { $selected_class = $c; break; }
    }

    if ($selected_class_id) {
        $sch = $db->prepare("SELECT cs.*, s.name as subject_name, u.name as teacher_name
                             FROM class_schedule cs
                             LEFT JOIN subjects s ON cs.subject_id = s.id
                             LEFT JOIN users u ON cs.teacher_id = u.id
                             WHERE cs.class_id = :class_id
                             ORDER BY cs.time_slot, FIELD(cs.day, 'Monday','Tuesday','Wednesday','Thursday','Friday')");
        $sch->bindParam(':class_id', $selected_class_id, PDO::PARAM_INT);
        $sch->execute();
        $schedules = $sch->fetchAll(PDO::FETCH_ASSOC);

        $ts = $db->prepare("SELECT DISTINCT time_slot FROM class_schedule WHERE class_id = :class_id ORDER BY time_slot");
        $ts->bindParam(':class_id', $selected_class_id, PDO::PARAM_INT);
        $ts->execute();
        $time_slots = $ts->fetchAll(PDO::FETCH_COLUMN);

        foreach ($schedules as $s) {
            if (!$s['is_break'] && (int)$s['teacher_id'] === $user_id) { $my_period_count++; }
        }
    }

    $today = date('l');
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $today = date('l');
}

function getScheduleForSlot($schedules, $day, $time_slot) {
    foreach ($schedules as $s) {
        if ($s['day'] === $day && $s['time_slot'] === $time_slot) { return $s; }
    }
    return null;
}

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
$print_class_label = $selected_class
    ? ('Grade ' . $selected_class['grade_level'] . ' - ' . $selected_class['name'])
    : 'Timetable';

$title = "Class Timetables";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Page Title -->
                <div class="bg-gradient-to-r from-blue-600 to-purple-600 rounded-xl p-4 mb-8 text-white">
                    <div class="flex items-center justify-between flex-wrap gap-3">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">
                                <i class="fas fa-calendar-alt mr-3"></i>Class Timetables
                            </h1>
                            <p class="text-blue-100">Weekly schedules for the classes you teach</p>
                        </div>
                        <?php if ($selected_class): ?>
                        <div class="text-right">
                            <div class="text-lg font-bold"><?= htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']) ?></div>
                            <div class="text-sm text-blue-100"><?= $my_period_count ?> period<?= $my_period_count === 1 ? '' : 's' ?> taught by you</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (empty($my_classes)): ?>
                    <!-- No classes assigned -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-12 text-center border border-gray-200 dark:border-gray-700">
                        <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-calendar-times text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Classes Assigned</h3>
                        <p class="text-gray-600 dark:text-gray-400">You are not currently assigned to teach any classes. Please contact your school administration.</p>
                    </div>
                <?php else: ?>
                    <!-- Class Selector -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
                        <div class="p-6">
                            <form action="" method="GET" class="flex flex-col sm:flex-row sm:items-end gap-4">
                                <div class="flex-grow">
                                    <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Select Class</label>
                                    <select id="class_id" name="class_id" onchange="this.form.submit()"
                                        class="block w-full px-3 py-2 border border-gray-300 dark:border-gray-700 dark:bg-gray-700 dark:text-white rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <?php foreach ($my_classes as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $selected_class_id ? 'selected' : '' ?>>
                                            Grade <?= htmlspecialchars($c['grade_level']) ?> - <?= htmlspecialchars($c['name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <i class="fas fa-circle text-blue-500 mr-1 text-xs"></i> highlighted cells are your lessons
                                </div>
                            </form>
                        </div>
                    </div>

                    <?php if (empty($schedules)): ?>
                    <!-- No schedule set -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-12 text-center border border-gray-200 dark:border-gray-700">
                        <div class="w-20 h-20 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-calendar text-gray-400 text-3xl"></i>
                        </div>
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-2">No Schedule Available</h3>
                        <p class="text-gray-600 dark:text-gray-400">The timetable for this class has not been set up yet. Please check back later.</p>
                    </div>
                    <?php else: ?>
                    <!-- Weekly Timetable -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-3">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">
                                <i class="fas fa-table mr-2"></i>Weekly Schedule
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
                                            <?php if ($is_today): ?><span class="block text-[10px] font-normal normal-case text-blue-500">(Today)</span><?php endif; ?>
                                        </th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($time_slots as $time_slot):
                                        $sample = getScheduleForSlot($schedules, 'Monday', $time_slot);
                                        $is_break = $sample && $sample['is_break'];
                                    ?>
                                    <tr class="<?= $is_break ? 'bg-purple-50 dark:bg-purple-900/20' : 'hover:bg-gray-50 dark:hover:bg-gray-700' ?>">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-semibold text-gray-700 dark:text-gray-300"><?= htmlspecialchars($time_slot) ?></td>
                                        <?php if ($is_break): ?>
                                            <td colspan="5" class="px-4 py-3 text-center">
                                                <span class="text-purple-700 dark:text-purple-300 font-semibold text-sm">
                                                    <i class="fas fa-mug-hot mr-2"></i><?= htmlspecialchars($sample['break_name'] ?? 'Break') ?>
                                                </span>
                                            </td>
                                        <?php else: ?>
                                            <?php foreach ($days as $day):
                                                $slot = getScheduleForSlot($schedules, $day, $time_slot);
                                                $is_today_col = ($day === $today);
                                                $is_mine = $slot && (int)$slot['teacher_id'] === $user_id;
                                                $color = $slot && $slot['subject_name'] ? getSubjectColor($slot['subject_name']) : null;
                                            ?>
                                            <td class="px-2 py-2 text-center <?= $is_today_col ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' ?>">
                                                <?php if ($slot && $slot['subject_name']): ?>
                                                <div class="rounded-lg p-2 <?= $color[2] ?> dark:bg-gray-700 border <?= $is_mine ? 'border-blue-500 ring-2 ring-blue-400/40' : $color[4] . ' dark:border-gray-600' ?>">
                                                    <div class="text-xs font-bold <?= $color[3] ?> dark:text-white truncate"><?= htmlspecialchars($slot['subject_name']) ?></div>
                                                    <?php if ($slot['teacher_name']): ?>
                                                    <div class="text-[10px] <?= $is_mine ? 'text-blue-600 dark:text-blue-300 font-semibold' : 'text-gray-500 dark:text-gray-400' ?> mt-0.5 truncate">
                                                        <?php if ($is_mine): ?><i class="fas fa-user-check mr-0.5"></i><?php endif; ?><?= htmlspecialchars($slot['teacher_name']) ?>
                                                    </div>
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
