<?php
// Include settings helper
require_once $_SERVER['DOCUMENT_ROOT'] . '/school_ms/includes/settings_helper.php';

$role = isset($_SESSION['role']) ? $_SESSION['role'] : '';
$current_page = $_SERVER['PHP_SELF'];
$user_name = $_SESSION['user_name'] ?? 'Guest';
$user_email = $_SESSION['email'] ?? '';
$school_name = getSchoolSetting('school_name', 'School Management System');

// Debug: Let's see what role the user has
// echo "<!-- DEBUG: User role is: '" . $role . "' -->";
// echo "<!-- DEBUG: Current page is: '" . $current_page . "' -->";
// echo "<!-- DEBUG: Session data: " . print_r($_SESSION, true) . " -->";
?>

<!-- Enhanced Modern Sidebar -->
<div class="sidebar fixed left-0 text-white shadow-2xl transition-all duration-300 ease-in-out transform lg:translate-x-0 -translate-x-full z-30 border-r border-white/10 flex flex-col backdrop-blur-xl" id="sidebar" x-data="{ searchQuery: '', activeSection: '' }" :class="$store.sidebar.collapsed ? 'w-16' : 'w-72'" style="top: 80px; height: calc(100vh - 80px); background: var(--sidebar-gradient); min-height: calc(100vh - 80px);" x-init="$store.sidebar.init()">

    <!-- Enhanced Sidebar Header -->
    <div class="relative py-6 border-b border-white/10 backdrop-blur-sm" :class="$store.sidebar.collapsed ? 'px-3' : 'px-6'">
        <!-- Background Pattern -->
        <div class="absolute inset-0 bg-gradient-to-br from-white/5 to-transparent"></div>

        <div class="relative flex items-center justify-between">
            <div class="flex items-center space-x-4" :class="$store.sidebar.collapsed ? 'justify-center space-x-0' : 'space-x-4'">
                <!-- Enhanced Profile Picture -->
                <div class="relative group">
                    <div class="w-14 h-14 rounded-2xl overflow-hidden shadow-xl backdrop-blur-sm border-2 border-white/20 group-hover:border-white/40 transition-all duration-300 ring-2 ring-white/10">
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
                                <?php echo ucfirst(str_replace('_', ' ', $role)); ?>
                            </span>
                        </div>
                        <p class="text-xs text-white/70 truncate"><?php echo htmlspecialchars($user_email); ?></p>
                    </div>
                </div>
            </div>

            <!-- Close Button -->
            <button @click="$store.sidebar.collapsed = !$store.sidebar.collapsed" class="lg:hidden p-2 rounded-xl hover:bg-white/10 transition-all duration-200 group" x-show="!$store.sidebar.collapsed" x-transition>
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
                <input x-model="searchQuery" type="text" placeholder="Search navigation..."
                       class="w-full pl-12 pr-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:outline-none focus:ring-2 focus:ring-white/30 focus:border-white/40 focus:bg-white/15 transition-all duration-200 backdrop-blur-sm text-sm font-medium">
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
        <div id="dashboard-section" class="mb-6">
            <a href="/school_ms/dashboard.php" class="flex items-center rounded-xl <?php echo strpos($current_page, 'dashboard.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group" :class="$store.sidebar.collapsed ? 'justify-center px-2 py-3' : 'space-x-3 px-4 py-3'">
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
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
        <div id="student-section" class="px-2 mb-4" x-data="{ studentOpen: <?php echo strpos($current_page, '/students/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>Student Management</h3>

            <div class="space-y-2">
                <button @click="studentOpen = !studentOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/students/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/students/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-user-graduate text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Students</span>
                        <p class="text-xs text-blue-100 opacity-75">Manage student records</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': studentOpen }"></i>
                </button>

                <div x-show="studentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/students/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/students/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white">All Students</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/students/enroll.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, 'enroll.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user-plus w-4 text-blue-200"></i>
                        <span class="text-white">Student Enrollment</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- User Management - Admin only -->
        <?php if (in_array($role, ['super_admin', 'school_admin'])): ?>
        <div class="px-2 mb-4" x-data="{ userOpen: <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3" x-show="!$store.sidebar.collapsed" x-transition>User Management</h3>

            <div class="space-y-2">
                <button @click="userOpen = !userOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/users/') !== false || strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-users text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Users</span>
                        <p class="text-xs text-blue-100 opacity-75">Manage all users</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': userOpen }"></i>
                </button>

                <div x-show="userOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/users/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/users/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white">All Users</span>
                    </a>
                    <a href="/school_ms/users/create.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, 'users/create.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user-plus w-4 text-blue-200"></i>
                        <span class="text-white">Add New User</span>
                    </a>
                    <a href="/school_ms/admin/parent_student_links.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/admin/parent_student_links.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-link w-4 text-blue-200"></i>
                        <span class="text-white">Parent-Student Links</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Student Academic Portal -->
        <?php if ($role === 'student'): ?>
        <div id="student-academic-section" class="px-2 mb-4" x-data="{ studentAcademicOpen: <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">My Academics</h3>

            <div class="space-y-2">
                <button @click="studentAcademicOpen = !studentAcademicOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/') !== false || strpos($current_page, '/students/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-graduation-cap text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">My Academics</span>
                        <p class="text-xs text-blue-100 opacity-75">Classes, grades & assignments</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': studentAcademicOpen }"></i>
                </button>

                <div x-show="studentAcademicOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/students/profile.php?id=<?php echo $_SESSION['user_id']; ?>" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/students/profile.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user w-4 text-blue-200"></i>
                        <span class="text-white">My Profile</span>
                    </a>
                    <a href="/school_ms/academic/assignments/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tasks w-4 text-blue-200"></i>
                        <span class="text-white">My Assignments</span>
                    </a>
                    <a href="/school_ms/academic/grades/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/grades/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white">My Grades</span>
                    </a>
                    <a href="/school_ms/attendance/student.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/attendance/student.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-calendar-check w-4 text-blue-200"></i>
                        <span class="text-white">My Attendance</span>
                    </a>
                    <a href="/school_ms/academic/timetable/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/timetable/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-calendar-alt w-4 text-blue-200"></i>
                        <span class="text-white">My Timetable</span>
                    </a>
                    <a href="/school_ms/academic/classes/my_classes.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/classes/my_classes.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white">My Classes</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Academic Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
        <div id="academic-section" class="px-2 mb-4" x-data="{ academicOpen: <?php echo strpos($current_page, '/academic/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Academic Management</h3>

            <div class="space-y-2">
                <button @click="academicOpen = !academicOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/academic/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-graduation-cap text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Academics</span>
                        <p class="text-xs text-blue-100 opacity-75">Classes, subjects & more</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': academicOpen }"></i>
                </button>

                <div x-show="academicOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/academic/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/academic/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white">Academic Overview</span>
                    </a>
                    <a href="/school_ms/academic/classes/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/classes/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white">Classes</span>
                    </a>
                    <a href="/school_ms/academic/subjects/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/subjects/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-book w-4 text-blue-200"></i>
                        <span class="text-white">Subjects</span>
                    </a>
                    <a href="/school_ms/academic/assignments/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tasks w-4 text-blue-200"></i>
                        <span class="text-white">Assignments</span>
                    </a>
                    <a href="/school_ms/academic/timetable/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/timetable/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-calendar-alt w-4 text-blue-200"></i>
                        <span class="text-white">Timetable</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/academic/class-management.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/class-management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user-friends w-4 text-blue-200"></i>
                        <span class="text-white">Class Management</span>
                    </a>
                    <a href="/school_ms/academic/settings/" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/settings/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-cogs w-4 text-blue-200"></i>
                        <span class="text-white">Academic Settings</span>
                    </a>
                    <a href="/school_ms/academic/promotions/" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/promotions/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-graduation-cap w-4 text-blue-200"></i>
                        <span class="text-white">Student Promotions</span>
                    </a>
                    <a href="/school_ms/academic/records/" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/records/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white">Academic Records</span>
                    </a>
                    <a href="/school_ms/academic/reports/generate.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/reports/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-file-alt w-4 text-blue-200"></i>
                        <span class="text-white">Term Reports</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Examinations -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])): ?>
        <div class="px-2 mb-4" x-data="{ examOpen: <?php echo strpos($current_page, '/academic/exams/') !== false ? 'true' : 'false'; ?> }">
            <div class="space-y-2">
                <button @click="examOpen = !examOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/academic/exams/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/academic/exams/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-file-alt text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Examinations</span>
                        <p class="text-xs text-blue-100 opacity-75">Exams & results</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': examOpen }"></i>
                </button>

                <div x-show="examOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/academic/exams/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/exams/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white">All Exams</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/academic/exams/create.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/exams/create.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-plus w-4 text-blue-200"></i>
                        <span class="text-white">Schedule Exam</span>
                    </a>
                    <?php endif; ?>
                    <a href="/school_ms/academic/exams/results.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/academic/exams/results.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white">Results</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Attendance -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
        <div class="px-2 mb-4">
            <a href="/school_ms/attendance/index.php" class="flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/attendance/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/attendance/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                    <i class="fas fa-calendar-check text-lg text-white"></i>
                </div>
                <div class="flex-1">
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
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher'])): ?>
        <div id="reports-section" class="px-2 mb-4" x-data="{ reportsOpen: <?php echo strpos($current_page, '/reports/') !== false ? 'true' : 'false'; ?> }">
            <div class="space-y-2">
                <button @click="reportsOpen = !reportsOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/reports/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/reports/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-chart-line text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Reports</span>
                        <p class="text-xs text-blue-100 opacity-75">Analytics & insights</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': reportsOpen }"></i>
                </button>

                <div x-show="reportsOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/reports/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/reports/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white">Reports Dashboard</span>
                    </a>
                    <a href="/school_ms/reports/academic.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/reports/academic.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-graduation-cap w-4 text-blue-200"></i>
                        <span class="text-white">Academic Progress</span>
                    </a>
                    <a href="/school_ms/attendance/reports.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/attendance/reports.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-calendar-check w-4 text-blue-200"></i>
                        <span class="text-white">Attendance Reports</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="/school_ms/reports/class.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/reports/class.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chalkboard w-4 text-blue-200"></i>
                        <span class="text-white">Class Reports</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Library Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'librarian', 'student', 'teacher'])): ?>
        <div id="library-section" class="px-2 mb-4" x-data="{ libraryOpen: <?php echo strpos($current_page, '/library/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Library Management</h3>

            <div class="space-y-2">
                <button @click="libraryOpen = !libraryOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/library/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/library/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-book text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Library</span>
                        <p class="text-xs text-blue-100 opacity-75">Books & resources</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': libraryOpen }"></i>
                </button>

                <div x-show="libraryOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/library/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/library/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white">Library Overview</span>
                    </a>
                    <a href="/school_ms/library/borrow.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/library/borrow.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-hand-holding w-4 text-blue-200"></i>
                        <span class="text-white">Borrow Books</span>
                    </a>
                    <a href="/school_ms/library/loans.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/library/loans.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-exchange-alt w-4 text-blue-200"></i>
                        <span class="text-white">Book Loans</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'librarian'])): ?>
                    <a href="/school_ms/library/manage.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/library/manage.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-cogs w-4 text-blue-200"></i>
                        <span class="text-white">Manage Library</span>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Transport Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'transport_officer'])): ?>
        <div id="transport-section" class="px-2 mb-4" x-data="{ transportOpen: <?php echo strpos($current_page, '/transport/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Transport Management</h3>

            <div class="space-y-2">
                <button @click="transportOpen = !transportOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/transport/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/transport/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-bus text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Transport</span>
                        <p class="text-xs text-blue-100 opacity-75">Routes & vehicles</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': transportOpen }"></i>
                </button>

                <div x-show="transportOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/transport/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/transport/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Transport Dashboard</span>
                    </a>
                    <a href="/school_ms/transport/routes/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/transport/routes/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-route w-4 text-blue-200"></i>
                        <span class="text-white">Routes</span>
                    </a>
                    <a href="/school_ms/transport/vehicles/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/transport/vehicles/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-bus w-4 text-blue-200"></i>
                        <span class="text-white">Vehicles</span>
                    </a>
                    <a href="/school_ms/transport/assignments/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/transport/assignments/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user-graduate w-4 text-blue-200"></i>
                        <span class="text-white">Student Assignments</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Hostel Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'hostel_warden'])): ?>
        <div id="hostel-section" class="px-2 mb-4" x-data="{ hostelOpen: <?php echo strpos($current_page, '/hostel/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Hostel Management</h3>

            <div class="space-y-2">
                <button @click="hostelOpen = !hostelOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/hostel/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/hostel/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-bed text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Hostel</span>
                        <p class="text-xs text-blue-100 opacity-75">Rooms & allocations</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': hostelOpen }"></i>
                </button>

                <div x-show="hostelOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/hostel/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/hostel/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Hostel Dashboard</span>
                    </a>
                    <a href="/school_ms/hostel/blocks/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/hostel/blocks/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-building w-4 text-blue-200"></i>
                        <span class="text-white">Blocks</span>
                    </a>
                    <a href="/school_ms/hostel/rooms/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/hostel/rooms/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-bed w-4 text-blue-200"></i>
                        <span class="text-white">Rooms</span>
                    </a>
                    <a href="/school_ms/hostel/allocations/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/hostel/allocations/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-users w-4 text-blue-200"></i>
                        <span class="text-white">Allocations</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Canteen Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'canteen_manager'])): ?>
        <div id="canteen-section" class="px-2 mb-4" x-data="{ canteenOpen: <?php echo strpos($current_page, '/canteen/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Canteen Management</h3>

            <div class="space-y-2">
                <button @click="canteenOpen = !canteenOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/canteen/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/canteen/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-utensils text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Canteen</span>
                        <p class="text-xs text-blue-100 opacity-75">Meals & orders</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': canteenOpen }"></i>
                </button>

                <div x-show="canteenOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/canteen/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/canteen/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Canteen Dashboard</span>
                    </a>
                    <a href="/school_ms/canteen/menu/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/canteen/menu/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-list w-4 text-blue-200"></i>
                        <span class="text-white">Menu</span>
                    </a>
                    <a href="/school_ms/canteen/orders/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/canteen/orders/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-shopping-cart w-4 text-blue-200"></i>
                        <span class="text-white">Orders</span>
                    </a>
                    <a href="/school_ms/canteen/inventory/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/canteen/inventory/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-boxes w-4 text-blue-200"></i>
                        <span class="text-white">Inventory</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Finance Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'accountant'])): ?>
        <div class="px-2 mb-4" x-data="{ financeOpen: <?php echo strpos($current_page, '/finance/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Finance Management</h3>

            <div class="space-y-2">
                <button @click="financeOpen = !financeOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/finance/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/finance/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-money-bill-wave text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Finance</span>
                        <p class="text-xs text-blue-100 opacity-75">Fees & payments</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': financeOpen }"></i>
                </button>

                <div x-show="financeOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/finance/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/finance/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-line w-4 text-blue-200"></i>
                        <span class="text-white">Finance Overview</span>
                    </a>
                    <a href="/school_ms/finance/fee_structures.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/finance/fee_structures.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-list-alt w-4 text-blue-200"></i>
                        <span class="text-white">Fee Structures</span>
                    </a>
                    <a href="/school_ms/finance/payments.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/finance/payments.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-credit-card w-4 text-blue-200"></i>
                        <span class="text-white">Payments</span>
                    </a>
                    <a href="/school_ms/finance/reports.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/finance/reports.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-chart-bar w-4 text-blue-200"></i>
                        <span class="text-white">Financial Reports</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Parent Portal -->
        <?php if ($role === 'parent'): ?>
        <div id="parent-section" class="px-2 mb-4" x-data="{ parentOpen: <?php echo strpos($current_page, '/parent/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Parent Portal</h3>

            <div class="space-y-2">
                <button @click="parentOpen = !parentOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/parent/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/parent/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-users text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Parent Portal</span>
                        <p class="text-xs text-blue-100 opacity-75">Monitor children</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': parentOpen }"></i>
                </button>

                <div x-show="parentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/parent/dashboard.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/parent/dashboard.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-home w-4 text-blue-200"></i>
                        <span class="text-white">Dashboard</span>
                    </a>

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
                            <span class="text-white">Academic Progress</span>
                        </a>
                        <a href="/school_ms/parent/child_attendance.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/child_attendance.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-calendar-check w-3 text-blue-200"></i>
                            <span class="text-white">Attendance</span>
                        </a>
                        <a href="/school_ms/parent/child_assignments.php?student_id=<?php echo $child['id']; ?>"
                           class="flex items-center space-x-3 px-3 py-1.5 rounded-lg <?php echo (strpos($current_page, '/parent/child_assignments.php') !== false && $_GET['student_id'] == $child['id']) ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-xs">
                            <i class="fas fa-tasks w-3 text-blue-200"></i>
                            <span class="text-white">Assignments</span>
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
        <?php if (in_array($role, ['super_admin', 'school_admin', 'nurse', 'counselor'])): ?>
        <div id="health-section" class="px-2 mb-4" x-data="{ healthOpen: <?php echo strpos($current_page, '/health/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Health & Counseling</h3>

            <div class="space-y-2">
                <button @click="healthOpen = !healthOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/health/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/health/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-heartbeat text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Health & Counseling</span>
                        <p class="text-xs text-blue-100 opacity-75">Medical & counseling</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': healthOpen }"></i>
                </button>

                <div x-show="healthOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/health/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/health/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Health Dashboard</span>
                    </a>
                    <a href="/school_ms/health/records/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/health/records/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-file-medical w-4 text-blue-200"></i>
                        <span class="text-white">Health Records</span>
                    </a>
                    <a href="/school_ms/health/counseling/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/health/counseling/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white">Counseling</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Inventory Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin'])): ?>
        <div id="inventory-section" class="px-2 mb-4" x-data="{ inventoryOpen: <?php echo strpos($current_page, '/inventory/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Inventory Management</h3>

            <div class="space-y-2">
                <button @click="inventoryOpen = !inventoryOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/inventory/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/inventory/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-boxes text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Inventory</span>
                        <p class="text-xs text-blue-100 opacity-75">Assets & supplies</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': inventoryOpen }"></i>
                </button>

                <div x-show="inventoryOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/inventory/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/inventory/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Inventory Dashboard</span>
                    </a>
                    <a href="/school_ms/inventory/items/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/inventory/items/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-box w-4 text-blue-200"></i>
                        <span class="text-white">Items</span>
                    </a>
                    <a href="/school_ms/inventory/requests/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/inventory/requests/') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-hand-paper w-4 text-blue-200"></i>
                        <span class="text-white">Requests</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Online Learning Tools -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])): ?>
        <div id="online-learning-section" class="px-2 mb-4" x-data="{ onlineLearningOpen: <?php echo strpos($current_page, '/online_learning/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Online Learning Tools</h3>

            <div class="space-y-2">
                <button @click="onlineLearningOpen = !onlineLearningOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/online_learning/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/online_learning/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-laptop text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Online Learning</span>
                        <p class="text-xs text-blue-100 opacity-75">Virtual classes & tools</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': onlineLearningOpen }"></i>
                </button>

                <div x-show="onlineLearningOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/online_learning/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/online_learning/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Learning Dashboard</span>
                    </a>
                    <a href="/school_ms/online_learning/virtual_classroom.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/online_learning/virtual_classroom.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-video w-4 text-blue-200"></i>
                        <span class="text-white">Virtual Classroom</span>
                    </a>
                    <a href="/school_ms/online_learning/materials.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/online_learning/materials.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-folder-open w-4 text-blue-200"></i>
                        <span class="text-white">Learning Materials</span>
                    </a>
                    <a href="/school_ms/online_learning/quizzes.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/online_learning/quizzes.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-question-circle w-4 text-blue-200"></i>
                        <span class="text-white">Quizzes & Tests</span>
                    </a>
                    <a href="/school_ms/online_learning/submissions.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/online_learning/submissions.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-upload w-4 text-blue-200"></i>
                        <span class="text-white">Submissions</span>
                    </a>
                    <a href="/school_ms/online_learning/discussions.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/online_learning/discussions.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white">Discussion Boards</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Document & File Management -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])): ?>
        <div id="document-management-section" class="px-2 mb-4" x-data="{ documentOpen: <?php echo strpos($current_page, '/documents/') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Document & File Management</h3>

            <div class="space-y-2">
                <button @click="documentOpen = !documentOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/documents/') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/documents/') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-file-alt text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Documents</span>
                        <p class="text-xs text-blue-100 opacity-75">Files & certificates</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': documentOpen }"></i>
                </button>

                <div x-show="documentOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/documents/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/documents/index.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-tachometer-alt w-4 text-blue-200"></i>
                        <span class="text-white">Document Dashboard</span>
                    </a>
                    <a href="/school_ms/documents/upload.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/documents/upload.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-cloud-upload-alt w-4 text-blue-200"></i>
                        <span class="text-white">Upload Documents</span>
                    </a>
                    <a href="/school_ms/documents/certificates.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/documents/certificates.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-certificate w-4 text-blue-200"></i>
                        <span class="text-white">Certificates & IDs</span>
                    </a>
                    <a href="/school_ms/documents/transcripts.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/documents/transcripts.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-scroll w-4 text-blue-200"></i>
                        <span class="text-white">Transcripts</span>
                    </a>
                    <a href="/school_ms/documents/shared.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/documents/shared.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-share-alt w-4 text-blue-200"></i>
                        <span class="text-white">Shared Files</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Communication -->
        <?php if (in_array($role, ['super_admin', 'school_admin', 'principal', 'teacher', 'student', 'parent'])): ?>
        <div class="px-2 mb-4" x-data="{ communicationOpen: <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Communication</h3>

            <div class="space-y-2">
                <button @click="communicationOpen = !communicationOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/communication/') !== false || strpos($current_page, '/notifications.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-comments text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Communication</span>
                        <p class="text-xs text-blue-100 opacity-75">Messages & notifications</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': communicationOpen }"></i>
                </button>

                <div x-show="communicationOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/notifications.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/notifications.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-bell w-4 text-blue-200"></i>
                        <span class="text-white">Notifications</span>
                    </a>
                    <a href="/school_ms/communication/live_chat.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/communication/live_chat.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-comment-dots w-4 text-blue-200"></i>
                        <span class="text-white">Live Chat</span>
                        <span class="bg-green-500 text-white text-xs rounded-full px-2 py-0.5 ml-auto">New</span>
                    </a>
                    <a href="/school_ms/communication/index.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/communication/index.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-comments w-4 text-blue-200"></i>
                        <span class="text-white">Messages</span>
                    </a>
                    <a href="/school_ms/communication/announcements.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/communication/announcements.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-bullhorn w-4 text-blue-200"></i>
                        <span class="text-white">Announcements</span>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Help & Support -->
        <div class="px-2 mb-4" x-data="{ helpOpen: <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Help & Support</h3>

            <div class="space-y-2">
                <button @click="helpOpen = !helpOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/help.php') !== false || strpos($current_page, '/support.php') !== false || strpos($current_page, '/feedback.php') !== false || strpos($current_page, '/admin/feedback_management.php') !== false || strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-question-circle text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Help & Support</span>
                        <p class="text-xs text-blue-100 opacity-75">Documentation & help</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': helpOpen }"></i>
                </button>

                <div x-show="helpOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/help.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/help.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-book-open w-4 text-blue-200"></i>
                        <span class="text-white">Help Center</span>
                    </a>
                    <a href="/school_ms/support.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/support.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-headset w-4 text-blue-200"></i>
                        <span class="text-white">Contact Support</span>
                    </a>
                    <a href="/school_ms/feedback.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/feedback.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-comment-alt w-4 text-blue-200"></i>
                        <span class="text-white">Send Feedback</span>
                    </a>

                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <div class="border-t border-white/20 pt-2 mt-2">
                        <a href="/school_ms/admin/feedback_management.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/admin/feedback_management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                            <i class="fas fa-comments-dollar w-4 text-blue-200"></i>
                            <span class="text-white">Manage Feedback</span>
                        </a>
                        <a href="/school_ms/admin/support_management.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/admin/support_management.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                            <i class="fas fa-ticket-alt w-4 text-blue-200"></i>
                            <span class="text-white">Manage Support Tickets</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Settings -->
        <div id="settings-section" class="px-2 mb-4" x-data="{ settingsOpen: <?php echo strpos($current_page, '/settings') !== false ? 'true' : 'false'; ?> }">
            <h3 class="px-4 text-xs font-semibold text-blue-200 uppercase tracking-wider mb-3">Settings</h3>

            <div class="space-y-2">
                <button @click="settingsOpen = !settingsOpen" class="w-full flex items-center space-x-3 px-4 py-3 rounded-xl <?php echo strpos($current_page, '/settings') !== false ? 'bg-white/20 shadow-lg backdrop-blur-sm' : 'hover:bg-white/10'; ?> transition-all duration-200 group">
                    <div class="w-10 h-10 rounded-lg <?php echo strpos($current_page, '/settings') !== false ? 'bg-white/30' : 'bg-white/10 group-hover:bg-white/20'; ?> flex items-center justify-center transition-colors duration-200 backdrop-blur-sm">
                        <i class="fas fa-cog text-lg text-white"></i>
                    </div>
                    <div class="flex-1 text-left">
                        <span class="font-medium text-white">Settings</span>
                        <p class="text-xs text-blue-100 opacity-75">Preferences & config</p>
                    </div>
                    <i class="fas fa-chevron-down text-sm transition-transform duration-200 text-white" :class="{ 'rotate-180': settingsOpen }"></i>
                </button>

                <div x-show="settingsOpen" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 -translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 -translate-y-2" class="ml-6 space-y-1">
                    <a href="/school_ms/settings.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo $current_page === '/school_ms/settings.php' ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-user-cog w-4 text-blue-200"></i>
                        <span class="text-white">My Profile</span>
                    </a>
                    <?php if (in_array($role, ['super_admin', 'school_admin'])): ?>
                    <a href="/school_ms/settings/school.php" class="flex items-center space-x-3 px-4 py-2 rounded-lg <?php echo strpos($current_page, '/settings/school.php') !== false ? 'bg-white/20' : 'hover:bg-white/10'; ?> transition-all duration-200 text-sm">
                        <i class="fas fa-school w-4 text-blue-200"></i>
                        <span class="text-white">School Settings</span>
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
            <span class="font-medium">v2.0.1</span>
            <div class="flex items-center space-x-2">
                <div class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                <span>Online</span>
            </div>
        </div>
    </div>
</div>

<!-- Enhanced Scrollbar Styles -->
<style>
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

    /* Adjust sidebar navigation height for mobile */
    #sidebar-nav {
        height: calc(100vh - 240px) !important;
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
</style>

<!-- Sidebar JavaScript -->
<script>
// Sidebar toggle functionality (Mobile & Desktop)
document.getElementById('sidebar-toggle')?.addEventListener('click', function() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        if (window.innerWidth >= 1024) {
            // Desktop: Toggle collapse state using Alpine store
            if (window.Alpine && window.Alpine.store('sidebar')) {
                window.Alpine.store('sidebar').toggle();
            } else {
                // Fallback: dispatch custom event
                window.dispatchEvent(new CustomEvent('sidebar-toggle'));
            }
        } else {
            // Mobile: Toggle visibility
            sidebar.classList.toggle('-translate-x-full');
        }
    }
});

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(event) {
    const sidebar = document.getElementById('sidebar');
    const sidebarToggle = document.getElementById('sidebar-toggle');

    if (window.innerWidth < 1024 && // lg breakpoint
        !sidebar?.contains(event.target) &&
        !sidebarToggle?.contains(event.target)) {
        sidebar?.classList.add('-translate-x-full');
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
    const sidebarLinks = document.querySelectorAll('#sidebar a[href^="/school_ms/"]');

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