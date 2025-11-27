<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$exam_id = isset($_GET['id']) ? $_GET['id'] : null;
if (!$exam_id) {
    header("Location: index.php");
    exit();
}

// Get exam details
$query = "SELECT e.*, s.name as subject_name, s.code as subject_code, 
          c.name as class_name, c.grade_level 
          FROM exams e 
          JOIN subjects s ON e.subject_id = s.id 
          JOIN classes c ON e.class_id = c.id 
          WHERE e.id = :exam_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':exam_id', $exam_id);
$stmt->execute();
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: index.php");
    exit();
}

// Check if exam has started
$exam_datetime = strtotime($exam['exam_date'] . ' ' . $exam['start_time']);
if ($exam_datetime <= time()) {
    header("Location: view.php?id=" . $exam_id);
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $exam_type = $_POST['exam_type'];
    $academic_term = $_POST['academic_term'];
    $exam_date = $_POST['exam_date'];
    $start_time = $_POST['start_time'];
    $duration = $_POST['duration'];
    $max_marks = $_POST['max_marks'];
    $passing_marks = $_POST['passing_marks'];
    
    // Validate input
    $errors = [];
    if (empty($exam_type)) $errors[] = "Exam type is required.";
    if (empty($exam_date)) $errors[] = "Exam date is required.";
    if (empty($start_time)) $errors[] = "Start time is required.";
    if (empty($duration)) $errors[] = "Duration is required.";
    if (empty($max_marks)) $errors[] = "Maximum marks is required.";
    if (empty($passing_marks)) $errors[] = "Passing marks is required.";
    
    if (empty($errors)) {
        $query = "UPDATE exams SET
                    exam_type = :exam_type,
                    academic_term = :academic_term,
                    exam_date = :exam_date,
                    date = :exam_date,
                    start_time = :start_time,
                    duration = :duration,
                    total_marks = :total_marks
                 WHERE id = :exam_id";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':exam_type', $exam_type);
        $stmt->bindParam(':academic_term', $academic_term);
        $stmt->bindParam(':exam_date', $exam_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':duration', $duration);
        $stmt->bindParam(':total_marks', $max_marks);
        $stmt->bindParam(':exam_id', $exam_id);
        
        if ($stmt->execute()) {
            header("Location: view.php?id=" . $exam_id . "&updated=1");
            exit();
        } else {
            $errors[] = "Error updating exam.";
        }
    }
}

$title = "Edit Exam - " . $exam['subject_name'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-3xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Edit Exam</h1>
                <a href="view.php?id=<?php echo $exam_id; ?>" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Exam Details
                </a>
            </div>

            <?php if (!empty($errors)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Please fix the following errors:</strong>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="p-6">
                    <!-- Current Details -->
                    <div class="mb-6 pb-6 border-b border-gray-200">
                        <h2 class="text-lg font-semibold text-gray-800 mb-4">Current Details</h2>
                        <dl class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Subject</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    <?php echo htmlspecialchars($exam['subject_name']); ?> 
                                    (<?php echo htmlspecialchars($exam['subject_code']); ?>)
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-gray-500">Class</dt>
                                <dd class="mt-1 text-sm text-gray-900">
                                    Grade <?php echo htmlspecialchars($exam['grade_level']); ?> - 
                                    <?php echo htmlspecialchars($exam['class_name']); ?>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Edit Form -->
                    <form method="POST" class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Exam Type -->
                            <div>
                                <label for="exam_type" class="block text-sm font-medium text-gray-700 mb-1">Exam Type*</label>
                                <select id="exam_type" name="exam_type" required
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Select Type</option>
                                    <?php
                                    $exam_types = [
                                        'midterm' => 'Midterm',
                                        'final' => 'Final',
                                        'quiz' => 'Quiz',
                                        'assignment' => 'Assignment',
                                        'project' => 'Project'
                                    ];
                                    foreach ($exam_types as $value => $label):
                                        $selected = $exam['exam_type'] === $value ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $value; ?>" <?php echo $selected; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Academic Term -->
                            <div>
                                <label for="academic_term" class="block text-sm font-medium text-gray-700 mb-1">Academic Term*</label>
                                <select id="academic_term" name="academic_term" required
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                    <option value="">Select Term</option>
                                    <?php
                                    $terms = [
                                        'first' => 'First Term',
                                        'second' => 'Second Term',
                                        'third' => 'Third Term'
                                    ];
                                    foreach ($terms as $value => $label):
                                        $selected = $exam['academic_term'] === $value ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo $value; ?>" <?php echo $selected; ?>>
                                        <?php echo $label; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Exam Date -->
                            <div>
                                <label for="exam_date" class="block text-sm font-medium text-gray-700 mb-1">Exam Date*</label>
                                <input type="date" id="exam_date" name="exam_date" required
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                    min="<?php echo date('Y-m-d'); ?>"
                                    value="<?php echo $exam['exam_date']; ?>">
                            </div>

                            <!-- Start Time -->
                            <div>
                                <label for="start_time" class="block text-sm font-medium text-gray-700 mb-1">Start Time*</label>
                                <input type="time" id="start_time" name="start_time" required
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                    value="<?php echo $exam['start_time']; ?>">
                            </div>

                            <!-- Duration -->
                            <div>
                                <label for="duration" class="block text-sm font-medium text-gray-700 mb-1">Duration (minutes)*</label>
                                <input type="number" id="duration" name="duration" required min="15" max="180"
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                    value="<?php echo $exam['duration']; ?>">
                            </div>

                            <!-- Maximum Marks -->
                            <div>
                                <label for="max_marks" class="block text-sm font-medium text-gray-700 mb-1">Maximum Marks*</label>
                                <input type="number" id="max_marks" name="max_marks" required min="1" max="100"
                                    class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                    value="<?php echo $exam['total_marks']; ?>">
                            </div>

                            <!-- Passing Marks (Read-only, calculated as 40%) -->
                            <div>
                                <label for="passing_marks_display" class="block text-sm font-medium text-gray-700 mb-1">Passing Marks (40%)</label>
                                <input type="number" id="passing_marks_display" readonly
                                    class="w-full px-4 py-2 border rounded-lg bg-gray-100 text-gray-600"
                                    value="<?php echo round($exam['total_marks'] * 0.4); ?>">
                                <small class="text-gray-500">Automatically calculated as 40% of maximum marks</small>
                            </div>
                        </div>

                        <div class="flex justify-end space-x-4 mt-6">
                            <button type="button" onclick="window.location.href='view.php?id=<?php echo $exam_id; ?>'" 
                                class="px-6 py-2 border rounded-lg hover:bg-gray-100">
                                Cancel
                            </button>
                            <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                                Update Exam
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>