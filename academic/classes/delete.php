<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if (!$id) {
    header("Location: index.php?error=Invalid class ID");
    exit();
}

// Get class details
$query = "SELECT * FROM classes WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: index.php?error=Class not found");
    exit();
}

// Check related records
$student_query = "SELECT COUNT(*) as count FROM student_classes WHERE class_id = :id";
$student_stmt = $db->prepare($student_query);
$student_stmt->bindParam(':id', $id);
$student_stmt->execute();
$student_count = $student_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$teacher_query = "SELECT COUNT(*) as count FROM class_teachers WHERE class_id = :id";
$teacher_stmt = $db->prepare($teacher_query);
$teacher_stmt->bindParam(':id', $id);
$teacher_stmt->execute();
$teacher_count = $teacher_stmt->fetch(PDO::FETCH_ASSOC)['count'];

$schedule_query = "SELECT COUNT(*) as count FROM class_schedule WHERE class_id = :id";
$schedule_stmt = $db->prepare($schedule_query);
$schedule_stmt->bindParam(':id', $id);
$schedule_stmt->execute();
$schedule_count = $schedule_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Handle deletion confirmation
if ($confirm === 'yes') {
    try {
        $db->beginTransaction();
        
        // Delete related records first
        if ($student_count > 0) {
            $delete_students = "DELETE FROM student_classes WHERE class_id = :id";
            $stmt = $db->prepare($delete_students);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        if ($teacher_count > 0) {
            $delete_teachers = "DELETE FROM class_teachers WHERE class_id = :id";
            $stmt = $db->prepare($delete_teachers);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        if ($schedule_count > 0) {
            $delete_schedules = "DELETE FROM class_schedule WHERE class_id = :id";
            $stmt = $db->prepare($delete_schedules);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        // Delete the class
        $delete_query = "DELETE FROM classes WHERE id = :id";
        $stmt = $db->prepare($delete_query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $db->commit();
        header("Location: index.php?success=Class deleted successfully");
        exit();
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error deleting class: " . $e->getMessage();
    }
}

$title = "Delete Class";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex items-center justify-between mb-6" style="margin-top: 30px;">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Delete Class</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Classes
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-6 dark:bg-red-900 dark:border-red-700 dark:text-red-300" role="alert">
                    <strong class="font-bold">Error:</strong>
                    <span class="block sm:inline"><?php echo htmlspecialchars($error); ?></span>
                </div>
                <?php endif; ?>

                <!-- Warning Card -->
                <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-6 mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-red-400 text-2xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-medium text-red-800 dark:text-red-200">
                                Confirm Class Deletion
                            </h3>
                            <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                <p>You are about to delete the following class:</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Class Details -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-6">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Class Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Class Name</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['name']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Grade Level</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['grade_level']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Academic Year</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($class['academic_year']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Impact Warning -->
                <?php if ($student_count > 0 || $teacher_count > 0 || $schedule_count > 0): ?>
                <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6 mb-6">
                    <div class="flex items-start">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-circle text-yellow-400 text-xl"></i>
                        </div>
                        <div class="ml-3">
                            <h3 class="text-lg font-medium text-yellow-800 dark:text-yellow-200">
                                Warning: This action will affect other records
                            </h3>
                            <div class="mt-2 text-sm text-yellow-700 dark:text-yellow-300">
                                <p>Deleting this class will also remove:</p>
                                <ul class="list-disc list-inside mt-2 space-y-1">
                                    <?php if ($student_count > 0): ?>
                                    <li><?php echo $student_count; ?> student enrollment(s)</li>
                                    <?php endif; ?>
                                    <?php if ($teacher_count > 0): ?>
                                    <li><?php echo $teacher_count; ?> teacher assignment(s)</li>
                                    <?php endif; ?>
                                    <?php if ($schedule_count > 0): ?>
                                    <li><?php echo $schedule_count; ?> schedule entry/entries</li>
                                    <?php endif; ?>
                                </ul>
                                <p class="mt-2 font-medium">This action cannot be undone!</p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Confirmation Buttons -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white">Are you sure?</h3>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                This action cannot be undone. The class and all related data will be permanently deleted.
                            </p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" 
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </a>
                            <a href="?id=<?php echo $id; ?>&confirm=yes" 
                               class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                               onclick="return confirm('Are you absolutely sure you want to delete this class? This action cannot be undone!')">
                                <i class="fas fa-trash mr-2"></i>Delete Class
                            </a>
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

<script>
// Add confirmation dialog for extra safety
document.addEventListener('DOMContentLoaded', function() {
    const deleteButton = document.querySelector('a[href*="confirm=yes"]');
    if (deleteButton) {
        deleteButton.addEventListener('click', function(e) {
            const className = '<?php echo addslashes($class['name']); ?>';
            const confirmed = confirm(`Are you absolutely sure you want to delete "${className}"?\n\nThis will also delete:\n- All student enrollments\n- All teacher assignments\n- All schedule entries\n\nThis action cannot be undone!`);
            if (!confirmed) {
                e.preventDefault();
            }
        });
    }
});
</script>
