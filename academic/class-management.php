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
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-6 text-white shadow-xl relative overflow-hidden bg-gradient-to-r from-indigo-600 via-purple-600 to-pink-600">
                        <!-- Abstract Background Shapes -->
                        <div class="absolute top-0 right-0 -mt-4 -mr-4 w-40 h-40 bg-white/10 rounded-full blur-2xl"></div>
                        <div class="absolute bottom-0 left-1/3 -mb-10 w-60 h-60 bg-pink-500/10 rounded-full blur-3xl"></div>

                        <div class="flex flex-col md:flex-row md:items-center md:justify-between relative z-10">
                            <div>
                                <div class="flex items-center space-x-3 mb-2">
                                    <div class="bg-white/20 p-2 rounded-xl backdrop-blur-md">
                                        <i class="fas fa-user-friends text-2xl text-white"></i>
                                    </div>
                                    <h1 class="text-3xl font-bold tracking-tight">Class Management</h1>
                                </div>
                                <p class="text-indigo-100 text-sm max-w-xl">Assign students to classes, designate class teachers, manage subject specialists, and inspect current allocations.</p>
                            </div>
                            <div class="mt-4 md:mt-0">
                                <a href="index.php" class="inline-flex items-center px-4 py-2 bg-white/15 hover:bg-white/25 text-white border border-white/20 hover:border-white/40 rounded-xl transition-all duration-300 backdrop-blur-md text-sm font-semibold shadow-sm hover:scale-[1.02]">
                                    <i class="fas fa-arrow-left mr-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </div>

                        <!-- Stats Row Inside Header -->
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-6 pt-6 border-t border-white/10 relative z-10">
                            <div class="bg-white/5 rounded-xl p-3 backdrop-blur-sm border border-white/5">
                                <div class="text-[10px] text-indigo-200 font-bold uppercase tracking-wider mb-1">Active Classes</div>
                                <div class="text-2xl font-extrabold text-white"><?php echo count($classes); ?></div>
                            </div>
                            <div class="bg-white/5 rounded-xl p-3 backdrop-blur-sm border border-white/5">
                                <div class="text-[10px] text-indigo-200 font-bold uppercase tracking-wider mb-1">Total Students</div>
                                <div class="text-2xl font-extrabold text-white"><?php echo count($students); ?></div>
                            </div>
                            <div class="bg-white/5 rounded-xl p-3 backdrop-blur-sm border border-white/5">
                                <div class="text-[10px] text-indigo-200 font-bold uppercase tracking-wider mb-1">Unassigned Students</div>
                                <div class="text-2xl font-extrabold text-orange-300">
                                    <?php 
                                    $unassigned_count = count(array_filter($students, function($s) { return empty($s['current_class_id']); }));
                                    echo $unassigned_count;
                                    ?>
                                </div>
                            </div>
                            <div class="bg-white/5 rounded-xl p-3 backdrop-blur-sm border border-white/5">
                                <div class="text-[10px] text-indigo-200 font-bold uppercase tracking-wider mb-1">Active Teachers</div>
                                <div class="text-2xl font-extrabold text-green-300"><?php echo count($teachers); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (isset($success)): ?>
                <div class="flex items-center p-4 mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800/30 rounded-2xl shadow-sm text-green-800 dark:text-green-400">
                    <div class="bg-green-100 dark:bg-green-900/40 p-2 rounded-xl mr-3 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-check-circle text-lg"></i>
                    </div>
                    <span class="font-semibold text-sm"><?php echo htmlspecialchars($success); ?></span>
                </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="flex items-center p-4 mb-6 bg-red-50 dark:bg-red-950/20 border border-red-200 dark:border-red-900/50 rounded-2xl shadow-sm text-red-800 dark:text-red-400">
                    <div class="bg-red-100 dark:bg-red-900/40 p-2 rounded-xl mr-3 flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-exclamation-circle text-lg"></i>
                    </div>
                    <span class="font-semibold text-sm"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Assignment Tabs Container -->
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700/50 overflow-hidden">
                    <div class="bg-gray-50/50 dark:bg-gray-900/30 px-6 py-4 border-b border-gray-100 dark:border-gray-700/50">
                        <nav class="flex flex-wrap gap-2 p-1 bg-gray-100/70 dark:bg-gray-900/55 rounded-xl" aria-label="Tabs">
                            <button onclick="showTab('students')" id="students-tab" 
                                class="tab-button flex items-center justify-center space-x-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 cursor-pointer select-none">
                                <i class="fas fa-user-graduate text-base transition-colors duration-300"></i>
                                <span>Assign Students</span>
                            </button>
                            <button onclick="showTab('main-teachers')" id="main-teachers-tab"
                                class="tab-button flex items-center justify-center space-x-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 cursor-pointer select-none">
                                <i class="fas fa-user-tie text-base transition-colors duration-300"></i>
                                <span>Assign Main Class Teachers</span>
                            </button>
                            <button onclick="showTab('subject-teachers')" id="subject-teachers-tab"
                                class="tab-button flex items-center justify-center space-x-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 cursor-pointer select-none">
                                <i class="fas fa-book-open text-base transition-colors duration-300"></i>
                                <span>Assign Subject Teachers</span>
                            </button>
                            <button onclick="showTab('current')" id="current-tab"
                                class="tab-button flex items-center justify-center space-x-2 px-5 py-2.5 rounded-lg text-sm font-semibold transition-all duration-300 cursor-pointer select-none">
                                <i class="fas fa-layer-group text-base transition-colors duration-300"></i>
                                <span>Current Assignments</span>
                            </button>
                        </nav>
                    </div>

                    <!-- Tab Content -->
                    <div class="p-6">
                        <!-- Assign Students to Classes Tab -->
                        <div id="students-content" class="tab-content hidden space-y-6">
                            <div class="border-b border-gray-100 dark:border-gray-700/50 pb-4">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Assign Students to Classes</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Select a class, choose students, and batch allocate them instantly.</p>
                            </div>

                            <?php if (isset($_SESSION['already_assigned_students'])): ?>
                            <div class="bg-amber-50 dark:bg-amber-955/10 border border-amber-200 dark:border-amber-900/50 rounded-2xl p-5 mb-4 shadow-sm">
                                <div class="flex">
                                    <div class="flex-shrink-0 bg-amber-100 dark:bg-amber-900/55 p-2.5 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-exclamation-triangle text-amber-600 dark:text-amber-400 text-lg"></i>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="text-base font-bold text-amber-800 dark:text-amber-400">Students Already Assigned</h3>
                                        <div class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                            <p class="font-medium">The following students were skipped because they are already assigned to other classes:</p>
                                            <ul class="list-disc list-inside mt-2 space-y-1 bg-amber-100/30 dark:bg-amber-900/10 p-3 rounded-xl border border-amber-200/20">
                                                <?php foreach ($_SESSION['already_assigned_students'] as $student): ?>
                                                <li><?php echo htmlspecialchars($student); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                            <p class="mt-3">Use the <strong class="text-orange-600 dark:text-orange-400">Transfer Students</strong> option below if you want to force-move them to the selected class.</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php unset($_SESSION['already_assigned_students']); ?>
                            <?php endif; ?>

                            <form method="POST" class="space-y-6" id="studentAssignmentForm">
                                <input type="hidden" name="action" value="assign_students">

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <!-- Class Select & Info Card -->
                                    <div class="bg-gray-50/50 dark:bg-gray-900/20 p-5 rounded-2xl border border-gray-150 dark:border-gray-700/40 space-y-4">
                                        <div>
                                            <label for="student_class_id" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Select Target Class</label>
                                            <select id="student_class_id" name="class_id" required onchange="updateStudentList()"
                                                class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all font-medium">
                                                <option value="">Choose a class</option>
                                                <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id']; ?>">
                                                    <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <!-- Transfer Mode Card Switch -->
                                        <div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-150 dark:border-gray-700/50 p-4 hover:shadow-sm transition-shadow duration-205">
                                            <div class="flex items-center justify-between">
                                                <div class="flex-1 pr-4">
                                                    <label for="transfer_students" class="font-bold text-sm text-gray-850 dark:text-gray-200 cursor-pointer block select-none">
                                                        Enable Transfer Mode
                                                    </label>
                                                    <span class="text-xs text-gray-500 dark:text-gray-400 block mt-0.5">Move students who are currently in other classes to the selected class.</span>
                                                </div>
                                                <div class="relative inline-flex items-center cursor-pointer">
                                                    <input type="checkbox" name="transfer_students" id="transfer_students" onchange="toggleTransferModeCheckbox(this)" class="sr-only peer">
                                                    <div class="w-11 h-6 bg-gray-200 dark:bg-gray-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-305 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-orange-500 font-semibold"></div>
                                                </div>
                                            </div>
                                            <p class="text-[11px] text-orange-600 dark:text-orange-400 mt-2 flex items-center font-medium">
                                                <i class="fas fa-exclamation-triangle mr-1 animate-pulse"></i> Warning: This removes selected students from their current class.
                                            </p>
                                        </div>

                                        <div class="bg-blue-50/50 dark:bg-blue-955/10 border border-blue-200/50 dark:border-blue-900/30 rounded-xl p-4 space-y-2.5">
                                            <h4 class="text-xs font-bold text-blue-800 dark:text-blue-400 uppercase tracking-wider flex items-center">
                                                <i class="fas fa-info-circle mr-1.5 text-sm"></i> Assignment Rules
                                            </h4>
                                            <ul class="list-disc list-inside text-xs text-blue-700 dark:text-blue-300 space-y-1 font-medium">
                                                <li>Each student can belong to exactly one active class.</li>
                                                <li>Double assignments are blocked automatically.</li>
                                                <li>Deactivating assignments archives the history.</li>
                                            </ul>
                                        </div>
                                    </div>

                                    <!-- Student Selection List Card -->
                                    <div class="bg-gray-50/50 dark:bg-gray-900/20 p-5 rounded-2xl border border-gray-150 dark:border-gray-700/40 flex flex-col h-full">
                                        <!-- List Header Controls -->
                                        <div class="space-y-3 mb-3">
                                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                                                <span class="block text-sm font-bold text-gray-700 dark:text-gray-300">Select Students</span>
                                                <div class="flex space-x-2">
                                                    <button type="button" onclick="selectAllStudents()" class="px-2.5 py-1 bg-blue-50 hover:bg-blue-100 dark:bg-blue-905/30 dark:hover:bg-blue-900/50 text-blue-600 dark:text-blue-400 border border-blue-200/20 dark:border-blue-800/30 rounded-lg text-xs font-semibold transition-all">
                                                        Select All Available
                                                    </button>
                                                    <button type="button" onclick="deselectAllStudents()" class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 dark:bg-gray-800 dark:hover:bg-gray-700 text-gray-600 dark:text-gray-400 border border-gray-200/20 dark:border-gray-700/40 rounded-lg text-xs font-semibold transition-all">
                                                        Deselect All
                                                    </button>
                                                </div>
                                            </div>

                                            <!-- Live Search Filter -->
                                            <div class="relative">
                                                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                                                <input type="text" id="student_search" placeholder="Type to filter students..." onkeyup="filterStudentList()"
                                                    class="pl-9 pr-4 py-2 w-full border border-gray-300 dark:border-gray-600 rounded-xl bg-white dark:bg-gray-750 text-gray-900 dark:text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 transition-all text-sm">
                                            </div>
                                        </div>

                                        <!-- Container for Scroll List -->
                                        <div id="studentsList" class="flex-1 max-h-[300px] overflow-y-auto border border-gray-200 dark:border-gray-700/50 rounded-xl p-3 bg-white dark:bg-gray-800/40">
                                            <div class="text-center text-gray-400 py-12">
                                                <div class="w-12 h-12 bg-gray-100 dark:bg-gray-850 rounded-full flex items-center justify-center mx-auto mb-3">
                                                    <i class="fas fa-arrow-left text-lg text-gray-400 animate-pulse"></i>
                                                </div>
                                                <p class="text-sm font-medium">Please select a target class first</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" id="assignButton" disabled
                                    class="w-full flex justify-center items-center py-3 px-6 border border-transparent rounded-xl shadow-lg hover:shadow-xl text-base font-bold text-white bg-blue-600 hover:bg-blue-700 dark:bg-blue-650 dark:hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed transition-all duration-300 transform active:scale-[0.99]">
                                    <i class="fas fa-user-plus mr-2 text-lg"></i>
                                    <span>Assign Students to Class</span>
                                </button>
                            </form>
                        </div>

                        <!-- Assign Main Class Teachers Tab -->
                        <div id="main-teachers-content" class="tab-content hidden space-y-6">
                            <div class="border-b border-gray-100 dark:border-gray-700/50 pb-4">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Assign Main Class Teachers</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Designate a primary educator as the lead teacher responsible for a class's overall progress.</p>
                            </div>

                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="assign_main_teacher">

                                <div class="bg-gray-50/50 dark:bg-gray-900/20 p-6 rounded-2xl border border-gray-150 dark:border-gray-700/40 grid grid-cols-1 md:grid-cols-2 gap-6">
                                    <div class="space-y-2">
                                        <label for="main_teacher_class_id" class="block text-sm font-bold text-gray-700 dark:text-gray-300">Select Target Class</label>
                                                                        <select id="main_teacher_class_id" name="class_id" required
                                            class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all font-medium">
                                            <option value="">Choose a class</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>">
                                                <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="space-y-2">
                                        <label for="main_teacher_id" class="block text-sm font-bold text-gray-700 dark:text-gray-300">Select Main Teacher</label>
                                        <select id="main_teacher_id" name="teacher_id" required
                                            class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-green-500/20 focus:border-green-500 transition-all font-medium">
                                            <option value="">Choose a teacher</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                            <option value="<?php echo $teacher['id']; ?>">
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <button type="submit"
                                    class="w-full flex justify-center items-center py-3 px-6 border border-transparent rounded-xl shadow-lg hover:shadow-xl text-base font-bold text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-300 transform active:scale-[0.99]">
                                    <i class="fas fa-user-tie mr-2 text-lg"></i>
                                    Assign Main Teacher
                                </button>
                            </form>
                        </div>

                        <!-- Assign Subject Teachers Tab -->
                        <div id="subject-teachers-content" class="tab-content hidden space-y-6">
                            <div class="border-b border-gray-100 dark:border-gray-700/50 pb-4">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">Assign Subject Teachers</h3>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Assign subject specialist teachers to deliver curriculum content for the selected class.</p>
                            </div>

                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="assign_subject_teachers">

                                <div class="bg-gray-50/50 dark:bg-gray-900/20 p-5 rounded-2xl border border-gray-150 dark:border-gray-700/40 space-y-4">
                                    <div>
                                        <label for="subject_teacher_class_id" class="block text-sm font-bold text-gray-700 dark:text-gray-300 mb-2">Select Target Class</label>
                                        <select id="subject_teacher_class_id" name="class_id" required
                                            class="block w-full px-4 py-3 bg-white dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-xl shadow-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all font-medium">
                                            <option value="">Choose a class</option>
                                            <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>">
                                                <?php echo htmlspecialchars($class['name'] . ' - ' . $class['grade_level']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="space-y-3">
                                    <label class="block text-sm font-bold text-gray-700 dark:text-gray-300">Assign Teachers to Subjects</label>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 max-h-[400px] overflow-y-auto p-4 border border-gray-200 dark:border-gray-700/60 rounded-2xl bg-white dark:bg-gray-800/30">
                                        <?php foreach ($subjects as $subject): ?>
                                        <div class="bg-white dark:bg-gray-800 p-4 rounded-xl border border-gray-150 dark:border-gray-700 shadow-sm hover:shadow-md hover:border-purple-200 dark:hover:border-purple-900/60 transition-all duration-300 flex flex-col justify-between">
                                            <div class="flex items-center justify-between mb-3">
                                                <h4 class="font-bold text-gray-800 dark:text-gray-200 truncate pr-2">
                                                    <?php echo htmlspecialchars($subject['name']); ?>
                                                </h4>
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-bold bg-purple-100 text-purple-850 dark:bg-purple-950/40 dark:text-purple-400 border border-purple-200/30">
                                                    <?php echo htmlspecialchars($subject['code']); ?>
                                                </span>
                                            </div>
                                            <div>
                                                <select name="teacher_subjects[]" data-subject-id="<?php echo $subject['id']; ?>" class="subject-teacher-select block w-full px-3 py-2.5 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-500 transition-all">
                                                    <option value="">Select Specialist Teacher</option>
                                                    <?php foreach ($teachers as $teacher): ?>
                                                    <option value="<?php echo $teacher['id'] . '_' . $subject['id']; ?>">
                                                        <?php echo htmlspecialchars($teacher['name']); ?>
                                                    </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center mt-1">
                                        <i class="fas fa-info-circle mr-1.5 animate-pulse"></i> Set specialized subject teacher assignments. Dropdowns left unassigned will skip that subject.
                                    </p>
                                </div>

                                <button type="submit"
                                    class="w-full flex justify-center items-center py-3 px-6 border border-transparent rounded-xl shadow-lg hover:shadow-xl text-base font-bold text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-all duration-300 transform active:scale-[0.99]">
                                    <i class="fas fa-book-open mr-2 text-lg"></i>
                                    Assign Subject Teachers
                                </button>
                            </form>
                        </div>

                        <!-- Current Assignments Tab -->
                        <div id="current-content" class="tab-content">
                            <div class="flex justify-between items-center mb-6">
                                <h3 class="text-2xl font-bold text-gray-950 dark:text-white">Current Assignments</h3>
                                <div class="flex items-center space-x-4 text-sm text-gray-550 dark:text-gray-400">
                                    <span><i class="fas fa-chalkboard mr-1"></i><?php echo count($classes); ?> classes</span>
                                    <span><i class="fas fa-users mr-1"></i><?php echo count($students); ?> students</span>
                                    <?php
                                    $unassigned_count = count(array_filter($students, function($s) { return empty($s['current_class_id']); }));
                                    if ($unassigned_count > 0):
                                    ?>
                                    <span class="text-orange-600 dark:text-orange-400 font-semibold"><i class="fas fa-exclamation-triangle mr-1"></i><?php echo $unassigned_count; ?> unassigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($unassigned_count > 0): ?>
                            <div class="bg-orange-55 dark:bg-orange-955/10 border border-orange-200 dark:border-orange-900/50 rounded-2xl p-5 mb-6 shadow-sm">
                                <div class="flex items-start">
                                    <div class="flex-shrink-0 bg-orange-100 dark:bg-orange-900/55 p-2.5 rounded-xl flex items-center justify-center">
                                        <i class="fas fa-exclamation-triangle text-orange-600 dark:text-orange-400 text-lg"></i>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="text-base font-bold text-orange-850 dark:text-orange-400">Unassigned Students</h3>
                                        <div class="mt-2 text-sm text-orange-700 dark:text-gray-300">
                                            <p class="mb-3 font-semibold">The following students are not assigned to any class:</p>
                                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                                                <?php foreach ($students as $student): ?>
                                                    <?php if (empty($student['current_class_id'])): ?>
                                                    <div class="bg-white dark:bg-gray-800 rounded-xl px-4 py-2.5 border border-orange-200/55 dark:border-orange-900/40 shadow-sm flex items-center justify-between">
                                                        <span class="font-bold text-gray-800 dark:text-gray-200 text-sm"><?php echo htmlspecialchars($student['name']); ?></span>
                                                        <?php if ($student['roll_number']): ?>
                                                            <span class="text-gray-500 dark:text-gray-400 text-xs font-semibold bg-gray-100 dark:bg-gray-700 px-1.5 py-0.5 rounded">ID: <?php echo htmlspecialchars($student['roll_number']); ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </div>
                                            <p class="mt-4 text-xs font-semibold flex items-center">
                                                <i class="fas fa-lightbulb mr-1.5 text-orange-500 animate-pulse text-sm"></i>
                                                Use the "Assign Students to Classes" tab to assign these students to classes.
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (empty($classes)): ?>
                            <div class="text-center py-16">
                                <div class="w-24 h-24 mx-auto mb-4 bg-gray-100 dark:bg-gray-750 rounded-full flex items-center justify-center text-gray-400">
                                    <i class="fas fa-chalkboard-teacher text-4xl"></i>
                                </div>
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white mb-2">No Classes Found</h3>
                                <p class="text-gray-500 dark:text-gray-400 mb-6">Create some classes first to manage assignments.</p>
                                <a href="classes/create.php" class="inline-flex items-center px-5 py-2.5 border border-transparent text-sm font-bold rounded-xl text-white bg-blue-600 hover:bg-blue-700 shadow-md transition-all">
                                    <i class="fas fa-plus mr-2"></i>Create Class
                                </a>
                            </div>
                            <?php else: ?>
                            <div class="space-y-8">
                                <?php foreach ($classes as $class): ?>
                                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-lg border border-gray-150 dark:border-gray-700/60 overflow-hidden hover:shadow-xl transition-all duration-300">
                                    <!-- Class Header -->
                                    <div class="bg-gradient-to-r from-blue-600 to-indigo-650 p-5 text-white relative">
                                        <div class="absolute top-0 right-0 -mt-2 -mr-2 w-32 h-32 bg-white/5 rounded-full blur-xl"></div>
                                        <div class="flex justify-between items-center relative z-10">
                                            <div>
                                                <h4 class="text-2xl font-black tracking-tight">
                                                    <?php echo htmlspecialchars($class['name']); ?>
                                                </h4>
                                                <div class="flex items-center space-x-4 text-blue-100 text-xs font-semibold mt-1">
                                                    <span class="flex items-center">
                                                        <i class="fas fa-graduation-cap mr-1.5"></i>
                                                        <?php echo htmlspecialchars($class['grade_level']); ?>
                                                    </span>
                                                    <span class="flex items-center">
                                                        <i class="fas fa-calendar-alt mr-1.5"></i>
                                                        <?php echo htmlspecialchars($class['academic_year']); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="text-right">
                                                <div class="text-3xl font-black">
                                                    <?php echo count($current_assignments[$class['id']]['students']); ?>
                                                </div>
                                                <div class="text-blue-200 text-[10px] font-bold uppercase tracking-wider">Total Enrolled</div>
                                            </div>
                                        </div>
                                    </div>
                                    <!-- Class Content -->
                                    <div class="p-6">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                                            <!-- Main Teacher Card -->
                                            <div class="bg-gradient-to-br from-green-50 to-teal-50 dark:from-green-900/10 dark:to-teal-950/10 rounded-2xl p-5 border border-green-100 dark:border-green-800/30">
                                                <div class="flex items-center mb-3">
                                                    <div class="w-12 h-12 bg-green-100 dark:bg-green-900/40 rounded-xl flex items-center justify-center mr-4 flex-shrink-0 text-green-500">
                                                        <i class="fas fa-user-tie text-2xl"></i>
                                                    </div>
                                                    <div>
                                                        <h5 class="font-bold text-gray-900 dark:text-white text-sm">Main Class Teacher</h5>
                                                        <p class="text-xs text-gray-500 dark:text-gray-400">Primary Instructor</p>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-800 rounded-xl p-3 border border-green-100/30 dark:border-green-800/10 shadow-sm">
                                                    <?php if ($current_assignments[$class['id']]['main_teacher'] !== 'Not Assigned'): ?>
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 bg-green-100/50 dark:bg-green-900/20 rounded-full flex items-center justify-center mr-3 text-green-600">
                                                            <i class="fas fa-check-double text-xs"></i>
                                                        </div>
                                                        <div>
                                                            <p class="font-bold text-sm text-gray-900 dark:text-white">
                                                                <?php echo htmlspecialchars($current_assignments[$class['id']]['main_teacher']); ?>
                                                            </p>
                                                            <p class="text-[10px] text-gray-550 dark:text-gray-400 font-semibold">Active Class Lead</p>
                                                        </div>
                                                    </div>
                                                    <?php else: ?>
                                                    <div class="flex items-center">
                                                        <div class="w-8 h-8 bg-amber-50 dark:bg-amber-950/40 rounded-full flex items-center justify-center mr-3 text-amber-500">
                                                            <i class="fas fa-exclamation-triangle text-xs animate-pulse"></i>
                                                        </div>
                                                        <div>
                                                            <p class="font-bold text-sm text-gray-500 dark:text-gray-400">Not Assigned</p>
                                                            <p class="text-[10px] text-amber-600 dark:text-amber-400 font-bold uppercase tracking-wider">Required</p>
                                                        </div>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Students Card -->
                                            <div class="bg-gradient-to-br from-blue-50 to-indigo-50 dark:from-blue-955/10 dark:to-indigo-955/10 rounded-2xl p-5 border border-blue-100 dark:border-blue-900/40">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center">
                                                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-905/50 rounded-xl flex items-center justify-center mr-4 flex-shrink-0 text-blue-500">
                                                            <i class="fas fa-users text-2xl"></i>
                                                        </div>
                                                        <div>
                                                            <h5 class="font-bold text-gray-900 dark:text-white text-sm">Students</h5>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400">Enrolled Learners</p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400">
                                                            Total: <?php echo count($current_assignments[$class['id']]['students']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-800 rounded-xl p-3 border border-blue-100/30 dark:border-blue-900/20 shadow-sm max-h-[140px] overflow-y-auto space-y-1.5">
                                                    <?php if (!empty($current_assignments[$class['id']]['students'])): ?>
                                                        <?php foreach (array_slice($current_assignments[$class['id']]['students'], 0, 5) as $student): ?>
                                                        <div class="flex items-center justify-between py-1.5 group border-b border-blue-100/20 dark:border-blue-900/10 last:border-0">
                                                            <div class="flex items-center min-w-0 pr-2">
                                                                <div class="w-6 h-6 bg-blue-100/70 dark:bg-blue-900/40 rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                                                                    <i class="fas fa-user text-blue-600 dark:text-blue-450 text-[10px]"></i>
                                                                </div>
                                                                <span class="text-sm font-semibold text-gray-750 dark:text-gray-300 truncate">
                                                                    <?php echo htmlspecialchars($student['name']); ?>
                                                                    <?php if ($student['roll_number']): ?>
                                                                        <span class="text-xs text-gray-500 font-normal">(<?php echo htmlspecialchars($student['roll_number']); ?>)</span>
                                                                    <?php endif; ?>
                                                                </span>
                                                            </div>
                                                            <button onclick="removeStudent(<?php echo $student['id']; ?>, <?php echo $class['id']; ?>, '<?php echo htmlspecialchars($student['name']); ?>')"
                                                                class="opacity-0 group-hover:opacity-100 w-6 h-6 rounded-full bg-red-50 hover:bg-red-100 dark:bg-red-950/30 dark:hover:bg-red-900/40 text-red-500 hover:text-red-700 flex items-center justify-center transition-all duration-200 flex-shrink-0"
                                                                title="Remove from class">
                                                                <i class="fas fa-times text-[10px]"></i>
                                                            </button>
                                                        </div>
                                                        <?php endforeach; ?>
                                                        <?php if (count($current_assignments[$class['id']]['students']) > 5): ?>
                                                        <div class="text-[11px] text-gray-500 dark:text-gray-400 mt-2 text-center font-semibold bg-gray-50 dark:bg-gray-900/45 py-1 rounded-lg">
                                                            +<?php echo count($current_assignments[$class['id']]['students']) - 5; ?> more students
                                                        </div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                    <div class="text-center py-4">
                                                        <i class="fas fa-user-slash text-gray-400 dark:text-gray-500 text-2xl mb-1.5 block"></i>
                                                        <p class="text-xs text-gray-550 dark:text-gray-450 font-medium">No students assigned</p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>

                                            <!-- Subject Teachers Card -->
                                            <div class="bg-gradient-to-br from-purple-50 to-pink-50 dark:from-purple-955/10 dark:to-pink-955/10 rounded-2xl p-5 border border-purple-100 dark:border-purple-900/40">
                                                <div class="flex items-center justify-between mb-3">
                                                    <div class="flex items-center">
                                                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900/50 rounded-xl flex items-center justify-center mr-4 flex-shrink-0 text-purple-500">
                                                            <i class="fas fa-chalkboard-teacher text-2xl"></i>
                                                        </div>
                                                        <div>
                                                            <h5 class="font-bold text-gray-900 dark:text-white text-sm">Subject Teachers</h5>
                                                            <p class="text-xs text-gray-500 dark:text-gray-400">Subject Specialists</p>
                                                        </div>
                                                    </div>
                                                    <div class="text-right">
                                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-400">
                                                            Count: <?php echo count($current_assignments[$class['id']]['subject_teachers']); ?>
                                                        </span>
                                                    </div>
                                                </div>
                                                <div class="bg-white dark:bg-gray-800 rounded-xl p-3 border border-purple-100/30 dark:border-purple-900/20 shadow-sm max-h-[140px] overflow-y-auto space-y-1.5">
                                                    <?php if (!empty($current_assignments[$class['id']]['subject_teachers'])): ?>
                                                        <?php foreach ($current_assignments[$class['id']]['subject_teachers'] as $assignment): ?>
                                                        <div class="flex items-center justify-between py-1.5 border-b border-purple-100/20 dark:border-purple-900/10 last:border-0">
                                                            <div class="flex items-center min-w-0 pr-2">
                                                                <div class="w-6 h-6 bg-purple-100/70 dark:bg-purple-900/40 rounded-full flex items-center justify-center mr-2 flex-shrink-0">
                                                                    <i class="fas fa-book text-purple-650 dark:text-purple-450 text-[10px]"></i>
                                                                </div>
                                                                <span class="text-sm font-semibold text-gray-750 dark:text-gray-300 truncate">
                                                                    <?php echo htmlspecialchars($assignment['subject_name']); ?>
                                                                </span>
                                                            </div>
                                                            <span class="text-xs font-bold text-purple-600 dark:text-purple-450 truncate max-w-[120px]" title="<?php echo htmlspecialchars($assignment['teacher_name']); ?>">
                                                                <?php echo htmlspecialchars($assignment['teacher_name']); ?>
                                                            </span>
                                                        </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                    <div class="text-center py-4">
                                                        <i class="fas fa-chalkboard text-gray-400 dark:text-gray-500 text-2xl mb-1.5 block"></i>
                                                        <p class="text-xs text-gray-555 dark:text-gray-455 font-medium">No subject teachers assigned</p>
                                                    </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Quick Actions -->
                                        <div class="mt-6 pt-4 border-t border-gray-150 dark:border-gray-700/60">
                                            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                                                <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center">
                                                    <i class="fas fa-clock mr-1.5 animate-pulse"></i>
                                                    Last updated: <?php echo date('M j, Y'); ?>
                                                </div>
                                                <div class="flex space-x-2">
                                                    <button onclick="editClass(<?php echo $class['id']; ?>)"
                                                        class="inline-flex items-center px-4 py-2 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/30 dark:hover:bg-blue-900/50 text-blue-600 dark:text-blue-400 border border-blue-100/30 dark:border-blue-800/40 text-xs font-bold rounded-xl transition-all duration-200">
                                                        <i class="fas fa-edit mr-1.5"></i>
                                                        Edit Assignments
                                                    </button>
                                                    <a href="classes/view.php?id=<?php echo $class['id']; ?>"
                                                        class="inline-flex items-center px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 border border-gray-200/40 dark:border-gray-600/40 text-xs font-bold rounded-xl transition-all duration-200">
                                                        <i class="fas fa-eye mr-1.5"></i>
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
// Load all current class assignments from PHP
const currentAssignments = <?php echo json_encode($current_assignments); ?>;

// Student assignment management functions
function updateStudentList() {
    const classId = document.getElementById('student_class_id').value;
    const studentsList = document.getElementById('studentsList');
    const assignButton = document.getElementById('assignButton');
    const searchInput = document.getElementById('student_search');

    // Reset search
    if (searchInput) searchInput.value = '';

    if (!classId) {
        studentsList.innerHTML = `
            <div class="text-center text-gray-400 py-12">
                <div class="w-12 h-12 bg-gray-100 dark:bg-gray-850 rounded-full flex items-center justify-center mx-auto mb-3">
                    <i class="fas fa-arrow-left text-lg text-gray-400 animate-pulse"></i>
                </div>
                <p class="text-sm font-medium">Please select a target class first</p>
            </div>
        `;
        assignButton.disabled = true;
        return;
    }

    // Show loading spinner
    studentsList.innerHTML = `
        <div class="text-center py-16">
            <div class="inline-flex items-center space-x-3 bg-white dark:bg-gray-800 px-4 py-3 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-md">
                <i class="fas fa-circle-notch fa-spin text-2xl text-blue-600"></i>
                <span class="text-sm font-bold text-gray-700 dark:text-gray-300">Loading student records...</span>
            </div>
        </div>
    `;
    assignButton.disabled = true;

    // Fetch students for the selected class
    fetch(`get_students_for_class.php?class_id=${classId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStudentsList(data);
                assignButton.disabled = false;
            } else {
                studentsList.innerHTML = `
                    <div class="text-center py-12 text-red-600 dark:text-red-400">
                        <i class="fas fa-exclamation-triangle text-3xl mb-2"></i>
                        <p class="font-bold text-sm">Error loading students</p>
                        <p class="text-xs mt-1 text-gray-500">${data.error || 'Unknown server error'}</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            studentsList.innerHTML = `
                <div class="text-center py-12 text-red-600 dark:text-red-400">
                    <i class="fas fa-wifi text-3xl mb-2"></i>
                    <p class="font-bold text-sm">Network / Fetch error</p>
                    <p class="text-xs mt-1 text-gray-500">Could not connect to get_students_for_class.php</p>
                </div>
            `;
        });
}

function updateSubjectTeachersList() {
    const classId = document.getElementById('subject_teacher_class_id').value;
    const selects = document.querySelectorAll('.subject-teacher-select');
    
    // Clear all first
    selects.forEach(select => {
        select.value = '';
    });
    
    if (!classId) return;
    
    // Load existing assignments
    const assignments = currentAssignments[classId];
    if (assignments && assignments.subject_teachers) {
        const subjectTeachersMap = {};
        assignments.subject_teachers.forEach(item => {
            subjectTeachersMap[item.subject_id] = item.teacher_id;
        });
        
        selects.forEach(select => {
            const subjectId = select.getAttribute('data-subject-id');
            const teacherId = subjectTeachersMap[subjectId];
            if (teacherId) {
                select.value = teacherId + '_' + subjectId;
            }
        });
    }
}

function displayStudentsList(data) {
    const studentsList = document.getElementById('studentsList');
    const transferCheckbox = document.getElementById('transfer_students');
    const isTransferEnabled = transferCheckbox ? transferCheckbox.checked : false;
    let html = '';

    // Unassigned students (available for assignment)
    if (data.students.unassigned.length > 0) {
        html += '<div class="mb-5">';
        html += '<h4 class="text-xs font-bold text-green-600 dark:text-green-400 mb-3 flex items-center uppercase tracking-wider">';
        html += '<i class="fas fa-user-plus mr-2 text-sm"></i>Available Students (' + data.students.unassigned.length + ')';
        html += '</h4>';
        html += '<div class="space-y-2">';

        data.students.unassigned.forEach(student => {
            html += `
                <div class="student-item flex items-center p-3 bg-green-50/40 dark:bg-green-900/10 rounded-xl border border-green-100 dark:border-green-800/30 hover:bg-green-50 dark:hover:bg-green-900/20 hover:scale-[1.005] transition-all duration-200 cursor-pointer" onclick="toggleCheckbox('student_${student.id}')">
                    <input type="checkbox" name="student_ids[]" value="${student.id}"
                           id="student_${student.id}" class="student-checkbox available-student h-5 w-5 text-green-600 focus:ring-green-500 dark:focus:ring-offset-gray-800 border-gray-300 dark:border-gray-600 rounded cursor-pointer transition-all" onclick="event.stopPropagation()">
                    <label for="student_${student.id}" class="ml-3 text-sm text-gray-700 dark:text-gray-300 flex-1 font-semibold cursor-pointer select-none flex items-center justify-between" onclick="event.stopPropagation()">
                        <span>${student.name}</span>
                        ${student.roll_number ? `<span class="text-xs font-bold bg-white dark:bg-gray-850 px-2 py-0.5 border border-green-200/20 rounded shadow-sm text-gray-500">ID: ${student.roll_number}</span>` : ''}
                    </label>
                </div>
            `;
        });
        html += '</div></div>';
    }

    // Students assigned to other classes
    if (data.students.other_class.length > 0) {
        const wrapperClass = isTransferEnabled 
            ? 'bg-orange-50/50 dark:bg-orange-955/10 border-orange-100 dark:border-orange-900/30 hover:bg-orange-50 dark:hover:bg-orange-955/20 hover:scale-[1.005]' 
            : 'bg-gray-100/50 dark:bg-gray-900/30 border-gray-200 dark:border-gray-700/50 opacity-50';

        html += '<div class="mb-5">';
        html += '<h4 class="text-xs font-bold text-orange-600 dark:text-orange-400 mb-3 flex items-center uppercase tracking-wider">';
        html += '<i class="fas fa-exchange-alt mr-2 text-sm"></i>Assigned to Other Classes (' + data.students.other_class.length + ')';
        html += '</h4>';
        html += '<div class="space-y-2">';

        data.students.other_class.forEach(student => {
            html += `
                <div class="student-item transfer-student-wrapper flex items-center p-3 rounded-xl border transition-all duration-200 cursor-pointer ${wrapperClass}" onclick="if(!this.querySelector('input').disabled) toggleCheckbox('student_transfer_${student.id}')">
                    <input type="checkbox" name="student_ids[]" value="${student.id}"
                           id="student_transfer_${student.id}" class="student-checkbox transfer-student h-5 w-5 text-orange-600 focus:ring-orange-500 dark:focus:ring-offset-gray-800 border-gray-300 dark:border-gray-600 rounded cursor-pointer transition-all" ${isTransferEnabled ? '' : 'disabled'} onclick="event.stopPropagation()">
                    <label for="student_transfer_${student.id}" class="ml-3 text-sm text-gray-700 dark:text-gray-300 flex-1 font-semibold cursor-pointer select-none flex items-center justify-between" onclick="event.stopPropagation()">
                        <span class="flex flex-col sm:flex-row sm:items-center sm:gap-2">
                            <span>${student.name}</span>
                            <span class="text-[10px] bg-orange-100 dark:bg-orange-950/45 text-orange-700 dark:text-orange-400 px-1.5 py-0.5 rounded font-bold uppercase tracking-wider border border-orange-200/20">Class: ${student.current_class_name}</span>
                        </span>
                        ${student.roll_number ? `<span class="text-xs font-bold bg-white dark:bg-gray-850 px-2 py-0.5 border border-orange-200/20 rounded shadow-sm text-gray-500">ID: ${student.roll_number}</span>` : ''}
                    </label>
                </div>
            `;
        });
        html += '</div></div>';
    }

    // Students already in this class
    if (data.students.current_class.length > 0) {
        html += '<div class="mb-5">';
        html += '<h4 class="text-xs font-bold text-blue-600 dark:text-blue-400 mb-3 flex items-center uppercase tracking-wider">';
        html += '<i class="fas fa-check-circle mr-2 text-sm"></i>Already Enrolled in ' + data.class_name + ' (' + data.students.current_class.length + ')';
        html += '</h4>';
        html += '<div class="space-y-2 opacity-75">';

        data.students.current_class.forEach(student => {
            html += `
                <div class="flex items-center p-3 bg-blue-50/30 dark:bg-blue-950/5 border border-blue-100/50 dark:border-blue-900/20 rounded-xl">
                    <input type="checkbox" disabled checked class="h-5 w-5 text-blue-600 border-gray-300 dark:border-gray-650 rounded opacity-60">
                    <label class="ml-3 text-sm text-gray-500 dark:text-gray-400 flex-1 font-semibold flex items-center justify-between select-none">
                        <span>${student.name}</span>
                        ${student.roll_number ? `<span class="text-xs font-bold bg-white dark:bg-gray-850 px-2 py-0.5 border border-blue-200/10 rounded shadow-sm text-gray-450">ID: ${student.roll_number}</span>` : ''}
                    </label>
                </div>
            `;
        });
        html += '</div></div>';
    }

    if (data.students.unassigned.length === 0 && data.students.other_class.length === 0) {
        html += `
            <div class="text-center py-12 text-gray-500">
                <div class="w-16 h-16 bg-emerald-50 dark:bg-emerald-950/20 rounded-full flex items-center justify-center mx-auto mb-3 text-emerald-500">
                    <i class="fas fa-check text-2xl"></i>
                </div>
                <h5 class="font-bold text-gray-800 dark:text-white mb-1">Fully Allocated</h5>
                <p class="text-sm">All students are already assigned to active classes.</p>
            </div>
        `;
    }

    studentsList.innerHTML = html;
}

function toggleCheckbox(id) {
    const checkbox = document.getElementById(id);
    if (checkbox && !checkbox.disabled) {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
    }
}

function filterStudentList() {
    const query = document.getElementById('student_search').value.toLowerCase();
    const items = document.querySelectorAll('#studentsList .student-item');
    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        if (text.includes(query)) {
            item.style.display = 'flex';
        } else {
            item.style.display = 'none';
        }
    });
}

function toggleTransferModeCheckbox(el) {
    const transferStudents = document.querySelectorAll('.transfer-student');
    const transferWrappers = document.querySelectorAll('.transfer-student-wrapper');
    
    transferStudents.forEach(checkbox => {
        checkbox.disabled = !el.checked;
        if (!el.checked) checkbox.checked = false; // Uncheck when disabling
    });

    transferWrappers.forEach(wrapper => {
        if (el.checked) {
            wrapper.classList.remove('opacity-50', 'bg-gray-100/50', 'dark:bg-gray-900/30', 'border-gray-200', 'dark:border-gray-700/50');
            wrapper.classList.add('bg-orange-50/50', 'dark:bg-orange-955/10', 'border-orange-100', 'dark:border-orange-900/30', 'hover:bg-orange-50', 'dark:hover:bg-orange-955/20', 'hover:scale-[1.005]');
        } else {
            wrapper.classList.add('opacity-50', 'bg-gray-100/50', 'dark:bg-gray-900/30', 'border-gray-200', 'dark:border-gray-700/50');
            wrapper.classList.remove('bg-orange-50/50', 'dark:bg-orange-955/10', 'border-orange-100', 'dark:border-orange-900/30', 'hover:bg-orange-50', 'dark:hover:bg-orange-955/20', 'hover:scale-[1.005]');
        }
    });
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
            // Remove active classes
            tab.classList.remove('bg-white', 'dark:bg-gray-800', 'text-gray-900', 'dark:text-white', 'shadow-sm', 'border', 'border-gray-200/40', 'dark:border-gray-700/60');
            // Add inactive classes
            tab.classList.add('text-gray-500', 'dark:text-gray-400', 'hover:text-gray-900', 'dark:hover:text-white', 'hover:bg-white/40', 'dark:hover:bg-gray-800/40');
            
            // Icon handling
            const icon = tab.querySelector('i');
            if (icon) {
                icon.classList.remove('text-blue-600', 'dark:text-blue-400');
                icon.classList.add('text-gray-400', 'dark:text-gray-500');
            }
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
            // Remove inactive classes
            activeTab.classList.remove('text-gray-500', 'dark:text-gray-400', 'hover:text-gray-900', 'dark:hover:text-white', 'hover:bg-white/40', 'dark:hover:bg-gray-800/40');
            // Add active classes
            activeTab.classList.add('bg-white', 'dark:bg-gray-850', 'text-gray-900', 'dark:text-white', 'shadow-sm', 'border', 'border-gray-200/40', 'dark:border-gray-700/60');
            
            // Icon handling
            const icon = activeTab.querySelector('i');
            if (icon) {
                icon.classList.remove('text-gray-400', 'dark:text-gray-500');
                icon.classList.add('text-blue-600', 'dark:text-blue-400');
            }
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

    // Add change event listener for subject teacher assignment class dropdown
    const subjectTeacherClassSelect = document.getElementById('subject_teacher_class_id');
    if (subjectTeacherClassSelect) {
        subjectTeacherClassSelect.addEventListener('change', updateSubjectTeachersList);
    }
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
