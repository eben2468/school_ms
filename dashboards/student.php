<?php
// Student Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM student_classes WHERE student_id = :user_id) as my_classes,
        (SELECT COUNT(*) FROM assignments a
         JOIN student_classes sc ON a.class_id = sc.class_id
         WHERE sc.student_id = :user_id AND a.due_date >= NOW()) as pending_assignments,
        (SELECT COUNT(*) FROM assignments a
         JOIN student_classes sc ON a.class_id = sc.class_id
         WHERE sc.student_id = :user_id AND a.due_date < NOW()) as overdue_assignments,
        (SELECT COUNT(*) FROM attendance WHERE student_id = :user_id AND status = 'present' AND MONTH(date) = MONTH(NOW())) as attendance_this_month,
        (SELECT AVG(total_score) FROM student_academic_records WHERE student_id = :user_id) as average_grade";
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':user_id', $user_id);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = [
            'my_classes' => 0,
            'pending_assignments' => 0,
            'overdue_assignments' => 0,
            'attendance_this_month' => 0,
            'average_grade' => 0
        ];
    }
    $stats['borrowed_books'] = 0; // Placeholder
} catch (PDOException $e) {
    $stats = [
        'my_classes' => 0,
        'pending_assignments' => 0,
        'overdue_assignments' => 0,
        'attendance_this_month' => 0,
        'borrowed_books' => 0,
        'average_grade' => 0
    ];
}

// Get student's classes with teachers
try {
    $classes_query = "SELECT
        c.id, c.name, c.grade_level, c.section,
        s.name as subject_name,
        t.name as teacher_name
        FROM student_classes sc
        JOIN classes c ON sc.class_id = c.id
        LEFT JOIN subjects s ON c.subject_id = s.id
        LEFT JOIN users t ON c.teacher_id = t.id
        WHERE sc.student_id = :user_id
        ORDER BY s.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':user_id', $user_id);
    $classes_stmt->execute();
    $my_classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $my_classes = [];
}

// Get upcoming assignments
try {
    $assignments_query = "SELECT
        a.id, a.title, a.description, a.due_date, a.total_marks,
        c.name as class_name,
        s.name as subject_name,
        sa.status as submission_status,
        sa.grade
        FROM assignments a
        JOIN student_classes sc ON a.class_id = sc.class_id
        JOIN classes c ON a.class_id = c.id
        LEFT JOIN subjects s ON c.subject_id = s.id
        LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :user_id
        WHERE sc.student_id = :user_id
        ORDER BY a.due_date ASC
        LIMIT 5";
    $assignments_stmt = $db->prepare($assignments_query);
    $assignments_stmt->bindParam(':user_id', $user_id);
    $assignments_stmt->execute();
    $upcoming_assignments = $assignments_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $upcoming_assignments = [];
}

// Get recent grades
try {
    $grades_query = "SELECT
        sar.total_score, sar.grade, sar.created_at,
        s.name as subject_name,
        c.name as class_name
        FROM student_academic_records sar
        JOIN subjects s ON sar.subject_id = s.id
        LEFT JOIN classes c ON sar.class_id = c.id
        WHERE sar.student_id = :user_id
        ORDER BY sar.created_at DESC
        LIMIT 5";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':user_id', $user_id);
    $grades_stmt->execute();
    $recent_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_grades = [];
}

// Get today's schedule (placeholder)
$today_schedule = [];
?>

<!-- Student Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Student Portal</h1>
                <p class="text-blue-100 text-lg">Your journey to academic excellence starts here</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-user-graduate mr-2"></i>
                        Student
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
                    <i class="fas fa-graduation-cap text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Student Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- My Classes -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Classes</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_classes']; ?></p>
                <p class="text-sm text-cyan-600 dark:text-cyan-400 mt-1">
                    <i class="fas fa-chalkboard mr-1"></i>
                    Enrolled classes
                </p>
            </div>
            <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chalkboard text-cyan-600 dark:text-cyan-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Pending Assignments -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Pending Tasks</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['pending_assignments']; ?></p>
                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                    <i class="fas fa-clock mr-1"></i>
                    Due soon
                </p>
            </div>
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-tasks text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Attendance -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Attendance</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['attendance_this_month']; ?></p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-calendar-check mr-1"></i>
                    Days present
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Average Grade -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Average Grade</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo number_format($stats['average_grade'] ?? 0, 1); ?>%</p>
                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                    <i class="fas fa-star mr-1"></i>
                    Overall performance
                </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-star text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Student Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- Today's Schedule -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Today's Classes</h3>
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
                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($schedule['subject_name'] ?? $schedule['class_name']); ?></p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo htmlspecialchars($schedule['teacher_name'] ?? 'No teacher assigned'); ?>
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

    <!-- Upcoming Assignments -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Upcoming Assignments</h3>
            <a href="academic/assignments/" class="text-sm text-cyan-600 dark:text-cyan-400 hover:text-cyan-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($upcoming_assignments)): ?>
                <?php foreach ($upcoming_assignments as $assignment): ?>
                <div class="p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-start justify-between">
                        <div class="flex-1">
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($assignment['title']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1"><?php echo htmlspecialchars($assignment['subject_name'] ?? $assignment['class_name']); ?></p>
                        </div>
                        <div class="text-right ml-4">
                            <p class="text-xs text-gray-500 dark:text-gray-400">Due</p>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo date('M j', strtotime($assignment['due_date'])); ?></p>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center justify-between">
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            <?php echo $assignment['total_marks']; ?> marks
                        </span>
                        <?php if ($assignment['submission_status'] === 'submitted'): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-green-100 text-green-800">Submitted</span>
                        <?php elseif (strtotime($assignment['due_date']) < time()): ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-red-100 text-red-800">Overdue</span>
                        <?php else: ?>
                            <span class="px-2 py-1 text-xs font-medium rounded-full bg-yellow-100 text-yellow-800">Pending</span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-tasks text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No upcoming assignments</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Grades -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Grades</h3>
        <a href="academic/grades/" class="text-sm text-cyan-600 dark:text-cyan-400 hover:text-cyan-800">View all</a>
    </div>
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-gray-700">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Subject</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Score</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                <?php if (!empty($recent_grades)): ?>
                    <?php foreach ($recent_grades as $grade): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($grade['subject_name']); ?></div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo number_format($grade['total_score'], 1); ?>%</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php
                            $grade_letter = $grade['grade'];
                            $grade_color = 'gray';
                            if ($grade_letter === 'A') $grade_color = 'green';
                            elseif ($grade_letter === 'B') $grade_color = 'blue';
                            elseif ($grade_letter === 'C') $grade_color = 'yellow';
                            elseif ($grade_letter === 'D') $grade_color = 'orange';
                            elseif ($grade_letter === 'F') $grade_color = 'red';
                            ?>
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-<?php echo $grade_color; ?>-100 text-<?php echo $grade_color; ?>-800">
                                <?php echo $grade_letter; ?>
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($grade['created_at'])); ?></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="4" class="px-6 py-4 text-center text-gray-500 dark:text-gray-400">No grades available</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Student Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick access</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="academic/assignments/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-cyan-200 dark:group-hover:bg-cyan-800 transition-colors duration-200">
                <i class="fas fa-tasks text-cyan-600 dark:text-cyan-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Assignments</span>
        </a>
        <a href="academic/grades/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-star text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">My Grades</span>
        </a>
        <a href="attendance/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Attendance</span>
        </a>
        <a href="academic/timetable/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-calendar-alt text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Timetable</span>
        </a>
        <a href="library/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-book text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Library</span>
        </a>
        <a href="communication/messages.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-comments text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Messages</span>
        </a>
    </div>
</div>

<script>
// Update time every second
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
