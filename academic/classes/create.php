<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Fetch available teachers
$query = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name ASC";
$stmt = $db->query($query);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects
$query = "SELECT id, name, code FROM subjects ORDER BY name ASC";
$stmt = $db->query($query);
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $main_teacher_id = !empty($_POST['main_teacher_id']) ? (int)$_POST['main_teacher_id'] : null;
    $teacher_subjects = $_POST['teacher_subjects'] ?? [];

    try {
        $db->beginTransaction();

        // Insert class
        $query = "INSERT INTO classes (name, grade_level, academic_year, main_teacher_id) VALUES (:name, :grade_level, :academic_year, :main_teacher_id)";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':grade_level', $grade_level);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':main_teacher_id', $main_teacher_id, PDO::PARAM_INT);
        $stmt->execute();
        
        $class_id = $db->lastInsertId();

        // Insert class teachers and their subjects
        if (!empty($teacher_subjects)) {
            $query = "INSERT INTO class_teachers (class_id, teacher_id, subject_id) VALUES (:class_id, :teacher_id, :subject_id)";
            $stmt = $db->prepare($query);

            foreach ($teacher_subjects as $assignment) {
                // Skip empty assignments
                if (empty($assignment) || trim($assignment) === '') {
                    continue;
                }

                // Validate the assignment format
                $parts = explode('_', $assignment);
                if (count($parts) !== 2) {
                    continue;
                }

                list($teacher_id, $subject_id) = $parts;

                // Validate that both teacher_id and subject_id are valid numbers
                if (!is_numeric($teacher_id) || !is_numeric($subject_id) ||
                    empty($teacher_id) || empty($subject_id)) {
                    continue;
                }

                $stmt->bindParam(':class_id', $class_id);
                $stmt->bindParam(':teacher_id', $teacher_id, PDO::PARAM_INT);
                $stmt->bindParam(':subject_id', $subject_id, PDO::PARAM_INT);
                $stmt->execute();
            }
        }

        $db->commit();
        header("Location: ../index.php?success=Class created successfully");
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        // Log the actual error for debugging
        error_log("Class creation error: " . $e->getMessage());
        $error = "Database error: " . $e->getMessage();
    }
}
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
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800">Add New Class</h1>
                <a href="../index.php" class="text-blue-600 hover:text-blue-800">
                    <i class="fas fa-arrow-left mr-2"></i>Back to Academic Management
                </a>
            </div>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <form action="" method="POST" class="p-6 space-y-6">
                    <div>
                        <label for="name" class="block text-sm font-medium text-gray-700">Class Name</label>
                        <input type="text" id="name" name="name" required
                            value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="grade_level" class="block text-sm font-medium text-gray-700">Grade Level</label>
                        <input type="text" id="grade_level" name="grade_level" required
                            value="<?php echo isset($_POST['grade_level']) ? htmlspecialchars($_POST['grade_level']) : ''; ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="academic_year" class="block text-sm font-medium text-gray-700">Academic Year</label>
                        <input type="text" id="academic_year" name="academic_year" required
                            placeholder="e.g., 2025-2026"
                            pattern="\d{4}-\d{4}"
                            value="<?php
                                if (isset($_POST['academic_year'])) {
                                    echo htmlspecialchars($_POST['academic_year']);
                                } else {
                                    $academic_context = $database->getCurrentAcademicContext();
                                    echo htmlspecialchars($academic_context['year_name']);
                                }
                            ?>"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    </div>

                    <div>
                        <label for="main_teacher_id" class="block text-sm font-medium text-gray-700">Main Class Teacher</label>
                        <select id="main_teacher_id" name="main_teacher_id"
                            class="mt-1 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Select Main Class Teacher</option>
                            <?php foreach ($teachers as $teacher): ?>
                            <option value="<?php echo $teacher['id']; ?>"
                                <?php echo (isset($_POST['main_teacher_id']) && $_POST['main_teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($teacher['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="mt-1 text-sm text-gray-500">Select the main teacher responsible for this class.</p>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Assign Teachers to Subjects</label>
                        <div class="space-y-4 max-h-96 overflow-y-auto p-4 border border-gray-200 rounded-md">
                            <?php foreach ($subjects as $subject): ?>
                            <div class="border-b border-gray-200 pb-4 last:border-0 last:pb-0">
                                <h3 class="font-medium text-gray-900 mb-2"><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
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
                        <p class="mt-2 text-sm text-gray-500">Select teachers for each subject in the class. Leave empty if not applicable.</p>
                    </div>

                    <div class="pt-4">
                        <button type="submit"
                            class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                            Create Class
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
// Form validation
document.querySelector('form').addEventListener('submit', function(e) {
    const name = document.querySelector('input[name="name"]').value.trim();
    const gradeLevel = document.querySelector('input[name="grade_level"]').value.trim();
    const academicYear = document.querySelector('input[name="academic_year"]').value.trim();

    if (!name || !gradeLevel || !academicYear) {
        e.preventDefault();
        alert('Please fill in all required fields (Class Name, Grade Level, and Academic Year).');
        return false;
    }

    // Validate teacher-subject assignments
    const teacherSubjects = document.querySelectorAll('select[name="teacher_subjects[]"]');
    let hasValidAssignment = false;

    teacherSubjects.forEach(select => {
        if (select.value && select.value.trim() !== '') {
            hasValidAssignment = true;
        }
    });

    if (!hasValidAssignment) {
        const confirm = window.confirm('No teacher-subject assignments have been made. Do you want to create the class without any teacher assignments?');
        if (!confirm) {
            e.preventDefault();
            return false;
        }
    }

    return true;
});
</script>