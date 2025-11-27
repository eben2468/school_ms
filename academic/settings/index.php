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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'switch_term':
                $new_term_id = filter_input(INPUT_POST, 'new_term_id', FILTER_SANITIZE_NUMBER_INT);
                try {
                    // Update current term
                    $update_sql = "UPDATE academic_settings SET setting_value = :term_id WHERE setting_key = 'current_academic_term_id'";
                    $stmt = $db->prepare($update_sql);
                    $stmt->bindParam(':term_id', $new_term_id);
                    $stmt->execute();
                    
                    // Update term statuses
                    $db->exec("UPDATE academic_terms SET status = 'completed' WHERE status = 'active'");
                    $update_term = "UPDATE academic_terms SET status = 'active' WHERE id = :term_id";
                    $stmt = $db->prepare($update_term);
                    $stmt->bindParam(':term_id', $new_term_id);
                    $stmt->execute();
                    
                    $success_message = "Academic term switched successfully!";
                } catch (PDOException $e) {
                    $error_message = "Error switching term: " . $e->getMessage();
                }
                break;
                
            case 'switch_year':
                $new_year_id = filter_input(INPUT_POST, 'new_year_id', FILTER_SANITIZE_NUMBER_INT);
                try {
                    // Update current year
                    $update_sql = "UPDATE academic_settings SET setting_value = :year_id WHERE setting_key = 'current_academic_year_id'";
                    $stmt = $db->prepare($update_sql);
                    $stmt->bindParam(':year_id', $new_year_id);
                    $stmt->execute();
                    
                    // Update year statuses
                    $db->exec("UPDATE academic_years SET status = 'completed' WHERE status = 'active'");
                    $update_year = "UPDATE academic_years SET status = 'active' WHERE id = :year_id";
                    $stmt = $db->prepare($update_year);
                    $stmt->bindParam(':year_id', $new_year_id);
                    $stmt->execute();
                    
                    // Set first term of new year as active
                    $first_term_sql = "SELECT id FROM academic_terms WHERE academic_year_id = :year_id AND term_number = '1'";
                    $stmt = $db->prepare($first_term_sql);
                    $stmt->bindParam(':year_id', $new_year_id);
                    $stmt->execute();
                    $first_term = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($first_term) {
                        $db->exec("UPDATE academic_terms SET status = 'upcoming' WHERE status = 'active'");
                        $update_term = "UPDATE academic_terms SET status = 'active' WHERE id = :term_id";
                        $stmt = $db->prepare($update_term);
                        $stmt->bindParam(':term_id', $first_term['id']);
                        $stmt->execute();
                        
                        $update_current_term = "UPDATE academic_settings SET setting_value = :term_id WHERE setting_key = 'current_academic_term_id'";
                        $stmt = $db->prepare($update_current_term);
                        $stmt->bindParam(':term_id', $first_term['id']);
                        $stmt->execute();
                    }
                    
                    $success_message = "Academic year switched successfully!";
                } catch (PDOException $e) {
                    $error_message = "Error switching year: " . $e->getMessage();
                }
                break;
                
            case 'create_year':
                $year_name = filter_input(INPUT_POST, 'year_name', FILTER_SANITIZE_STRING);
                $start_date = filter_input(INPUT_POST, 'start_date', FILTER_SANITIZE_STRING);
                $end_date = filter_input(INPUT_POST, 'end_date', FILTER_SANITIZE_STRING);

                // Get term dates
                $term1_start = filter_input(INPUT_POST, 'term1_start', FILTER_SANITIZE_STRING);
                $term1_end = filter_input(INPUT_POST, 'term1_end', FILTER_SANITIZE_STRING);
                $term2_start = filter_input(INPUT_POST, 'term2_start', FILTER_SANITIZE_STRING);
                $term2_end = filter_input(INPUT_POST, 'term2_end', FILTER_SANITIZE_STRING);
                $term3_start = filter_input(INPUT_POST, 'term3_start', FILTER_SANITIZE_STRING);
                $term3_end = filter_input(INPUT_POST, 'term3_end', FILTER_SANITIZE_STRING);

                try {
                    $db->beginTransaction();

                    // Create academic year
                    $insert_year = "INSERT INTO academic_years (year_name, start_date, end_date, status) VALUES (:year_name, :start_date, :end_date, 'upcoming')";
                    $stmt = $db->prepare($insert_year);
                    $stmt->bindParam(':year_name', $year_name);
                    $stmt->bindParam(':start_date', $start_date);
                    $stmt->bindParam(':end_date', $end_date);
                    $stmt->execute();

                    $year_id = $db->lastInsertId();

                    // Create three terms with manual dates
                    $terms = [
                        ['1', 'First Term', $term1_start, $term1_end],
                        ['2', 'Second Term', $term2_start, $term2_end],
                        ['3', 'Third Term', $term3_start, $term3_end]
                    ];

                    foreach ($terms as $term) {
                        $insert_term = "INSERT INTO academic_terms (academic_year_id, term_number, term_name, start_date, end_date, status)
                                       VALUES (:year_id, :term_number, :term_name, :start_date, :end_date, 'upcoming')";
                        $stmt = $db->prepare($insert_term);
                        $stmt->bindParam(':year_id', $year_id);
                        $stmt->bindParam(':term_number', $term[0]);
                        $stmt->bindParam(':term_name', $term[1]);
                        $stmt->bindParam(':start_date', $term[2]);
                        $stmt->bindParam(':end_date', $term[3]);
                        $stmt->execute();
                    }

                    $db->commit();
                    $success_message = "Academic year '$year_name' created successfully with three terms!";
                } catch (PDOException $e) {
                    $db->rollBack();
                    $error_message = "Error creating academic year: " . $e->getMessage();
                }
                break;

            case 'update_term_dates':
                $term_id = filter_input(INPUT_POST, 'term_id', FILTER_SANITIZE_NUMBER_INT);
                $new_start_date = filter_input(INPUT_POST, 'new_start_date', FILTER_SANITIZE_STRING);
                $new_end_date = filter_input(INPUT_POST, 'new_end_date', FILTER_SANITIZE_STRING);

                try {
                    $update_term = "UPDATE academic_terms SET start_date = :start_date, end_date = :end_date WHERE id = :term_id";
                    $stmt = $db->prepare($update_term);
                    $stmt->bindParam(':start_date', $new_start_date);
                    $stmt->bindParam(':end_date', $new_end_date);
                    $stmt->bindParam(':term_id', $term_id);
                    $stmt->execute();

                    $success_message = "Term dates updated successfully!";
                } catch (PDOException $e) {
                    $error_message = "Error updating term dates: " . $e->getMessage();
                }
                break;
        }
    }
}

// Get current academic year and term
$current_settings_sql = "SELECT 
    (SELECT setting_value FROM academic_settings WHERE setting_key = 'current_academic_year_id') as current_year_id,
    (SELECT setting_value FROM academic_settings WHERE setting_key = 'current_academic_term_id') as current_term_id";
$current_settings = $db->query($current_settings_sql)->fetch(PDO::FETCH_ASSOC);

// Get current academic year details
$current_year = null;
if ($current_settings['current_year_id']) {
    $year_sql = "SELECT * FROM academic_years WHERE id = :id";
    $stmt = $db->prepare($year_sql);
    $stmt->bindParam(':id', $current_settings['current_year_id']);
    $stmt->execute();
    $current_year = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get current term details
$current_term = null;
if ($current_settings['current_term_id']) {
    $term_sql = "SELECT * FROM academic_terms WHERE id = :id";
    $stmt = $db->prepare($term_sql);
    $stmt->bindParam(':id', $current_settings['current_term_id']);
    $stmt->execute();
    $current_term = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all academic years
$years_sql = "SELECT * FROM academic_years ORDER BY year_name DESC";
$years = $db->query($years_sql)->fetchAll(PDO::FETCH_ASSOC);

// Get terms for current year
$terms = [];
if ($current_year) {
    $terms_sql = "SELECT * FROM academic_terms WHERE academic_year_id = :year_id ORDER BY term_number";
    $stmt = $db->prepare($terms_sql);
    $stmt->bindParam(':year_id', $current_year['id']);
    $stmt->execute();
    $terms = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$title = "Academic Settings";
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
                                <h1 class="text-3xl font-bold mb-2">Academic Settings</h1>
                                <p class="text-indigo-100 text-lg">Manage academic years, terms, and system settings</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-cogs text-6xl text-white/80"></i>
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
                    <span class="text-gray-900 dark:text-white font-medium">Settings</span>
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

                <!-- Current Status -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <!-- Current Academic Year -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current Academic Year</h3>
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-calendar-alt text-blue-600 dark:text-blue-400"></i>
                                </div>
                            </div>
                            <?php if ($current_year): ?>
                            <div class="space-y-2">
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($current_year['year_name']); ?></p>
                                <p class="text-gray-600 dark:text-gray-400">
                                    <?php echo date('M j, Y', strtotime($current_year['start_date'])); ?> -
                                    <?php echo date('M j, Y', strtotime($current_year['end_date'])); ?>
                                </p>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                                    <?php echo $current_year['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                    <?php echo ucfirst($current_year['status']); ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400">No academic year set</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Current Term -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6">
                            <div class="flex items-center justify-between mb-4">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Current Term</h3>
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-clock text-green-600 dark:text-green-400"></i>
                                </div>
                            </div>
                            <?php if ($current_term): ?>
                            <div class="space-y-2">
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($current_term['term_name']); ?></p>
                                <p class="text-gray-600 dark:text-gray-400">
                                    <?php echo date('M j, Y', strtotime($current_term['start_date'])); ?> -
                                    <?php echo date('M j, Y', strtotime($current_term['end_date'])); ?>
                                </p>
                                <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full
                                    <?php echo $current_term['status'] === 'active' ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?>">
                                    <?php echo ucfirst($current_term['status']); ?>
                                </span>
                            </div>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400">No term set</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Management Sections -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- Term Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Term Management</h3>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Switch between academic terms</p>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($terms)): ?>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="switch_term">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Term</label>
                                    <select name="new_term_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <?php foreach ($terms as $term): ?>
                                        <option value="<?php echo $term['id']; ?>" <?php echo $term['id'] == $current_settings['current_term_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($term['term_name']); ?>
                                            (<?php echo date('M j', strtotime($term['start_date'])); ?> - <?php echo date('M j, Y', strtotime($term['end_date'])); ?>)
                                            - <?php echo ucfirst($term['status']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-exchange-alt mr-2"></i>Switch Term
                                </button>
                            </form>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400">No terms available for current academic year</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Year Management -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Year Management</h3>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Switch between academic years</p>
                        </div>
                        <div class="p-6">
                            <?php if (!empty($years)): ?>
                            <form method="POST" class="space-y-4">
                                <input type="hidden" name="action" value="switch_year">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Academic Year</label>
                                    <select name="new_year_id" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        <?php foreach ($years as $year): ?>
                                        <option value="<?php echo $year['id']; ?>" <?php echo $year['id'] == $current_settings['current_year_id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($year['year_name']); ?> - <?php echo ucfirst($year['status']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <button type="submit" class="w-full bg-green-500 hover:bg-green-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-calendar-check mr-2"></i>Switch Year
                                </button>
                            </form>
                            <?php else: ?>
                            <p class="text-gray-500 dark:text-gray-400">No academic years available</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Edit Term Dates -->
                <?php if (!empty($terms)): ?>
                <div class="mt-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Edit Term Dates</h3>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Modify start and end dates for existing terms</p>
                        </div>
                        <div class="p-6">
                            <?php foreach ($terms as $term): ?>
                            <div class="mb-6 p-4 border border-gray-200 dark:border-gray-700 rounded-lg">
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="action" value="update_term_dates">
                                    <input type="hidden" name="term_id" value="<?php echo $term['id']; ?>">

                                    <h4 class="text-lg font-medium text-gray-900 dark:text-white mb-3">
                                        <?php echo htmlspecialchars($term['term_name']); ?>
                                        <span class="text-sm text-gray-500 dark:text-gray-400 ml-2">(<?php echo ucfirst($term['status']); ?>)</span>
                                    </h4>

                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                                            <input type="date" name="new_start_date" required
                                                value="<?php echo $term['start_date']; ?>"
                                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Date</label>
                                            <input type="date" name="new_end_date" required
                                                value="<?php echo $term['end_date']; ?>"
                                                class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                        </div>
                                    </div>

                                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-medium py-2 px-4 rounded-lg transition-colors duration-200">
                                        <i class="fas fa-save mr-2"></i>Update <?php echo htmlspecialchars($term['term_name']); ?> Dates
                                    </button>
                                </form>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create New Academic Year -->
                <div class="mt-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Create New Academic Year</h3>
                            <p class="text-gray-600 dark:text-gray-400 mt-1">Add a new academic year with three terms</p>
                        </div>
                        <div class="p-6">
                            <form method="POST" class="space-y-6">
                                <input type="hidden" name="action" value="create_year">

                                <!-- Academic Year Basic Info -->
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year Name</label>
                                        <input type="text" name="year_name" required placeholder="e.g., 2025-2026" pattern="\d{4}-\d{4}"
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year Start</label>
                                        <input type="date" name="start_date" required
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Academic Year End</label>
                                        <input type="date" name="end_date" required
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>

                                <!-- Term Dates -->
                                <div class="border-t border-gray-200 dark:border-gray-700 pt-6">
                                    <h4 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Term Dates</h4>

                                    <!-- First Term -->
                                    <div class="mb-6">
                                        <h5 class="text-md font-medium text-gray-700 dark:text-gray-300 mb-3">First Term</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                                                <input type="date" name="term1_start" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Date</label>
                                                <input type="date" name="term1_end" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Second Term -->
                                    <div class="mb-6">
                                        <h5 class="text-md font-medium text-gray-700 dark:text-gray-300 mb-3">Second Term</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                                                <input type="date" name="term2_start" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Date</label>
                                                <input type="date" name="term2_end" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Third Term -->
                                    <div class="mb-6">
                                        <h5 class="text-md font-medium text-gray-700 dark:text-gray-300 mb-3">Third Term</h5>
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Start Date</label>
                                                <input type="date" name="term3_start" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">End Date</label>
                                                <input type="date" name="term3_end" required
                                                    class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="bg-purple-500 hover:bg-purple-600 text-white font-medium py-2 px-6 rounded-lg transition-colors duration-200">
                                    <i class="fas fa-plus mr-2"></i>Create Academic Year with Terms
                                </button>
                            </form>
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
