<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic Information
    $school_name = filter_input(INPUT_POST, 'school_name', FILTER_SANITIZE_STRING);
    $school_address = filter_input(INPUT_POST, 'school_address', FILTER_SANITIZE_STRING);
    $school_phone = filter_input(INPUT_POST, 'school_phone', FILTER_SANITIZE_STRING);
    $school_email = filter_input(INPUT_POST, 'school_email', FILTER_SANITIZE_EMAIL);
    $school_website = filter_input(INPUT_POST, 'school_website', FILTER_SANITIZE_URL);
    $principal_name = filter_input(INPUT_POST, 'principal_name', FILTER_SANITIZE_STRING);

    // Academic Settings
    $academic_year_start = filter_input(INPUT_POST, 'academic_year_start', FILTER_SANITIZE_STRING);
    $academic_year_end = filter_input(INPUT_POST, 'academic_year_end', FILTER_SANITIZE_STRING);
    $currency = filter_input(INPUT_POST, 'currency', FILTER_SANITIZE_STRING);
    $timezone = filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING);
    $terms_per_year = filter_input(INPUT_POST, 'terms_per_year', FILTER_SANITIZE_STRING);
    $grading_system = filter_input(INPUT_POST, 'grading_system', FILTER_SANITIZE_STRING);

    // System Appearance
    $theme_color = filter_input(INPUT_POST, 'theme_color', FILTER_SANITIZE_STRING);
    $default_language = filter_input(INPUT_POST, 'default_language', FILTER_SANITIZE_STRING);
    $date_format = filter_input(INPUT_POST, 'date_format', FILTER_SANITIZE_STRING);

    // Communication Settings
    $sms_gateway = filter_input(INPUT_POST, 'sms_gateway', FILTER_SANITIZE_STRING);
    $email_notifications = filter_input(INPUT_POST, 'email_notifications', FILTER_SANITIZE_STRING);
    $parent_portal = filter_input(INPUT_POST, 'parent_portal', FILTER_SANITIZE_STRING);
    $student_portal = filter_input(INPUT_POST, 'student_portal', FILTER_SANITIZE_STRING);

    // Additional Settings
    $maintenance_mode = filter_input(INPUT_POST, 'maintenance_mode', FILTER_SANITIZE_STRING);
    $registration_enabled = filter_input(INPUT_POST, 'registration_enabled', FILTER_SANITIZE_STRING);
    $max_file_upload_size = filter_input(INPUT_POST, 'max_file_upload_size', FILTER_SANITIZE_STRING);
    $session_timeout = filter_input(INPUT_POST, 'session_timeout', FILTER_SANITIZE_STRING);
    $backup_frequency = filter_input(INPUT_POST, 'backup_frequency', FILTER_SANITIZE_STRING);
    $auto_backup = filter_input(INPUT_POST, 'auto_backup', FILTER_SANITIZE_STRING);
    $time_format = filter_input(INPUT_POST, 'time_format', FILTER_SANITIZE_STRING);

    if ($school_name && $school_address && $school_phone && $school_email) {
        try {
            // Check if settings exist
            $check_query = "SELECT COUNT(*) FROM school_settings";
            $check_stmt = $db->query($check_query);
            $settings_exist = $check_stmt->fetchColumn() > 0;

            // Check which columns exist in the table
            $columns_query = "SHOW COLUMNS FROM school_settings";
            $columns_stmt = $db->query($columns_query);
            $existing_columns = [];
            while ($column = $columns_stmt->fetch(PDO::FETCH_ASSOC)) {
                $existing_columns[] = $column['Field'];
            }

            // Build query based on existing columns
            $basic_fields = [
                'school_name', 'school_address', 'school_phone', 'school_email',
                'school_website', 'principal_name', 'academic_year_start',
                'academic_year_end', 'currency', 'timezone'
            ];

            $extended_fields = [
                'terms_per_year', 'grading_system', 'theme_color', 'default_language',
                'date_format', 'time_format', 'sms_gateway', 'email_notifications', 'parent_portal', 'student_portal',
                'maintenance_mode', 'registration_enabled', 'max_file_upload_size', 'session_timeout',
                'backup_frequency', 'auto_backup'
            ];

            // Only include fields that exist in the database
            $update_fields = [];
            $insert_fields = [];
            $insert_values = [];

            foreach ($basic_fields as $field) {
                if (in_array($field, $existing_columns)) {
                    $update_fields[] = "$field = :$field";
                    $insert_fields[] = $field;
                    $insert_values[] = ":$field";
                }
            }

            foreach ($extended_fields as $field) {
                if (in_array($field, $existing_columns)) {
                    $update_fields[] = "$field = :$field";
                    $insert_fields[] = $field;
                    $insert_values[] = ":$field";
                }
            }

            if ($settings_exist) {
                // Update existing settings
                $query = "UPDATE school_settings SET " . implode(', ', $update_fields);
                if (in_array('updated_at', $existing_columns)) {
                    $query .= ", updated_at = CURRENT_TIMESTAMP";
                }
            } else {
                // Insert new settings
                $query = "INSERT INTO school_settings (" . implode(', ', $insert_fields) . ")
                         VALUES (" . implode(', ', $insert_values) . ")";
            }

            $stmt = $db->prepare($query);

            // Bind parameters only for existing columns
            $all_params = [
                'school_name' => $school_name,
                'school_address' => $school_address,
                'school_phone' => $school_phone,
                'school_email' => $school_email,
                'school_website' => $school_website,
                'principal_name' => $principal_name,
                'academic_year_start' => $academic_year_start,
                'academic_year_end' => $academic_year_end,
                'currency' => $currency,
                'timezone' => $timezone,
                'terms_per_year' => $terms_per_year,
                'grading_system' => $grading_system,
                'theme_color' => $theme_color,
                'default_language' => $default_language,
                'date_format' => $date_format,
                'sms_gateway' => $sms_gateway,
                'email_notifications' => $email_notifications,
                'parent_portal' => $parent_portal,
                'student_portal' => $student_portal,
                'time_format' => $time_format,
                'maintenance_mode' => $maintenance_mode,
                'registration_enabled' => $registration_enabled,
                'max_file_upload_size' => $max_file_upload_size,
                'session_timeout' => $session_timeout,
                'backup_frequency' => $backup_frequency,
                'auto_backup' => $auto_backup
            ];

            foreach ($all_params as $param => $value) {
                if (in_array($param, $existing_columns)) {
                    $stmt->bindParam(":$param", $all_params[$param]);
                }
            }

            if ($stmt->execute()) {
                // Clear settings cache to ensure changes take effect immediately
                require_once '../includes/settings_helper.php';
                clearSettingsCache();

                $success_message = "School settings updated successfully! Changes will take effect immediately.";
            } else {
                $error_message = "Error updating school settings.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields.";
    }
}

// Get current settings
$settings_query = "SELECT * FROM school_settings LIMIT 1";
$settings_stmt = $db->query($settings_query);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// Default values if no settings exist
if (!$settings) {
    $settings = [
        'school_name' => 'Greenwood Academy',
        'school_address' => '',
        'school_phone' => '',
        'school_email' => '',
        'school_website' => '',
        'principal_name' => '',
        'academic_year_start' => date('Y') . '-09-01',
        'academic_year_end' => (date('Y') + 1) . '-06-30',
        'currency' => 'GHS',
        'timezone' => 'Africa/Accra',
        'terms_per_year' => '3',
        'grading_system' => 'percentage',
        'theme_color' => 'blue',
        'default_language' => 'en',
        'date_format' => 'Y-m-d',
        'sms_gateway' => 'disabled',
        'email_notifications' => 'enabled',
        'parent_portal' => 'enabled',
        'student_portal' => 'enabled'
    ];
}

$title = "School Settings";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">School Settings</h1>
                                <p class="text-blue-100 text-lg">Configure your school's basic information and preferences</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-school text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
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

                <!-- Settings Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">School Information</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Update your school's basic information and settings</p>
                    </div>

                    <form method="POST" class="p-6 space-y-8">
                        <!-- Basic Information -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Basic Information</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- School Name -->
                                <div class="md:col-span-2">
                                    <label for="school_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        School Name <span class="text-red-500">*</span>
                                    </label>
                                    <input type="text" id="school_name" name="school_name" required
                                        value="<?php echo htmlspecialchars($settings['school_name']); ?>"
                                        placeholder="Enter school name"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- School Phone -->
                                <div>
                                    <label for="school_phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Phone Number <span class="text-red-500">*</span>
                                    </label>
                                    <input type="tel" id="school_phone" name="school_phone" required
                                        value="<?php echo htmlspecialchars($settings['school_phone']); ?>"
                                        placeholder="Enter phone number"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- School Email -->
                                <div>
                                    <label for="school_email" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Email Address <span class="text-red-500">*</span>
                                    </label>
                                    <input type="email" id="school_email" name="school_email" required
                                        value="<?php echo htmlspecialchars($settings['school_email']); ?>"
                                        placeholder="school@example.com"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- School Website -->
                                <div>
                                    <label for="school_website" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Website URL
                                    </label>
                                    <input type="url" id="school_website" name="school_website"
                                        value="<?php echo htmlspecialchars($settings['school_website']); ?>"
                                        placeholder="https://www.school.com"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Principal Name -->
                                <div>
                                    <label for="principal_name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Principal Name
                                    </label>
                                    <input type="text" id="principal_name" name="principal_name"
                                        value="<?php echo htmlspecialchars($settings['principal_name']); ?>"
                                        placeholder="Enter principal's name"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>
                            </div>

                            <!-- School Address -->
                            <div class="mt-6">
                                <label for="school_address" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    School Address <span class="text-red-500">*</span>
                                </label>
                                <textarea id="school_address" name="school_address" rows="3" required
                                    placeholder="Enter complete school address"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"><?php echo htmlspecialchars($settings['school_address']); ?></textarea>
                            </div>
                        </div>

                        <!-- Academic Settings -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Academic Settings</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Academic Year Start -->
                                <div>
                                    <label for="academic_year_start" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Academic Year Start
                                    </label>
                                    <input type="date" id="academic_year_start" name="academic_year_start"
                                        value="<?php echo htmlspecialchars($settings['academic_year_start']); ?>"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Academic Year End -->
                                <div>
                                    <label for="academic_year_end" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Academic Year End
                                    </label>
                                    <input type="date" id="academic_year_end" name="academic_year_end"
                                        value="<?php echo htmlspecialchars($settings['academic_year_end']); ?>"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <!-- Currency -->
                                <div>
                                    <label for="currency" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Currency
                                    </label>
                                    <select id="currency" name="currency"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="GHS" <?php echo $settings['currency'] === 'GHS' ? 'selected' : ''; ?>>Ghana Cedi (₵)</option>
                                        <option value="USD" <?php echo $settings['currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar ($)</option>
                                        <option value="EUR" <?php echo $settings['currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (€)</option>
                                        <option value="GBP" <?php echo $settings['currency'] === 'GBP' ? 'selected' : ''; ?>>British Pound (£)</option>
                                    </select>
                                </div>

                                <!-- Timezone -->
                                <div>
                                    <label for="timezone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Timezone
                                    </label>
                                    <select id="timezone" name="timezone"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="Africa/Accra" <?php echo $settings['timezone'] === 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra (GMT)</option>
                                        <option value="America/New_York" <?php echo $settings['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (EST)</option>
                                        <option value="Europe/London" <?php echo $settings['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                                        <option value="Asia/Dubai" <?php echo $settings['timezone'] === 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai (GST)</option>
                                    </select>
                                </div>

                                <!-- Terms per Academic Year -->
                                <div>
                                    <label for="terms_per_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Terms per Academic Year
                                    </label>
                                    <select id="terms_per_year" name="terms_per_year"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="2" <?php echo ($settings['terms_per_year'] ?? '3') === '2' ? 'selected' : ''; ?>>2 Terms (Semester System)</option>
                                        <option value="3" <?php echo ($settings['terms_per_year'] ?? '3') === '3' ? 'selected' : ''; ?>>3 Terms (Trimester System)</option>
                                        <option value="4" <?php echo ($settings['terms_per_year'] ?? '3') === '4' ? 'selected' : ''; ?>>4 Terms (Quarter System)</option>
                                    </select>
                                </div>

                                <!-- Grading System -->
                                <div>
                                    <label for="grading_system" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Grading System
                                    </label>
                                    <select id="grading_system" name="grading_system"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="percentage" <?php echo ($settings['grading_system'] ?? 'percentage') === 'percentage' ? 'selected' : ''; ?>>Percentage (0-100%)</option>
                                        <option value="letter" <?php echo ($settings['grading_system'] ?? 'percentage') === 'letter' ? 'selected' : ''; ?>>Letter Grades (A-F)</option>
                                        <option value="gpa" <?php echo ($settings['grading_system'] ?? 'percentage') === 'gpa' ? 'selected' : ''; ?>>GPA (4.0 Scale)</option>
                                        <option value="points" <?php echo ($settings['grading_system'] ?? 'percentage') === 'points' ? 'selected' : ''; ?>>Points (1-10)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- System Appearance Settings -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Appearance</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- School Logo -->
                                <div>
                                    <label for="school_logo" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        School Logo
                                    </label>
                                    <input type="file" id="school_logo" name="school_logo" accept="image/*"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                    <p class="text-xs text-gray-500 mt-1">Upload PNG, JPG, or SVG. Max size: 2MB</p>
                                </div>

                                <!-- Theme Color -->
                                <div>
                                    <label for="theme_color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Primary Theme Color
                                    </label>
                                    <div class="space-y-3">
                                        <select id="theme_color" name="theme_color"
                                            class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                            <!-- Blue Family -->
                                            <optgroup label="🌊 Blue Family">
                                                <option value="blue" <?php echo ($settings['theme_color'] ?? 'blue') === 'blue' ? 'selected' : ''; ?>>Ocean Blue (Default)</option>
                                                <option value="dodgerblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'dodgerblue' ? 'selected' : ''; ?>>Dodger Blue</option>
                                                <option value="royalblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'royalblue' ? 'selected' : ''; ?>>Royal Blue</option>
                                                <option value="navyblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'navyblue' ? 'selected' : ''; ?>>Navy Blue</option>
                                                <option value="steelblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'steelblue' ? 'selected' : ''; ?>>Steel Blue</option>
                                                <option value="cornflowerblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'cornflowerblue' ? 'selected' : ''; ?>>Cornflower Blue</option>
                                                <option value="sky" <?php echo ($settings['theme_color'] ?? 'blue') === 'sky' ? 'selected' : ''; ?>>Sky Blue</option>
                                                <option value="lightblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'lightblue' ? 'selected' : ''; ?>>Light Blue</option>
                                                <option value="deepblue" <?php echo ($settings['theme_color'] ?? 'blue') === 'deepblue' ? 'selected' : ''; ?>>Deep Blue</option>
                                            </optgroup>

                                            <!-- Purple & Violet Family -->
                                            <optgroup label="🔮 Purple & Violet Family">
                                                <option value="indigo" <?php echo ($settings['theme_color'] ?? 'blue') === 'indigo' ? 'selected' : ''; ?>>Royal Indigo</option>
                                                <option value="purple" <?php echo ($settings['theme_color'] ?? 'blue') === 'purple' ? 'selected' : ''; ?>>Mystic Purple</option>
                                                <option value="violet" <?php echo ($settings['theme_color'] ?? 'blue') === 'violet' ? 'selected' : ''; ?>>Deep Violet</option>
                                                <option value="lavender" <?php echo ($settings['theme_color'] ?? 'blue') === 'lavender' ? 'selected' : ''; ?>>Lavender Dreams</option>
                                                <option value="plum" <?php echo ($settings['theme_color'] ?? 'blue') === 'plum' ? 'selected' : ''; ?>>Rich Plum</option>
                                                <option value="orchid" <?php echo ($settings['theme_color'] ?? 'blue') === 'orchid' ? 'selected' : ''; ?>>Elegant Orchid</option>
                                            </optgroup>

                                            <!-- Pink & Rose Family -->
                                            <optgroup label="🌸 Pink & Rose Family">
                                                <option value="fuchsia" <?php echo ($settings['theme_color'] ?? 'blue') === 'fuchsia' ? 'selected' : ''; ?>>Electric Fuchsia</option>
                                                <option value="pink" <?php echo ($settings['theme_color'] ?? 'blue') === 'pink' ? 'selected' : ''; ?>>Soft Pink</option>
                                                <option value="rose" <?php echo ($settings['theme_color'] ?? 'blue') === 'rose' ? 'selected' : ''; ?>>Rose Garden</option>
                                                <option value="hotpink" <?php echo ($settings['theme_color'] ?? 'blue') === 'hotpink' ? 'selected' : ''; ?>>Hot Pink</option>
                                                <option value="magenta" <?php echo ($settings['theme_color'] ?? 'blue') === 'magenta' ? 'selected' : ''; ?>>Vibrant Magenta</option>
                                                <option value="cherry" <?php echo ($settings['theme_color'] ?? 'blue') === 'cherry' ? 'selected' : ''; ?>>Cherry Blossom</option>
                                            </optgroup>

                                            <!-- Red & Orange Family -->
                                            <optgroup label="🔥 Red & Orange Family">
                                                <option value="red" <?php echo ($settings['theme_color'] ?? 'blue') === 'red' ? 'selected' : ''; ?>>Crimson Fire</option>
                                                <option value="scarlet" <?php echo ($settings['theme_color'] ?? 'blue') === 'scarlet' ? 'selected' : ''; ?>>Scarlet Red</option>
                                                <option value="burgundy" <?php echo ($settings['theme_color'] ?? 'blue') === 'burgundy' ? 'selected' : ''; ?>>Burgundy Wine</option>
                                                <option value="orange" <?php echo ($settings['theme_color'] ?? 'blue') === 'orange' ? 'selected' : ''; ?>>Sunset Orange</option>
                                                <option value="coral" <?php echo ($settings['theme_color'] ?? 'blue') === 'coral' ? 'selected' : ''; ?>>Coral Reef</option>
                                                <option value="tangerine" <?php echo ($settings['theme_color'] ?? 'blue') === 'tangerine' ? 'selected' : ''; ?>>Tangerine Dream</option>
                                            </optgroup>

                                            <!-- Yellow & Gold Family -->
                                            <optgroup label="☀️ Yellow & Gold Family">
                                                <option value="amber" <?php echo ($settings['theme_color'] ?? 'blue') === 'amber' ? 'selected' : ''; ?>>Golden Amber</option>
                                                <option value="yellow" <?php echo ($settings['theme_color'] ?? 'blue') === 'yellow' ? 'selected' : ''; ?>>Sunshine Yellow</option>
                                                <option value="gold" <?php echo ($settings['theme_color'] ?? 'blue') === 'gold' ? 'selected' : ''; ?>>Pure Gold</option>
                                                <option value="honey" <?php echo ($settings['theme_color'] ?? 'blue') === 'honey' ? 'selected' : ''; ?>>Honey Gold</option>
                                                <option value="mustard" <?php echo ($settings['theme_color'] ?? 'blue') === 'mustard' ? 'selected' : ''; ?>>Mustard Yellow</option>
                                            </optgroup>

                                            <!-- Green Family -->
                                            <optgroup label="🌿 Green Family">
                                                <option value="lime" <?php echo ($settings['theme_color'] ?? 'blue') === 'lime' ? 'selected' : ''; ?>>Electric Lime</option>
                                                <option value="green" <?php echo ($settings['theme_color'] ?? 'blue') === 'green' ? 'selected' : ''; ?>>Forest Green</option>
                                                <option value="emerald" <?php echo ($settings['theme_color'] ?? 'blue') === 'emerald' ? 'selected' : ''; ?>>Emerald Mint</option>
                                                <option value="jade" <?php echo ($settings['theme_color'] ?? 'blue') === 'jade' ? 'selected' : ''; ?>>Jade Green</option>
                                                <option value="mint" <?php echo ($settings['theme_color'] ?? 'blue') === 'mint' ? 'selected' : ''; ?>>Fresh Mint</option>
                                                <option value="olive" <?php echo ($settings['theme_color'] ?? 'blue') === 'olive' ? 'selected' : ''; ?>>Olive Branch</option>
                                            </optgroup>

                                            <!-- Cyan & Teal Family -->
                                            <optgroup label="🌊 Cyan & Teal Family">
                                                <option value="teal" <?php echo ($settings['theme_color'] ?? 'blue') === 'teal' ? 'selected' : ''; ?>>Teal Ocean</option>
                                                <option value="cyan" <?php echo ($settings['theme_color'] ?? 'blue') === 'cyan' ? 'selected' : ''; ?>>Cyan Sky</option>
                                                <option value="turquoise" <?php echo ($settings['theme_color'] ?? 'blue') === 'turquoise' ? 'selected' : ''; ?>>Turquoise Waters</option>
                                                <option value="aqua" <?php echo ($settings['theme_color'] ?? 'blue') === 'aqua' ? 'selected' : ''; ?>>Aqua Marine</option>
                                                <option value="seafoam" <?php echo ($settings['theme_color'] ?? 'blue') === 'seafoam' ? 'selected' : ''; ?>>Seafoam Green</option>
                                            </optgroup>

                                            <!-- Neutral & Earth Tones -->
                                            <optgroup label="🏔️ Neutral & Earth Tones">
                                                <option value="slate" <?php echo ($settings['theme_color'] ?? 'blue') === 'slate' ? 'selected' : ''; ?>>Modern Slate</option>
                                                <option value="gray" <?php echo ($settings['theme_color'] ?? 'blue') === 'gray' ? 'selected' : ''; ?>>Professional Gray</option>
                                                <option value="zinc" <?php echo ($settings['theme_color'] ?? 'blue') === 'zinc' ? 'selected' : ''; ?>>Metallic Zinc</option>
                                                <option value="stone" <?php echo ($settings['theme_color'] ?? 'blue') === 'stone' ? 'selected' : ''; ?>>Natural Stone</option>
                                                <option value="neutral" <?php echo ($settings['theme_color'] ?? 'blue') === 'neutral' ? 'selected' : ''; ?>>Warm Neutral</option>
                                                <option value="charcoal" <?php echo ($settings['theme_color'] ?? 'blue') === 'charcoal' ? 'selected' : ''; ?>>Charcoal Gray</option>
                                                <option value="bronze" <?php echo ($settings['theme_color'] ?? 'blue') === 'bronze' ? 'selected' : ''; ?>>Bronze Metal</option>
                                                <option value="copper" <?php echo ($settings['theme_color'] ?? 'blue') === 'copper' ? 'selected' : ''; ?>>Copper Shine</option>
                                            </optgroup>
                                        </select>

                                        <!-- Color Preview -->
                                        <div id="color-preview" class="w-full h-16 rounded-lg shadow-lg transition-all duration-300" class="page-header-gradient" style=";"></div>

                                        <!-- Color Description -->
                                        <div id="color-description" class="text-sm text-gray-600 dark:text-gray-400">
                                            <span class="font-medium">Ocean Blue:</span> A calming and professional gradient that inspires trust and reliability.
                                        </div>
                                    </div>
                                </div>

                                <!-- Default Language -->
                                <div>
                                    <label for="default_language" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Default Language
                                    </label>
                                    <select id="default_language" name="default_language"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="en" <?php echo ($settings['default_language'] ?? 'en') === 'en' ? 'selected' : ''; ?>>English</option>
                                        <option value="fr" <?php echo ($settings['default_language'] ?? 'en') === 'fr' ? 'selected' : ''; ?>>French</option>
                                        <option value="es" <?php echo ($settings['default_language'] ?? 'en') === 'es' ? 'selected' : ''; ?>>Spanish</option>
                                        <option value="ar" <?php echo ($settings['default_language'] ?? 'en') === 'ar' ? 'selected' : ''; ?>>Arabic</option>
                                    </select>
                                </div>

                                <!-- Date Format -->
                                <div>
                                    <label for="date_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Date Format
                                    </label>
                                    <select id="date_format" name="date_format"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="Y-m-d" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (2024-01-15)</option>
                                        <option value="d/m/Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (15/01/2024)</option>
                                        <option value="m/d/Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (01/15/2024)</option>
                                        <option value="d-M-Y" <?php echo ($settings['date_format'] ?? 'Y-m-d') === 'd-M-Y' ? 'selected' : ''; ?>>DD-MMM-YYYY (15-Jan-2024)</option>
                                    </select>
                                </div>

                                <!-- Time Format -->
                                <div>
                                    <label for="time_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Time Format
                                    </label>
                                    <select id="time_format" name="time_format"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="H:i" <?php echo ($settings['time_format'] ?? 'H:i') === 'H:i' ? 'selected' : ''; ?>>24-hour (14:30)</option>
                                        <option value="h:i A" <?php echo ($settings['time_format'] ?? 'H:i') === 'h:i A' ? 'selected' : ''; ?>>12-hour (2:30 PM)</option>
                                        <option value="h:i a" <?php echo ($settings['time_format'] ?? 'H:i') === 'h:i a' ? 'selected' : ''; ?>>12-hour lowercase (2:30 pm)</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Communication Settings -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Communication Settings</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- SMS Gateway -->
                                <div>
                                    <label for="sms_gateway" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        SMS Gateway
                                    </label>
                                    <select id="sms_gateway" name="sms_gateway"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="disabled" <?php echo ($settings['sms_gateway'] ?? 'disabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="twilio" <?php echo ($settings['sms_gateway'] ?? 'disabled') === 'twilio' ? 'selected' : ''; ?>>Twilio</option>
                                        <option value="nexmo" <?php echo ($settings['sms_gateway'] ?? 'disabled') === 'nexmo' ? 'selected' : ''; ?>>Vonage (Nexmo)</option>
                                        <option value="local" <?php echo ($settings['sms_gateway'] ?? 'disabled') === 'local' ? 'selected' : ''; ?>>Local Provider</option>
                                    </select>
                                </div>

                                <!-- Email Notifications -->
                                <div>
                                    <label for="email_notifications" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Email Notifications
                                    </label>
                                    <select id="email_notifications" name="email_notifications"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="enabled" <?php echo ($settings['email_notifications'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="disabled" <?php echo ($settings['email_notifications'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="admin_only" <?php echo ($settings['email_notifications'] ?? 'enabled') === 'admin_only' ? 'selected' : ''; ?>>Admin Only</option>
                                    </select>
                                </div>

                                <!-- Parent Portal Access -->
                                <div>
                                    <label for="parent_portal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Parent Portal Access
                                    </label>
                                    <select id="parent_portal" name="parent_portal"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="enabled" <?php echo ($settings['parent_portal'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="disabled" <?php echo ($settings['parent_portal'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="restricted" <?php echo ($settings['parent_portal'] ?? 'enabled') === 'restricted' ? 'selected' : ''; ?>>Restricted Access</option>
                                    </select>
                                </div>

                                <!-- Student Portal Access -->
                                <div>
                                    <label for="student_portal" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Student Portal Access
                                    </label>
                                    <select id="student_portal" name="student_portal"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="enabled" <?php echo ($settings['student_portal'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="disabled" <?php echo ($settings['student_portal'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="restricted" <?php echo ($settings['student_portal'] ?? 'enabled') === 'restricted' ? 'selected' : ''; ?>>Restricted Access</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- System Management Settings -->
                        <div>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Management</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <!-- Maintenance Mode -->
                                <div>
                                    <label for="maintenance_mode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Maintenance Mode
                                    </label>
                                    <select id="maintenance_mode" name="maintenance_mode"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="disabled" <?php echo ($settings['maintenance_mode'] ?? 'disabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="enabled" <?php echo ($settings['maintenance_mode'] ?? 'disabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                    </select>
                                    <p class="text-xs text-gray-500 mt-1">When enabled, only admins can access the system</p>
                                </div>

                                <!-- Registration Enabled -->
                                <div>
                                    <label for="registration_enabled" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        New User Registration
                                    </label>
                                    <select id="registration_enabled" name="registration_enabled"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="enabled" <?php echo ($settings['registration_enabled'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="disabled" <?php echo ($settings['registration_enabled'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                        <option value="admin_only" <?php echo ($settings['registration_enabled'] ?? 'enabled') === 'admin_only' ? 'selected' : ''; ?>>Admin Only</option>
                                    </select>
                                </div>

                                <!-- Max File Upload Size -->
                                <div>
                                    <label for="max_file_upload_size" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Max File Upload Size
                                    </label>
                                    <select id="max_file_upload_size" name="max_file_upload_size"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="2MB" <?php echo ($settings['max_file_upload_size'] ?? '10MB') === '2MB' ? 'selected' : ''; ?>>2 MB</option>
                                        <option value="5MB" <?php echo ($settings['max_file_upload_size'] ?? '10MB') === '5MB' ? 'selected' : ''; ?>>5 MB</option>
                                        <option value="10MB" <?php echo ($settings['max_file_upload_size'] ?? '10MB') === '10MB' ? 'selected' : ''; ?>>10 MB</option>
                                        <option value="20MB" <?php echo ($settings['max_file_upload_size'] ?? '10MB') === '20MB' ? 'selected' : ''; ?>>20 MB</option>
                                        <option value="50MB" <?php echo ($settings['max_file_upload_size'] ?? '10MB') === '50MB' ? 'selected' : ''; ?>>50 MB</option>
                                    </select>
                                </div>

                                <!-- Session Timeout -->
                                <div>
                                    <label for="session_timeout" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Session Timeout (minutes)
                                    </label>
                                    <select id="session_timeout" name="session_timeout"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="15" <?php echo ($settings['session_timeout'] ?? '30') === '15' ? 'selected' : ''; ?>>15 minutes</option>
                                        <option value="30" <?php echo ($settings['session_timeout'] ?? '30') === '30' ? 'selected' : ''; ?>>30 minutes</option>
                                        <option value="60" <?php echo ($settings['session_timeout'] ?? '30') === '60' ? 'selected' : ''; ?>>1 hour</option>
                                        <option value="120" <?php echo ($settings['session_timeout'] ?? '30') === '120' ? 'selected' : ''; ?>>2 hours</option>
                                        <option value="480" <?php echo ($settings['session_timeout'] ?? '30') === '480' ? 'selected' : ''; ?>>8 hours</option>
                                    </select>
                                </div>

                                <!-- Backup Frequency -->
                                <div>
                                    <label for="backup_frequency" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Automatic Backup Frequency
                                    </label>
                                    <select id="backup_frequency" name="backup_frequency"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="daily" <?php echo ($settings['backup_frequency'] ?? 'weekly') === 'daily' ? 'selected' : ''; ?>>Daily</option>
                                        <option value="weekly" <?php echo ($settings['backup_frequency'] ?? 'weekly') === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                                        <option value="monthly" <?php echo ($settings['backup_frequency'] ?? 'weekly') === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                                        <option value="manual" <?php echo ($settings['backup_frequency'] ?? 'weekly') === 'manual' ? 'selected' : ''; ?>>Manual Only</option>
                                    </select>
                                </div>

                                <!-- Auto Backup -->
                                <div>
                                    <label for="auto_backup" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                        Automatic Backup
                                    </label>
                                    <select id="auto_backup" name="auto_backup"
                                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                                        <option value="enabled" <?php echo ($settings['auto_backup'] ?? 'enabled') === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                                        <option value="disabled" <?php echo ($settings['auto_backup'] ?? 'enabled') === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                            <div class="text-sm text-gray-500 dark:text-gray-400">
                                <i class="fas fa-info-circle mr-1"></i>
                                Changes will be applied system-wide
                            </div>
                            <button type="submit"
                                class="inline-flex items-center px-6 py-3 bg-indigo-500 hover:bg-indigo-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-save mr-2"></i>
                                Save Settings
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Additional Settings Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mt-8">
                    <!-- System Information -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">System Information</h3>
                        <div class="space-y-3 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">System Version:</span>
                                <span class="font-medium text-gray-900 dark:text-white">v1.0.0</span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Last Updated:</span>
                                <span class="font-medium text-gray-900 dark:text-white"><?php echo date('M j, Y'); ?></span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600 dark:text-gray-400">Database:</span>
                                <span class="font-medium text-green-600 dark:text-green-400">Connected</span>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Quick Actions</h3>
                        <div class="space-y-3">
                            <a href="../backup.php" class="flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">
                                <i class="fas fa-download mr-2"></i>
                                Backup Database
                            </a>
                            <a href="../maintenance.php" class="flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300">
                                <i class="fas fa-tools mr-2"></i>
                                Maintenance Mode
                            </a>
                            <a href="../logs.php" class="flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300">
                                <i class="fas fa-file-alt mr-2"></i>
                                View System Logs
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
// Auto-focus on first input
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('school_name').focus();
});

// Real-time school name update in header/sidebar
document.getElementById('school_name').addEventListener('input', function(e) {
    const newName = e.target.value;
    // Update school name in header if it exists
    const headerSchoolName = document.querySelector('.header-school-name');
    if (headerSchoolName) {
        headerSchoolName.textContent = newName || 'School Management System';
    }

    // Update school name in sidebar if it exists
    const sidebarSchoolName = document.querySelector('.sidebar-school-name');
    if (sidebarSchoolName) {
        sidebarSchoolName.textContent = newName || 'SMS';
    }

    // Update any other school name references
    const allSchoolNames = document.querySelectorAll('[data-school-name]');
    allSchoolNames.forEach(element => {
        element.textContent = newName || 'School Management System';
    });
});

// Enhanced theme color preview
document.getElementById('theme_color').addEventListener('change', function(e) {
    const color = e.target.value;
    const preview = document.getElementById('color-preview');
    const description = document.getElementById('color-description');

    // Define color gradients and descriptions
    const colorThemes = {
        // Blue Family
        'blue': {
            gradient: 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)',
            description: '<span class="font-medium">Ocean Blue:</span> A calming and professional gradient that inspires trust and reliability.'
        },
        'dodgerblue': {
            gradient: 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)',
            description: '<span class="font-medium">Dodger Blue:</span> A vibrant and energetic blue that captures attention and promotes clarity.'
        },
        'royalblue': {
            gradient: 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)',
            description: '<span class="font-medium">Royal Blue:</span> A majestic and authoritative blue perfect for prestigious institutions.'
        },
        'navyblue': {
            gradient: 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)',
            description: '<span class="font-medium">Navy Blue:</span> A deep and sophisticated blue that conveys professionalism and stability.'
        },
        'steelblue': {
            gradient: 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)',
            description: '<span class="font-medium">Steel Blue:</span> A strong and reliable blue-gray that represents durability and trust.'
        },
        'cornflowerblue': {
            gradient: 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)',
            description: '<span class="font-medium">Cornflower Blue:</span> A gentle and approachable blue that creates a welcoming atmosphere.'
        },
        'lightblue': {
            gradient: 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)',
            description: '<span class="font-medium">Light Blue:</span> A soft and peaceful blue that promotes calm and focused learning.'
        },
        'deepblue': {
            gradient: 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)',
            description: '<span class="font-medium">Deep Blue:</span> An intense and powerful blue that commands respect and attention.'
        },

        // Purple & Violet Family
        'indigo': {
            gradient: 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)',
            description: '<span class="font-medium">Royal Indigo:</span> A sophisticated blend of deep blues and purples, perfect for academic excellence.'
        },
        'purple': {
            gradient: 'linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)',
            description: '<span class="font-medium">Mystic Purple:</span> A creative and inspiring gradient that encourages innovation and learning.'
        },
        'violet': {
            gradient: 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)',
            description: '<span class="font-medium">Deep Violet:</span> A rich and mysterious gradient that encourages deep thinking and creativity.'
        },
        'lavender': {
            gradient: 'linear-gradient(135deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)',
            description: '<span class="font-medium">Lavender Dreams:</span> A soft and soothing purple that creates a peaceful learning environment.'
        },
        'plum': {
            gradient: 'linear-gradient(135deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)',
            description: '<span class="font-medium">Rich Plum:</span> A deep and luxurious purple that adds elegance and sophistication.'
        },
        'orchid': {
            gradient: 'linear-gradient(135deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)',
            description: '<span class="font-medium">Elegant Orchid:</span> A refined and graceful purple that inspires creativity and beauty.'
        },

        // Pink & Rose Family
        'fuchsia': {
            gradient: 'linear-gradient(135deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)',
            description: '<span class="font-medium">Electric Fuchsia:</span> A vibrant and energetic gradient that sparks creativity and innovation.'
        },
        'pink': {
            gradient: 'linear-gradient(135deg, #ec4899 0%, #db2777 50%, #be185d 100%)',
            description: '<span class="font-medium">Soft Pink:</span> A gentle and nurturing gradient that creates a supportive learning environment.'
        },
        'rose': {
            gradient: 'linear-gradient(135deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)',
            description: '<span class="font-medium">Rose Garden:</span> A warm and welcoming gradient that creates a friendly atmosphere.'
        },
        'hotpink': {
            gradient: 'linear-gradient(135deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)',
            description: '<span class="font-medium">Hot Pink:</span> A bold and confident pink that energizes and motivates students.'
        },
        'magenta': {
            gradient: 'linear-gradient(135deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)',
            description: '<span class="font-medium">Vibrant Magenta:</span> A striking and dynamic color that captures attention and inspires action.'
        },
        'cherry': {
            gradient: 'linear-gradient(135deg, #de3163 0%, #dc143c 50%, #b22222 100%)',
            description: '<span class="font-medium">Cherry Blossom:</span> A sweet and delicate pink-red that brings warmth and joy.'
        },

        // Red & Orange Family
        'red': {
            gradient: 'linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)',
            description: '<span class="font-medium">Crimson Fire:</span> A bold and powerful gradient that commands attention and respect.'
        },
        'scarlet': {
            gradient: 'linear-gradient(135deg, #ff2400 0%, #dc143c 50%, #b22222 100%)',
            description: '<span class="font-medium">Scarlet Red:</span> A passionate and intense red that motivates and energizes.'
        },
        'burgundy': {
            gradient: 'linear-gradient(135deg, #800020 0%, #722f37 50%, #654321 100%)',
            description: '<span class="font-medium">Burgundy Wine:</span> A rich and sophisticated red that conveys luxury and tradition.'
        },
        'orange': {
            gradient: 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
            description: '<span class="font-medium">Sunset Orange:</span> An energetic and optimistic gradient that radiates warmth and enthusiasm.'
        },
        'coral': {
            gradient: 'linear-gradient(135deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)',
            description: '<span class="font-medium">Coral Reef:</span> A vibrant and lively orange-pink that brings energy and positivity.'
        },
        'tangerine': {
            gradient: 'linear-gradient(135deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)',
            description: '<span class="font-medium">Tangerine Dream:</span> A fresh and zesty orange that stimulates creativity and enthusiasm.'
        },

        // Yellow & Gold Family
        'amber': {
            gradient: 'linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%)',
            description: '<span class="font-medium">Golden Amber:</span> A rich and luxurious gradient that represents wisdom and achievement.'
        },
        'yellow': {
            gradient: 'linear-gradient(135deg, #eab308 0%, #ca8a04 50%, #a16207 100%)',
            description: '<span class="font-medium">Sunshine Yellow:</span> A bright and cheerful gradient that promotes positivity and learning.'
        },
        'gold': {
            gradient: 'linear-gradient(135deg, #ffd700 0%, #ffb347 50%, #daa520 100%)',
            description: '<span class="font-medium">Pure Gold:</span> A prestigious and valuable color that represents excellence and achievement.'
        },
        'honey': {
            gradient: 'linear-gradient(135deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)',
            description: '<span class="font-medium">Honey Gold:</span> A warm and sweet golden color that creates a welcoming atmosphere.'
        },
        'mustard': {
            gradient: 'linear-gradient(135deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)',
            description: '<span class="font-medium">Mustard Yellow:</span> A bold and distinctive yellow that adds character and warmth.'
        },

        // Green Family
        'lime': {
            gradient: 'linear-gradient(135deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)',
            description: '<span class="font-medium">Electric Lime:</span> A fresh and energizing gradient that promotes growth and vitality.'
        },
        'green': {
            gradient: 'linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%)',
            description: '<span class="font-medium">Forest Green:</span> A natural and growth-oriented theme representing progress and sustainability.'
        },
        'emerald': {
            gradient: 'linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)',
            description: '<span class="font-medium">Emerald Mint:</span> A fresh and vibrant gradient that symbolizes new beginnings and vitality.'
        },
        'jade': {
            gradient: 'linear-gradient(135deg, #00a86b 0%, #29ab87 50%, #50c878 100%)',
            description: '<span class="font-medium">Jade Green:</span> A precious and balanced green that promotes harmony and wisdom.'
        },
        'mint': {
            gradient: 'linear-gradient(135deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)',
            description: '<span class="font-medium">Fresh Mint:</span> A cool and refreshing green that energizes and revitalizes.'
        },
        'olive': {
            gradient: 'linear-gradient(135deg, #808000 0%, #9acd32 50%, #6b8e23 100%)',
            description: '<span class="font-medium">Olive Branch:</span> An earthy and peaceful green that represents growth and stability.'
        },

        // Cyan & Teal Family
        'teal': {
            gradient: 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
            description: '<span class="font-medium">Teal Ocean:</span> A balanced blend of blue and green, representing harmony and tranquility.'
        },
        'cyan': {
            gradient: 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)',
            description: '<span class="font-medium">Cyan Sky:</span> A bright and energetic gradient that evokes clarity and openness.'
        },
        'sky': {
            gradient: 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',
            description: '<span class="font-medium">Sky Blue:</span> A serene and uplifting gradient that inspires limitless possibilities.'
        },
        'turquoise': {
            gradient: 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)',
            description: '<span class="font-medium">Turquoise Waters:</span> A tropical and refreshing blue-green that promotes calm and clarity.'
        },
        'aqua': {
            gradient: 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)',
            description: '<span class="font-medium">Aqua Marine:</span> A pure and clean blue that represents freshness and innovation.'
        },
        'seafoam': {
            gradient: 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)',
            description: '<span class="font-medium">Seafoam Green:</span> A gentle and soothing blue-green that creates a peaceful environment.'
        },

        // Neutral & Earth Tones
        'slate': {
            gradient: 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)',
            description: '<span class="font-medium">Modern Slate:</span> A sleek and contemporary gradient perfect for modern educational environments.'
        },
        'gray': {
            gradient: 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)',
            description: '<span class="font-medium">Professional Gray:</span> A neutral and sophisticated gradient that emphasizes content and functionality.'
        },
        'zinc': {
            gradient: 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)',
            description: '<span class="font-medium">Metallic Zinc:</span> A modern industrial gradient that conveys strength and reliability.'
        },
        'stone': {
            gradient: 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)',
            description: '<span class="font-medium">Natural Stone:</span> An earthy and grounded gradient that promotes stability and focus.'
        },
        'neutral': {
            gradient: 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)',
            description: '<span class="font-medium">Warm Neutral:</span> A balanced and versatile gradient that complements any content beautifully.'
        },
        'charcoal': {
            gradient: 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)',
            description: '<span class="font-medium">Charcoal Gray:</span> A deep and sophisticated gray that provides excellent contrast and readability.'
        },
        'bronze': {
            gradient: 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)',
            description: '<span class="font-medium">Bronze Metal:</span> A warm and rich metallic color that adds elegance and distinction.'
        },
        'copper': {
            gradient: 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)',
            description: '<span class="font-medium">Copper Shine:</span> A lustrous and warm metallic that brings sophistication and warmth.'
        }
    };

    // Update preview
    if (colorThemes[color]) {
        preview.style.background = colorThemes[color].gradient;
        description.innerHTML = colorThemes[color].description;
    }

    // Show notification
    const notification = document.createElement('div');
    notification.className = 'fixed top-4 right-4 px-6 py-3 text-white rounded-lg shadow-lg z-50 transform transition-all duration-300';
    notification.style.background = colorThemes[color]?.gradient || 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)';
    notification.innerHTML = `<i class="fas fa-palette mr-2"></i>Theme preview: ${color.charAt(0).toUpperCase() + color.slice(1)}`;
    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.transform = 'translateX(100%)';
        setTimeout(() => notification.remove(), 300);
    }, 3000);

    // Apply theme immediately to current page
    applyThemeToCurrentPage(color);
});

// Function to apply theme changes immediately
function applyThemeToCurrentPage(color) {
    const colorThemes = {
        // Blue Family
        'blue': 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)',
        'dodgerblue': 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)',
        'royalblue': 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)',
        'navyblue': 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)',
        'steelblue': 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)',
        'cornflowerblue': 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)',
        'lightblue': 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)',
        'deepblue': 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)',
        'sky': 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',

        // Purple & Violet Family
        'indigo': 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)',
        'purple': 'linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)',
        'violet': 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)',
        'lavender': 'linear-gradient(135deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)',
        'plum': 'linear-gradient(135deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)',
        'orchid': 'linear-gradient(135deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)',

        // Pink & Rose Family
        'fuchsia': 'linear-gradient(135deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)',
        'pink': 'linear-gradient(135deg, #ec4899 0%, #db2777 50%, #be185d 100%)',
        'rose': 'linear-gradient(135deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)',
        'hotpink': 'linear-gradient(135deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)',
        'magenta': 'linear-gradient(135deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)',
        'cherry': 'linear-gradient(135deg, #de3163 0%, #dc143c 50%, #b22222 100%)',

        // Red & Orange Family
        'red': 'linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)',
        'scarlet': 'linear-gradient(135deg, #ff2400 0%, #dc143c 50%, #b22222 100%)',
        'burgundy': 'linear-gradient(135deg, #800020 0%, #722f37 50%, #654321 100%)',
        'orange': 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
        'coral': 'linear-gradient(135deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)',
        'tangerine': 'linear-gradient(135deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)',

        // Yellow & Gold Family
        'amber': 'linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%)',
        'yellow': 'linear-gradient(135deg, #eab308 0%, #ca8a04 50%, #a16207 100%)',
        'gold': 'linear-gradient(135deg, #ffd700 0%, #ffb347 50%, #daa520 100%)',
        'honey': 'linear-gradient(135deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)',
        'mustard': 'linear-gradient(135deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)',

        // Green Family
        'lime': 'linear-gradient(135deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)',
        'green': 'linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%)',
        'emerald': 'linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)',
        'jade': 'linear-gradient(135deg, #00a86b 0%, #29ab87 50%, #50c878 100%)',
        'mint': 'linear-gradient(135deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)',
        'olive': 'linear-gradient(135deg, #808000 0%, #9acd32 50%, #6b8e23 100%)',

        // Cyan & Teal Family
        'teal': 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
        'cyan': 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)',
        'turquoise': 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)',
        'aqua': 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)',
        'seafoam': 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)',

        // Neutral & Earth Tones
        'slate': 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)',
        'gray': 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)',
        'zinc': 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)',
        'stone': 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)',
        'neutral': 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)',
        'charcoal': 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)',
        'bronze': 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)',
        'copper': 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)'
    };

    const gradient = colorThemes[color] || colorThemes['blue'];

    // Update CSS variables
    document.documentElement.style.setProperty('--primary-gradient', gradient);
    document.documentElement.style.setProperty('--sidebar-gradient', gradient);
    document.documentElement.style.setProperty('--footer-gradient', gradient);
    document.documentElement.style.setProperty('--header-gradient', gradient);

    // Update header
    const header = document.querySelector('header');
    if (header) {
        header.style.background = gradient;
    }

    // Update sidebar
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.style.background = gradient;
    }

    // Update footer
    const footer = document.querySelector('footer');
    if (footer) {
        footer.style.background = gradient;
    }

    // Update any gradient backgrounds
    const gradientElements = document.querySelectorAll('.gradient-bg, .theme-button, .dashboard-card-gradient, .page-header-gradient, .page-header');
    gradientElements.forEach(element => {
        element.style.background = gradient;
    });

    // Update page headers specifically
    const pageHeaders = document.querySelectorAll('.page-header, .page-header-gradient, [class*="bg-gradient-to-r"]');
    pageHeaders.forEach(element => {
        element.style.background = gradient;
        element.style.backgroundImage = gradient;
    });
}

// Form validation with enhanced checks
document.querySelector('form').addEventListener('submit', function(e) {
    const requiredFields = ['school_name', 'school_address', 'school_phone', 'school_email'];
    let isValid = true;
    let errorMessages = [];

    requiredFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (!field.value.trim()) {
            isValid = false;
            field.classList.add('border-red-500');
            errorMessages.push(`${field.previousElementSibling.textContent.replace('*', '').trim()} is required`);
        } else {
            field.classList.remove('border-red-500');
        }
    });

    // Email validation
    const emailField = document.getElementById('school_email');
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (emailField.value && !emailRegex.test(emailField.value)) {
        isValid = false;
        emailField.classList.add('border-red-500');
        errorMessages.push('Please enter a valid email address');
    }

    // Phone validation (basic)
    const phoneField = document.getElementById('school_phone');
    if (phoneField.value && phoneField.value.length < 10) {
        isValid = false;
        phoneField.classList.add('border-red-500');
        errorMessages.push('Please enter a valid phone number');
    }

    // Academic year validation
    const startDate = new Date(document.getElementById('academic_year_start').value);
    const endDate = new Date(document.getElementById('academic_year_end').value);
    if (startDate && endDate && startDate >= endDate) {
        isValid = false;
        document.getElementById('academic_year_end').classList.add('border-red-500');
        errorMessages.push('Academic year end date must be after start date');
    }

    if (!isValid) {
        e.preventDefault();
        alert('Please fix the following errors:\n\n' + errorMessages.join('\n'));
    } else {
        // Show loading state
        const submitBtn = e.target.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Saving...';
        submitBtn.disabled = true;

        // Re-enable after a delay (in case of errors)
        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 5000);
    }
});

// File upload preview for logo
document.getElementById('school_logo').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        if (file.size > 2 * 1024 * 1024) { // 2MB limit
            alert('File size must be less than 2MB');
            e.target.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function(e) {
            // Create preview if it doesn't exist
            let preview = document.getElementById('logo-preview');
            if (!preview) {
                preview = document.createElement('img');
                preview.id = 'logo-preview';
                preview.className = 'mt-2 w-20 h-20 object-cover rounded-lg border';
                e.target.parentNode.appendChild(preview);
            }
            preview.src = e.target.result;
        };
        reader.readAsDataURL(file);
    }
});
</script>
