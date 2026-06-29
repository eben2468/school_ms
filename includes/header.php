<?php
// System Control enforcement (maintenance mode, session timeout, scheduled
// backups). Runs before any output so it can redirect / show 503 cleanly.
require_once dirname(__DIR__) . '/includes/system_guard.php';
?><!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <?php
    // Expose a CSRF token for forms and AJAX (see footer.php auto-attach script).
    require_once dirname(__DIR__) . '/includes/csrf.php';
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES); ?>">
    <?php
    // Include settings helper
    require_once dirname(__DIR__) . '/includes/settings_helper.php';
    $school_name = getSchoolSetting('school_name', 'School Management System');
    ?>
    <title><?php
    $current_page = basename($_SERVER['PHP_SELF']);
    $current_dir = basename(dirname($_SERVER['PHP_SELF']));

    // Check multiple sources for title
    $page_title = '';
    if (isset($title) && $title !== '' && $title !== 0 && $title !== '0' && $title !== false && $title !== null) {
        $page_title = $title;
    } elseif (isset($GLOBALS['title']) && $GLOBALS['title'] !== '' && $GLOBALS['title'] !== 0 && $GLOBALS['title'] !== '0' && $GLOBALS['title'] !== false && $GLOBALS['title'] !== null) {
        $page_title = $GLOBALS['title'];
    } elseif (isset($_SESSION['page_title']) && $_SESSION['page_title'] !== '' && $_SESSION['page_title'] !== 0 && $_SESSION['page_title'] !== '0' && $_SESSION['page_title'] !== false && $_SESSION['page_title'] !== null) {
        $page_title = $_SESSION['page_title'];
    } elseif (defined('PAGE_TITLE') && PAGE_TITLE !== '' && PAGE_TITLE !== 0 && PAGE_TITLE !== '0' && PAGE_TITLE !== false && PAGE_TITLE !== null) {
        $page_title = PAGE_TITLE;
    }

    // Dynamic self-healing fallback for online learning module
    if (empty($page_title) && $current_dir === 'online_learning') {
        switch ($current_page) {
            case 'index.php':
                $page_title = 'Online Learning Tools';
                break;
            case 'virtual_classroom.php':
                $page_title = 'Virtual Classroom';
                break;
            case 'materials.php':
                $page_title = 'Learning Materials';
                break;
            case 'quizzes.php':
                $page_title = 'Quizzes & Tests';
                break;
            case 'submissions.php':
                $page_title = 'Assignment Submissions';
                break;
            case 'discussions.php':
                $page_title = 'Online Discussions';
                break;
            case 'discussion_view.php':
                $page_title = 'Online Discussions';
                break;
        }
    }

    echo ($page_title !== '' && $page_title !== '0' && $page_title !== 0 && $page_title !== null) ? htmlspecialchars($page_title) . ' - ' : '';
    echo htmlspecialchars($school_name);
    ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="/school_ms/assets/css/app.css" rel="stylesheet">
    <link href="/school_ms/assets/css/dynamic-theme.php" rel="stylesheet">
    <link href="/school_ms/assets/css/responsive.css" rel="stylesheet">
    <!-- Layout offset fallback: guarantees content clears the fixed sidebar on
         desktop even if the layout JavaScript is slow or blocked (e.g. on some
         hosts). Harmless when the JS runs (it overrides this). -->
    <style>
      @media (min-width: 1024px) {
        .sidebar-spacer { flex: 0 0 18rem; width: 18rem; }
        .sidebar-spacer.collapsed { flex: 0 0 4rem; width: 4rem; }
      }
    </style>
    <link href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.1/dist/cropper.min.js"></script>
    <script src="/school_ms/assets/js/image-cropper.js" defer></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script src="/school_ms/assets/js/app.js" defer></script>
    <script src="/school_ms/assets/js/export-utils.js"></script>
    <style>
        <?php echo getThemeCSSVariables(); ?>

        :root {
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }

        * {
            font-family: 'Inter', sans-serif;
        }

        .gradient-bg {
            background: var(--header-gradient);
        }

        .glass-effect {
            background: var(--glass-bg);
            backdrop-filter: blur(10px);
            border: 1px solid var(--glass-border);
        }

        .sidebar {
            background: var(--sidebar-gradient);
            min-height: calc(100vh - 4rem);
        }

        .content-area {
            min-height: calc(100vh - 4rem);
        }

        body {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
            transition: all 0.3s ease;
            margin: 0;
            padding: 0;
        }

        body.dark {
            background-color: #0f172a;
            color: #e2e8f0;
        }

        /* Main content area with proper spacing */
        main {
            margin-top: 20px; /* Space for fixed header */
            flex: 1;
        }

        /* Ensure no spacing issues in dark mode */
        :root {
            --root-font-size: 12px; /* Scale down entire system (text, padding, margins, sizes) by 25% */
        }

        html {
            margin: 0;
            padding: 0;
            font-size: var(--root-font-size) !important;
        }

        html.dark {
            background-color: #0f172a;
        }

        /* Global layout overrides for 56px header height */
        header.gradient-bg {
            height: 56px !important;
        }
        
        #sidebar, .sidebar {
            top: 56px !important;
            height: calc(100vh - 56px) !important;
            min-height: calc(100vh - 56px) !important;
        }
        
        [style*="margin-top: 80px"],
        [style*="margin-top:80px"],
        [style*="margin-top: 80px;"],
        [style*="margin-top:80px;"] {
            margin-top: 56px !important;
        }

        /* Smooth transitions for theme changes */
        html, body, main {
            transition: background-color 0.3s ease, color 0.3s ease;
        }

        .dropdown-content {
            position: absolute;
            right: 0;
            top: 100%;
            min-width: 200px;
            z-index: 50;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px);
            transition: all 0.3s ease;
            pointer-events: none;
        }

        .dropdown:hover .dropdown-content,
        .dropdown-content:hover {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
            pointer-events: auto;
        }

        /* Add a bridge area to prevent dropdown from closing */
        .dropdown::after {
            content: '';
            position: absolute;
            top: 100%;
            right: 0;
            width: 100%;
            height: 10px;
            background: transparent;
        }

        .search-overlay {
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
        }

        .notification-badge {
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .breadcrumb-separator::before {
            content: '/';
            margin: 0 0.5rem;
            color: #94a3b8;
        }

        .header-shadow {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
    </style>
</head>
<body class="bg-gray-50 dark:bg-gray-900 transition-colors duration-300" x-data="{ darkMode: $store.theme.dark }" :class="{ 'dark': $store.theme.dark }">
    <!-- Modern Header -->
    <header class="gradient-bg text-white header-shadow fixed top-0 left-0 right-0 z-50" x-data="headerData()" style="height: 56px;">
        <div class="px-3 sm:px-4 h-full w-full">
            <div class="flex justify-between items-center h-full w-full">
                <!-- Left Section - Extreme Left -->
                <div class="flex items-center space-x-3 pl-2">
                    <!-- Sidebar Toggle (Mobile & Desktop) -->
                    <button id="sidebar-toggle" class="p-2 rounded-lg hover:bg-white/10 transition-colors duration-200" title="Toggle Sidebar">
                        <i class="fas fa-bars text-xl"></i>
                    </button>

                    <!-- Logo and Brand -->
                    <a href="<?php echo (($_SESSION['role'] ?? '') === 'parent') ? '/school_ms/parent/dashboard.php' : '/school_ms/dashboard.php'; ?>" class="flex items-center space-x-3 group">
                        <div class="relative">
                            <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center group-hover:bg-white/30 transition-colors duration-200 overflow-hidden">
                                <?php 
                                $logo_url = getSchoolLogo();
                                if ($logo_url): 
                                ?>
                                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="w-full h-full object-contain p-1">
                                <?php else: ?>
                                    <i class="fas fa-graduation-cap text-xl"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="hidden sm:block">
                            <h1 class="text-xl font-bold tracking-tight header-school-name" data-school-name><?php echo htmlspecialchars($school_name); ?></h1>
                            <p class="text-xs opacity-75">School Management System</p>
                        </div>
                    </a>
                </div>

                <!-- Center Section - Academic Context, Date and Time -->
                <div class="header-center-section flex items-center space-x-6 text-center">
                    <!-- Academic Context -->
                    <?php
                    require_once dirname(__DIR__) . '/config/database.php';
                    $database = new Database();
                    $academic_context = $database->getCurrentAcademicContext();
                    ?>
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-graduation-cap text-sm"></i>
                        </div>
                        <div class="text-center">
                            <div class="text-sm font-semibold"><?php echo htmlspecialchars($academic_context['year_name']); ?></div>
                            <div class="text-xs opacity-75"><?php echo htmlspecialchars($academic_context['term_name']); ?></div>
                        </div>
                    </div>

                    <!-- Time -->
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-sm"></i>
                        </div>
                        <div class="text-lg font-semibold" x-text="currentTime || 'Loading...'"></div>
                    </div>

                    <!-- Date -->
                    <div class="flex items-center space-x-2">
                        <div class="w-8 h-8 bg-white/10 rounded-lg flex items-center justify-center">
                            <i class="fas fa-calendar-alt text-sm"></i>
                        </div>
                        <div class="text-lg font-semibold" x-text="currentDate || 'Loading...'"></div>
                    </div>
                </div>

                <!-- Right Section - Extreme Right -->
                <div class="header-right-section flex items-center space-x-3 pr-2">
                    <!-- Quick Actions -->
                    <div class="hidden lg:flex items-center space-x-2">
                        <!-- Search Button -->
                        <button @click="$store.search.toggle()" class="p-2 rounded-lg hover:bg-white/10 transition-colors duration-200" title="Search (Ctrl+K)">
                            <i class="fas fa-search text-lg"></i>
                        </button>

                        <?php if (in_array($_SESSION['role'] ?? '', ['super_admin', 'school_admin', 'principal'])): ?>
                        <!-- Quick Add Dropdown -->
                        <div class="dropdown relative">
                            <button class="p-2 rounded-lg hover:bg-white/10 transition-colors duration-200" title="Quick Add">
                                <i class="fas fa-plus text-lg"></i>
                            </button>
                            <div class="dropdown-content bg-white dark:bg-gray-800 shadow-xl rounded-xl mt-2 py-2 text-gray-800 dark:text-gray-200 w-56">
                                <a href="/school_ms/students/enroll.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <i class="fas fa-user-plus mr-3 w-4 text-blue-500"></i> Add Student
                                </a>
                                <a href="/school_ms/academic/classes/create.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <i class="fas fa-chalkboard mr-3 w-4 text-purple-500"></i> Create Class
                                </a>
                                <a href="/school_ms/academic/assignments/create.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                    <i class="fas fa-tasks mr-3 w-4 text-orange-500"></i> Create Assignment
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Chat Support Notifications (for support agents only) -->
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                    <div class="dropdown relative">
                        <button id="chatNotificationBtn" class="relative p-2 rounded-lg hover:bg-white/10 transition-colors duration-200">
                            <i class="fas fa-comments text-lg"></i>
                            <span id="chatNotificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center notification-badge hidden">0</span>
                        </button>
                        <div class="dropdown-content bg-white dark:bg-gray-800 shadow-xl rounded-xl mt-2 py-2 text-gray-800 dark:text-gray-200 w-80">
                            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h3 class="font-semibold">Support Chat</h3>
                                    <span id="chatNotificationCount" class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full">0 new</span>
                                </div>
                            </div>
                            <div id="chatNotificationContent" class="max-h-80 overflow-y-auto">
                                <div class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                    Loading chat notifications...
                                </div>
                            </div>
                            <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700">
                                <a href="/school_ms/chat/admin_dashboard.php" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">View Chat Dashboard</a>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Notifications -->
                    <div class="dropdown relative">
                        <button id="notificationBtn" class="relative p-2 rounded-lg hover:bg-white/10 transition-colors duration-200">
                            <i class="fas fa-bell text-lg"></i>
                            <span id="notificationBadge" class="absolute -top-1 -right-1 bg-red-500 text-xs rounded-full h-5 w-5 flex items-center justify-center notification-badge hidden">0</span>
                        </button>
                        <div class="dropdown-content bg-white dark:bg-gray-800 shadow-xl rounded-xl mt-2 py-2 text-gray-800 dark:text-gray-200 w-80">
                            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <div class="flex items-center justify-between">
                                    <h3 class="font-semibold">Notifications</h3>
                                    <div class="flex items-center space-x-2">
                                        <span id="notificationCount" class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full">0 new</span>
                                        <button id="markAllNotificationsRead" class="text-xs text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300" title="Mark all as read">
                                            <i class="fas fa-check-double"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div id="notificationContent" class="max-h-80 overflow-y-auto">
                                <div class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">
                                    Loading notifications...
                                </div>
                            </div>
                            <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700 flex items-center justify-between">
                                <a href="/school_ms/notifications.php" class="text-sm text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300">View all notifications</a>
                                <div class="flex space-x-2">
                                    <button id="refreshNotifications" class="text-xs text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300" title="Refresh">
                                        <i class="fas fa-sync-alt"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- User Menu -->
                    <div class="dropdown relative">
                        <button class="flex items-center space-x-3 p-2 rounded-lg hover:bg-white/10 transition-colors duration-200">
                            <div class="w-8 h-8 rounded-full overflow-hidden border-2 border-white/30">
                                <?php if (isset($_SESSION['profile_picture']) && !empty($_SESSION['profile_picture'])): ?>
                                    <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>"
                                         alt="Profile Picture"
                                         class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-white/20 flex items-center justify-center">
                                        <i class="fas fa-user text-sm text-white"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="hidden lg:block text-left">
                                <p class="text-sm font-medium"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Guest'; ?></p>
                                <p class="text-xs opacity-75"><?php echo isset($_SESSION['role']) ? htmlspecialchars(formatRoleName($_SESSION['role'])) : 'Guest'; ?></p>
                            </div>
                            <i class="fas fa-chevron-down text-xs hidden lg:block"></i>
                        </button>
                        <div class="dropdown-content bg-white dark:bg-gray-800 shadow-xl rounded-xl mt-2 py-2 text-gray-800 dark:text-gray-200 w-56">
                            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <p class="font-medium"><?php echo isset($_SESSION['user_name']) ? htmlspecialchars($_SESSION['user_name']) : 'Guest'; ?></p>
                                <p class="text-sm text-gray-500 dark:text-gray-400"><?php echo isset($_SESSION['email']) ? htmlspecialchars($_SESSION['email']) : ''; ?></p>
                            </div>
                            <a href="/school_ms/profile.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                <i class="fas fa-user-circle mr-3 w-4"></i> My Profile
                            </a>
                            <?php if (isset($_SESSION['role']) && in_array($_SESSION['role'], ['super_admin', 'school_admin'], true)): ?>
                            <a href="/school_ms/settings/school.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                <i class="fas fa-cog mr-3 w-4"></i> Settings
                            </a>
                            <?php endif; ?>
                            <a href="/school_ms/help.php" class="flex items-center px-4 py-2 hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors duration-200">
                                <i class="fas fa-question-circle mr-3 w-4"></i> Help & Support
                            </a>
                            <div class="border-t border-gray-200 dark:border-gray-700 my-1"></div>
                            <a href="/school_ms/auth/logout.php" class="flex items-center px-4 py-2 hover:bg-red-50 dark:hover:bg-red-900/20 text-red-600 dark:text-red-400 transition-colors duration-200">
                                <i class="fas fa-sign-out-alt mr-3 w-4"></i> Sign Out
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>


    </header>

    <?php if (!empty($_SESSION['impersonator'])): ?>
    <!-- Super Admin Impersonation Banner -->
    <div class="fixed bottom-0 left-0 right-0 bg-amber-500 text-amber-950 shadow-2xl border-t-2 border-amber-600" style="z-index: 60;">
        <div class="px-4 py-2 flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2 text-sm font-semibold">
                <i class="fas fa-user-secret text-base"></i>
                <span>
                    Viewing <strong><?php echo htmlspecialchars($_SESSION['school_name'] ?? 'school'); ?></strong>
                    as super admin
                    <span class="hidden sm:inline opacity-80">— acting as <?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?></span>
                </span>
            </div>
            <form method="POST" action="/school_ms/settings/exit_impersonation.php" class="inline">
                <button type="submit"
                        class="bg-amber-950 text-amber-50 hover:bg-black hover:text-white font-bold px-4 py-1.5 rounded-lg text-xs transition-colors duration-150 flex items-center">
                    <i class="fas fa-arrow-left-from-bracket mr-1.5"></i> Exit to Super Admin
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Sidebar Overlay for Mobile -->
    <div id="sidebarOverlay" class="sidebar-overlay"></div>

    <!-- Search Modal -->
    <div x-show="$store.search.open" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 z-50 search-overlay" @click="$store.search.close()" style="display: none;">
        <div class="flex items-start justify-center min-h-screen pt-20 px-4">
            <div @click.stop class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100" x-transition:leave="transition ease-in duration-200" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700">
                    <div class="relative">
                        <i class="fas fa-search absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400"></i>
                        <input id="search-input" x-model="$store.search.query" @input.debounce.300ms="$store.search.search()" type="text" placeholder="Search students, teachers, classes, or anything..." class="w-full pl-10 pr-4 py-3 border-0 bg-gray-50 dark:bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 focus:outline-none text-gray-900 dark:text-gray-100" autofocus>
                    </div>
                </div>
                <div class="p-4 max-h-96 overflow-y-auto">
                    <!-- Loading State -->
                    <div x-show="$store.search.loading" class="flex items-center justify-center py-8">
                        <i class="fas fa-spinner fa-spin text-gray-400 text-2xl"></i>
                        <span class="ml-2 text-gray-500 dark:text-gray-400">Searching...</span>
                    </div>

                    <!-- Search Results -->
                    <div x-show="!$store.search.loading && $store.search.results.length > 0" class="space-y-2">
                        <template x-for="result in $store.search.results" :key="result.id || result.title">
                            <a :href="result.url" class="flex items-center p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200" @click="$store.search.close()">
                                <i :class="result.icon" class="text-blue-500 mr-3 w-5"></i>
                                <div class="flex-1">
                                    <div class="text-gray-900 dark:text-gray-100 font-medium" x-text="result.title"></div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400" x-text="result.subtitle"></div>
                                </div>
                                <span class="text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-1 rounded capitalize" x-text="result.type"></span>
                            </a>
                        </template>
                    </div>

                    <!-- No Results -->
                    <div x-show="!$store.search.loading && $store.search.query.length >= 2 && $store.search.results.length === 0" class="text-center py-8">
                        <i class="fas fa-search text-gray-400 text-2xl mb-2"></i>
                        <p class="text-gray-500 dark:text-gray-400">No results found</p>
                        <p class="text-sm text-gray-400 dark:text-gray-500">Try searching with different keywords</p>
                    </div>

                    <!-- Default Quick Actions -->
                    <div x-show="!$store.search.loading && $store.search.query.length < 2" class="space-y-2">
                        <div class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-2">Quick Actions</div>
                        <a href="/school_ms/students/enroll.php" class="flex items-center p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200" @click="$store.search.close()">
                            <i class="fas fa-user-plus text-blue-500 mr-3"></i>
                            <span class="text-gray-900 dark:text-gray-100">Add New Student</span>
                        </a>
                        <a href="/school_ms/academic/classes/create.php" class="flex items-center p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200" @click="$store.search.close()">
                            <i class="fas fa-plus-circle text-green-500 mr-3"></i>
                            <span class="text-gray-900 dark:text-gray-100">Create New Class</span>
                        </a>
                        <a href="/school_ms/academic/assignments/create.php" class="flex items-center p-3 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200" @click="$store.search.close()">
                            <i class="fas fa-tasks text-purple-500 mr-3"></i>
                            <span class="text-gray-900 dark:text-gray-100">Create Assignment</span>
                        </a>
                    </div>
                </div>
                <div class="p-4 border-t border-gray-200 dark:border-gray-700 text-xs text-gray-500 dark:text-gray-400">
                    Press <kbd class="px-2 py-1 bg-gray-100 dark:bg-gray-700 rounded">ESC</kbd> to close
                </div>
            </div>
        </div>
    </div>



    <!-- JavaScript for Header Functionality -->
    <script>
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+K or Cmd+K to open search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                if (window.Alpine && window.Alpine.store('search')) {
                    window.Alpine.store('search').toggle();
                }
            }

            // ESC to close search
            if (e.key === 'Escape') {
                if (window.Alpine && window.Alpine.store('search')) {
                    window.Alpine.store('search').close();
                }
            }

            // Theme toggle with Ctrl+D
            if ((e.ctrlKey || e.metaKey) && e.key === 'd') {
                e.preventDefault();
                if (window.Alpine && window.Alpine.store('theme')) {
                    window.Alpine.store('theme').toggle();
                }
            }
        });

        // Alpine.js header data function
        function headerData() {
            return {
                notificationCount: 0,
                currentTime: '',
                currentDate: '',

                init() {
                    this.updateDateTime();
                    setInterval(() => {
                        this.updateDateTime();
                    }, 1000);

                    // Load notifications
                    loadNotifications();
                    setInterval(loadNotifications, 30000); // Refresh every 30 seconds

                    // Load chat notifications for support agents
                    <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
                    loadChatNotifications();
                    setInterval(loadChatNotifications, 30000); // Refresh every 30 seconds
                    <?php endif; ?>
                },

                updateDateTime() {
                    const now = new Date();
                    this.currentTime = now.toLocaleTimeString('en-US', {
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit',
                        hour12: true
                    });
                    this.currentDate = now.toLocaleDateString('en-US', {
                        weekday: 'short',
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    });
                }
            }
        }

        // Alpine.js stores for global state management
        document.addEventListener('alpine:init', () => {
            Alpine.store('search', {
                open: false,
                query: '',
                results: [],
                loading: false,

                toggle() {
                    this.open = !this.open;
                    if (this.open) {
                        this.$nextTick(() => {
                            document.querySelector('#search-input')?.focus();
                        });
                    }
                },

                close() {
                    this.open = false;
                    this.query = '';
                    this.results = [];
                },

                async search() {
                    if (this.query.length < 2) {
                        this.results = [];
                        return;
                    }

                    this.loading = true;
                    try {
                        const response = await fetch('/school_ms/api/search.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({ query: this.query })
                        });

                        if (response.ok) {
                            this.results = await response.json();
                        } else {
                            this.results = [];
                        }
                    } catch (error) {
                        console.error('Search error:', error);
                        this.results = [];
                    } finally {
                        this.loading = false;
                    }
                }
            });

            Alpine.store('theme', {
                dark: localStorage.getItem('darkMode') === 'true' || (localStorage.getItem('darkMode') === null && window.matchMedia('(prefers-color-scheme: dark)').matches),

                toggle() {
                    this.dark = !this.dark;
                    localStorage.setItem('darkMode', this.dark.toString());
                    document.documentElement.classList.toggle('dark', this.dark);
                    document.body.classList.toggle('dark', this.dark);

                    // Trigger custom event for other components
                    window.dispatchEvent(new CustomEvent('themeChanged', {
                        detail: { isDark: this.dark }
                    }));
                },

                init() {
                    document.documentElement.classList.toggle('dark', this.dark);
                    document.body.classList.toggle('dark', this.dark);
                }
            });

            Alpine.store('sidebar', {
                collapsed: window.innerWidth < 1024 ? false : localStorage.getItem('sidebarCollapsed') === 'true',

                toggle() {
                    if (window.innerWidth < 1024) {
                        this.collapsed = false;
                        return;
                    }
                    console.log('Alpine sidebar toggle called, current state:', this.collapsed);
                    this.collapsed = !this.collapsed;
                    localStorage.setItem('sidebarCollapsed', this.collapsed);
                    console.log('Alpine sidebar new state:', this.collapsed);

                    // Trigger a custom event for additional handling
                    window.dispatchEvent(new CustomEvent('sidebar-toggled', {
                        detail: { collapsed: this.collapsed }
                    }));
                },

                init() {
                    if (window.innerWidth < 1024) {
                        this.collapsed = false;
                    }
                }
            });
        });

        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Fallback for early initialization
            const darkMode = localStorage.getItem('darkMode') === 'true' || (localStorage.getItem('darkMode') === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
            document.documentElement.classList.toggle('dark', darkMode);
            document.body.classList.toggle('dark', darkMode);
        });

        // Additional theme initialization after Alpine is ready
        document.addEventListener('alpine:initialized', function() {
            if (window.Alpine && window.Alpine.store('theme')) {
                window.Alpine.store('theme').init();
            }
        });

        // Sidebar toggle functionality is handled by the unified handler in fix_sidebar_toggle.js

        // Function to update main content layout based on sidebar state
        function updateMainContentLayout() {
            const mainContent = document.querySelector('main');
            const sidebar = document.getElementById('sidebar');
            const sidebarSpace = document.getElementById('sidebar-space') || 
                                 document.querySelector('.sidebar-spacer') || 
                                 document.querySelector('.transition-all.duration-300.lg\\:block.hidden, .w-72.flex-shrink-0, .w-16.flex-shrink-0, .w-0.transition-all');
            const mainContainer = document.querySelector('.flex-1.flex.flex-col');

            if (sidebar && window.Alpine && window.Alpine.store('sidebar')) {
                const isCollapsed = window.Alpine.store('sidebar').collapsed;

                // Update sidebar space div to account for collapsed sidebar icons
                if (sidebarSpace) {
                    if (sidebarSpace.classList.contains('sidebar-spacer')) {
                        if (isCollapsed) {
                            sidebarSpace.classList.add('collapsed');
                        } else {
                            sidebarSpace.classList.remove('collapsed');
                        }
                    } else {
                        if (isCollapsed) {
                            sidebarSpace.className = 'w-16 transition-all duration-300 lg:block hidden';
                        } else {
                            sidebarSpace.className = 'w-72 transition-all duration-300 lg:block hidden';
                        }
                    }
                    if (mainContent) {
                        mainContent.style.marginLeft = '';
                        mainContent.style.width = '';
                    }
                }

                // For pages that use the flex layout, the sidebar space div handles the layout
                // For pages with direct main content, apply direct styles
                if (mainContent && !sidebarSpace) {
                    if (isCollapsed) {
                        // Sidebar is collapsed - account for icon space (4rem)
                        mainContent.style.marginLeft = '4rem';
                        mainContent.style.width = 'calc(100% - 4rem)';
                    } else {
                        // Sidebar is expanded (18rem)
                        mainContent.style.marginLeft = '18rem';
                        mainContent.style.width = 'calc(100% - 18rem)';
                    }

                    // Add smooth transition
                    mainContent.style.transition = 'margin-left 0.3s ease-in-out, width 0.3s ease-in-out';
                }
            }
        }

        // Initialize layout on page load
        document.addEventListener('alpine:initialized', function() {
            // Set initial layout
            setTimeout(() => {
                updateMainContentLayout();
            }, 100);

            // Watch for sidebar state changes
            if (window.Alpine && window.Alpine.store('sidebar')) {
                // Override the toggle method to include layout update
                const originalToggle = window.Alpine.store('sidebar').toggle;
                window.Alpine.store('sidebar').toggle = function() {
                    originalToggle.call(this);
                    setTimeout(() => {
                        updateMainContentLayout();
                    }, 50);
                };
            }
        });

        // Handle window resize to adjust layout
        window.addEventListener('resize', function() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.querySelector('main');
            const sidebarSpace = document.getElementById('sidebar-space') || 
                                 document.querySelector('.sidebar-spacer') || 
                                 document.querySelector('.transition-all.duration-300.lg\\:block.hidden, .w-72.flex-shrink-0, .w-16.flex-shrink-0, .w-0.transition-all');

            if (window.innerWidth < 1024) {
                // Mobile: Reset main content styles and sidebar space
                if (mainContent) {
                    mainContent.style.marginLeft = '';
                    mainContent.style.width = '';
                }
                if (sidebarSpace) {
                    if (sidebarSpace.classList.contains('sidebar-spacer')) {
                        sidebarSpace.classList.remove('collapsed');
                    } else {
                        sidebarSpace.className = 'w-72 transition-all duration-300 lg:block hidden';
                    }
                }
                // Ensure sidebar is hidden on mobile when collapsed
                if (sidebar && window.Alpine && window.Alpine.store('sidebar') && window.Alpine.store('sidebar').collapsed) {
                    sidebar.classList.add('-translate-x-full');
                }
            } else {
                // Desktop: Apply proper layout
                if (sidebar) {
                    sidebar.classList.remove('-translate-x-full');
                }
                updateMainContentLayout();
            }
        });

        // Close dropdowns when clicking outside (improved version)
        document.addEventListener('click', function(event) {
            const dropdowns = document.querySelectorAll('.dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(event.target)) {
                    // Just remove hover state, CSS will handle the transition
                    dropdown.classList.remove('force-open');
                }
            });
        });

        // Chat notification system for support agents
        function loadChatNotifications() {
            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])): ?>
            fetch('/school_ms/chat/get_agent_notifications.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const notifications = data.notifications;
                        const totalCount = notifications.unassigned_count + notifications.my_unread_count + notifications.urgent_count;

                        // Update badge
                        const badge = document.getElementById('chatNotificationBadge');
                        const countSpan = document.getElementById('chatNotificationCount');

                        if (totalCount > 0) {
                            badge.textContent = totalCount;
                            badge.classList.remove('hidden');
                            countSpan.textContent = totalCount + ' new';
                        } else {
                            badge.classList.add('hidden');
                            countSpan.textContent = '0 new';
                        }

                        // Update content
                        const content = document.getElementById('chatNotificationContent');
                        let html = '';

                        if (notifications.unassigned_count > 0) {
                            html += `
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 border-l-4 border-red-500 cursor-pointer" onclick="window.location.href='/school_ms/chat/admin_dashboard.php'">
                                    <div class="flex items-start space-x-3">
                                        <div class="w-8 h-8 bg-red-100 dark:bg-red-900 rounded-full flex items-center justify-center">
                                            <i class="fas fa-exclamation text-red-600 dark:text-red-400 text-xs"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium">Unassigned Chats</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${notifications.unassigned_count} conversations need assignment</p>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }

                        if (notifications.my_unread_count > 0) {
                            html += `
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 border-l-4 border-blue-500 cursor-pointer" onclick="window.location.href='/school_ms/help.php'">
                                    <div class="flex items-start space-x-3">
                                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                            <i class="fas fa-message text-blue-600 dark:text-blue-400 text-xs"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium">Unread Messages</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${notifications.my_unread_count} conversations have new messages</p>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }

                        if (notifications.urgent_count > 0) {
                            html += `
                                <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 border-l-4 border-orange-500 cursor-pointer" onclick="window.location.href='/school_ms/chat/admin_dashboard.php?filter=urgent'">
                                    <div class="flex items-start space-x-3">
                                        <div class="w-8 h-8 bg-orange-100 dark:bg-orange-900 rounded-full flex items-center justify-center">
                                            <i class="fas fa-fire text-orange-600 dark:text-orange-400 text-xs"></i>
                                        </div>
                                        <div class="flex-1">
                                            <p class="text-sm font-medium">Urgent Chats</p>
                                            <p class="text-xs text-gray-500 dark:text-gray-400">${notifications.urgent_count} urgent conversations</p>
                                        </div>
                                    </div>
                                </div>
                            `;
                        }

                        if (html === '') {
                            html = '<div class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">No new chat notifications</div>';
                        }

                        content.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error loading chat notifications:', error);
                    document.getElementById('chatNotificationContent').innerHTML =
                        '<div class="px-4 py-3 text-center text-red-500">Error loading notifications</div>';
                });
            <?php endif; ?>
        }

        // Lightweight global toast for real-time notification alerts
        let __previousUnreadCount = null;
        function headerToast(message, title) {
            let container = document.getElementById('headerToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'headerToastContainer';
                container.className = 'fixed top-20 right-4 z-[100] space-y-2';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.className = 'bg-white dark:bg-gray-800 border-l-4 border-blue-500 text-gray-800 dark:text-gray-100 px-4 py-3 rounded-lg shadow-xl flex items-start space-x-3 transform transition-all duration-300 translate-x-full opacity-0 max-w-sm cursor-pointer';
            toast.innerHTML = `
                <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center flex-shrink-0">
                    <i class="fas fa-bell text-blue-600 dark:text-blue-400 text-sm"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold truncate">${title || 'New Notification'}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 line-clamp-2">${message || ''}</p>
                </div>`;
            toast.addEventListener('click', () => { window.location.href = '/school_ms/notifications.php'; });
            container.appendChild(toast);
            requestAnimationFrame(() => toast.classList.remove('translate-x-full', 'opacity-0'));
            setTimeout(() => {
                toast.classList.add('translate-x-full', 'opacity-0');
                setTimeout(() => toast.remove(), 300);
            }, 5000);
        }

        // Real-time notification system
        function loadNotifications() {
            fetch('/school_ms/communication/notifications/get_notifications.php?limit=5&unread_only=false')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const notifications = data.notifications;
                        const unreadCount = data.pagination.unread;

                        // Real-time alert: if unread count went up since last poll, toast the newest
                        if (__previousUnreadCount !== null && unreadCount > __previousUnreadCount && notifications.length > 0) {
                            const newest = notifications.find(n => !n.is_read) || notifications[0];
                            headerToast(newest.message, newest.title);
                        }
                        __previousUnreadCount = unreadCount;

                        // Update badge
                        const badge = document.getElementById('notificationBadge');
                        const countSpan = document.getElementById('notificationCount');

                        if (unreadCount > 0) {
                            badge.textContent = unreadCount > 99 ? '99+' : unreadCount;
                            badge.classList.remove('hidden');
                            countSpan.textContent = unreadCount + ' new';
                        } else {
                            badge.classList.add('hidden');
                            countSpan.textContent = '0 new';
                        }

                        // Update content
                        const content = document.getElementById('notificationContent');
                        let html = '';

                        if (notifications.length > 0) {
                            notifications.forEach(notification => {
                                const isUnread = !notification.is_read;
                                const priorityIndicator = notification.priority === 'urgent' || notification.priority === 'high'
                                    ? `<span class="w-2 h-2 bg-${notification.priority === 'urgent' ? 'red' : 'orange'}-500 rounded-full"></span>`
                                    : '';

                                html += `
                                    <div class="px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 border-l-4 border-${notification.color}-500 cursor-pointer ${isUnread ? '' : 'opacity-75'}"
                                         onclick="handleNotificationClick(${notification.id}, '${notification.action_url || ''}')"
                                         data-notification-id="${notification.id}">
                                        <div class="flex items-start space-x-3">
                                            <div class="w-8 h-8 bg-${notification.color}-100 dark:bg-${notification.color}-900 rounded-full flex items-center justify-center">
                                                <i class="${notification.icon} text-${notification.color}-600 dark:text-${notification.color}-400 text-xs"></i>
                                            </div>
                                            <div class="flex-1">
                                                <div class="flex items-center space-x-2 mb-1">
                                                    <p class="text-sm font-medium">${notification.title}</p>
                                                    ${priorityIndicator}
                                                    ${isUnread ? '<span class="w-2 h-2 bg-blue-500 rounded-full"></span>' : ''}
                                                </div>
                                                <p class="text-xs text-gray-500 dark:text-gray-400">${notification.message}</p>
                                                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">${notification.time_ago}</p>
                                            </div>
                                            ${isUnread ? `
                                                <button onclick="event.stopPropagation(); markNotificationAsRead(${notification.id})"
                                                        class="text-blue-400 hover:text-blue-600 dark:hover:text-blue-300" title="Mark as read">
                                                    <i class="fas fa-check text-xs"></i>
                                                </button>
                                            ` : ''}
                                        </div>
                                    </div>
                                `;
                            });
                        } else {
                            html = '<div class="px-4 py-3 text-center text-gray-500 dark:text-gray-400">No notifications</div>';
                        }

                        content.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error loading notifications:', error);
                    document.getElementById('notificationContent').innerHTML =
                        '<div class="px-4 py-3 text-center text-red-500">Error loading notifications</div>';
                });
        }

        // Notification interaction functions
        function handleNotificationClick(notificationId, actionUrl) {
            // Mark as read
            markNotificationAsRead(notificationId);

            // Navigate to action URL if provided
            if (actionUrl && actionUrl !== '') {
                window.location.href = actionUrl;
            }
        }

        function markNotificationAsRead(notificationId) {
            fetch('/school_ms/communication/notifications/mark_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    notification_id: notificationId
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh notifications
                    loadNotifications();
                } else {
                    console.error('Failed to mark notification as read:', data.message);
                }
            })
            .catch(error => {
                console.error('Error marking notification as read:', error);
            });
        }

        function markAllNotificationsAsRead() {
            fetch('/school_ms/communication/notifications/mark_all_read.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({})
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Refresh notifications
                    loadNotifications();
                    headerToast(`Marked ${data.affected_rows ?? ''} notification(s) as read`, 'Notifications');
                } else {
                    console.error('Failed to mark all notifications as read:', data.message);
                }
            })
            .catch(error => {
                console.error('Error marking all notifications as read:', error);
            });
        }

        // Search functionality
        function performSearch(query) {
            if (query.length < 2) return [];

            // This would typically search via API
            // For now, we'll simulate with static data
            const searchResults = [
                { type: 'student', name: 'John Doe', class: 'Grade 5A', url: '/school_ms/students/profile.php?id=1' },
                { type: 'teacher', name: 'Jane Smith', subject: 'Mathematics', url: '/school_ms/teachers/profile.php?id=1' },
                { type: 'class', name: 'Grade 5A', students: 25, url: '/school_ms/academic/classes/view.php?id=1' }
            ];

            return searchResults.filter(item =>
                item.name.toLowerCase().includes(query.toLowerCase())
            );
        }

        // Auto-save theme preference
        function toggleTheme() {
            const body = document.body;
            const isDark = body.classList.contains('dark');

            if (isDark) {
                body.classList.remove('dark');
                localStorage.setItem('darkMode', 'false');
            } else {
                body.classList.add('dark');
                localStorage.setItem('darkMode', 'true');
            }
        }

        // Initialize tooltips and notification handlers
        document.addEventListener('DOMContentLoaded', function() {
            const tooltipElements = document.querySelectorAll('[title]');
            tooltipElements.forEach(element => {
                element.addEventListener('mouseenter', function() {
                    // Add tooltip functionality here if needed
                });
            });

            // Add event listeners for notification actions
            document.getElementById('markAllNotificationsRead')?.addEventListener('click', markAllNotificationsAsRead);
            document.getElementById('refreshNotifications')?.addEventListener('click', loadNotifications);
            // Refresh the dropdown contents whenever the bell is opened
            document.getElementById('notificationBtn')?.addEventListener('click', loadNotifications);
            document.getElementById('notificationBtn')?.addEventListener('mouseenter', loadNotifications);

            // Initialize dropdown functionality
            const dropdowns = document.querySelectorAll('.dropdown');

            dropdowns.forEach(dropdown => {
                const button = dropdown.querySelector('button');
                const content = dropdown.querySelector('.dropdown-content');

                if (button && content) {
                    button.addEventListener('click', function(e) {
                        e.stopPropagation();

                        // Close other dropdowns
                        dropdowns.forEach(otherDropdown => {
                            if (otherDropdown !== dropdown) {
                                const otherContent = otherDropdown.querySelector('.dropdown-content');
                                if (otherContent) {
                                    otherContent.classList.add('hidden');
                                }
                            }
                        });

                        // Toggle current dropdown
                        content.classList.toggle('hidden');
                    });
                }
            });

            // Close dropdowns when clicking outside
            document.addEventListener('click', function() {
                dropdowns.forEach(dropdown => {
                    const content = dropdown.querySelector('.dropdown-content');
                    if (content) {
                        content.classList.add('hidden');
                    }
                });
            });

            // Prevent dropdown from closing when clicking inside
            document.querySelectorAll('.dropdown-content').forEach(content => {
                content.addEventListener('click', function(e) {
                    e.stopPropagation();
                });
            });
        });
    </script>

    <!-- Emergency Sidebar Toggle Fix -->
    <script src="/school_ms/fix_sidebar_toggle.js"></script>

    <main>