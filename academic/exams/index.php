<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Fetch active classes
$query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$stmt = $db->query($query);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Examination Management</h1>
                <div class="space-x-4">
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
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
                    <div class="bg-gray-50">
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
                    <div class="divide-y divide-gray-200">
                        <?php foreach ($exams as $exam): ?>
                        <div class="grid grid-cols-7 gap-4 px-6 py-4 hover:bg-gray-50">
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($exam['class_name'] ?: 'N/A'); ?>
                            </div>
                            <div class="text-sm font-medium text-gray-900">
                                <?php echo htmlspecialchars($exam['subject_name'] ?: 'N/A'); ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php
                                $exam_type_labels = [
                                    'midterm' => 'Midterm',
                                    'final' => 'Final',
                                    'quiz' => 'Quiz',
                                    'assignment' => 'Assignment',
                                    'project' => 'Project'
                                ];
                                echo htmlspecialchars($exam_type_labels[$exam['exam_type']] ?? ucfirst($exam['exam_type']));
                                ?>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php echo date('M j, Y', strtotime($exam['date'])); ?>
                                <br>
                                <span class="text-xs">
                                    <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                                </span>
                            </div>
                            <div class="text-sm text-gray-500">
                                <?php
                                $duration = isset($exam['duration']) ? $exam['duration'] :
                                    (strtotime($exam['end_time']) - strtotime($exam['start_time'])) / 60;
                                echo $duration;
                                ?> minutes
                            </div>
                            <div class="text-sm">
                                <?php
                                $exam_date = strtotime($exam['date'] . ' ' . $exam['start_time']);
                                $now = time();
                                $status_class = '';
                                $status_text = '';

                                if ($now < $exam_date) {
                                    $status_class = 'text-yellow-600';
                                    $status_text = 'Upcoming';
                                } elseif ($now < ($exam_date + ($duration * 60))) {
                                    $status_class = 'text-green-600';
                                    $status_text = 'In Progress';
                                } else {
                                    $status_class = 'text-blue-600';
                                    $status_text = 'Completed';
                                }
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </span>
                            </div>
                            <div class="text-sm text-right space-x-3">
                                <a href="view.php?id=<?php echo $exam['id']; ?>" 
                                   class="text-blue-600 hover:text-blue-900">
                                    View
                                </a>
                                <?php if ($status_text === 'Completed'): ?>
                                <a href="results.php?id=<?php echo $exam['id']; ?>"
                                   class="text-green-600 hover:text-green-900">
                                    Results
                                </a>
                                <?php endif; ?>
                                <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal']) && $status_text === 'Upcoming'): ?>
                                <a href="edit.php?id=<?php echo $exam['id']; ?>" 
                                   class="text-indigo-600 hover:text-indigo-900">
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