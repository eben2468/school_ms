<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = (int)$_SESSION['user_id'];
$id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);

if (!$id) { header("Location: index.php"); exit(); }

$stmt = $db->prepare("SELECT sar.*, u.name AS student_name, sp.student_id AS student_number,
        s.name AS subject_name, s.code AS subject_code, c.name AS class_name, c.grade_level,
        ay.year_name, at.term_name, teacher.name AS teacher_name
    FROM student_academic_records sar
    JOIN users u ON sar.student_id = u.id
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN subjects s ON sar.subject_id = s.id
    JOIN classes c ON sar.class_id = c.id
    JOIN academic_years ay ON sar.academic_year_id = ay.id
    JOIN academic_terms at ON sar.academic_term_id = at.id
    LEFT JOIN users teacher ON sar.teacher_id = teacher.id
    WHERE sar.id = :id");
$stmt->execute([':id' => $id]);
$grade = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$grade) { header("Location: index.php"); exit(); }

// Access control: teachers only their taught class+subject; students only their own record
if ($user_role === 'teacher') {
    $chk = $db->prepare("SELECT COUNT(*) FROM class_teachers WHERE teacher_id = :tid AND class_id = :cid AND subject_id = :sid");
    $chk->execute([':tid' => $user_id, ':cid' => $grade['class_id'], ':sid' => $grade['subject_id']]);
    if ($chk->fetchColumn() == 0) { header("Location: index.php"); exit(); }
} elseif ($user_role === 'student' && (int)$grade['student_id'] !== $user_id) {
    header("Location: index.php"); exit();
}

// Grade display honours the school's configured grading system.
require_once '../../includes/settings_helper.php';
$letter = formatGrade($grade['total_score']);
$grade_badge_class = getGradeBadgeClass($grade['total_score']);
$can_edit = in_array($user_role, ['super_admin', 'school_admin', 'principal', 'teacher']);

$title = "Grade Details";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full max-w-3xl mx-auto">
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Grade Details</h1>
                    <a href="index.php" class="bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold px-4 py-2 rounded-lg transition flex items-center shadow-sm">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
                    <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-6 text-white">
                        <h2 class="text-2xl font-bold"><?= htmlspecialchars($grade['student_name']) ?></h2>
                        <p class="text-blue-100">ID: <?= htmlspecialchars($grade['student_number']) ?> &bull; <?= htmlspecialchars($grade['class_name']) ?> (<?= htmlspecialchars($grade['grade_level']) ?>)</p>
                    </div>
                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php
                        $fields = [
                            ['Subject', $grade['subject_name'] . ' (' . $grade['subject_code'] . ')'],
                            ['Academic Year', $grade['year_name']],
                            ['Term', $grade['term_name']],
                            ['Recorded By', $grade['teacher_name'] ?? '—'],
                            ['Continuous Assessment', number_format($grade['continuous_assessment'] ?? 0, 1)],
                            ['Exam Score', number_format($grade['exam_score'] ?? 0, 1)],
                        ];
                        foreach ($fields as $f): ?>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide"><?= $f[0] ?></p>
                            <p class="text-base font-medium text-gray-900 dark:text-white mt-1"><?= htmlspecialchars($f[1]) ?></p>
                        </div>
                        <?php endforeach; ?>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Total Score</p>
                            <p class="text-2xl font-extrabold text-gray-900 dark:text-white mt-1"><?= number_format($grade['total_score'] ?? 0, 1) ?>%</p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Grade</p>
                            <span class="inline-flex mt-1 px-3 py-1 text-lg font-bold rounded-lg <?= $grade_badge_class ?>"><?= htmlspecialchars($letter) ?></span>
                        </div>
                        <?php if (!empty($grade['remarks'])): ?>
                        <div class="sm:col-span-2">
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Remarks</p>
                            <p class="text-base text-gray-700 dark:text-gray-300 mt-1 bg-gray-50 dark:bg-gray-700/40 rounded-lg p-3"><?= htmlspecialchars($grade['remarks']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_edit): ?>
                    <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-2">
                        <a href="edit.php?id=<?= (int)$grade['id'] ?>" class="bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 rounded-lg transition flex items-center"><i class="fas fa-edit mr-2"></i>Edit</a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
        <div class="lg:ml-0"><?php include '../../includes/footer.php'; ?></div>
    </div>
</div>
