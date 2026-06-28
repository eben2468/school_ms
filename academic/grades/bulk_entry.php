<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
require_once '../../includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$is_teacher = ($user_role === 'teacher');

$ctx = $database->getCurrentAcademicContext();

// Stored letter grades come from the scale-aware helper (getGradeLetter in
// settings_helper.php) so they stay consistent with the grading scales.
function teacherTeaches($db, $tid, $cid, $sid) {
    $s = $db->prepare("SELECT COUNT(*) FROM class_teachers WHERE teacher_id=:t AND class_id=:c AND subject_id=:s");
    $s->execute([':t' => $tid, ':c' => $cid, ':s' => $sid]);
    return $s->fetchColumn() > 0;
}

$flash = '';
$flash_type = 'success';

// ---- Save bulk grades ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $term_id = (int)($_POST['term_id'] ?? 0);
    $year_id = (int)($_POST['year_id'] ?? 0);
    $rows = $_POST['grades'] ?? [];

    if (!$class_id || !$subject_id || !$term_id || !$year_id) {
        $flash = 'Missing class, subject, term or year.'; $flash_type = 'error';
    } elseif ($is_teacher && !teacherTeaches($db, $user_id, $class_id, $subject_id)) {
        $flash = 'You can only enter grades for the subjects and classes you teach.'; $flash_type = 'error';
    } else {
        $saved = 0;
        $exist = $db->prepare("SELECT id FROM student_academic_records WHERE student_id=:st AND subject_id=:su AND academic_term_id=:tm AND academic_year_id=:yr");
        $ins = $db->prepare("INSERT INTO student_academic_records (student_id, academic_year_id, academic_term_id, class_id, subject_id, continuous_assessment, exam_score, total_score, grade, teacher_id) VALUES (:st,:yr,:tm,:c,:su,:ca,:ex,:tot,:gr,:tid)");
        $upd = $db->prepare("UPDATE student_academic_records SET class_id=:c, continuous_assessment=:ca, exam_score=:ex, total_score=:tot, grade=:gr, teacher_id=:tid WHERE id=:id");
        foreach ($rows as $student_id => $vals) {
            $student_id = (int)$student_id;
            $ca_in = $vals['ca'] ?? '';
            $ex_in = $vals['exam'] ?? '';
            $tot_in = $vals['total'] ?? '';
            // Skip rows left entirely blank
            if ($ca_in === '' && $ex_in === '' && $tot_in === '') { continue; }
            $ca = is_numeric($ca_in) ? (float)$ca_in : 0;
            $ex = is_numeric($ex_in) ? (float)$ex_in : 0;
            $tot = is_numeric($tot_in) ? (float)$tot_in : ($ca + $ex);
            $gr = getGradeLetter($tot);
            $exist->execute([':st' => $student_id, ':su' => $subject_id, ':tm' => $term_id, ':yr' => $year_id]);
            $eid = $exist->fetchColumn();
            if ($eid) {
                $upd->execute([':c' => $class_id, ':ca' => $ca, ':ex' => $ex, ':tot' => $tot, ':gr' => $gr, ':tid' => $user_id, ':id' => $eid]);
            } else {
                $ins->execute([':st' => $student_id, ':yr' => $year_id, ':tm' => $term_id, ':c' => $class_id, ':su' => $subject_id, ':ca' => $ca, ':ex' => $ex, ':tot' => $tot, ':gr' => $gr, ':tid' => $user_id]);
            }
            $saved++;
        }
        $flash = "Saved grades for $saved student(s).";
        // keep selection so the table reloads with saved values
        $_GET['class_id'] = $class_id; $_GET['subject_id'] = $subject_id; $_GET['term_id'] = $term_id; $_GET['year_id'] = $year_id;
    }
}

// ---- Filter selections ----
$sel_class = (int)($_GET['class_id'] ?? 0);
$sel_subject = (int)($_GET['subject_id'] ?? 0);
$sel_term = (int)($_GET['term_id'] ?? ($ctx['term_id'] ?? 0));
$sel_year = (int)($_GET['year_id'] ?? ($ctx['year_id'] ?? 0));

// Classes & class->subjects (teacher scoped)
if ($is_teacher) {
    $cls = $db->prepare("SELECT DISTINCT c.id, c.name, c.grade_level FROM class_teachers ct JOIN classes c ON ct.class_id=c.id WHERE ct.teacher_id=:t AND c.status='active' ORDER BY c.grade_level, c.name");
    $cls->execute([':t' => $user_id]);
    $classes = $cls->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = $db->query("SELECT id, name, grade_level FROM classes WHERE status='active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
}
$class_ids = array_column($classes, 'id');
$class_subjects = [];
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    if ($is_teacher) {
        $r = $db->prepare("SELECT DISTINCT ct.class_id, s.id, s.name FROM class_teachers ct JOIN subjects s ON ct.subject_id=s.id WHERE ct.teacher_id=:t AND ct.class_id IN ($in) ORDER BY s.name");
        $r->execute([':t' => $user_id]);
        $r = $r->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $r = $db->query("SELECT class_id, id, name FROM subjects WHERE class_id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($r as $row) { $class_subjects[(int)$row['class_id']][] = ['id' => (int)$row['id'], 'name' => $row['name']]; }
}

$years = $db->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$terms = $db->query("SELECT id, term_name FROM academic_terms ORDER BY term_number")->fetchAll(PDO::FETCH_ASSOC);

// Load roster if a valid, in-scope selection exists
$students = [];
$scope_ok = $sel_class && $sel_subject && $sel_term && $sel_year && (!$is_teacher || teacherTeaches($db, $user_id, $sel_class, $sel_subject));
if ($scope_ok) {
    $st = $db->prepare("SELECT u.id, u.name, sp.student_id AS roll,
            sar.continuous_assessment, sar.exam_score, sar.total_score
        FROM student_classes sc
        JOIN users u ON sc.student_id = u.id
        JOIN student_profiles sp ON u.id = sp.user_id
        LEFT JOIN student_academic_records sar ON sar.student_id = u.id AND sar.subject_id = :su AND sar.academic_term_id = :tm AND sar.academic_year_id = :yr
        WHERE sc.class_id = :c AND sc.status = 'active' AND u.role = 'student'
        ORDER BY u.name");
    $st->execute([':su' => $sel_subject, ':tm' => $sel_term, ':yr' => $sel_year, ':c' => $sel_class]);
    $students = $st->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Bulk Grade Entry";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Bulk Grade Entry</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Enter scores for a whole class in one subject at once.</p>
                    </div>
                    <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm"><i class="fas fa-arrow-left mr-2"></i>Back</a>
                </div>

                <?php if ($flash): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border <?= $flash_type === 'error' ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:text-red-300' : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:text-green-300' ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-2"></i><?= htmlspecialchars($flash) ?>
                </div>
                <?php endif; ?>

                <!-- Selection -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-6">
                    <form method="GET" class="grid grid-cols-1 md:grid-cols-5 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Class</label>
                            <select name="class_id" id="class_id" onchange="syncSubjects()" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="">Select</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $sel_class === (int)$c['id'] ? 'selected' : '' ?>>Grade <?= htmlspecialchars($c['grade_level']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Subject</label>
                            <select name="subject_id" id="subject_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="">Select class first</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Year</label>
                            <select name="year_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= $sel_year === (int)$y['id'] ? 'selected' : '' ?>><?= htmlspecialchars($y['year_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Term</label>
                            <select name="term_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= $sel_term === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['term_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2.5 rounded-lg shadow-sm transition flex items-center justify-center"><i class="fas fa-users mr-2"></i>Load Roster</button>
                        </div>
                    </form>
                </div>

                <?php if ($scope_ok): ?>
                    <?php if (empty($students)): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-10 text-center">
                        <i class="fas fa-user-slash text-4xl text-gray-300 mb-3"></i>
                        <p class="text-gray-600 dark:text-gray-400">No students are enrolled in this class.</p>
                    </div>
                    <?php else: ?>
                    <form method="POST" x-data="bulkGrades()">
                        <input type="hidden" name="action" value="save">
                        <input type="hidden" name="class_id" value="<?= $sel_class ?>">
                        <input type="hidden" name="subject_id" value="<?= $sel_subject ?>">
                        <input type="hidden" name="term_id" value="<?= $sel_term ?>">
                        <input type="hidden" name="year_id" value="<?= $sel_year ?>">

                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <h2 class="text-lg font-bold text-gray-900 dark:text-white"><?= count($students) ?> Students</h2>
                                <span class="text-xs text-gray-400">Total auto-fills from CA + Exam; you can override it.</span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase">Student</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase w-28">CA</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase w-28">Exam</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase w-28">Total</th>
                                            <th class="px-4 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase w-20">Grade</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                        <?php foreach ($students as $s):
                                            $sid = (int)$s['id'];
                                            $ca = $s['continuous_assessment'];
                                            $ex = $s['exam_score'];
                                            $tot = $s['total_score'];
                                        ?>
                                        <tr x-data="{ ca: '<?= $ca !== null ? htmlspecialchars($ca) : '' ?>', exam: '<?= $ex !== null ? htmlspecialchars($ex) : '' ?>', total: '<?= $tot !== null ? htmlspecialchars($tot) : '' ?>',
                                            letter(t){ t=parseFloat(t)||0; if(t>=90)return'A+';if(t>=80)return'A';if(t>=70)return'B+';if(t>=60)return'B';if(t>=50)return'C+';if(t>=40)return'C';if(t>=30)return'D';return'F'; },
                                            recalc(){ const t=(parseFloat(this.ca)||0)+(parseFloat(this.exam)||0); this.total = (this.ca===''&&this.exam==='')?'':t.toFixed(1); },
                                            get grade(){ return this.total===''?'—':this.letter(this.total); } }">
                                            <td class="px-6 py-3">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white"><?= htmlspecialchars($s['name']) ?></div>
                                                <div class="text-xs text-gray-400"><?= htmlspecialchars($s['roll']) ?></div>
                                            </td>
                                            <td class="px-4 py-3"><input type="number" step="0.1" min="0" max="100" name="grades[<?= $sid ?>][ca]" x-model="ca" @input="recalc()" class="w-full px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white text-center"></td>
                                            <td class="px-4 py-3"><input type="number" step="0.1" min="0" max="100" name="grades[<?= $sid ?>][exam]" x-model="exam" @input="recalc()" class="w-full px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white text-center"></td>
                                            <td class="px-4 py-3"><input type="number" step="0.1" min="0" max="100" name="grades[<?= $sid ?>][total]" x-model="total" class="w-full px-2 py-1.5 border border-gray-300 dark:border-gray-600 rounded dark:bg-gray-700 dark:text-white text-center font-semibold"></td>
                                            <td class="px-4 py-3 text-center text-sm font-bold text-gray-900 dark:text-white" x-text="grade"></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700">
                                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition flex items-center"><i class="fas fa-save mr-2"></i>Save All Grades</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                <?php elseif ($sel_class && $sel_subject && $is_teacher): ?>
                <div class="bg-yellow-50 border border-yellow-300 text-yellow-700 px-4 py-3 rounded-lg">You can only enter grades for subjects you teach in your classes.</div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-10 text-center">
                    <i class="fas fa-table text-4xl text-indigo-400 mb-3"></i>
                    <p class="text-gray-600 dark:text-gray-400">Select a class, subject, year and term, then load the roster to enter grades.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>
        <div class="lg:ml-0"><?php include '../../includes/footer.php'; ?></div>
    </div>
</div>

<script>
const CLASS_SUBJECTS = <?= json_encode($class_subjects) ?>;
const PRESELECT_SUBJECT = <?= (int)$sel_subject ?>;
function syncSubjects() {
    const cid = document.getElementById('class_id').value;
    const sel = document.getElementById('subject_id');
    sel.innerHTML = '<option value="">Select subject</option>';
    (CLASS_SUBJECTS[cid] || []).forEach(s => {
        const o = document.createElement('option'); o.value = s.id; o.textContent = s.name;
        if (s.id === PRESELECT_SUBJECT) o.selected = true;
        sel.appendChild(o);
    });
}
document.addEventListener('DOMContentLoaded', syncSubjects);
</script>
