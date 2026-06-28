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
$default_year = $ctx['year_id'];
$default_term = $ctx['term_id'];

// Letter grade stored alongside each record comes from the centralised,
// scale-aware helper (getGradeLetter in settings_helper.php) so stored grades
// stay consistent with the school's grading scales and report cards.

$flash = '';
$flash_type = 'success';

// Teacher's taught (class, subject) pairs for scope validation
function teacherTeaches($db, $tid, $cid, $sid) {
    $s = $db->prepare("SELECT COUNT(*) FROM class_teachers WHERE teacher_id = :t AND class_id = :c AND subject_id = :s");
    $s->execute([':t' => $tid, ':c' => $cid, ':s' => $sid]);
    return $s->fetchColumn() > 0;
}

// ---- Handle submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = (int)($_POST['class_id'] ?? 0);
    $subject_id = (int)($_POST['subject_id'] ?? 0);
    $student_id = (int)($_POST['student_id'] ?? 0);
    $year_id = (int)($_POST['year_id'] ?? 0);
    $term_id = (int)($_POST['term_id'] ?? 0);
    $ca = is_numeric($_POST['ca'] ?? '') ? (float)$_POST['ca'] : 0;
    $exam = is_numeric($_POST['exam'] ?? '') ? (float)$_POST['exam'] : 0;
    $total = is_numeric($_POST['total'] ?? '') ? (float)$_POST['total'] : ($ca + $exam);
    $remarks = trim($_POST['remarks'] ?? '');

    if (!$class_id || !$subject_id || !$student_id || !$year_id || !$term_id) {
        $flash = 'Please complete all required fields.'; $flash_type = 'error';
    } elseif ($is_teacher && !teacherTeaches($db, $user_id, $class_id, $subject_id)) {
        $flash = 'You can only record grades for the subjects and classes you teach.'; $flash_type = 'error';
    } else {
        try {
            $letter = getGradeLetter($total);
            // Upsert: one record per student/subject/term/year
            $exist = $db->prepare("SELECT id FROM student_academic_records WHERE student_id=:st AND subject_id=:su AND academic_term_id=:tm AND academic_year_id=:yr");
            $exist->execute([':st' => $student_id, ':su' => $subject_id, ':tm' => $term_id, ':yr' => $year_id]);
            $existing = $exist->fetchColumn();

            if ($existing) {
                $upd = $db->prepare("UPDATE student_academic_records SET class_id=:c, continuous_assessment=:ca, exam_score=:ex, total_score=:tot, grade=:gr, remarks=:rm, teacher_id=:tid WHERE id=:id");
                $upd->execute([':c' => $class_id, ':ca' => $ca, ':ex' => $exam, ':tot' => $total, ':gr' => $letter, ':rm' => $remarks, ':tid' => $user_id, ':id' => $existing]);
                $flash = 'Existing grade for this student/subject/term was updated.';
            } else {
                $ins = $db->prepare("INSERT INTO student_academic_records
                    (student_id, academic_year_id, academic_term_id, class_id, subject_id, continuous_assessment, exam_score, total_score, grade, remarks, teacher_id)
                    VALUES (:st,:yr,:tm,:c,:su,:ca,:ex,:tot,:gr,:rm,:tid)");
                $ins->execute([':st' => $student_id, ':yr' => $year_id, ':tm' => $term_id, ':c' => $class_id, ':su' => $subject_id,
                    ':ca' => $ca, ':ex' => $exam, ':tot' => $total, ':gr' => $letter, ':rm' => $remarks, ':tid' => $user_id]);
                $flash = 'Grade recorded successfully.';
            }
        } catch (PDOException $e) {
            $flash = 'Database error: ' . $e->getMessage(); $flash_type = 'error';
        }
    }
}

// ---- Build option data ----
if ($is_teacher) {
    $cls = $db->prepare("SELECT DISTINCT c.id, c.name, c.grade_level FROM class_teachers ct JOIN classes c ON ct.class_id=c.id WHERE ct.teacher_id=:t AND c.status='active' ORDER BY c.grade_level, c.name");
    $cls->execute([':t' => $user_id]);
    $classes = $cls->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes = $db->query("SELECT id, name, grade_level FROM classes WHERE status='active' ORDER BY grade_level, name")->fetchAll(PDO::FETCH_ASSOC);
}
$class_ids = array_column($classes, 'id');

// class -> subjects map
$class_subjects = [];
if (!empty($class_ids)) {
    $in = implode(',', array_map('intval', $class_ids));
    if ($is_teacher) {
        $rows = $db->prepare("SELECT DISTINCT ct.class_id, s.id, s.name FROM class_teachers ct JOIN subjects s ON ct.subject_id=s.id WHERE ct.teacher_id=:t AND ct.class_id IN ($in) ORDER BY s.name");
        $rows->execute([':t' => $user_id]);
        $rows = $rows->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $db->query("SELECT class_id, id, name FROM subjects WHERE class_id IN ($in) ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
    }
    foreach ($rows as $r) { $class_subjects[(int)$r['class_id']][] = ['id' => (int)$r['id'], 'name' => $r['name']]; }

    // class -> students map
    $srows = $db->query("SELECT sc.class_id, u.id, u.name, sp.student_id AS roll
        FROM student_classes sc JOIN users u ON sc.student_id=u.id
        JOIN student_profiles sp ON u.id=sp.user_id
        WHERE sc.class_id IN ($in) AND sc.status='active' AND u.role='student'
        ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
    $class_students = [];
    foreach ($srows as $r) { $class_students[(int)$r['class_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'roll' => $r['roll']]; }
} else {
    $class_students = [];
}

$years = $db->query("SELECT id, year_name FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$terms = $db->query("SELECT id, term_name, academic_year_id FROM academic_terms ORDER BY term_number")->fetchAll(PDO::FETCH_ASSOC);

$title = "Add Grade";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-3xl mx-auto">
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Add Grade</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Record a student's score for a subject you teach.</p>
                    </div>
                    <div class="flex gap-2">
                        <a href="bulk_entry.php" class="bg-purple-600 hover:bg-purple-700 text-white font-semibold px-4 py-2 rounded-lg transition flex items-center"><i class="fas fa-table mr-2"></i>Bulk Entry</a>
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm"><i class="fas fa-arrow-left mr-2"></i>Back</a>
                    </div>
                </div>

                <?php if ($flash): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border <?= $flash_type === 'error' ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:text-red-300' : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:text-green-300' ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-2"></i><?= htmlspecialchars($flash) ?>
                </div>
                <?php endif; ?>

                <?php if (empty($classes)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-10 text-center">
                    <i class="fas fa-chalkboard text-4xl text-gray-300 mb-3"></i>
                    <p class="text-gray-600 dark:text-gray-400">You are not assigned to any classes yet, so there is nothing to grade.</p>
                </div>
                <?php else: ?>
                <form method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 space-y-5" x-data="gradeForm()">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Class <span class="text-red-500">*</span></label>
                            <select name="class_id" id="class_id" required @change="onClassChange()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="">Select class</option>
                                <?php foreach ($classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">Grade <?= htmlspecialchars($c['grade_level']) ?> - <?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Subject <span class="text-red-500">*</span></label>
                            <select name="subject_id" id="subject_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="">Select class first</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Student <span class="text-red-500">*</span></label>
                            <select name="student_id" id="student_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="">Select class first</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Academic Year <span class="text-red-500">*</span></label>
                            <select name="year_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y['id'] ?>" <?= (int)$y['id'] === (int)$default_year ? 'selected' : '' ?>><?= htmlspecialchars($y['year_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Term <span class="text-red-500">*</span></label>
                            <select name="term_id" required class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <?php foreach ($terms as $t): ?>
                                <option value="<?= (int)$t['id'] ?>" <?= (int)$t['id'] === (int)$default_term ? 'selected' : '' ?>><?= htmlspecialchars($t['term_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-2 border-t border-gray-100 dark:border-gray-700">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">CA</label>
                            <input type="number" step="0.1" min="0" max="100" name="ca" x-model="ca" @input="recalc()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Exam</label>
                            <input type="number" step="0.1" min="0" max="100" name="exam" x-model="exam" @input="recalc()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Total</label>
                            <input type="number" step="0.1" min="0" max="100" name="total" x-model="total" @input="gradeFromTotal()" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white font-bold">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Grade</label>
                            <input type="text" readonly x-model="grade" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 dark:text-white font-bold text-center">
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-1">Remarks</label>
                        <textarea name="remarks" rows="2" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white" placeholder="Optional teacher comment"></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition flex items-center"><i class="fas fa-save mr-2"></i>Save Grade</button>
                        <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-5 py-2.5 rounded-lg transition flex items-center">Cancel</a>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </main>
        <div class="lg:ml-0"><?php include '../../includes/footer.php'; ?></div>
    </div>
</div>

<script>
const CLASS_SUBJECTS = <?= json_encode($class_subjects) ?>;
const CLASS_STUDENTS = <?= json_encode($class_students) ?>;

function gradeForm() {
    return {
        ca: '', exam: '', total: '', grade: '',
        letter(t) {
            t = parseFloat(t) || 0;
            if (t >= 90) return 'A+'; if (t >= 80) return 'A'; if (t >= 70) return 'B+';
            if (t >= 60) return 'B'; if (t >= 50) return 'C+'; if (t >= 40) return 'C';
            if (t >= 30) return 'D'; return 'F';
        },
        recalc() {
            const t = (parseFloat(this.ca) || 0) + (parseFloat(this.exam) || 0);
            this.total = t ? t.toFixed(1) : '';
            this.grade = t ? this.letter(t) : '';
        },
        gradeFromTotal() { this.grade = this.total ? this.letter(this.total) : ''; },
        onClassChange() {
            const cid = document.getElementById('class_id').value;
            const subSel = document.getElementById('subject_id');
            const stuSel = document.getElementById('student_id');
            subSel.innerHTML = '<option value="">Select subject</option>';
            stuSel.innerHTML = '<option value="">Select student</option>';
            (CLASS_SUBJECTS[cid] || []).forEach(s => {
                const o = document.createElement('option'); o.value = s.id; o.textContent = s.name; subSel.appendChild(o);
            });
            (CLASS_STUDENTS[cid] || []).forEach(s => {
                const o = document.createElement('option'); o.value = s.id; o.textContent = s.name + (s.roll ? ' (' + s.roll + ')' : ''); stuSel.appendChild(o);
            });
        }
    };
}
</script>
