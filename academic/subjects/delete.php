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
    header("Location: index.php?error=Invalid subject ID");
    exit();
}

// Get subject details
$query = "SELECT * FROM subjects WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$subject = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$subject) {
    header("Location: index.php?error=Subject not found");
    exit();
}

// Check if subject is assigned to any classes
$assignment_query = "SELECT COUNT(*) as count FROM class_teachers WHERE subject_id = :id";
$assignment_stmt = $db->prepare($assignment_query);
$assignment_stmt->bindParam(':id', $id);
$assignment_stmt->execute();
$assignment_count = $assignment_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Check if subject has any schedules
$schedule_query = "SELECT COUNT(*) as count FROM class_schedule WHERE subject_id = :id";
$schedule_stmt = $db->prepare($schedule_query);
$schedule_stmt->bindParam(':id', $id);
$schedule_stmt->execute();
$schedule_count = $schedule_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Handle deletion confirmation
if ($confirm === 'yes') {
    try {
        $db->beginTransaction();
        
        // Delete related records first
        if ($assignment_count > 0) {
            $delete_assignments = "DELETE FROM class_teachers WHERE subject_id = :id";
            $stmt = $db->prepare($delete_assignments);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        if ($schedule_count > 0) {
            $delete_schedules = "DELETE FROM class_schedule WHERE subject_id = :id";
            $stmt = $db->prepare($delete_schedules);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        // Delete the subject
        $delete_query = "DELETE FROM subjects WHERE id = :id";
        $stmt = $db->prepare($delete_query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $db->commit();
        header("Location: index.php?success=Subject deleted successfully");
        exit();
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error deleting subject: " . $e->getMessage();
    }
}

$title = "Delete Subject";
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
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Delete Subject</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Subjects
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
                                Confirm Subject Deletion
                            </h3>
                            <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                <p>You are about to delete the following subject:</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Subject Details -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-6">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Subject Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Subject Code</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($subject['code']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Subject Name</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($subject['name']); ?></p>
                            </div>
                            <?php if ($subject['description']): ?>
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Description</label>
                                <p class="mt-1 text-gray-900 dark:text-white"><?php echo htmlspecialchars($subject['description']); ?></p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Impact Warning -->
                <?php if ($assignment_count > 0 || $schedule_count > 0): ?>
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
                                <p>Deleting this subject will also remove:</p>
                                <ul class="list-disc list-inside mt-2 space-y-1">
                                    <?php if ($assignment_count > 0): ?>
                                    <li><?php echo $assignment_count; ?> class teacher assignment(s)</li>
                                    <?php endif; ?>
                                    <?php if ($schedule_count > 0): ?>
                                    <li><?php echo $schedule_count; ?> class schedule entry/entries</li>
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
                                This action cannot be undone. The subject and all related data will be permanently deleted.
                            </p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" 
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </a>
                            <a href="?id=<?php echo $id; ?>&confirm=yes" 
                               class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                               onclick="return confirm('Are you absolutely sure you want to delete this subject? This action cannot be undone!')">
                                <i class="fas fa-trash mr-2"></i>Delete Subject
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
            const subjectName = '<?php echo addslashes($subject['name']); ?>';
            const confirmed = confirm(`Are you absolutely sure you want to delete "${subjectName}"?\n\nThis will also delete:\n- All class assignments\n- All schedule entries\n\nThis action cannot be undone!`);
            if (!confirmed) {
                e.preventDefault();
            }
        });
    }
});
</script>
