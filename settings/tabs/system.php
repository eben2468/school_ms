<!-- System Settings Tab -->
<div class="space-y-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">System Settings</h2>
            <p class="text-gray-600 dark:text-gray-400 mt-1">Configure general system settings, appearance, timezone, and date/time formats</p>
        </div>
    </div>

    <?php
    // Super admin may target the Main System or any individual school's theme.
    $is_super = (($user_role ?? ($_SESSION['role'] ?? '')) === 'super_admin');
    $all_schools = [];
    $school_themes = [];
    if ($is_super) {
        try {
            foreach ($db->query("SELECT id, name, db_name FROM schools ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) as $__s) {
                $all_schools[] = $__s;
                try {
                    $__t = new PDO("mysql:host=" . DB_HOST . ";dbname=" . $__s['db_name'], DB_USER, DB_PASS);
                    $__t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $school_themes[$__s['id']] = $__t->query("SELECT theme_color FROM school_settings LIMIT 1")->fetchColumn() ?: 'blue';
                } catch (Exception $__e) {
                    $school_themes[$__s['id']] = 'blue';
                }
            }
        } catch (Exception $__e) { /* schools table unavailable on this connection */ }
    }
    $central_theme = $settings['theme_color'] ?? 'blue';
    ?>

    <!-- Theme Colour — standalone form (super admin sets it per school) -->
    <form method="POST" class="space-y-4 mb-8">
        <input type="hidden" name="action" value="update_school_theme">
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Appearance &amp; Localization</h3>

            <?php if ($is_super): ?>
            <div class="mb-5 md:max-w-md">
                <label for="theme_target_school" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                    <i class="fas fa-school mr-1 text-indigo-500"></i> Apply theme to
                </label>
                <select id="theme_target_school" name="target_school"
                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <option value="central" data-theme="<?php echo htmlspecialchars($central_theme); ?>">Main System (Central Panel)</option>
                    <?php foreach ($all_schools as $__s): ?>
                    <option value="<?php echo (int)$__s['id']; ?>" data-theme="<?php echo htmlspecialchars($school_themes[$__s['id']] ?? 'blue'); ?>">
                        <?php echo htmlspecialchars($__s['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pick a school to theme — the swatches jump to that school's current colour.</p>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 gap-6">
                <!-- Theme Color -->
                <div class="md:col-span-2">
                    <label for="theme_color" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                        Theme Color
                    </label>
                    
                    <!-- Color Grid Preview -->
                    <div class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 lg:grid-cols-10 gap-3 mb-4">
                        <?php
                        $color_themes = [
                            // Blue Family
                            ['value' => 'blue', 'name' => 'Blue', 'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)'],
                            ['value' => 'sky', 'name' => 'Sky', 'gradient' => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)'],
                            ['value' => 'dodgerblue', 'name' => 'Dodger', 'gradient' => 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)'],
                            ['value' => 'royalblue', 'name' => 'Royal', 'gradient' => 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)'],
                            ['value' => 'navyblue', 'name' => 'Navy', 'gradient' => 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)'],
                            ['value' => 'steelblue', 'name' => 'Steel', 'gradient' => 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)'],
                            ['value' => 'lightblue', 'name' => 'Light Blue', 'gradient' => 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)'],
                            ['value' => 'deepblue', 'name' => 'Deep Blue', 'gradient' => 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)'],
                            
                            // Purple & Violet
                            ['value' => 'indigo', 'name' => 'Indigo', 'gradient' => 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)'],
                            ['value' => 'purple', 'name' => 'Purple', 'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)'],
                            ['value' => 'violet', 'name' => 'Violet', 'gradient' => 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)'],
                            ['value' => 'lavender', 'name' => 'Lavender', 'gradient' => 'linear-gradient(135deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)'],
                            ['value' => 'plum', 'name' => 'Plum', 'gradient' => 'linear-gradient(135deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)'],
                            
                            // Pink & Rose
                            ['value' => 'fuchsia', 'name' => 'Fuchsia', 'gradient' => 'linear-gradient(135deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)'],
                            ['value' => 'pink', 'name' => 'Pink', 'gradient' => 'linear-gradient(135deg, #ec4899 0%, #db2777 50%, #be185d 100%)'],
                            ['value' => 'rose', 'name' => 'Rose', 'gradient' => 'linear-gradient(135deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)'],
                            ['value' => 'hotpink', 'name' => 'Hot Pink', 'gradient' => 'linear-gradient(135deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)'],
                            ['value' => 'magenta', 'name' => 'Magenta', 'gradient' => 'linear-gradient(135deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)'],
                            
                            // Red & Orange
                            ['value' => 'red', 'name' => 'Red', 'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)'],
                            ['value' => 'scarlet', 'name' => 'Scarlet', 'gradient' => 'linear-gradient(135deg, #ff2400 0%, #dc143c 50%, #b22222 100%)'],
                            ['value' => 'crimson', 'name' => 'Crimson', 'gradient' => 'linear-gradient(135deg, #dc143c 0%, #b22222 50%, #8b0000 100%)'],
                            ['value' => 'orange', 'name' => 'Orange', 'gradient' => 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)'],
                            ['value' => 'coral', 'name' => 'Coral', 'gradient' => 'linear-gradient(135deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)'],
                            
                            // Yellow & Gold
                            ['value' => 'amber', 'name' => 'Amber', 'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%)'],
                            ['value' => 'yellow', 'name' => 'Yellow', 'gradient' => 'linear-gradient(135deg, #eab308 0%, #ca8a04 50%, #a16207 100%)'],
                            ['value' => 'gold', 'name' => 'Gold', 'gradient' => 'linear-gradient(135deg, #ffd700 0%, #ffb347 50%, #daa520 100%)'],
                            ['value' => 'honey', 'name' => 'Honey', 'gradient' => 'linear-gradient(135deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)'],
                            
                            // Green Family
                            ['value' => 'lime', 'name' => 'Lime', 'gradient' => 'linear-gradient(135deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)'],
                            ['value' => 'green', 'name' => 'Green', 'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%)'],
                            ['value' => 'emerald', 'name' => 'Emerald', 'gradient' => 'linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)'],
                            ['value' => 'jade', 'name' => 'Jade', 'gradient' => 'linear-gradient(135deg, #00a86b 0%, #29ab87 50%, #50c878 100%)'],
                            ['value' => 'mint', 'name' => 'Mint', 'gradient' => 'linear-gradient(135deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)'],
                            ['value' => 'forest', 'name' => 'Forest', 'gradient' => 'linear-gradient(135deg, #228b22 0%, #006400 50%, #013220 100%)'],
                            
                            // Cyan & Teal
                            ['value' => 'teal', 'name' => 'Teal', 'gradient' => 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)'],
                            ['value' => 'cyan', 'name' => 'Cyan', 'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)'],
                            ['value' => 'turquoise', 'name' => 'Turquoise', 'gradient' => 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)'],
                            ['value' => 'aqua', 'name' => 'Aqua', 'gradient' => 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)'],
                            
                            // Brown & Earth
                            ['value' => 'brown', 'name' => 'Brown', 'gradient' => 'linear-gradient(135deg, #8b4513 0%, #a0522d 50%, #654321 100%)'],
                            ['value' => 'chocolate', 'name' => 'Chocolate', 'gradient' => 'linear-gradient(135deg, #d2691e 0%, #8b4513 50%, #654321 100%)'],
                            ['value' => 'bronze', 'name' => 'Bronze', 'gradient' => 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)'],
                            ['value' => 'copper', 'name' => 'Copper', 'gradient' => 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)'],
                            
                            // Extra single-hue tones (engine already supports these)
                            ['value' => 'cornflowerblue', 'name' => 'Cornflower', 'gradient' => 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)'],
                            ['value' => 'orchid', 'name' => 'Orchid', 'gradient' => 'linear-gradient(135deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)'],
                            ['value' => 'cherry', 'name' => 'Cherry', 'gradient' => 'linear-gradient(135deg, #de3163 0%, #dc143c 50%, #b22222 100%)'],
                            ['value' => 'burgundy', 'name' => 'Burgundy', 'gradient' => 'linear-gradient(135deg, #800020 0%, #722f37 50%, #654321 100%)'],
                            ['value' => 'tangerine', 'name' => 'Tangerine', 'gradient' => 'linear-gradient(135deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)'],
                            ['value' => 'mustard', 'name' => 'Mustard', 'gradient' => 'linear-gradient(135deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)'],
                            ['value' => 'olive', 'name' => 'Olive', 'gradient' => 'linear-gradient(135deg, #808000 0%, #9acd32 50%, #6b8e23 100%)'],
                            ['value' => 'seafoam', 'name' => 'Seafoam', 'gradient' => 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)'],
                            ['value' => 'neutral', 'name' => 'Neutral', 'gradient' => 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)'],

                            // Multi-hue gradient blends
                            ['value' => 'sunset', 'name' => 'Sunset', 'gradient' => 'linear-gradient(135deg, #ff6a00 0%, #ee0979 50%, #b5179e 100%)'],
                            ['value' => 'ocean', 'name' => 'Ocean', 'gradient' => 'linear-gradient(135deg, #2193b0 0%, #1c92d2 50%, #6dd5ed 100%)'],
                            ['value' => 'aurora', 'name' => 'Aurora', 'gradient' => 'linear-gradient(135deg, #00c9a7 0%, #4d8076 50%, #845ec2 100%)'],
                            ['value' => 'twilight', 'name' => 'Twilight', 'gradient' => 'linear-gradient(135deg, #9d4edd 0%, #6a0dad 50%, #4b0082 100%)'],
                            ['value' => 'flamingo', 'name' => 'Flamingo', 'gradient' => 'linear-gradient(135deg, #fc466b 0%, #f6416c 50%, #ff6b6b 100%)'],
                            ['value' => 'sapphire', 'name' => 'Sapphire', 'gradient' => 'linear-gradient(135deg, #2c5364 0%, #203a43 50%, #0f2027 100%)'],
                            ['value' => 'amethyst', 'name' => 'Amethyst', 'gradient' => 'linear-gradient(135deg, #9d50bb 0%, #6e48aa 50%, #4a148c 100%)'],
                            ['value' => 'ruby', 'name' => 'Ruby', 'gradient' => 'linear-gradient(135deg, #e0245e 0%, #c2185b 50%, #880e4f 100%)'],
                            ['value' => 'peach', 'name' => 'Peach', 'gradient' => 'linear-gradient(135deg, #ffc1a6 0%, #ff9a76 50%, #ff7e5f 100%)'],
                            ['value' => 'periwinkle', 'name' => 'Periwinkle', 'gradient' => 'linear-gradient(135deg, #8e9efc 0%, #6c63ff 50%, #5a55e0 100%)'],
                            ['value' => 'seagreen', 'name' => 'Sea Green', 'gradient' => 'linear-gradient(135deg, #43cea2 0%, #3cb371 50%, #2e8b57 100%)'],
                            ['value' => 'midnight', 'name' => 'Midnight', 'gradient' => 'linear-gradient(135deg, #302b63 0%, #24243e 50%, #0f0c29 100%)'],
                            ['value' => 'cobalt', 'name' => 'Cobalt', 'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #0066cc 50%, #0047ab 100%)'],
                            ['value' => 'maroon', 'name' => 'Maroon', 'gradient' => 'linear-gradient(135deg, #a52a2a 0%, #800000 50%, #5e1414 100%)'],
                            ['value' => 'sand', 'name' => 'Sand', 'gradient' => 'linear-gradient(135deg, #e4c590 0%, #d2b48c 50%, #c2a06b 100%)'],
                            ['value' => 'graphite', 'name' => 'Graphite', 'gradient' => 'linear-gradient(135deg, #424b5a 0%, #283048 50%, #1f242e 100%)'],

                            // Neutral & Dark
                            ['value' => 'slate', 'name' => 'Slate', 'gradient' => 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)'],
                            ['value' => 'gray', 'name' => 'Gray', 'gradient' => 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)'],
                            ['value' => 'zinc', 'name' => 'Zinc', 'gradient' => 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)'],
                            ['value' => 'stone', 'name' => 'Stone', 'gradient' => 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)'],
                            ['value' => 'charcoal', 'name' => 'Charcoal', 'gradient' => 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)'],
                            ['value' => 'black', 'name' => 'Black', 'gradient' => 'linear-gradient(135deg, #1f2937 0%, #111827 50%, #000000 100%)'],
                        ];
                        
                        foreach ($color_themes as $theme):
                            $is_selected = $settings['theme_color'] === $theme['value'];
                        ?>
                        <div class="color-option-wrapper">
                            <input type="radio" 
                                   id="color_<?php echo $theme['value']; ?>" 
                                   name="theme_color" 
                                   value="<?php echo $theme['value']; ?>"
                                   class="hidden color-radio"
                                   <?php echo $is_selected ? 'checked' : ''; ?>
                                   data-gradient="<?php echo htmlspecialchars($theme['gradient']); ?>">
                            <label for="color_<?php echo $theme['value']; ?>" 
                                   class="color-option block cursor-pointer rounded-lg overflow-hidden transition-all duration-200 <?php echo $is_selected ? 'ring-4 ring-offset-2 ring-blue-500' : 'hover:ring-2 hover:ring-gray-300'; ?>"
                                   title="<?php echo $theme['name']; ?>">
                                <div class="w-full h-16 relative" style="background: <?php echo $theme['gradient']; ?>">
                                    <?php if ($is_selected): ?>
                                    <div class="absolute inset-0 flex items-center justify-center">
                                        <i class="fas fa-check text-white text-2xl drop-shadow-lg"></i>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="text-xs text-center py-1 bg-gray-50 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium">
                                    <?php echo $theme['name']; ?>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                        <i class="fas fa-info-circle mr-1"></i>
                        Pick a colour, then click Save Theme<?php echo $is_super ? ' to apply it to the school selected above.' : '.'; ?>
                    </p>
                </div>
            </div>

            <div class="flex justify-end mt-4">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2.5 px-6 rounded-lg transition-colors duration-200 flex items-center">
                    <i class="fas fa-palette mr-2"></i> Save Theme
                </button>
            </div>
        </div>
    </form>

    <!-- General system settings -->
    <form method="POST" class="space-y-8">
        <input type="hidden" name="action" value="update_system">

        <!-- Localization -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Localization</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Default Language -->
                <div>
                    <label for="default_language" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Default Language
                    </label>
                    <select id="default_language" name="default_language"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="en" <?php echo $settings['default_language'] === 'en' ? 'selected' : ''; ?>>English</option>
                        <option value="fr" <?php echo $settings['default_language'] === 'fr' ? 'selected' : ''; ?>>French</option>
                        <option value="es" <?php echo $settings['default_language'] === 'es' ? 'selected' : ''; ?>>Spanish</option>
                        <option value="ar" <?php echo $settings['default_language'] === 'ar' ? 'selected' : ''; ?>>Arabic</option>
                    </select>
                </div>

                <!-- Date Format -->
                <div>
                    <label for="date_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Date Format
                    </label>
                    <select id="date_format" name="date_format"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="Y-m-d" <?php echo $settings['date_format'] === 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD (<?php echo date('Y-m-d'); ?>)</option>
                        <option value="d/m/Y" <?php echo $settings['date_format'] === 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY (<?php echo date('d/m/Y'); ?>)</option>
                        <option value="m/d/Y" <?php echo $settings['date_format'] === 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY (<?php echo date('m/d/Y'); ?>)</option>
                        <option value="d-M-Y" <?php echo $settings['date_format'] === 'd-M-Y' ? 'selected' : ''; ?>>DD-Mon-YYYY (<?php echo date('d-M-Y'); ?>)</option>
                    </select>
                </div>

                <!-- Time Format -->
                <div>
                    <label for="time_format" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Time Format
                    </label>
                    <select id="time_format" name="time_format"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="H:i" <?php echo $settings['time_format'] === 'H:i' ? 'selected' : ''; ?>>24-hour (<?php echo date('H:i'); ?>)</option>
                        <option value="h:i A" <?php echo $settings['time_format'] === 'h:i A' ? 'selected' : ''; ?>>12-hour (<?php echo date('h:i A'); ?>)</option>
                    </select>
                </div>

                <!-- Timezone -->
                <div class="md:col-span-2">
                    <label for="timezone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Timezone
                    </label>
                    <select id="timezone" name="timezone"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <optgroup label="Africa">
                            <option value="Africa/Accra" <?php echo $settings['timezone'] === 'Africa/Accra' ? 'selected' : ''; ?>>Africa/Accra (GMT)</option>
                            <option value="Africa/Lagos" <?php echo $settings['timezone'] === 'Africa/Lagos' ? 'selected' : ''; ?>>Africa/Lagos (WAT)</option>
                            <option value="Africa/Nairobi" <?php echo $settings['timezone'] === 'Africa/Nairobi' ? 'selected' : ''; ?>>Africa/Nairobi (EAT)</option>
                            <option value="Africa/Cairo" <?php echo $settings['timezone'] === 'Africa/Cairo' ? 'selected' : ''; ?>>Africa/Cairo (EET)</option>
                        </optgroup>
                        <optgroup label="Americas">
                            <option value="America/New_York" <?php echo $settings['timezone'] === 'America/New_York' ? 'selected' : ''; ?>>America/New_York (EST)</option>
                            <option value="America/Chicago" <?php echo $settings['timezone'] === 'America/Chicago' ? 'selected' : ''; ?>>America/Chicago (CST)</option>
                            <option value="America/Los_Angeles" <?php echo $settings['timezone'] === 'America/Los_Angeles' ? 'selected' : ''; ?>>America/Los_Angeles (PST)</option>
                        </optgroup>
                        <optgroup label="Europe">
                            <option value="Europe/London" <?php echo $settings['timezone'] === 'Europe/London' ? 'selected' : ''; ?>>Europe/London (GMT)</option>
                            <option value="Europe/Paris" <?php echo $settings['timezone'] === 'Europe/Paris' ? 'selected' : ''; ?>>Europe/Paris (CET)</option>
                        </optgroup>
                        <optgroup label="Asia">
                            <option value="Asia/Dubai" <?php echo $settings['timezone'] === 'Asia/Dubai' ? 'selected' : ''; ?>>Asia/Dubai (GST)</option>
                            <option value="Asia/Kolkata" <?php echo $settings['timezone'] === 'Asia/Kolkata' ? 'selected' : ''; ?>>Asia/Kolkata (IST)</option>
                            <option value="Asia/Singapore" <?php echo $settings['timezone'] === 'Asia/Singapore' ? 'selected' : ''; ?>>Asia/Singapore (SGT)</option>
                        </optgroup>
                    </select>
                </div>
            </div>
        </div>

        <!-- System Control -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">System Control</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Maintenance Mode -->
                <div>
                    <label for="maintenance_mode" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Maintenance Mode
                    </label>
                    <select id="maintenance_mode" name="maintenance_mode"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="disabled" <?php echo $settings['maintenance_mode'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        <option value="enabled" <?php echo $settings['maintenance_mode'] === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                    </select>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">When enabled, only admins can access the system</p>
                </div>

                <!-- Registration Enabled -->
                <div>
                    <label for="registration_enabled" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        User Registration
                    </label>
                    <select id="registration_enabled" name="registration_enabled"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="enabled" <?php echo $settings['registration_enabled'] === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?php echo $settings['registration_enabled'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                        <option value="admin_only" <?php echo $settings['registration_enabled'] === 'admin_only' ? 'selected' : ''; ?>>Admin Only</option>
                    </select>
                </div>

                <!-- Max File Upload Size -->
                <div>
                    <label for="max_file_upload_size" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Max File Upload Size
                    </label>
                    <select id="max_file_upload_size" name="max_file_upload_size"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="5MB" <?php echo $settings['max_file_upload_size'] === '5MB' ? 'selected' : ''; ?>>5 MB</option>
                        <option value="10MB" <?php echo $settings['max_file_upload_size'] === '10MB' ? 'selected' : ''; ?>>10 MB</option>
                        <option value="20MB" <?php echo $settings['max_file_upload_size'] === '20MB' ? 'selected' : ''; ?>>20 MB</option>
                        <option value="50MB" <?php echo $settings['max_file_upload_size'] === '50MB' ? 'selected' : ''; ?>>50 MB</option>
                    </select>
                </div>

                <!-- Session Timeout -->
                <div>
                    <label for="session_timeout" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Session Timeout (minutes)
                    </label>
                    <input type="number" id="session_timeout" name="session_timeout" min="5" max="1440"
                        value="<?php echo htmlspecialchars($settings['session_timeout']); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                </div>
            </div>
        </div>

        <!-- Security & Access -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Security &amp; Access</h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Protect the login page by temporarily locking an account after repeated failed password attempts.</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Max Failed Login Attempts -->
                <div>
                    <label for="login_max_attempts" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Max Failed Login Attempts
                    </label>
                    <input type="number" id="login_max_attempts" name="login_max_attempts" min="0" max="20"
                        value="<?php echo htmlspecialchars($settings['login_max_attempts'] ?? '5'); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Number of wrong passwords allowed before lockout. Set to <strong>0</strong> to disable lockout.</p>
                </div>

                <!-- Lockout Duration -->
                <div>
                    <label for="login_lockout_duration" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Lockout Duration (minutes)
                    </label>
                    <input type="number" id="login_lockout_duration" name="login_lockout_duration" min="1" max="1440"
                        value="<?php echo htmlspecialchars($settings['login_lockout_duration'] ?? '15'); ?>"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">How long the account stays locked once the limit is reached.</p>
                </div>
            </div>
        </div>

        <!-- Backup Settings -->
        <div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Backup Configuration</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Backup Frequency -->
                <div>
                    <label for="backup_frequency" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Backup Frequency
                    </label>
                    <select id="backup_frequency" name="backup_frequency"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="daily" <?php echo $settings['backup_frequency'] === 'daily' ? 'selected' : ''; ?>>Daily</option>
                        <option value="weekly" <?php echo $settings['backup_frequency'] === 'weekly' ? 'selected' : ''; ?>>Weekly</option>
                        <option value="monthly" <?php echo $settings['backup_frequency'] === 'monthly' ? 'selected' : ''; ?>>Monthly</option>
                        <option value="manual" <?php echo $settings['backup_frequency'] === 'manual' ? 'selected' : ''; ?>>Manual Only</option>
                    </select>
                </div>

                <!-- Auto Backup -->
                <div>
                    <label for="auto_backup" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                        Automatic Backup
                    </label>
                    <select id="auto_backup" name="auto_backup"
                        class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white">
                        <option value="enabled" <?php echo $settings['auto_backup'] === 'enabled' ? 'selected' : ''; ?>>Enabled</option>
                        <option value="disabled" <?php echo $settings['auto_backup'] === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Submit Button -->
        <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-3 px-8 rounded-lg transition-colors duration-200 flex items-center">
                <i class="fas fa-save mr-2"></i>
                Save Changes
            </button>
        </div>
    </form>
</div>

<script>
// Live Theme Color Preview
document.addEventListener('DOMContentLoaded', function() {
    const colorRadios = document.querySelectorAll('.color-radio');
    const pageHeader = document.querySelector('.page-header-gradient');
    
    colorRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            // Remove selected styling from all options
            document.querySelectorAll('.color-option').forEach(option => {
                option.classList.remove('ring-4', 'ring-offset-2', 'ring-blue-500');
                option.classList.add('hover:ring-2', 'hover:ring-gray-300');
                // Remove check icon
                const checkIcon = option.querySelector('.fa-check');
                if (checkIcon) {
                    checkIcon.parentElement.remove();
                }
            });
            
            // Add selected styling to chosen option
            const selectedLabel = this.nextElementSibling;
            selectedLabel.classList.add('ring-4', 'ring-offset-2', 'ring-blue-500');
            selectedLabel.classList.remove('hover:ring-2', 'hover:ring-gray-300');
            
            // Add check icon
            const colorDiv = selectedLabel.querySelector('div[style*="background"]');
            if (colorDiv && !colorDiv.querySelector('.fa-check')) {
                const checkContainer = document.createElement('div');
                checkContainer.className = 'absolute inset-0 flex items-center justify-center';
                checkContainer.innerHTML = '<i class="fas fa-check text-white text-2xl drop-shadow-lg"></i>';
                colorDiv.appendChild(checkContainer);
            }
            
            // Apply gradient to page header for live preview
            const gradient = this.dataset.gradient;
            if (pageHeader && gradient) {
                pageHeader.style.background = gradient;

                // Show preview notification
                showPreviewNotification();
            }
        });
    });

    // Super admin: switching the target school jumps the swatches to that
    // school's currently saved theme colour.
    const targetSelect = document.getElementById('theme_target_school');
    if (targetSelect) {
        targetSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            const theme = opt ? opt.getAttribute('data-theme') : null;
            if (!theme) return;
            const radio = document.querySelector('.color-radio[value="' + theme + '"]');
            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    function showPreviewNotification() {
        // Remove existing notification if any
        const existingNotif = document.querySelector('.theme-preview-notification');
        if (existingNotif) {
            existingNotif.remove();
        }
        
        // Create notification
        const notification = document.createElement('div');
        notification.className = 'theme-preview-notification fixed top-24 right-6 bg-blue-600 text-white px-6 py-3 rounded-lg shadow-lg z-50 flex items-center space-x-3 animate-slide-in';
        notification.innerHTML = `
            <i class="fas fa-palette text-xl"></i>
            <div>
                <p class="font-semibold">Theme Preview Active</p>
                <p class="text-sm text-blue-100">Click "Save Changes" to apply permanently</p>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after 4 seconds
        setTimeout(() => {
            notification.style.animation = 'slide-out 0.3s ease-out';
            setTimeout(() => notification.remove(), 300);
        }, 4000);
    }
});
</script>

<style>
.color-option-wrapper {
    position: relative;
}

.color-option {
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.color-option:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 6px rgba(0, 0, 0, 0.15);
}

@keyframes slide-in {
    from {
        transform: translateX(400px);
        opacity: 0;
    }
    to {
        transform: translateX(0);
        opacity: 1;
    }
}

@keyframes slide-out {
    from {
        transform: translateX(0);
        opacity: 1;
    }
    to {
        transform: translateX(400px);
        opacity: 0;
    }
}

.animate-slide-in {
    animation: slide-in 0.3s ease-out;
}
</style>
