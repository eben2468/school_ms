<?php
session_start();
require_once '../config/database.php';
require_once '../finance/includes/finance_functions.php';

// Check if user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Academic context
$academic_context = $database->getCurrentAcademicContext();
$current_year_id = $academic_context['year_id'] ?? null;

// Get parent's children information
$children_sql = "SELECT
    u.id, u.name, u.email, u.status, u.profile_picture,
    sp.student_id, sp.date_of_birth, sp.gender, sp.phone, sp.address,
    sp.admission_date, sp.blood_group, sp.medical_conditions,
    c.name as class_name, c.grade_level,
    ps.relationship
FROM parent_students ps
JOIN users u ON ps.student_id = u.id
JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
WHERE ps.parent_id = :parent_id AND u.status = 'active'
ORDER BY u.name";

$stmt = $db->prepare($children_sql);
$stmt->bindParam(':parent_id', $user_id);
$stmt->execute();
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Build a rich per-child analytics dataset used by the cards and charts.
 * Every query is wrapped defensively so a missing table never breaks the dashboard.
 */
$dashboard_data = [];          // keyed by child id, consumed by JS for charts
$today = date('Y-m-d');

// Aggregate totals for the headline summary cards
$agg_attendance_sum = 0;       // sum of per-child attendance percentages
$agg_attendance_count = 0;
$agg_grade_sum = 0;            // sum of per-child average scores
$agg_grade_count = 0;
$agg_outstanding = 0.0;        // total outstanding balance across children
$agg_upcoming = 0;            // upcoming assignment count across children

foreach ($children as $child) {
    $cid = (int)$child['id'];

    // --- Attendance (current academic year, falls back to all-time) ---
    $attendance = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0, 'total' => 0, 'percentage' => 0];
    try {
        $att_stmt = $db->prepare("SELECT status, COUNT(*) as cnt FROM attendance WHERE student_id = :sid GROUP BY status");
        $att_stmt->execute([':sid' => $cid]);
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

    if ($attendance['total'] > 0) {
        $agg_attendance_sum += $attendance['percentage'];
        $agg_attendance_count++;
    }

    // --- 6-month attendance trend (present-rate per month) ---
    $att_trend = [];
    try {
        $trend_stmt = $db->prepare("SELECT DATE_FORMAT(date, '%Y-%m') as ym,
                SUM(CASE WHEN status IN ('present','late') THEN 1 ELSE 0 END) as present_cnt,
                COUNT(*) as total_cnt
            FROM attendance
            WHERE student_id = :sid AND date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ym ORDER BY ym");
        $trend_stmt->execute([':sid' => $cid]);
        foreach ($trend_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rate = $row['total_cnt'] > 0 ? round(($row['present_cnt'] / $row['total_cnt']) * 100, 1) : 0;
            $att_trend[] = ['label' => date('M', strtotime($row['ym'] . '-01')), 'value' => $rate];
        }
    } catch (Exception $e) { /* ignore */ }

    // --- Subject performance (current year, most recent total per subject) ---
    $subjects = [];
    $avg_score = null;
    try {
        $sub_stmt = $db->prepare("SELECT s.name as subject_name, AVG(sar.total_score) as score
            FROM student_academic_records sar
            JOIN subjects s ON sar.subject_id = s.id
            WHERE sar.student_id = :sid " . ($current_year_id ? "AND sar.academic_year_id = :yid" : "") . "
              AND sar.total_score IS NOT NULL
            GROUP BY s.id, s.name
            ORDER BY s.name");
        $params = [':sid' => $cid];
        if ($current_year_id) { $params[':yid'] = $current_year_id; }
        $sub_stmt->execute($params);
        $score_total = 0; $score_n = 0;
        foreach ($sub_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sc = round((float)$row['score'], 1);
            $subjects[] = ['name' => $row['subject_name'], 'score' => $sc];
            $score_total += $sc; $score_n++;
        }
        if ($score_n > 0) { $avg_score = round($score_total / $score_n, 1); }
    } catch (Exception $e) { /* ignore */ }

    if ($avg_score !== null) {
        $agg_grade_sum += $avg_score;
        $agg_grade_count++;
    }

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
        $params = [':sid' => $cid];
        if ($current_year_id) { $params[':yid'] = $current_year_id; }
        $tt_stmt->execute($params);
        foreach ($tt_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $term_trend[] = ['label' => $row['term_name'], 'value' => round((float)$row['score'], 1)];
        }
    } catch (Exception $e) { /* ignore */ }

    // --- Upcoming & recent assignments ---
    $upcoming_assignments = [];
    $assignment_stats = ['submitted' => 0, 'pending' => 0, 'graded' => 0];
    try {
        $asg_stmt = $db->prepare("SELECT a.title, a.due_date, a.total_marks,
                s.name as subject_name,
                sa.submitted_at, sa.grade as student_grade
            FROM assignments a
            JOIN subjects s ON a.subject_id = s.id
            JOIN classes c ON a.class_id = c.id
            JOIN student_classes sc ON c.id = sc.class_id AND sc.student_id = :sid AND sc.status = 'active'
            LEFT JOIN student_assignments sa ON a.id = sa.assignment_id AND sa.student_id = :sid2
            WHERE a.status = 'active'
            ORDER BY a.due_date DESC
            LIMIT 30");
        $asg_stmt->execute([':sid' => $cid, ':sid2' => $cid]);
        foreach ($asg_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['student_grade'] !== null) {
                $assignment_stats['graded']++;
            } elseif (!empty($row['submitted_at'])) {
                $assignment_stats['submitted']++;
            } else {
                $assignment_stats['pending']++;
            }

            // collect upcoming (due today or later, not yet submitted) for the list
            if (empty($row['submitted_at']) && $row['due_date'] >= $today) {
                $upcoming_assignments[] = [
                    'title' => $row['title'],
                    'subject' => $row['subject_name'],
                    'due_date' => $row['due_date'],
                ];
            }
        }
        // soonest first, cap to 5
        usort($upcoming_assignments, fn($a, $b) => strcmp($a['due_date'], $b['due_date']));
        $upcoming_assignments = array_slice($upcoming_assignments, 0, 5);
        $agg_upcoming += count($upcoming_assignments);
    } catch (Exception $e) { /* ignore */ }

    // --- Finance summary ---
    $finance = ['billed' => 0.0, 'paid' => 0.0, 'balance' => 0.0];
    try {
        $fin_stmt = $db->prepare("SELECT
                COALESCE(SUM(total_amount + penalty_amount - discount_amount), 0) as total_charged,
                COALESCE(SUM(amount_paid), 0) as total_paid
            FROM finance_invoices
            WHERE student_id = :sid AND status != 'cancelled'");
        $fin_stmt->execute([':sid' => $cid]);
        $f = $fin_stmt->fetch(PDO::FETCH_ASSOC);
        $finance['billed'] = (float)$f['total_charged'];
        $finance['paid'] = (float)$f['total_paid'];
        $finance['balance'] = $finance['billed'] - $finance['paid'];
    } catch (Exception $e) { /* ignore */ }
    if ($finance['balance'] > 0) {
        $agg_outstanding += $finance['balance'];
    }

    $dashboard_data[$cid] = [
        'id' => $cid,
        'name' => $child['name'],
        'profile_picture' => !empty($child['profile_picture'])
            ? ('/school_ms/serve_image.php?path=profile_pictures/' . rawurlencode($child['profile_picture']))
            : '',
        'student_id' => $child['student_id'],
        'class' => $child['class_name'] ? ('Grade ' . $child['grade_level'] . ' - ' . $child['class_name']) : 'Not enrolled',
        'relationship' => ucfirst((string)$child['relationship']),
        'attendance' => $attendance,
        'att_trend' => $att_trend,
        'subjects' => $subjects,
        'avg_score' => $avg_score,
        'term_trend' => $term_trend,
        'upcoming' => $upcoming_assignments,
        'assignment_stats' => $assignment_stats,
        'finance' => $finance,
    ];
}

$avg_attendance = $agg_attendance_count > 0 ? round($agg_attendance_sum / $agg_attendance_count, 1) : 0;
$avg_grade = $agg_grade_count > 0 ? round($agg_grade_sum / $agg_grade_count, 1) : 0;

// School announcements targeted at parents
$announcements = [];
try {
    $ann_stmt = $db->prepare("SELECT title, content, priority, created_at
        FROM announcements
        WHERE (target_audience = 'all' OR target_audience = 'parents')
          AND status = 'published'
          AND (publish_date IS NULL OR publish_date <= NOW())
          AND (expiry_date IS NULL OR expiry_date >= NOW())
        ORDER BY created_at DESC
        LIMIT 5");
    $ann_stmt->execute();
    $announcements = $ann_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { /* ignore */ }

$title = "Parent Dashboard";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0"
         x-data="parentDashboard()">
        <main class="p-4 sm:p-6 lg:p-8 flex-1">
            <div class="w-full max-w-7xl mx-auto">

                <!-- Welcome / Hero -->
                <section class="mb-6" aria-label="Welcome">
                    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
                        <div class="absolute -right-8 -top-8 w-48 h-48 bg-white/10 rounded-full blur-2xl" aria-hidden="true"></div>
                        <div class="absolute -right-16 bottom-0 w-32 h-32 bg-white/5 rounded-full" aria-hidden="true"></div>
                        <div class="relative flex items-center justify-between gap-4">
                            <div>
                                <p class="text-blue-100/90 text-sm font-medium mb-1">
                                    <i class="fas fa-heart mr-1.5"></i> Parent Portal
                                </p>
                                <h1 class="text-2xl sm:text-3xl font-bold mb-2">Welcome back, <?php echo htmlspecialchars($user_name); ?>!</h1>
                                <p class="text-blue-100 text-sm sm:text-base">Monitor your <?php echo count($children) === 1 ? "child's" : "children's"; ?> academic progress and school activities.</p>
                                <div class="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-blue-100">
                                    <span class="flex items-center"><i class="fas fa-calendar-alt mr-2"></i><?php echo date('l, F j, Y'); ?></span>
                                    <span class="flex items-center"><i class="fas fa-graduation-cap mr-2"></i><?php echo htmlspecialchars($academic_context['year_name']); ?> &middot; <?php echo htmlspecialchars($academic_context['term_name']); ?></span>
                                </div>
                            </div>
                            <div class="hidden md:flex flex-shrink-0">
                                <div class="w-28 h-28 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm border border-white/20">
                                    <i class="fas fa-users text-5xl text-white/85"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <?php if (empty($children)): ?>
                <!-- No Children Found -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-10 text-center">
                        <div class="w-16 h-16 mx-auto bg-blue-50 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center mb-4">
                            <i class="fas fa-user-friends text-3xl text-blue-500 dark:text-blue-400"></i>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">No Children Linked</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-1">No children are currently linked to your parent account.</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Please contact the school administration to link your child's account.</p>
                    </div>
                </div>
                <?php else: ?>

                <!-- Summary Stat Cards -->
                <section class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6" aria-label="Summary statistics">
                    <?php
                    $summary_cards = [
                        ['label' => count($children) === 1 ? 'My Child' : 'My Children', 'value' => count($children), 'icon' => 'fa-users', 'color' => 'blue'],
                        ['label' => 'Avg. Attendance', 'value' => $avg_attendance . '%', 'icon' => 'fa-calendar-check', 'color' => 'emerald'],
                        ['label' => 'Avg. Performance', 'value' => $avg_grade > 0 ? $avg_grade . '%' : '—', 'icon' => 'fa-chart-line', 'color' => 'violet'],
                        ['label' => 'Outstanding Fees', 'value' => formatFinanceCurrency($agg_outstanding, $db), 'icon' => 'fa-wallet', 'color' => $agg_outstanding > 0 ? 'rose' : 'emerald'],
                    ];
                    $color_map = [
                        'blue'    => ['bg' => 'bg-blue-100 dark:bg-blue-900/40', 'text' => 'text-blue-600 dark:text-blue-400', 'ring' => 'hover:border-blue-300 dark:hover:border-blue-700'],
                        'emerald' => ['bg' => 'bg-emerald-100 dark:bg-emerald-900/40', 'text' => 'text-emerald-600 dark:text-emerald-400', 'ring' => 'hover:border-emerald-300 dark:hover:border-emerald-700'],
                        'violet'  => ['bg' => 'bg-violet-100 dark:bg-violet-900/40', 'text' => 'text-violet-600 dark:text-violet-400', 'ring' => 'hover:border-violet-300 dark:hover:border-violet-700'],
                        'rose'    => ['bg' => 'bg-rose-100 dark:bg-rose-900/40', 'text' => 'text-rose-600 dark:text-rose-400', 'ring' => 'hover:border-rose-300 dark:hover:border-rose-700'],
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
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </section>

                <!-- Child Selector -->
                <?php if (count($children) > 1): ?>
                <section class="mb-6" aria-label="Select child">
                    <div class="flex items-center gap-2 overflow-x-auto pb-1">
                        <?php foreach ($children as $child): $cid = (int)$child['id']; ?>
                        <button type="button"
                                @click="selectChild(<?php echo $cid; ?>)"
                                :class="selected === <?php echo $cid; ?> ? 'bg-blue-600 text-white border-blue-600 shadow-md' : 'bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-300 border-gray-200 dark:border-gray-700 hover:border-blue-300'"
                                class="flex items-center gap-2 px-4 py-2 rounded-full border text-sm font-medium whitespace-nowrap transition-all duration-200">
                            <span class="w-6 h-6 rounded-full overflow-hidden bg-white/20 flex items-center justify-center text-xs"
                                  :class="selected === <?php echo $cid; ?> ? 'bg-white/25' : 'bg-blue-100 dark:bg-blue-900/40 text-blue-600 dark:text-blue-300'">
                                <?php if (!empty($child['profile_picture'])): ?>
                                <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo rawurlencode($child['profile_picture']); ?>" alt="" class="w-full h-full object-cover">
                                <?php else: ?>
                                <?php echo strtoupper(substr($child['name'], 0, 1)); ?>
                                <?php endif; ?>
                            </span>
                            <?php echo htmlspecialchars($child['name']); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </section>
                <?php endif; ?>

                <!-- Selected Child Detail -->
                <section class="grid grid-cols-1 xl:grid-cols-3 gap-6 mb-6" aria-label="Child overview and performance">
                    <!-- Child Overview Card -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-6 xl:col-span-1">
                        <div class="flex items-center gap-4 mb-5">
                            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-violet-500 rounded-2xl flex items-center justify-center text-white text-2xl font-bold flex-shrink-0 overflow-hidden">
                                <template x-if="active.profile_picture">
                                    <img :src="active.profile_picture" :alt="active.name"
                                         class="w-full h-full object-cover" @error="active.profile_picture = ''">
                                </template>
                                <template x-if="!active.profile_picture">
                                    <span x-text="active.name ? active.name.charAt(0).toUpperCase() : ''"></span>
                                </template>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white truncate" x-text="active.name"></h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400">ID: <span x-text="active.student_id"></span></p>
                                <span class="inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300" x-text="active.class"></span>
                            </div>
                        </div>

                        <!-- Mini metrics -->
                        <div class="grid grid-cols-3 gap-3 mb-5">
                            <div class="text-center p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20">
                                <p class="text-xl font-bold text-emerald-600 dark:text-emerald-400" x-text="active.attendance.percentage + '%'"></p>
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 mt-0.5">Attendance</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-violet-50 dark:bg-violet-900/20">
                                <p class="text-xl font-bold text-violet-600 dark:text-violet-400" x-text="active.avg_score !== null ? active.avg_score + '%' : '—'"></p>
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 mt-0.5">Avg Score</p>
                            </div>
                            <div class="text-center p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20">
                                <p class="text-xl font-bold text-amber-600 dark:text-amber-400" x-text="active.upcoming.length"></p>
                                <p class="text-[11px] font-medium text-gray-500 dark:text-gray-400 mt-0.5">Upcoming</p>
                            </div>
                        </div>

                        <!-- Financial standing -->
                        <div class="rounded-xl border border-gray-100 dark:border-gray-700/60 p-4 mb-5">
                            <h4 class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-3 flex items-center gap-2">
                                <i class="fas fa-wallet text-emerald-500"></i> Financial Standing
                            </h4>
                            <div class="flex items-center justify-between text-sm mb-1.5">
                                <span class="text-gray-500 dark:text-gray-400">Billed</span>
                                <span class="font-semibold text-gray-700 dark:text-gray-200" x-text="fmt(active.finance.billed)"></span>
                            </div>
                            <div class="flex items-center justify-between text-sm mb-1.5">
                                <span class="text-gray-500 dark:text-gray-400">Paid</span>
                                <span class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="fmt(active.finance.paid)"></span>
                            </div>
                            <div class="flex items-center justify-between text-sm pt-1.5 border-t border-gray-100 dark:border-gray-700/60">
                                <span class="text-gray-500 dark:text-gray-400" x-text="active.finance.balance >= 0 ? 'Outstanding' : 'Credit'"></span>
                                <span class="font-bold" :class="active.finance.balance > 0 ? 'text-rose-500' : 'text-emerald-500'" x-text="fmt(Math.abs(active.finance.balance))"></span>
                            </div>
                        </div>

                        <!-- Quick links -->
                        <div class="grid grid-cols-4 gap-2">
                            <a :href="'child_academic.php?student_id=' + selected" class="flex flex-col items-center gap-1 p-2.5 rounded-xl bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 text-blue-600 dark:text-blue-400 transition-colors" title="Academic">
                                <i class="fas fa-chart-line"></i><span class="text-[10px] font-medium">Academic</span>
                            </a>
                            <a :href="'child_attendance.php?student_id=' + selected" class="flex flex-col items-center gap-1 p-2.5 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 text-emerald-600 dark:text-emerald-400 transition-colors" title="Attendance">
                                <i class="fas fa-calendar-check"></i><span class="text-[10px] font-medium">Attend.</span>
                            </a>
                            <a :href="'child_assignments.php?student_id=' + selected" class="flex flex-col items-center gap-1 p-2.5 rounded-xl bg-violet-50 dark:bg-violet-900/20 hover:bg-violet-100 dark:hover:bg-violet-900/40 text-violet-600 dark:text-violet-400 transition-colors" title="Assignments">
                                <i class="fas fa-tasks"></i><span class="text-[10px] font-medium">Tasks</span>
                            </a>
                            <a :href="'fees.php?student_id=' + selected" class="flex flex-col items-center gap-1 p-2.5 rounded-xl bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/40 text-amber-600 dark:text-amber-400 transition-colors" title="Fees">
                                <i class="fas fa-money-bill-wave"></i><span class="text-[10px] font-medium">Fees</span>
                            </a>
                        </div>
                    </div>

                    <!-- Charts -->
                    <div class="xl:col-span-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Subject performance -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Subject Performance</h3>
                                <span class="text-xs text-gray-400"><i class="fas fa-book"></i></span>
                            </div>
                            <div class="relative h-56">
                                <canvas id="subjectChart" role="img" aria-label="Bar chart of scores per subject"></canvas>
                                <p x-show="!active.subjects.length" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No grades recorded yet</p>
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
                                <p x-show="!active.attendance.total" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No attendance recorded yet</p>
                            </div>
                        </div>

                        <!-- Performance trend -->
                        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5 md:col-span-2">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">Performance &amp; Attendance Trend</h3>
                                <span class="text-xs text-gray-400"><i class="fas fa-chart-area"></i></span>
                            </div>
                            <div class="relative h-56">
                                <canvas id="trendChart" role="img" aria-label="Line chart of performance and attendance trends"></canvas>
                                <p x-show="!active.term_trend.length && !active.att_trend.length" class="absolute inset-0 flex items-center justify-center text-sm text-gray-400">No trend data available yet</p>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Assignments + Announcements + Communication -->
                <section class="grid grid-cols-1 lg:grid-cols-3 gap-6" aria-label="Assignments, announcements and communication">
                    <!-- Upcoming Assignments -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="fas fa-tasks text-violet-500"></i> Upcoming Assignments
                            </h3>
                            <a :href="'child_assignments.php?student_id=' + selected" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">View all</a>
                        </div>
                        <div class="space-y-3" x-show="active.upcoming.length">
                            <template x-for="(item, idx) in active.upcoming" :key="idx">
                                <div class="flex items-center gap-3 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40 hover:bg-gray-100 dark:hover:bg-gray-700/70 transition-colors">
                                    <div class="w-9 h-9 rounded-lg bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center flex-shrink-0">
                                        <i class="fas fa-file-alt text-violet-600 dark:text-violet-400 text-sm"></i>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white truncate" x-text="item.title"></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate" x-text="item.subject"></p>
                                    </div>
                                    <span class="text-xs font-medium px-2 py-1 rounded-full flex-shrink-0"
                                          :class="dueClass(item.due_date)" x-text="dueLabel(item.due_date)"></span>
                                </div>
                            </template>
                        </div>
                        <div x-show="!active.upcoming.length" class="text-center py-8">
                            <i class="fas fa-check-circle text-3xl text-emerald-400 mb-2"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">All caught up! No pending assignments.</p>
                        </div>
                    </div>

                    <!-- School Announcements -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
                                <i class="fas fa-bullhorn text-amber-500"></i> Announcements
                            </h3>
                            <a href="messages.php" class="text-xs text-blue-600 dark:text-blue-400 hover:underline">View all</a>
                        </div>
                        <?php if (!empty($announcements)): ?>
                        <div class="space-y-3">
                            <?php
                            $priority_styles = [
                                'urgent' => ['border-rose-400', 'text-rose-500', 'fa-exclamation-triangle'],
                                'high'   => ['border-orange-400', 'text-orange-500', 'fa-exclamation-circle'],
                                'medium' => ['border-amber-400', 'text-amber-500', 'fa-info-circle'],
                                'low'    => ['border-blue-400', 'text-blue-500', 'fa-bell'],
                            ];
                            foreach ($announcements as $a):
                                $p = strtolower($a['priority'] ?? 'low');
                                $ps = $priority_styles[$p] ?? $priority_styles['low'];
                            ?>
                            <div class="border-l-4 <?php echo $ps[0]; ?> pl-3 py-1">
                                <div class="flex items-start gap-2">
                                    <i class="fas <?php echo $ps[2]; ?> <?php echo $ps[1]; ?> text-xs mt-1"></i>
                                    <div class="min-w-0">
                                        <p class="text-sm font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($a['title']); ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2"><?php echo htmlspecialchars(mb_strimwidth(strip_tags($a['content'] ?? ''), 0, 90, '…')); ?></p>
                                        <p class="text-[11px] text-gray-400 mt-0.5"><?php echo date('M j, Y', strtotime($a['created_at'])); ?></p>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-bullhorn text-3xl text-gray-300 dark:text-gray-600 mb-2"></i>
                            <p class="text-sm text-gray-500 dark:text-gray-400">No announcements right now.</p>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Communication / Quick Links -->
                    <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-5">
                        <h3 class="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2 mb-4">
                            <i class="fas fa-comments text-blue-500"></i> Communication
                        </h3>
                        <div class="space-y-3">
                            <a href="teacher_communication.php" class="flex items-center gap-3 p-3 rounded-xl bg-blue-50 dark:bg-blue-900/20 hover:bg-blue-100 dark:hover:bg-blue-900/40 transition-colors group">
                                <div class="w-10 h-10 rounded-lg bg-blue-100 dark:bg-blue-900/50 flex items-center justify-center">
                                    <i class="fas fa-chalkboard-teacher text-blue-600 dark:text-blue-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">Message Teachers</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Reach out to your child's teachers</p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300 group-hover:text-blue-500 transition-colors"></i>
                            </a>
                            <a href="messages.php" class="flex items-center gap-3 p-3 rounded-xl bg-violet-50 dark:bg-violet-900/20 hover:bg-violet-100 dark:hover:bg-violet-900/40 transition-colors group">
                                <div class="w-10 h-10 rounded-lg bg-violet-100 dark:bg-violet-900/50 flex items-center justify-center">
                                    <i class="fas fa-envelope text-violet-600 dark:text-violet-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">Messages &amp; Notifications</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">View school updates</p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300 group-hover:text-violet-500 transition-colors"></i>
                            </a>
                            <a href="calendar.php" class="flex items-center gap-3 p-3 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition-colors group">
                                <div class="w-10 h-10 rounded-lg bg-emerald-100 dark:bg-emerald-900/50 flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-emerald-600 dark:text-emerald-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">School Calendar</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Events &amp; important dates</p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300 group-hover:text-emerald-500 transition-colors"></i>
                            </a>
                            <a href="download_reports.php" class="flex items-center gap-3 p-3 rounded-xl bg-amber-50 dark:bg-amber-900/20 hover:bg-amber-100 dark:hover:bg-amber-900/40 transition-colors group">
                                <div class="w-10 h-10 rounded-lg bg-amber-100 dark:bg-amber-900/50 flex items-center justify-center">
                                    <i class="fas fa-download text-amber-600 dark:text-amber-400"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-gray-900 dark:text-white">Download Reports</p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Report cards &amp; statements</p>
                                </div>
                                <i class="fas fa-chevron-right text-gray-300 group-hover:text-amber-500 transition-colors"></i>
                            </a>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<?php if (!empty($children)): ?>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Server-provided per-child analytics
const PARENT_DATA = <?php echo json_encode($dashboard_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const CURRENCY_SYMBOL = <?php echo json_encode(formatFinanceCurrency(0, $db) === '0.00' ? '' : preg_replace('/[\d.,\s]/', '', formatFinanceCurrency(0, $db))); ?>;

function parentDashboard() {
    return {
        data: PARENT_DATA,
        selected: <?php echo (int)$children[0]['id']; ?>,
        active: {},
        charts: {},

        init() {
            this.active = this.data[this.selected];
            this.$nextTick(() => this.renderCharts());
            // Re-theme charts when dark mode toggles
            window.addEventListener('themeChanged', () => this.renderCharts());
        },

        selectChild(id) {
            this.selected = id;
            this.active = this.data[id];
            this.$nextTick(() => this.renderCharts());
        },

        fmt(v) {
            const n = Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
            return CURRENCY_SYMBOL ? CURRENCY_SYMBOL + ' ' + n : n;
        },

        dueLabel(dateStr) {
            const days = Math.ceil((new Date(dateStr) - new Date(new Date().toDateString())) / 86400000);
            if (days <= 0) return 'Due today';
            if (days === 1) return 'Tomorrow';
            if (days <= 7) return days + ' days';
            return new Date(dateStr).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
        },

        dueClass(dateStr) {
            const days = Math.ceil((new Date(dateStr) - new Date(new Date().toDateString())) / 86400000);
            if (days <= 1) return 'bg-rose-100 text-rose-700 dark:bg-rose-900/40 dark:text-rose-300';
            if (days <= 3) return 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300';
            return 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300';
        },

        renderCharts() {
            const isDark = document.documentElement.classList.contains('dark');
            const grid = isDark ? 'rgba(148,163,184,0.15)' : 'rgba(107,114,128,0.12)';
            const tick = isDark ? '#94a3b8' : '#6b7280';
            const a = this.active;

            Object.values(this.charts).forEach(c => c && c.destroy());
            this.charts = {};

            if (!window.Chart) return;
            Chart.defaults.font.family = "'Inter', sans-serif";

            // Subject performance (bar)
            const subCanvas = document.getElementById('subjectChart');
            if (subCanvas && a.subjects.length) {
                this.charts.subject = new Chart(subCanvas, {
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
                this.charts.attendance = new Chart(attCanvas, {
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
                this.charts.trend = new Chart(trendCanvas, {
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
    };
}
</script>
<style>
.line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
</style>
<?php endif; ?>
