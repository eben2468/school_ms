<?php
/**
 * Dynamic Theme CSS Generator
 * This file generates CSS based on the current theme settings
 */

// Set content type to CSS
header('Content-Type: text/css');

// Include settings helper
require_once '../../includes/settings_helper.php';

// Get current theme
$theme_color = getSchoolSetting('theme_color', 'blue');
$theme_gradient = getThemeGradient($theme_color);

// Define theme-specific gradients and colors
$theme_configs = [
    'blue' => [
        'primary' => '#3b82f6',
        'secondary' => '#1e40af',
        'light' => '#dbeafe',
        'gradient' => 'linear-gradient(135deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #3b82f6 0%, #8b5cf6 50%, #6366f1 100%)'
    ],
    'indigo' => [
        'primary' => '#6366f1',
        'secondary' => '#4338ca',
        'light' => '#e0e7ff',
        'gradient' => 'linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #6366f1 0%, #8b5cf6 50%, #a855f7 100%)'
    ],
    'purple' => [
        'primary' => '#8b5cf6',
        'secondary' => '#7c3aed',
        'light' => '#ede9fe',
        'gradient' => 'linear-gradient(135deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #8b5cf6 0%, #a855f7 50%, #c084fc 100%)'
    ],
    'green' => [
        'primary' => '#10b981',
        'secondary' => '#059669',
        'light' => '#d1fae5',
        'gradient' => 'linear-gradient(135deg, #10b981 0%, #059669 50%, #047857 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #10b981 0%, #059669 50%, #047857 100%)'
    ],
    'emerald' => [
        'primary' => '#10b981',
        'secondary' => '#047857',
        'light' => '#d1fae5',
        'gradient' => 'linear-gradient(135deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #10b981 0%, #34d399 50%, #6ee7b7 100%)'
    ],
    'teal' => [
        'primary' => '#14b8a6',
        'secondary' => '#0d9488',
        'light' => '#ccfbf1',
        'gradient' => 'linear-gradient(135deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #14b8a6 0%, #0d9488 50%, #0f766e 100%)'
    ],
    'cyan' => [
        'primary' => '#06b6d4',
        'secondary' => '#0891b2',
        'light' => '#cffafe',
        'gradient' => 'linear-gradient(135deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #06b6d4 0%, #0891b2 50%, #0e7490 100%)'
    ],
    'red' => [
        'primary' => '#ef4444',
        'secondary' => '#dc2626',
        'light' => '#fee2e2',
        'gradient' => 'linear-gradient(135deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ef4444 0%, #dc2626 50%, #b91c1c 100%)'
    ],
    'rose' => [
        'primary' => '#f43f5e',
        'secondary' => '#e11d48',
        'light' => '#ffe4e6',
        'gradient' => 'linear-gradient(135deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #f43f5e 0%, #e11d48 50%, #be123c 100%)'
    ],
    'orange' => [
        'primary' => '#f97316',
        'secondary' => '#ea580c',
        'light' => '#fed7aa',
        'gradient' => 'linear-gradient(135deg, #f97316 0%, #ea580c 50%, #c2410c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #f97316 0%, #ea580c 50%, #c2410c 100%)'
    ],
    'amber' => [
        'primary' => '#f59e0b',
        'secondary' => '#d97706',
        'light' => '#fef3c7',
        'gradient' => 'linear-gradient(135deg, #f59e0b 0%, #d97706 50%, #b45309 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #f59e0b 0%, #d97706 50%, #b45309 100%)'
    ],
    'yellow' => [
        'primary' => '#eab308',
        'secondary' => '#ca8a04',
        'light' => '#fef9c3',
        'gradient' => 'linear-gradient(135deg, #eab308 0%, #ca8a04 50%, #a16207 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #eab308 0%, #ca8a04 50%, #a16207 100%)'
    ],
    'slate' => [
        'primary' => '#64748b',
        'secondary' => '#475569',
        'light' => '#f1f5f9',
        'gradient' => 'linear-gradient(135deg, #64748b 0%, #475569 50%, #334155 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #64748b 0%, #475569 50%, #334155 100%)'
    ],
    'gray' => [
        'primary' => '#6b7280',
        'secondary' => '#4b5563',
        'light' => '#f9fafb',
        'gradient' => 'linear-gradient(135deg, #6b7280 0%, #4b5563 50%, #374151 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #6b7280 0%, #4b5563 50%, #374151 100%)'
    ],
    'violet' => [
        'primary' => '#7c3aed',
        'secondary' => '#6d28d9',
        'light' => '#f3e8ff',
        'gradient' => 'linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%)'
    ],
    'fuchsia' => [
        'primary' => '#d946ef',
        'secondary' => '#c026d3',
        'light' => '#fdf4ff',
        'gradient' => 'linear-gradient(135deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #d946ef 0%, #c026d3 50%, #a21caf 100%)'
    ],
    'pink' => [
        'primary' => '#ec4899',
        'secondary' => '#db2777',
        'light' => '#fce7f3',
        'gradient' => 'linear-gradient(135deg, #ec4899 0%, #db2777 50%, #be185d 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ec4899 0%, #db2777 50%, #be185d 100%)'
    ],
    'lime' => [
        'primary' => '#84cc16',
        'secondary' => '#65a30d',
        'light' => '#f7fee7',
        'gradient' => 'linear-gradient(135deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #84cc16 0%, #65a30d 50%, #4d7c0f 100%)'
    ],
    'sky' => [
        'primary' => '#0ea5e9',
        'secondary' => '#0284c7',
        'light' => '#f0f9ff',
        'gradient' => 'linear-gradient(135deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #0ea5e9 0%, #0284c7 50%, #0369a1 100%)'
    ],
    'zinc' => [
        'primary' => '#71717a',
        'secondary' => '#52525b',
        'light' => '#fafafa',
        'gradient' => 'linear-gradient(135deg, #71717a 0%, #52525b 50%, #3f3f46 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #71717a 0%, #52525b 50%, #3f3f46 100%)'
    ],
    'stone' => [
        'primary' => '#78716c',
        'secondary' => '#57534e',
        'light' => '#fafaf9',
        'gradient' => 'linear-gradient(135deg, #78716c 0%, #57534e 50%, #44403c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #78716c 0%, #57534e 50%, #44403c 100%)'
    ],
    'neutral' => [
        'primary' => '#737373',
        'secondary' => '#525252',
        'light' => '#fafafa',
        'gradient' => 'linear-gradient(135deg, #737373 0%, #525252 50%, #404040 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #737373 0%, #525252 50%, #404040 100%)'
    ],

    // Extended Blue Family
    'dodgerblue' => [
        'primary' => '#1e90ff',
        'secondary' => '#4169e1',
        'light' => '#e6f3ff',
        'gradient' => 'linear-gradient(135deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #1e90ff 0%, #4169e1 50%, #0000cd 100%)'
    ],
    'royalblue' => [
        'primary' => '#4169e1',
        'secondary' => '#6a5acd',
        'light' => '#e6eeff',
        'gradient' => 'linear-gradient(135deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #4169e1 0%, #6a5acd 50%, #483d8b 100%)'
    ],
    'navyblue' => [
        'primary' => '#000080',
        'secondary' => '#191970',
        'light' => '#e6e6ff',
        'gradient' => 'linear-gradient(135deg, #000080 0%, #191970 50%, #0f0f23 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #000080 0%, #191970 50%, #0f0f23 100%)'
    ],
    'steelblue' => [
        'primary' => '#4682b4',
        'secondary' => '#5f9ea0',
        'light' => '#e6f2f7',
        'gradient' => 'linear-gradient(135deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #4682b4 0%, #5f9ea0 50%, #708090 100%)'
    ],
    'cornflowerblue' => [
        'primary' => '#6495ed',
        'secondary' => '#7b68ee',
        'light' => '#eef2ff',
        'gradient' => 'linear-gradient(135deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #6495ed 0%, #7b68ee 50%, #9370db 100%)'
    ],
    'lightblue' => [
        'primary' => '#87ceeb',
        'secondary' => '#87cefa',
        'light' => '#f0f8ff',
        'gradient' => 'linear-gradient(135deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #87ceeb 0%, #87cefa 50%, #b0e0e6 100%)'
    ],
    'deepblue' => [
        'primary' => '#00008b',
        'secondary' => '#0000cd',
        'light' => '#e6e6ff',
        'gradient' => 'linear-gradient(135deg, #00008b 0%, #0000cd 50%, #4169e1 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #00008b 0%, #0000cd 50%, #4169e1 100%)'
    ],

    // Extended Purple Family
    'lavender' => [
        'primary' => '#e6e6fa',
        'secondary' => '#dda0dd',
        'light' => '#faf8ff',
        'gradient' => 'linear-gradient(135deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #e6e6fa 0%, #dda0dd 50%, #da70d6 100%)'
    ],
    'plum' => [
        'primary' => '#dda0dd',
        'secondary' => '#ba55d3',
        'light' => '#faf5ff',
        'gradient' => 'linear-gradient(135deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #dda0dd 0%, #ba55d3 50%, #9932cc 100%)'
    ],
    'orchid' => [
        'primary' => '#da70d6',
        'secondary' => '#ba55d3',
        'light' => '#faf5ff',
        'gradient' => 'linear-gradient(135deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #da70d6 0%, #ba55d3 50%, #9370db 100%)'
    ],

    // Extended Pink Family
    'hotpink' => [
        'primary' => '#ff69b4',
        'secondary' => '#ff1493',
        'light' => '#fff0f8',
        'gradient' => 'linear-gradient(135deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ff69b4 0%, #ff1493 50%, #dc143c 100%)'
    ],
    'magenta' => [
        'primary' => '#ff00ff',
        'secondary' => '#da70d6',
        'light' => '#fff0ff',
        'gradient' => 'linear-gradient(135deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ff00ff 0%, #da70d6 50%, #ba55d3 100%)'
    ],
    'cherry' => [
        'primary' => '#de3163',
        'secondary' => '#dc143c',
        'light' => '#fff0f2',
        'gradient' => 'linear-gradient(135deg, #de3163 0%, #dc143c 50%, #b22222 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #de3163 0%, #dc143c 50%, #b22222 100%)'
    ],

    // Extended Red & Orange Family
    'scarlet' => [
        'primary' => '#ff2400',
        'secondary' => '#dc143c',
        'light' => '#fff0f0',
        'gradient' => 'linear-gradient(135deg, #ff2400 0%, #dc143c 50%, #b22222 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ff2400 0%, #dc143c 50%, #b22222 100%)'
    ],
    'burgundy' => [
        'primary' => '#800020',
        'secondary' => '#722f37',
        'light' => '#f5f0f0',
        'gradient' => 'linear-gradient(135deg, #800020 0%, #722f37 50%, #654321 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #800020 0%, #722f37 50%, #654321 100%)'
    ],
    'coral' => [
        'primary' => '#ff7f50',
        'secondary' => '#ff6347',
        'light' => '#fff8f5',
        'gradient' => 'linear-gradient(135deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ff7f50 0%, #ff6347 50%, #ff4500 100%)'
    ],
    'tangerine' => [
        'primary' => '#ff8c00',
        'secondary' => '#ff7f00',
        'light' => '#fff8f0',
        'gradient' => 'linear-gradient(135deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ff8c00 0%, #ff7f00 50%, #ff6600 100%)'
    ],

    // Extended Yellow & Gold Family
    'gold' => [
        'primary' => '#ffd700',
        'secondary' => '#ffb347',
        'light' => '#fffdf0',
        'gradient' => 'linear-gradient(135deg, #ffd700 0%, #ffb347 50%, #daa520 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ffd700 0%, #ffb347 50%, #daa520 100%)'
    ],
    'honey' => [
        'primary' => '#ffb347',
        'secondary' => '#ffa500',
        'light' => '#fffaf0',
        'gradient' => 'linear-gradient(135deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ffb347 0%, #ffa500 50%, #ff8c00 100%)'
    ],
    'mustard' => [
        'primary' => '#ffdb58',
        'secondary' => '#daa520',
        'light' => '#fffdf5',
        'gradient' => 'linear-gradient(135deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #ffdb58 0%, #daa520 50%, #b8860b 100%)'
    ],

    // Extended Green Family
    'jade' => [
        'primary' => '#00a86b',
        'secondary' => '#29ab87',
        'light' => '#f0fff8',
        'gradient' => 'linear-gradient(135deg, #00a86b 0%, #29ab87 50%, #50c878 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #00a86b 0%, #29ab87 50%, #50c878 100%)'
    ],
    'mint' => [
        'primary' => '#98fb98',
        'secondary' => '#90ee90',
        'light' => '#f8fff8',
        'gradient' => 'linear-gradient(135deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #98fb98 0%, #90ee90 50%, #00ff7f 100%)'
    ],
    'olive' => [
        'primary' => '#808000',
        'secondary' => '#9acd32',
        'light' => '#f8fff0',
        'gradient' => 'linear-gradient(135deg, #808000 0%, #9acd32 50%, #6b8e23 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #808000 0%, #9acd32 50%, #6b8e23 100%)'
    ],

    // Extended Cyan & Teal Family
    'turquoise' => [
        'primary' => '#40e0d0',
        'secondary' => '#48d1cc',
        'light' => '#f0ffff',
        'gradient' => 'linear-gradient(135deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #40e0d0 0%, #48d1cc 50%, #00ced1 100%)'
    ],
    'aqua' => [
        'primary' => '#00ffff',
        'secondary' => '#00e5ff',
        'light' => '#f0ffff',
        'gradient' => 'linear-gradient(135deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #00ffff 0%, #00e5ff 50%, #00bcd4 100%)'
    ],
    'seafoam' => [
        'primary' => '#9fe2bf',
        'secondary' => '#7fffd4',
        'light' => '#f8ffff',
        'gradient' => 'linear-gradient(135deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #9fe2bf 0%, #7fffd4 50%, #66cdaa 100%)'
    ],

    // Extended Neutral & Earth Tones
    'charcoal' => [
        'primary' => '#36454f',
        'secondary' => '#2f4f4f',
        'light' => '#f5f5f5',
        'gradient' => 'linear-gradient(135deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #36454f 0%, #2f4f4f 50%, #1c1c1c 100%)'
    ],
    'bronze' => [
        'primary' => '#cd7f32',
        'secondary' => '#b87333',
        'light' => '#faf8f5',
        'gradient' => 'linear-gradient(135deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #cd7f32 0%, #b87333 50%, #a0522d 100%)'
    ],
    'copper' => [
        'primary' => '#b87333',
        'secondary' => '#d2691e',
        'light' => '#faf8f5',
        'gradient' => 'linear-gradient(135deg, #b87333 0%, #d2691e 50%, #cd853f 100%)',
        'sidebar_gradient' => 'linear-gradient(180deg, #b87333 0%, #d2691e 50%, #cd853f 100%)'
    ]
];

$config = $theme_configs[$theme_color] ?? $theme_configs['blue'];

?>
/* Dynamic Theme CSS - Generated for <?php echo $theme_color; ?> theme */

:root {
    --primary-gradient: <?php echo $config['gradient']; ?>;
    --sidebar-gradient: <?php echo $config['sidebar_gradient']; ?>;
    --footer-gradient: <?php echo $config['gradient']; ?>;
    --header-gradient: <?php echo $config['gradient']; ?>;
    --theme-primary: <?php echo $config['primary']; ?>;
    --theme-secondary: <?php echo $config['secondary']; ?>;
    --theme-light: <?php echo $config['light']; ?>;
    --theme-color: <?php echo $theme_color; ?>;
}

/* Header Styles */
.gradient-bg,
.page-header-gradient,
.bg-gradient-to-r {
    background: var(--header-gradient) !important;
}

/* Universal page header class */
.page-header,
.page-header-gradient {
    background: var(--header-gradient) !important;
    color: white !important;
}

.page-header h1,
.page-header-gradient h1 {
    color: white !important;
}

.page-header p,
.page-header-gradient p {
    color: rgba(255, 255, 255, 0.8) !important;
}

/* Sidebar Styles */
.sidebar {
    background: var(--sidebar-gradient) !important;
}

/* Footer Styles */
footer {
    background: var(--footer-gradient) !important;
}

/* Button Styles */
.theme-button,
.btn-primary {
    background: var(--primary-gradient) !important;
    border: none !important;
}

.theme-button:hover,
.btn-primary:hover {
    opacity: 0.9 !important;
    transform: translateY(-1px) !important;
}

/* Card Headers */
.card-header-gradient {
    background: var(--primary-gradient) !important;
}

/* Progress Bars */
.progress-bar-theme {
    background: var(--primary-gradient) !important;
}

/* Links */
.theme-link {
    color: var(--theme-primary) !important;
}

.theme-link:hover {
    color: var(--theme-secondary) !important;
}

/* Badges */
.badge-theme {
    background: var(--theme-primary) !important;
    color: white !important;
}

/* Focus States */
.theme-focus:focus {
    border-color: var(--theme-primary) !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1) !important;
}

/* Login Page Specific */
.login-gradient {
    background: var(--primary-gradient) !important;
}

/* Dashboard Cards */
.dashboard-card-gradient {
    background: var(--primary-gradient) !important;
}

/* Notification Badges */
.notification-badge {
    background: var(--theme-primary) !important;
}

/* Active States */
.nav-active {
    background: rgba(255, 255, 255, 0.2) !important;
}

/* Scroll Progress */
.scroll-progress-bar {
    background: var(--primary-gradient) !important;
}

/* Quick Actions */
.quick-action-gradient {
    background: var(--primary-gradient) !important;
}

/* Status Indicators */
.status-online {
    color: #10b981 !important;
}

.status-offline {
    color: #ef4444 !important;
}

/* Animation for theme changes */
* {
    transition: background 0.3s ease, color 0.3s ease, border-color 0.3s ease !important;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .gradient-bg,
    .sidebar,
    footer {
        background: var(--primary-gradient) !important;
    }
}

/* Print styles */
@media print {
    .gradient-bg,
    .sidebar,
    footer {
        background: #f8f9fa !important;
        color: #000 !important;
    }
}
