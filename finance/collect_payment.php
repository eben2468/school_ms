<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal', 'teacher'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$title = "Collect Payment";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'], ['title' => 'Finance', 'url' => 'index.php'], ['title' => 'Collect Payment']
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 20px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Collect Payment</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
                </div>

                <!-- Page Content -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <div class="text-center py-12">
                            <i class="fas fa-credit-card text-gray-400 dark:text-gray-500 text-6xl mb-4"></i>
                            <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Collect Payment</h3>
                            <p class="text-gray-500 dark:text-gray-400 mb-4">Record fee payments from students</p>
                            <div class="space-y-4">
                                <button class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-plus mr-2"></i>Create New
                                </button>
                                <button class="bg-green-600 hover:bg-green-700 text-white px-6 py-2 rounded-lg ml-4">
                                    <i class="fas fa-list mr-2"></i>View All
                                </button>
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