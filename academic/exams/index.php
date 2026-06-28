<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Fetch active classes (teachers see only the classes they teach)
$is_teacher = $_SESSION['role'] === 'teacher';
if ($is_teacher) {
    $query = "SELECT DISTINCT c.id, c.name, c.grade_level
              FROM classes c
              JOIN class_teachers ct ON ct.class_id = c.id
              WHERE c.status = 'active' AND ct.teacher_id = :tid
              ORDER BY c.grade_level, c.name";
    $stmt = $db->prepare($query);
    $stmt->execute([':tid' => $_SESSION['user_id']]);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $stmt = $db->query($query);
    $classes = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get selected class exams
$selected_class_id = isset($_GET['class_id']) ? (int)$_GET['class_id'] : null;
$exam_type = isset($_GET['exam_type']) ? trim($_GET['exam_type']) : '';
$academic_term = isset($_GET['academic_term']) ? trim($_GET['academic_term']) : '';

// Build query conditions
$where_conditions = [];
$params = [];

if ($selected_class_id) {
    $where_conditions[] = "e.class_id = :class_id";
    $params[':class_id'] = $selected_class_id;
}

if ($exam_type) {
    $where_conditions[] = "e.exam_type = :exam_type";
    $params[':exam_type'] = $exam_type;
}

if ($academic_term) {
    $where_conditions[] = "e.academic_term = :academic_term";
    $params[':academic_term'] = $academic_term;
}

// Teachers only see exams for the classes + subjects they teach
if ($is_teacher) {
    $where_conditions[] = "EXISTS (SELECT 1 FROM class_teachers ct
                                   WHERE ct.class_id = e.class_id
                                   AND ct.subject_id = e.subject_id
                                   AND ct.teacher_id = :teacher_id)";
    $params[':teacher_id'] = $_SESSION['user_id'];
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Simplified query without subquery
$query = "SELECT e.*, s.name as subject_name, c.name as class_name, c.grade_level
          FROM exams e
          LEFT JOIN subjects s ON e.subject_id = s.id
          LEFT JOIN classes c ON e.class_id = c.id
          $where_clause
          ORDER BY e.date DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

if ($stmt->execute()) {
    $exams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $exams = [];
    $query_error = $stmt->errorInfo();
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <?php if (isset($_GET['error']) && $_GET['error'] === 'not_authorized'): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Access denied:</strong>
                <span class="block sm:inline">You can only manage exams for classes and subjects you teach.</span>
            </div>
            <?php endif; ?>
            <div class="exam-management-header">
                <h1 class="text-3xl font-semibold text-gray-800 mb-3">Examination Management</h1>
                <div class="flex no-stack space-x-4">
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <a href="create.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg">
                        Schedule New Exam
                    </a>
                    <?php endif; ?>
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Academic Management
                    </a>
                </div>
            </div>

            <!-- Filters -->
            <div class="bg-white rounded-lg shadow mb-6">
                <div class="p-6">
                    <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700">Class</label>
                            <select id="class_id" name="class_id" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="">Select a class...</option>
                                <?php foreach ($classes as $class): ?>
                                    <?php $selected = $selected_class_id == $class['id'] ? 'selected' : ''; ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $selected; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - 
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="exam_type" class="block text-sm font-medium text-gray-700">Exam Type</label>
                            <select id="exam_type" name="exam_type"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="">All Types</option>
                                <?php
                                $exam_types = [
                                    'midterm' => 'Midterm',
                                    'final' => 'Final',
                                    'quiz' => 'Quiz',
                                    'assignment' => 'Assignment',
                                    'project' => 'Project'
                                ];
                                foreach ($exam_types as $value => $label):
                                    $selected = $exam_type === $value ? 'selected' : '';
                                ?>
                                <option value="<?php echo $value; ?>" <?php echo $selected; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="academic_term" class="block text-sm font-medium text-gray-700">Academic Term</label>
                            <select id="academic_term" name="academic_term"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                                onchange="this.form.submit()">
                                <option value="">All Terms</option>
                                <?php
                                $terms = [
                                    'first' => 'First Term',
                                    'second' => 'Second Term',
                                    'third' => 'Third Term'
                                ];
                                foreach ($terms as $value => $label):
                                    $selected = $academic_term === $value ? 'selected' : '';
                                ?>
                                <option value="<?php echo $value; ?>" <?php echo $selected; ?>>
                                    <?php echo $label; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Exams List -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="min-w-full divide-y divide-gray-200">
                    <?php if (!empty($exams)): ?>
                    <!-- Desktop Table Header -->
                    <div class="bg-gray-50 exam-table-header">
                        <div class="grid grid-cols-7 gap-4 px-6 py-3">
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Class</div>
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Subject</div>
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</div>
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</div>
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</div>
                            <div class="text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</div>
                            <div class="text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</div>
                        </div>
                    </div>
                    <div class="divide-y divide-gray-200 exam-list-container">
                        <?php foreach ($exams as $exam): ?>
                        <?php
                        // Calculate status
                        $duration = isset($exam['duration']) ? $exam['duration'] :
                            (strtotime($exam['end_time']) - strtotime($exam['start_time'])) / 60;
                        $exam_date = strtotime($exam['date'] . ' ' . $exam['start_time']);
                        $now = time();
                        $status_class = '';
                        $status_text = '';

                        if ($now < $exam_date) {
                            $status_class = 'text-yellow-600 bg-yellow-50';
                            $status_text = 'Upcoming';
                        } elseif ($now < ($exam_date + ($duration * 60))) {
                            $status_class = 'text-green-600 bg-green-50';
                            $status_text = 'In Progress';
                        } else {
                            $status_class = 'text-blue-600 bg-blue-50';
                            $status_text = 'Completed';
                        }

                        $exam_type_labels = [
                            'midterm' => 'Midterm',
                            'final' => 'Final',
                            'quiz' => 'Quiz',
                            'assignment' => 'Assignment',
                            'project' => 'Project'
                        ];
                        $exam_type_display = $exam_type_labels[$exam['exam_type']] ?? ucfirst($exam['exam_type']);
                        ?>
                        
                        <!-- Desktop Row -->
                        <div class="exam-table-row grid grid-cols-7 gap-4 px-6 py-4 hover:bg-gray-50">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($exam['class_name'] ?: 'N/A'); ?>
                            </div>
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($exam['subject_name'] ?: 'N/A'); ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php echo htmlspecialchars($exam_type_display); ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php echo date('M j, Y', strtotime($exam['date'])); ?>
                                <br>
                                <span class="text-xs">
                                    <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                                </span>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php echo $duration; ?> minutes
                            </div>
                            <div class="text-sm">
                                <span class="<?php echo $status_class; ?> px-2 py-1 rounded">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            <div class="text-sm text-right flex justify-end gap-2">
                                <a href="view.php?id=<?php echo $exam['id']; ?>" 
                                   class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded text-sm font-medium inline-block">
                                    View
                                </a>
                                <?php if ($status_text === 'Completed'): ?>
                                <a href="results.php?id=<?php echo $exam['id']; ?>"
                                   class="bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded text-sm font-medium inline-block">
                                    Results
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                <a href="edit.php?id=<?php echo $exam['id']; ?>"
                                   class="bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-1.5 rounded text-sm font-medium inline-block">
                                    Edit
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Mobile Card -->
                        <div class="exam-mobile-card p-4 border-b border-gray-200">
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex-1">
                                    <h3 class="text-base font-semibold text-gray-900 mb-1">
                                        <?php echo htmlspecialchars($exam['class_name'] ?: 'N/A'); ?>
                                    </h3>
                                    <p class="text-sm font-medium text-gray-700">
                                        <?php echo htmlspecialchars($exam['subject_name'] ?: 'N/A'); ?>
                                    </p>
                                </div>
                                <span class="<?php echo $status_class; ?> px-3 py-1 rounded-full text-xs font-semibold">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-3 mb-3 text-sm">
                                <div>
                                    <span class="text-gray-500 text-xs block">Type</span>
                                    <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($exam_type_display); ?></span>
                                </div>
                                <div>
                                    <span class="text-gray-500 text-xs block">Duration</span>
                                    <span class="text-gray-900 font-medium"><?php echo $duration; ?> min</span>
                                </div>
                                <div class="col-span-2">
                                    <span class="text-gray-500 text-xs block">Date & Time</span>
                                    <span class="text-gray-900 font-medium">
                                        <?php echo date('M j, Y', strtotime($exam['date'])); ?> at 
                                        <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                                    </span>
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2 pt-3 border-t border-gray-100">
                                <a href="view.php?id=<?php echo $exam['id']; ?>" 
                                   class="flex-1 text-center bg-blue-500 hover:bg-blue-600 text-white px-3 py-2 rounded text-sm font-medium">
                                    View
                                </a>
                                <?php if ($status_text === 'Completed'): ?>
                                <a href="results.php?id=<?php echo $exam['id']; ?>"
                                   class="flex-1 text-center bg-green-500 hover:bg-green-600 text-white px-3 py-2 rounded text-sm font-medium">
                                    Results
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                                <a href="edit.php?id=<?php echo $exam['id']; ?>"
                                   class="flex-1 text-center bg-indigo-500 hover:bg-indigo-600 text-white px-3 py-2 rounded text-sm font-medium">
                                    Edit
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div class="p-6 text-center text-gray-500">
                        No exams found for the selected criteria.
                        <?php if (!$selected_class_id): ?>
                        <br><small>Select a class above to filter exams, or create a new exam using the "Schedule New Exam" button.</small>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>