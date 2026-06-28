<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'hr'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

$staff_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$staff_id) {
    header("Location: index.php");
    exit();
}

// Fetch staff details
$stmt = $db->prepare("SELECT id, name, role, email FROM users WHERE id = :id AND role NOT IN ('student', 'parent')");
$stmt->execute(['id' => $staff_id]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['confirm']) && $_POST['confirm'] === 'yes') {
        try {
            $update_stmt = $db->prepare("UPDATE users SET status = 'inactive' WHERE id = :id");
            $update_stmt->execute(['id' => $staff_id]);
            
            $_SESSION['success_message'] = "Staff member " . htmlspecialchars($staff['name']) . " has been deactivated successfully.";
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $error = "Error deactivating staff member.";
        }
    }
}

$title = "Deactivate Staff: " . htmlspecialchars($staff['name']);
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="max-w-3xl mx-auto">
                <!-- Page Header -->
                <div class="mb-8">
                    <div class="bg-gradient-to-r from-red-600 to-red-800 rounded-2xl p-8 text-white shadow-xl">
                        <div class="flex items-center justify-between">
                            <div>
                                <h1 class="text-3xl font-bold mb-2">Deactivate Staff Member</h1>
                                <p class="text-red-100 text-lg">Confirm deactivation of staff account</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-24 h-24 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-exclamation-triangle text-5xl text-white/90"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Confirmation Card -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg p-8 border border-red-100 dark:border-red-900">
                    <?php if (isset($error)): ?>
                        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6 flex items-center gap-3">
                            <i class="fas fa-exclamation-circle text-xl"></i>
                            <p><?= htmlspecialchars($error) ?></p>
                        </div>
                    <?php endif; ?>

                    <div class="text-center mb-8">
                        <div class="w-20 h-20 bg-red-100 text-red-600 rounded-full flex items-center justify-center mx-auto mb-4 text-3xl">
                            <i class="fas fa-user-times"></i>
                        </div>
                        <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">Are you sure?</h2>
                        <p class="text-gray-600 dark:text-gray-400">
                            You are about to deactivate the account for <strong class="text-gray-900 dark:text-white"><?= htmlspecialchars($staff['name']) ?></strong> (<?= htmlspecialchars(formatRoleName($staff['role'])) ?>).
                        </p>
                        <p class="text-gray-500 dark:text-gray-500 mt-4 text-sm bg-gray-50 dark:bg-gray-900/50 p-4 rounded-lg inline-block text-left">
                            <i class="fas fa-info-circle mr-2"></i> This is a soft delete. The user will no longer be able to log in, but their historical data (attendance, evaluations, etc.) will be preserved.
                        </p>
                    </div>

                    <form method="POST" class="flex flex-col sm:flex-row items-center justify-center gap-4 mt-8">
                        <input type="hidden" name="confirm" value="yes">
                        <a href="view.php?id=<?= $staff_id ?>" class="w-full sm:w-auto px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600 font-semibold rounded-xl transition-colors flex items-center justify-center gap-2">
                            <i class="fas fa-arrow-left"></i> Cancel & Go Back
                        </a>
                        <button type="submit" class="w-full sm:w-auto px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-semibold rounded-xl transition-colors flex items-center justify-center gap-2 shadow-lg shadow-red-600/30">
                            <i class="fas fa-user-times"></i> Yes, Deactivate Account
                        </button>
                    </form>
                </div>
            </div>
        </main>

        <div class="lg:ml-0">
            <?php include '../includes/footer.php'; ?>
        </div>
    </div>
</div>
