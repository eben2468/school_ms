<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

$title = "Quizzes & Tests";
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="mb-8">
                    <div class="flex items-center justify-between">
                        <div>
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Quizzes & Tests</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Create and take online assessments with automatic grading</p>
                        </div>
                        <div class="flex space-x-3">
                            <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>Back
                            </a>
                            <?php if (in_array($_SESSION['role'], ['super_admin', 'school_admin', 'teacher'])): ?>
                            <button onclick="showCreateModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                                <i class="fas fa-plus mr-2"></i>Create Quiz
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Coming Soon Message -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-12 text-center">
                        <div class="w-24 h-24 bg-blue-100 dark:bg-blue-900 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-question-circle text-blue-600 dark:text-blue-400 text-4xl"></i>
                        </div>
                        <h2 class="text-2xl font-semibold text-gray-900 dark:text-white mb-4">Quizzes & Tests Coming Soon</h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-2xl mx-auto">
                            We're working on an advanced quiz and testing system with features like:
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                            <div class="text-center">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-clock text-green-600 dark:text-green-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Timed Assessments</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Set time limits for quizzes and tests</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-random text-purple-600 dark:text-purple-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Question Randomization</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Randomize questions for each attempt</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-orange-100 dark:bg-orange-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-chart-bar text-orange-600 dark:text-orange-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Automatic Grading</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Instant results and detailed analytics</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-shield-alt text-red-600 dark:text-red-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Anti-Cheating</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Proctoring and plagiarism detection</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-list-alt text-indigo-600 dark:text-indigo-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Multiple Question Types</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">MCQ, True/False, Short Answer, Essay</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-cyan-100 dark:bg-cyan-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-mobile-alt text-cyan-600 dark:text-cyan-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Mobile Friendly</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Take quizzes on any device</p>
                            </div>
                        </div>
                        <div class="bg-blue-50 dark:bg-blue-900 rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-100 mb-2">Stay Updated</h3>
                            <p class="text-blue-800 dark:text-blue-200 mb-4">This feature will be available in the next update. Check back soon!</p>
                            <button class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors duration-200">
                                <i class="fas fa-bell mr-2"></i>Notify Me When Ready
                            </button>
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
