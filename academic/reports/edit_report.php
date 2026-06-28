<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: index.php?error=Unauthorized access");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$report_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$report_id) {
    header("Location: index.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $teacher_remarks = filter_input(INPUT_POST, 'teacher_remarks', FILTER_SANITIZE_STRING) ?: null;
    $principal_remarks = filter_input(INPUT_POST, 'principal_remarks', FILTER_SANITIZE_STRING) ?: null;
    $conduct_grade = filter_input(INPUT_POST, 'conduct_grade', FILTER_SANITIZE_STRING) ?: null;
    $interest = filter_input(INPUT_POST, 'interest', FILTER_SANITIZE_STRING) ?: null;
    $attitude = filter_input(INPUT_POST, 'attitude', FILTER_SANITIZE_STRING) ?: null;
    
    $attendance_present = filter_input(INPUT_POST, 'attendance_present', FILTER_VALIDATE_INT);
    if ($attendance_present === false) $attendance_present = null;
    
    $attendance_days = filter_input(INPUT_POST, 'attendance_days', FILTER_VALIDATE_INT);
    if ($attendance_days === false) $attendance_days = null;
    
    $promoted_input = $_POST['promoted'];
    $promoted = ($promoted_input === '1') ? 1 : (($promoted_input === '0') ? 0 : null);
    
    $next_term_begins = $_POST['next_term_begins'] ?: null;

    try {
        $update_sql = "UPDATE term_reports SET 
            teacher_remarks = :teacher_remarks,
            principal_remarks = :principal_remarks,
            conduct_grade = :conduct_grade,
            interest = :interest,
            attitude = :attitude,
            attendance_present = :attendance_present,
            attendance_days = :attendance_days,
            promoted = :promoted,
            next_term_begins = :next_term_begins
            WHERE id = :report_id";
            
        $stmt = $db->prepare($update_sql);
        $stmt->bindValue(':teacher_remarks', $teacher_remarks, $teacher_remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':principal_remarks', $principal_remarks, $principal_remarks === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':conduct_grade', $conduct_grade, $conduct_grade === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':interest', $interest, $interest === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':attitude', $attitude, $attitude === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':attendance_present', $attendance_present, $attendance_present === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':attendance_days', $attendance_days, $attendance_days === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':promoted', $promoted, $promoted === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':next_term_begins', $next_term_begins, $next_term_begins === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->bindValue(':report_id', $report_id, PDO::PARAM_INT);
        
        if ($stmt->execute()) {
            $success_message = "Report card updated successfully!";
        } else {
            $error_message = "Error updating report card settings.";
        }
    } catch (PDOException $e) {
        $error_message = "Database error: " . $e->getMessage();
    }
}

// Fetch report details
$report_sql = "SELECT 
    tr.*,
    u.name as student_name, sp.student_id as student_code,
    c.name as class_name, c.grade_level,
    ay.year_name,
    at.term_name
FROM term_reports tr
JOIN users u ON tr.student_id = u.id
LEFT JOIN student_profiles sp ON u.id = sp.user_id
JOIN classes c ON tr.class_id = c.id
JOIN academic_years ay ON tr.academic_year_id = ay.id
JOIN academic_terms at ON tr.academic_term_id = at.id
WHERE tr.id = :report_id";

$stmt = $db->prepare($report_sql);
$stmt->bindParam(':report_id', $report_id);
$stmt->execute();
$report = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$report) {
    header("Location: index.php?error=Report not found");
    exit();
}

$title = "Edit Report Card";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-4xl mx-auto">
                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Term Reports</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Edit Report Card</span>
                </div>

                <!-- Page Header -->
                <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Edit Report Card</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">
                            Manually override remarks, conduct, attitude, and attendance for 
                            <span class="font-semibold text-indigo-600 dark:text-indigo-400"><?php echo htmlspecialchars($report['student_name']); ?></span>
                        </p>
                    </div>
                    <div class="flex gap-3">
                        <a href="view.php?id=<?php echo $report_id; ?>" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300 font-semibold rounded-lg text-sm transition flex items-center">
                            <i class="fas fa-eye mr-2"></i> View Report
                        </a>
                        <a href="index.php" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-lg text-sm transition hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> Back to List
                        </a>
                    </div>
                </div>

                <!-- Notification Alerts -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-emerald-100 border-l-4 border-emerald-500 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400 rounded flex items-center shadow-sm">
                    <i class="fas fa-check-circle mr-3 text-lg"></i>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-800 dark:bg-red-950/30 dark:text-red-400 rounded flex items-center shadow-sm">
                    <i class="fas fa-exclamation-circle mr-3 text-lg"></i>
                    <div><?php echo htmlspecialchars($error_message); ?></div>
                </div>
                <?php endif; ?>

                <!-- Student Quick Details Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 p-6 mb-8">
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                        <div>
                            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Student ID</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white mt-1"><?php echo htmlspecialchars($report['student_code'] ?? 'N/A'); ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Class / Grade</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white mt-1">Grade <?php echo htmlspecialchars($report['grade_level']); ?> - <?php echo htmlspecialchars($report['class_name']); ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Academic Year</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white mt-1"><?php echo htmlspecialchars($report['year_name']); ?></div>
                        </div>
                        <div>
                            <div class="text-xs font-semibold text-gray-400 uppercase tracking-wider">Academic Term</div>
                            <div class="text-sm font-bold text-gray-900 dark:text-white mt-1"><?php echo htmlspecialchars($report['term_name']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Main Edit Form -->
                <form method="POST" class="space-y-8">
                    <!-- Section 1: Conduct, Interest & Attitude -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex items-center">
                            <div class="w-10 h-10 bg-indigo-100 dark:bg-indigo-950 rounded-lg flex items-center justify-center text-indigo-600 dark:text-indigo-400 mr-3">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Conduct, Interest & Behaviour</h2>
                        </div>
                        <div class="p-6 grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label for="conduct_grade" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Conduct / Behaviour Grade</label>
                                <input type="text" id="conduct_grade" name="conduct_grade" 
                                    value="<?php echo htmlspecialchars($report['conduct_grade'] ?? ''); ?>" 
                                    placeholder="e.g. A, B, Excellent"
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="interest" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Student's Key Interest</label>
                                <input type="text" id="interest" name="interest" 
                                    value="<?php echo htmlspecialchars($report['interest'] ?? ''); ?>" 
                                    placeholder="e.g. Science, Art, Sports"
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="attitude" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Attitude / Class Deportment</label>
                                <input type="text" id="attitude" name="attitude" 
                                    value="<?php echo htmlspecialchars($report['attitude'] ?? ''); ?>" 
                                    placeholder="e.g. Attentive, Hardworking"
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>

                    <!-- Section 2: Attendance & Promotion -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex items-center">
                            <div class="w-10 h-10 bg-emerald-100 dark:bg-emerald-950 rounded-lg flex items-center justify-center text-emerald-600 dark:text-emerald-400 mr-3">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Attendance & Promotion Status</h2>
                        </div>
                        <div class="p-6 grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div>
                                <label for="attendance_present" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Days Present</label>
                                <input type="number" id="attendance_present" name="attendance_present" 
                                    value="<?php echo htmlspecialchars($report['attendance_present'] ?? '0'); ?>" 
                                    min="0"
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="attendance_days" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Total Term Days</label>
                                <input type="number" id="attendance_days" name="attendance_days" 
                                    value="<?php echo htmlspecialchars($report['attendance_days'] ?? '0'); ?>" 
                                    min="0"
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                            <div>
                                <label for="promoted" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Promotion Status</label>
                                <select id="promoted" name="promoted" 
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    <option value="" <?php echo $report['promoted'] === null ? 'selected' : ''; ?>>Not Applicable (N/A)</option>
                                    <option value="1" <?php echo $report['promoted'] === 1 ? 'selected' : ''; ?>>Promoted</option>
                                    <option value="0" <?php echo $report['promoted'] === 0 ? 'selected' : ''; ?>>Not Promoted</option>
                                </select>
                            </div>
                            <div>
                                <label for="next_term_begins" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Next Term Begins</label>
                                <input type="date" id="next_term_begins" name="next_term_begins" 
                                    value="<?php echo htmlspecialchars($report['next_term_begins'] ?? ''); ?>" 
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>
                    </div>

                    <!-- Section 3: Remarks -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-md border border-gray-200 dark:border-gray-700 overflow-hidden">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/50 flex items-center">
                            <div class="w-10 h-10 bg-purple-100 dark:bg-purple-950 rounded-lg flex items-center justify-center text-purple-600 dark:text-purple-400 mr-3">
                                <i class="fas fa-comment-dots"></i>
                            </div>
                            <h2 class="text-xl font-bold text-gray-900 dark:text-white">Manual Remarks</h2>
                        </div>
                        <div class="p-6 space-y-6">
                            <div>
                                <label for="teacher_remarks" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Class Teacher's Remarks</label>
                                <textarea id="teacher_remarks" name="teacher_remarks" rows="4" 
                                    placeholder="Write teacher comments here..."
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($report['teacher_remarks'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label for="principal_remarks" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Headteacher / Headmaster/Headmistress's Remarks</label>
                                <textarea id="principal_remarks" name="principal_remarks" rows="4" 
                                    placeholder="Write principal comments here..."
                                    class="w-full px-4 py-2.5 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($report['principal_remarks'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Form Submissions Action -->
                    <div class="flex justify-end space-x-4">
                        <a href="index.php" class="px-6 py-3 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-lg shadow-sm transition hover:bg-gray-50 dark:hover:bg-gray-750">
                            Cancel
                        </a>
                        <button type="submit" class="px-8 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg shadow transition duration-200">
                            <i class="fas fa-save mr-2"></i> Save Report Card Details
                        </button>
                    </div>
                </form>
            </div>
        </main>

        <!-- Footer -->
        <div>
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
