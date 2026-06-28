<?php
// Teacher Dashboard Content
// These dashboard partials are included by /dashboard.php, which provides
// $db, $user_id, $role and $academic_context. Guard against direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
try {
    // Teacher ↔ class ↔ subject relationships live in the class_teachers junction table.
    $stats_query = "SELECT
        (SELECT COUNT(DISTINCT sc.student_id) FROM student_classes sc
         WHERE sc.status = 'active'
           AND sc.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :user_id)) as my_students,
        (SELECT COUNT(DISTINCT class_id) FROM class_teachers WHERE teacher_id = :user_id) as my_classes,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = :user_id AND status = 'active') as my_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = :user_id AND status = 'active' AND due_date < NOW()) as overdue_assignments,
        (SELECT COUNT(DISTINCT a.id) FROM attendance a
         WHERE a.class_id IN (SELECT class_id FROM class_teachers WHERE teacher_id = :user_id)
           AND DATE(a.date) = CURDATE()) as today_attendance,
        (SELECT COUNT(*) FROM student_assignments sa
         JOIN assignments a ON sa.assignment_id = a.id
         WHERE a.teacher_id = :user_id AND sa.grade IS NULL) as pending_grading";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':user_id', $user_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = [
            'my_students' => 0,
            'my_classes' => 0,
            'my_assignments' => 0,
            'overdue_assignments' => 0,
            'today_attendance' => 0,
            'pending_grading' => 0
        ];
    }
} catch (PDOException $e) {
    $stats = [
        'my_students' => 0,
        'my_classes' => 0,
        'my_assignments' => 0,
        'overdue_assignments' => 0,
        'today_attendance' => 0,
        'pending_grading' => 0
    ];
}

// Get teacher's classes with student counts
try {
    $classes_query = "SELECT
        c.id, c.name, c.grade_level, c.section,
        (SELECT COUNT(*) FROM student_classes sc WHERE sc.class_id = c.id AND sc.status = 'active') as student_count,
        GROUP_CONCAT(DISTINCT s.name ORDER BY s.name SEPARATOR ', ') as subject_name
        FROM class_teachers ct
        JOIN classes c ON ct.class_id = c.id
        LEFT JOIN subjects s ON ct.subject_id = s.id
        WHERE ct.teacher_id = :user_id
        GROUP BY c.id, c.name, c.grade_level, c.section
        ORDER BY c.grade_level, c.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':user_id', $user_id);
    $classes_stmt->execute();
    $my_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $my_classes = [];
}

// Get recent assignments
try {
    $assignments_query = "SELECT
        a.id, a.title, a.due_date,
        c.name as class_name,
        COUNT(sa.id) as total_submissions,
        COUNT(CASE WHEN sa.grade IS NOT NULL THEN 1 END) as graded_count
        FROM assignments a
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN student_assignments sa ON a.id = sa.assignment_id
        WHERE a.teacher_id = :user_id
        GROUP BY a.id, a.title, a.due_date, c.name
        ORDER BY a.due_date ASC
        LIMIT 5";
    $assignments_stmt = $db->prepare($assignments_query);
    $assignments_stmt->bindParam(':user_id', $user_id);
    $assignments_stmt->execute();
    $recent_assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_assignments = [];
}

// Get today's schedule for this teacher from the class_schedule timetable.
try {
    $today_name = date('l'); // e.g. "Thursday"
    $sched_stmt = $db->prepare("SELECT cs.time_slot, cs.room_number,
            c.name AS class_name, c.grade_level,
            s.name AS subject_name
        FROM class_schedule cs
        JOIN classes c ON cs.class_id = c.id
        LEFT JOIN subjects s ON cs.subject_id = s.id
        WHERE cs.teacher_id = :user_id
          AND cs.day = :today
          AND (cs.is_break IS NULL OR cs.is_break = 0)
          AND cs.status = 'active'
        ORDER BY cs.time_slot ASC");
    $sched_stmt->execute([':user_id' => $user_id, ':today' => $today_name]);
    $today_schedule = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $today_schedule = [];
}
?>

<?php
$color_map = [
    'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
    'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
    'green'   => ['bg' => 'bg-green-100 dark:bg-green-900/40', 'text' => 'text-green-600 dark:text-green-400', 'ring' => 'hover:border-green-300 dark:hover:border-green-700'],
    'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
];
?>
<!-- Teacher Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1"><i class="fas fa-chalkboard-teacher mr-1.5"></i> Teacher Portal</p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($_SESSION['name'] ?? 'Teacher'); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Inspire, educate and shape the future.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($academic_context['year_name']); ?> &middot; <?php echo htmlspecialchars($academic_context['term_name']); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-apple-alt text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Teacher Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="Teaching statistics">
    <?php
    $summary_cards = [
        ['label' => 'My Students', 'value' => $stats['my_students'], 'icon' => 'fa-user-graduate', 'color' => 'amber', 'hint' => 'Total enrolled'],
        ['label' => 'My Classes', 'value' => $stats['my_classes'], 'icon' => 'fa-chalkboard', 'color' => 'blue', 'hint' => 'Active classes'],
        ['label' => 'Active Assignments', 'value' => $stats['my_assignments'], 'icon' => 'fa-tasks', 'color' => 'green', 'hint' => 'Currently active'],
        ['label' => 'Pending Grading', 'value' => $stats['pending_grading'], 'icon' => 'fa-pen', 'color' => 'violet', 'hint' => 'Need review'],
    ];
    foreach ($summary_cards as $card):
        $c = $color_map[$card['color']];
    ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm hover:shadow-md p-5 border border-gray-200 dark:border-gray-700 <?php echo $c['ring']; ?> transition-all duration-200">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 <?php echo $c['bg']; ?> rounded-xl flex items-center justify-center flex-shrink-0">
                <i class="fas <?php echo $card['icon']; ?> <?php echo $c['text']; ?> text-xl"></i>
            </div>
            <div class="min-w-0">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 truncate"><?php echo $card['label']; ?></p>
                <p class="text-2xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Teacher Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <!-- My Classes -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">My Classes</h3>
            <a href="academic/classes/" class="text-sm text-amber-600 dark:text-amber-400 hover:text-amber-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($my_classes)): ?>
                <?php foreach ($my_classes as $class): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 transition-colors duration-200">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-amber-100 dark:bg-amber-900 rounded-lg flex items-center justify-center">
                            <span class="text-amber-600 dark:text-amber-400 font-bold text-sm"><?php echo $class['grade_level']; ?></span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo htmlspecialchars($class['subject_name'] ?? 'No subject'); ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo $class['student_count']; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Students</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-chalkboard text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No classes assigned</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Today's Schedule -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Today's Schedule</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo date('l'); ?></span>
        </div>
        <div class="space-y-4">
            <?php if (!empty($today_schedule)): ?>
                <?php foreach ($today_schedule as $schedule):
                    $slot_parts = explode('-', $schedule['time_slot'] ?? '');
                    $slot_start = trim($slot_parts[0] ?? '');
                    $slot_end   = trim($slot_parts[1] ?? '');
                ?>
                <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="w-16 text-center">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo $slot_start ? date('g:i', strtotime($slot_start)) : ''; ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $slot_start ? date('A', strtotime($slot_start)) : ''; ?></p>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($schedule['subject_name'] ?? 'Class'); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($schedule['class_name']); ?><?php if (!empty($schedule['grade_level'])): ?> &middot; Grade <?php echo htmlspecialchars($schedule['grade_level']); ?><?php endif; ?>
                            <?php if (!empty($schedule['room_number'])): ?> &middot; Room <?php echo htmlspecialchars($schedule['room_number']); ?><?php endif; ?>
                        </p>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <?php echo $slot_end ? date('g:i A', strtotime($slot_end)) : ''; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-calendar text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No classes scheduled for today</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Teacher Charts & Analytics -->
<?php
// Build chart data from the teacher's real classes and assignment submissions.
$class_chart = ['labels' => [], 'counts' => []];
foreach ($my_classes as $cl) {
    $class_chart['labels'][] = $cl['name'];
    $class_chart['counts'][] = (int)$cl['student_count'];
}
$graded_total = 0; $submitted_total = 0;
foreach ($recent_assignments as $a) {
    $graded_total += (int)$a['graded_count'];
    $submitted_total += (int)$a['total_submissions'];
}
$ungraded_total = max($submitted_total - $graded_total, 0);
?>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <!-- Students per Class -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 lg:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Students per Class</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400">Current enrollment</span>
        </div>
        <div class="h-64"><canvas id="teacherClassChart"></canvas></div>
    </div>
    <!-- Grading Progress -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Grading Progress</h3>
        </div>
        <div class="h-64"><canvas id="teacherGradingChart"></canvas></div>
    </div>
</div>

<!-- Recent Assignments -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Assignments</h3>
        <a href="academic/assignments/" class="text-sm text-amber-600 dark:text-amber-400 hover:text-amber-800">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Assignment</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Class</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Due Date</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Graded / Submitted</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (!empty($recent_assignments)): ?>
                    <?php foreach ($recent_assignments as $assignment): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['class_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($assignment['due_date'])); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white">
                                <?php echo $assignment['graded_count']; ?>/<?php echo $assignment['total_submissions']; ?>
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $due_date = strtotime($assignment['due_date']);
                            $now = time();
                            if ($due_date < $now) {
                                echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">Overdue</span>';
                            } else {
                                echo '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Active</span>';
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No assignments found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Teacher Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Teaching Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick actions</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4">
        <a href="attendance/take.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-amber-200 dark:group-hover:bg-amber-800 transition-colors duration-200">
                <i class="fas fa-calendar-check text-amber-600 dark:text-amber-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Take Attendance</span>
        </a>
        <a href="academic/assignments/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-tasks text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Create Assignment</span>
        </a>
        <a href="academic/grades/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-pen text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Grade Assignments</span>
        </a>
        <a href="academic/timetable/teacher.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-calendar-alt text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">View Timetable</span>
        </a>
        <a href="communication/messages/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-comments text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Messages</span>
        </a>
        <a href="library/books/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-book text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Library</span>
        </a>
        <a href="academic/reports/compilation.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-clipboard-check text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Report Compilation</span>
        </a>
    </div>
</div>

<!-- Chart.js Library -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const TEACHER_CLASS = <?php echo json_encode($class_chart, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const TEACHER_GRADING = <?php echo json_encode(['graded' => $graded_total, 'pending' => $ungraded_total], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
(function () {
    let classChart = null, gradeChart = null;

    function render() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.10)';

        const cc = document.getElementById('teacherClassChart');
        if (cc) {
            const labels = TEACHER_CLASS.labels.length ? TEACHER_CLASS.labels : ['No classes'];
            const counts = TEACHER_CLASS.counts.length ? TEACHER_CLASS.counts : [0];
            if (classChart) classChart.destroy();
            classChart = new Chart(cc, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Students', data: counts,
                        backgroundColor: 'rgba(245,158,11,0.65)', hoverBackgroundColor: 'rgba(245,158,11,0.9)',
                        borderRadius: 8, maxBarThickness: 40 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { cornerRadius: 8, displayColors: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: tick, font: { size: 11 }, precision: 0 } }
                    }
                }
            });
        }

        const gc = document.getElementById('teacherGradingChart');
        if (gc) {
            const hasData = (TEACHER_GRADING.graded + TEACHER_GRADING.pending) > 0;
            if (gradeChart) gradeChart.destroy();
            gradeChart = new Chart(gc, {
                type: 'doughnut',
                data: {
                    labels: ['Graded', 'Pending'],
                    datasets: [{ data: hasData ? [TEACHER_GRADING.graded, TEACHER_GRADING.pending] : [1, 0],
                        backgroundColor: ['#10b981', '#f59e0b'], borderColor: isDark ? '#1f2937' : '#fff',
                        borderWidth: 3, hoverOffset: 6 }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '64%',
                    plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, boxWidth: 10, color: tick, font: { size: 12 } } },
                               tooltip: { cornerRadius: 8 } }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', render);
    window.addEventListener('themeChanged', render);
}());
</script>
