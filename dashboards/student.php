<?php
// Student Dashboard Content
// Included by /dashboard.php (provides $db, $user_id, $academic_context). Guard direct access.
if (!isset($db)) { header('Location: ../dashboard.php'); exit(); }
require_once dirname(__DIR__) . '/finance/includes/finance_functions.php';

// Get student's finance summary
try {
    $fin_stmt = $db->prepare("SELECT 
        COALESCE(SUM(total_amount + penalty_amount - discount_amount), 0) as total_charged,
        COALESCE(SUM(amount_paid), 0) as total_paid
        FROM finance_invoices 
        WHERE student_id = :user_id AND status != 'cancelled'");
    $fin_stmt->bindParam(':user_id', $user_id);
    $fin_stmt->execute();
    $fin_summary = $fin_stmt->fetch(PDO::FETCH_ASSOC);
    
    $total_charged = (float)$fin_summary['total_charged'];
    $total_paid = (float)$fin_summary['total_paid'];
    $net_balance = $total_charged - $total_paid;
} catch (PDOException $e) {
    $total_charged = 0.00;
    $total_paid = 0.00;
    $net_balance = 0.00;
}

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
        (SELECT AVG(sar2.total_score) FROM student_academic_records sar2
         JOIN subjects s2 ON sar2.subject_id = s2.id
         WHERE sar2.student_id = :user_id
           AND s2.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :user_id AND status = 'active')) as average_grade";
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
        LEFT JOIN subjects s ON a.subject_id = s.id
        LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :user_id
        WHERE sc.student_id = :user_id AND sc.status = 'active' AND a.status = 'active'
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
          AND s.class_id IN (SELECT class_id FROM student_classes WHERE student_id = :user_id AND status = 'active')
        ORDER BY sar.created_at DESC
        LIMIT 5";
    $grades_stmt = $db->prepare($grades_query);
    $grades_stmt->bindParam(':user_id', $user_id);
    $grades_stmt->execute();
    $recent_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_grades = [];
}

// Get today's class schedule for the student's active class
try {
    $today_name = date('l'); // e.g. "Wednesday"
    $sched_stmt = $db->prepare("SELECT cs.time_slot, cs.room_number,
            s.name AS subject_name,
            u.name AS teacher_name
        FROM student_classes sc
        JOIN class_schedule cs ON cs.class_id = sc.class_id
        LEFT JOIN subjects s ON cs.subject_id = s.id
        LEFT JOIN users u ON cs.teacher_id = u.id
        WHERE sc.student_id = :user_id AND sc.status = 'active'
          AND cs.day = :today
          AND (cs.is_break IS NULL OR cs.is_break = 0)
          AND cs.status = 'active'
        ORDER BY cs.time_slot ASC");
    $sched_stmt->execute([':user_id' => $user_id, ':today' => $today_name]);
    $today_schedule = $sched_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $today_schedule = [];
}

/**
 * Analytics datasets powering the charts. Each query is wrapped defensively so a
 * missing table never breaks the dashboard (mirrors the parent dashboard approach).
 */
$current_year_id = $academic_context['year_id'] ?? null;

// --- Attendance breakdown + percentage ---
$attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'percentage' => 0];
try {
    $att_stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE student_id = :sid GROUP BY status");
    $att_stmt->execute([':sid' => $user_id]);
    foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $key = strtolower($row['status']);
        if (isset($attendance[$key])) {
            $attendance[$key] = (int)$row['cnt'];
        }
        $attendance['total'] += (int)$row['cnt'];
    }
    if ($attendance['total'] > 0) {
        $attendance['percentage'] = round((($attendance['present'] + $attendance['late']) / $attendance['total']) * 100, 1);
    }
} catch (Exception $e) { /* table may not exist */ }

// --- 6-month attendance trend (present-rate per month) ---
$att_trend = [];
try {
    $trend_stmt = $db->prepare("SELECT DATE_FORMAT(date, '%Y-%m') as ym,
            SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_cnt,
            COUNT(*) as total_cnt
        FROM attendance
        WHERE student_id = :sid AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY ym ORDER BY ym");
    $trend_stmt->execute([':sid' => $user_id]);
    foreach ($trend_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rate = $row['total_cnt'] > 0 ? round(($row['present_cnt'] / $row['total_cnt']) * 100, 1) : 0;
        $att_trend[] = ['label' => date('M', strtotime($row['ym'] . '-01')), 'value' => $rate];
    }
} catch (Exception $e) { /* ignore */ }

// --- Subject performance (current year, average score per subject) ---
$subject_perf = [];
try {
    $sub_stmt = $db->prepare("SELECT s.name as subject_name, AVG(sar.total_score) as score
        FROM student_academic_records sar
        JOIN subjects s ON sar.subject_id = s.id
        WHERE sar.student_id = :sid " . ($current_year_id ? "AND sar.academic_year_id = :yid" : "") . "
          AND sar.total_score IS NOT NULL
        GROUP BY s.id, s.name
        ORDER BY s.name");
    $params = [':sid' => $user_id];
    if ($current_year_id) { $params[':yid'] = $current_year_id; }
    $sub_stmt->execute($params);
    foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $subject_perf[] = ['name' => $row['subject_name'], 'score' => round((float)$row['score'], 1)];
    }
} catch (Exception $e) { /* ignore */ }

// --- Performance trend across terms (current year) ---
$term_trend = [];
try {
    $tt_stmt = $db->prepare("SELECT at.term_name, at.term_number, AVG(sar.total_score) as score
        FROM student_academic_records sar
        JOIN academic_terms at ON sar.academic_term_id = at.id
        WHERE sar.student_id = :sid " . ($current_year_id ? "AND sar.academic_year_id = :yid" : "") . "
          AND sar.total_score IS NOT NULL
        GROUP BY at.id, at.term_name, at.term_number
        ORDER BY at.term_number");
    $params = [':sid' => $user_id];
    if ($current_year_id) { $params[':yid'] = $current_year_id; }
    $tt_stmt->execute($params);
    foreach ($tt_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $term_trend[] = ['label' => $row['term_name'], 'value' => round((float)$row['score'], 1)];
    }
} catch (Exception $e) { /* ignore */ }

// Bundle for the front-end chart renderer
$student_chart_data = [
    'attendance' => $attendance,
    'att_trend'  => $att_trend,
    'subjects'   => $subject_perf,
    'term_trend' => $term_trend,
];
?>

<!-- Student Header -->
<section class="mb-6" aria-label="Welcome">
    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
        <div class="relative flex items-center justify-between gap-4">
            <div>
                <p class="text-blue-100/90 text-sm font-medium mb-1">
                    <i class="fas fa-user-graduate mr-1.5"></i> Student Portal
                </p>
                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                <p class="text-blue-100 text-sm sm:text-base">Track your classes, assignments and academic progress all in one place.</p>
                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                    <span class="flex items-center"><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($academic_context['year_name']); ?> &middot; <?php echo htmlspecialchars($academic_context['term_name']); ?></span>
                </div>
            </div>
            <div class="hidden md:flex flex-shrink-0">
                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                    <i class="fas fa-user-graduate text-5xl text-white/85"></i>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Student Statistics -->
<section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="Summary statistics">
    <?php
    $summary_cards = [
        ['label' => 'My Classes', 'value' => (int)($stats['my_classes'] ?? 0), 'icon' => 'fa-chalkboard', 'color' => 'blue', 'hint' => 'Enrolled classes'],
        ['label' => 'Pending Tasks', 'value' => (int)($stats['pending_assignments'] ?? 0), 'icon' => 'fa-tasks', 'color' => 'amber', 'hint' => 'Due soon'],
        ['label' => 'Attendance', 'value' => ($attendance['total'] > 0 ? $attendance['percentage'] . '%' : '—'), 'icon' => 'fa-calendar-check', 'color' => 'emerald', 'hint' => 'Present rate'],
        ['label' => 'Average Grade', 'value' => (($stats['average_grade'] ?? 0) > 0 ? number_format($stats['average_grade'], 1) . '%' : '—'), 'icon' => 'fa-star', 'color' => 'violet', 'hint' => 'Overall performance'],
    ];
    $color_map = [
        'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
        'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
        'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
        'amber'   => ['bg' => 'bg-amber-100 dark:bg-amber-900/40', 'text' => 'text-amber-600 dark:text-amber-400', 'ring' => 'hover:border-amber-300 dark:hover:border-amber-700'],
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
                <p class="text-xl font-bold text-gray-900 dark:text-white truncate"><?php echo $card['value']; ?></p>
                <p class="text-[11px] text-gray-400 dark:text-gray-500 truncate"><?php echo $card['hint']; ?></p>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</section>

<!-- Performance & Attendance Charts -->
<section class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6" aria-label="Performance analytics" id="student-analytics">
    <!-- Subject performance -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Subject Performance</h3>
            <span class="text-xs text-gray-400"><i class="fas fa-book"></i></span>
        </div>
        <div class="relative h-56">
            <canvas id="subjectChart" role="img" aria-label="Bar chart of scores per subject"></canvas>
            <?php if (empty($subject_perf)): ?>
            <p class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No grades recorded yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Attendance breakdown -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Attendance Breakdown</h3>
            <span class="text-xs text-gray-400"><i class="fas fa-calendar-check"></i></span>
        </div>
        <div class="relative h-56">
            <canvas id="attendanceChart" role="img" aria-label="Doughnut chart of attendance status"></canvas>
            <?php if ($attendance['total'] === 0): ?>
            <p class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No attendance recorded yet</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Combined trend -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 md:col-span-2">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Performance &amp; Attendance Trend</h3>
            <span class="text-xs text-gray-400"><i class="fas fa-chart-area"></i></span>
        </div>
        <div class="relative h-56">
            <canvas id="trendChart" role="img" aria-label="Line chart of performance and attendance trends"></canvas>
            <?php if (empty($term_trend) && empty($att_trend)): ?>
            <p class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No trend data available yet</p>
            <?php endif; ?>
        </div>
    </div>
</section>

<!-- Financial Summary Card (Premium Widget) -->
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-6">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-950 rounded-xl flex items-center justify-center text-emerald-600 dark:text-emerald-400">
                <i class="fas fa-wallet text-2xl"></i>
            </div>
            <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Financial Standing</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Real-time school fees and payment details</p>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-6 lg:gap-8 w-full lg:w-auto justify-between lg:justify-end">
            <div class="text-left">
                <span class="text-xs text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wider block">Total Billed</span>
                <span class="text-base font-bold text-gray-800 dark:text-gray-200"><?php echo formatFinanceCurrency($total_charged, $db); ?></span>
            </div>
            <div class="text-left">
                <span class="text-xs text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wider block">Total Paid</span>
                <span class="text-base font-bold text-emerald-600 dark:text-emerald-400"><?php echo formatFinanceCurrency($total_paid, $db); ?></span>
            </div>
            <div class="text-left">
                <span class="text-xs text-gray-400 dark:text-gray-500 font-semibold uppercase tracking-wider block">
                    <?php echo $net_balance >= 0 ? 'Outstanding Balance' : 'Prepaid Credit'; ?>
                </span>
                <span class="text-lg font-extrabold <?php echo $net_balance > 0 ? 'text-rose-500' : 'text-emerald-500'; ?>">
                    <?php echo formatFinanceCurrency(abs($net_balance), $db); ?>
                    <?php if ($net_balance < 0): ?>
                        <span class="text-xs font-semibold">(Credit)</span>
                    <?php endif; ?>
                </span>
            </div>
            <a href="/school_ms/finance/student_finances.php" class="inline-flex items-center justify-center bg-gradient-to-r from-green-600 to-emerald-500 hover:from-green-700 hover:to-emerald-600 text-white font-semibold px-5 py-2.5 rounded-xl shadow-md hover:shadow-lg transition gap-2 text-sm">
                <i class="fas fa-wallet"></i> View Financial Dashboard
            </a>
        </div>
    </div>
</div>

<!-- Student Overview -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
    <!-- Today's Schedule -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Today's Classes</h3>
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
                            <?php echo htmlspecialchars($schedule['teacher_name'] ?? 'No teacher assigned'); ?>
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

    <!-- Upcoming Assignments -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Upcoming Assignments</h3>
            <a href="/school_ms/academic/assignments/" class="text-sm text-cyan-600 dark:text-cyan-400 hover:text-cyan-800">View all</a>
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
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700 mb-8">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Grades</h3>
        <a href="/school_ms/academic/grades/" class="text-sm text-cyan-600 dark:text-cyan-400 hover:text-cyan-800">View all</a>
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
<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm p-6 border border-gray-200 dark:border-gray-700">
    <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Tools</h3>
        <span class="text-sm text-gray-500 dark:text-gray-400">Quick access</span>
    </div>
    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <a href="/school_ms/academic/assignments/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-cyan-200 dark:group-hover:bg-cyan-800 transition-colors duration-200">
                <i class="fas fa-tasks text-cyan-600 dark:text-cyan-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Assignments</span>
        </a>
        <a href="/school_ms/academic/grades/" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                <i class="fas fa-star text-purple-600 dark:text-purple-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">My Grades</span>
        </a>
        <a href="/school_ms/attendance/student.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                <i class="fas fa-calendar-check text-green-600 dark:text-green-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Attendance</span>
        </a>
        <a href="/school_ms/academic/timetable/student.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                <i class="fas fa-calendar-alt text-blue-600 dark:text-blue-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Timetable</span>
        </a>
        <a href="/school_ms/library/books/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                <i class="fas fa-book text-indigo-600 dark:text-indigo-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Library</span>
        </a>
        <a href="/school_ms/communication/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                <i class="fas fa-comments text-teal-600 dark:text-teal-400 text-xl"></i>
            </div>
            <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Messages</span>
        </a>
    </div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Server-provided analytics for the logged-in student
const STUDENT_DATA = <?php echo json_encode($student_chart_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

(function () {
    const charts = {};

    function renderCharts() {
        if (!window.Chart) return;
        const isDark = document.documentElement.classList.contains('dark');
        const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.12)';
        const tick = isDark ? '#94a3b8' : '#6b7280';
        const a = STUDENT_DATA;

        Object.values(charts).forEach(c => c && c.destroy());
        Chart.defaults.font.family = "'Inter', sans-serif";

        // Subject performance (bar)
        const subCanvas = document.getElementById('subjectChart');
        if (subCanvas && a.subjects.length) {
            charts.subject = new Chart(subCanvas, {
                type: 'bar',
                data: {
                    labels: a.subjects.map(s => s.name),
                    datasets: [{
                        label: 'Score',
                        data: a.subjects.map(s => s.score),
                        backgroundColor: a.subjects.map(s => s.score >= 70 ? 'rgba(16,185,129,0.85)' : s.score >= 50 ? 'rgba(245,158,11,0.85)' : 'rgba(244,63,94,0.85)'),
                        borderRadius: 8,
                        maxBarThickness: 38
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.y + '%' } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, max: 100, grid: { color: grid }, ticks: { color: tick, callback: v => v + '%' } }
                    }
                }
            });
        }

        // Attendance breakdown (doughnut)
        const attCanvas = document.getElementById('attendanceChart');
        if (attCanvas && a.attendance.total) {
            charts.attendance = new Chart(attCanvas, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Late', 'Excused', 'Absent'],
                    datasets: [{
                        data: [a.attendance.present, a.attendance.late, a.attendance.excused, a.attendance.absent],
                        backgroundColor: ['#10b981', '#f59e0b', '#3b82f6', '#f43f5e'],
                        borderWidth: 0,
                        hoverOffset: 6
                    }]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, cutout: '62%',
                    plugins: { legend: { position: 'bottom', labels: { color: tick, usePointStyle: true, padding: 14, font: { size: 11 } } } }
                }
            });
        }

        // Combined trend (line)
        const trendCanvas = document.getElementById('trendChart');
        if (trendCanvas && (a.term_trend.length || a.att_trend.length)) {
            const datasets = [];
            if (a.term_trend.length) {
                datasets.push({
                    label: 'Performance', data: a.term_trend.map(t => t.value),
                    borderColor: '#8b5cf6', backgroundColor: 'rgba(139,92,246,0.12)',
                    borderWidth: 3, fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#8b5cf6'
                });
            }
            if (a.att_trend.length) {
                datasets.push({
                    label: 'Attendance', data: a.att_trend.map(t => t.value),
                    borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.10)',
                    borderWidth: 3, fill: true, tension: 0.4, pointRadius: 4, pointBackgroundColor: '#10b981'
                });
            }
            const labels = a.att_trend.length >= a.term_trend.length ? a.att_trend.map(t => t.label) : a.term_trend.map(t => t.label);
            charts.trend = new Chart(trendCanvas, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { intersect: false, mode: 'index' },
                    plugins: { legend: { position: 'bottom', labels: { color: tick, usePointStyle: true, padding: 14, font: { size: 11 } } }, tooltip: { callbacks: { label: c => c.dataset.label + ': ' + c.parsed.y + '%' } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tick, font: { size: 11 } } },
                        y: { beginAtZero: true, max: 100, grid: { color: grid }, ticks: { color: tick, callback: v => v + '%' } }
                    }
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', renderCharts);
    // Re-theme charts when dark mode toggles
    window.addEventListener('themeChanged', renderCharts);
}());
</script>
