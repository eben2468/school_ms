<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle bulk promotion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_promote') {
    $from_year_id = filter_input(INPUT_POST, 'from_year_id', FILTER_SANITIZE_NUMBER_INT);
    $to_year_id = filter_input(INPUT_POST, 'to_year_id', FILTER_SANITIZE_NUMBER_INT);
    $selected_students = $_POST['selected_students'] ?? [];
    $promotions = $_POST['promotions'] ?? [];

    if (!empty($selected_students)) {
        try {
            $db->beginTransaction();
            
            foreach ($selected_students as $student_id) {
                // Skip if no promotion data for this student
                if (!isset($promotions[$student_id])) {
                    continue;
                }

                $promotion_data = $promotions[$student_id];
                $to_class_id = $promotion_data['to_class_id'];
                $status = $promotion_data['status'];
                $remarks = $promotion_data['remarks'] ?? '';

                // Skip if required fields are empty
                if (empty($to_class_id) || empty($status)) {
                    continue;
                }
                
                // Get current class
                $current_class_sql = "SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active'";
                $stmt = $db->prepare($current_class_sql);
                $stmt->bindParam(':student_id', $student_id);
                $stmt->execute();
                $current_class = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current_class) {
                    // Record promotion
                    $promotion_sql = "INSERT INTO student_promotions 
                        (student_id, from_academic_year_id, to_academic_year_id, from_class_id, to_class_id, promotion_status, promotion_date, remarks, created_by)
                        VALUES (:student_id, :from_year_id, :to_year_id, :from_class_id, :to_class_id, :status, CURDATE(), :remarks, :created_by)";
                    $stmt = $db->prepare($promotion_sql);
                    $stmt->bindParam(':student_id', $student_id);
                    $stmt->bindParam(':from_year_id', $from_year_id);
                    $stmt->bindParam(':to_year_id', $to_year_id);
                    $stmt->bindParam(':from_class_id', $current_class['class_id']);
                    $stmt->bindParam(':to_class_id', $to_class_id);
                    $stmt->bindParam(':status', $status);
                    $stmt->bindParam(':remarks', $remarks);
                    $stmt->bindParam(':created_by', $_SESSION['user_id']);
                    $stmt->execute();
                    
                    // Update student class assignment
                    if ($status === 'promoted' || $status === 'repeated') {
                        // Deactivate current class assignment
                        $deactivate_sql = "UPDATE student_classes SET status = 'inactive' WHERE student_id = :student_id AND status = 'active'";
                        $stmt = $db->prepare($deactivate_sql);
                        $stmt->bindParam(':student_id', $student_id);
                        $stmt->execute();
                        
                        // Create new class assignment
                        $assign_sql = "INSERT INTO student_classes (student_id, class_id, status) VALUES (:student_id, :class_id, 'active')";
                        $stmt = $db->prepare($assign_sql);
                        $stmt->bindParam(':student_id', $student_id);
                        $stmt->bindParam(':class_id', $to_class_id);
                        $stmt->execute();
                    }
                }
            }
            
            $db->commit();
            $success_message = "Student promotions processed successfully!";
        } catch (PDOException $e) {
            $db->rollBack();
            $error_message = "Error processing promotions: " . $e->getMessage();
        }
    } else {
        $error_message = "No students selected for promotion.";
    }
}

// Get academic years
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get current academic context
$academic_context = $database->getCurrentAcademicContext();
$current_year_id = $academic_context['year_id'];

// Get classes
$classes_sql = "SELECT * FROM classes WHERE status = 'active' ORDER BY grade_level, name";
$classes = $db->query($classes_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get students for promotion (if year and optionally class is selected)
$students = [];
$selected_year_id = $_GET['year_id'] ?? $current_year_id;
$selected_class_id = $_GET['class_id'] ?? '';

if ($selected_year_id) {
    $students_sql = "SELECT
        u.id, u.name, u.email,
        sp.student_id as profile_student_id,
        c.id as class_id, c.name as class_name, c.grade_level,
        sc.status as class_status,
        COALESCE(AVG(sar.total_score), 0) as average_score,
        COUNT(sar.id) as subjects_count
    FROM users u
    JOIN student_profiles sp ON u.id = sp.user_id
    JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
    JOIN classes c ON sc.class_id = c.id
    LEFT JOIN student_academic_records sar ON u.id = sar.student_id AND sar.academic_year_id = :year_id
    WHERE u.role = 'student' AND u.status = 'active'";

    // Add class filter if selected
    if ($selected_class_id) {
        $students_sql .= " AND c.id = :class_id";
    }

    $students_sql .= " GROUP BY u.id, sp.student_id, c.id, c.name, c.grade_level, sc.status
                      ORDER BY c.grade_level, c.name, u.name";

    $stmt = $db->prepare($students_sql);
    $stmt->bindParam(':year_id', $selected_year_id);
    if ($selected_class_id) {
        $stmt->bindParam(':class_id', $selected_class_id);
    }
    $stmt->execute();
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Student Promotions";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-blue-600 via-purple-600 to-indigo-600 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Student Promotions</h1>
                                <p class="text-green-100 text-lg">Promote students to next academic year and class</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-graduation-cap text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Promotions</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Year and Class Selection -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-6">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Filter Students for Promotion</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Select academic year and optionally filter by class</p>
                    </div>
                    <div class="p-6">
                        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <!-- Academic Year -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year</label>
                                <select name="year_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Academic Year</option>
                                    <?php foreach ($years as $year): ?>
                                    <option value="<?php echo $year['id']; ?>" <?php echo $year['id'] == $selected_year_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($year['year_name']); ?> - <?php echo ucfirst($year['status']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Class Filter -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Filter by Class (Optional)</label>
                                <select name="class_id" onchange="this.form.submit()"
                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">All Classes</option>
                                    <?php foreach ($classes as $class): ?>
                                    <option value="<?php echo $class['id']; ?>" <?php echo $class['id'] == $selected_class_id ? 'selected' : ''; ?>>
                                        Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Filter Button -->
                            <div class="flex items-end">
                                <button type="submit"
                                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-filter mr-2"></i>Apply Filter
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Promotion Form -->
                <?php if (!empty($students)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Student Promotion List</h3>
                                <p class="text-gray-600 dark:text-gray-400 mt-1">
                                    <?php if ($selected_class_id): ?>
                                        <?php
                                        $current_class = array_filter($classes, function($c) use ($selected_class_id) {
                                            return $c['id'] == $selected_class_id;
                                        });
                                        $current_class = reset($current_class);
                                        ?>
                                        Promoting students from Grade <?php echo htmlspecialchars($current_class['grade_level']); ?> - <?php echo htmlspecialchars($current_class['name']); ?>
                                    <?php else: ?>
                                        Review and promote students to next academic year
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="text-sm text-gray-600 dark:text-gray-400">
                                Total Students: <span class="font-semibold text-gray-900 dark:text-white"><?php echo count($students); ?></span>
                            </div>
                        </div>
                    </div>

                    <form method="POST" class="p-6" id="promotionForm">
                        <input type="hidden" name="action" value="bulk_promote">
                        <input type="hidden" name="from_year_id" value="<?php echo $selected_year_id; ?>">

                        <!-- Promotion Settings -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                            <!-- Target Academic Year -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Promote to Academic Year</label>
                                <select name="to_year_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select Target Academic Year</option>
                                    <?php foreach ($years as $year): ?>
                                        <?php if ($year['id'] != $selected_year_id): ?>
                                        <option value="<?php echo $year['id']; ?>">
                                            <?php echo htmlspecialchars($year['year_name']); ?>
                                        </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Bulk Actions -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Bulk Actions</label>
                                <div class="flex space-x-2">
                                    <button type="button" onclick="selectAll()"
                                        class="flex-1 bg-green-500 hover:bg-green-600 text-white font-medium py-2 px-3 rounded-lg transition-colors duration-200 text-sm">
                                        <i class="fas fa-check-double mr-1"></i>Select All
                                    </button>
                                    <button type="button" onclick="deselectAll()"
                                        class="flex-1 bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-3 rounded-lg transition-colors duration-200 text-sm">
                                        <i class="fas fa-times mr-1"></i>Deselect All
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Students Table -->
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-700">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">
                                            <input type="checkbox" id="selectAllCheckbox" onchange="toggleAll(this)"
                                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                                        </th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Current Class</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Average Score</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Promote to Class</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                    <?php foreach ($students as $student): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="checkbox" name="selected_students[]" value="<?php echo $student['id']; ?>"
                                                class="student-checkbox rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                                                onchange="updateSelectAllCheckbox()">
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="flex items-center">
                                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                    <i class="fas fa-user text-blue-600 dark:text-blue-400"></i>
                                                </div>
                                                <div class="ml-4">
                                                    <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                        <?php echo htmlspecialchars($student['name']); ?>
                                                    </div>
                                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                                        ID: <?php echo htmlspecialchars($student['profile_student_id']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                Grade <?php echo htmlspecialchars($student['grade_level']); ?> - <?php echo htmlspecialchars($student['class_name']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 dark:text-white">
                                                <?php echo number_format($student['average_score'], 1); ?>%
                                                <span class="text-xs text-gray-500 dark:text-gray-400">
                                                    (<?php echo $student['subjects_count']; ?> subjects)
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select name="promotions[<?php echo $student['id']; ?>][to_class_id]"
                                                class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm">
                                                <option value="">Select Class</option>
                                                <?php foreach ($classes as $class): ?>
                                                <option value="<?php echo $class['id']; ?>">
                                                    Grade <?php echo htmlspecialchars($class['grade_level']); ?> - <?php echo htmlspecialchars($class['name']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <select name="promotions[<?php echo $student['id']; ?>][status]"
                                                class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm">
                                                <option value="">Select Status</option>
                                                <option value="promoted">Promoted</option>
                                                <option value="repeated">Repeated</option>
                                                <option value="transferred">Transferred</option>
                                                <option value="graduated">Graduated</option>
                                            </select>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <input type="text" name="promotions[<?php echo $student['id']; ?>][remarks]"
                                                placeholder="Optional remarks"
                                                class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white text-sm w-32">
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700 mt-6">
                            <div class="flex items-center space-x-4">
                                <div class="text-sm text-gray-600 dark:text-gray-400">
                                    Total Students: <span class="font-semibold"><?php echo count($students); ?></span>
                                </div>
                                <div class="text-sm text-blue-600 dark:text-blue-400">
                                    Selected: <span id="selectedCount" class="font-semibold">0</span>
                                </div>
                            </div>
                            <button type="submit" id="promoteButton" disabled
                                class="bg-green-500 hover:bg-green-600 disabled:bg-gray-400 disabled:cursor-not-allowed text-white font-medium py-2 px-6 rounded-lg transition-colors duration-200">
                                <i class="fas fa-graduation-cap mr-2"></i>Process Selected Promotions
                            </button>
                        </div>
                    </form>
                </div>
                <?php elseif ($selected_year_id): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-users text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Students Found</h3>
                        <p class="text-gray-600 dark:text-gray-400">No students found for the selected academic year.</p>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-calendar-alt text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Select Academic Year</h3>
                        <p class="text-gray-600 dark:text-gray-400">Please select an academic year to view students for promotion.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// JavaScript for bulk selection functionality
function selectAll() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    document.getElementById('selectAllCheckbox').checked = true;
    updateSelectedCount();
    updatePromoteButton();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    document.getElementById('selectAllCheckbox').checked = false;
    updateSelectedCount();
    updatePromoteButton();
}

function toggleAll(masterCheckbox) {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = masterCheckbox.checked;
    });
    updateSelectedCount();
    updatePromoteButton();
}

function updateSelectAllCheckbox() {
    const checkboxes = document.querySelectorAll('.student-checkbox');
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    const masterCheckbox = document.getElementById('selectAllCheckbox');

    if (checkedBoxes.length === 0) {
        masterCheckbox.indeterminate = false;
        masterCheckbox.checked = false;
    } else if (checkedBoxes.length === checkboxes.length) {
        masterCheckbox.indeterminate = false;
        masterCheckbox.checked = true;
    } else {
        masterCheckbox.indeterminate = true;
    }

    updateSelectedCount();
    updatePromoteButton();
}

function updateSelectedCount() {
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    document.getElementById('selectedCount').textContent = checkedBoxes.length;
}

function updatePromoteButton() {
    const checkedBoxes = document.querySelectorAll('.student-checkbox:checked');
    const promoteButton = document.getElementById('promoteButton');

    if (checkedBoxes.length > 0) {
        promoteButton.disabled = false;
        promoteButton.classList.remove('disabled:bg-gray-400', 'disabled:cursor-not-allowed');
    } else {
        promoteButton.disabled = true;
        promoteButton.classList.add('disabled:bg-gray-400', 'disabled:cursor-not-allowed');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    updatePromoteButton();

    // Add event listeners to existing checkboxes
    const checkboxes = document.querySelectorAll('.student-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectAllCheckbox);
    });
});
</script>
