<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$assignment_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

if (!$assignment_id) {
    header("Location: index.php");
    exit();
}

// Get assignment details
$query = "SELECT a.*, s.name as subject_name, c.name as class_name, c.grade_level
          FROM assignments a
          JOIN subjects s ON a.subject_id = s.id
          JOIN classes c ON a.class_id = c.id
          WHERE a.id = :assignment_id";

// Add permission check for teachers
if ($user_role === 'teacher') {
    $query .= " AND a.teacher_id = :user_id";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':assignment_id', $assignment_id);
if ($user_role === 'teacher') {
    $stmt->bindParam(':user_id', $user_id);
}
$stmt->execute();
$assignment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$assignment) {
    header("Location: index.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = filter_input(INPUT_POST, 'title', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $subject_id = filter_input(INPUT_POST, 'subject_id', FILTER_SANITIZE_NUMBER_INT);
    $due_date = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
    $due_time = filter_input(INPUT_POST, 'due_time', FILTER_SANITIZE_STRING);
    
    $due_datetime = $due_date . ' ' . $due_time;
    
    if (empty($title) || empty($description) || empty($class_id) || empty($subject_id) || empty($due_date) || empty($due_time)) {
        $error = "All fields are required.";
    } elseif (strtotime($due_datetime) <= time()) {
        $error = "Due date must be in the future.";
    } else {
        try {
            $update_query = "UPDATE assignments SET 
                           title = :title, 
                           description = :description, 
                           class_id = :class_id, 
                           subject_id = :subject_id, 
                           due_date = :due_date 
                           WHERE id = :assignment_id";
            
            $update_stmt = $db->prepare($update_query);
            $update_stmt->bindParam(':title', $title);
            $update_stmt->bindParam(':description', $description);
            $update_stmt->bindParam(':class_id', $class_id);
            $update_stmt->bindParam(':subject_id', $subject_id);
            $update_stmt->bindParam(':due_date', $due_datetime);
            $update_stmt->bindParam(':assignment_id', $assignment_id);
            
            if ($update_stmt->execute()) {
                $success = "Assignment updated successfully.";
                // Refresh assignment data
                $stmt->execute();
                $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $error = "Failed to update assignment.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Get classes for dropdown
$classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subjects for dropdown
$subjects_query = "SELECT id, name FROM subjects ORDER BY name";
$subjects_stmt = $db->query($subjects_query);
$subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Parse current due date
$current_due_date = date('Y-m-d', strtotime($assignment['due_date']));
$current_due_time = date('H:i', strtotime($assignment['due_date']));
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
        <div class="w-full">
        <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Edit Assignment</h1>
                <div class="flex space-x-3">
                    <a href="view.php?id=<?php echo $assignment_id; ?>" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-eye mr-2"></i>View Assignment
                    </a>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Assignments
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-xl shadow-lg border border-gray-200">
                <div class="p-6 border-b border-gray-200">
                    <h2 class="text-xl font-semibold text-gray-900">Assignment Details</h2>
                    <p class="text-gray-600 text-sm mt-1">Update the assignment information below.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <!-- Title -->
                    <div>
                        <label for="title" class="block text-sm font-medium text-gray-700 mb-2">Assignment Title</label>
                        <input type="text" id="title" name="title" required
                            value="<?php echo htmlspecialchars($assignment['title']); ?>"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter assignment title">
                    </div>

                    <!-- Description -->
                    <div>
                        <label for="description" class="block text-sm font-medium text-gray-700 mb-2">Description</label>
                        <textarea id="description" name="description" rows="6" required
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Enter assignment description and instructions"><?php echo htmlspecialchars($assignment['description']); ?></textarea>
                    </div>

                    <!-- Class and Subject -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="class_id" class="block text-sm font-medium text-gray-700 mb-2">Class</label>
                            <select id="class_id" name="class_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select a class...</option>
                                <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" 
                                        <?php echo $class['id'] == $assignment['class_id'] ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - 
                                        <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="subject_id" class="block text-sm font-medium text-gray-700 mb-2">Subject</label>
                            <select id="subject_id" name="subject_id" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="">Select a subject...</option>
                                <?php foreach ($subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>"
                                        <?php echo $subject['id'] == $assignment['subject_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($subject['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Due Date and Time -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="due_date" class="block text-sm font-medium text-gray-700 mb-2">Due Date</label>
                            <input type="date" id="due_date" name="due_date" required
                                value="<?php echo $current_due_date; ?>"
                                min="<?php echo date('Y-m-d'); ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="due_time" class="block text-sm font-medium text-gray-700 mb-2">Due Time</label>
                            <input type="time" id="due_time" name="due_time" required
                                value="<?php echo $current_due_time; ?>"
                                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-6 border-t border-gray-200">
                        <div class="flex space-x-3">
                            <a href="view.php?id=<?php echo $assignment_id; ?>" 
                               class="px-6 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium">
                                Cancel
                            </a>
                            <button type="submit" 
                                class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-save mr-2"></i>Update Assignment
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Assignment Statistics -->
            <div class="bg-white rounded-xl shadow-lg border border-gray-200 mt-6">
                <div class="p-6 border-b border-gray-200">
                    <h3 class="text-lg font-semibold text-gray-900">Assignment Statistics</h3>
                </div>
                
                <div class="p-6">
                    <?php
                    // Get submission statistics
                    $stats_query = "SELECT 
                                   COUNT(*) as total_students,
                                   COUNT(sa.id) as submitted_count,
                                   AVG(sa.grade) as average_grade
                                   FROM student_classes sc
                                   LEFT JOIN student_assignments sa ON sc.student_id = sa.student_id AND sa.assignment_id = :assignment_id
                                   WHERE sc.class_id = :class_id";
                    $stats_stmt = $db->prepare($stats_query);
                    $stats_stmt->bindParam(':assignment_id', $assignment_id);
                    $stats_stmt->bindParam(':class_id', $assignment['class_id']);
                    $stats_stmt->execute();
                    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $submission_rate = $stats['total_students'] > 0 ? round(($stats['submitted_count'] / $stats['total_students']) * 100, 1) : 0;
                    ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-blue-600"><?php echo $stats['total_students']; ?></div>
                            <div class="text-sm text-gray-600">Total Students</div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-green-600"><?php echo $stats['submitted_count']; ?></div>
                            <div class="text-sm text-gray-600">Submissions (<?php echo $submission_rate; ?>%)</div>
                        </div>
                        
                        <div class="text-center">
                            <div class="text-2xl font-bold text-purple-600">
                                <?php echo $stats['average_grade'] ? number_format($stats['average_grade'], 1) : 'N/A'; ?>
                            </div>
                            <div class="text-sm text-gray-600">Average Grade</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
