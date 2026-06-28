<?php
session_start();
require_once '../includes/access_control.php';
requireModuleRole('online_learning');

require_once '../config/database.php';
require_once '../includes/module_access.php';
requireModule('online_learning'); // block access if disabled for this school
$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$user_name = $_SESSION['name'] ?? 'User';

// Get statistics based on role
$stats = [];

try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Scheduled virtual classrooms
        $stmt = $db->query("SELECT COUNT(*) FROM virtual_classrooms WHERE status = 'scheduled'");
        $scheduled_classes = $stmt->fetchColumn();

        // Learning materials uploaded in the last 30 days
        $stmt = $db->query("SELECT COUNT(*) FROM learning_materials WHERE created_at >= NOW() - INTERVAL 30 DAY");
        $recent_materials = $stmt->fetchColumn();

        // Active quizzes
        $stmt = $db->query("SELECT COUNT(*) FROM online_quizzes WHERE status = 'published'");
        $active_quizzes = $stmt->fetchColumn();

        // Recent discussions (last 7 days)
        $stmt = $db->query("SELECT COUNT(*) FROM discussion_boards WHERE created_at >= NOW() - INTERVAL 7 DAY");
        $recent_discussions = $stmt->fetchColumn();

        $stats = [
            'scheduled_classes' => $scheduled_classes,
            'recent_materials' => $recent_materials,
            'active_quizzes' => $active_quizzes,
            'recent_discussions' => $recent_discussions
        ];
    } elseif ($role === 'teacher') {
        // Teacher statistics
        // Scheduled virtual classrooms owned by teacher
        $stmt = $db->prepare("SELECT COUNT(*) FROM virtual_classrooms WHERE teacher_id = :teacher_id AND status = 'scheduled'");
        $stmt->execute([':teacher_id' => $user_id]);
        $my_classes = $stmt->fetchColumn();

        // Learning materials uploaded by teacher
        $stmt = $db->prepare("SELECT COUNT(*) FROM learning_materials WHERE uploaded_by = :teacher_id");
        $stmt->execute([':teacher_id' => $user_id]);
        $my_materials = $stmt->fetchColumn();

        // Active quizzes created by teacher
        $stmt = $db->prepare("SELECT COUNT(*) FROM online_quizzes WHERE teacher_id = :teacher_id AND status = 'published'");
        $stmt->execute([':teacher_id' => $user_id]);
        $my_quizzes = $stmt->fetchColumn();

        // Discussions created by teacher
        $stmt = $db->prepare("SELECT COUNT(*) FROM discussion_boards WHERE created_by = :teacher_id");
        $stmt->execute([':teacher_id' => $user_id]);
        $my_discussions = $stmt->fetchColumn();

        $stats = [
            'my_classes' => $my_classes,
            'my_materials' => $my_materials,
            'my_quizzes' => $my_quizzes,
            'my_discussions' => $my_discussions
        ];
    } else {
        // Student statistics
        // Get student's class
        $stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $stmt->execute([':student_id' => $user_id]);
        $class_id = $stmt->fetchColumn();

        $upcoming_classes = 0;
        if ($class_id) {
            $stmt = $db->prepare("SELECT COUNT(*) FROM virtual_classrooms WHERE class_id = :class_id AND status = 'scheduled'");
            $stmt->execute([':class_id' => $class_id]);
            $upcoming_classes = $stmt->fetchColumn();
        }

        // Completed quizzes (distinct quizzes they completed attempts for)
        $stmt = $db->prepare("SELECT COUNT(DISTINCT quiz_id) FROM quiz_attempts WHERE student_id = :student_id AND status = 'completed'");
        $stmt->execute([':student_id' => $user_id]);
        $completed_quizzes = $stmt->fetchColumn();

        // Submitted assignments
        $stmt = $db->prepare("SELECT COUNT(*) FROM student_assignments WHERE student_id = :student_id AND status = 'submitted'");
        $stmt->execute([':student_id' => $user_id]);
        $submitted_assignments = $stmt->fetchColumn();

        // Discussion posts
        $stmt = $db->prepare("SELECT COUNT(*) FROM discussion_posts WHERE user_id = :student_id");
        $stmt->execute([':student_id' => $user_id]);
        $my_posts = $stmt->fetchColumn();

        $stats = [
            'upcoming_classes' => $upcoming_classes,
            'completed_quizzes' => $completed_quizzes,
            'submitted_assignments' => $submitted_assignments,
            'my_posts' => $my_posts
        ];
    }
} catch (PDOException $e) {
    $stats = [];
}

// Get recent activities
$recent_activities = [];
try {
    if (in_array($role, ['super_admin', 'school_admin', 'principal'])) {
        // Query recent quizzes and learning materials overall
        $recent_query = "
            (SELECT 'quiz' as type, title, q.created_at, u.name as teacher_name, NULL as uploader_name
             FROM online_quizzes q
             JOIN users u ON q.teacher_id = u.id)
            UNION
            (SELECT 'material' as type, title, lm.created_at, NULL as teacher_name, u.name as uploader_name
             FROM learning_materials lm
             JOIN users u ON lm.uploaded_by = u.id)
            ORDER BY created_at DESC LIMIT 50
        ";
        $stmt = $db->query($recent_query);
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($role === 'teacher') {
        // Query recent quizzes and learning materials uploaded by this teacher
        $recent_query = "
            (SELECT 'quiz' as type, title, q.created_at, u.name as teacher_name, NULL as uploader_name
             FROM online_quizzes q
             JOIN users u ON q.teacher_id = u.id
             WHERE q.teacher_id = :teacher_id)
            UNION
            (SELECT 'material' as type, title, lm.created_at, NULL as teacher_name, u.name as uploader_name
             FROM learning_materials lm
             JOIN users u ON lm.uploaded_by = u.id
             WHERE lm.uploaded_by = :teacher_id)
            ORDER BY created_at DESC LIMIT 50
        ";
        $stmt = $db->prepare($recent_query);
        $stmt->execute([':teacher_id' => $user_id]);
        $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Student: fetch for their class
        $stmt = $db->prepare("SELECT class_id FROM student_classes WHERE student_id = :student_id AND status = 'active' LIMIT 1");
        $stmt->execute([':student_id' => $user_id]);
        $class_id = $stmt->fetchColumn();

        if ($class_id) {
            $recent_query = "
                (SELECT 'quiz' as type, title, q.created_at, u.name as teacher_name, NULL as uploader_name
                 FROM online_quizzes q
                 JOIN users u ON q.teacher_id = u.id
                 WHERE q.class_id = :class_id AND q.status = 'published')
                UNION
                (SELECT 'material' as type, title, lm.created_at, NULL as teacher_name, u.name as uploader_name
                 FROM learning_materials lm
                 JOIN users u ON lm.uploaded_by = u.id
                 WHERE lm.class_id = :class_id)
                ORDER BY created_at DESC LIMIT 50
            ";
            $stmt = $db->prepare($recent_query);
            $stmt->execute([':class_id' => $class_id]);
            $recent_activities = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (PDOException $e) {
    $recent_activities = [];
}

// Force output buffering to catch any errors
ob_start();

// Add proper headers
header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

// Ensure title is set before includes
$title = "Online Learning Tools";

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Online Learning Tools Page - Cache Buster: <?php echo time(); ?> -->
<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen online-learning-container">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-2xl font-bold mb-2">Online Learning Tools</h1>
                                <p class="text-blue-100 text-lg">Access virtual classrooms and online learning resources</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-laptop mr-2"></i>
                                        <span>Virtual classrooms & online assessments</span>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-clock mr-2"></i>
                                        <span><?php echo date('l, F j, Y'); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden lg:block">
                                <div class="w-16 h-16 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-graduation-cap text-4xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Statistics Cards -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                    <?php if (in_array($role, ['super_admin', 'school_admin', 'principal'])): ?>
                    <!-- Scheduled Classes -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Scheduled Classes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['scheduled_classes'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-video mr-1"></i>
                                    Virtual sessions
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-video text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Materials -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">New Materials</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['recent_materials'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-arrow-up mr-1"></i>
                                    Last 30 days
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-folder-open text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Active Quizzes -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Quizzes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['active_quizzes'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-question-circle mr-1"></i>
                                    Published
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-question-circle text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <!-- Recent Discussions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">New Discussions</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['recent_discussions'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-comments mr-1"></i>
                                    This week
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comments text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($role === 'teacher'): ?>
                    <!-- Teacher specific stats -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Classes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_classes'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-video mr-1"></i>
                                    Scheduled
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-chalkboard-teacher text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Materials</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_materials'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-upload mr-1"></i>
                                    Uploaded
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-folder text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Quizzes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_quizzes'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-question-circle mr-1"></i>
                                    Active
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-quiz text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Discussions</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_discussions'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-comments mr-1"></i>
                                    Created
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comments text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php else: // Student ?>
                    <!-- Student specific stats -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Upcoming Classes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['upcoming_classes'] ?? 0; ?></p>
                                <p class="text-sm text-blue-600 dark:text-blue-400 mt-1">
                                    <i class="fas fa-calendar mr-1"></i>
                                    Scheduled
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-video text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Completed Quizzes</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['completed_quizzes'] ?? 0; ?></p>
                                <p class="text-sm text-green-600 dark:text-green-400 mt-1">
                                    <i class="fas fa-check mr-1"></i>
                                    Finished
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-check-circle text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Submitted Assignments</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['submitted_assignments'] ?? 0; ?></p>
                                <p class="text-sm text-purple-600 dark:text-purple-400 mt-1">
                                    <i class="fas fa-upload mr-1"></i>
                                    Submitted
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-file-upload text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-shadow duration-300">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Discussion Posts</p>
                                <p class="text-3xl font-bold text-gray-900 dark:text-white"><?php echo $stats['my_posts'] ?? 0; ?></p>
                                <p class="text-sm text-orange-600 dark:text-orange-400 mt-1">
                                    <i class="fas fa-comment mr-1"></i>
                                    Posted
                                </p>
                            </div>
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-comment text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Actions Grid -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                    <!-- Virtual Classroom -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-video text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-blue-100 dark:bg-blue-900 text-blue-800 dark:text-blue-200 px-2 py-1 rounded-full">Live</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Virtual Classroom</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Join or create virtual classroom sessions with video conferencing integration.</p>
                        <a href="virtual_classroom.php" class="inline-flex items-center text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 font-medium text-sm">
                            <span>Access Classroom</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Learning Materials -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-folder-open text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-2 py-1 rounded-full">Resources</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Learning Materials</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Upload and access course materials, documents, videos, and presentations.</p>
                        <a href="materials.php" class="inline-flex items-center text-green-600 dark:text-green-400 hover:text-green-800 dark:hover:text-green-300 font-medium text-sm">
                            <span>Browse Materials</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Quizzes & Tests -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-question-circle text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-purple-100 dark:bg-purple-900 text-purple-800 dark:text-purple-200 px-2 py-1 rounded-full">Assessment</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Quizzes & Tests</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Create and take online quizzes with automatic grading and plagiarism detection.</p>
                        <a href="quizzes.php" class="inline-flex items-center text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300 font-medium text-sm">
                            <span>View Assessments</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Assignment Submissions -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-upload text-orange-600 dark:text-orange-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-orange-100 dark:bg-orange-900 text-orange-800 dark:text-orange-200 px-2 py-1 rounded-full">Submit</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Assignment Submissions</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Submit assignments online with plagiarism checking and progress tracking.</p>
                        <a href="submissions.php" class="inline-flex items-center text-orange-600 dark:text-orange-400 hover:text-orange-800 dark:hover:text-orange-300 font-medium text-sm">
                            <span>Manage Submissions</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Discussion Boards -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-comments text-indigo-600 dark:text-indigo-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-indigo-100 dark:bg-indigo-900 text-indigo-800 dark:text-indigo-200 px-2 py-1 rounded-full">Forum</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Discussion Boards</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Participate in course discussions and collaborate with classmates and teachers.</p>
                        <a href="discussions.php" class="inline-flex items-center text-indigo-600 dark:text-indigo-400 hover:text-indigo-800 dark:hover:text-indigo-300 font-medium text-sm">
                            <span>Join Discussions</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </a>
                    </div>

                    <!-- Platform Integration -->
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700 hover:shadow-xl transition-all duration-300 group">
                        <div class="flex items-center justify-between mb-4">
                            <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center group-hover:scale-110 transition-transform duration-300">
                                <i class="fas fa-link text-red-600 dark:text-red-400 text-xl"></i>
                            </div>
                            <span class="text-xs bg-red-100 dark:bg-red-900 text-red-800 dark:text-red-200 px-2 py-1 rounded-full">Integration</span>
                        </div>
                        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">Platform Integration</h3>
                        <p class="text-gray-600 dark:text-gray-400 text-sm mb-4">Connect with Zoom, Google Meet, Teams, and other learning platforms.</p>
                        <button class="inline-flex items-center text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300 font-medium text-sm" data-action="show-integration-modal">
                            <span>View Integrations</span>
                            <i class="fas fa-arrow-right ml-2 group-hover:translate-x-1 transition-transform duration-300"></i>
                        </button>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700 mb-8">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center justify-between">
                            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Recent Activities</h2>
                            <button onclick="showActivitiesModal()" class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300 text-sm font-medium">
                                View All
                            </button>
                        </div>
                    </div>
                    <div class="p-6">
                        <?php if (!empty($recent_activities)): ?>
                        <div class="space-y-4">
                            <?php foreach (array_slice($recent_activities, 0, 5) as $activity): ?>
                            <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                                <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                    <?php if ($activity['type'] === 'quiz'): ?>
                                    <i class="fas fa-question-circle text-blue-600 dark:text-blue-400"></i>
                                    <?php else: ?>
                                    <i class="fas fa-file-alt text-blue-600 dark:text-blue-400"></i>
                                    <?php endif; ?>
                                </div>
                                <div class="flex-1">
                                    <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['title']); ?></h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">
                                        <?php if ($activity['type'] === 'quiz'): ?>
                                        Quiz by <?php echo htmlspecialchars($activity['teacher_name']); ?>
                                        <?php else: ?>
                                        Material uploaded by <?php echo htmlspecialchars($activity['uploader_name']); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                    <?php echo date('M j, Y', strtotime($activity['created_at'])); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8">
                            <i class="fas fa-clock text-gray-400 text-4xl mb-4"></i>
                            <p class="text-gray-600 dark:text-gray-400">No recent activities to display.</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Platform Features -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Platform Features</h2>
                    </div>
                    <div class="p-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Virtual Classroom Integration</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Seamless integration with Zoom, Google Meet, and Microsoft Teams</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Content Upload & Management</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Upload documents, videos, presentations, and links</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Online Assessments</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Create time-based quizzes with automatic scoring</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Assignment Tracking</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Track student submissions and progress</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Plagiarism Detection</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Integrated plagiarism checking for submissions</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-3">
                                <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                    <i class="fas fa-check text-green-600 dark:text-green-400"></i>
                                </div>
                                <div>
                                    <h4 class="font-medium text-gray-900 dark:text-white">Discussion Forums</h4>
                                    <p class="text-sm text-gray-600 dark:text-gray-400">Forum-style interactions on lessons and topics</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>

<!-- Integration Modal -->
<div id="integrationModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full" style="max-height: 85vh; overflow-y: auto;">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Platform Integrations</h3>
                <button data-action="hide-integration-modal" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                            <i class="fas fa-video text-blue-600 dark:text-blue-400"></i>
                        </div>
                        <h4 class="font-medium text-gray-900 dark:text-white">Zoom</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Host virtual classrooms with video conferencing</p>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                        <i class="fas fa-check mr-1"></i>
                        Connected
                    </span>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                    <div class="flex items-center space-x-3 mb-3">
                        <div class="w-8 h-8 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                            <i class="fab fa-google text-green-600 dark:text-green-400"></i>
                        </div>
                        <h4 class="font-medium text-gray-900 dark:text-white">Google Meet</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Integrate with Google Workspace for Education</p>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                        <i class="fas fa-check mr-1"></i>
                        Connected
                    </span>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fab fa-microsoft text-blue-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Microsoft Teams</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Collaborate with Microsoft 365 Education</p>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-yellow-100 dark:bg-yellow-900 text-yellow-800 dark:text-yellow-200">
                        <i class="fas fa-clock mr-1"></i>
                        Available
                    </span>
                </div>
                <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
                    <div class="flex items-center space-x-3 mb-3">
                        <i class="fas fa-shield-alt text-purple-600 text-2xl"></i>
                        <h4 class="font-medium text-gray-900 dark:text-white">Plagiarism Checker</h4>
                    </div>
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">Automated plagiarism detection for submissions</p>
                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200">
                        <i class="fas fa-check mr-1"></i>
                        Active
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activities Modal -->
<div id="activitiesModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-2xl w-full" style="max-height: 85vh; overflow-y: auto;">
        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <h3 class="text-xl font-semibold text-gray-900 dark:text-white">All Recent Activities</h3>
                <button onclick="hideActivitiesModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
        </div>
        <div class="p-6 space-y-4">
            <?php if (!empty($recent_activities)): ?>
                <?php foreach ($recent_activities as $activity): ?>
                <div class="flex items-center space-x-4 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                    <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                        <?php if ($activity['type'] === 'quiz'): ?>
                        <i class="fas fa-question-circle text-blue-600 dark:text-blue-400"></i>
                        <?php else: ?>
                        <i class="fas fa-file-alt text-blue-600 dark:text-blue-400"></i>
                        <?php endif; ?>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-medium text-gray-900 dark:text-white"><?php echo htmlspecialchars($activity['title']); ?></h4>
                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            <?php if ($activity['type'] === 'quiz'): ?>
                            Quiz by <?php echo htmlspecialchars($activity['teacher_name']); ?>
                            <?php else: ?>
                            Material uploaded by <?php echo htmlspecialchars($activity['uploader_name']); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">
                        <?php echo date('M j, Y', strtotime($activity['created_at'])); ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-clock text-gray-400 text-4xl mb-4"></i>
                    <p class="text-gray-600 dark:text-gray-400">No activities to display.</p>
                </div>
            <?php endif; ?>
        </div>
        <div class="p-6 border-t border-gray-200 dark:border-gray-700 flex justify-end bg-gray-50 dark:bg-gray-900 rounded-b-xl">
            <button onclick="hideActivitiesModal()" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-4 py-2 rounded-lg transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<script>
// Inline JavaScript for modal functionality
document.addEventListener('DOMContentLoaded', function() {
    // Modal functions for integration modal
    function showModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
    }

    function hideModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            document.body.style.overflow = '';
        }
    }

    // Integration modal handlers
    document.querySelectorAll('[data-action="show-integration-modal"]').forEach(button => {
        button.addEventListener('click', () => showModal('integrationModal'));
    });

    document.querySelectorAll('[data-action="hide-integration-modal"]').forEach(button => {
        button.addEventListener('click', () => hideModal('integrationModal'));
    });

    // Close modal when clicking outside
    document.getElementById('integrationModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            hideModal('integrationModal');
        }
    });

    // Activities modal handlers
    window.showActivitiesModal = function() {
        showModal('activitiesModal');
    };
    window.hideActivitiesModal = function() {
        hideModal('activitiesModal');
    };

    document.getElementById('activitiesModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            hideModal('activitiesModal');
        }
    });
});
</script>

<script src="/assets/js/online-learning.js"></script>
