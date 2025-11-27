<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->beginTransaction();
        
        if ($action === 'assign_students') {
            // Assign students to classes (one class per student)
            $class_id = (int)$_POST['class_id'];
            $student_ids = $_POST['student_ids'] ?? [];
            $transfer_mode = isset($_POST['transfer_students']);

            $assigned_count = 0;
            $transferred_count = 0;
            $already_assigned = [];

            foreach ($student_ids as $student_id) {
                // Check if student is already assigned to any class
                $check_query = "SELECT c.name as class_name, sc.class_id
                               FROM student_classes sc
                               JOIN classes c ON sc.class_id = c.id
                               WHERE sc.student_id = :student_id AND sc.status = 'active'";
                $check_stmt = $db->prepare($check_query);
                $check_stmt->bindParam(':student_id', $student_id);
                $check_stmt->execute();
                $existing_assignment = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if ($existing_assignment) {
                    if ($transfer_mode) {
                        // Transfer student: deactivate old assignment and create new one
                        $deactivate_query = "UPDATE student_classes SET status = 'inactive' WHERE student_id = :student_id AND status = 'active'";
                        $deactivate_stmt = $db->prepare($deactivate_query);
                        $deactivate_stmt->bindParam(':student_id', $student_id);
                        $deactivate_stmt->execute();

                        // Create new assignment
                        $assign_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
                        $assign_stmt = $db->prepare($assign_query);
                        $assign_stmt->bindParam(':student_id', $student_id);
                        $assign_stmt->bindParam(':class_id', $class_id);
                        $assign_stmt->execute();

                        $transferred_count++;
                    } else {
                        // Student already assigned, skip
                        $student_query = "SELECT name FROM users WHERE id = :student_id";
                        $student_stmt = $db->prepare($student_query);
                        $student_stmt->bindParam(':student_id', $student_id);
                        $student_stmt->execute();
                        $student_name = $student_stmt->fetch(PDO::FETCH_ASSOC)['name'];

                        $already_assigned[] = $student_name . ' (currently in ' . $existing_assignment['class_name'] . ')';
                    }
                } else {
                    // Student not assigned to any class, assign normally
                    $assign_query = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
                    $assign_stmt = $db->prepare($assign_query);
                    $assign_stmt->bindParam(':student_id', $student_id);
                    $assign_stmt->bindParam(':class_id', $class_id);
                    $assign_stmt->execute();

                    $assigned_count++;
                }
            }

            // Build success message
            $messages = [];
            if ($assigned_count > 0) {
                $messages[] = "$assigned_count student(s) assigned successfully";
            }
            if ($transferred_count > 0) {
                $messages[] = "$transferred_count student(s) transferred successfully";
            }
            if (!empty($already_assigned)) {
                $messages[] = "Skipped " . count($already_assigned) . " student(s) already assigned to other classes";
            }

            $success = implode('. ', $messages) . '.';

            // Store already assigned students for display
            if (!empty($already_assigned)) {
                $_SESSION['already_assigned_students'] = $already_assigned;
            }
            
        } elseif ($action === 'assign_main_teacher') {
            // Assign main teacher to class
            $class_id = (int)$_POST['class_id'];
            $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : null;
            
            $query = "UPDATE classes SET main_teacher_id = :teacher_id WHERE id = :class_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->execute();
            
            $success = "Main class teacher assigned successfully!";
            
        } elseif ($action === 'assign_subject_teachers') {
            // Assign teachers to subjects for a class
            $class_id = (int)$_POST['class_id'];
            $teacher_subjects = $_POST['teacher_subjects'] ?? [];
            
            // Remove existing assignments for this class
            $query = "DELETE FROM class_teachers WHERE class_id = :class_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->execute();
            
            // Add new assignments
            foreach ($teacher_subjects as $assignment) {
                if (!empty($assignment)) {
                    list($teacher_id, $subject_id) = explode('_', $assignment);
                    $query = "INSERT INTO class_teachers (class_id, teacher_id, subject_id) 
                             VALUES (:class_id, :teacher_id, :subject_id)";
                    $stmt = $db->prepare($query);
                    $stmt->bindParam(':class_id', $class_id);
                    $stmt->bindParam(':teacher_id', $teacher_id);
                    $stmt->bindParam(':subject_id', $subject_id);
                    $stmt->execute();
                }
            }
            
            $success = "Subject teachers assigned successfully!";

        } elseif ($action === 'remove_student') {
            // Remove student from class
            $student_id = (int)$_POST['student_id'];
            $class_id = (int)$_POST['class_id'];

            // Deactivate the assignment
            $query = "UPDATE student_classes SET status = 'inactive' WHERE student_id = :student_id AND class_id = :class_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->execute();

            // Get student name for success message
            $student_query = "SELECT name FROM users WHERE id = :student_id";
            $student_stmt = $db->prepare($student_query);
            $student_stmt->bindParam(':student_id', $student_id);
            $student_stmt->execute();
            $student_name = $student_stmt->fetch(PDO::FETCH_ASSOC)['name'];

            $success = "Student '{$student_name}' removed from class successfully!";
        }

        $db->commit();
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error processing assignment: " . $e->getMessage();
    }
}

// Fetch data for the page
$query = "SELECT id, name, grade_level, academic_year, main_teacher_id FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$stmt = $db->query($query);
$classes = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name";
$stmt = $db->query($query);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT u.id, u.name, sp.student_id as roll_number,
                 sc.class_id as current_class_id, c.name as current_class_name
          FROM users u
          LEFT JOIN student_profiles sp ON u.id = sp.user_id
          LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
          LEFT JOIN classes c ON sc.class_id = c.id
          WHERE u.role = 'student' AND u.status = 'active'
          ORDER BY u.name";
$stmt = $db->query($query);
$students = $stmt->fetchAll(PDO::FETCH_ASSOC);

$query = "SELECT id, name, code FROM subjects ORDER BY name";
$stmt = $db->query($query);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current assignments for display
$current_assignments = [];
foreach ($classes as $class) {
    // Get students in this class
    $query = "SELECT u.id, u.name, sp.student_id as roll_number 
              FROM users u 
              JOIN student_classes sc ON u.id = sc.student_id 
              LEFT JOIN student_profiles sp ON u.id = sp.user_id
              WHERE sc.class_id = :class_id AND sc.status = 'active'
              ORDER BY u.name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $class['id']);
    $stmt->execute();
    $current_assignments[$class['id']]['students'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get subject teachers for this class
    $query = "SELECT ct.*, s.name as subject_name, u.name as teacher_name 
              FROM class_teachers ct
              JOIN subjects s ON ct.subject_id = s.id
              JOIN users u ON ct.teacher_id = u.id
              WHERE ct.class_id = :class_id
              ORDER BY s.name";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':class_id', $class['id']);
    $stmt->execute();
    $current_assignments[$class['id']]['subject_teachers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get main teacher name
    if ($class['main_teacher_id']) {
        $query = "SELECT name FROM users WHERE id = :teacher_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':teacher_id', $class['main_teacher_id']);
        $stmt->execute();
        $main_teacher = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_assignments[$class['id']]['main_teacher'] = $main_teacher['name'] ?? 'Unknown';
    } else {
        $current_assignments[$class['id']]['main_teacher'] = 'Not Assigned';
    }
}
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden transition-all duration-300" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-800">Class Management</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Academic Management
                    </a>
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

                <!-- Assignment Tabs -->
                <div class="bg-white rounded-lg shadow overflow-hidden">
                    <div class="border-b border-gray-200">
                        <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
                            <button onclick="showTab('students')" id="students-tab" 
                                class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                                Assign Students to Classes
                            </button>
                            <button onclick="showTab('main-teachers')" id="main-teachers-tab"
                                class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                                Assign Main Class Teachers
                            </button>
                            <button onclick="showTab('subject-teachers')" id="subject-teachers-tab"
                                class="tab-button border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                                Assign Subject Teachers
                            </button>
                            <button onclick="showTab('current')" id="current-tab"
                                class="tab-button border-blue-500 text-blue-600 whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm">
                                Current Assignments
                            </button>
                        </nav>
                    </div>

                    <!-- Tab Content -->
                    <div class="p-6">
                        <!-- Assign Students to Classes Tab -->
                        <div id="students-content" class="tab-content hidden">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Assign Students to Classes</h3>

                            <?php if (isset($_SESSION['already_assigned_students'])): ?>
                            <div class="bg-yellow-50 border border-yellow-200 rounded-md p-4 mb-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-yellow-800">Students Already Assigned</h3>
                                        <div class="mt-2 text-sm text-yellow-700">
                                            <p>The following students were skipped because they are already assigned to other classes:</p>
                                            <ul class="list-disc list-inside mt-1">
                                                <?php foreach ($_SESSION['already_assigned_students'] as $student): ?>
                                                <li><?php echo htmlspecialchars($student); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <p class="mt-2">Use the "Transfer Students" option if you want to move them to a different class.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php unset($_SESSION['already_assigned_students']); ?>
                            <?php endif; ?>

                            <form method="POST" class="space-y-4" id="studentAssignmentForm">
                                <input type="hidden" name="action" value="assign_students">

                                <div>
                                    <label for="student_class_id" class="block text-sm font-medium text-gray-700">Select Class</label>
                                    <select id="student_class_id" name="class_id" required onchange="updateStudentList()"
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Choose a class</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <div class="flex justify-between items-center mb-2">
                                        <label class="block text-sm font-medium text-gray-700">Select Students</label>
                                        <div class="flex space-x-2">
                                            <button type="button" onclick="selectAllStudents()" class="text-xs text-blue-600 hover:text-blue-800">Select All Available</button>
                                            <button type="button" onclick="deselectAllStudents()" class="text-xs text-gray-600 hover:text-gray-800">Deselect All</button>
                                        </div>
                                    </div>
                                    <div id="studentsList" class="max-h-64 overflow-y-auto border border-gray-300 rounded-md p-3 bg-gray-50">
                                        <div class="text-center text-gray-500 py-4">
                                            <i class="fas fa-arrow-up mr-2"></i>Please select a class first
                                        </div>
                                    </div>
                                </div>

                                <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
                                    <div class="flex items-start">
                                        <div class="flex-shrink-0">
                                            <i class="fas fa-info-circle text-blue-400"></i>
                                        </div>
                                        <div class="ml-3">
                                            <h3 class="text-sm font-medium text-blue-800">Assignment Rules</h3>
                                            <div class="mt-2 text-sm text-blue-700">
                                                <ul class="list-disc list-inside space-y-1">
                                                    <li>Each student can only be assigned to <strong>one class</strong> at a time</li>
                                                    <li>Students already assigned to other classes will be <strong>skipped</strong></li>
                                                    <li>Use "Transfer Students" to move students between classes</li>
                                                </ul>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <div class="flex items-center">
                                        <input type="checkbox" name="transfer_students" id="transfer_students"
                                            class="h-4 w-4 text-orange-600 focus:ring-orange-500 border-gray-300 rounded">
                                        <label for="transfer_students" class="ml-2 text-sm text-gray-700">
                                            <span class="font-medium text-orange-600">Transfer Students</span> - Move students from their current class to the selected class
                                        </label>
                                    </div>
                                    <p class="text-xs text-gray-500 ml-6">⚠️ This will remove students from their current classes and assign them to the selected class.</p>
                                </div>

                                <button type="submit" id="assignButton"
                                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed">
                                    <i class="fas fa-user-plus mr-2"></i>Assign Students to Class
                                </button>
                            </form>
                        </div>

                        <!-- Assign Main Class Teachers Tab -->
                        <div id="main-teachers-content" class="tab-content hidden">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Assign Main Class Teachers</h3>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="assign_main_teacher">

                                <div>
                                    <label for="main_teacher_class_id" class="block text-sm font-medium text-gray-700">Select Class</label>
                                    <select id="main_teacher_class_id" name="class_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Choose a class</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="main_teacher_id" class="block text-sm font-medium text-gray-700">Select Main Teacher</label>
                                    <select id="main_teacher_id" name="teacher_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Choose a teacher</option>
                                        <?php foreach ($teachers as $teacher): ?>
                                        <option value="<?php echo $teacher['id']; ?>">
                                            <?php echo htmlspecialchars($teacher['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <button type="submit"
                                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Assign Main Teacher
                                </button>
                            </form>
                        </div>

                        <!-- Assign Subject Teachers Tab -->
                        <div id="subject-teachers-content" class="tab-content hidden">
                            <h3 class="text-lg font-medium text-gray-900 mb-4">Assign Subject Teachers</h3>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="assign_subject_teachers">

                                <div>
                                    <label for="subject_teacher_class_id" class="block text-sm font-medium text-gray-700">Select Class</label>
                                    <select id="subject_teacher_class_id" name="class_id" required
                                        class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                        <option value="">Choose a class</option>
                                        <?php foreach ($classes as $class): ?>
                                        <option value="<?php echo $class['id']; ?>">
                                            <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Assign Teachers to Subjects</label>
                                    <div class="space-y-4 max-h-96 overflow-y-auto p-4 border border-gray-200 rounded-md">
                                        <?php foreach ($subjects as $subject): ?>
                                        <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                                            <h4 class="font-medium text-gray-900 mb-2">
                                                <?php echo htmlspecialchars($subject['name']); ?>
                                                (<?php echo htmlspecialchars($subject['code']); ?>)
                                            </h4>
                                            <select name="teacher_subjects[]"
                                                class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                                                <option value="">Select Teacher</option>
                                                <?php foreach ($teachers as $teacher): ?>
                                                <option value="<?php echo $teacher['id'] . '_' . $subject['id']; ?>">
                                                    <?php echo htmlspecialchars($teacher['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="mt-2 text-sm text-gray-500">Select teachers for each subject. Leave empty if not applicable.</p>
                                </div>

                                <button type="submit"
                                    class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                    Assign Subject Teachers
                                </button>
                            </form>
                        </div>

                        <!-- Current Assignments Tab -->
                        <div id="current-content" class="tab-content">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-2xl font-bold text-gray-900 dark:text-white">Current Assignments</h3>
                                <div class="flex items-center space-x-4 text-sm text-gray-500">
                                    <span><i class="fas fa-chalkboard mr-1"></i><?php echo count($classes); ?> classes</span>
                                    <span><i class="fas fa-users mr-1"></i><?php echo count($students); ?> students</span>
                                    <?php
                                    $unassigned_count = count(array_filter($students, function($s) { return empty($s['current_class_id']); }));
                                    if ($unassigned_count > 0):
                                    ?>
                                    <span class="text-orange-600"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo $unassigned_count; ?> unassigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($unassigned_count > 0): ?>
                            <div class="bg-orange-50 border border-orange-200 rounded-lg p-4 mb-6">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0">
                                        <i class="fas fa-exclamation-triangle text-orange-400"></i>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-sm font-medium text-orange-800">Unassigned Students</h3>
                                        <div class="mt-2 text-sm text-orange-700">
                                            <p class="mb-2">The following students are not assigned to any class:</p>
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2">
                                                <?php foreach ($students as $student): ?>
                                                    <?php if (empty($student['current_class_id'])): ?>
                                                    <div class="bg-white rounded px-3 py-2 border border-orange-200">
                                                        <span class="font-medium"><?php echo htmlspecialchars($student['name']); ?></span>
                                                        <?php if ($student['roll_number']): ?>
                                                            <span class="text-gray-500 text-xs">(ID: <?php echo htmlspecialchars($student['roll_number']); ?>)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="mt-3 text-xs">
                                                <i class="fas fa-lightbulb mr-1"></i>
                                                Use the "Assign Students to Classes" tab to assign these students to classes.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (empty($classes)): ?>
                            <div class="text-center py-12">
                                <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center">
                                    <i class="fas fa-chalkboard-teacher text-gray-400 text-3xl"></i>
                                </div>
                                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Classes Found</h3>
                                <p class="text-gray-500 dark:text-gray-400 mb-4">Create some classes first to manage assignments.</p>
                                <a href="classes/create.php" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700">
                                    <i class="fas fa-plus mr-2"></i>Create Class
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="space-y-6">
                                <?php foreach ($classes as $class): ?>
                                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300">
                                    <!-- Class Header -->
                                    <div class="bg-gradient-to-r from-blue-600 to-purple-600 p-4">
                                        <div class="flex justify-between items-start">
                                            <div class="text-white">
                                                <h4 class="text-2xl font-bold mb-1">
                                                    <?php echo htmlspecialchars($class['name']); ?>
                                                </h4>
                                                <div class="flex items-center space-x-4 text-blue-100">
                                                    <span class="flex items-center">
                                                        <i class="fas fa-graduation-cap mr-2"></i>
                                                        <?php echo htmlspecialchars($class['grade_level']); ?>
                                                    </span>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-calendar mr-2"></i>
                                                        <?php echo htmlspecialchars($class['academic_year']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-right text-white">
                                                <div class="text-3xl font-bold">
                                                    <?php echo count($current_assignments[$class['id']]['students']); ?>
                                                </div>
                                                <div class="text-blue-200 text-sm">Total Students</div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Class Content -->
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                            <!-- Main Teacher Card -->
                                            <div class="bg-gradient-to-br from-emerald-50 to-teal-50 dark:from-emerald-900/20 dark:to-teal-900/20 rounded-xl p-5 border border-emerald-200 dark:border-emerald-700">
                                                <div class="flex items-center mb-3">
                                                    <div class="w-12 h-12 bg-emerald-100 dark:bg-emerald-800 rounded-full flex items-center justify-center mr-4">
                                                        <i class="fas fa-user-tie text-emerald-600 dark:text-emerald-400 text-xl"></i>
                                                    </div>
                                                    <div>
                                                        <h5 class="font-semibold text-gray-900 dark:text-white">Main Class Teacher</h5>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400">Primary Instructor</p>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-700 rounded-lg p-3">
                                                    <?php if ($current_assignments[$class['id']]['main_teacher'] !== 'Not Assigned'): ?>
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 bg-emerald-100 dark:bg-emerald-800 rounded-full flex items-center justify-center mr-3">
                                                            <i class="fas fa-check text-emerald-600 dark:text-emerald-400 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <p class="font-medium text-gray-900 dark:text-white">
                                                                <?php echo htmlspecialchars($current_assignments[$class['id']]['main_teacher']); ?>
                                                            </p>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400">Assigned</p>
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 bg-gray-100 dark:bg-gray-600 rounded-full flex items-center justify-center mr-3">
                                                            <i class="fas fa-exclamation text-gray-400 text-sm"></i>
                                                        </div>
                                                        <div>
                                                            <p class="font-medium text-gray-500 dark:text-gray-400">Not Assigned</p>
                                                            <p class="text-xs text-gray-400">Needs Assignment</p>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Students Card -->
                                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl p-5 border border-blue-200 dark:border-blue-700">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center">
                                                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-800 rounded-full flex items-center justify-center mr-4">
                                                            <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                                                        </div>
                                                        <div>
                                                            <h5 class="font-semibold text-gray-900 dark:text-white">Students</h5>
                                                            <p class="text-sm text-gray-500 dark:text-gray-400">Enrolled Learners</p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                                            <?php echo count($current_assignments[$class['id']]['students']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-700 rounded-lg p-3 max-h-32 overflow-y-auto">
                                                    <?php if (!empty($current_assignments[$class['id']]['students'])): ?>
                                                        <?php foreach (array_slice($current_assignments[$class['id']]['students'], 0, 5) as $student): ?>
                                                        <div class="flex items-center justify-between py-1 group">
                                                            <div class="flex items-center">
                                                                <div class="w-6 h-6 bg-blue-100 dark:bg-blue-800 rounded-full flex items-center justify-center mr-2">
                                                                    <i class="fas fa-user text-blue-600 dark:text-blue-400 text-xs"></i>
                                                                </div>
                                                                <span class="text-sm text-gray-700 dark:text-gray-300">
                                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                                    <?php if ($student['roll_number']): ?>
                                                                        <span class="text-gray-500 dark:text-gray-400">(<?php echo htmlspecialchars($student['roll_number']); ?>)</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                            <button onclick="removeStudent(<?php echo $student['id']; ?>, <?php echo $class['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')"
                                                                class="opacity-0 group-hover:opacity-100 text-red-500 hover:text-red-700 text-xs p-1 rounded transition-all duration-200"
                                                                title="Remove from class">
                                                                <i class="fas fa-times"></i>
                                                            </button>
                                                        </div>
                                                        <?php endforeach; ?>
                                                        <?php if (count($current_assignments[$class['id']]['students']) > 5): ?>
                                                        <div class="text-xs text-gray-500 dark:text-gray-400 mt-2 text-center">
                                                            +<?php echo count($current_assignments[$class['id']]['students']) - 5; ?> more students
                                                        </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                    <div class="text-center py-2">
                                                        <i class="fas fa-user-slash text-gray-400 text-lg mb-2"></i>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400">No students assigned</p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Subject Teachers Card -->
                                            <div class="bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-900/20 dark:to-pink-900/20 rounded-xl p-5 border border-purple-200 dark:border-purple-700">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center">
                                                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-800 rounded-full flex items-center justify-center mr-4">
                                                            <i class="fas fa-chalkboard-teacher text-purple-600 dark:text-purple-400 text-xl"></i>
                                                        </div>
                                                        <div>
                                                            <h5 class="font-semibold text-gray-900 dark:text-white">Subject Teachers</h5>
                                                            <p class="text-sm text-gray-500 dark:text-gray-400">Subject Specialists</p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                                                            <?php echo count($current_assignments[$class['id']]['subject_teachers']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-700 rounded-lg p-3 max-h-32 overflow-y-auto">
                                                    <?php if (!empty($current_assignments[$class['id']]['subject_teachers'])): ?>
                                                        <?php foreach ($current_assignments[$class['id']]['subject_teachers'] as $assignment): ?>
                                                        <div class="flex items-center justify-between py-1 border-b border-gray-100 dark:border-gray-600 last:border-0">
                                                            <div class="flex items-center">
                                                                <div class="w-6 h-6 bg-purple-100 dark:bg-purple-800 rounded-full flex items-center justify-center mr-2">
                                                                    <i class="fas fa-book text-purple-600 dark:text-purple-400 text-xs"></i>
                                                                </div>
                                                                <span class="text-sm font-medium text-gray-900 dark:text-white">
                                                                    <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                                                </span>
                                                            </div>
                                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                                <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                                                            </span>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                    <div class="text-center py-2">
                                                        <i class="fas fa-chalkboard text-gray-400 text-lg mb-2"></i>
                                                        <p class="text-sm text-gray-500 dark:text-gray-400">No subject teachers assigned</p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Quick Actions -->
                                        <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-600">
                                            <div class="flex justify-between items-center">
                                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                                    <i class="fas fa-clock mr-1"></i>
                                                    Last updated: <?php echo date('M j, Y'); ?>
                                                </div>
                                                <div class="flex space-x-2">
                                                    <button onclick="editClass(<?php echo $class['id']; ?>)"
                                                        class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-blue-700 bg-blue-100 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200">
                                                        <i class="fas fa-edit mr-1"></i>
                                                        Edit Assignments
                                                    </button>
                                                    <a href="classes/view.php?id=<?php echo $class['id']; ?>"
                                                        class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-gray-700 bg-gray-100 hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-gray-500 transition-colors duration-200">
                                                        <i class="fas fa-eye mr-1"></i>
                                                        View Details
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Student assignment management functions
function updateStudentList() {
    const classId = document.getElementById('student_class_id').value;
    const studentsList = document.getElementById('studentsList');
    const assignButton = document.getElementById('assignButton');

    if (!classId) {
        studentsList.innerHTML = '<div class="text-center text-gray-500 py-4"><i class="fas fa-arrow-up mr-2"></i>Please select a class first</div>';
        assignButton.disabled = true;
        return;
    }

    // Show loading
    studentsList.innerHTML = '<div class="text-center py-4"><div class="inline-flex items-center"><i class="fas fa-spinner fa-spin mr-2 text-blue-600"></i><span class="text-gray-600">Loading students...</span></div></div>';
    assignButton.disabled = true;

    // Fetch students for the selected class
    fetch(`get_students_for_class.php?class_id=${classId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStudentsList(data);
                assignButton.disabled = false;
            } else {
                studentsList.innerHTML = '<div class="text-center py-4 text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Error loading students: ' + (data.error || 'Unknown error') + '</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            studentsList.innerHTML = '<div class="text-center py-4 text-red-600"><i class="fas fa-exclamation-triangle mr-2"></i>Error loading students</div>';
        });
}

function displayStudentsList(data) {
    const studentsList = document.getElementById('studentsList');
    let html = '';

    // Unassigned students (available for assignment)
    if (data.students.unassigned.length > 0) {
        html += '<div class="mb-4">';
        html += '<h4 class="text-sm font-semibold text-green-700 mb-2 flex items-center">';
        html += '<i class="fas fa-user-plus mr-2"></i>Available Students (' + data.students.unassigned.length + ')';
        html += '</h4>';

        data.students.unassigned.forEach(student => {
            html += `
                <div class="flex items-center mb-2 p-2 bg-green-50 rounded border border-green-200">
                    <input type="checkbox" name="student_ids[]" value="${student.id}"
                           id="student_${student.id}" class="student-checkbox available-student h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded">
                    <label for="student_${student.id}" class="ml-2 text-sm text-gray-700 flex-1">
                        <span class="font-medium">${student.name}</span>
                        ${student.roll_number ? `<span class="text-gray-500"> (ID: ${student.roll_number})</span>` : ''}
                        <span class="text-green-600 text-xs ml-2">• Available</span>
                    </label>
                </div>
            `;
        });
        html += '</div>';
    }

    // Students already in this class
    if (data.students.current_class.length > 0) {
        html += '<div class="mb-4">';
        html += '<h4 class="text-sm font-semibold text-blue-700 mb-2 flex items-center">';
        html += '<i class="fas fa-users mr-2"></i>Already in ' + data.class_name + ' (' + data.students.current_class.length + ')';
        html += '</h4>';

        data.students.current_class.forEach(student => {
            html += `
                <div class="flex items-center mb-2 p-2 bg-blue-50 rounded border border-blue-200">
                    <input type="checkbox" disabled class="h-4 w-4 text-blue-600 border-gray-300 rounded opacity-50">
                    <label class="ml-2 text-sm text-gray-600 flex-1">
                        <span class="font-medium">${student.name}</span>
                        ${student.roll_number ? `<span class="text-gray-500"> (ID: ${student.roll_number})</span>` : ''}
                        <span class="text-blue-600 text-xs ml-2">• Already assigned</span>
                    </label>
                </div>
            `;
        });
        html += '</div>';
    }

    // Students assigned to other classes
    if (data.students.other_class.length > 0) {
        html += '<div class="mb-4">';
        html += '<h4 class="text-sm font-semibold text-orange-700 mb-2 flex items-center">';
        html += '<i class="fas fa-exchange-alt mr-2"></i>Assigned to Other Classes (' + data.students.other_class.length + ')';
        html += '</h4>';

        data.students.other_class.forEach(student => {
            html += `
                <div class="flex items-center mb-2 p-2 bg-orange-50 rounded border border-orange-200">
                    <input type="checkbox" name="student_ids[]" value="${student.id}"
                           id="student_transfer_${student.id}" class="student-checkbox transfer-student h-4 w-4 text-orange-600 focus:ring-orange-500 border-gray-300 rounded" disabled>
                    <label for="student_transfer_${student.id}" class="ml-2 text-sm text-gray-700 flex-1">
                        <span class="font-medium">${student.name}</span>
                        ${student.roll_number ? `<span class="text-gray-500"> (ID: ${student.roll_number})</span>` : ''}
                        <span class="text-orange-600 text-xs ml-2">• Currently in ${student.current_class_name}</span>
                    </label>
                </div>
            `;
        });
        html += '<p class="text-xs text-orange-600 mt-2"><i class="fas fa-info-circle mr-1"></i>Enable "Transfer Students" option to move these students</p>';
        html += '</div>';
    }

    if (data.students.unassigned.length === 0 && data.students.other_class.length === 0) {
        html += '<div class="text-center py-4 text-gray-500">';
        html += '<i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>';
        html += '<p>All students are already assigned to classes</p>';
        html += '</div>';
    }

    studentsList.innerHTML = html;

    // Add event listener for transfer mode
    const transferCheckbox = document.getElementById('transfer_students');
    if (transferCheckbox) {
        transferCheckbox.addEventListener('change', function() {
            const transferStudents = document.querySelectorAll('.transfer-student');
            transferStudents.forEach(checkbox => {
                checkbox.disabled = !this.checked;
                if (!this.checked) {
                    checkbox.checked = false;
                }
            });

            // Update button text
            const assignButton = document.getElementById('assignButton');
            const buttonText = assignButton.querySelector('i').nextSibling;
            if (this.checked) {
                assignButton.className = assignButton.className.replace('bg-blue-600 hover:bg-blue-700', 'bg-orange-600 hover:bg-orange-700');
                buttonText.textContent = 'Transfer Students to Class';
            } else {
                assignButton.className = assignButton.className.replace('bg-orange-600 hover:bg-orange-700', 'bg-blue-600 hover:bg-blue-700');
                buttonText.textContent = 'Assign Students to Class';
            }
        });
    }
}

function selectAllStudents() {
    const availableStudents = document.querySelectorAll('.available-student:not(:disabled)');
    const transferMode = document.getElementById('transfer_students').checked;
    const transferStudents = document.querySelectorAll('.transfer-student:not(:disabled)');

    availableStudents.forEach(checkbox => checkbox.checked = true);

    if (transferMode) {
        transferStudents.forEach(checkbox => checkbox.checked = true);
    }
}

function deselectAllStudents() {
    const allStudentCheckboxes = document.querySelectorAll('.student-checkbox');
    allStudentCheckboxes.forEach(checkbox => checkbox.checked = false);
}

function removeStudent(studentId, classId, studentName) {
    if (confirm(`Are you sure you want to remove "${studentName}" from this class?\n\nThis will make the student unassigned and available for assignment to other classes.`)) {
        // Create a form and submit it
        const form = document.createElement('form');
        form.method = 'POST';
        form.style.display = 'none';

        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = 'remove_student';

        const studentIdInput = document.createElement('input');
        studentIdInput.type = 'hidden';
        studentIdInput.name = 'student_id';
        studentIdInput.value = studentId;

        const classIdInput = document.createElement('input');
        classIdInput.type = 'hidden';
        classIdInput.name = 'class_id';
        classIdInput.value = classId;

        form.appendChild(actionInput);
        form.appendChild(studentIdInput);
        form.appendChild(classIdInput);

        document.body.appendChild(form);
        form.submit();
    }
}

function showTab(tabName) {
    try {
        // Hide all tab contents
        const contents = document.querySelectorAll('.tab-content');
        contents.forEach(content => content.classList.add('hidden'));

        // Remove active class from all tabs
        const tabs = document.querySelectorAll('.tab-button');
        tabs.forEach(tab => {
            tab.classList.remove('border-blue-500', 'text-blue-600');
            tab.classList.add('border-transparent', 'text-gray-500');
        });

        // Show selected tab content
        const targetContent = document.getElementById(tabName + '-content');
        if (targetContent) {
            targetContent.classList.remove('hidden');
        } else {
            console.error('Tab content not found:', tabName + '-content');
            return;
        }

        // Add active class to selected tab
        const activeTab = document.getElementById(tabName + '-tab');
        if (activeTab) {
            activeTab.classList.remove('border-transparent', 'text-gray-500');
            activeTab.classList.add('border-blue-500', 'text-blue-600');
        } else {
            console.error('Tab button not found:', tabName + '-tab');
        }

        // Add visual feedback
        console.log('Switched to tab:', tabName);

        // Scroll to top of content area for better UX
        const contentArea = document.querySelector('.tab-content:not(.hidden)');
        if (contentArea) {
            contentArea.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    } catch (error) {
        console.error('Error switching tabs:', error);
    }
}

// Initialize with current assignments tab active
document.addEventListener('DOMContentLoaded', function() {
    showTab('current');
});

// Edit class function
function editClass(classId) {
    console.log('Opening edit modal for class ID:', classId);

    // Create modal
    const modal = document.createElement('div');
    modal.className = 'fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50';
    modal.setAttribute('role', 'dialog');
    modal.setAttribute('aria-labelledby', 'modal-title');
    modal.setAttribute('aria-modal', 'true');

    modal.innerHTML = `
        <div class="relative top-10 mx-auto p-6 border w-full max-w-lg shadow-xl rounded-lg bg-white dark:bg-gray-800">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-6">
                    <h3 id="modal-title" class="text-xl font-semibold text-gray-900 dark:text-white">Edit Class Assignments</h3>
                    <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500 rounded" aria-label="Close modal">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
                <div class="space-y-3">
                    <button onclick="assignStudents(${classId})"
                        class="modal-action-btn w-full text-left px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 transition-all duration-200 flex items-center focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-user-graduate mr-3 text-blue-500"></i>
                        Assign Students to Class
                    </button>
                    <button onclick="assignMainTeacher(${classId})"
                        class="modal-action-btn w-full text-left px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 transition-all duration-200 flex items-center focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-chalkboard-teacher mr-3 text-blue-500"></i>
                        Assign Main Class Teacher
                    </button>
                    <button onclick="assignSubjectTeachers(${classId})"
                        class="modal-action-btn w-full text-left px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 transition-all duration-200 flex items-center focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-book mr-3 text-blue-500"></i>
                        Assign Subject Teachers
                    </button>
                    <button onclick="editClassDetails(${classId})"
                        class="modal-action-btn w-full text-left px-4 py-3 text-sm text-gray-700 dark:text-gray-300 hover:bg-blue-50 dark:hover:bg-gray-700 rounded-lg border border-gray-200 dark:border-gray-600 transition-all duration-200 flex items-center focus:outline-none focus:ring-2 focus:ring-blue-500">
                        <i class="fas fa-edit mr-3 text-blue-500"></i>
                        Edit Class Details
                    </button>
                </div>
                <div class="mt-6 pt-4 border-t border-gray-200 dark:border-gray-600">
                    <button onclick="closeModal()"
                        class="w-full px-4 py-2 text-sm text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 bg-gray-100 dark:bg-gray-700 rounded-lg transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    `;

    // Add click outside to close
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeModal();
        }
    });

    // Add keyboard navigation
    modal.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeModal();
        }
    });

    document.body.appendChild(modal);

    // Store modal reference for closing
    window.currentModal = modal;

    // Focus first action button for accessibility
    setTimeout(() => {
        const firstButton = modal.querySelector('.modal-action-btn');
        if (firstButton) {
            firstButton.focus();
        }
    }, 100);
}

// Helper functions for modal actions
function closeModal() {
    if (window.currentModal) {
        window.currentModal.remove();
        window.currentModal = null;
    }
}

function assignStudents(classId) {
    console.log('Assigning students for class ID:', classId);
    closeModal();
    showTab('students');
    const classSelect = document.getElementById('student_class_id');
    if (classSelect) {
        classSelect.value = classId;
        // Trigger change event to update the form
        classSelect.dispatchEvent(new Event('change'));
        // Add visual feedback
        classSelect.focus();
        classSelect.style.borderColor = '#3B82F6';
        setTimeout(() => {
            classSelect.style.borderColor = '';
        }, 2000);
    } else {
        console.error('Student class select element not found');
    }
}

function assignMainTeacher(classId) {
    console.log('Assigning main teacher for class ID:', classId);
    closeModal();
    showTab('main-teachers');
    const classSelect = document.getElementById('main_teacher_class_id');
    if (classSelect) {
        classSelect.value = classId;
        // Trigger change event to update the form
        classSelect.dispatchEvent(new Event('change'));
        // Add visual feedback
        classSelect.focus();
        classSelect.style.borderColor = '#3B82F6';
        setTimeout(() => {
            classSelect.style.borderColor = '';
        }, 2000);
    } else {
        console.error('Main teacher class select element not found');
    }
}

function assignSubjectTeachers(classId) {
    console.log('Assigning subject teachers for class ID:', classId);
    closeModal();
    showTab('subject-teachers');
    const classSelect = document.getElementById('subject_teacher_class_id');
    if (classSelect) {
        classSelect.value = classId;
        // Trigger change event to update the form
        classSelect.dispatchEvent(new Event('change'));
        // Add visual feedback
        classSelect.focus();
        classSelect.style.borderColor = '#3B82F6';
        setTimeout(() => {
            classSelect.style.borderColor = '';
        }, 2000);
    } else {
        console.error('Subject teacher class select element not found');
    }
}

function editClassDetails(classId) {
    console.log('Editing class details for class ID:', classId);
    closeModal();
    // Add loading feedback
    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg z-50';
    loadingDiv.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Redirecting to edit page...';
    document.body.appendChild(loadingDiv);

    setTimeout(() => {
        window.location.href = 'classes/edit.php?id=' + classId;
    }, 500);
}
</script>
