<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

// Get parameters from URL
$student_id = filter_input(INPUT_GET, 'student_id', FILTER_SANITIZE_NUMBER_INT);
$class_id = filter_input(INPUT_GET, 'class_id', FILTER_SANITIZE_NUMBER_INT);
$date = filter_input(INPUT_GET, 'date', FILTER_SANITIZE_STRING);

// Validate required parameters
if (!$student_id || !$class_id || !$date) {
    $_SESSION['error'] = "Missing required parameters. Please select a student to edit.";
    header("Location: index.php");
    exit();
}

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $_SESSION['error'] = "Invalid date format.";
    header("Location: index.php");
    exit();
}

// Verify user has access to this class (for teachers)
if ($user_role === 'teacher') {
    $access_query = "SELECT COUNT(*) FROM class_teachers WHERE class_id = :class_id AND teacher_id = :teacher_id";
    $access_stmt = $db->prepare($access_query);
    $access_stmt->bindParam(':class_id', $class_id);
    $access_stmt->bindParam(':teacher_id', $user_id);
    $access_stmt->execute();
    $has_access = $access_stmt->fetchColumn() > 0;
    
    if (!$has_access) {
        $_SESSION['error'] = "You do not have permission to edit attendance for this class.";
        header("Location: index.php");
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_attendance'])) {
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    // Validate status
    $valid_statuses = ['present', 'absent', 'late'];
    if (!in_array($status, $valid_statuses)) {
        $error = "Invalid attendance status selected.";
    } else {
        try {
            $db->beginTransaction();
            
            // Check if attendance record exists
            $check_query = "SELECT id FROM attendance WHERE student_id = :student_id AND class_id = :class_id AND date = :date";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':student_id', $student_id);
            $check_stmt->bindParam(':class_id', $class_id);
            $check_stmt->bindParam(':date', $date);
            $check_stmt->execute();
            $existing_record = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check if created_by and updated_by columns exist
            try {
                $check_columns = "SHOW COLUMNS FROM attendance WHERE Field IN ('created_by', 'updated_by', 'updated_at')";
                $columns_stmt = $db->query($check_columns);
                $columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
                $has_created_by = in_array('created_by', $columns);
                $has_updated_by = in_array('updated_by', $columns);
                $has_updated_at = in_array('updated_at', $columns);
            } catch (PDOException $e) {
                $has_created_by = false;
                $has_updated_by = false;
                $has_updated_at = false;
            }
            
            // Add columns if they don't exist
            if (!$has_created_by) {
                try {
                    $db->exec("ALTER TABLE attendance ADD COLUMN created_by INT NULL, ADD FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL");
                    $has_created_by = true;
                } catch (PDOException $e) {
                    $has_created_by = false;
                }
            }
            
            if (!$has_updated_by) {
                try {
                    $db->exec("ALTER TABLE attendance ADD COLUMN updated_by INT NULL, ADD FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL");
                    $has_updated_by = true;
                } catch (PDOException $e) {
                    $has_updated_by = false;
                }
            }
            
            if (!$has_updated_at) {
                try {
                    $db->exec("ALTER TABLE attendance ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
                    $has_updated_at = true;
                } catch (PDOException $e) {
                    $has_updated_at = false;
                }
            }
            
            if ($existing_record) {
                // Update existing record
                $update_parts = ["status = :status", "notes = :notes"];
                if ($has_updated_by) {
                    $update_parts[] = "updated_by = :updated_by";
                }
                if ($has_updated_at) {
                    $update_parts[] = "updated_at = CURRENT_TIMESTAMP";
                }
                
                $update_query = "UPDATE attendance SET " . implode(", ", $update_parts) . " 
                                WHERE student_id = :student_id AND class_id = :class_id AND date = :date";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':status', $status);
                $update_stmt->bindParam(':notes', $notes);
                $update_stmt->bindParam(':student_id', $student_id);
                $update_stmt->bindParam(':class_id', $class_id);
                $update_stmt->bindParam(':date', $date);
                if ($has_updated_by) {
                    $update_stmt->bindParam(':updated_by', $user_id);
                }
                $update_stmt->execute();
            } else {
                // Insert new record
                $insert_parts = ["student_id", "class_id", "date", "status", "notes"];
                $insert_values = [":student_id", ":class_id", ":date", ":status", ":notes"];
                
                if ($has_created_by) {
                    $insert_parts[] = "created_by";
                    $insert_values[] = ":created_by";
                }
                
                $insert_query = "INSERT INTO attendance (" . implode(", ", $insert_parts) . ") 
                                VALUES (" . implode(", ", $insert_values) . ")";
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindParam(':student_id', $student_id);
                $insert_stmt->bindParam(':class_id', $class_id);
                $insert_stmt->bindParam(':date', $date);
                $insert_stmt->bindParam(':status', $status);
                $insert_stmt->bindParam(':notes', $notes);
                if ($has_created_by) {
                    $insert_stmt->bindParam(':created_by', $user_id);
                }
                $insert_stmt->execute();
            }
            
            $db->commit();
            $success = "Attendance record updated successfully.";
            
            // Redirect back to index with success message
            $_SESSION['success'] = $success;
            header("Location: index.php?class_id=" . $class_id . "&date=" . $date);
            exit();
            
        } catch (PDOException $e) {
            // Only rollback if transaction is active
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Attendance update error: " . $e->getMessage());
            $error = "Error updating attendance: " . $e->getMessage();
        }
    }
}

// Fetch student details
$student_query = "SELECT u.id, u.name, u.email, sp.student_id as roll_number, sp.date_of_birth, sp.gender
                 FROM users u
                 LEFT JOIN student_profiles sp ON u.id = sp.user_id
                 WHERE u.id = :student_id AND u.role = 'student'";
$student_stmt = $db->prepare($student_query);
$student_stmt->bindParam(':student_id', $student_id);
$student_stmt->execute();
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    header("Location: index.php");
    exit();
}

// Fetch class details
$class_query = "SELECT id, name, grade_level FROM classes WHERE id = :class_id";
$class_stmt = $db->prepare($class_query);
$class_stmt->bindParam(':class_id', $class_id);
$class_stmt->execute();
$class = $class_stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    $_SESSION['error'] = "Class not found.";
    header("Location: index.php");
    exit();
}

// Check which columns exist in attendance table
try {
    $check_columns = "SHOW COLUMNS FROM attendance WHERE Field IN ('created_at', 'updated_at')";
    $columns_stmt = $db->query($check_columns);
    $existing_columns = $columns_stmt->fetchAll(PDO::FETCH_COLUMN);
    $has_created_at = in_array('created_at', $existing_columns);
    $has_updated_at_col = in_array('updated_at', $existing_columns);
} catch (PDOException $e) {
    $has_created_at = false;
    $has_updated_at_col = false;
}

// Build query with only existing columns
$select_fields = ['status', 'notes'];
if ($has_created_at) {
    $select_fields[] = 'created_at';
}
if ($has_updated_at_col) {
    $select_fields[] = 'updated_at';
}

// Fetch existing attendance record
$attendance_query = "SELECT " . implode(', ', $select_fields) . " FROM attendance 
                    WHERE student_id = :student_id AND class_id = :class_id AND date = :date";
$attendance_stmt = $db->prepare($attendance_query);
$attendance_stmt->bindParam(':student_id', $student_id);
$attendance_stmt->bindParam(':class_id', $class_id);
$attendance_stmt->bindParam(':date', $date);
$attendance_stmt->execute();
$attendance_record = $attendance_stmt->fetch(PDO::FETCH_ASSOC);

// Set defaults if no record exists
$current_status = $attendance_record['status'] ?? 'absent';
$current_notes = $attendance_record['notes'] ?? '';

$title = "Edit Attendance";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Attendance Management', 'url' => 'index.php'],
    ['title' => 'Edit Attendance']
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
            <div class="w-full max-w-4xl mx-auto">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Edit Attendance</h1>
                                <p class="text-blue-100 text-lg">Update attendance record for a student</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-green-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-check mr-2"></i>
                                        <?php echo date('F j, Y', strtotime($date)); ?>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-chalkboard mr-2"></i>
                                        <?php echo htmlspecialchars($class['grade_level'] . ' - ' . $class['name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-user-edit text-6xl text-white/80"></i>
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
                                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Edit Attendance</span>
                                </div>
                            </li>
                        </ol>
                    </nav>
                    <a href="index.php?class_id=<?php echo $class_id; ?>&date=<?php echo $date; ?>" 
                        class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
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

                <!-- Student Information Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg mb-6 border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Student Information</h3>
                    </div>
                    <div class="p-6">
                        <div class="flex items-center space-x-4">
                            <div class="w-16 h-16 bg-gradient-to-r from-blue-500 to-purple-600 rounded-full flex items-center justify-center text-white font-bold text-2xl">
                                <?php echo strtoupper(substr($student['name'], 0, 1)); ?>
                            </div>
                            <div class="flex-1">
                                <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                                    <?php echo htmlspecialchars($student['name']); ?>
                                </h3>
                                <div class="flex items-center space-x-4 text-sm text-gray-600 dark:text-gray-400 mt-1">
                                    <span>
                                        <i class="fas fa-id-badge mr-1"></i>
                                        Roll: <?php echo htmlspecialchars($student['roll_number'] ?? 'N/A'); ?>
                                    </span>
                                    <?php if ($student['gender']): ?>
                                    <span>
                                        <i class="fas fa-venus-mars mr-1"></i>
                                        <?php echo ucfirst(htmlspecialchars($student['gender'])); ?>
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($student['date_of_birth']): ?>
                                    <span>
                                        <i class="fas fa-birthday-cake mr-1"></i>
                                        <?php echo date('M j, Y', strtotime($student['date_of_birth'])); ?>
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Edit Attendance Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700">
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Edit Attendance Record</h2>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                            Update the attendance status and notes for <?php echo date('F j, Y', strtotime($date)); ?>
                        </p>
                    </div>

                    <form action="" method="POST" class="p-6">
                        <!-- Attendance Status -->
                        <div class="mb-6">
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                                <i class="fas fa-clipboard-check mr-2 text-blue-500"></i>Attendance Status *
                            </label>
                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                <!-- Present Option -->
                                <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all duration-200 
                                    <?php echo $current_status === 'present' ? 'border-green-500 bg-green-50 dark:bg-green-900/30' : 'border-gray-300 dark:border-gray-600 hover:border-green-400 dark:hover:border-green-500'; ?>">
                                    <input type="radio" name="status" value="present" 
                                        <?php echo $current_status === 'present' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-green-600 focus:ring-green-500 focus:ring-2"
                                        onchange="updateStatusPreview(this.value)">
                                    <div class="ml-3 flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-check text-green-600 text-xl mr-2"></i>
                                            <span class="text-base font-semibold text-gray-900 dark:text-white">Present</span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Student attended class</p>
                                    </div>
                                </label>

                                <!-- Absent Option -->
                                <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all duration-200 
                                    <?php echo $current_status === 'absent' ? 'border-red-500 bg-red-50 dark:bg-red-900/30' : 'border-gray-300 dark:border-gray-600 hover:border-red-400 dark:hover:border-red-500'; ?>">
                                    <input type="radio" name="status" value="absent" 
                                        <?php echo $current_status === 'absent' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-red-600 focus:ring-red-500 focus:ring-2"
                                        onchange="updateStatusPreview(this.value)">
                                    <div class="ml-3 flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-times text-red-600 text-xl mr-2"></i>
                                            <span class="text-base font-semibold text-gray-900 dark:text-white">Absent</span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Student did not attend</p>
                                    </div>
                                </label>

                                <!-- Late Option -->
                                <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all duration-200 
                                    <?php echo $current_status === 'late' ? 'border-yellow-500 bg-yellow-50 dark:bg-yellow-900/30' : 'border-gray-300 dark:border-gray-600 hover:border-yellow-400 dark:hover:border-yellow-500'; ?>">
                                    <input type="radio" name="status" value="late" 
                                        <?php echo $current_status === 'late' ? 'checked' : ''; ?>
                                        class="w-5 h-5 text-yellow-600 focus:ring-yellow-500 focus:ring-2"
                                        onchange="updateStatusPreview(this.value)">
                                    <div class="ml-3 flex-1">
                                        <div class="flex items-center">
                                            <i class="fas fa-clock text-yellow-600 text-xl mr-2"></i>
                                            <span class="text-base font-semibold text-gray-900 dark:text-white">Late</span>
                                        </div>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Student arrived late</p>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="mb-6">
                            <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-sticky-note mr-2 text-purple-500"></i>Notes (Optional)
                            </label>
                            <textarea id="notes" name="notes" rows="4" 
                                placeholder="Add any additional notes or reasons for absence/lateness..."
                                class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white dark:placeholder-gray-400 resize-none"><?php echo htmlspecialchars($current_notes); ?></textarea>
                            <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>
                                For absences or late arrivals, please provide a brief explanation if available
                            </p>
                        </div>

                        <!-- Record Information -->
                        <?php if ($attendance_record): ?>
                        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-6">
                            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                <i class="fas fa-info-circle mr-2"></i>Record Information
                            </h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm text-gray-600 dark:text-gray-400">
                                <div>
                                    <span class="font-medium">Created:</span>
                                    <?php echo $attendance_record['created_at'] ? date('M j, Y g:i A', strtotime($attendance_record['created_at'])) : 'N/A'; ?>
                                </div>
                                <?php if (isset($attendance_record['updated_at']) && $attendance_record['updated_at']): ?>
                                <div>
                                    <span class="font-medium">Last Updated:</span>
                                    <?php echo date('M j, Y g:i A', strtotime($attendance_record['updated_at'])); ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Status Preview -->
                        <div id="status-preview" class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-4 mb-6">
                            <h4 class="text-sm font-medium text-blue-900 dark:text-blue-200 mb-2">
                                <i class="fas fa-eye mr-2"></i>Current Selection
                            </h4>
                            <div class="flex items-center">
                                <span id="preview-icon" class="text-2xl mr-3">
                                    <?php 
                                    echo $current_status === 'present' ? '<i class="fas fa-check text-green-600"></i>' : 
                                         ($current_status === 'late' ? '<i class="fas fa-clock text-yellow-600"></i>' : 
                                          '<i class="fas fa-times text-red-600"></i>'); 
                                    ?>
                                </span>
                                <span id="preview-text" class="text-base font-semibold text-gray-900 dark:text-white">
                                    <?php echo ucfirst($current_status); ?>
                                </span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-center space-x-2 text-sm text-gray-500 dark:text-gray-400 mb-4">
                                <i class="fas fa-asterisk text-red-500 text-xs"></i>
                                <span>Required fields</span>
                            </div>
                            <div class="flex gap-3">
                                <a href="index.php?class_id=<?php echo $class_id; ?>&date=<?php echo $date; ?>"
                                    class="flex-1 px-6 py-3 border border-gray-300 dark:border-gray-600 rounded-lg text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200 text-center">
                                    <i class="fas fa-times mr-2"></i>Cancel
                                </a>
                                <button type="submit" name="update_attendance"
                                    class="flex-1 px-6 py-3 border border-transparent rounded-lg text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-colors duration-200 shadow-lg hover:shadow-xl">
                                    <i class="fas fa-save mr-2"></i>Save Attendance
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Help Section -->
                <div class="bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-800 rounded-lg p-6 mt-6">
                    <h3 class="text-base font-semibold text-blue-900 dark:text-blue-200 mb-3">
                        <i class="fas fa-question-circle mr-2"></i>Need Help?
                    </h3>
                    <ul class="space-y-2 text-sm text-blue-800 dark:text-blue-300">
                        <li class="flex items-start">
                            <i class="fas fa-check-circle mr-2 mt-0.5"></i>
                            <span><strong>Present:</strong> Select when the student attended the full class session</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-times-circle mr-2 mt-0.5"></i>
                            <span><strong>Absent:</strong> Select when the student did not attend class at all</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-clock mr-2 mt-0.5"></i>
                            <span><strong>Late:</strong> Select when the student arrived after the class started</span>
                        </li>
                        <li class="flex items-start">
                            <i class="fas fa-edit mr-2 mt-0.5"></i>
                            <span><strong>Notes:</strong> Add any relevant information about the absence or late arrival (medical, family emergency, etc.)</span>
                        </li>
                    </ul>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function updateStatusPreview(status) {
    const previewIcon = document.getElementById('preview-icon');
    const previewText = document.getElementById('preview-text');
    
    let icon = '';
    let text = '';
    
    switch(status) {
        case 'present':
            icon = '<i class="fas fa-check text-green-600"></i>';
            text = 'Present';
            break;
        case 'absent':
            icon = '<i class="fas fa-times text-red-600"></i>';
            text = 'Absent';
            break;
        case 'late':
            icon = '<i class="fas fa-clock text-yellow-600"></i>';
            text = 'Late';
            break;
        default:
            icon = '<i class="fas fa-question text-gray-600"></i>';
            text = 'Unknown';
    }
    
    previewIcon.innerHTML = icon;
    previewText.textContent = text;
}

// Form validation
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');
    
    form.addEventListener('submit', function(e) {
        const status = document.querySelector('input[name="status"]:checked');
        
        if (!status) {
            e.preventDefault();
            alert('Please select an attendance status.');
            return false;
        }
    });
    
    // Add visual feedback for radio selection
    const radioInputs = document.querySelectorAll('input[type="radio"][name="status"]');
    radioInputs.forEach(radio => {
        radio.addEventListener('change', function() {
            // Remove all active classes
            document.querySelectorAll('label[class*="border-2"]').forEach(label => {
                label.classList.remove('border-green-500', 'bg-green-50', 'dark:bg-green-900/30',
                                      'border-red-500', 'bg-red-50', 'dark:bg-red-900/30',
                                      'border-yellow-500', 'bg-yellow-50', 'dark:bg-yellow-900/30');
                label.classList.add('border-gray-300', 'dark:border-gray-600');
            });
            
            // Add active class to selected option
            const label = this.closest('label');
            const value = this.value;
            
            label.classList.remove('border-gray-300', 'dark:border-gray-600');
            
            if (value === 'present') {
                label.classList.add('border-green-500', 'bg-green-50', 'dark:bg-green-900/30');
            } else if (value === 'absent') {
                label.classList.add('border-red-500', 'bg-red-50', 'dark:bg-red-900/30');
            } else if (value === 'late') {
                label.classList.add('border-yellow-500', 'bg-yellow-50', 'dark:bg-yellow-900/30');
            }
        });
    });
});
</script>
