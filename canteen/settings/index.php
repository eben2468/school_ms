<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$can_edit = in_array($user_role, ['super_admin', 'school_admin']);

$config_file = __DIR__ . '/canteen_config.json';

// Default settings
$defaults = [
    'operating_hours_start' => '08:00',
    'operating_hours_end' => '17:00',
    'low_stock_threshold' => 10,
    'default_daily_price' => 15.00,
    'default_weekly_price' => 75.00,
    'default_monthly_price' => 300.00,
    'default_term_price' => 800.00,
    'meal_time_breakfast' => '08:00 - 10:00',
    'meal_time_lunch' => '12:00 - 14:00',
    'meal_time_dinner' => '18:00 - 20:00',
    'notifications_enabled' => 'yes'
];

// Load settings
$settings = $defaults;
if (file_exists($config_file)) {
    $loaded = json_decode(file_get_contents($config_file), true);
    if (is_array($loaded)) {
        $settings = array_merge($defaults, $loaded);
    }
}

// Handle settings update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    $updated = [];
    foreach ($defaults as $key => $default_val) {
        if (is_numeric($default_val)) {
            $updated[$key] = filter_input(INPUT_POST, $key, FILTER_VALIDATE_FLOAT);
        } else {
            $updated[$key] = isset($_POST[$key]) ? trim($_POST[$key]) : $default_val;
        }
    }
    
    if (file_put_contents($config_file, json_encode($updated, JSON_PRETTY_PRINT))) {
        $settings = $updated;
        $success_message = "Canteen settings updated successfully!";
    } else {
        $error_message = "Failed to write configuration file.";
    }
}

$title = "Canteen Settings";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Canteen', 'url' => '../index.php'],
    ['title' => 'Settings']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                <!-- Header Section -->
                <div class="flex justify-between items-center mb-6">
                    <div>
                        <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Canteen Settings</h1>
                        <p class="text-gray-500 dark:text-gray-400 mt-1">Configure operating hours, pricing plans, and notifications</p>
                    </div>
                    <a href="../index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors flex items-center">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Canteen
                    </a>
                </div>

                <!-- Navigation breadcrumb -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">Settings</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Warning banner for Canteen Manager -->
                <?php if (!$can_edit): ?>
                <div class="bg-yellow-50 border border-yellow-200 text-yellow-800 p-4 rounded-lg mb-6 flex items-start">
                    <i class="fas fa-info-circle mr-3 mt-1 text-yellow-600"></i>
                    <div>
                        <span class="font-semibold">View-Only Access:</span> As a Canteen Manager, you have permission to view settings. Modifying these configurations requires School Administrator or Super Administrator privileges.
                    </div>
                </div>
                <?php endif; ?>

                <!-- Form Section -->
                <form method="POST" class="space-y-6">
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Operational configuration -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4 border-b pb-2 flex items-center">
                                <i class="fas fa-clock mr-2 text-blue-500"></i>Operations & Hours
                            </h3>
                            <div class="space-y-4">
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label for="operating_hours_start" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Operating From</label>
                                        <input type="time" id="operating_hours_start" name="operating_hours_start" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                            value="<?php echo htmlspecialchars($settings['operating_hours_start']); ?>"
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                    </div>
                                    <div>
                                        <label for="operating_hours_end" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Operating Until</label>
                                        <input type="time" id="operating_hours_end" name="operating_hours_end" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                            value="<?php echo htmlspecialchars($settings['operating_hours_end']); ?>"
                                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                    </div>
                                </div>

                                <div>
                                    <label for="low_stock_threshold" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Low Stock Warning Threshold</label>
                                    <input type="number" id="low_stock_threshold" name="low_stock_threshold" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                        value="<?php echo htmlspecialchars($settings['low_stock_threshold']); ?>"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                    <span class="text-xs text-gray-500">Items with stock equal or lower than this will trigger warning status.</span>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Meal Schedules</label>
                                    <div class="space-y-2">
                                        <div class="flex items-center space-x-2">
                                            <span class="w-24 text-xs font-semibold text-gray-550 dark:text-gray-400">Breakfast:</span>
                                            <input type="text" name="meal_time_breakfast" <?php echo !$can_edit ? 'disabled' : ''; ?> value="<?php echo htmlspecialchars($settings['meal_time_breakfast']); ?>" class="flex-grow px-2 py-1 text-sm border rounded dark:bg-gray-700 dark:text-white">
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="w-24 text-xs font-semibold text-gray-550 dark:text-gray-400">Lunch:</span>
                                            <input type="text" name="meal_time_lunch" <?php echo !$can_edit ? 'disabled' : ''; ?> value="<?php echo htmlspecialchars($settings['meal_time_lunch']); ?>" class="flex-grow px-2 py-1 text-sm border rounded dark:bg-gray-700 dark:text-white">
                                        </div>
                                        <div class="flex items-center space-x-2">
                                            <span class="w-24 text-xs font-semibold text-gray-550 dark:text-gray-400">Dinner:</span>
                                            <input type="text" name="meal_time_dinner" <?php echo !$can_edit ? 'disabled' : ''; ?> value="<?php echo htmlspecialchars($settings['meal_time_dinner']); ?>" class="flex-grow px-2 py-1 text-sm border rounded dark:bg-gray-700 dark:text-white">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Pricing Configuration -->
                        <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4 border-b pb-2 flex items-center">
                                <i class="fas fa-wallet mr-2 text-green-500"></i>Standard Meal Plan Pricing
                            </h3>
                            <div class="space-y-4">
                                <div>
                                    <label for="default_daily_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Daily Meal Plan (₵)</label>
                                    <input type="number" id="default_daily_price" name="default_daily_price" step="0.01" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                        value="<?php echo htmlspecialchars($settings['default_daily_price']); ?>"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label for="default_weekly_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Weekly Meal Plan (₵)</label>
                                    <input type="number" id="default_weekly_price" name="default_weekly_price" step="0.01" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                        value="<?php echo htmlspecialchars($settings['default_weekly_price']); ?>"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label for="default_monthly_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Monthly Meal Plan (₵)</label>
                                    <input type="number" id="default_monthly_price" name="default_monthly_price" step="0.01" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                        value="<?php echo htmlspecialchars($settings['default_monthly_price']); ?>"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                </div>
                                <div>
                                    <label for="default_term_price" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Term-based Meal Plan (₵)</label>
                                    <input type="number" id="default_term_price" name="default_term_price" step="0.01" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                        value="<?php echo htmlspecialchars($settings['default_term_price']); ?>"
                                        class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- System preferences -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow p-6 border border-gray-200 dark:border-gray-700">
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-4 border-b pb-2 flex items-center">
                            <i class="fas fa-sliders-h mr-2 text-indigo-500"></i>Preferences & Alerts
                        </h3>
                        <div>
                            <label for="notifications_enabled" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Enable Low Stock Notifications</label>
                            <select id="notifications_enabled" name="notifications_enabled" <?php echo !$can_edit ? 'disabled' : ''; ?>
                                class="w-full md:w-64 px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg dark:bg-gray-700 dark:text-white">
                                <option value="yes" <?php echo $settings['notifications_enabled'] === 'yes' ? 'selected' : ''; ?>>Yes (Alert on dashboard)</option>
                                <option value="no" <?php echo $settings['notifications_enabled'] === 'no' ? 'selected' : ''; ?>>No</option>
                            </select>
                        </div>
                    </div>

                    <!-- Submit action -->
                    <?php if ($can_edit): ?>
                    <div class="flex justify-end pt-4">
                        <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white px-8 py-3 rounded-lg font-medium shadow-lg hover:shadow-xl transition-all flex items-center">
                            <i class="fas fa-save mr-2"></i>Save Configuration
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>