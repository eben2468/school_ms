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
$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT) ?: (int)($_POST['id'] ?? 0);

if (!$id) { header("Location: index.php"); exit(); }

// Stored letter grade comes from the scale-aware helper (getGradeLetter in
// settings_helper.php) so it stays consistent with the grading scales.

// Load record with context
$stmt = $db->prepare("SELECT sar.*, u.name AS student_name, sp.student_id AS student_number,
        s.name AS subject_name, c.name AS class_name, c.grade_level, ay.year_name, at.term_name
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN subjects s ON sar.subject_id = s.id
    JOIN classes c ON sar.class_id = c.id
    JOIN academic_years ay ON sar.academic_year_id = ay.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    WHERE sar.id = :id");
$stmt->execute([':id' => $id]);
$grade = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grade) { header("Location: index.php"); exit(); }

// Teachers may only edit grades for class+subject they teach
if ($is_teacher) {
    $chk = $db->prepare("SELECT COUNT(*) FROM class_teachers WHERE teacher_id=:t AND class_id=:c AND subject_id=:s");
    $chk->execute([':t' => $user_id, ':c' => $grade['class_id'], ':s' => $grade['subject_id']]);
    if ($chk->fetchColumn() == 0) { header("Location: index.php"); exit(); }
}

$flash = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ca = is_numeric($_POST['ca'] ?? '') ? (float)$_POST['ca'] : 0;
    $exam = is_numeric($_POST['exam'] ?? '') ? (float)$_POST['exam'] : 0;
    $total = is_numeric($_POST['total'] ?? '') ? (float)$_POST['total'] : ($ca + $exam);
    $remarks = trim($_POST['remarks'] ?? '');
    $letter = getGradeLetter($total);
    try {
        $upd = $db->prepare("UPDATE student_academic_records SET continuous_assessment=:ca, exam_score=:ex, total_score=:tot, grade=:gr, remarks=:rm WHERE id=:id");
        $upd->execute([':ca' => $ca, ':ex' => $exam, ':tot' => $total, ':gr' => $letter, ':rm' => $remarks, ':id' => $id]);
        $flash = 'Grade updated successfully.';
        // refresh local copy
        $grade['continuous_assessment'] = $ca; $grade['exam_score'] = $exam;
        $grade['total_score'] = $total; $grade['grade'] = $letter; $grade['remarks'] = $remarks;
    } catch (PDOException $e) {
        $flash = 'Database error: ' . $e->getMessage(); $flash_type = 'error';
    }
}

$title = "Edit Grade";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-2xl mx-auto">
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Edit Grade</h1>
                    <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm"><i class="fas fa-arrow-left mr-2"></i>Back</a>
                </div>

                <?php if ($flash): ?>
                <div class="mb-6 px-4 py-3 rounded-lg border <?= $flash_type === 'error' ? 'bg-red-50 border-red-200 text-red-700 dark:bg-red-900/20 dark:text-red-300' : 'bg-green-50 border-green-200 text-green-700 dark:bg-green-900/20 dark:text-green-300' ?>">
                    <i class="fas <?= $flash_type === 'error' ? 'fa-exclamation-circle' : 'fa-check-circle' ?> mr-2"></i><?= htmlspecialchars($flash) ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 space-y-5" x-data="editForm(<?= json_encode([
                    'ca' => (float)($grade['continuous_assessment'] ?? 0),
                    'exam' => (float)($grade['exam_score'] ?? 0),
                    'total' => (float)($grade['total_score'] ?? 0)
                ]) ?>)">
                    <input type="hidden" name="id" value="<?= (int)$grade['id'] ?>">

                    <div class="bg-gray-50 dark:bg-gray-700/40 rounded-lg p-4">
                        <p class="text-lg font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($grade['student_name']) ?> <span class="text-sm font-normal text-gray-500">(<?= htmlspecialchars($grade['student_number']) ?>)</span></p>
                        <p class="text-sm text-gray-600 dark:text-gray-400"><?= htmlspecialchars($grade['subject_name']) ?> &bull; <?= htmlspecialchars($grade['class_name']) ?> &bull; <?= htmlspecialchars($grade['term_name']) ?>, <?= htmlspecialchars($grade['year_name']) ?></p>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
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
                        <textarea name="remarks" rows="2" class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white"><?= htmlspecialchars($grade['remarks'] ?? '') ?></textarea>
                    </div>

                    <div class="flex gap-3">
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-5 py-2.5 rounded-lg shadow-sm transition flex items-center"><i class="fas fa-save mr-2"></i>Update Grade</button>
                        <a href="view.php?id=<?= (int)$grade['id'] ?>" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-5 py-2.5 rounded-lg transition flex items-center">View</a>
                    </div>
                </form>
            </div>
        </main>
        <div class="lg:ml-0"><?php include '../../includes/footer.php'; ?></div>
    </div>
</div>

<script>
function editForm(init) {
    return {
        ca: init.ca, exam: init.exam, total: init.total, grade: '',
        letter(t) {
            t = parseFloat(t) || 0;
            if (t >= 90) return 'A+'; if (t >= 80) return 'A'; if (t >= 70) return 'B+';
            if (t >= 60) return 'B'; if (t >= 50) return 'C+'; if (t >= 40) return 'C';
            if (t >= 30) return 'D'; return 'F';
        },
        init() { this.grade = this.letter(this.total); },
        recalc() {
            const t = (parseFloat(this.ca) || 0) + (parseFloat(this.exam) || 0);
            this.total = t.toFixed(1); this.grade = this.letter(t);
        },
        gradeFromTotal() { this.grade = this.letter(this.total); }
    };
}
</script>
