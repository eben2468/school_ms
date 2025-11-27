<?php
// Principal Dashboard Content
try {
    $stats_query = "SELECT
        (SELECT COUNT(*) FROM users WHERE role = 'student') as total_students,
        (SELECT COUNT(*) FROM users WHERE role = 'teacher') as total_teachers,
        (SELECT COUNT(*) FROM classes) as total_classes,
        (SELECT COUNT(*) FROM subjects) as total_subjects,
        (SELECT AVG(CASE WHEN status = 'present' THEN 1 ELSE 0 END) * 100 FROM attendance WHERE MONTH(date) = MONTH(NOW())) as attendance_rate,
        (SELECT COUNT(*) FROM assignments) as active_assignments";
    $stats_stmt = $db->query($stats_query);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stats) {
        $stats = [
            'total_students' => 0,
            'total_teachers' => 0,
            'total_classes' => 0,
            'total_subjects' => 0,
            'attendance_rate' => 0,
            'active_assignments' => 0
        ];
    }
    $stats['upcoming_exams'] = 0;
    $stats['teachers_online_today'] = 0;
} catch (PDOException $e) {
    $stats = [
        'total_students' => 0,
        'total_teachers' => 0,
        'total_classes' => 0,
        'total_subjects' => 0,
        'attendance_rate' => 0,
        'upcoming_exams' => 0,
        'active_assignments' => 0,
        'teachers_online_today' => 0
    ];
}

// Get academic performance overview
try {
    $performance_query = "SELECT
        c.name as class_name,
        c.grade_level,
        COUNT(sc.student_id) as student_count,
        AVG(CASE WHEN a.status = 'present' THEN 1 ELSE 0 END) * 100 as attendance_rate
        FROM classes c
        LEFT JOIN student_classes sc ON c.id = sc.class_id
        LEFT JOIN attendance a ON sc.student_id = a.student_id AND MONTH(a.date) = MONTH(NOW())
        GROUP BY c.id, c.name, c.grade_level
        ORDER BY c.grade_level";
    $performance_stmt = $db->query($performance_query);
    $class_performance = $performance_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $class_performance = [];
}

// Get recent achievements and events (placeholder)
$recent_achievements = [];

// Get teacher performance metrics
try {
    $teacher_metrics_query = "SELECT
        u.name as teacher_name,
        COUNT(DISTINCT c.id) as classes_taught,
        COUNT(DISTINCT a.id) as assignments_given,
        AVG(CASE WHEN att.status = 'present' THEN 1 ELSE 0 END) * 100 as class_attendance_rate
        FROM users u
        LEFT JOIN classes c ON u.id = c.teacher_id
        LEFT JOIN assignments a ON u.id = a.teacher_id
        LEFT JOIN attendance att ON c.id = att.class_id AND MONTH(att.date) = MONTH(NOW())
        WHERE u.role = 'teacher'
        GROUP BY u.id, u.name
        ORDER BY classes_taught DESC
        LIMIT 5";
    $teacher_metrics_stmt = $db->query($teacher_metrics_query);
    $teacher_metrics = $teacher_metrics_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $teacher_metrics = [];
}
?>

<!-- Principal Header -->
<div class="mb-8">
    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold mb-2">Principal's Office</h1>
                <p class="text-blue-100 text-lg">Leading academic excellence and institutional growth</p>
                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                    <div class="flex items-center">
                        <i class="fas fa-medal mr-2"></i>
                        Academic Leadership
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
                        <a href="academic/settings/" class="text-blue-100 hover:text-white transition-colors duration-200">
                            <i class="fas fa-cog text-lg"></i>
                        </a>
                    </div>
                </div>
            </div>
            <div class="hidden md:block">
                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                    <i class="fas fa-user-tie text-6xl text-white/80"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Principal Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <!-- Total Students -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Students</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_students']; ?></p>
                <p class="text-sm text-emerald-600 dark:text-emerald-400 mt-1">
                    <i class="fas fa-graduation-cap mr-1"></i>
                    Enrolled students
                </p>
            </div>
            <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-user-graduate text-emerald-600 dark:text-emerald-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Faculty Strength -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Faculty Strength</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_teachers']; ?></p>
                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                    <i class="fas fa-chalkboard-teacher mr-1"></i>
                    Teaching staff
                </p>
            </div>
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-chalkboard-teacher text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Attendance Rate -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Attendance Rate</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo number_format($stats['attendance_rate'] ?? 0, 1); ?>%</p>
                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                    <i class="fas fa-calendar-check mr-1"></i>
                    This month
                </p>
            </div>
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
            </div>
        </div>
    </div>

    <!-- Active Programs -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Programs</p>
                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['total_subjects']; ?></p>
                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                    <i class="fas fa-book mr-1"></i>
                    Subject offerings
                </p>
            </div>
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                <i class="fas fa-book text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- Academic Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- Class Performance Overview -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Class Performance</h3>
            <a href="academic/classes/" class="text-sm text-emerald-600 dark:text-emerald-400 hover:text-emerald-800">View all</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($class_performance)): ?>
                <?php foreach (array_slice($class_performance, 0, 5) as $class): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center">
                            <span class="text-emerald-600 dark:text-emerald-400 font-bold text-sm"><?php echo $class['grade_level']; ?></span>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['class_name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $class['student_count']; ?> students</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format($class['attendance_rate'] ?? 0, 1); ?>%</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Attendance</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-chalkboard text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No class data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Teacher Performance -->
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Faculty Performance</h3>
            <a href="reports/teacher_performance.php" class="text-sm text-emerald-600 dark:text-emerald-400 hover:text-emerald-800">View report</a>
        </div>
        <div class="space-y-4">
            <?php if (!empty($teacher_metrics)): ?>
                <?php foreach ($teacher_metrics as $teacher): ?>
                <div class="flex items-center justify-between p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-user-tie text-blue-600 dark:text-blue-400"></i>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($teacher['teacher_name']); ?></p>
                            <p class="text-xs text-gray-500 dark:text-gray-400"><?php echo $teacher['classes_taught']; ?> classes, <?php echo $teacher['assignments_given']; ?> assignments</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-sm font-bold text-gray-900 dark:text-white"><?php echo number_format($teacher['class_attendance_rate'] ?? 0, 1); ?>%</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Class attendance</p>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-user-tie text-4xl text-gray-300 dark:text-gray-600 mb-4"></i>
                    <p class="text-gray-500 dark:text-gray-400">No teacher data available</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Principal Quick Actions -->
<div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Leadership Actions</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Principal tools</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="academic/exams/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-emerald-200 dark:group-hover:bg-emerald-800 transition-colors duration-200">
                <i class="fas fa-file-alt text-emerald-600 dark:text-emerald-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Schedule Exam</span>
        </a>
        <a href="reports/academic.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-chart-line text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Reports</span>
        </a>
        <a href="communication/announcements.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-bullhorn text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Announcements</span>
        </a>
        <a href="academic/class-management.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                <i class="fas fa-user-friends text-orange-600 dark:text-orange-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Class Management</span>
        </a>
        <a href="academic/promotions.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-level-up-alt text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Student Promotion</span>
        </a>
        <a href="settings/school.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-school text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">School Settings</span>
        </a>
    </div>
</div>
