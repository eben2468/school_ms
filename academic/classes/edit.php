<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$class_id = filter_input(INPUT_GET, 'id', FILTER_SANITIZE_NUMBER_INT);
if (!$class_id) {
    header("Location: index.php");
    exit();
}

// Fetch class details
$query = "SELECT * FROM classes WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $class_id);
$stmt->execute();
$class = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$class) {
    header("Location: index.php");
    exit();
}

// Fetch available teachers
$query = "SELECT id, name FROM users WHERE role = 'teacher' AND status = 'active' ORDER BY name ASC";
$stmt = $db->query($query);
$teachers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch academic years
$query = "SELECT id, year_name, status FROM academic_years ORDER BY year_name DESC";
$stmt = $db->query($query);
$academic_years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch subjects under this class
$query = "SELECT id, name, code FROM subjects WHERE class_id = :class_id ORDER BY name ASC";
$stmt = $db->prepare($query);
$stmt->bindParam(':class_id', $class_id, PDO::PARAM_INT);
$stmt->execute();
$subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch current teacher assignments
$query = "SELECT teacher_id, subject_id FROM class_teachers WHERE class_id = :class_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':class_id', $class_id);
$stmt->execute();
$current_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Create lookup array for current assignments
$assignment_lookup = [];
foreach ($current_assignments as $assignment) {
    $assignment_lookup[$assignment['subject_id']] = $assignment['teacher_id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $grade_level = trim($_POST['grade_level'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $main_teacher_id = !empty($_POST['main_teacher_id']) ? (int)$_POST['main_teacher_id'] : null;
    $teacher_subjects = $_POST['teacher_subjects'] ?? [];

    try {
        $db->beginTransaction();

        // Update class
        $query = "UPDATE classes SET name = :name, grade_level = :grade_level, academic_year = :academic_year, main_teacher_id = :main_teacher_id WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':grade_level', $grade_level);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':main_teacher_id', $main_teacher_id, PDO::PARAM_INT);
        $stmt->bindParam(':id', $class_id);
        $stmt->execute();

        // Delete existing teacher assignments
        $query = "DELETE FROM class_teachers WHERE class_id = :class_id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':class_id', $class_id);
        $stmt->execute();

        // Insert new teacher assignments
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
        header("Location: index.php?success=Class updated successfully");
        exit();
    } catch (PDOException $e) {
        $db->rollBack();
        $error = "Error updating class. Please try again.";
    }
}
?>

<?php include '../../includes/header.php'; ?>
<?php include '../../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Edit Class</h1>
                    <a href="index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Classes
                    </a>
                </div>

                <?php if (isset($error)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4 dark:bg-red-900 dark:border-red-700 dark:text-red-300">
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <?php endif; ?>

                <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden">
                    <form action="" method="POST" class="p-6 space-y-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class Name</label>
                            <input type="text" id="name" name="name" required
                                value="<?php echo htmlspecialchars($class['name']); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label for="grade_level" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Grade Level</label>
                            <input type="text" id="grade_level" name="grade_level" required
                                value="<?php echo htmlspecialchars($class['grade_level']); ?>"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                        </div>

                        <div>
                            <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year</label>
                            <select id="academic_year" name="academic_year" required
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Academic Year</option>
                                <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year['year_name']); ?>"
                                    <?php echo ($class['academic_year'] === $year['year_name']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year['year_name']); ?> (<?php echo ucfirst($year['status']); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div>
                            <label for="main_teacher_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Main Class Teacher</label>
                            <select id="main_teacher_id" name="main_teacher_id"
                                class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                <option value="">Select Main Class Teacher</option>
                                <?php foreach ($teachers as $teacher): ?>
                                <option value="<?php echo $teacher['id']; ?>"
                                    <?php echo ($class['main_teacher_id'] == $teacher['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($teacher['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Select the main teacher responsible for this class.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Assign Teachers to Subjects</label>
                            <div class="relative">
                                <div class="space-y-4 max-h-96 overflow-y-auto p-4 border border-gray-200 dark:border-gray-600 rounded-md scroll-smooth" id="subjects-container">
                                    <?php foreach ($subjects as $subject): ?>
                                    <div class="border-b border-gray-200 dark:border-gray-600 pb-4 last:border-0 last:pb-0">
                                        <h3 class="font-medium text-gray-900 dark:text-white mb-2"><?php echo htmlspecialchars($subject['name']); ?> (<?php echo htmlspecialchars($subject['code']); ?>)</h3>
                                        <select name="teacher_subjects[]"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                            <option value="">Select Teacher</option>
                                            <?php foreach ($teachers as $teacher): ?>
                                            <?php
                                            $assignment_value = $teacher['id'] . '_' . $subject['id'];
                                            $is_selected = isset($assignment_lookup[$subject['id']]) && $assignment_lookup[$subject['id']] == $teacher['id'];
                                            ?>
                                            <option value="<?php echo $assignment_value; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($teacher['name']); ?>
                                            </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Scroll to Bottom Button - positioned inside subjects area -->
                                <button type="button" id="subjects-scroll-to-bottom" onclick="scrollSubjectsToBottom()"
                                        class="hidden absolute bottom-4 right-4 bg-blue-600 hover:bg-blue-700 text-white p-2 rounded-full shadow-lg transition-all duration-200 z-10">
                                    <i class="fas fa-chevron-down text-sm"></i>
                                </button>

                                <!-- Scroll to Top Button - positioned inside subjects area -->
                                <button type="button" id="subjects-scroll-to-top" onclick="scrollSubjectsToTop()"
                                        class="hidden absolute top-4 right-4 bg-gray-600 hover:bg-gray-700 text-white p-2 rounded-full shadow-lg transition-all duration-200 z-10">
                                    <i class="fas fa-chevron-up text-sm"></i>
                                </button>
                            </div>
                            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Select teachers for each subject in the class. Leave empty if not applicable.</p>
                        </div>

                        <div class="pt-4">
                            <button type="submit"
                                class="w-full flex justify-center py-2 px-4 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
                                Update Class
                            </button>
                        </div>
                    </form>
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
// Scroll functionality for subjects container
function scrollSubjectsToBottom() {
    const container = document.getElementById('subjects-container');
    container.scrollTo({
        top: container.scrollHeight,
        behavior: 'smooth'
    });
}

function scrollSubjectsToTop() {
    const container = document.getElementById('subjects-container');
    container.scrollTo({
        top: 0,
        behavior: 'smooth'
    });
}

// Show/hide scroll buttons based on scroll position
function updateSubjectsScrollButtons() {
    const container = document.getElementById('subjects-container');
    const scrollToTopBtn = document.getElementById('subjects-scroll-to-top');
    const scrollToBottomBtn = document.getElementById('subjects-scroll-to-bottom');

    if (container.scrollHeight > container.clientHeight) {
        // Show buttons only if content is scrollable
        if (container.scrollTop > 50) {
            scrollToTopBtn.classList.remove('hidden');
        } else {
            scrollToTopBtn.classList.add('hidden');
        }

        if (container.scrollTop < container.scrollHeight - container.clientHeight - 50) {
            scrollToBottomBtn.classList.remove('hidden');
        } else {
            scrollToBottomBtn.classList.add('hidden');
        }
    } else {
        // Hide buttons if content is not scrollable
        scrollToTopBtn.classList.add('hidden');
        scrollToBottomBtn.classList.add('hidden');
    }
}

// Initialize scroll buttons
document.addEventListener('DOMContentLoaded', function() {
    const container = document.getElementById('subjects-container');
    if (container) {
        container.addEventListener('scroll', updateSubjectsScrollButtons);
        // Initial check
        updateSubjectsScrollButtons();

        // Check again after a short delay to ensure content is loaded
        setTimeout(updateSubjectsScrollButtons, 100);
    }
});
</script>


