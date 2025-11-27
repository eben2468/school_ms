<?php
// Teacher Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM student_classes sc
         JOIN classes c ON sc.class_id = c.id
         WHERE c.teacher_id = :user_id) as my_students,
        (SELECT COUNT(*) FROM classes WHERE teacher_id = :user_id) as my_classes,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = :user_id) as my_assignments,
        (SELECT COUNT(*) FROM assignments WHERE teacher_id = :user_id AND due_date < NOW()) as overdue_assignments,
        (SELECT COUNT(*) FROM attendance a
         JOIN classes c ON a.class_id = c.id
         WHERE c.teacher_id = :user_id AND DATE(a.date) = CURDATE()) as today_attendance,
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
        COUNT(sc.student_id) as student_count,
        s.name as subject_name
        FROM classes c
        LEFT JOIN student_classes sc ON c.id = sc.class_id
        LEFT JOIN subjects s ON c.subject_id = s.id
        WHERE c.teacher_id = :user_id
        GROUP BY c.id, c.name, c.grade_level, c.section, s.name
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
        COUNT(CASE WHEN sa.status = 'submitted' THEN 1 END) as submitted_count
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

// Get today's schedule (placeholder - timetable table may not exist)
$today_schedule = [];
?>

<!-- Teacher Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Teacher Dashboard</h1>
                <p class="text-blue-100 text-lg">Inspire, educate, and shape the future</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-chalkboard-teacher mr-2"></i>
                        Educator
                    </div>
                    <div class="flex items-center">
                        <i class="fas fa-calendar-alt mr-2"></i>
                        <?php echo date('l, F j, Y'); ?>
                    </div>
                </div>
                <!-- Academic Context -->
                <div class="mt-4 p-4 bg-white/10 rounded-lg backdrop-blur-sm">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm text-blue-100 mb-1">Current Academic Session</p>
                            <p class="text-lg font-semibold text-white">
                                <?php echo htmlspecialchars($academic_context['year_name']); ?> - <?php echo htmlspecialchars($academic_context['term_name']); ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-apple-alt text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Teacher Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- My Students -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Students</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_students']; ?></p>
                <p class="text-sm text-amber-600 dark:text-amber-400 mt-1">
                    <i class="fas fa-users mr-1"></i>
                    Total enrolled
                </p>
            </div>
            <div class="w-12 h-12 bg-amber-100 dark:bg-amber-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-graduate text-amber-600 dark:text-amber-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- My Classes -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Classes</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_classes']; ?></p>
                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    <i class="fas fa-chalkboard mr-1"></i>
                    Active classes
                </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chalkboard text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Assignments -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Assignments</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_assignments']; ?></p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-tasks mr-1"></i>
                    Currently active
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-tasks text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Pending Grading -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending Grading</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['pending_grading']; ?></p>
                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                    <i class="fas fa-pen mr-1"></i>
                    Need review
                </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-pen text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Teacher Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- My Classes -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
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
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Today's Schedule</h3>
            <span class="text-sm text-gray-500 dark:text-gray-400"><?php echo date('l'); ?></span>
        </div>
        <div class="space-y-4">
            <?php if (!empty($today_schedule)): ?>
                <?php foreach ($today_schedule as $schedule): ?>
                <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="w-16 text-center">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo date('g:i', strtotime($schedule['start_time'])); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo date('A', strtotime($schedule['start_time'])); ?></p>
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($schedule['class_name']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($schedule['subject_name'] ?? 'General'); ?> • Grade <?php echo $schedule['grade_level']; ?>
                        </p>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
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

<!-- Recent Assignments -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
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
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Submissions</th>
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
                                <?php echo $assignment['submitted_count']; ?>/<?php echo $assignment['total_submissions']; ?>
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
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Teaching Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick actions</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
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
        <a href="academic/grades/input.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-pen text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Grade Assignments</span>
        </a>
        <a href="academic/timetable/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-calendar-alt text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">View Timetable</span>
        </a>
        <a href="communication/messages.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-comments text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Messages</span>
        </a>
        <a href="library/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-book text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Library</span>
        </a>
    </div>
</div>
