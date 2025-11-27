<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: /school_ms/auth/login.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Academic Dashboard";
$user_role = $_SESSION['role'];
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <!-- Header Section -->
            <div class="mb-8">
                <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-bold mb-2">Academic Management</h1>
                            <p class="text-blue-100 text-lg">Manage classes, subjects, assignments, and academic activities</p>
                            <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                <div class="flex items-center">
                                    <i class="fas fa-graduation-cap mr-2"></i>
                                    Academic Year 2024-2025
                                </div>
                                <div class="flex items-center">
                                    <i class="fas fa-calendar-alt mr-2"></i>
                                    <?php echo date('F j, Y'); ?>
                                </div>
                            </div>
                        </div>
                        <div class="hidden md:block">
                            <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                <i class="fas fa-graduation-cap text-6xl text-white/80"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Classes -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Classes</p>
                            <?php
                            $query = "SELECT COUNT(*) as count FROM classes WHERE status = 'active'";
                            $stmt = $db->query($query);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $result['count']; ?></p>
                            <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                <i class="fas fa-arrow-up mr-1"></i>
                                Active classes
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-chalkboard text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Total Subjects -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Total Subjects</p>
                            <?php
                            $query = "SELECT COUNT(*) as count FROM subjects";
                            $stmt = $db->query($query);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $result['count']; ?></p>
                            <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                <i class="fas fa-book mr-1"></i>
                                Available subjects
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Active Assignments -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Assignments</p>
                            <?php
                            $query = "SELECT COUNT(*) as count FROM assignments WHERE due_date >= CURDATE()";
                            $stmt = $db->query($query);
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $result['count']; ?></p>
                            <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                <i class="fas fa-tasks mr-1"></i>
                                Pending assignments
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-tasks text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                    </div>
                </div>

                <!-- Upcoming Exams -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Upcoming Exams</p>
                            <?php
                            $current_date = date('Y-m-d');
                            $query = "SELECT COUNT(*) as count FROM exams WHERE date >= :current_date";
                            $stmt = $db->prepare($query);
                            $stmt->bindParam(':current_date', $current_date);
                            $stmt->execute();
                            $result = $stmt->fetch(PDO::FETCH_ASSOC);
                            ?>
                            <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $result['count']; ?></p>
                            <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                <i class="fas fa-calendar-alt mr-1"></i>
                                Scheduled exams
                            </p>
                        </div>
                        <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-file-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Academic Management Grid -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                <!-- Classes Management -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                                <i class="fas fa-chalkboard text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="classes/create.php" class="text-blue-500 hover:text-blue-600 dark:text-blue-400 dark:hover:text-blue-300 p-2 rounded-lg hover:bg-blue-50 dark:hover:bg-blue-900/50 transition-colors duration-200">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Classes</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage school classes, sections, and student assignments.</p>
                        <a href="classes/index.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Classes</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Subjects Management -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                                <i class="fas fa-book text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="subjects/create.php" class="text-green-500 hover:text-green-600 dark:text-green-400 dark:hover:text-green-300 p-2 rounded-lg hover:bg-green-50 dark:hover:bg-green-900/50 transition-colors duration-200">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Subjects</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Manage academic subjects and their assignments to classes.</p>
                        <a href="subjects/index.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Subjects</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Assignments -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                                <i class="fas fa-tasks text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher'])): ?>
                            <a href="assignments/create.php" class="text-purple-500 hover:text-purple-600 dark:text-purple-400 dark:hover:text-purple-300 p-2 rounded-lg hover:bg-purple-50 dark:hover:bg-purple-900/50 transition-colors duration-200">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Assignments</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Create and manage student assignments and submissions.</p>
                        <a href="assignments/index.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Assignments</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Timetable -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                                <i class="fas fa-calendar-alt text-indigo-600 dark:text-indigo-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="timetable/index.php" class="text-indigo-500 hover:text-indigo-600 dark:text-indigo-400 dark:hover:text-indigo-300 p-2 rounded-lg hover:bg-indigo-50 dark:hover:bg-indigo-900/50 transition-colors duration-200">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Timetable</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Create and manage class schedules and timetables.</p>
                        <a href="timetable/index.php" class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Timetable</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Examinations -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                                <i class="fas fa-file-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="exams/create.php" class="text-orange-500 hover:text-orange-600 dark:text-orange-400 dark:hover:text-orange-300 p-2 rounded-lg hover:bg-orange-50 dark:hover:bg-orange-900/50 transition-colors duration-200">
                                <i class="fas fa-plus"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Examinations</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Schedule and manage examinations and view results.</p>
                        <a href="exams/index.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Exams</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Class Management -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-pink-100 dark:bg-pink-900 rounded-lg flex items-center justify-center group-hover:bg-pink-200 dark:group-hover:bg-pink-800 transition-colors duration-200">
                                <i class="fas fa-user-friends text-pink-600 dark:text-pink-400 text-xl"></i>
                            </div>
                            <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                            <a href="class-management.php" class="text-pink-500 hover:text-pink-600 dark:text-pink-400 dark:hover:text-pink-300 p-2 rounded-lg hover:bg-pink-50 dark:hover:bg-pink-900/50 transition-colors duration-200">
                                <i class="fas fa-edit"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Class Management</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Assign students to classes, main teachers, and subject teachers.</p>
                        <a href="class-management.php" class="inline-flex items-center text-pink-600 dark:text-pink-400 hover:text-pink-800 dark:hover:text-pink-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>Manage Classes</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>

                <!-- Academic Reports -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg overflow-hidden border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                    <div class="p-6">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                                <i class="fas fa-chart-bar text-teal-600 dark:text-teal-400 text-xl"></i>
                            </div>
                            <a href="../reports/academic.php" class="text-teal-500 hover:text-teal-600 dark:text-teal-400 dark:hover:text-teal-300 p-2 rounded-lg hover:bg-teal-50 dark:hover:bg-teal-900/50 transition-colors duration-200">
                                <i class="fas fa-external-link-alt"></i>
                            </a>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-800 dark:text-white mb-2">Academic Reports</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4 text-sm">Generate and view academic performance reports.</p>
                        <a href="../reports/academic.php" class="inline-flex items-center text-teal-600 dark:text-teal-400 hover:text-teal-800 dark:hover:text-teal-300 font-medium text-sm group-hover:translate-x-1 transition-all duration-200">
                            <span>View Reports</span>
                            <i class="fas fa-arrow-right ml-2"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                <div class="flex items-center justify-between mb-6">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Quick Actions</h3>
                    <span class="text-sm text-gray-500 dark:text-gray-400">Frequently used features</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <a href="classes/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-blue-200 dark:group-hover:bg-blue-800 transition-colors duration-200">
                            <i class="fas fa-plus text-blue-600 dark:text-blue-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Class</span>
                    </a>
                    <a href="subjects/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-green-200 dark:group-hover:bg-green-800 transition-colors duration-200">
                            <i class="fas fa-book text-green-600 dark:text-green-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Add Subject</span>
                    </a>
                    <a href="class-management.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-pink-100 dark:bg-pink-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-pink-200 dark:group-hover:bg-pink-800 transition-colors duration-200">
                            <i class="fas fa-user-friends text-pink-600 dark:text-pink-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Class Management</span>
                    </a>
                    <?php endif; ?>

                    <?php if (in_array($user_role, ['super_admin', 'school_admin', 'teacher'])): ?>
                    <a href="assignments/create.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-purple-200 dark:group-hover:bg-purple-800 transition-colors duration-200">
                            <i class="fas fa-tasks text-purple-600 dark:text-purple-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Create Assignment</span>
                    </a>
                    <?php endif; ?>

                    <a href="timetable/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-indigo-200 dark:group-hover:bg-indigo-800 transition-colors duration-200">
                            <i class="fas fa-calendar-alt text-indigo-600 dark:text-indigo-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">View Timetable</span>
                    </a>

                    <a href="exams/index.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-orange-200 dark:group-hover:bg-orange-800 transition-colors duration-200">
                            <i class="fas fa-file-alt text-orange-600 dark:text-orange-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">View Exams</span>
                    </a>

                    <a href="../reports/academic.php" class="flex flex-col items-center p-4 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors duration-200 group">
                        <div class="w-12 h-12 bg-teal-100 dark:bg-teal-900 rounded-lg flex items-center justify-center mb-3 group-hover:bg-teal-200 dark:group-hover:bg-teal-800 transition-colors duration-200">
                            <i class="fas fa-chart-bar text-teal-600 dark:text-teal-400 text-xl"></i>
                        </div>
                        <span class="text-sm font-medium text-gray-900 dark:text-white text-center">Academic Reports</span>
                    </a>
                </div>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>