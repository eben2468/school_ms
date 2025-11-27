<?php
session_start();
require_once '../config/database.php';

// Check if user is logged in and is a parent
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'parent') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['name'];

// Get parent's children information
$children_sql = "SELECT 
    u.id, u.name, u.email, u.status,
    sp.student_id, sp.date_of_birth, sp.gender, sp.phone, sp.address,
    sp.admission_date, sp.blood_group, sp.medical_conditions,
    c.name as class_name, c.grade_level,
    ps.relationship
FROM parent_students ps
JOIN users u ON ps.student_id = u.id
JOIN student_profiles sp ON u.id = sp.user_id
LEFT JOIN student_classes sc ON u.id = sc.student_id AND sc.status = 'active'
LEFT JOIN classes c ON sc.class_id = c.id
WHERE ps.parent_id = :parent_id AND u.status = 'active'
ORDER BY u.name";

$stmt = $db->prepare($children_sql);
$stmt->bindParam(':parent_id', $user_id);
$stmt->execute();
$children = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get academic context
$academic_context = $database->getCurrentAcademicContext();

$title = "Parent Dashboard";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen">
    <!-- Sidebar Space -->
    <div class="w-72 flex-shrink-0 lg:block hidden"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full" style="margin-top: 20px;">
                <!-- Welcome Section -->
                <div class="mb-8">
                    <div class="page-header-gradient rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
                                <p class="text-blue-100 text-lg">Monitor your child's academic progress and school activities</p>
                                <div class="mt-4 flex items-center space-x-4 text-sm text-blue-100">
                                    <div class="flex items-center">
                                        <i class="fas fa-calendar-alt mr-2"></i>
                                        <?php echo date('l, F j, Y'); ?>
                                    </div>
                                    <div class="flex items-center">
                                        <i class="fas fa-graduation-cap mr-2"></i>
                                        <?php echo htmlspecialchars($academic_context['year_name']); ?> - <?php echo htmlspecialchars($academic_context['term_name']); ?>
                                    </div>
                                </div>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-users text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($children)): ?>
                <!-- Quick Overview -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-users text-blue-600 dark:text-blue-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">My Children</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo count($children); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-graduation-cap text-green-600 dark:text-green-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Active Students</p>
                                <p class="text-2xl font-bold text-gray-900 dark:text-white"><?php echo count(array_filter($children, function($c) { return !empty($c['class_name']); })); ?></p>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-6 border border-gray-200 dark:border-gray-700">
                        <div class="flex items-center">
                            <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center">
                                <i class="fas fa-calendar-alt text-purple-600 dark:text-purple-400 text-xl"></i>
                            </div>
                            <div class="ml-4">
                                <p class="text-sm font-medium text-gray-600 dark:text-gray-400">Academic Year</p>
                                <p class="text-lg font-bold text-gray-900 dark:text-white"><?php echo htmlspecialchars($academic_context['year_name']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (empty($children)): ?>
                <!-- No Children Found -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-user-friends text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Children Found</h3>
                        <p class="text-gray-600 dark:text-gray-400 mb-4">
                            No children are currently linked to your parent account.
                        </p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Please contact the school administration to link your child's account.
                        </p>
                    </div>
                </div>
                <?php else: ?>
                <!-- Children Information -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <?php foreach ($children as $child): ?>
                    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                        <!-- Child Header -->
                        <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center">
                                    <div class="w-16 h-16 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                        <i class="fas fa-user-graduate text-blue-600 dark:text-blue-400 text-2xl"></i>
                                    </div>
                                    <div class="ml-4">
                                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($child['name']); ?>
                                        </h3>
                                        <p class="text-gray-600 dark:text-gray-400">
                                            Student ID: <?php echo htmlspecialchars($child['student_id']); ?>
                                        </p>
                                        <p class="text-sm text-blue-600 dark:text-blue-400">
                                            <?php echo ucfirst($child['relationship']); ?>
                                        </p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <?php if ($child['class_name']): ?>
                                    <div class="bg-green-100 dark:bg-green-900 text-green-800 dark:text-green-200 px-3 py-1 rounded-full text-sm font-medium">
                                        Grade <?php echo htmlspecialchars($child['grade_level']); ?> - <?php echo htmlspecialchars($child['class_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Child Details -->
                        <div class="p-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Date of Birth</label>
                                    <p class="text-gray-900 dark:text-white">
                                        <?php echo $child['date_of_birth'] ? date('F j, Y', strtotime($child['date_of_birth'])) : 'Not provided'; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Gender</label>
                                    <p class="text-gray-900 dark:text-white">
                                        <?php echo $child['gender'] ? ucfirst($child['gender']) : 'Not provided'; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Blood Group</label>
                                    <p class="text-gray-900 dark:text-white">
                                        <?php echo $child['blood_group'] ?: 'Not provided'; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Admission Date</label>
                                    <p class="text-gray-900 dark:text-white">
                                        <?php echo $child['admission_date'] ? date('F j, Y', strtotime($child['admission_date'])) : 'Not provided'; ?>
                                    </p>
                                </div>
                            </div>

                            <?php if ($child['medical_conditions']): ?>
                            <div class="mt-4">
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Medical Conditions</label>
                                <p class="text-gray-900 dark:text-white bg-yellow-50 dark:bg-yellow-900/20 p-3 rounded-lg">
                                    <?php echo htmlspecialchars($child['medical_conditions']); ?>
                                </p>
                            </div>
                            <?php endif; ?>

                            <!-- Quick Actions -->
                            <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-3">
                                <a href="child_academic.php?student_id=<?php echo $child['id']; ?>"
                                   class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg text-sm transition-colors duration-200 text-center">
                                    <i class="fas fa-chart-line text-lg mb-1 block"></i>
                                    <span class="font-medium">Academic Progress</span>
                                </a>
                                <a href="child_attendance.php?student_id=<?php echo $child['id']; ?>"
                                   class="bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg text-sm transition-colors duration-200 text-center">
                                    <i class="fas fa-calendar-check text-lg mb-1 block"></i>
                                    <span class="font-medium">Attendance</span>
                                </a>
                                <a href="child_assignments.php?student_id=<?php echo $child['id']; ?>"
                                   class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-3 rounded-lg text-sm transition-colors duration-200 text-center">
                                    <i class="fas fa-tasks text-lg mb-1 block"></i>
                                    <span class="font-medium">Assignments</span>
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
