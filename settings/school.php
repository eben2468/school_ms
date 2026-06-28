<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('settings');

require_once '../includes/csrf.php';
require_once '../config/database.php';
require_once '../includes/schema_helpers.php';
$database = new Database();
$db = $database->getConnection();

// Ensure digital-signature columns exist before reading/writing settings.
ensureSignatureColumns($db);

$user_role = $_SESSION['role'];
// Only super admins may access the System Settings and Permissions tabs.
$is_super = ($user_role === 'super_admin');

// Get active tab from URL or default to 'school-info'
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'school-info';
$allowed_tabs = ['school-info', 'signatures', 'academics', 'attendance', 'payment', 'sms', 'email', 'ai'];
if ($is_super) {
    $allowed_tabs[] = 'system';
    $allowed_tabs[] = 'permissions';
}
if (!in_array($active_tab, $allowed_tabs)) {
    $active_tab = 'school-info';
}

// Get current settings
$settings_query = "SELECT * FROM school_settings LIMIT 1";
$settings_stmt = $db->query($settings_query);
$settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);

// Default values if no settings exist
$default_settings = [
    'school_name' => 'Greenwood Academy',
    'school_address' => '',
    'school_phone' => '',
    'school_email' => '',
    'school_website' => '',
    'school_logo' => '',
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
    'sms_api_key' => '',
    'sms_api_secret' => '',
    'sms_sender_id' => '',
    'sms_country_code' => '233',
    'sms_absence_alerts' => '0',
    'sms_payment_reminders' => '0',
    'sms_exam_results' => '0',
    'sms_event_announcements' => '0',
    'sms_emergency_alerts' => '0',
    'ai_provider' => 'builtin',
    'ai_api_key' => '',
    'ai_model' => 'gemini-1.5-flash',
    'email_notifications' => 'enabled',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'parent_portal' => 'enabled',
    'student_portal' => 'enabled',
    'time_format' => 'H:i',
    'maintenance_mode' => 'disabled',
    'registration_enabled' => 'enabled',
    'max_file_upload_size' => '10MB',
    'session_timeout' => '30',
    'login_max_attempts' => '5',
    'login_lockout_duration' => '15',
    'backup_frequency' => 'weekly',
    'auto_backup' => 'enabled',
    'attendance_grace_period' => '15',
    'attendance_auto_absent' => 'enabled',
    'attendance_daily_reports' => 'enabled',
    'attendance_parent_notifications' => 'enabled',
    'attendance_weekly_summary' => 'disabled',
    'payment_gateway' => 'manual',
    'payment_api_key' => '',
    'payment_api_secret' => '',
    'pay_method_cash' => '1',
    'pay_method_bank' => '1',
    'pay_method_card' => '1',
    'pay_method_mobile' => '1',
    'currency_symbol' => '₵',
    'footer_tagline' => 'Excellence in Education',
    'footer_description' => 'Empowering education through innovative technology and efficient management solutions for the digital age.',
    'office_hours' => 'Mon - Fri: 8:00 AM - 5:00 PM',
    'social_facebook' => '',
    'social_twitter' => '',
    'social_linkedin' => '',
    'social_instagram' => '',
    'social_youtube' => '',
    'social_tiktok' => '',
    'social_whatsapp' => '',
    'social_telegram' => ''
];

if (!$settings) {
    $settings = $default_settings;
} else {
    foreach ($default_settings as $key => $val) {
        if (!isset($settings[$key])) {
            $settings[$key] = $val;
        }
    }
}

// Get academic settings
$academic_settings = [];
$academic_query = "SELECT setting_key, setting_value FROM academic_settings";
$academic_stmt = $db->query($academic_query);
while ($row = $academic_stmt->fetch(PDO::FETCH_ASSOC)) {
    $academic_settings[$row['setting_key']] = $row['setting_value'];
}

// Get current academic year and term
$current_year = null;
$current_term = null;
if (isset($academic_settings['current_academic_year_id'])) {
    $year_stmt = $db->prepare("SELECT * FROM academic_years WHERE id = :id");
    $year_stmt->execute([':id' => $academic_settings['current_academic_year_id']]);
    $current_year = $year_stmt->fetch(PDO::FETCH_ASSOC);
}
if (isset($academic_settings['current_academic_term_id'])) {
    $term_stmt = $db->prepare("SELECT * FROM academic_terms WHERE id = :id");
    $term_stmt->execute([':id' => $academic_settings['current_academic_term_id']]);
    $current_term = $term_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all academic years and terms
$years = $db->query("SELECT * FROM academic_years ORDER BY year_name DESC")->fetchAll(PDO::FETCH_ASSOC);
$terms = [];
if ($current_year) {
    $terms_stmt = $db->prepare("SELECT * FROM academic_terms WHERE academic_year_id = :year_id ORDER BY term_number");
    $terms_stmt->execute([':year_id' => $current_year['id']]);
    $terms = $terms_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Handle form submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Reject forged/expired submissions before processing any settings change.
    if (!csrf_verify()) {
        $error_message = 'Security validation failed. Please refresh the page and try again.';
        $action = '';
    }

    try {
        switch ($action) {
            case 'update_school_info':
                $school_name = filter_input(INPUT_POST, 'school_name', FILTER_SANITIZE_STRING);
                $school_address = filter_input(INPUT_POST, 'school_address', FILTER_SANITIZE_STRING);
                $school_phone = filter_input(INPUT_POST, 'school_phone', FILTER_SANITIZE_STRING);
                $school_email = filter_input(INPUT_POST, 'school_email', FILTER_SANITIZE_EMAIL);
                $school_website = filter_input(INPUT_POST, 'school_website', FILTER_SANITIZE_URL);
                $principal_name = filter_input(INPUT_POST, 'principal_name', FILTER_SANITIZE_STRING);
                $footer_tagline = trim($_POST['footer_tagline'] ?? '');
                $footer_description = trim($_POST['footer_description'] ?? '');
                $office_hours = trim($_POST['office_hours'] ?? '');

                // Social media links
                $social_keys = ['social_facebook', 'social_twitter', 'social_linkedin', 'social_instagram',
                                'social_youtube', 'social_tiktok', 'social_whatsapp', 'social_telegram'];
                $social_values = [];
                foreach ($social_keys as $sk) {
                    $val = trim($_POST[$sk] ?? '');
                    $social_values[$sk] = $val !== '' ? $val : null;
                }
                
                // Handle logo upload
                $school_logo = $settings['school_logo'];
                if (isset($_FILES['school_logo']) && $_FILES['school_logo']['error'] === UPLOAD_ERR_OK) {
                    $file_ext = strtolower(pathinfo($_FILES['school_logo']['name'], PATHINFO_EXTENSION));
                    $allowed_exts = ['png', 'jpg', 'jpeg', 'gif', 'svg'];
                    // Logos are capped at the smaller of 2 MB and the configured
                    // Max File Upload Size, so the system-wide limit is honoured.
                    require_once '../includes/settings_helper.php';
                    $logo_max = min(2 * 1024 * 1024, getMaxUploadBytes());
                    if (in_array($file_ext, $allowed_exts) && $_FILES['school_logo']['size'] <= $logo_max) {
                        $upload_dir = '../uploads/logos/';
                        if (!file_exists($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }
                        $new_file_name = 'logo_' . time() . '.' . $file_ext;
                        if (move_uploaded_file($_FILES['school_logo']['tmp_name'], $upload_dir . $new_file_name)) {
                            if (!empty($settings['school_logo']) && file_exists($upload_dir . $settings['school_logo'])) {
                                @unlink($upload_dir . $settings['school_logo']);
                            }
                            $school_logo = $new_file_name;
                        }
                    }
                }
                
                $update_query = "UPDATE school_settings SET
                    school_name = :school_name,
                    school_address = :school_address,
                    school_phone = :school_phone,
                    school_email = :school_email,
                    school_website = :school_website,
                    principal_name = :principal_name,
                    school_logo = :school_logo,
                    footer_tagline = :footer_tagline,
                    footer_description = :footer_description,
                    office_hours = :office_hours,
                    social_facebook = :social_facebook,
                    social_twitter = :social_twitter,
                    social_linkedin = :social_linkedin,
                    social_instagram = :social_instagram,
                    social_youtube = :social_youtube,
                    social_tiktok = :social_tiktok,
                    social_whatsapp = :social_whatsapp,
                    social_telegram = :social_telegram,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute(array_merge([
                    ':school_name' => $school_name,
                    ':school_address' => $school_address,
                    ':school_phone' => $school_phone,
                    ':school_email' => $school_email,
                    ':school_website' => $school_website,
                    ':principal_name' => $principal_name,
                    ':school_logo' => $school_logo,
                    ':footer_tagline' => $footer_tagline !== '' ? $footer_tagline : null,
                    ':footer_description' => $footer_description !== '' ? $footer_description : null,
                    ':office_hours' => $office_hours !== '' ? $office_hours : null
                ], [
                    ':social_facebook'  => $social_values['social_facebook'],
                    ':social_twitter'   => $social_values['social_twitter'],
                    ':social_linkedin'  => $social_values['social_linkedin'],
                    ':social_instagram' => $social_values['social_instagram'],
                    ':social_youtube'   => $social_values['social_youtube'],
                    ':social_tiktok'    => $social_values['social_tiktok'],
                    ':social_whatsapp'  => $social_values['social_whatsapp'],
                    ':social_telegram'  => $social_values['social_telegram'],
                ]));
                
                $success_message = "School information updated successfully!";
                $active_tab = 'school-info';
                break;

            case 'update_signatures':
                require_once '../includes/settings_helper.php';
                require_once '../includes/signature_helper.php';
                $sig_max = min(1 * 1024 * 1024, getMaxUploadBytes()); // 1 MB cap
                $sig_dir = '../uploads/signatures/';
                if (!file_exists($sig_dir)) { @mkdir($sig_dir, 0777, true); }

                $signatures_enabled = (isset($_POST['signatures_enabled']) && $_POST['signatures_enabled'] === '1') ? '1' : '0';

                $sig_set = ['signatures_enabled' => $signatures_enabled];
                foreach (['headmaster', 'accountant', 'hr', 'registrar'] as $slot) {
                    // Keep the current file unless a new valid one is uploaded or a removal is requested.
                    $current = $settings['signature_' . $slot] ?? '';
                    $field = 'signature_' . $slot;

                    if (!empty($_POST['remove_' . $slot]) && $current) {
                        if (file_exists($sig_dir . $current)) { @unlink($sig_dir . $current); }
                        $current = '';
                    }

                    if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
                        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif']) && $_FILES[$field]['size'] <= $sig_max) {
                            $base = 'sig_' . $slot . '_' . time();
                            $raw_name = $base . '.' . $ext;
                            if (move_uploaded_file($_FILES[$field]['tmp_name'], $sig_dir . $raw_name)) {
                                // Normalize so the signature prints at a consistent size.
                                $final_name = $base . '.png';
                                if (normalizeSignatureImage($sig_dir . $raw_name, $sig_dir . $final_name)) {
                                    if ($raw_name !== $final_name) { @unlink($sig_dir . $raw_name); }
                                } else {
                                    $final_name = $raw_name; // keep original if normalization fails
                                }
                                if ($current && $current !== $final_name && file_exists($sig_dir . $current)) { @unlink($sig_dir . $current); }
                                $current = $final_name;
                            }
                        } else {
                            $error_message = "Signature for {$slot} must be a PNG/JPG/GIF under 1 MB.";
                        }
                    }

                    $sig_set[$field] = $current !== '' ? $current : null;
                    $sig_set[$field . '_name']  = trim($_POST[$field . '_name'] ?? '') ?: null;
                    $sig_set[$field . '_title'] = trim($_POST[$field . '_title'] ?? '') ?: null;
                }

                // Build the UPDATE dynamically from the prepared set.
                $cols = [];
                $vals = [];
                foreach ($sig_set as $k => $v) {
                    $cols[] = "`$k` = :$k";
                    $vals[":$k"] = $v;
                }
                $stmt = $db->prepare("UPDATE school_settings SET " . implode(', ', $cols) . ", updated_at = CURRENT_TIMESTAMP");
                $stmt->execute($vals);

                clearSettingsCache();
                if ($error_message === '') {
                    $success_message = "Signature settings saved successfully!";
                }
                $active_tab = 'signatures';
                break;

            case 'update_academics':
                $academic_year_start = filter_input(INPUT_POST, 'academic_year_start', FILTER_SANITIZE_STRING);
                $academic_year_end = filter_input(INPUT_POST, 'academic_year_end', FILTER_SANITIZE_STRING);
                $terms_per_year = filter_input(INPUT_POST, 'terms_per_year', FILTER_SANITIZE_STRING);
                $grading_system = filter_input(INPUT_POST, 'grading_system', FILTER_SANITIZE_STRING);
                $school_motto = filter_input(INPUT_POST, 'school_motto', FILTER_SANITIZE_STRING);

                // Validate the grading system against the allowed display styles.
                // Reject unknown values (keep the existing setting) so only a
                // recognised style is ever persisted.
                require_once '../includes/settings_helper.php';
                if (!isValidGradingSystem($grading_system)) {
                    $grading_system = getGradingSystem();
                }
                // Constrain terms-per-year to the supported options.
                if (!in_array($terms_per_year, ['2', '3', '4'], true)) {
                    $terms_per_year = '3';
                }

                $school_postal = filter_input(INPUT_POST, 'school_postal', FILTER_SANITIZE_STRING);
                
                $update_query = "UPDATE school_settings SET 
                    academic_year_start = :academic_year_start,
                    academic_year_end = :academic_year_end,
                    terms_per_year = :terms_per_year,
                    grading_system = :grading_system,
                    updated_at = CURRENT_TIMESTAMP";
                
                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':academic_year_start' => $academic_year_start,
                    ':academic_year_end' => $academic_year_end,
                    ':terms_per_year' => $terms_per_year,
                    ':grading_system' => $grading_system
                ]);
                
                // Update academic settings
                $upsert_sql = "INSERT INTO academic_settings (setting_key, setting_value) 
                               VALUES (:key, :value) 
                               ON DUPLICATE KEY UPDATE setting_value = :value";
                $stmt = $db->prepare($upsert_sql);
                $stmt->execute([':key' => 'school_motto', ':value' => $school_motto]);
                $stmt->execute([':key' => 'school_postal', ':value' => $school_postal]);
                
                $success_message = "Academic settings updated successfully!";
                $active_tab = 'academics';
                break;
                
            case 'update_system':
                if (!$is_super) { throw new Exception('You are not authorized to change system settings.'); }
                $default_language = filter_input(INPUT_POST, 'default_language', FILTER_SANITIZE_STRING);
                $date_format = filter_input(INPUT_POST, 'date_format', FILTER_SANITIZE_STRING);
                $time_format = filter_input(INPUT_POST, 'time_format', FILTER_SANITIZE_STRING);
                $timezone = filter_input(INPUT_POST, 'timezone', FILTER_SANITIZE_STRING);
                $maintenance_mode = filter_input(INPUT_POST, 'maintenance_mode', FILTER_SANITIZE_STRING);
                $registration_enabled = filter_input(INPUT_POST, 'registration_enabled', FILTER_SANITIZE_STRING);
                $max_file_upload_size = filter_input(INPUT_POST, 'max_file_upload_size', FILTER_SANITIZE_STRING);
                $session_timeout = filter_input(INPUT_POST, 'session_timeout', FILTER_SANITIZE_NUMBER_INT);
                $backup_frequency = filter_input(INPUT_POST, 'backup_frequency', FILTER_SANITIZE_STRING);
                $auto_backup = filter_input(INPUT_POST, 'auto_backup', FILTER_SANITIZE_STRING);

                // Login lockout settings (0 attempts = feature disabled).
                $login_max_attempts = (int)filter_input(INPUT_POST, 'login_max_attempts', FILTER_SANITIZE_NUMBER_INT);
                if ($login_max_attempts < 0) { $login_max_attempts = 0; }
                $login_lockout_duration = (int)filter_input(INPUT_POST, 'login_lockout_duration', FILTER_SANITIZE_NUMBER_INT);
                if ($login_lockout_duration < 1) { $login_lockout_duration = 1; }

                // Self-heal: add lockout columns the first time they are saved.
                $check_lockout = $db->query("SHOW COLUMNS FROM school_settings LIKE 'login_max_attempts'");
                if ($check_lockout->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN login_max_attempts INT DEFAULT 5");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN login_lockout_duration INT DEFAULT 15");
                }

                $update_query = "UPDATE school_settings SET
                    default_language = :default_language,
                    date_format = :date_format,
                    time_format = :time_format,
                    timezone = :timezone,
                    maintenance_mode = :maintenance_mode,
                    registration_enabled = :registration_enabled,
                    max_file_upload_size = :max_file_upload_size,
                    session_timeout = :session_timeout,
                    login_max_attempts = :login_max_attempts,
                    login_lockout_duration = :login_lockout_duration,
                    backup_frequency = :backup_frequency,
                    auto_backup = :auto_backup,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':default_language' => $default_language,
                    ':date_format' => $date_format,
                    ':time_format' => $time_format,
                    ':timezone' => $timezone,
                    ':maintenance_mode' => $maintenance_mode,
                    ':registration_enabled' => $registration_enabled,
                    ':max_file_upload_size' => $max_file_upload_size,
                    ':session_timeout' => $session_timeout,
                    ':login_max_attempts' => $login_max_attempts,
                    ':login_lockout_duration' => $login_lockout_duration,
                    ':backup_frequency' => $backup_frequency,
                    ':auto_backup' => $auto_backup
                ]);
                
                require_once '../includes/settings_helper.php';
                clearSettingsCache();
                
                $success_message = "System settings updated successfully!";
                $active_tab = 'system';
                break;
                
            case 'update_attendance':
                $attendance_grace_period = filter_input(INPUT_POST, 'attendance_grace_period', FILTER_SANITIZE_NUMBER_INT);
                $attendance_auto_absent = filter_input(INPUT_POST, 'attendance_auto_absent', FILTER_SANITIZE_STRING);

                // Checkboxes only post a value when toggled on
                $attendance_daily_reports = isset($_POST['attendance_daily_reports']) ? 'enabled' : 'disabled';
                $attendance_parent_notifications = isset($_POST['attendance_parent_notifications']) ? 'enabled' : 'disabled';
                $attendance_weekly_summary = isset($_POST['attendance_weekly_summary']) ? 'enabled' : 'disabled';

                // Check if columns exist, if not add them
                $check_columns = $db->query("SHOW COLUMNS FROM school_settings LIKE 'attendance_grace_period'");
                if ($check_columns->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN attendance_grace_period INT DEFAULT 15");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN attendance_auto_absent ENUM('enabled','disabled') DEFAULT 'enabled'");
                }

                // Ensure notification toggle columns exist
                $check_notif = $db->query("SHOW COLUMNS FROM school_settings LIKE 'attendance_daily_reports'");
                if ($check_notif->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN attendance_daily_reports ENUM('enabled','disabled') DEFAULT 'enabled'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN attendance_parent_notifications ENUM('enabled','disabled') DEFAULT 'enabled'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN attendance_weekly_summary ENUM('enabled','disabled') DEFAULT 'disabled'");
                }

                $update_query = "UPDATE school_settings SET
                    attendance_grace_period = :attendance_grace_period,
                    attendance_auto_absent = :attendance_auto_absent,
                    attendance_daily_reports = :attendance_daily_reports,
                    attendance_parent_notifications = :attendance_parent_notifications,
                    attendance_weekly_summary = :attendance_weekly_summary,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':attendance_grace_period' => $attendance_grace_period,
                    ':attendance_auto_absent' => $attendance_auto_absent,
                    ':attendance_daily_reports' => $attendance_daily_reports,
                    ':attendance_parent_notifications' => $attendance_parent_notifications,
                    ':attendance_weekly_summary' => $attendance_weekly_summary
                ]);

                $success_message = "Attendance settings updated successfully!";
                $active_tab = 'attendance';
                break;
                
            case 'update_payment':
                $currency = filter_input(INPUT_POST, 'currency', FILTER_SANITIZE_STRING);
                $payment_gateway = filter_input(INPUT_POST, 'payment_gateway', FILTER_SANITIZE_STRING);
                $payment_api_key = filter_input(INPUT_POST, 'payment_api_key', FILTER_SANITIZE_STRING);
                $payment_api_secret = filter_input(INPUT_POST, 'payment_api_secret', FILTER_SANITIZE_STRING);
                
                $currency_symbols = [
                    'GHS' => '₵', 'USD' => '$', 'EUR' => '€', 'GBP' => '£',
                    'NGN' => '₦', 'KES' => 'KSh', 'ZAR' => 'R'
                ];
                $currency_symbol = $currency_symbols[$currency] ?? $currency;
                
                // Accepted payment-method toggles (hidden field posts '0' when off)
                $pay_methods = ['cash', 'bank', 'card', 'mobile'];
                $pay_method_values = [];
                foreach ($pay_methods as $pm) {
                    $raw = $_POST['pay_method_' . $pm] ?? '0';
                    if (is_array($raw)) {
                        $raw = end($raw); // last value wins (checkbox overrides hidden '0')
                    }
                    $pay_method_values[$pm] = ((string)$raw === '1') ? '1' : '0';
                }

                // Check if columns exist
                $check_columns = $db->query("SHOW COLUMNS FROM school_settings LIKE 'payment_gateway'");
                if ($check_columns->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN payment_gateway VARCHAR(50) DEFAULT 'manual'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN payment_api_key VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN payment_api_secret VARCHAR(255) DEFAULT ''");
                }

                // Ensure accepted-payment-method columns exist
                $check_pm = $db->query("SHOW COLUMNS FROM school_settings LIKE 'pay_method_cash'");
                if ($check_pm->rowCount() == 0) {
                    foreach ($pay_methods as $pm) {
                        $db->exec("ALTER TABLE school_settings ADD COLUMN pay_method_$pm ENUM('0','1') DEFAULT '1'");
                    }
                }

                $update_query = "UPDATE school_settings SET
                    currency = :currency,
                    currency_symbol = :currency_symbol,
                    payment_gateway = :payment_gateway,
                    payment_api_key = :payment_api_key,
                    payment_api_secret = :payment_api_secret,
                    pay_method_cash = :pay_method_cash,
                    pay_method_bank = :pay_method_bank,
                    pay_method_card = :pay_method_card,
                    pay_method_mobile = :pay_method_mobile,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':currency' => $currency,
                    ':currency_symbol' => $currency_symbol,
                    ':payment_gateway' => $payment_gateway,
                    ':payment_api_key' => $payment_api_key,
                    ':payment_api_secret' => $payment_api_secret,
                    ':pay_method_cash' => $pay_method_values['cash'],
                    ':pay_method_bank' => $pay_method_values['bank'],
                    ':pay_method_card' => $pay_method_values['card'],
                    ':pay_method_mobile' => $pay_method_values['mobile']
                ]);

                require_once '../includes/settings_helper.php';
                clearSettingsCache();

                $success_message = "Payment settings updated successfully!";
                $active_tab = 'payment';
                break;
                
            case 'update_sms':
                $sms_gateway = filter_input(INPUT_POST, 'sms_gateway', FILTER_SANITIZE_STRING);
                // Whitelist the gateway so only a recognised provider key is stored.
                $valid_gateways = ['disabled', 'twilio', 'nexmo', 'termii', 'hubtel',
                                   'notifysms', 'onlinegh', 'wigal', 'nalopay', 'local'];
                if (!in_array($sms_gateway, $valid_gateways, true)) {
                    $sms_gateway = 'disabled';
                }
                $sms_api_key = filter_input(INPUT_POST, 'sms_api_key', FILTER_SANITIZE_STRING);
                $sms_api_secret = filter_input(INPUT_POST, 'sms_api_secret', FILTER_SANITIZE_STRING);
                $sms_sender_id = filter_input(INPUT_POST, 'sms_sender_id', FILTER_SANITIZE_STRING);
                // Default country dialling code used to normalize local phone numbers.
                $sms_country_code = preg_replace('/\D+/', '', $_POST['sms_country_code'] ?? '233');
                if ($sms_country_code === '') { $sms_country_code = '233'; }

                // SMS notification triggers (checkboxes) - get last value from array if multiple values exist
                $sms_absence_alerts = is_array($_POST['sms_absence_alerts']) ? end($_POST['sms_absence_alerts']) : ($_POST['sms_absence_alerts'] ?? '0');
                $sms_payment_reminders = is_array($_POST['sms_payment_reminders']) ? end($_POST['sms_payment_reminders']) : ($_POST['sms_payment_reminders'] ?? '0');
                $sms_exam_results = is_array($_POST['sms_exam_results']) ? end($_POST['sms_exam_results']) : ($_POST['sms_exam_results'] ?? '0');
                $sms_event_announcements = is_array($_POST['sms_event_announcements']) ? end($_POST['sms_event_announcements']) : ($_POST['sms_event_announcements'] ?? '0');
                $sms_emergency_alerts = is_array($_POST['sms_emergency_alerts']) ? end($_POST['sms_emergency_alerts']) : ($_POST['sms_emergency_alerts'] ?? '0');
                
                // Check if columns exist
                $check_columns = $db->query("SHOW COLUMNS FROM school_settings LIKE 'sms_api_key'");
                if ($check_columns->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_api_key VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_api_secret VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_sender_id VARCHAR(50) DEFAULT ''");
                }

                // Self-heal: add the country-code column the first time it is saved.
                $check_cc = $db->query("SHOW COLUMNS FROM school_settings LIKE 'sms_country_code'");
                if ($check_cc->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_country_code VARCHAR(5) DEFAULT '233'");
                }

                // Older schemas declared sms_gateway as a restrictive ENUM that only
                // listed a few providers, so picking e.g. Hubtel/Wigal silently saved
                // '' and the dropdown reverted to "Disabled". Widen it to a VARCHAR so
                // every supported gateway key persists correctly.
                $gateway_col = $db->query("SHOW COLUMNS FROM school_settings LIKE 'sms_gateway'")->fetch(PDO::FETCH_ASSOC);
                if ($gateway_col && stripos($gateway_col['Type'], 'enum') !== false) {
                    $db->exec("ALTER TABLE school_settings MODIFY COLUMN sms_gateway VARCHAR(50) NOT NULL DEFAULT 'disabled'");
                }

                // Check if notification trigger columns exist
                $check_triggers = $db->query("SHOW COLUMNS FROM school_settings LIKE 'sms_absence_alerts'");
                if ($check_triggers->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_absence_alerts ENUM('0','1') DEFAULT '0'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_payment_reminders ENUM('0','1') DEFAULT '0'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_exam_results ENUM('0','1') DEFAULT '0'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_event_announcements ENUM('0','1') DEFAULT '0'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN sms_emergency_alerts ENUM('0','1') DEFAULT '0'");
                }
                
                $update_query = "UPDATE school_settings SET
                    sms_gateway = :sms_gateway,
                    sms_api_key = :sms_api_key,
                    sms_api_secret = :sms_api_secret,
                    sms_sender_id = :sms_sender_id,
                    sms_country_code = :sms_country_code,
                    sms_absence_alerts = :sms_absence_alerts,
                    sms_payment_reminders = :sms_payment_reminders,
                    sms_exam_results = :sms_exam_results,
                    sms_event_announcements = :sms_event_announcements,
                    sms_emergency_alerts = :sms_emergency_alerts,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':sms_gateway' => $sms_gateway,
                    ':sms_api_key' => $sms_api_key,
                    ':sms_api_secret' => $sms_api_secret,
                    ':sms_sender_id' => $sms_sender_id,
                    ':sms_country_code' => $sms_country_code,
                    ':sms_absence_alerts' => $sms_absence_alerts,
                    ':sms_payment_reminders' => $sms_payment_reminders,
                    ':sms_exam_results' => $sms_exam_results,
                    ':sms_event_announcements' => $sms_event_announcements,
                    ':sms_emergency_alerts' => $sms_emergency_alerts
                ]);

                require_once '../includes/settings_helper.php';
                clearSettingsCache();

                $success_message = "SMS integration settings updated successfully!";
                $active_tab = 'sms';
                break;
                
            case 'update_ai':
                $ai_provider = filter_input(INPUT_POST, 'ai_provider', FILTER_SANITIZE_STRING);
                $ai_api_key = filter_input(INPUT_POST, 'ai_api_key', FILTER_SANITIZE_STRING);
                $ai_model = filter_input(INPUT_POST, 'ai_model', FILTER_SANITIZE_STRING);

                // Self-heal: add Draft AI columns if this is the first time.
                $check_ai = $db->query("SHOW COLUMNS FROM school_settings LIKE 'ai_provider'");
                if ($check_ai->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN ai_provider VARCHAR(50) DEFAULT 'builtin'");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN ai_api_key VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN ai_model VARCHAR(100) DEFAULT 'gemini-1.5-flash'");
                }

                $update_query = "UPDATE school_settings SET
                    ai_provider = :ai_provider,
                    ai_api_key = :ai_api_key,
                    ai_model = :ai_model,
                    updated_at = CURRENT_TIMESTAMP";

                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':ai_provider' => $ai_provider ?: 'builtin',
                    ':ai_api_key' => $ai_api_key ?? '',
                    ':ai_model' => $ai_model ?: 'gemini-1.5-flash'
                ]);

                $success_message = "Draft AI settings updated successfully!";
                $active_tab = 'ai';
                break;

            case 'update_email':
                $email_notifications = filter_input(INPUT_POST, 'email_notifications', FILTER_SANITIZE_STRING);
                $smtp_host = filter_input(INPUT_POST, 'smtp_host', FILTER_SANITIZE_STRING);
                $smtp_port = filter_input(INPUT_POST, 'smtp_port', FILTER_SANITIZE_NUMBER_INT);
                $smtp_username = filter_input(INPUT_POST, 'smtp_username', FILTER_SANITIZE_STRING);
                $smtp_password = filter_input(INPUT_POST, 'smtp_password', FILTER_SANITIZE_STRING);
                $smtp_encryption = filter_input(INPUT_POST, 'smtp_encryption', FILTER_SANITIZE_STRING);
                
                // Check if columns exist
                $check_columns = $db->query("SHOW COLUMNS FROM school_settings LIKE 'smtp_host'");
                if ($check_columns->rowCount() == 0) {
                    $db->exec("ALTER TABLE school_settings ADD COLUMN smtp_host VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN smtp_port INT DEFAULT 587");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN smtp_username VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN smtp_password VARCHAR(255) DEFAULT ''");
                    $db->exec("ALTER TABLE school_settings ADD COLUMN smtp_encryption VARCHAR(10) DEFAULT 'tls'");
                }
                
                $update_query = "UPDATE school_settings SET 
                    email_notifications = :email_notifications,
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_username = :smtp_username,
                    smtp_password = :smtp_password,
                    smtp_encryption = :smtp_encryption,
                    updated_at = CURRENT_TIMESTAMP";
                
                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':email_notifications' => $email_notifications,
                    ':smtp_host' => $smtp_host,
                    ':smtp_port' => $smtp_port,
                    ':smtp_username' => $smtp_username,
                    ':smtp_password' => $smtp_password,
                    ':smtp_encryption' => $smtp_encryption
                ]);
                
                $success_message = "Email integration settings updated successfully!";
                $active_tab = 'email';
                break;
                
            case 'update_school_theme':
                // Super admin sets the theme colour for the Main System or for an
                // individual school's isolated database.
                if (!$is_super) { throw new Exception('You are not authorized to change theme colours.'); }
                $target = $_POST['target_school'] ?? 'central';
                $theme  = filter_input(INPUT_POST, 'theme_color', FILTER_SANITIZE_STRING) ?: 'blue';

                if ($target === 'central') {
                    $stmt = $db->prepare("UPDATE school_settings SET theme_color = :t, updated_at = CURRENT_TIMESTAMP");
                    $stmt->execute([':t' => $theme]);
                    $success_message = "Theme colour updated for the Main System.";
                } else {
                    $sid = (int)$target;
                    $sch = $db->prepare("SELECT name, db_name FROM schools WHERE id = :id");
                    $sch->execute([':id' => $sid]);
                    $school = $sch->fetch(PDO::FETCH_ASSOC);
                    if (!$school || empty($school['db_name'])) {
                        throw new Exception('Selected school could not be found.');
                    }
                    $tenant = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $school['db_name'], DB_USER, DB_PASS);
                    $tenant->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $tenant->prepare("UPDATE school_settings SET theme_color = :t, updated_at = CURRENT_TIMESTAMP")
                           ->execute([':t' => $theme]);
                    $success_message = "Theme colour updated for {$school['name']}.";
                }

                require_once '../includes/settings_helper.php';
                clearSettingsCache();
                $active_tab = 'system';
                break;

            case 'update_permissions':
                if (!$is_super) { throw new Exception('You are not authorized to change permission settings.'); }
                $parent_portal = filter_input(INPUT_POST, 'parent_portal', FILTER_SANITIZE_STRING);
                $student_portal = filter_input(INPUT_POST, 'student_portal', FILTER_SANITIZE_STRING);
                
                $update_query = "UPDATE school_settings SET 
                    parent_portal = :parent_portal,
                    student_portal = :student_portal,
                    updated_at = CURRENT_TIMESTAMP";
                
                $stmt = $db->prepare($update_query);
                $stmt->execute([
                    ':parent_portal' => $parent_portal,
                    ':student_portal' => $student_portal
                ]);
                
                $success_message = "Permission settings updated successfully!";
                $active_tab = 'permissions';
                break;
        }
        
        // Refresh settings after update
        $settings_stmt = $db->query($settings_query);
        $settings = $settings_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
            $settings = $default_settings;
        }
        
    } catch (PDOException $e) {
        error_log("Settings update failed: " . $e->getMessage());
        $error_message = "Could not update settings. Please try again.";
    }
}

$title = "School Settings";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

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
                                <p class="text-blue-100 text-lg">Manage your school's profile, configuration, and system preferences</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-cog text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Success/Error Messages -->
                <?php if ($success_message): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-check-circle mr-2"></i>
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center">
                    <i class="fas fa-exclamation-circle mr-2"></i>
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
                <?php endif; ?>

                <!-- Tabbed Interface -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <!-- Tab Navigation -->
                    <div class="border-b border-gray-200 dark:border-gray-700">
                        <nav class="flex flex-wrap -mb-px overflow-x-auto">
                            <a href="?tab=school-info" class="tab-link <?php echo $active_tab === 'school-info' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-school mr-2"></i>
                                School Profile
                            </a>
                            <a href="?tab=signatures" class="tab-link <?php echo $active_tab === 'signatures' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-signature mr-2"></i>
                                Signatures
                            </a>
                            <a href="?tab=academics" class="tab-link <?php echo $active_tab === 'academics' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-graduation-cap mr-2"></i>
                                Academics
                            </a>
                            <?php if ($is_super): ?>
                            <a href="?tab=system" class="tab-link <?php echo $active_tab === 'system' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-cogs mr-2"></i>
                                System Settings
                            </a>
                            <?php endif; ?>
                            <a href="?tab=attendance" class="tab-link <?php echo $active_tab === 'attendance' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-clipboard-check mr-2"></i>
                                Attendance
                            </a>
                            <?php if ($is_super): ?>
                            <a href="?tab=permissions" class="tab-link <?php echo $active_tab === 'permissions' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-user-shield mr-2"></i>
                                Permissions
                            </a>
                            <?php endif; ?>
                            <a href="?tab=payment" class="tab-link <?php echo $active_tab === 'payment' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-credit-card mr-2"></i>
                                Payment
                            </a>
                            <a href="?tab=sms" class="tab-link <?php echo $active_tab === 'sms' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-sms mr-2"></i>
                                SMS Integration
                            </a>
                            <a href="?tab=email" class="tab-link <?php echo $active_tab === 'email' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-envelope mr-2"></i>
                                Email Integration
                            </a>
                            <a href="?tab=ai" class="tab-link <?php echo $active_tab === 'ai' ? 'active' : ''; ?> flex items-center px-6 py-4 text-sm font-medium border-b-2 transition-colors duration-200 whitespace-nowrap">
                                <i class="fas fa-wand-magic-sparkles mr-2"></i>
                                Draft AI
                            </a>
                        </nav>
                    </div>

                    <!-- Tab Content -->
                    <div class="p-6">
                        <?php include 'tabs/' . $active_tab . '.php'; ?>
                    </div>
                </div>
            </div>
        </main>
        
        <!-- Footer -->
        <?php include '../includes/footer.php'; ?>
    </div>
</div>

<style>
.tab-link {
    color: #6b7280;
    border-bottom-color: transparent;
}

.tab-link:hover {
    color: #3b82f6;
    border-bottom-color: #e5e7eb;
}

.tab-link.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}

.dark .tab-link {
    color: #9ca3af;
}

.dark .tab-link:hover {
    color: #60a5fa;
    border-bottom-color: #374151;
}

.dark .tab-link.active {
    color: #60a5fa;
    border-bottom-color: #60a5fa;
}
</style>
