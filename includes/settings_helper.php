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
 * Get currency symbol based on currency code
 * @param string $currency
 * @return string
 */
function getCurrencySymbol($currency = null) {
    if (!$currency) {
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
        'dodgerblue' => 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)',
        'royalblue' => 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)',
        'navyblue' => 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)',
        'steelblue' => 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)',
        'cornflowerblue' => 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)',
        'lightblue' => 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)',
        'deepblue' => 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)',
        'sky' => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',

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

        // Cyan & Teal Family
        'teal' => 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
        'cyan' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)',
        'turquoise' => 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)',
        'aqua' => 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)',
        'seafoam' => 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)',

        // Neutral & Earth Tones
        'slate' => 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)',
        'gray' => 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)',
        'zinc' => 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)',
        'stone' => 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)',
        'neutral' => 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)',
        'charcoal' => 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)',
        'bronze' => 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)',
        'copper' => 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)'
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
    if ($logo && file_exists('uploads/logos/' . $logo)) {
        return 'uploads/logos/' . $logo;
    }
    return 'assets/images/default-logo.png'; // Default logo
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
 * Get grading system
 * @return string
 */
function getGradingSystem() {
    return getSchoolSetting('grading_system', 'percentage');
}

// Set timezone when this file is included
setSchoolTimezone();
?>
