<?php
session_start();
require_once 'includes/settings_helper.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$current_theme = getSchoolSetting('theme_color', 'blue');
$school_name = getSchoolSetting('school_name', 'School Management System');

include 'includes/header.php';
include 'includes/sidebar.php';
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
                    <div class="dashboard-card-gradient rounded-2xl p-8 text-white shadow-xl" style="background: var(--primary-gradient);">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Theme Test Page</h1>
                                <p class="text-blue-100 text-lg">Testing dynamic theme system for <?php echo htmlspecialchars($school_name); ?></p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-palette mr-2"></i>
                                        Current Theme: <?php echo ucfirst($current_theme); ?>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-palette text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Theme Components Test -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Primary Button -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Primary Button</h3>
                        <button class="theme-button px-6 py-3 text-white rounded-lg hover:opacity-90 transition-all duration-200">
                            <i class="fas fa-star mr-2"></i>Primary Action
                        </button>
                    </div>

                    <!-- Secondary Elements -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Links & Text</h3>
                        <div class="space-y-2">
                            <a href="#" class="theme-link block">Theme Link Example</a>
                            <span class="badge-theme px-3 py-1 rounded-full text-sm">Badge</span>
                        </div>
                    </div>

                    <!-- Form Elements -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Form Elements</h3>
                        <input type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg theme-focus" placeholder="Focus me">
                    </div>
                </div>

                <!-- Color Palette Display -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6 mb-8">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Current Theme Gradient</h3>
                    <div class="h-32 rounded-lg shadow-lg" style="background: var(--primary-gradient);"></div>
                    <p class="text-gray-600 dark:text-gray-400 mt-4 text-center">
                        This gradient is applied to headers, sidebars, footers, and primary elements throughout the system.
                    </p>
                </div>

                <!-- Available Themes -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 p-6">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Available Theme Colors</h3>
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-8 gap-4">
                        <?php
                        $themes = [
                            // Blue Family
                            'blue' => 'Ocean Blue',
                            'dodgerblue' => 'Dodger Blue',
                            'royalblue' => 'Royal Blue',
                            'navyblue' => 'Navy Blue',
                            'steelblue' => 'Steel Blue',
                            'cornflowerblue' => 'Cornflower Blue',
                            'lightblue' => 'Light Blue',
                            'deepblue' => 'Deep Blue',
                            'sky' => 'Sky Blue',

                            // Purple & Violet Family
                            'indigo' => 'Royal Indigo',
                            'purple' => 'Mystic Purple',
                            'violet' => 'Deep Violet',
                            'lavender' => 'Lavender Dreams',
                            'plum' => 'Rich Plum',
                            'orchid' => 'Elegant Orchid',

                            // Pink & Rose Family
                            'fuchsia' => 'Electric Fuchsia',
                            'pink' => 'Soft Pink',
                            'rose' => 'Rose Garden',
                            'hotpink' => 'Hot Pink',
                            'magenta' => 'Vibrant Magenta',
                            'cherry' => 'Cherry Blossom',

                            // Red & Orange Family
                            'red' => 'Crimson Fire',
                            'scarlet' => 'Scarlet Red',
                            'burgundy' => 'Burgundy Wine',
                            'orange' => 'Sunset Orange',
                            'coral' => 'Coral Reef',
                            'tangerine' => 'Tangerine Dream',

                            // Yellow & Gold Family
                            'amber' => 'Golden Amber',
                            'yellow' => 'Sunshine Yellow',
                            'gold' => 'Pure Gold',
                            'honey' => 'Honey Gold',
                            'mustard' => 'Mustard Yellow',

                            // Green Family
                            'lime' => 'Electric Lime',
                            'green' => 'Forest Green',
                            'emerald' => 'Emerald Mint',
                            'jade' => 'Jade Green',
                            'mint' => 'Fresh Mint',
                            'olive' => 'Olive Branch',

                            // Cyan & Teal Family
                            'teal' => 'Teal Ocean',
                            'cyan' => 'Cyan Sky',
                            'turquoise' => 'Turquoise Waters',
                            'aqua' => 'Aqua Marine',
                            'seafoam' => 'Seafoam Green',

                            // Neutral & Earth Tones
                            'slate' => 'Modern Slate',
                            'gray' => 'Professional Gray',
                            'zinc' => 'Metallic Zinc',
                            'stone' => 'Natural Stone',
                            'neutral' => 'Warm Neutral',
                            'charcoal' => 'Charcoal Gray',
                            'bronze' => 'Bronze Metal',
                            'copper' => 'Copper Shine'
                        ];
                        
                        foreach ($themes as $color => $name):
                            $gradient = getThemeGradient($color);
                            $isActive = $color === $current_theme;
                        ?>
                        <div class="text-center">
                            <div class="w-16 h-16 rounded-lg shadow-lg mx-auto mb-2 <?php echo $isActive ? 'ring-4 ring-blue-500' : ''; ?>" 
                                 style="background: <?php echo $gradient; ?>;"></div>
                            <p class="text-xs text-gray-600 dark:text-gray-400"><?php echo $name; ?></p>
                            <?php if ($isActive): ?>
                            <p class="text-xs text-blue-600 font-medium">Active</p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-6 text-center">
                        <a href="settings/school.php" class="theme-button px-6 py-3 text-white rounded-lg hover:opacity-90 transition-all duration-200">
                            <i class="fas fa-cog mr-2"></i>Change Theme in Settings
                        </a>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include 'includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
// Real-time theme updates
document.addEventListener('DOMContentLoaded', function() {
    // Check for theme changes every 5 seconds
    setInterval(function() {
        // This would be enhanced with WebSocket or Server-Sent Events in production
        console.log('Theme system active - Current theme: <?php echo $current_theme; ?>');
    }, 5000);
});
</script>
