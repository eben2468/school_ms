<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher', 'student'])) {
    header("Location: ../index.php");
    exit();
}

$title = "Assignment Submissions";
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
                            <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Assignment Submissions</h1>
                            <p class="text-gray-600 dark:text-gray-400 mt-2">Submit assignments online with plagiarism checking and progress tracking</p>
                        </div>
                        <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors duration-200">
                            <i class="fas fa-arrow-left mr-2"></i>Back
                        </a>
                    </div>
                </div>

                <!-- Coming Soon Message -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-12 text-center">
                        <div class="w-24 h-24 bg-orange-100 dark:bg-orange-900 rounded-full flex items-center justify-center mx-auto mb-6">
                            <i class="fas fa-upload text-orange-600 dark:text-orange-400 text-4xl"></i>
                        </div>
                        <h2 class="text-2xl font-semibold text-gray-900 dark:text-white mb-4">Assignment Submissions Coming Soon</h2>
                        <p class="text-gray-600 dark:text-gray-400 mb-8 max-w-2xl mx-auto">
                            We're developing an advanced assignment submission system with comprehensive features:
                        </p>
                        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
                            <div class="text-center">
                                <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-file-upload text-blue-600 dark:text-blue-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">File Submissions</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Upload documents, images, and multimedia files</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-red-100 dark:bg-red-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-shield-alt text-red-600 dark:text-red-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Plagiarism Detection</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Automatic plagiarism checking for all submissions</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-green-100 dark:bg-green-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-clock text-green-600 dark:text-green-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Deadline Tracking</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Automatic reminders and deadline management</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-purple-100 dark:bg-purple-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-comments text-purple-600 dark:text-purple-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Feedback System</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Detailed feedback and grading from teachers</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-yellow-100 dark:bg-yellow-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-history text-yellow-600 dark:text-yellow-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Version Control</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Track submission history and revisions</p>
                            </div>
                            <div class="text-center">
                                <div class="w-12 h-12 bg-indigo-100 dark:bg-indigo-900 rounded-lg flex items-center justify-center mx-auto mb-3">
                                    <i class="fas fa-chart-line text-indigo-600 dark:text-indigo-400"></i>
                                </div>
                                <h4 class="font-medium text-gray-900 dark:text-white">Progress Analytics</h4>
                                <p class="text-sm text-gray-600 dark:text-gray-400">Track student progress and performance</p>
                            </div>
                        </div>
                        <div class="bg-orange-50 dark:bg-orange-900 rounded-lg p-6">
                            <h3 class="text-lg font-semibold text-orange-900 dark:text-orange-100 mb-2">Coming Soon</h3>
                            <p class="text-orange-800 dark:text-orange-200 mb-4">This feature is currently under development and will be available soon!</p>
                            <button class="px-6 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 transition-colors duration-200">
                                <i class="fas fa-bell mr-2"></i>Get Notified
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
