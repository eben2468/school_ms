<?php
/**
 * Global Settings Helper
 * Provides functions to get and manage school settings throughout the system
 */

// Cache for settings to avoid multiple database queries
$_GLOBAL_SETTINGS_CACHE = null;

/**
 * Get all school settings
 * @return array
 */
function getSchoolSettings() {
    global $_GLOBAL_SETTINGS_CACHE, $pdo, $conn;
    
    // Return cached settings if available
    if ($_GLOBAL_SETTINGS_CACHE !== null) {
        return $_GLOBAL_SETTINGS_CACHE;
    }
    
    try {
        // Try PDO connection first
        if (isset($pdo) && $pdo) {
            $stmt = $pdo->query("SELECT * FROM school_settings LIMIT 1");
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        } 
        // Fallback to mysqli connection
        elseif (isset($conn) && $conn) {
            $result = $conn->query("SELECT * FROM school_settings LIMIT 1");
            $settings = $result ? $result->fetch_assoc() : false;
        }
        // Create connection if none exists
        else {
            require_once __DIR__ . '/../config/database.php';
            if (isset($pdo) && $pdo) {
                $stmt = $pdo->query("SELECT * FROM school_settings LIMIT 1");
                $settings = $stmt->fetch(PDO::FETCH_ASSOC);
            }
        }
        
        // Default settings if none found
        if (!$settings) {
            $settings = getDefaultSettings();
        }
        
        // Cache the settings
        $_GLOBAL_SETTINGS_CACHE = $settings;
        
        return $settings;
        
    } catch (Exception $e) {
        error_log("Error fetching school settings: " . $e->getMessage());
        return getDefaultSettings();
    }
}

/**
 * Get a specific school setting
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSchoolSetting($key, $default = null) {
    $settings = getSchoolSettings();
    return isset($settings[$key]) ? $settings[$key] : $default;
}

/**
 * Get default settings
 * @return array
 */
function getDefaultSettings() {
    return [
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
        'currency_symbol' => '₵',
        'timezone' => 'Africa/Accra',
        'terms_per_year' => '3',
        'grading_system' => 'percentage',
        'theme_color' => 'blue',
        'default_language' => 'en',
        'date_format' => 'Y-m-d',
        'time_format' => 'H:i',
        'sms_gateway' => 'disabled',
        'email_notifications' => 'enabled',
        'parent_portal' => 'enabled',
        'student_portal' => 'enabled',
        'maintenance_mode' => 'disabled',
        'registration_enabled' => 'enabled',
        'max_file_upload_size' => '10MB',
        'session_timeout' => '30',
        'backup_frequency' => 'weekly',
        'auto_backup' => 'enabled'
    ];
}

/**
 * Check whether an accepted payment method is enabled in settings.
 * Valid keys: 'cash', 'bank', 'card', 'mobile'. Defaults to enabled so that
 * existing installations keep working until an admin changes the setting.
 *
 * @param string $key
 * @return bool
 */
function isPaymentMethodEnabled($key) {
    $value = getSchoolSetting('pay_method_' . $key, '1');
    return (string)$value === '1';
}

/**
 * Currency symbol based on currency code
 * @param string $currency
 * @return string
 */
function getCurrencySymbol($currency = null) {
    if (!$currency) {
        $db_symbol = getSchoolSetting('currency_symbol');
        if ($db_symbol && $db_symbol !== '?') {
            return $db_symbol;
        }
        $currency = getSchoolSetting('currency', 'GHS');
    }
    
    $symbols = [
        'GHS' => '₵',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'NGN' => '₦',
        'KES' => 'KSh',
        'ZAR' => 'R'
    ];
    
    return isset($symbols[$currency]) ? $symbols[$currency] : $currency;
}

/**
 * Format currency amount
 * @param float $amount
 * @param string $currency
 * @return string
 */
function formatCurrency($amount, $currency = null) {
    if (!$currency) {
        $currency = getSchoolSetting('currency', 'GHS');
    }
    
    $symbol = getCurrencySymbol($currency);
    return $symbol . number_format($amount, 2);
}

/**
 * Format date according to school settings
 * @param string $date
 * @param string $format
 * @return string
 */
function formatSchoolDate($date, $format = null) {
    if (!$format) {
        $format = getSchoolSetting('date_format', 'Y-m-d');
    }
    
    if (is_string($date)) {
        $date = strtotime($date);
    }
    
    return date($format, $date);
}

/**
 * Get school timezone
 * @return string
 */
function getSchoolTimezone() {
    return getSchoolSetting('timezone', 'Africa/Accra');
}

/**
 * Set school timezone for current script
 */
function setSchoolTimezone() {
    $timezone = getSchoolTimezone();
    if ($timezone) {
        date_default_timezone_set($timezone);
    }
}

/**
 * Check if maintenance mode is enabled
 * @return bool
 */
function isMaintenanceMode() {
    return getSchoolSetting('maintenance_mode', 'disabled') === 'enabled';
}

/**
 * Check if registration is enabled
 * @return bool
 */
function isRegistrationEnabled() {
    return getSchoolSetting('registration_enabled', 'enabled') === 'enabled';
}

/**
 * Get theme color classes
 * @param string $type (primary, secondary, accent, gradient, etc.)
 * @return string
 */
function getThemeColorClass($type = 'primary') {
    $color = getSchoolSetting('theme_color', 'blue');

    $classes = [
        'primary' => "bg-{$color}-600 hover:bg-{$color}-700 text-white",
        'secondary' => "bg-{$color}-100 hover:bg-{$color}-200 text-{$color}-800",
        'accent' => "text-{$color}-600 hover:text-{$color}-800",
        'border' => "border-{$color}-500",
        'gradient' => getThemeGradient($color)
    ];

    return isset($classes[$type]) ? $classes[$type] : $classes['primary'];
}

/**
 * Get theme gradient CSS
 * @param string $color
 * @return string
 */
function getThemeGradient($color = null) {
    if (!$color) {
        $color = getSchoolSetting('theme_color', 'blue');
    }

    $gradients = [
        // Blue Family
        'blue' => 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)',
        'sky' => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',
        'dodgerblue' => 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)',
        'royalblue' => 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)',
        'navyblue' => 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)',
        'steelblue' => 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)',
        'cornflowerblue' => 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)',
        'lightblue' => 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)',
        'deepblue' => 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)',

        // Purple & Violet Family
        'indigo' => 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)',
        'purple' => 'linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)',
        'violet' => 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)',
        'lavender' => 'linear-gradient(135deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)',
        'plum' => 'linear-gradient(135deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)',
        'orchid' => 'linear-gradient(135deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)',

        // Pink & Rose Family
        'fuchsia' => 'linear-gradient(135deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)',
        'pink' => 'linear-gradient(135deg, #ec4899 0%, #db2777 50%, #be185d 100%)',
        'rose' => 'linear-gradient(135deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)',
        'hotpink' => 'linear-gradient(135deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)',
        'magenta' => 'linear-gradient(135deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)',
        'cherry' => 'linear-gradient(135deg, #de3163 0%, #dc143c 50%, #b22222 100%)',

        // Red & Orange Family
        'red' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)',
        'scarlet' => 'linear-gradient(135deg, #ff2400 0%, #dc143c 50%, #b22222 100%)',
        'crimson' => 'linear-gradient(135deg, #dc143c 0%, #b22222 50%, #8b0000 100%)',
        'burgundy' => 'linear-gradient(135deg, #800020 0%, #722f37 50%, #654321 100%)',
        'orange' => 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
        'coral' => 'linear-gradient(135deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)',
        'tangerine' => 'linear-gradient(135deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)',

        // Yellow & Gold Family
        'amber' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%)',
        'yellow' => 'linear-gradient(135deg, #eab308 0%, #ca8a04 50%, #a16207 100%)',
        'gold' => 'linear-gradient(135deg, #ffd700 0%, #ffb347 50%, #daa520 100%)',
        'honey' => 'linear-gradient(135deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)',
        'mustard' => 'linear-gradient(135deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)',

        // Green Family
        'lime' => 'linear-gradient(135deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)',
        'green' => 'linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%)',
        'emerald' => 'linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)',
        'jade' => 'linear-gradient(135deg, #00a86b 0%, #29ab87 50%, #50c878 100%)',
        'mint' => 'linear-gradient(135deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)',
        'olive' => 'linear-gradient(135deg, #808000 0%, #9acd32 50%, #6b8e23 100%)',
        'forest' => 'linear-gradient(135deg, #228b22 0%, #006400 50%, #013220 100%)',

        // Cyan & Teal Family
        'teal' => 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
        'cyan' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)',
        'turquoise' => 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)',
        'aqua' => 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)',
        'seafoam' => 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)',

        // Brown & Earth Tones
        'brown' => 'linear-gradient(135deg, #8b4513 0%, #a0522d 50%, #654321 100%)',
        'chocolate' => 'linear-gradient(135deg, #d2691e 0%, #8b4513 50%, #654321 100%)',
        'bronze' => 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)',
        'copper' => 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)',

        // Neutral & Dark
        'slate' => 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)',
        'gray' => 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)',
        'zinc' => 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)',
        'stone' => 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)',
        'neutral' => 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)',
        'charcoal' => 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)',
        'graphite' => 'linear-gradient(135deg, #424b5a 0%, #283048 50%, #1f242e 100%)',
        'black' => 'linear-gradient(135deg, #1f2937 0%, #111827 50%, #000000 100%)',

        // Multi-hue gradient blends
        'sunset' => 'linear-gradient(135deg, #ff6a00 0%, #ee0979 50%, #b5179e 100%)',
        'ocean' => 'linear-gradient(135deg, #2193b0 0%, #1c92d2 50%, #6dd5ed 100%)',
        'aurora' => 'linear-gradient(135deg, #00c9a7 0%, #4d8076 50%, #845ec2 100%)',
        'twilight' => 'linear-gradient(135deg, #9d4edd 0%, #6a0dad 50%, #4b0082 100%)',
        'flamingo' => 'linear-gradient(135deg, #fc466b 0%, #f6416c 50%, #ff6b6b 100%)',
        'sapphire' => 'linear-gradient(135deg, #2c5364 0%, #203a43 50%, #0f2027 100%)',
        'amethyst' => 'linear-gradient(135deg, #9d50bb 0%, #6e48aa 50%, #4a148c 100%)',
        'ruby' => 'linear-gradient(135deg, #e0245e 0%, #c2185b 50%, #880e4f 100%)',
        'peach' => 'linear-gradient(135deg, #ffc1a6 0%, #ff9a76 50%, #ff7e5f 100%)',
        'periwinkle' => 'linear-gradient(135deg, #8e9efc 0%, #6c63ff 50%, #5a55e0 100%)',
        'seagreen' => 'linear-gradient(135deg, #43cea2 0%, #3cb371 50%, #2e8b57 100%)',
        'midnight' => 'linear-gradient(135deg, #302b63 0%, #24243e 50%, #0f0c29 100%)',
        'cobalt' => 'linear-gradient(135deg, #3b82f6 0%, #0066cc 50%, #0047ab 100%)',
        'maroon' => 'linear-gradient(135deg, #a52a2a 0%, #800000 50%, #5e1414 100%)',
        'sand' => 'linear-gradient(135deg, #e4c590 0%, #d2b48c 50%, #c2a06b 100%)',
    ];

    return isset($gradients[$color]) ? $gradients[$color] : $gradients['blue'];
}

/**
 * Get theme CSS variables
 * @return string
 */
function getThemeCSSVariables() {
    $color = getSchoolSetting('theme_color', 'blue');
    $gradient = getThemeGradient($color);

    return "
    :root {
        --primary-gradient: {$gradient};
        --sidebar-gradient: {$gradient};
        --footer-gradient: {$gradient};
        --header-gradient: {$gradient};
        --theme-color: {$color};
    }
    ";
}

/**
 * Get school logo URL
 * @return string
 */
function getSchoolLogo() {
    $logo = getSchoolSetting('school_logo', '');
    $logo_path = __DIR__ . '/../uploads/logos/' . $logo;
    
    // Dynamically detect base path (supporting both subdirectory and virtual host setups)
    $base_path = '/school_ms';
    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $doc_root = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT']));
        $root_dir = str_replace('\\', '/', realpath(__DIR__ . '/..'));
        if (strpos($root_dir, $doc_root) === 0) {
            $base_path = substr($root_dir, strlen($doc_root));
        }
    }
    
    $base_path = '/' . trim($base_path, '/');
    if ($base_path === '/') {
        $base_path = '';
    }
    
    if ($logo && file_exists($logo_path)) {
        return $base_path . '/uploads/logos/' . $logo;
    }
    return $base_path . '/assets/images/logo.svg'; // Default logo
}

/**
 * Clear settings cache (call after updating settings)
 */
function clearSettingsCache() {
    global $_GLOBAL_SETTINGS_CACHE;
    $_GLOBAL_SETTINGS_CACHE = null;
}

/**
 * Update a school setting
 * @param string $key
 * @param mixed $value
 * @return bool
 */
function updateSchoolSetting($key, $value) {
    global $pdo, $conn;
    
    try {
        // Clear cache first
        clearSettingsCache();
        
        if (isset($pdo) && $pdo) {
            // Check if settings exist
            $check = $pdo->query("SELECT COUNT(*) FROM school_settings")->fetchColumn();
            
            if ($check > 0) {
                $stmt = $pdo->prepare("UPDATE school_settings SET `$key` = ?, updated_at = CURRENT_TIMESTAMP");
                return $stmt->execute([$value]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO school_settings (`$key`) VALUES (?)");
                return $stmt->execute([$value]);
            }
        } elseif (isset($conn) && $conn) {
            $value = $conn->real_escape_string($value);
            $check = $conn->query("SELECT COUNT(*) FROM school_settings")->fetch_row()[0];
            
            if ($check > 0) {
                return $conn->query("UPDATE school_settings SET `$key` = '$value', updated_at = CURRENT_TIMESTAMP");
            } else {
                return $conn->query("INSERT INTO school_settings (`$key`) VALUES ('$value')");
            }
        }
        
        return false;
        
    } catch (Exception $e) {
        error_log("Error updating school setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Maximum allowed upload size (in bytes) from the Max File Upload Size setting.
 * Accepts values like "10MB", "500KB", "1GB". Falls back to 10 MB.
 */
function getMaxUploadBytes() {
    $raw = strtoupper(trim((string) getSchoolSetting('max_file_upload_size', '10MB')));
    if (!preg_match('/^(\d+(?:\.\d+)?)\s*(KB|MB|GB)?$/', $raw, $m)) {
        return 10 * 1024 * 1024;
    }
    $num = (float) $m[1];
    switch ($m[2] ?? 'MB') {
        case 'KB': return (int) round($num * 1024);
        case 'GB': return (int) round($num * 1024 * 1024 * 1024);
        case 'MB':
        default:   return (int) round($num * 1024 * 1024);
    }
}

/**
 * Human-readable form of the Max File Upload Size setting (e.g. "10 MB").
 */
function getMaxUploadLabel() {
    $raw = strtoupper(trim((string) getSchoolSetting('max_file_upload_size', '10MB')));
    return preg_replace('/(\d)(KB|MB|GB)/', '$1 $2', $raw);
}

/**
 * Whether a new user account may be created, per the User Registration setting.
 *   enabled     -> always allowed
 *   admin_only  -> allowed only when the creator holds an admin-level role
 *   disabled    -> never allowed
 *
 * @param string|null $creatorRole role of the user attempting the creation
 *                                  (defaults to the current session role)
 */
function isUserRegistrationAllowed($creatorRole = null) {
    $mode = getSchoolSetting('registration_enabled', 'enabled');
    if ($mode === 'disabled') {
        return false;
    }
    if ($mode === 'admin_only') {
        $role = $creatorRole ?? ($_SESSION['role'] ?? '');
        return in_array($role, ['super_admin', 'school_admin', 'principal'], true);
    }
    return true; // 'enabled'
}

/**
 * Get academic year display format
 * @return string
 */
function getCurrentAcademicYear() {
    $start = getSchoolSetting('academic_year_start');
    $end = getSchoolSetting('academic_year_end');
    
    if ($start && $end) {
        $startYear = date('Y', strtotime($start));
        $endYear = date('Y', strtotime($end));
        return $startYear . '-' . $endYear;
    }
    
    return date('Y') . '-' . (date('Y') + 1);
}

/**
 * Get terms per year
 * @return int
 */
function getTermsPerYear() {
    return (int) getSchoolSetting('terms_per_year', 3);
}

/**
 * The grading-system display styles a school may choose from.
 * The keys are the values stored in school_settings.grading_system; the values
 * are the human-readable labels shown in the settings dropdown.
 * @return array
 */
function getGradingSystems() {
    return [
        'percentage' => 'Percentage (0-100%)',
        'letter'     => 'Letter Grades (A-F)',
        'gpa'        => 'GPA (4.0 Scale)',
        'points'     => 'Points (1-10)',
    ];
}

/**
 * Validate a grading-system value against the allowed styles.
 * @param string $value
 * @return bool
 */
function isValidGradingSystem($value) {
    return is_string($value) && array_key_exists($value, getGradingSystems());
}

/**
 * Get the configured grading system (display style).
 * Falls back to 'percentage' for empty or unrecognised stored values so the
 * rest of the system always receives a known, safe value.
 * @return string
 */
function getGradingSystem() {
    $value = getSchoolSetting('grading_system', 'percentage');
    return isValidGradingSystem($value) ? $value : 'percentage';
}

/**
 * Human-readable label for a grading system (defaults to the current one).
 * @param string|null $system
 * @return string
 */
function getGradingSystemLabel($system = null) {
    $system = $system ?: getGradingSystem();
    $systems = getGradingSystems();
    return $systems[$system] ?? $systems['percentage'];
}

/**
 * Default grading scales (WASSCE A1-F9), used as a fallback when the
 * grading_scales table is missing/empty so existing installations and
 * fresh tenants keep working without setup. Mirrors the seed in
 * setup_all_missing_tables.php.
 * @return array
 */
function getDefaultGradingScales() {
    return [
        ['min_score' => 80.00, 'max_score' => 100.00, 'grade' => 'A1', 'grade_point' => 4.0, 'interpretation' => 'Excellent'],
        ['min_score' => 70.00, 'max_score' => 79.99,  'grade' => 'B2', 'grade_point' => 3.5, 'interpretation' => 'Very Good'],
        ['min_score' => 65.00, 'max_score' => 69.99,  'grade' => 'B3', 'grade_point' => 3.0, 'interpretation' => 'Good'],
        ['min_score' => 60.00, 'max_score' => 64.99,  'grade' => 'C4', 'grade_point' => 2.5, 'interpretation' => 'Credit'],
        ['min_score' => 55.00, 'max_score' => 59.99,  'grade' => 'C5', 'grade_point' => 2.0, 'interpretation' => 'Credit'],
        ['min_score' => 50.00, 'max_score' => 54.99,  'grade' => 'C6', 'grade_point' => 1.5, 'interpretation' => 'Credit'],
        ['min_score' => 45.00, 'max_score' => 49.99,  'grade' => 'D7', 'grade_point' => 1.0, 'interpretation' => 'Pass'],
        ['min_score' => 40.00, 'max_score' => 44.99,  'grade' => 'E8', 'grade_point' => 0.5, 'interpretation' => 'Pass'],
        ['min_score' => 0.00,  'max_score' => 39.99,  'grade' => 'F9', 'grade_point' => 0.0, 'interpretation' => 'Fail'],
    ];
}

/**
 * Obtain a (tenant-aware) PDO connection for reading grading data.
 * Prefers an already-open global $pdo, otherwise opens one via the Database
 * class (which honours the active tenant in the session). Returns null on
 * failure so callers can fall back to defaults.
 * @return PDO|null
 */
function getGradingConnection() {
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        return $pdo;
    }

    static $conn = null;
    if ($conn instanceof PDO) {
        return $conn;
    }

    try {
        if (!class_exists('Database')) {
            require_once __DIR__ . '/../config/database.php';
        }
        $database = new Database();
        $conn = $database->getConnection();
    } catch (Exception $e) {
        error_log('getGradingConnection: ' . $e->getMessage());
        $conn = null;
    }
    return $conn;
}

/**
 * Get the active grading scales (mark range -> grade/grade point/interpretation).
 * This is the single source of truth for converting a numeric score to a grade
 * across the whole academic system. Result is cached per request. Falls back to
 * the WASSCE defaults when the grading_scales table is absent or empty.
 * @return array
 */
function getGradingScales() {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $scales = [];
    try {
        $conn = getGradingConnection();
        if ($conn instanceof PDO) {
            $stmt = $conn->query(
                "SELECT min_score, max_score, grade, grade_point, interpretation
                 FROM grading_scales WHERE is_active = 1 ORDER BY min_score DESC"
            );
            $scales = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Table missing or query failed: fall back to defaults below.
        $scales = [];
    }

    if (empty($scales)) {
        $scales = getDefaultGradingScales();
    }

    $cache = $scales;
    return $cache;
}

/**
 * Resolve a numeric score (0-100) to its grade information using the active
 * grading scales.
 * @param float|int|string|null $score
 * @return array{grade:string, grade_point:?float, interpretation:string}
 */
function getGradeInfoFromScore($score) {
    $out = ['grade' => 'N/A', 'grade_point' => null, 'interpretation' => ''];
    if ($score === null || $score === '') {
        return $out;
    }
    $score = (float) $score;

    foreach (getGradingScales() as $scale) {
        if ($score >= (float) $scale['min_score'] && $score <= (float) $scale['max_score']) {
            return [
                'grade'          => $scale['grade'],
                'grade_point'    => isset($scale['grade_point']) && $scale['grade_point'] !== null
                                        ? (float) $scale['grade_point'] : null,
                'interpretation' => $scale['interpretation'] ?? '',
            ];
        }
    }
    return $out;
}

/**
 * Letter grade for a score, per the active grading scales.
 * @param float|int|string|null $score
 * @return string
 */
function getGradeLetter($score) {
    return getGradeInfoFromScore($score)['grade'];
}

/**
 * Grade point (GPA value) for a score, per the active grading scales.
 * @param float|int|string|null $score
 * @return float|null
 */
function getGradePoint($score) {
    return getGradeInfoFromScore($score)['grade_point'];
}

/**
 * Format a numeric score (0-100) for display according to the school's chosen
 * grading system. This is the function display surfaces should call so grades
 * render consistently everywhere.
 *   percentage -> "85.0%"
 *   letter     -> "A1"      (from grading scales)
 *   gpa        -> "4.0"     (grade point from grading scales)
 *   points     -> "8/10"    (score mapped onto a 1-10 scale)
 * @param float|int|string|null $score
 * @param string|null $system  Override the configured system (optional)
 * @return string
 */
function formatGrade($score, $system = null) {
    if ($score === null || $score === '') {
        return 'N/A';
    }
    $score = (float) $score;
    $system = $system && isValidGradingSystem($system) ? $system : getGradingSystem();

    switch ($system) {
        case 'letter':
            return getGradeLetter($score);

        case 'gpa':
            $gp = getGradePoint($score);
            return $gp !== null ? number_format($gp, 1) : getGradeLetter($score);

        case 'points':
            $points = (int) round($score / 10);
            if ($points < 1) { $points = 1; }
            if ($points > 10) { $points = 10; }
            return $points . '/10';

        case 'percentage':
        default:
            return number_format($score, 1) . '%';
    }
}

/**
 * Tailwind colour classes (text + background) for a score badge. Based on the
 * numeric score so colours stay consistent regardless of the display style.
 * @param float|int|string|null $score
 * @return string
 */
function getGradeBadgeClass($score) {
    $score = (float) $score;
    if ($score >= 80) return 'text-green-600 bg-green-100 dark:bg-green-900/30 dark:text-green-300';
    if ($score >= 70) return 'text-blue-600 bg-blue-100 dark:bg-blue-900/30 dark:text-blue-300';
    if ($score >= 60) return 'text-indigo-600 bg-indigo-100 dark:bg-indigo-900/30 dark:text-indigo-300';
    if ($score >= 50) return 'text-yellow-600 bg-yellow-100 dark:bg-yellow-900/30 dark:text-yellow-300';
    if ($score >= 40) return 'text-orange-600 bg-orange-100 dark:bg-orange-900/30 dark:text-orange-300';
    return 'text-red-600 bg-red-100 dark:bg-red-900/30 dark:text-red-300';
}

// Set timezone when this file is included
setSchoolTimezone();
?>
