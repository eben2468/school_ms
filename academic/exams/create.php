<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
require_once 'exam_access.php';
$database = new Database();
$db = $database->getConnection();

// Teachers may only schedule exams for classes/subjects they actually teach.
$is_teacher = $_SESSION['role'] === 'teacher';
$teacher_id = $_SESSION['user_id'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $class_id = $_POST['class_id'];
    $subject_id = $_POST['subject_id'];
    $exam_type = $_POST['exam_type'];
    $academic_term = $_POST['academic_term'];
    $exam_date = $_POST['exam_date'];
    $start_time = $_POST['start_time'];
    $duration = $_POST['duration'];
    $max_marks = $_POST['max_marks'];
    $passing_marks = isset($_POST['passing_marks']) && $_POST['passing_marks'] !== '' ? intval($_POST['passing_marks']) : null;
    
    // Validate input
    $errors = [];
    if (empty($class_id)) $errors[] = "Class is required.";
    if (empty($subject_id)) $errors[] = "Subject is required.";
    if (empty($exam_type)) $errors[] = "Exam type is required.";
    if (empty($exam_date)) $errors[] = "Exam date is required.";
    if (empty($start_time)) $errors[] = "Start time is required.";
    if (empty($duration)) $errors[] = "Duration is required.";
    if (empty($max_marks)) $errors[] = "Maximum marks is required.";
    if ($passing_marks !== null) {
        if ($passing_marks < 0) {
            $errors[] = "Passing marks cannot be negative.";
        }
        if ($passing_marks > $max_marks) {
            $errors[] = "Passing marks cannot be greater than maximum marks.";
        }
    }

    // Enforce that teachers can only schedule for classes/subjects they teach
    if ($is_teacher && !empty($class_id) && !empty($subject_id)
        && !teacherTeachesClassSubject($db, $teacher_id, $class_id, $subject_id)) {
        $errors[] = "You can only schedule exams for classes and subjects you teach.";
    }

    if (empty($errors)) {
        // Calculate end time based on start time and duration
        $start_datetime = new DateTime($exam_date . ' ' . $start_time);
        $end_datetime = clone $start_datetime;
        $end_datetime->add(new DateInterval('PT' . $duration . 'M'));
        $end_time = $end_datetime->format('H:i:s');

        // Generate exam name and title
        $exam_name = $exam_type . ' - ' . date('Y-m-d', strtotime($exam_date));
        $exam_title = $exam_type . ' Examination';

        // Get current academic context
        $academic_context = $database->getCurrentAcademicContext();
        $academic_year = $academic_context['year_name'];

        $query = "INSERT INTO exams (name, title, class_id, subject_id, exam_type, academic_term, date, exam_date,
                                   start_time, end_time, duration, total_marks, passing_marks, academic_year)
                 VALUES (:name, :title, :class_id, :subject_id, :exam_type, :academic_term, :date, :exam_date,
                        :start_time, :end_time, :duration, :total_marks, :passing_marks, :academic_year)";

        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $exam_name);
        $stmt->bindParam(':title', $exam_title);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->bindParam(':subject_id', $subject_id);
        $stmt->bindParam(':exam_type', $exam_type);
        $stmt->bindParam(':academic_term', $academic_term);
        $stmt->bindParam(':date', $exam_date);
        $stmt->bindParam(':exam_date', $exam_date);
        $stmt->bindParam(':start_time', $start_time);
        $stmt->bindParam(':end_time', $end_time);
        $stmt->bindParam(':duration', $duration);
        $stmt->bindParam(':total_marks', $max_marks);
        $stmt->bindParam(':passing_marks', $passing_marks, PDO::PARAM_INT);
        $stmt->bindParam(':academic_year', $academic_year);
        
        if ($stmt->execute()) {
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "Error creating exam.";
        }
    }
}

$title = "Schedule New Exam";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
            <div class="flex items-center justify-between mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Schedule New Exam</h1>
                <a href="index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i> Back to Exams
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
                <form method="POST" class="p-6 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Class Selection -->
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-1">Class*</label>
                            <select id="class_id" name="class_id" required onchange="loadSubjects(this.value)"
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Class</option>
                                <?php
                                if ($is_teacher) {
                                    // Only classes this teacher teaches
                                    $query = "SELECT DISTINCT c.id, c.grade_level, c.name
                                              FROM classes c
                                              JOIN class_teachers ct ON ct.class_id = c.id
                                              WHERE c.status = 'active' AND ct.teacher_id = :tid
                                              ORDER BY c.grade_level, c.name";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute([':tid' => $teacher_id]);
                                } else {
                                    $query = "SELECT id, grade_level, name FROM classes WHERE status = 'active' ORDER BY grade_level, name";
                                    $stmt = $db->query($query);
                                }
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)):
                                ?>
                                <option value="<?php echo $row['id']; ?>" <?php echo isset($_POST['class_id']) && $_POST['class_id'] == $row['id'] ? 'selected' : ''; ?>>
                                    Grade <?php echo htmlspecialchars($row['grade_level']); ?> - <?php echo htmlspecialchars($row['name']); ?>
                                </option>
                                <?php endwhile; ?>
                            </select>
                        </div>

                        <!-- Subject Selection -->
                        <div>
                            <label for="subject_id" class="block text-sm font-medium text-gray-700 mb-1">Subject*</label>
                            <select id="subject_id" name="subject_id" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Subject</option>
                            </select>
                        </div>

                        <!-- Exam Type -->
                        <div>
                            <label for="exam_type" class="block text-sm font-medium text-gray-700 mb-1">Exam Type*</label>
                            <select id="exam_type" name="exam_type" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Type</option>
                                <option value="midterm">Midterm</option>
                                <option value="final">Final</option>
                                <option value="quiz">Quiz</option>
                                <option value="assignment">Assignment</option>
                                <option value="project">Project</option>
                            </select>
                        </div>

                        <!-- Academic Term -->
                        <div>
                            <label for="academic_term" class="block text-sm font-medium text-gray-700 mb-1">Academic Term*</label>
                            <select id="academic_term" name="academic_term" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500">
                                <option value="">Select Term</option>
                                <option value="first">First Term</option>
                                <option value="second">Second Term</option>
                                <option value="third">Third Term</option>
                            </select>
                        </div>

                        <!-- Exam Date -->
                        <div>
                            <label for="exam_date" class="block text-sm font-medium text-gray-700 mb-1">Exam Date*</label>
                            <input type="date" id="exam_date" name="exam_date" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                min="<?php echo date('Y-m-d'); ?>"
                                value="<?php echo isset($_POST['exam_date']) ? $_POST['exam_date'] : ''; ?>">
                        </div>

                        <!-- Start Time -->
                        <div>
                            <label for="start_time" class="block text-sm font-medium text-gray-700 mb-1">Start Time*</label>
                            <input type="time" id="start_time" name="start_time" required
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['start_time']) ? $_POST['start_time'] : ''; ?>">
                        </div>

                        <!-- Duration -->
                        <div>
                            <label for="duration" class="block text-sm font-medium text-gray-700 mb-1">Duration (minutes)*</label>
                            <input type="number" id="duration" name="duration" required min="15" max="180"
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['duration']) ? $_POST['duration'] : '60'; ?>">
                        </div>

                        <!-- Maximum Marks -->
                        <div>
                            <label for="max_marks" class="block text-sm font-medium text-gray-700 mb-1">Maximum Marks*</label>
                            <input type="number" id="max_marks" name="max_marks" required min="1" max="100"
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['max_marks']) ? $_POST['max_marks'] : '100'; ?>">
                        </div>

                        <!-- Passing Marks -->
                        <div>
                            <label for="passing_marks" class="block text-sm font-medium text-gray-700 mb-1">Passing Marks (Optional)</label>
                            <input type="number" id="passing_marks" name="passing_marks" min="0" max="100"
                                class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-blue-500"
                                value="<?php echo isset($_POST['passing_marks']) ? $_POST['passing_marks'] : ''; ?>"
                                placeholder="Leave empty for no pass/fail threshold">
                        </div>


                    </div>

                    <div class="flex justify-end space-x-4 mt-6">
                        <button type="button" onclick="window.location.href='index.php'" 
                            class="px-6 py-2 border rounded-lg hover:bg-gray-100">
                            Cancel
                        </button>
                        <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            Schedule Exam
                        </button>
                    </div>
                </form>
            </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function loadSubjects(classId) {
    if (!classId) {
        document.getElementById('subject_id').innerHTML = '<option value="">Select Subject</option>';
        return;
    }

    fetch('get_subjects.php?class_id=' + classId)
        .then(response => response.json())
        .then(data => {
            let options = '<option value="">Select Subject</option>';
            data.forEach(subject => {
                options += `<option value="${subject.id}">${subject.name}</option>`;
            });
            document.getElementById('subject_id').innerHTML = options;
        })
        .catch(error => console.error('Error:', error));
}

// Load subjects if class is pre-selected
<?php if (isset($_POST['class_id']) && !empty($_POST['class_id'])): ?>
loadSubjects(<?php echo $_POST['class_id']; ?>);
<?php endif; ?>
</script>