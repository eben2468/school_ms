<?php
// Include settings helper
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/includes/settings_helper.php';
// Include module access control (per-school subscription gating)
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/includes/module_access.php';
// Include role-based access control (canonical role -> module matrix)
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/includes/access_control.php';
// Application version (single source of truth)
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/config/version.php';

$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$current_page = $_SERVER['PHP_SELF'];
$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_email = $_SESSION['email'] ?? '';
$school_name = getSchoolSetting('school_name', 'School Management System');

// Pending password-reset requests (stored in the central directory DB) — used for a
// sidebar badge so admins are alerted to requests awaiting action.
$pending_reset_requests = 0;
if (in_array($role, ['super_admin', 'school_admin']) && defined('DB_HOST')) {
    try {
        $rq_pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
        $rq_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if ($role === 'super_admin') {
            $pending_reset_requests = (int)$rq_pdo->query("SELECT COUNT(*) FROM password_reset_requests WHERE status = 'pending'")->fetchColumn();
        } else {
            $rq = $rq_pdo->prepare("SELECT COUNT(*) FROM password_reset_requests r
                                    JOIN users u ON u.email = r.email
                                    WHERE r.status = 'pending' AND u.school_id = :sid");
            $rq->execute([':sid' => $_SESSION['school_id'] ?? 0]);
            $pending_reset_requests = (int)$rq->fetchColumn();
        }
    } catch (Exception $e) {
        $pending_reset_requests = 0;
    }
}

// Debug: Let's see what role the user has
// echo "<!-- DEBUG: User role is: '" . $role . "' -->";
// echo "<!-- DEBUG: Current page is: '" . $current_page . "' -->";
// echo "<!-- DEBUG: Session data: " . print_r($_SESSION, true) . " -->";
?>

<!-- Enhanced Modern Sidebar -->
<div class="sidebar fixed left-0 text-white shadow-2xl transition-all duration-300 ease-in-out transform lg:translate-x-0 -translate-x-full z-30 border-r border-white/10 flex flex-col backdrop-blur-xl" id="sidebar" x-data="{ searchQuery: '', activeSection: '' }" :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'" style="top: 56px; height: calc(100vh - 56px); background: var(--sidebar-gradient); min-height: calc(100vh - 56px);" x-init="$store.sidebar.init()">

    <!-- Enhanced Sidebar Header -->
    <div class="relative py-6 border-b border-white/10 backdrop-blur-sm" :class="$store.sidebar.collapsed ? 'px-3' : 'px-6'">
        <!-- Background Pattern -->
        <div class="absolute inset-0 bg-gradient-to-br from-white/5 to-transparent"></div>

        <div class="relative flex items-center justify-between">
            <div class="flex items-center space-x-4" :class="$store.sidebar.collapsed ? 'justify-center space-x-0' : 'space-x-4'">
                <!-- Enhanced Profile Picture -->
                <div class="relative group">
                    <div class="rounded-2xl overflow-hidden shadow-xl backdrop-blur-sm border-2 border-white/20 group-hover:border-white/40 transition-all duration-300 ring-2 ring-white/10" :class="$store.sidebar.collapsed ? 'w-10 h-10' : 'w-14 h-14'">
                        <?php if (isset($_SESSION['profile_picture']) && !empty($_SESSION['profile_picture'])): ?>
                            <img src="/school_ms/serve_image.php?path=profile_pictures/<?php echo htmlspecialchars($_SESSION['profile_picture']); ?>"
                                 alt="Profile Picture"
                                 class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-300">
                        <?php else: ?>
                            <div class="w-full h-full bg-gradient-to-br from-white/20 to-white/10 flex items-center justify-center group-hover:from-white/30 group-hover:to-white/20 transition-all duration-300">
                                <span class="text-xl font-bold text-white"><?php echo strtoupper(substr($user_name, 0, 1)); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                    <!-- Status Indicator -->
                    <div class="absolute -bottom-1 -right-1 w-5 h-5 bg-gradient-to-r from-green-400 to-emerald-500 rounded-full border-3 border-white/30 shadow-lg animate-pulse"></div>
                    <!-- Hover Tooltip -->
                    <div class="absolute -top-12 left-1/2 transform -translate-x-1/2 bg-black/80 text-white text-xs px-2 py-1 rounded-lg opacity-0 group-hover:opacity-100 transition-opacity duration-200 whitespace-nowrap" x-show="$store.sidebar.collapsed">
                        <?php echo htmlspecialchars($user_name); ?>
                    </div>
                </div>

                <!-- User Info -->
                <div class="flex-1 min-w-0" x-show="!$store.sidebar.collapsed" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-x-4" x-transition:enter-end="opacity-100 translate-x-0">
                    <div class="space-y-1">
                        <p class="font-bold text-white truncate text-lg"><?php echo htmlspecialchars($user_name); ?></p>
                        <div class="flex items-center space-x-2">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-white/20 text-white backdrop-blur-sm">
                                <i class="fas fa-user-circle mr-1"></i>
                                <?php echo htmlspecialchars(formatRoleName($role)); ?>
                            </span>
                        </div>
                        <p class="text-xs text-white/70 truncate"><?php echo htmlspecialchars($user_email); ?></p>
                    </div>
                </div>
            </div>

            <!-- Close Button -->
            <button @click="document.getElementById('sidebar')?.classList.add('-translate-x-full'); document.getElementById('sidebar')?.classList.remove('sidebar-open'); document.getElementById('sidebarOverlay')?.classList.remove('active'); document.body.style.overflow = '';" class="lg:hidden p-2 rounded-xl hover:bg-white/10 transition-all duration-200 group" x-show="!$store.sidebar.collapsed" x-transition>
                <i class="fas fa-times text-lg text-white group-hover:rotate-90 transition-transform duration-200"></i>
            </button>
        </div>
    </div>

    <!-- Enhanced Search Bar -->
    <div class="py-4 border-b border-white/10" :class="$store.sidebar.collapsed ? 'px-3' : 'px-6'" x-show="!$store.sidebar.collapsed" x-transition>
        <div class="relative group">
            <div class="absolute inset-0 bg-gradient-to-r from-white/10 to-white/5 rounded-xl blur-sm group-hover:blur-none transition-all duration-300"></div>
            <div class="relative">
                <i class="fas fa-search absolute left-4 top-1/2 transform -translate-y-1/2 text-white/60 text-sm group-hover:text-white/80 transition-colors duration-200"></i>
                <input id="sidebar-search-input" x-model="searchQuery" type="text" placeholder="Search navigation..."
                       style="background-color: rgba(255,255,255,0.12); color: #ffffff;"
                       class="w-full pl-12 pr-4 py-3 border border-white/20 rounded-xl focus:outline-none focus:ring-2 focus:ring-white/30 focus:border-white/40 transition-all duration-200 backdrop-blur-sm text-sm font-medium">
                <div class="absolute right-3 top-1/2 transform -translate-y-1/2">
                    <kbd class="px-2 py-1 text-xs font-semibold text-white/60 bg-white/10 border border-white/20 rounded">⌘K</kbd>
                </div>
            </div>
        </div>
    </div>

    <!-- Enhanced Navigation Menu -->
    <nav id="sidebar-nav" class="flex-1 overflow-y-auto py-6 space-y-2 scrollbar-thin scrollbar-thumb-white/20 scrollbar-track-transparent hover:scrollbar-thumb-white/40 transition-all duration-300 relative" style="scroll-behavior: smooth; min-height: 0; overflow-x: hidden;" :class="$store.sidebar.collapsed ? 'px-2' : 'px-4'">



        <!-- Progress Indicator -->
        <div id="scroll-progress" class="absolute top-0 right-0 w-1 bg-white/5 rounded-full transition-all duration-300">
            <div id="scroll-progress-bar" class="w-full bg-gradient-to-b from-blue-400 via-purple-500 to-pink-500 rounded-full transition-all duration-150 shadow-lg" style="height: 0%;"></div>
        </div>
        <!-- Dashboard Section -->
        <?php
        // Parents get the dedicated parent dashboard as their primary Dashboard link
        $dashboard_link = ($role === 'parent') ? '/school_ms/parent/dashboard.php' : '/school_ms/dashboard.php';
        ?>
        <div id="dashboard-section" class="mb-6">
            <a href="<?php echo $dashboard_link; ?>" class="flex items-center rounded-xl <?php echo strpos($current_page, 'dashboard.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, 'dashboard.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                    <i class="fas fa-tachometer-alt text-lg text-white"></i>
                </div>
                <div class="flex-1" x-show="!$store.sidebar.collapsed" x-transition>
                    <span class="font-medium text-white">Dashboard</span>
                    <p class="text-xs text-blue-100 opacity-75">Overview & Analytics</p>
                </div>
            </a>
        </div>

        <!-- Navigation Sections Container -->
        <div class="space-y-6">
        <!-- Student Management -->
        <?php if (canAccessModule('students')): ?>
        <div id="student-section" class="px-2 mb-4" x-data="{ studentOpen: <?php echo strpos($current_page, '/students/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Student Management</h3>

            <div class="space-y-2">
                <button @click="studentOpen = !studentOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/students/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/students/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-user-graduate text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Students</span>
                        <p class="text-xs text-blue-100 opacity-75">Manage student records</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': studentOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="studentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/students/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/students/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>All Students</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/students/enroll.php" class="flex items-center rounded-lg <?php echo strpos($current_page, 'enroll.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-plus w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Student Enrollment</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Management - Admin only -->
        <?php if (canAccessModule('users')): ?>
        <div class="px-2 mb-4" x-data="{ userOpen: <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>User Management</h3>

            <div class="space-y-2">
                <button @click="userOpen = !userOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-users text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Users</span>
                        <p class="text-xs text-blue-100 opacity-75">Manage all users</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': userOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="userOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/users/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/users/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>All Users</span>
                    </a>
                    <?php if (($role ?? '') === 'super_admin'): ?>
                    <a href="/school_ms/users/create.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/users/create.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-plus w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Create User</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/admin/parent_student_links.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-link w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Parent-Student Links</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Student Academic Portal -->
        <?php if ($role === 'student'): ?>
        <div id="student-academic-section" class="px-2 mb-4" x-data="{ studentAcademicOpen: <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>My Academics</h3>

            <div class="space-y-2">
                <button @click="studentAcademicOpen = !studentAcademicOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-graduation-cap text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">My Academics</span>
                        <p class="text-xs text-blue-100 opacity-75">Classes, grades & assignments</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': studentAcademicOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="studentAcademicOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/students/profile.php?id=<?php echo $_SESSION['user_id']; ?>" class="flex items-center rounded-lg <?php echo strpos($current_page, '/students/profile.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Profile</span>
                    </a>
                    <a href="/school_ms/academic/assignments/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tasks w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Assignments</span>
                    </a>
                    <a href="/school_ms/academic/grades/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/grades/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Grades</span>
                    </a>
                    <a href="/school_ms/attendance/student.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/attendance/student.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-calendar-check w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Attendance</span>
                    </a>
                    <a href="/school_ms/academic/timetable/student.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/timetable/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-calendar-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Timetable</span>
                    </a>
                    <a href="/school_ms/academic/classes/my_classes.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/classes/my_classes.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Classes</span>
                    </a>
                    <a href="/school_ms/finance/student_finances.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/student_finances.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-wallet w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Finances</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Academic Management -->
        <?php if (canAccessModule('academic')): ?>
        <div id="academic-section" class="px-2 mb-4" x-data="{ academicOpen: <?php echo strpos($current_page, '/academic/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Academic Management</h3>

            <div class="space-y-2">
                <button @click="academicOpen = !academicOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/academic/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-graduation-cap text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Academics</span>
                        <p class="text-xs text-blue-100 opacity-75">Classes, subjects & more</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': academicOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="academicOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/academic/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/academic/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Academic Overview</span>
                    </a>
                    <a href="/school_ms/academic/classes/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/classes/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Classes</span>
                    </a>
                    <a href="/school_ms/academic/subjects/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/subjects/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-book w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Subjects</span>
                    </a>
                    <a href="/school_ms/academic/assignments/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tasks w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Assignments</span>
                    </a>
                    <a href="/school_ms/academic/grades/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/grades/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Grades</span>
                    </a>
                    <a href="/school_ms/academic/timetable/<?php echo $role === 'teacher' ? 'teacher.php' : 'index.php'; ?>" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/timetable/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-calendar-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Timetable</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/academic/class-management.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/class-management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-friends w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Class Management</span>
                    </a>
                    <a href="/school_ms/academic/settings/" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/settings/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-cogs w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Academic Settings</span>
                    </a>
                    <a href="/school_ms/academic/promotions/" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/promotions/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-graduation-cap w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Student Promotions</span>
                    </a>
                    <a href="/school_ms/academic/records/" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/records/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Academic Records</span>
                    </a>

                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Examinations -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
        <div class="px-2 mb-4" x-data="{ examOpen: <?php echo strpos($current_page, '/academic/exams/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Examination Management</h3>
            <div class="space-y-2">
                <button @click="examOpen = !examOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/academic/exams/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/exams/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-file-alt text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Examinations</span>
                        <p class="text-xs text-blue-100 opacity-75">Exams & results</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': examOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="examOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/academic/exams/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/exams/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>All Exams</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/academic/exams/create.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/exams/create.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-plus w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Schedule Exam</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/academic/exams/results.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/exams/results.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Results</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attendance -->
        <?php if (canAccessModule('attendance')): ?>
        <div class="px-2 mb-4">
            <a href="/school_ms/attendance/index.php" class="flex items-center rounded-xl <?php echo strpos($current_page, '/attendance/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/attendance/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                    <i class="fas fa-calendar-check text-lg text-white"></i>
                </div>
                <div class="flex-1" x-show="!$store.sidebar.collapsed" x-transition>
                    <span class="font-medium text-white">Attendance</span>
                    <p class="text-xs text-blue-100 opacity-75">Track daily attendance</p>
                </div>
                <?php if (strpos($current_page, '/attendance/') !== false): ?>
                <i class="fas fa-chevron-right text-sm text-white"></i>
                <?php endif; ?>
            </a>
        </div>
        <?php endif; ?>

        <!-- Reports -->
        <?php if (canAccessModule('reports')): ?>
        <div id="reports-section" class="px-2 mb-4" x-data="{ reportsOpen: <?php echo (strpos($current_page, '/reports/') !== false || strpos($current_page, '/academic/reports/') !== false || strpos($current_page, '/attendance/reports.php') !== false) ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Reports &amp; Analytics</h3>
            <div class="space-y-2">
                <button @click="reportsOpen = !reportsOpen" class="w-full flex items-center rounded-xl <?php echo (strpos($current_page, '/reports/') !== false || strpos($current_page, '/academic/reports/') !== false || strpos($current_page, '/attendance/reports.php') !== false) ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo (strpos($current_page, '/reports/') !== false || strpos($current_page, '/academic/reports/') !== false || strpos($current_page, '/attendance/reports.php') !== false) ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-chart-line text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Reports</span>
                        <p class="text-xs text-blue-100 opacity-75">Analytics & insights</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': reportsOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="reportsOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/reports/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/reports/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Reports Dashboard</span>
                    </a>
                    <a href="/school_ms/reports/academic.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/reports/academic.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-graduation-cap w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Academic Progress</span>
                    </a>
                    <a href="/school_ms/attendance/reports.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/attendance/reports.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-calendar-check w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Attendance Reports</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/reports/class.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/reports/class.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Class Reports</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/academic/reports/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/reports/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-file-invoice w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Term Report</span>
                    </a>
                    <a href="/school_ms/academic/reports/compilation.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/academic/reports/compilation.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-clipboard-check w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Report Compilation</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Library Management -->
        <?php if (canAccessModule('library') && isModuleEnabled('library')): ?>
        <div id="library-section" class="px-2 mb-4" x-data="{ libraryOpen: <?php echo strpos($current_page, '/library/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Library Management</h3>

            <div class="space-y-2">
                <button @click="libraryOpen = !libraryOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/library/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/library/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-book text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Library</span>
                        <p class="text-xs text-blue-100 opacity-75">Books & resources</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': libraryOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="libraryOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/library/books/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/library/books/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Library Management</span>
                    </a>
                    <a href="/school_ms/library/borrow.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/library/borrow.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-hand-holding w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Borrow Books</span>
                    </a>
                    <a href="/school_ms/library/loans.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/library/loans.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-exchange-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Book Loans</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'librarian'])): ?>
                    <a href="/school_ms/library/manage.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/library/manage.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-cogs w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Manage Library</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Transport Management -->
        <?php if (canAccessModule('transport') && isModuleEnabled('transport')): ?>
        <div id="transport-section" class="px-2 mb-4" x-data="{ transportOpen: <?php echo strpos($current_page, '/transport/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Transport Management</h3>

            <div class="space-y-2">
                <button @click="transportOpen = !transportOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/transport/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/transport/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-bus text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Transport</span>
                        <p class="text-xs text-blue-100 opacity-75">Routes & vehicles</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': transportOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="transportOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/transport/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/transport/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Transport Dashboard</span>
                    </a>
                    <a href="/school_ms/transport/routes/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/routes/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-route w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Routes</span>
                    </a>
                    <a href="/school_ms/transport/vehicles/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/vehicles/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-bus w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Vehicles</span>
                    </a>
                    <a href="/school_ms/transport/assignments/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-graduate w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Student Assignments</span>
                    </a>
                    <a href="/school_ms/transport/drivers/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/drivers/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-id-card w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Drivers</span>
                    </a>
                    <a href="/school_ms/transport/maintenance/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/maintenance/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tools w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Maintenance Logs</span>
                    </a>
                    <a href="/school_ms/transport/reports/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/transport/reports/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Transport Reports</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hostel Management -->
        <?php if (canAccessModule('hostel') && isModuleEnabled('hostel')): ?>
        <div id="hostel-section" class="px-2 mb-4" x-data="{ hostelOpen: <?php echo strpos($current_page, '/hostel/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Hostel Management</h3>

            <div class="space-y-2">
                <button @click="hostelOpen = !hostelOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/hostel/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/hostel/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-bed text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Hostel</span>
                        <p class="text-xs text-blue-100 opacity-75">Rooms & allocations</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': hostelOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="hostelOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/hostel/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/hostel/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Hostel Dashboard</span>
                    </a>
                    <a href="/school_ms/hostel/blocks/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/hostel/blocks/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-building w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Blocks</span>
                    </a>
                    <a href="/school_ms/hostel/rooms/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/hostel/rooms/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-bed w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Rooms</span>
                    </a>
                    <a href="/school_ms/hostel/allocations/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/hostel/allocations/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-users w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Allocations</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- My Hostel (student portal) -->
        <?php if (canAccessModule('hostel_student') && isModuleEnabled('hostel')): ?>
        <div id="my-hostel-section" class="px-2 mb-4">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Hostel</h3>
            <a href="/school_ms/hostel/my_hostel.php" class="w-full flex items-center rounded-xl <?php echo $current_page === '/school_ms/hostel/my_hostel.php' ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                <div class="w-10 h-10 rounded-lg <?php echo $current_page === '/school_ms/hostel/my_hostel.php' ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                    <i class="fas fa-bed text-lg text-white"></i>
                </div>
                <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                    <span class="font-medium text-white">My Hostel</span>
                    <p class="text-xs text-blue-100 opacity-75">Room, roommates & repairs</p>
                </div>
            </a>
        </div>
        <?php endif; ?>

        <!-- Canteen Management -->
        <?php if (canAccessModule('canteen') && isModuleEnabled('canteen')): ?>
        <div id="canteen-section" class="px-2 mb-4" x-data="{ canteenOpen: <?php echo strpos($current_page, '/canteen/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Canteen Management</h3>

            <div class="space-y-2">
                <button @click="canteenOpen = !canteenOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/canteen/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/canteen/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-utensils text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Canteen</span>
                        <p class="text-xs text-blue-100 opacity-75">Meals & orders</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': canteenOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="canteenOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/canteen/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/canteen/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Canteen Dashboard</span>
                    </a>
                    <a href="/school_ms/canteen/menu/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/menu/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Menu</span>
                    </a>
                    <a href="/school_ms/canteen/orders/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/orders/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-shopping-cart w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Orders</span>
                    </a>
                    <a href="/school_ms/canteen/inventory/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/inventory/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-boxes w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Inventory</span>
                    </a>
                    <a href="/school_ms/canteen/registrations/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/registrations/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-check w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Registrations</span>
                    </a>
                    <a href="/school_ms/canteen/reports/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/reports/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Reports</span>
                    </a>
                    <a href="/school_ms/canteen/settings/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/canteen/settings/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-cog w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Settings</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Finance Management -->
        <?php if (canAccessModule('finance') && isModuleEnabled('finance')): ?>
        <div class="px-2 mb-4" x-data="{ financeOpen: <?php echo strpos($current_page, '/finance/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Finance Management</h3>

            <div class="space-y-2">
                <button @click="financeOpen = !financeOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/finance/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/finance/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-money-bill-wave text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Finance</span>
                        <p class="text-xs text-blue-100 opacity-75">Fees & payments</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': financeOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="financeOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/finance/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/finance/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Finance Overview</span>
                    </a>
                    <a href="/school_ms/finance/fee_categories.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/fee_categories.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tags w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Fee Categories</span>
                    </a>
                    <a href="/school_ms/finance/fee_structures.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/fee_structures.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-list-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Fee Structures</span>
                    </a>
                    <a href="/school_ms/finance/invoices.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/invoices.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-file-invoice-dollar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Invoices</span>
                    </a>
                    <a href="/school_ms/finance/payments.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/payments.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-credit-card w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Payments</span>
                    </a>
                    <a href="/school_ms/finance/receipts.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/receipts.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-receipt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Receipts</span>
                    </a>
                    <a href="/school_ms/finance/student_balances.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/student_balances.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-wallet w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Account Statement</span>
                    </a>
                    <a href="/school_ms/finance/discounts.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/discounts.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-percent w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Discounts & Schol.</span>
                    </a>
                    <a href="/school_ms/finance/penalties.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/penalties.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-exclamation-triangle w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Penalties</span>
                    </a>
                    <a href="/school_ms/finance/income.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/income.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-hand-holding-usd w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Other Income</span>
                    </a>
                    <a href="/school_ms/finance/expenses.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/expenses.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-file-invoice w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Expenses</span>
                    </a>
                    <a href="/school_ms/finance/transactions.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/transactions.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-book w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Unified Ledger</span>
                    </a>
                    <a href="/school_ms/finance/reports.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/reports.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Reports & Analytics</span>
                    </a>
                    <a href="/school_ms/finance/audit_logs.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/finance/audit_logs.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-shield-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Audit Logs</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Staff Management -->
        <?php if (canAccessModule('staff')): ?>
        <div id="staff-section" class="px-2 mb-4" x-data="{ staffOpen: <?php echo strpos($current_page, '/staff/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Staff Management</h3>

            <div class="space-y-2">
                <button @click="staffOpen = !staffOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/staff/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/staff/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-id-badge text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Staff</span>
                        <p class="text-xs text-blue-100 opacity-75">Employees & HR</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': staffOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="staffOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'hr'])): ?>
                    <a href="/school_ms/staff/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/staff/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-address-book w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Directory</span>
                    </a>
                    <a href="/school_ms/staff/departments.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/departments.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-sitemap w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Departments</span>
                    </a>
                    <a href="/school_ms/staff/attendance.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/attendance.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-calendar-check w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Attendance</span>
                    </a>
                    <a href="/school_ms/staff/leaves.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/leaves.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-bed w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Leave Management</span>
                    </a>
                    <a href="/school_ms/staff/schedules.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/schedules.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-clock w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Schedules</span>
                    </a>
                    <a href="/school_ms/staff/performance.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/performance.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-star w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Performance</span>
                    </a>
                    <a href="/school_ms/staff/qualifications.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/qualifications.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-certificate w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Qualifications</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/staff/salaries.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/salaries.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-coins w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Salary Allocation</span>
                    </a>
                    <a href="/school_ms/staff/payroll.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/payroll.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-money-check-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Payroll</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'hr'])): ?>
                    <a href="/school_ms/staff/reports.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/staff/reports.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-chart-pie w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Reports</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Parent Portal -->
        <?php if ($role === 'parent'): ?>
        <div id="parent-section" class="px-2 mb-4" x-data="{ parentOpen: <?php echo strpos($current_page, '/parent/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Parent Portal</h3>

            <div class="space-y-2">
                <button @click="parentOpen = !parentOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/parent/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/parent/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-users text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Parent Portal</span>
                        <p class="text-xs text-blue-100 opacity-75">Monitor children</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': parentOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="parentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <!-- Dynamic Child Links -->
                    <?php if ($role === 'parent'): ?>
                    <?php
                    // Get parent's children for navigation
                    $nav_children_sql = "SELECT u.id, u.name, sp.student_id
                                        FROM parent_students ps
                                        JOIN users u ON ps.student_id = u.id
                                        JOIN student_profiles sp ON u.id = sp.user_id
                                        WHERE ps.parent_id = :parent_id AND u.status = 'active'
                                        ORDER BY u.name";
                    $nav_stmt = $db->prepare($nav_children_sql);
                    $nav_stmt->bindParam(':parent_id', $_SESSION['user_id']);
                    $nav_stmt->execute();
                    $nav_children = $nav_stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (!empty($nav_children)): ?>
                    <?php foreach ($nav_children as $child): ?>
                    <div class="ml-4 border-l border-white/20 pl-4 space-y-1">
                        <div class="text-xs text-blue-200 font-medium mb-2">
                            <?php echo htmlspecialchars($child['name']); ?>
                        </div>
                        <a href="/school_ms/parent/child_academic.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/child_academic.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-chart-line w-3 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Academic Progress</span>
                        </a>
                        <a href="/school_ms/parent/child_attendance.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/child_attendance.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-calendar-check w-3 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Attendance</span>
                        </a>
                        <a href="/school_ms/parent/child_assignments.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/child_assignments.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-tasks w-3 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Assignments</span>
                        </a>
                        <a href="/school_ms/parent/fees.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/fees.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-wallet w-3 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Fees & Finances</span>
                        </a>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="ml-4 text-xs text-blue-200 opacity-75 py-2">
                        No children linked to account
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Health & Counseling -->
        <?php if (canAccessModule('health') && isModuleEnabled('health')): ?>
        <div id="health-section" class="px-2 mb-4" x-data="{ healthOpen: <?php echo strpos($current_page, '/health/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Health & Counseling</h3>

            <div class="space-y-2">
                <button @click="healthOpen = !healthOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/health/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/health/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-heartbeat text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Health & Counseling</span>
                        <p class="text-xs text-blue-100 opacity-75">Medical & counseling</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': healthOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="healthOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/health/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/health/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Health Dashboard</span>
                    </a>
                    <a href="/school_ms/health/records/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/health/records/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-file-medical w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Health Records</span>
                    </a>
                    <a href="/school_ms/health/counseling/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/health/counseling/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Counseling</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inventory Management -->
        <?php if (canAccessModule('inventory') && isModuleEnabled('inventory')): ?>
        <div id="inventory-section" class="px-2 mb-4" x-data="{ inventoryOpen: <?php echo strpos($current_page, '/inventory/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Inventory Management</h3>

            <div class="space-y-2">
                <button @click="inventoryOpen = !inventoryOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/inventory/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/inventory/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-boxes text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Inventory</span>
                        <p class="text-xs text-blue-100 opacity-75">Assets & supplies</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': inventoryOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="inventoryOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/inventory/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/inventory/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Inventory Dashboard</span>
                    </a>
                    <a href="/school_ms/inventory/items/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/inventory/items/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-box w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Items</span>
                    </a>
                    <a href="/school_ms/inventory/requests/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/inventory/requests/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-hand-paper w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Requests</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Online Learning Tools -->
        <?php if (canAccessModule('online_learning') && isModuleEnabled('online_learning')): ?>
        <div id="online-learning-section" class="px-2 mb-4" x-data="{ onlineLearningOpen: <?php echo strpos($current_page, '/online_learning/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Online Learning Tools</h3>

            <div class="space-y-2">
                <button @click="onlineLearningOpen = !onlineLearningOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/online_learning/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/online_learning/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-laptop text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Online Learning</span>
                        <p class="text-xs text-blue-100 opacity-75">Virtual classes & tools</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': onlineLearningOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="onlineLearningOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/online_learning/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/online_learning/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Learning Dashboard</span>
                    </a>
                    <a href="/school_ms/online_learning/virtual_classroom.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/online_learning/virtual_classroom.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-video w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Virtual Classroom</span>
                    </a>
                    <a href="/school_ms/online_learning/materials.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/online_learning/materials.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-folder-open w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Learning Materials</span>
                    </a>
                    <a href="/school_ms/online_learning/quizzes.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/online_learning/quizzes.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-question-circle w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Quizzes & Tests</span>
                    </a>
                    <a href="/school_ms/online_learning/submissions.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/online_learning/submissions.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-upload w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Submissions</span>
                    </a>
                    <a href="/school_ms/online_learning/discussions.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/online_learning/discussions.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Discussion Boards</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document & File Management -->
        <?php if (canAccessModule('documents') && isModuleEnabled('documents')): ?>
        <div id="document-management-section" class="px-2 mb-4" x-data="{ documentOpen: <?php echo strpos($current_page, '/documents/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Document & File Management</h3>

            <div class="space-y-2">
                <button @click="documentOpen = !documentOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/documents/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/documents/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-file-alt text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Documents</span>
                        <p class="text-xs text-blue-100 opacity-75">Files & certificates</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': documentOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="documentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/documents/index.php" class="flex items-center rounded-lg <?php echo $current_page === '/school_ms/documents/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Document Dashboard</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
                    <a href="/school_ms/documents/upload.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/documents/upload.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-cloud-upload-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Upload Documents</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/documents/certificates.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/documents/certificates.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-certificate w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Certificates & IDs</span>
                    </a>
                    <a href="/school_ms/documents/transcripts.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/documents/transcripts.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-scroll w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Transcripts</span>
                    </a>
                    <a href="/school_ms/documents/shared.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/documents/shared.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-share-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Shared Files</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Communication -->
        <?php if (canAccessModule('communication') && isModuleEnabled('communication')): ?>
        <div class="px-2 mb-4" x-data="{ communicationOpen: <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Communication</h3>

            <div class="space-y-2">
                <button @click="communicationOpen = !communicationOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-comments text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Communication</span>
                        <p class="text-xs text-blue-100 opacity-75">Messages & notifications</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': communicationOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="communicationOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/notifications.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/notifications.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-bell w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Notifications</span>
                    </a>
                    <a href="/school_ms/communication/live_chat.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/communication/live_chat.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-comment-dots w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Live Chat</span>
                        <span class="bg-green-500 text-white text-xs rounded-full px-2 py-0.5 ml-auto" x-show="!$store.sidebar.collapsed" x-transition>New</span>
                    </a>
                    <a href="/school_ms/communication/index.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/communication/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Messages</span>
                    </a>
                    <a href="/school_ms/communication/announcements.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/communication/announcements.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-bullhorn w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Announcements</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Help & Support -->
        <div class="px-2 mb-4" x-data="{ helpOpen: <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Help & Support</h3>

            <div class="space-y-2">
                <button @click="helpOpen = !helpOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-question-circle text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Help & Support</span>
                        <p class="text-xs text-blue-100 opacity-75">Documentation & help</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': helpOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="helpOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <a href="/school_ms/help.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/help.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-book-open w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Help Center</span>
                    </a>
                    <a href="/school_ms/support.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/support.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-headset w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Contact Support</span>
                    </a>
                    <a href="/school_ms/feedback.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/feedback.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-comment-alt w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Send Feedback</span>
                    </a>

                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <div class="border-t border-white/20 pt-2 mt-2">
                        <a href="/school_ms/admin/feedback_management.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/feedback_management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                            <i class="fas fa-comments-dollar w-4 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Manage Feedback</span>
                        </a>
                        <a href="/school_ms/admin/support_management.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                            <i class="fas fa-ticket-alt w-4 text-blue-200"></i>
                            <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Manage Support Tickets</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Settings -->
        <div id="settings-section" class="px-2 mb-4" x-data="{ settingsOpen: <?php echo strpos($current_page, '/settings') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Settings</h3>

            <div class="space-y-2">
                <button @click="settingsOpen = !settingsOpen" class="w-full flex items-center rounded-xl <?php echo strpos($current_page, '/settings') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-3' : 'space-x-3 px-4 py-3'">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/settings') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-cog text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left" x-show="!$store.sidebar.collapsed" x-transition>
                        <span class="font-medium text-white">Settings</span>
                        <p class="text-xs text-blue-100 opacity-75">Preferences & config</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': settingsOpen }" x-show="!$store.sidebar.collapsed" x-transition></i>
                </button>

                <div x-show="settingsOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="space-y-1" :class="$store.sidebar.collapsed ? 'ml-0 flex flex-col items-center' : 'ml-6'">
                    <?php $my_profile_link = ($role === 'parent') ? '/school_ms/parent/profile.php' : '/school_ms/profile.php'; ?>
                    <a href="<?php echo $my_profile_link; ?>" class="flex items-center rounded-lg <?php echo $current_page === $my_profile_link ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-user-cog w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>My Profile</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin'])): ?>
                    <a href="/school_ms/settings/school.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/settings/school.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-school w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>School Settings</span>
                    </a>
                    <?php endif; ?>
                    <?php if ($role === 'super_admin'): ?>
                    <a href="/school_ms/settings/super_admin.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/settings/super_admin.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-sliders-h w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Super Admin Control</span>
                    </a>
                    <a href="/school_ms/settings/module_access.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/settings/module_access.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-toggle-on w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Module Access Control</span>
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/admin/logs.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/logs.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-clipboard-list w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Activity Logs</span>
                    </a>
                    <?php endif; ?>
                    <?php if (in_array($role, ['super_admin', 'school_admin'])): ?>
                    <a href="/school_ms/admin/password_reset.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/password_reset.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-key w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Reset Password</span>
                    </a>
                    <a href="/school_ms/admin/reset_requests.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/reset_requests.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm relative" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <span class="relative">
                            <i class="fas fa-user-clock w-4 text-blue-200"></i>
                            <?php if ($pending_reset_requests > 0): ?>
                            <span class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold rounded-full min-w-[16px] h-4 px-1 flex items-center justify-center" x-show="$store.sidebar.collapsed"><?php echo $pending_reset_requests > 9 ? '9+' : $pending_reset_requests; ?></span>
                            <?php endif; ?>
                        </span>
                        <span class="text-white flex-1" x-show="!$store.sidebar.collapsed" x-transition>Reset Requests</span>
                        <?php if ($pending_reset_requests > 0): ?>
                        <span class="bg-red-500 text-white text-xs font-bold rounded-full min-w-[20px] h-5 px-1.5 flex items-center justify-center" x-show="!$store.sidebar.collapsed" x-transition><?php echo $pending_reset_requests > 99 ? '99+' : $pending_reset_requests; ?></span>
                        <?php endif; ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($role === 'super_admin'): ?>
                    <a href="/school_ms/admin/security.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/security.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-shield-halved w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Security Center</span>
                    </a>
                    <a href="/school_ms/admin/backup.php" class="flex items-center rounded-lg <?php echo strpos($current_page, '/admin/backup.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm" :class="$store.sidebar.collapsed ? 'justify-center px-0 py-2 w-10' : 'space-x-3 px-4 py-2'">
                        <i class="fas fa-database w-4 text-blue-200"></i>
                        <span class="text-white" x-show="!$store.sidebar.collapsed" x-transition>Backup &amp; Restore</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Bottom Padding for Better Scrolling -->
        <div class="h-8"></div>
    </nav>

    <!-- Sidebar Footer -->
    <div class="px-6 py-4 border-t border-white/20 flex-shrink-0 bg-gradient-to-r from-blue-600/20 to-purple-600/20 backdrop-blur-sm" x-show="!$store.sidebar.collapsed" x-transition>
        <div class="flex items-center justify-between text-xs text-blue-100">
            <span class="font-medium">v<?php echo APP_VERSION; ?></span>
            <div class="flex items-center space-x-2">
                <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                <span>Online</span>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Scrollbar Styles -->
<style>
/* Sidebar search input (Tailwind 2 CDN lacks /opacity utilities, so set these explicitly) */
#sidebar-search-input { color: #ffffff !important; }
#sidebar-search-input::placeholder { color: rgba(255, 255, 255, 0.6); }
#sidebar-search-input:focus { background-color: rgba(255, 255, 255, 0.2) !important; }

/* Custom Scrollbar for Sidebar */
#sidebar-nav {
    scrollbar-width: thin;
    scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
    /* Ensure proper height calculation - leave space for footer */
    height: calc(100vh - 200px);
    overflow-y: auto !important;
    overflow-x: hidden !important;
    /* Improve scrolling performance */
    -webkit-overflow-scrolling: touch;
    scroll-behavior: smooth;
}

#sidebar-nav::-webkit-scrollbar {
    width: 6px;
}

#sidebar-nav::-webkit-scrollbar-track {
    background: rgba(255, 255, 255, 0.1);
    border-radius: 3px;
}

#sidebar-nav::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.3) 0%, rgba(255, 255, 255, 0.5) 100%);
    border-radius: 3px;
    transition: all 0.3s ease;
}

#sidebar-nav::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.5) 0%, rgba(255, 255, 255, 0.7) 100%);
}

/* Scroll Progress Indicator */
#scroll-progress {
    height: 100%;
    opacity: 0;
    transition: opacity 0.3s ease;
}

/* Smooth scroll behavior */
#sidebar-nav {
    scroll-behavior: smooth;
}

/* Ensure sidebar uses flexbox properly */
#sidebar {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 80px);
    min-height: calc(100vh - 80px);
}

/* Fix for sidebar header and search */
#sidebar > div:not(nav) {
    flex-shrink: 0;
}

/* Ensure navigation takes remaining space */
#sidebar-nav {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
}

/* Ensure footer is always visible */
#sidebar > div:last-child {
    flex-shrink: 0;
    margin-top: auto;
}

/* Enhanced hover effects for scroll controls */
#scroll-controls button:hover {
    transform: scale(1.1);
    box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
}

/* Quick navigation dropdown styling */
#scroll-controls .absolute {
    backdrop-filter: blur(10px);
    border: 1px solid rgba(255, 255, 255, 0.2);
}

/* Animation for scroll controls appearance */
@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#scroll-controls {
    animation: fadeInUp 0.3s ease-out;
}

/* Visual feedback for scroll position */
#scroll-controls.near-top .fa-chevron-down {
    animation: pulse 1.5s infinite;
}

#scroll-controls.near-bottom .fa-chevron-up {
    animation: pulse 1.5s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Responsive adjustments */
@media (max-width: 1024px) {
    #scroll-controls {
        bottom: 80px;
        right: 16px;
    }

    #scroll-controls button {
        width: 44px;
        height: 44px;
    }

}

/* Focus styles for accessibility */
#scroll-controls button:focus {
    outline: 2px solid rgba(255, 255, 255, 0.5);
    outline-offset: 2px;
}

/* Loading state for navigation links */
.sidebar-link-loading {
    position: relative;
    overflow: hidden;
}

.sidebar-link-loading::after {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
    animation: shimmer 1.5s infinite;
}

@keyframes shimmer {
    0% { left: -100%; }
    100% { left: 100%; }
}

/* ============================================================
   PROFESSIONAL SIDEBAR REFINEMENTS
   Polishes how navigation content is presented: clearer section
   headers, refined active state, hierarchy guides and softer rhythm.
   Scoped to #sidebar so nothing else is affected.
   ============================================================ */

/* Tighter, more even spacing between top-level groups */
#sidebar nav .space-y-6 > * + * { margin-top: 0.5rem !important; }
#sidebar nav .px-2.mb-4 { margin-bottom: 0.5rem !important; }

/* --- Section category headers: lighter, refined, with trailing divider --- */
#sidebar nav h3 {
    font-size: 0.65rem !important;
    font-weight: 700;
    letter-spacing: 0.14em;
    color: rgba(191, 219, 254, 0.55) !important;
    margin-bottom: 0.55rem !important;
    display: flex;
    align-items: center;
    gap: 0.6rem;
}
#sidebar nav h3::after {
    content: "";
    flex: 1 1 auto;
    height: 1px;
    background: linear-gradient(to right, rgba(255, 255, 255, 0.16), transparent);
}

/* --- Top-level items & sub-links: unified smooth motion --- */
#sidebar nav a,
#sidebar nav button {
    transition: background-color .2s ease, transform .15s ease, box-shadow .2s ease;
}
#sidebar nav a:hover,
#sidebar nav button:hover { transform: translateX(2px); }

/* Icon tiles: consistent subtle depth */
#sidebar nav .w-10.h-10.rounded-lg {
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: 0 1px 2px rgba(0, 0, 0, 0.12), inset 0 1px 0 rgba(255, 255, 255, 0.06);
    transition: background .2s ease, box-shadow .2s ease, transform .2s ease;
}
#sidebar nav button:hover .w-10.h-10.rounded-lg,
#sidebar nav > div > a:hover .w-10.h-10.rounded-lg { transform: scale(1.06); }

/* Descriptions under titles: muted and quieter for a cleaner read */
#sidebar nav p.text-xs {
    color: rgba(219, 234, 254, 0.5) !important;
    font-weight: 400;
    opacity: 1 !important;
    margin-top: 1px;
}

/* --- Active state: gradient pill + glowing left accent bar --- */
#sidebar nav a[class*="bg-white/20"],
#sidebar nav button[class*="bg-white/20"] {
    position: relative;
    background: linear-gradient(90deg, rgba(255,255,255,0.20) 0%, rgba(255,255,255,0.06) 100%) !important;
    box-shadow: inset 0 0 0 1px rgba(255,255,255,0.12), 0 6px 16px -6px rgba(0,0,0,0.45) !important;
}
#sidebar nav a[class*="bg-white/20"]::before,
#sidebar nav button[class*="bg-white/20"]::before {
    content: "";
    position: absolute;
    left: 0; top: 50%;
    height: 62%; width: 3px;
    transform: translateY(-50%);
    border-radius: 0 4px 4px 0;
    background: linear-gradient(180deg, #60a5fa, #a78bfa);
    box-shadow: 0 0 8px rgba(96, 165, 250, 0.6);
}
/* Active icon tile becomes a vivid accent */
#sidebar nav a[class*="bg-white/20"] > .w-10.h-10.rounded-lg,
#sidebar nav button[class*="bg-white/20"] > .w-10.h-10.rounded-lg {
    background: linear-gradient(135deg, #3b82f6 0%, #8b5cf6 100%) !important;
    box-shadow: 0 4px 14px -2px rgba(99, 102, 241, 0.5);
    border-color: rgba(255, 255, 255, 0.25);
}

/* --- Nested sub-items: vertical hierarchy guide rail --- */
#sidebar nav .ml-6 {
    position: relative;
    border-left: 1px solid rgba(255, 255, 255, 0.12);
    padding-left: 0.5rem;
}
/* Replace the generic accent bar with a neat connector notch for sub-links */
#sidebar nav .ml-6 > a[class*="bg-white/20"]::before {
    content: "";
    left: -0.5rem; top: 50%;
    width: 0.5rem; height: 2px;
    transform: translateY(-50%);
    border-radius: 0;
    background: linear-gradient(to right, #60a5fa, transparent);
    box-shadow: none;
}
#sidebar nav .ml-6 a i { opacity: 0.85; transition: opacity .2s ease; }
#sidebar nav .ml-6 a:hover i,
#sidebar nav .ml-6 a[class*="bg-white/20"] i { opacity: 1; }

/* --- Footer status indicator glow --- */
#sidebar > div:last-child .bg-green-400 {
    box-shadow: 0 0 8px rgba(74, 222, 128, 0.85);
}
</style>

<!-- Sidebar JavaScript -->
<script>
// Sidebar toggle functionality (Mobile & Desktop) is handled by the unified handler in fix_sidebar_toggle.js to avoid conflicts and race conditions.

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');

    if (window.innerWidth < 1024 && // lg breakpoint
        !sidebar?.contains(event.target) &&
        !sidebarToggle?.contains(event.target)) {
        sidebar?.classList.add('-translate-x-full');
        sidebar?.classList.remove('sidebar-open');
    }
});

// Sidebar search functionality
function filterSidebarItems(query) {
    const menuItems = document.querySelectorAll('#sidebar nav a, #sidebar nav button');
    const sections = document.querySelectorAll('#sidebar nav > div');

    if (!query) {
        // Show all items and sections
        menuItems.forEach(item => {
            item.style.display = '';
            item.closest('div').style.display = '';
        });
        sections.forEach(section => section.style.display = '');
        return;
    }

    query = query.toLowerCase();
    let hasVisibleItems = false;

    sections.forEach(section => {
        let sectionHasVisible = false;
        const items = section.querySelectorAll('a, button');

        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            if (text.includes(query)) {
                item.style.display = '';
                sectionHasVisible = true;
                hasVisibleItems = true;
            } else {
                item.style.display = 'none';
            }
        });

        section.style.display = sectionHasVisible ? '' : 'none';
    });
}

// Initialize search functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.querySelector('#sidebar input[x-model="searchQuery"]');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            filterSidebarItems(e.target.value);
        });
    }
});

// Smooth scrolling for sidebar navigation
document.addEventListener('DOMContentLoaded', function() {
    const sidebarLinks = document.querySelectorAll('#sidebar a[href^="/"]');

    sidebarLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Add loading state
            const icon = this.querySelector('i');
            if (icon) {
                const originalClass = icon.className;
                icon.className = 'fas fa-spinner fa-spin';

                // Restore original icon after navigation
                setTimeout(() => {
                    icon.className = originalClass;
                }, 1000);
            }
        });
    });
});

// Keyboard navigation for sidebar
document.addEventListener('keydown', function(e) {
    if (e.target.closest('#sidebar')) {
        const focusableElements = document.querySelectorAll('#sidebar a, #sidebar button, #sidebar input');
        const currentIndex = Array.from(focusableElements).indexOf(document.activeElement);

        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                const nextIndex = (currentIndex + 1) % focusableElements.length;
                focusableElements[nextIndex]?.focus();
                break;
            case 'ArrowUp':
                e.preventDefault();
                const prevIndex = currentIndex > 0 ? currentIndex - 1 : focusableElements.length - 1;
                focusableElements[prevIndex]?.focus();
                break;
            case 'Enter':
                if (document.activeElement.tagName === 'BUTTON') {
                    e.preventDefault();
                    document.activeElement.click();
                }
                break;
        }
    }
});

// Add tooltips for collapsed sidebar items
function initSidebarTooltips() {
    const sidebarItems = document.querySelectorAll('#sidebar a, #sidebar button');

    sidebarItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
            const text = this.querySelector('span')?.textContent;
            if (text && window.innerWidth >= 1024) {
                // Create tooltip if needed
                const tooltip = document.createElement('div');
                tooltip.className = 'absolute left-full ml-2 px-2 py-1 bg-gray-900 text-white text-sm rounded shadow-lg z-50 whitespace-nowrap';
                tooltip.textContent = text;
                tooltip.id = 'sidebar-tooltip';

                // Position tooltip
                const rect = this.getBoundingClientRect();
                tooltip.style.top = rect.top + 'px';

                document.body.appendChild(tooltip);
            }
        });

        item.addEventListener('mouseleave', function() {
            const tooltip = document.getElementById('sidebar-tooltip');
            if (tooltip) {
                tooltip.remove();
            }
        });
    });
}

// Initialize tooltips
document.addEventListener('DOMContentLoaded', initSidebarTooltips);

// Handle responsive sidebar behavior
function handleSidebarResize() {
    const sidebar = document.getElementById('sidebar');
    if (window.innerWidth >= 1024) {
        sidebar?.classList.remove('-translate-x-full');
    } else {
        sidebar?.classList.add('-translate-x-full');
    }
}

window.addEventListener('resize', handleSidebarResize);
document.addEventListener('DOMContentLoaded', handleSidebarResize);

// Enhanced Scrolling Tools
function scrollSidebarToTop() {
    const sidebarNav = document.getElementById('sidebar-nav');
    if (sidebarNav) {
        sidebarNav.scrollTo({
            top: 0,
            behavior: 'smooth'
        });
    }
}

function scrollSidebarToBottom() {
    const sidebarNav = document.getElementById('sidebar-nav');
    if (sidebarNav) {
        sidebarNav.scrollTo({
            top: sidebarNav.scrollHeight,
            behavior: 'smooth'
        });
    }
}

function updateScrollProgress() {
    const sidebarNav = document.getElementById('sidebar-nav');
    const scrollProgress = document.getElementById('scroll-progress');
    const scrollProgressBar = document.getElementById('scroll-progress-bar');
    const scrollControls = document.getElementById('scroll-controls');

    if (!sidebarNav || !scrollProgressBar || !scrollControls) return;

    const scrollTop = sidebarNav.scrollTop;
    const scrollHeight = sidebarNav.scrollHeight - sidebarNav.clientHeight;
    const scrollPercentage = scrollHeight > 0 ? (scrollTop / scrollHeight) * 100 : 0;

    // Debug logging
    console.log('Scroll Debug:', {
        scrollTop,
        scrollHeight: sidebarNav.scrollHeight,
        clientHeight: sidebarNav.clientHeight,
        maxScroll: scrollHeight,
        percentage: scrollPercentage
    });

    // Update progress bar
    scrollProgressBar.style.height = scrollPercentage + '%';

    // Always show scroll controls if there's scrollable content
    if (scrollHeight > 10) { // Show if there's any scrollable content
        scrollControls.style.opacity = '1';
        scrollControls.style.pointerEvents = 'auto';

        // Add visual feedback for scroll position
        if (scrollTop <= 10) {
            // Near top - highlight scroll down
            scrollControls.classList.add('near-top');
            scrollControls.classList.remove('near-bottom');
        } else if (scrollTop >= scrollHeight - 10) {
            // Near bottom - highlight scroll up
            scrollControls.classList.add('near-bottom');
            scrollControls.classList.remove('near-top');
        } else {
            // Middle - show both
            scrollControls.classList.remove('near-top', 'near-bottom');
        }
    } else {
        // Always keep controls visible for better UX
        scrollControls.style.opacity = '0.7';
        scrollControls.style.pointerEvents = 'auto';
    }

    // Show/hide progress indicator
    if (scrollHeight > 0 && scrollProgress) {
        scrollProgress.style.opacity = '1';
    } else if (scrollProgress) {
        scrollProgress.style.opacity = '0';
    }
}

// Smooth scroll to specific menu section
function scrollToSection(sectionId) {
    const section = document.getElementById(sectionId);
    const sidebarNav = document.getElementById('sidebar-nav');

    if (section && sidebarNav) {
        const sectionTop = section.offsetTop - sidebarNav.offsetTop;
        sidebarNav.scrollTo({
            top: sectionTop - 20, // Add some padding
            behavior: 'smooth'
        });
    }
}

// Enhanced sidebar theme toggle
function toggleSidebarTheme() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        const currentFilter = sidebar.style.filter;
        if (currentFilter.includes('hue-rotate')) {
            sidebar.style.filter = '';
        } else {
            sidebar.style.filter = 'hue-rotate(60deg) saturate(1.2)';
        }
    }
}

// Enhanced search functionality
function initSidebarSearch() {
    const searchInput = document.querySelector('input[placeholder*="Search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function(e) {
            const query = e.target.value.toLowerCase().trim();
            const sections = document.querySelectorAll('#sidebar-nav > div');

            sections.forEach(section => {
                const menuItems = section.querySelectorAll('a');
                let sectionHasMatch = false;

                menuItems.forEach(item => {
                    const text = item.textContent.toLowerCase();
                    const isMatch = text.includes(query) || query === '';

                    if (isMatch) {
                        sectionHasMatch = true;
                        item.style.display = '';
                        item.style.opacity = '1';
                        // Highlight matching text
                        if (query) {
                            item.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
                        } else {
                            item.style.backgroundColor = '';
                        }
                    } else {
                        item.style.display = 'none';
                        item.style.opacity = '0.3';
                    }
                });

                // Show/hide entire section based on matches
                if (sectionHasMatch || query === '') {
                    section.style.display = '';
                } else {
                    section.style.display = 'none';
                }
            });
        });

        // Add keyboard shortcuts for search
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                this.value = '';
                this.dispatchEvent(new Event('input'));
                this.blur();
            }
        });
    }
}

// Initialize scroll tracking
document.addEventListener('DOMContentLoaded', function() {
    const sidebarNav = document.getElementById('sidebar-nav');
    if (sidebarNav) {
        // Add scroll event listener
        sidebarNav.addEventListener('scroll', updateScrollProgress);

        // Force initial layout calculation
        setTimeout(() => {
            // Trigger a reflow to ensure proper height calculation
            sidebarNav.style.height = 'calc(100vh - 200px)';
            updateScrollProgress();
        }, 100);

        // Also update on window resize
        window.addEventListener('resize', () => {
            setTimeout(updateScrollProgress, 100);
        });

        // Initialize enhanced search
        initSidebarSearch();

        // Add keyboard shortcuts for scrolling and search
        document.addEventListener('keydown', function(e) {
            // Quick search shortcut (Cmd/Ctrl + K)
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                const searchInput = document.querySelector('input[placeholder*="Search"]');
                if (searchInput) {
                    searchInput.focus();
                    searchInput.select();
                }
                return;
            }

            if (e.target.closest('#sidebar')) {
                switch(e.key) {
                    case 'Home':
                        if (e.ctrlKey) {
                            e.preventDefault();
                            scrollSidebarToTop();
                        }
                        break;
                    case 'End':
                        if (e.ctrlKey) {
                            e.preventDefault();
                            scrollSidebarToBottom();
                        }
                        break;
                    case 'PageUp':
                        e.preventDefault();
                        sidebarNav.scrollBy({
                            top: -sidebarNav.clientHeight * 0.8,
                            behavior: 'smooth'
                        });
                        break;
                    case 'PageDown':
                        e.preventDefault();
                        sidebarNav.scrollBy({
                            top: sidebarNav.clientHeight * 0.8,
                            behavior: 'smooth'
                        });
                        break;
                }
            }
        });
    }
});

// Add mouse wheel smooth scrolling enhancement
document.addEventListener('DOMContentLoaded', function() {
    const sidebarNav = document.getElementById('sidebar-nav');
    if (sidebarNav) {
        // Remove throttling for better responsiveness
        sidebarNav.addEventListener('wheel', function(e) {
            // Allow natural scrolling behavior
            // The browser handles this better than custom implementation
            updateScrollProgress();
        }, { passive: true });

        // Add touch scrolling support for mobile
        let touchStartY = 0;
        sidebarNav.addEventListener('touchstart', function(e) {
            touchStartY = e.touches[0].clientY;
        }, { passive: true });

        sidebarNav.addEventListener('touchmove', function(e) {
            const touchY = e.touches[0].clientY;
            const deltaY = touchStartY - touchY;

            sidebarNav.scrollBy({
                top: deltaY * 0.5,
                behavior: 'auto'
            });

            touchStartY = touchY;
            updateScrollProgress();
        }, { passive: true });
    }
});
</script>