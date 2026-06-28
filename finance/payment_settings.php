<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
require_once 'includes/finance_functions.php';

$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];
$user_id = $_SESSION['user_id'];

$success = '';
$error = '';

// Retrieve settings from settings helper
if (!function_exists('getSchoolSetting')) {
    require_once dirname(__DIR__) . '/includes/settings_helper.php';
}

$settings = getFinanceSettings($db);
?>

<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 56px;">
    <!-- Sidebar Space -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <!-- Header -->
                <div class="flex justify-between items-center mb-8">
                    <div>
                        <h1 class="text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Finance Settings</h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Configure default payment values and currency behaviors</p>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl border border-gray-100 dark:border-gray-700 p-6 md:p-8 space-y-6">
                    <div class="flex items-center gap-4 border-b border-gray-100 dark:border-gray-700/50 pb-4 mb-4">
                        <div class="w-12 h-12 bg-blue-100 dark:bg-blue-900/30 rounded-2xl flex items-center justify-center text-blue-600 dark:text-blue-400">
                            <i class="fas fa-cog text-xl"></i>
                        </div>
                        <div>
                            <h2 class="text-xl font-bold text-gray-800 dark:text-white">Active Configurations</h2>
                            <p class="text-xs text-gray-400">Global system configuration metrics currently running</p>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-sm">
                        <div class="p-4 bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 rounded-xl space-y-2">
                            <span class="text-xs text-gray-400 font-bold block uppercase tracking-wider">Currency Configured</span>
                            <span class="font-extrabold text-lg text-gray-800 dark:text-white"><?php echo htmlspecialchars($settings['currency'] ?? 'GHS'); ?> (<?php echo htmlspecialchars($settings['currency_symbol'] ?? '₵'); ?>)</span>
                        </div>
                        
                        <div class="p-4 bg-gray-50 dark:bg-gray-900/40 border border-gray-200 dark:border-gray-700 rounded-xl space-y-2">
                            <span class="text-xs text-gray-400 font-bold block uppercase tracking-wider">Payment Gateway Provider</span>
                            <span class="font-extrabold text-lg text-gray-800 dark:text-white capitalize"><?php echo htmlspecialchars($settings['payment_gateway'] ?? 'manual'); ?></span>
                        </div>
                    </div>

                    <div class="bg-amber-50 border-l-4 border-amber-500 text-amber-800 p-4 rounded-xl dark:bg-amber-950/20 dark:text-amber-300 flex items-start gap-3 mt-8">
                        <i class="fas fa-info-circle text-lg mt-0.5 text-amber-500"></i>
                        <div>
                            <span class="font-bold block">Integrate Payments Gateways</span>
                            <span class="text-xs">API Credentials, Public/Secret Keys, and direct gateway integration logic are set under the main **Settings** tab. Please head to settings page for custom credentials.</span>
                            <a href="../settings/school.php?tab=payment" class="inline-flex items-center text-xs font-bold text-amber-600 dark:text-amber-400 hover:underline mt-2">
                                Go to settings panel <i class="fas fa-chevron-right ml-1"></i>
                            </a>
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
