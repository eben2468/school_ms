<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
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
          c.name as class_name, c.grade_level,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id) as total_submissions,
          (SELECT COUNT(*) FROM exam_results er WHERE er.exam_id = e.id AND er.marks_obtained >= (e.total_marks * 0.4)) as passed_count
          FROM exams e
          LEFT JOIN subjects s ON e.subject_id = s.id
          LEFT JOIN classes c ON e.class_id = c.id
          WHERE e.id = :exam_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':exam_id', $exam_id);
$stmt->execute();
$exam = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$exam) {
    header("Location: index.php");
    exit();
}

// Handle result submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_results'])) {
    foreach ($_POST['marks'] as $student_id => $marks) {
        if ($marks !== '') {
            $query = "INSERT INTO exam_results (exam_id, student_id, marks_obtained, remarks)
                     VALUES (:exam_id, :student_id, :marks, :remarks)
                     ON DUPLICATE KEY UPDATE marks_obtained = :marks, remarks = :remarks";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':exam_id', $exam_id);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':marks', $marks);
            $remarks = isset($_POST['remarks'][$student_id]) ? $_POST['remarks'][$student_id] : '';
            $stmt->bindParam(':remarks', $remarks);
            $stmt->execute();
        }
    }
    
    header("Location: results.php?id=" . $exam_id . "&success=1");
    exit();
}

$title = "Exam Results - " . $exam['subject_name'];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="flex">
    <!-- Sidebar space -->
    <div class="w-64 flex-shrink-0"></div>

    <!-- Main content -->
    <div class="flex-grow p-8 bg-gray-50 min-h-screen">
        <div class="max-w-7xl mx-auto">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Exam Results</h1>
                <div class="space-x-4">
                    <a href="view.php?id=<?php echo $exam_id; ?>" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Exam Details
                    </a>
                    <?php if ($exam['total_submissions'] > 0): ?>
                    <a href="export_results.php?id=<?php echo $exam_id; ?>" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-download mr-2"></i> Export Results
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Exam Information -->
            <div class="bg-white rounded-lg shadow overflow-hidden mb-6">
                <div class="p-6">
                    <dl class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Subject</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                <?php echo htmlspecialchars($exam['subject_name']); ?> 
                                (<?php echo htmlspecialchars($exam['subject_code']); ?>)
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Class</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                Grade <?php echo htmlspecialchars($exam['grade_level']); ?> - 
                                <?php echo htmlspecialchars($exam['class_name']); ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Exam Date & Time</dt>
                            <dd class="mt-1 text-lg text-gray-900">
                                <?php echo date('M j, Y', strtotime($exam['exam_date'])); ?> at
                                <?php echo date('g:i A', strtotime($exam['start_time'])); ?>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Maximum Marks</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo $exam['total_marks']; ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Passing Marks (40%)</dt>
                            <dd class="mt-1 text-lg text-gray-900"><?php echo round($exam['total_marks'] * 0.4); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Results Summary</dt>
                            <dd class="mt-1">
                                <div class="flex items-center space-x-2">
                                    <span class="text-lg text-gray-900"><?php echo $exam['total_submissions']; ?> Submitted</span>
                                    <span class="text-gray-500">|</span>
                                    <span class="text-lg text-green-600"><?php echo $exam['passed_count']; ?> Passed</span>
                                </div>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <?php if (isset($_GET['success'])): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-6" role="alert">
                <strong class="font-bold">Success!</strong>
                <span class="block sm:inline">Results have been saved successfully.</span>
            </div>
            <?php endif; ?>

            <!-- Results Form -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form method="POST" class="divide-y divide-gray-200">
                    <div class="p-6">
                        <?php
                        // Get students and their results
                        $query = "SELECT u.id, u.name, sp.student_id as roll_number, er.marks_obtained as marks, er.remarks,
                                er.created_at as updated_at
                                FROM users u
                                JOIN student_classes sc ON u.id = sc.student_id
                                LEFT JOIN student_profiles sp ON u.id = sp.user_id
                                LEFT JOIN exam_results er ON u.id = er.student_id AND er.exam_id = :exam_id
                                WHERE sc.class_id = :class_id AND u.role = 'student' AND sc.status = 'active'
                                ORDER BY sp.student_id, u.name";
                        $stmt = $db->prepare($query);
                        $stmt->bindParam(':exam_id', $exam_id);
                        $stmt->bindParam(':class_id', $exam['class_id']);
                        $stmt->execute();
                        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        ?>

                        <?php if (empty($students)): ?>
                        <p class="text-gray-500 text-center py-4">No students found in this class.</p>
                        <?php else: ?>
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Roll No</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Student Name</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Marks</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Remarks</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Last Updated</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <?php foreach ($students as $student): ?>
                                    <tr>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['roll_number']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($student['name']); ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="number" name="marks[<?php echo $student['id']; ?>]"
                                                value="<?php echo isset($student['marks']) ? $student['marks'] : ''; ?>"
                                                class="w-24 px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                                min="0" max="<?php echo $exam['total_marks']; ?>"
                                                <?php echo strtotime($exam['exam_date'] . ' ' . $exam['start_time']) > time() ? 'disabled' : ''; ?>>
                                        </td>
                                        <td class="px-6 py-4">
                                            <input type="text" name="remarks[<?php echo $student['id']; ?>]"
                                                value="<?php echo isset($student['remarks']) ? htmlspecialchars($student['remarks']) : ''; ?>"
                                                class="w-full px-3 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                                <?php echo strtotime($exam['exam_date'] . ' ' . $exam['start_time']) > time() ? 'disabled' : ''; ?>>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if (isset($student['marks'])): ?>
                                            <?php $passing_marks = round($exam['total_marks'] * 0.4); ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full
                                                <?php echo $student['marks'] >= $passing_marks ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'; ?>">
                                                <?php echo $student['marks'] >= $passing_marks ? 'Pass' : 'Fail'; ?>
                                            </span>
                                            <?php else: ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                                                Pending
                                            </span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if (isset($student['updated_at'])): ?>
                                            <?php echo date('M j, Y g:i A', strtotime($student['updated_at'])); ?>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($students) && strtotime($exam['exam_date'] . ' ' . $exam['start_time']) <= time()): ?>
                    <div class="px-6 py-4 bg-gray-50">
                        <div class="flex justify-end">
                            <button type="submit" name="submit_results" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg">
                                Save Results
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>