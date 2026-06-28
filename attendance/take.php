<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
// Apply the school's configured timezone (e.g. Africa/Accra) before any time is
// stamped, so recorded check-in times match local time rather than the server's
// default timezone.
require_once '../includes/settings_helper.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get parameters
$selected_class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$selected_date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING) ?: date('Y-m-d');

// Fetch classes based on user role
if ($user_role === 'teacher') {
    $classes_query = "SELECT DISTINCT c.id, c.name, c.grade_level 
                     FROM classes c 
                     JOIN class_teachers ct ON c.id = ct.class_id 
                     WHERE ct.teacher_id = :teacher_id AND c.status = 'active'
                     ORDER BY c.grade_level, c.name";
    $classes_stmt = $db->prepare($classes_query);
    $classes_stmt->bindParam(':teacher_id', $user_id);
    $classes_stmt->execute();
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $classes_query = "SELECT id, name, grade_level FROM classes WHERE status = 'active' ORDER BY grade_level, name";
    $classes_stmt = $db->query($classes_query);
    $classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Set default class if none selected
if (!$selected_class_id && !empty($classes)) {
    $selected_class_id = $classes[0]['id'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_attendance'])) {
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $date = filter_input(INPUT_POST, 'date', FILTER_SANITIZE_STRING);
    $attendance_data = $_POST['attendance'] ?? [];
    
    try {
        $db->beginTransaction();
        
        // Delete existing attendance for this class and date
        $delete_query = "DELETE FROM attendance WHERE class_id = :class_id AND date = :date";
        $delete_stmt = $db->prepare($delete_query);
        $delete_stmt->bindParam(':class_id', $class_id);
        $delete_stmt->bindParam(':date', $date);
        $delete_stmt->execute();
        
        // Check if created_by column exists, if not add it
        try {
            $check_column = "SHOW COLUMNS FROM attendance LIKE 'created_by'";
            $check_stmt = $db->query($check_column);
            $has_created_by = $check_stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $has_created_by = false;
        }

        // Add created_by column if it doesn't exist
        if (!$has_created_by) {
            try {
                $alter_query = "ALTER TABLE attendance ADD COLUMN created_by INT NULL, ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL";
                $db->exec($alter_query);
                $has_created_by = true;
            } catch (PDOException $e) {
                // If we can't add the column, we'll insert without it
                $has_created_by = false;
            }
        }

        // Insert new attendance records
        if ($has_created_by) {
            $insert_query = "INSERT INTO attendance (student_id, class_id, date, status, notes, time_in, created_by)
                            VALUES (:student_id, :class_id, :date, :status, :notes, :time_in, :created_by)";
        } else {
            $insert_query = "INSERT INTO attendance (student_id, class_id, date, status, notes, time_in)
                            VALUES (:student_id, :class_id, :date, :status, :notes, :time_in)";
        }
        $insert_stmt = $db->prepare($insert_query);

        foreach ($attendance_data as $student_id => $data) {
            $status = $data['status'] ?? 'absent';
            $notes = $data['notes'] ?? '';
            // Record the check-in time for students who are present or late.
            // Use a time the teacher supplied for this student if provided,
            // otherwise stamp the moment the register is saved.
            $time_in = null;
            if (in_array($status, ['present', 'late'], true)) {
                $time_in = !empty($data['time_in']) ? $data['time_in'] : date('H:i:s');
            }

            $insert_stmt->bindParam(':student_id', $student_id);
            $insert_stmt->bindParam(':class_id', $class_id);
            $insert_stmt->bindParam(':date', $date);
            $insert_stmt->bindParam(':status', $status);
            $insert_stmt->bindParam(':notes', $notes);
            $insert_stmt->bindParam(':time_in', $time_in);
            if ($has_created_by) {
                $insert_stmt->bindParam(':created_by', $user_id);
            }
            $insert_stmt->execute();
        }
        
        $db->commit();
        $success = "Attendance saved successfully for " . date('F j, Y', strtotime($date));
    } catch (PDOException $e) {
        $db->rollBack();
        // Log the actual error for debugging
        error_log("Attendance save error: " . $e->getMessage());

        // Check if it's a table doesn't exist error
        if (strpos($e->getMessage(), "doesn't exist") !== false) {
            $error = "Database table missing. Please contact administrator to set up attendance table.";
        } else {
            $error = "Error saving attendance: " . $e->getMessage();
        }
    }
}

// Fetch students for selected class
$students = [];
if ($selected_class_id) {
    $students_query = "SELECT u.id, u.name, sp.student_id as roll_number,
                      a.status, a.notes
                      FROM users u
                      JOIN student_classes sc ON u.id = sc.student_id
                      LEFT JOIN student_profiles sp ON u.id = sp.user_id
                      LEFT JOIN attendance a ON u.id = a.student_id AND a.class_id = :class_id AND a.date = :date
                      WHERE sc.class_id = :class_id AND sc.status = 'active' AND u.role = 'student'
                      ORDER BY u.name";
    $students_stmt = $db->prepare($students_query);
    $students_stmt->bindParam(':class_id', $selected_class_id);
    $students_stmt->bindParam(':date', $selected_date);
    $students_stmt->execute();
    $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get selected class details
$selected_class = null;
if ($selected_class_id) {
    foreach ($classes as $class) {
        if ($class['id'] == $selected_class_id) {
            $selected_class = $class;
            break;
        }
    }
}
?>

<?php
$title = "Take Attendance";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Attendance Management', 'url' => 'index.php'],
    ['title' => 'Take Attendance']
];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Take Attendance</h1>
                                <p class="text-blue-100 text-lg">Mark student attendance for classes</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-green-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-check mr-2"></i>
                                        <?php echo date('F j, Y', strtotime($selected_date)); ?>
                                    </div>
                                    <?php if ($selected_class): ?>
                                    <div class="flex items-center">
                                        <i class="fas fa-chalkboard mr-2"></i>
                                        <?php echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-clipboard-check text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex justify-between items-center mb-6">
                    <nav class="flex" aria-label="Breadcrumb">
                        <ol class="inline-flex items-center space-x-1 md:space-x-3">
                            <li class="inline-flex items-center">
                                <a href="index.php" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                                    <i class="fas fa-clipboard-list mr-2"></i>
                                    Attendance Management
                                </a>
                            </li>
                            <li>
                                <div class="flex items-center">
                                    <i class="fas fa-chevron-right text-gray-400 mx-2"></i>
                                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Take Attendance</span>
                                </div>
                            </li>
                        </ol>
                    </nav>
                    <a href="index.php" class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Attendance
                    </a>
                </div>

                <?php if (isset($success)): ?>
                <div class="bg-green-50 dark:bg-green-900/50 border border-green-200 dark:border-green-800 text-green-700 dark:text-green-200 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                <div class="bg-red-50 dark:bg-red-900/50 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-200 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Class and Date Selection -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Select Class & Date</h3>
                        <p class="text-sm text-gray-500 dark:text-gray-400">Choose the class and date for attendance marking</p>
                    </div>
                    <div class="p-6">
                        <form action="" method="GET" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-chalkboard mr-2 text-blue-500"></i>Select Class
                                </label>
                                <select id="class_id" name="class_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $selected_class_id == $class['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    <i class="fas fa-calendar-alt mr-2 text-green-500"></i>Select Date
                                </label>
                                <input type="date" id="date" name="date" value="<?php echo htmlspecialchars($selected_date); ?>"
                                    onchange="this.form.submit()"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($selected_class_id && !empty($students)): ?>
                <!-- Attendance Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
                                    Attendance for <?php echo htmlspecialchars($selected_class['grade_level'] . ' - ' . $selected_class['name']); ?>
                                </h2>
                                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                    <?php echo date('F j, Y', strtotime($selected_date)); ?> • Total Students: <?php echo count($students); ?>
                                </p>
                            </div>
                            <div class="flex items-center space-x-2">
                                <div class="w-3 h-3 bg-green-400 rounded-full"></div>
                                <span class="text-sm text-gray-600 dark:text-gray-400">Ready to mark</span>
                            </div>
                        </div>
                    </div>

                    <form action="" method="POST" class="p-6">
                        <input type="hidden" name="class_id" value="<?php echo $selected_class_id; ?>">
                        <input type="hidden" name="date" value="<?php echo $selected_date; ?>">

                        <!-- Quick Actions -->
                        <div class="mb-6">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Quick Actions</h3>
                            <div class="flex flex-wrap gap-3">
                                <button type="button" onclick="markAll('present')"
                                    class="inline-flex items-center px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg text-sm font-medium shadow-lg hover:shadow-xl transition-all duration-200">
                                    <i class="fas fa-check mr-2"></i>Mark All Present
                                </button>
                                <button type="button" onclick="markAll('absent')"
                                    class="inline-flex items-center px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium shadow-lg hover:shadow-xl transition-all duration-200">
                                    <i class="fas fa-times mr-2"></i>Mark All Absent
                                </button>
                                <button type="button" onclick="markAll('late')"
                                    class="inline-flex items-center px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg text-sm font-medium shadow-lg hover:shadow-xl transition-all duration-200">
                                    <i class="fas fa-clock mr-2"></i>Mark All Late
                                </button>
                                <button type="button" onclick="clearAll()"
                                    class="inline-flex items-center px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-lg text-sm font-medium shadow-lg hover:shadow-xl transition-all duration-200">
                                    <i class="fas fa-eraser mr-2"></i>Clear All
                                </button>
                            </div>
                        </div>

                        <!-- Students List -->
                        <div class="space-y-4">
                            <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-4">Student Attendance</h3>
                            <?php foreach ($students as $index => $student): ?>
                            <div class="border border-gray-200 dark:border-gray-600 rounded-xl p-4 hover:shadow-lg transition-shadow duration-200 bg-gray-50 dark:bg-gray-700">
                                <div class="flex items-center justify-between flex-wrap gap-4">
                                    <div class="flex items-center space-x-4">
                                        <div class="w-12 h-12 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-semibold">
                                            <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                                        </div>
                                        <div>
                                            <h3 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['name']); ?></h3>
                                            <p class="text-sm text-gray-500 dark:text-gray-400">
                                                <i class="fas fa-id-badge mr-1"></i>
                                                Roll: <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                            </p>
                                        </div>
                                    </div>

                                    <div class="flex items-center space-x-6 flex-wrap">
                                        <!-- Attendance Status -->
                                        <div class="flex items-center space-x-4">
                                            <label class="flex items-center cursor-pointer group">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>][status]" value="present"
                                                    <?php echo $student['status'] === 'present' ? 'checked' : ''; ?>
                                                    class="w-4 h-4 text-green-600 focus:ring-green-500 focus:ring-2">
                                                <span class="ml-2 text-sm font-medium text-green-600 dark:text-green-400 group-hover:text-green-700 dark:group-hover:text-green-300">
                                                    <i class="fas fa-check mr-1"></i>Present
                                                </span>
                                            </label>
                                            <label class="flex items-center cursor-pointer group">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>][status]" value="absent"
                                                    <?php echo $student['status'] === 'absent' || !$student['status'] ? 'checked' : ''; ?>
                                                    class="w-4 h-4 text-red-600 focus:ring-red-500 focus:ring-2">
                                                <span class="ml-2 text-sm font-medium text-red-600 dark:text-red-400 group-hover:text-red-700 dark:group-hover:text-red-300">
                                                    <i class="fas fa-times mr-1"></i>Absent
                                                </span>
                                            </label>
                                            <label class="flex items-center cursor-pointer group">
                                                <input type="radio" name="attendance[<?php echo $student['id']; ?>][status]" value="late"
                                                    <?php echo $student['status'] === 'late' ? 'checked' : ''; ?>
                                                    class="w-4 h-4 text-yellow-600 focus:ring-yellow-500 focus:ring-2">
                                                <span class="ml-2 text-sm font-medium text-yellow-600 dark:text-yellow-400 group-hover:text-yellow-700 dark:group-hover:text-yellow-300">
                                                    <i class="fas fa-clock mr-1"></i>Late
                                                </span>
                                            </label>
                                        </div>

                                        <!-- Notes -->
                                        <div class="min-w-0 flex-1 max-w-xs">
                                            <input type="text" name="attendance[<?php echo $student['id']; ?>][notes]"
                                                value="<?php echo htmlspecialchars($student['notes'] ?? ''); ?>"
                                                placeholder="Add notes (optional)..."
                                                class="w-full px-3 py-2 text-sm border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-600 dark:text-white dark:placeholder-gray-400">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="mt-8 flex justify-between items-center pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex items-center space-x-2 text-sm text-gray-500 dark:text-gray-400">
                                <i class="fas fa-info-circle"></i>
                                <span>Changes are saved automatically as you mark attendance</span>
                            </div>
                            <div class="flex space-x-3">
                                <a href="index.php"
                                    class="px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="submit" name="save_attendance"
                                    class="px-6 py-3 border border-transparent rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 shadow-lg hover:shadow-xl">
                                    <i class="fas fa-save mr-2"></i>Save Attendance
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                <?php elseif ($selected_class_id && empty($students)): ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-graduate text-gray-400 text-4xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Students Found</h3>
                    <p class="text-gray-500 dark:text-gray-400">No students are enrolled in the selected class.</p>
                    <div class="mt-4">
                        <a href="../students/enroll.php" class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-lg text-sm">
                            <i class="fas fa-plus mr-2"></i>Enroll Students
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center py-12">
                    <div class="w-24 h-24 bg-gray-100 dark:bg-gray-700 rounded-full flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-clipboard-list text-gray-400 text-4xl"></i>
                    </div>
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Select a Class</h3>
                    <p class="text-gray-500 dark:text-gray-400">Please select a class to take attendance.</p>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function markAll(status) {
    const radios = document.querySelectorAll(`input[type="radio"][value="${status}"]`);
    radios.forEach(radio => {
        radio.checked = true;
    });
    updateAttendanceCount();
}

function clearAll() {
    const radios = document.querySelectorAll('input[type="radio"]');
    radios.forEach(radio => {
        radio.checked = false;
    });

    const notes = document.querySelectorAll('input[name*="[notes]"]');
    notes.forEach(note => {
        note.value = '';
    });
    updateAttendanceCount();
}

function updateAttendanceCount() {
    const presentCount = document.querySelectorAll('input[type="radio"][value="present"]:checked').length;
    const absentCount = document.querySelectorAll('input[type="radio"][value="absent"]:checked').length;
    const lateCount = document.querySelectorAll('input[type="radio"][value="late"]:checked').length;

    // Update counts in header if elements exist
    const totalStudents = document.querySelectorAll('input[type="radio"][value="present"]').length;
    console.log(`Present: ${presentCount}, Absent: ${absentCount}, Late: ${lateCount}, Total: ${totalStudents}`);
}

// Auto-save functionality
let autoSaveTimeout;
function autoSave() {
    clearTimeout(autoSaveTimeout);
    autoSaveTimeout = setTimeout(() => {
        // Save to localStorage for recovery
        const formData = new FormData(document.querySelector('form'));
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }
        localStorage.setItem('attendance_draft', JSON.stringify(data));
        console.log('Auto-saved attendance data');
    }, 2000);
}

// Load saved data on page load
function loadSavedData() {
    const savedData = localStorage.getItem('attendance_draft');
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            // Restore form data if it matches current class and date
            if (data.class_id === document.querySelector('input[name="class_id"]').value &&
                data.date === document.querySelector('input[name="date"]').value) {

                for (let [key, value] of Object.entries(data)) {
                    const input = document.querySelector(`[name="${key}"]`);
                    if (input) {
                        if (input.type === 'radio') {
                            if (input.value === value) {
                                input.checked = true;
                            }
                        } else {
                            input.value = value;
                        }
                    }
                }
            }
        } catch (e) {
            console.log('Error loading saved data:', e);
        }
    }
}

// Clear saved data on successful submit
function clearSavedData() {
    localStorage.removeItem('attendance_draft');
}

// Add event listeners
document.addEventListener('DOMContentLoaded', function() {
    loadSavedData();
    updateAttendanceCount();

    const inputs = document.querySelectorAll('input[type="radio"], input[type="text"]');
    inputs.forEach(input => {
        input.addEventListener('change', () => {
            autoSave();
            updateAttendanceCount();
        });
    });

    // Clear saved data on form submit
    const form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', clearSavedData);
    }
});
</script>
