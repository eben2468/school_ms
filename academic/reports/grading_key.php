<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: index.php?error=Unauthorized access");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$success_message = '';
$error_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
    
    if ($action === 'add' || $action === 'edit') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        $min_score = filter_input(INPUT_POST, 'min_score', FILTER_VALIDATE_FLOAT);
        $max_score = filter_input(INPUT_POST, 'max_score', FILTER_VALIDATE_FLOAT);
        $grade = filter_input(INPUT_POST, 'grade', FILTER_SANITIZE_STRING);
        $grade_point = filter_input(INPUT_POST, 'grade_point', FILTER_VALIDATE_FLOAT);
        $interpretation = filter_input(INPUT_POST, 'interpretation', FILTER_SANITIZE_STRING);
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if ($min_score === false || $max_score === false || empty($grade)) {
            $error_message = "Please enter valid minimum score, maximum score, and letter grade.";
        } elseif ($min_score < 0 || $max_score > 100 || $min_score > $max_score) {
            $error_message = "Scores must be between 0 and 100, and minimum must be less than or equal to maximum.";
        } else {
            try {
                if ($action === 'add') {
                    $sql = "INSERT INTO grading_scales (min_score, max_score, grade, grade_point, interpretation, is_active) 
                            VALUES (:min_score, :max_score, :grade, :grade_point, :interpretation, :is_active)";
                    $stmt = $db->prepare($sql);
                } else {
                    $sql = "UPDATE grading_scales SET 
                                min_score = :min_score, 
                                max_score = :max_score, 
                                grade = :grade, 
                                grade_point = :grade_point, 
                                interpretation = :interpretation, 
                                is_active = :is_active 
                            WHERE id = :id";
                    $stmt = $db->prepare($sql);
                    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                }
                
                $stmt->bindParam(':min_score', $min_score);
                $stmt->bindParam(':max_score', $max_score);
                $stmt->bindParam(':grade', $grade);
                $stmt->bindValue(':grade_point', $grade_point === false ? null : $grade_point, $grade_point === false ? PDO::PARAM_NULL : PDO::PARAM_STR);
                $stmt->bindParam(':interpretation', $interpretation);
                $stmt->bindParam(':is_active', $is_active, PDO::PARAM_INT);
                
                if ($stmt->execute()) {
                    $success_message = $action === 'add' ? "Grading range added successfully!" : "Grading range updated successfully!";
                } else {
                    $error_message = "Failed to save the grading range.";
                }
            } catch (PDOException $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        if ($id) {
            try {
                $stmt = $db->prepare("DELETE FROM grading_scales WHERE id = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $success_message = "Grading scale deleted successfully!";
                } else {
                    $error_message = "Failed to delete the grading scale.";
                }
            } catch (PDOException $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'toggle') {
        $id = filter_input(INPUT_POST, 'id', FILTER_SANITIZE_NUMBER_INT);
        if ($id) {
            try {
                $stmt = $db->prepare("UPDATE grading_scales SET is_active = 1 - is_active WHERE id = :id");
                $stmt->bindParam(':id', $id, PDO::PARAM_INT);
                if ($stmt->execute()) {
                    $success_message = "Status toggled successfully!";
                } else {
                    $error_message = "Failed to toggle status.";
                }
            } catch (PDOException $e) {
                $error_message = "Error: " . $e->getMessage();
            }
        }
    }
}

// Fetch all grading scales
try {
    $scales_sql = "SELECT * FROM grading_scales ORDER BY min_score DESC";
    $scales = $db->query($scales_sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If grading_scales table doesn't exist, redirect to setup
    if ($e->getCode() == '42S02') {
        header("Location: ../../fix_missing_grading_scales.php");
        exit();
    }
    $scales = [];
}

// If editing, fetch the specific scale range details
$edit_scale = null;
$edit_id = filter_input(INPUT_GET, 'edit_id', FILTER_SANITIZE_NUMBER_INT);
if ($edit_id) {
    try {
        $edit_stmt = $db->prepare("SELECT * FROM grading_scales WHERE id = :id");
        $edit_stmt->bindParam(':id', $edit_id, PDO::PARAM_INT);
        $edit_stmt->execute();
        $edit_scale = $edit_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $edit_scale = null;
    }
}

$title = "Grading Key Settings";
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Term Reports</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Grading Key Settings</span>
                </div>

                <!-- Page Header -->
                <div class="mb-8 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                    <div>
                        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">Grading Key Settings</h1>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Configure mark ranges, grades, grade points, and remarks for report card compilation.</p>
                    </div>
                    <div>
                        <a href="index.php" class="px-4 py-2 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-lg text-sm transition hover:bg-gray-50 dark:hover:bg-gray-800 flex items-center">
                            <i class="fas fa-arrow-left mr-2"></i> Back to Term Reports
                        </a>
                    </div>
                </div>

                <!-- Notification Alerts -->
                <?php if ($success_message): ?>
                <div class="mb-6 p-4 bg-emerald-100 border-l-4 border-emerald-500 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-400 rounded flex items-center shadow-sm">
                    <i class="fas fa-check-circle mr-3 text-lg"></i>
                    <div><?php echo htmlspecialchars($success_message); ?></div>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="mb-6 p-4 bg-red-100 border-l-4 border-red-500 text-red-800 dark:bg-red-950/30 dark:text-red-400 rounded flex items-center shadow-sm">
                    <i class="fas fa-exclamation-circle mr-3 text-lg"></i>
                    <div><?php echo htmlspecialchars($error_message); ?></div>
                </div>
                <?php endif; ?>

                <!-- Content Columns -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                    <!-- List Table Column (Left) -->
                    <div class="lg:col-span-2 space-y-6">
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 overflow-hidden">
                            <div class="p-6 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Active Grading Key Scales</h3>
                                <span class="bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300 text-xs font-bold px-2.5 py-1 rounded-full">
                                    <?php echo count($scales); ?> Ranges Defined
                                </span>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                    <thead class="bg-gray-50 dark:bg-gray-700/50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Range</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Grade</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">GPA Value</th>
                                            <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Interpretation</th>
                                            <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                            <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 dark:text-gray-300 uppercase tracking-wider">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-800">
                                        <?php if (empty($scales)): ?>
                                        <tr>
                                            <td colspan="6" class="px-6 py-10 text-center text-gray-500 dark:text-gray-400">
                                                No grading scales set up. Add your first range using the sidebar form!
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($scales as $row): ?>
                                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                                    <?php echo number_format($row['min_score'], 1); ?>% – <?php echo number_format($row['max_score'], 1); ?>%
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <?php
                                                        $grade = $row['grade'];
                                                        $colorClass = 'bg-gray-100 text-gray-800 dark:bg-gray-900/30 dark:text-gray-400';
                                                        if (strpos($grade, 'A') === 0) $colorClass = 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300';
                                                        elseif (strpos($grade, 'B') === 0) $colorClass = 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300';
                                                        elseif (strpos($grade, 'C') === 0) $colorClass = 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300';
                                                        elseif (strpos($grade, 'D') === 0 || strpos($grade, 'E') === 0) $colorClass = 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300';
                                                        elseif (strpos($grade, 'F') === 0) $colorClass = 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300';
                                                    ?>
                                                    <span class="inline-flex px-2.5 py-0.5 rounded text-xs font-bold <?php echo $colorClass; ?>">
                                                        <?php echo htmlspecialchars($grade); ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-gray-900 dark:text-white">
                                                    <?php echo $row['grade_point'] !== null ? number_format($row['grade_point'], 1) : 'N/A'; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600 dark:text-gray-300">
                                                    <?php echo htmlspecialchars($row['interpretation'] ?? 'N/A'); ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-center text-sm">
                                                    <form method="POST" class="inline">
                                                        <input type="hidden" name="action" value="toggle">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-bold transition cursor-pointer <?php echo $row['is_active'] ? 'bg-green-100 text-green-800 hover:bg-green-200 dark:bg-green-950/30 dark:text-green-400' : 'bg-red-100 text-red-800 hover:bg-red-200 dark:bg-red-950/30 dark:text-red-400'; ?>">
                                                            <?php echo $row['is_active'] ? 'Active' : 'Disabled'; ?>
                                                        </button>
                                                    </form>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium space-x-2">
                                                    <a href="grading_key.php?edit_id=<?php echo $row['id']; ?>" class="text-indigo-600 hover:text-indigo-900 dark:text-indigo-400 dark:hover:text-indigo-200 transition">
                                                        <i class="fas fa-edit"></i> Edit
                                                    </a>
                                                    <form method="POST" class="inline" onsubmit="return confirm('Are you sure you want to delete this grading range?');">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                                        <button type="submit" class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-200 transition cursor-pointer bg-transparent border-0 p-0">
                                                            <i class="fas fa-trash-alt"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Sidebar Panel Form Column (Right) -->
                    <div>
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow border border-gray-200 dark:border-gray-700 p-6 sticky top-24">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4">
                                <?php echo $edit_scale ? 'Edit Grading Range' : 'Add New Grading Range'; ?>
                            </h3>
                            
                            <form method="POST" class="space-y-5">
                                <input type="hidden" name="action" value="<?php echo $edit_scale ? 'edit' : 'add'; ?>">
                                <?php if ($edit_scale): ?>
                                <input type="hidden" name="id" value="<?php echo $edit_scale['id']; ?>">
                                <?php endif; ?>

                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="min_score" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Min Mark (%)</label>
                                        <input type="number" step="0.01" min="0" max="100" id="min_score" name="min_score" required
                                            value="<?php echo htmlspecialchars($edit_scale['min_score'] ?? ''); ?>"
                                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <div>
                                        <label for="max_score" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Max Mark (%)</label>
                                        <input type="number" step="0.01" min="0" max="100" id="max_score" name="max_score" required
                                            value="<?php echo htmlspecialchars($edit_scale['max_score'] ?? ''); ?>"
                                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>

                                <div>
                                    <label for="grade" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Letter Grade</label>
                                    <input type="text" id="grade" name="grade" required placeholder="e.g. A1, B2, F"
                                        value="<?php echo htmlspecialchars($edit_scale['grade'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="grade_point" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Grade Point / GPA Value</label>
                                    <input type="number" step="0.1" min="0" max="10" id="grade_point" name="grade_point" placeholder="e.g. 4.0, 3.5"
                                        value="<?php echo htmlspecialchars($edit_scale['grade_point'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="interpretation" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Interpretation</label>
                                    <input type="text" id="interpretation" name="interpretation" placeholder="e.g. Excellent, Very Good"
                                        value="<?php echo htmlspecialchars($edit_scale['interpretation'] ?? ''); ?>"
                                        class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-transparent dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="flex items-center">
                                    <input type="checkbox" id="is_active" name="is_active" value="1"
                                        <?php echo (!isset($edit_scale['is_active']) || $edit_scale['is_active']) ? 'checked' : ''; ?>
                                        class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded cursor-pointer">
                                    <label for="is_active" class="ml-2 block text-sm text-gray-900 dark:text-gray-300 cursor-pointer">
                                        Mark as Active (Used in Compilation)
                                    </label>
                                </div>

                                <div class="flex gap-3 pt-2">
                                    <?php if ($edit_scale): ?>
                                    <a href="grading_key.php" class="w-1/2 text-center py-2.5 border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-semibold rounded-lg text-sm transition hover:bg-gray-50 dark:hover:bg-gray-800">
                                        Cancel
                                    </a>
                                    <?php endif; ?>
                                    <button type="submit" class="<?php echo $edit_scale ? 'w-1/2' : 'w-full'; ?> py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg text-sm transition duration-200">
                                        <i class="fas fa-save mr-2"></i><?php echo $edit_scale ? 'Update Scale' : 'Add Range'; ?>
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div>
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
