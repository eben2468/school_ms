<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$confirm = isset($_GET['confirm']) ? $_GET['confirm'] : '';

if (!$id) {
    header("Location: index.php?error=Invalid student ID");
    exit();
}

// Get student details
$query = "SELECT u.*, sp.student_id, sp.admission_date 
          FROM users u 
          LEFT JOIN student_profiles sp ON u.id = sp.user_id 
          WHERE u.id = :id AND u.role = 'student'";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    header("Location: index.php?error=Student not found");
    exit();
}

// Check related records
$class_query = "SELECT COUNT(*) as count FROM student_classes WHERE student_id = :id";
$class_stmt = $db->prepare($class_query);
$class_stmt->bindParam(':id', $id);
$class_stmt->execute();
$class_count = $class_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Check for grades/assessments (if tables exist)
$grade_count = 0;
try {
    $grade_query = "SELECT COUNT(*) as count FROM grades WHERE student_id = :id";
    $grade_stmt = $db->prepare($grade_query);
    $grade_stmt->bindParam(':id', $id);
    $grade_stmt->execute();
    $grade_count = $grade_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    // Table might not exist, ignore
}

// Handle deletion confirmation
if ($confirm === 'yes') {
    try {
        $db->beginTransaction();
        
        // Delete related records first
        if ($class_count > 0) {
            $delete_classes = "DELETE FROM student_classes WHERE student_id = :id";
            $stmt = $db->prepare($delete_classes);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
        }
        
        if ($grade_count > 0) {
            try {
                $delete_grades = "DELETE FROM grades WHERE student_id = :id";
                $stmt = $db->prepare($delete_grades);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
            } catch (PDOException $e) {
                // Table might not exist, continue
            }
        }
        
        // Delete student profile
        $delete_profile = "DELETE FROM student_profiles WHERE user_id = :id";
        $stmt = $db->prepare($delete_profile);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        // Delete the user
        $delete_user = "DELETE FROM users WHERE id = :id";
        $stmt = $db->prepare($delete_user);
        $stmt->bindParam(':id', $id);
        $stmt->execute();
        
        $db->commit();
        header("Location: index.php?success=Student deleted successfully");
        exit();
        
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error deleting student: " . $e->getMessage();
    }
}

$title = "Delete Student";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex items-center justify-between mb-6" style="margin-top: 30px;">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Delete Student</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i> Back to Students
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
                                Confirm Student Deletion
                            </h3>
                            <div class="mt-2 text-sm text-red-700 dark:text-red-300">
                                <p>You are about to permanently delete the following student:</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Student Details -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow mb-6">
                    <div class="p-6">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">Student Information</h2>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Full Name</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['name']); ?></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Email</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['email']); ?></p>
                            </div>
                            <?php if ($student['student_id']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Student ID</label>
                                <p class="mt-1 text-lg text-gray-900 dark:text-white"><?php echo htmlspecialchars($student['student_id']); ?></p>
                            </div>
                            <?php endif; ?>
                            <?php if ($student['admission_date']): ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Admission Date</label>
                                <p class="mt-1 text-gray-900 dark:text-white"><?php echo date('M j, Y', strtotime($student['admission_date'])); ?></p>
                            </div>
                            <?php endif; ?>
                            <div>
                                <label class="block text-sm font-medium text-gray-500 dark:text-gray-400">Status</label>
                                <p class="mt-1 text-gray-900 dark:text-white">
                                    <span class="px-2 py-1 text-xs font-medium rounded-full <?php echo $student['status'] === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200'; ?>">
                                        <?php echo ucfirst($student['status']); ?>
                                    </span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Impact Warning -->
                <?php if ($class_count > 0 || $grade_count > 0): ?>
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
                                <p>Deleting this student will also remove:</p>
                                <ul class="list-disc list-inside mt-2 space-y-1">
                                    <li>Student profile and personal information</li>
                                    <?php if ($class_count > 0): ?>
                                    <li><?php echo $class_count; ?> class enrollment(s)</li>
                                    <?php endif; ?>
                                    <?php if ($grade_count > 0): ?>
                                    <li><?php echo $grade_count; ?> grade record(s)</li>
                                    <?php endif; ?>
                                    <li>All academic history and records</li>
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
                                This action cannot be undone. The student and all related academic data will be permanently deleted.
                            </p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" 
                               class="px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Cancel
                            </a>
                            <a href="?id=<?php echo $id; ?>&confirm=yes" 
                               class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                               onclick="return confirm('Are you absolutely sure you want to delete this student? This action cannot be undone!')">
                                <i class="fas fa-trash mr-2"></i>Delete Student
                            </a>
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
// Add confirmation dialog for extra safety
document.addEventListener('DOMContentLoaded', function() {
    const deleteButton = document.querySelector('a[href*="confirm=yes"]');
    if (deleteButton) {
        deleteButton.addEventListener('click', function(e) {
            const studentName = '<?php echo addslashes($student['name']); ?>';
            const confirmed = confirm(`Are you absolutely sure you want to delete "${studentName}"?\n\nThis will permanently remove:\n- Student profile\n- Class enrollments\n- All academic records\n\nThis action cannot be undone!`);
            if (!confirmed) {
                e.preventDefault();
            }
        });
    }
});
</script>
