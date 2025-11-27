<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Get promotion history
$history_sql = "SELECT 
    sp.*, 
    u.name as student_name,
    prof.student_id as student_profile_id,
    fy.year_name as from_year,
    ty.year_name as to_year,
    fc.name as from_class, fc.grade_level as from_grade,
    tc.name as to_class, tc.grade_level as to_grade,
    creator.name as created_by_name
FROM student_promotions sp
JOIN users u ON sp.student_id = u.id
JOIN student_profiles prof ON u.id = prof.user_id
JOIN academic_years fy ON sp.from_academic_year_id = fy.id
JOIN academic_years ty ON sp.to_academic_year_id = ty.id
JOIN classes fc ON sp.from_class_id = fc.id
JOIN classes tc ON sp.to_class_id = tc.id
JOIN users creator ON sp.created_by = creator.id
ORDER BY sp.promotion_date DESC, sp.created_at DESC";

$history = $db->query($history_sql)->fetchAll(PDO::FETCH_ASSOC);

$title = "Promotion History";
include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                <!-- Header Section -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-purple-600 via-pink-600 to-red-600 rounded-xl p-4 text-white shadow-lg">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Promotion History</h1>
                                <p class="text-purple-100 text-lg">View all student promotion records</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-history text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../../dashboard.php" class="hover:text-blue-600 dark:hover:text-blue-400">Dashboard</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="../" class="hover:text-blue-600 dark:hover:text-blue-400">Academic</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Promotions</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">History</span>
                </div>

                <!-- Actions -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <a href="index.php" 
                            class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200">
                            <i class="fas fa-plus mr-2"></i>New Promotions
                        </a>
                    </div>
                    <div class="text-sm text-gray-600 dark:text-gray-400">
                        Total Records: <?php echo count($history); ?>
                    </div>
                </div>

                <!-- Promotion History Table -->
                <?php if (!empty($history)): ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-xl font-semibold text-gray-900 dark:text-white">Promotion Records</h3>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Complete history of student promotions</p>
                    </div>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-700">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Student</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">From</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">To</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Remarks</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Created By</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                <?php foreach ($history as $record): ?>
                                <tr>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="w-10 h-10 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center">
                                                <i class="fas fa-user text-blue-600 dark:text-blue-400"></i>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900 dark:text-white">
                                                    <?php echo htmlspecialchars($record['student_name']); ?>
                                                </div>
                                                <div class="text-sm text-gray-500 dark:text-gray-400">
                                                    ID: <?php echo htmlspecialchars($record['student_profile_id']); ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['from_year']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            Grade <?php echo htmlspecialchars($record['from_grade']); ?> - <?php echo htmlspecialchars($record['from_class']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-900 dark:text-white">
                                            <?php echo htmlspecialchars($record['to_year']); ?>
                                        </div>
                                        <div class="text-sm text-gray-500 dark:text-gray-400">
                                            Grade <?php echo htmlspecialchars($record['to_grade']); ?> - <?php echo htmlspecialchars($record['to_class']); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full 
                                            <?php 
                                            switch($record['promotion_status']) {
                                                case 'promoted': echo 'bg-green-100 text-green-800'; break;
                                                case 'repeated': echo 'bg-yellow-100 text-yellow-800'; break;
                                                case 'transferred': echo 'bg-blue-100 text-blue-800'; break;
                                                case 'graduated': echo 'bg-purple-100 text-purple-800'; break;
                                                default: echo 'bg-gray-100 text-gray-800';
                                            }
                                            ?>">
                                            <?php echo ucfirst($record['promotion_status']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo date('M j, Y', strtotime($record['promotion_date'])); ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-sm text-gray-900 dark:text-white max-w-xs truncate">
                                            <?php echo htmlspecialchars($record['remarks'] ?: 'No remarks'); ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                        <?php echo htmlspecialchars($record['created_by_name']); ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php else: ?>
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-8 text-center">
                        <i class="fas fa-history text-4xl text-gray-400 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">No Promotion History</h3>
                        <p class="text-gray-600 dark:text-gray-400">No student promotions have been recorded yet.</p>
                        <a href="index.php" 
                            class="inline-flex items-center px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 mt-4">
                            <i class="fas fa-plus mr-2"></i>Start Promotions
                        </a>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>

        <!-- Footer with proper margin for sidebar -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
