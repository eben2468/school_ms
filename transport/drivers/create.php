<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'transport_officer'])) {
    header("Location: ../../index.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $phone = filter_input(INPUT_POST, 'phone', FILTER_SANITIZE_STRING);
    $license_number = filter_input(INPUT_POST, 'license_number', FILTER_SANITIZE_STRING);
    $license_expiry = filter_input(INPUT_POST, 'license_expiry', FILTER_SANITIZE_STRING);
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING);
    $notes = filter_input(INPUT_POST, 'notes', FILTER_SANITIZE_STRING);
    
    if (empty($name) || empty($phone) || empty($license_number) || empty($license_expiry)) {
        $error = "Name, phone, license number, and license expiry are required.";
    } else {
        try {
            $query = "INSERT INTO transport_drivers (name, phone, license_number, license_expiry, status, notes) 
                      VALUES (:name, :phone, :license_number, :license_expiry, :status, :notes)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':license_number', $license_number);
            $stmt->bindParam(':license_expiry', $license_expiry);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':notes', $notes);
            
            if ($stmt->execute()) {
                $success = "Driver added successfully.";
                // Clear form data
                $name = $phone = $license_number = $license_expiry = $notes = '';
                $status = 'active';
            } else {
                $error = "Failed to add driver. Please try again.";
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}
?>

<?php
$title = "Add New Driver";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Transport', 'url' => '../index.php'],
    ['title' => 'Drivers', 'url' => '../index.php'], // Links back to transport dashboard list
    ['title' => 'Add Driver']
];
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="sidebar-spacer lg:block hidden" :class="{ 'collapsed': $store.sidebar.collapsed }"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300 min-w-0">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
            <div class="flex justify-between items-center mb-6">
                <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">Add New Driver</h1>
                <div class="flex space-x-3">
                    <a href="../index.php" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Transport
                    </a>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($success); ?>
            </div>
            <?php endif; ?>

            <?php if (isset($error)): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                    <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Driver Profile Information</h2>
                    <p class="text-gray-600 dark:text-gray-400 text-sm mt-1">Enter details of the new transport driver.</p>
                </div>

                <form action="" method="POST" class="p-6 space-y-6">
                    <!-- Driver Basic Info -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Driver Full Name *</label>
                            <input type="text" id="name" name="name" required
                                value="<?php echo htmlspecialchars($name ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., John Doe">
                        </div>

                        <div>
                            <label for="phone" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" required
                                value="<?php echo htmlspecialchars($phone ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., +1234567890">
                        </div>
                    </div>

                    <!-- License Details -->
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label for="license_number" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">License Number *</label>
                            <input type="text" id="license_number" name="license_number" required
                                value="<?php echo htmlspecialchars($license_number ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                placeholder="e.g., DL-987654321">
                        </div>

                        <div>
                            <label for="license_expiry" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">License Expiry Date *</label>
                            <input type="date" id="license_expiry" name="license_expiry" required
                                value="<?php echo htmlspecialchars($license_expiry ?? ''); ?>"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>

                        <div>
                            <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Status</label>
                            <select id="status" name="status"
                                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                                <option value="active" <?php echo (!isset($status) || $status === 'active') ? 'selected' : ''; ?>>Active</option>
                                <option value="inactive" <?php echo (isset($status) && $status === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label for="notes" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Additional Notes / History</label>
                        <textarea id="notes" name="notes" rows="4"
                            class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                            placeholder="Any notes about previous experience, accident history, medical issues, etc."><?php echo htmlspecialchars($notes ?? ''); ?></textarea>
                    </div>

                    <!-- Submit Button -->
                    <div class="flex justify-end pt-6 border-t border-gray-200 dark:border-gray-700">
                        <div class="flex flex-wrap items-center gap-3 no-stack">
                            <a href="../index.php"
                               class="inline-flex items-center whitespace-nowrap px-6 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-750 font-medium">
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex items-center whitespace-nowrap bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg font-medium">
                                <i class="fas fa-plus mr-2"></i>Add Driver
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Management Tips -->
            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-6 mt-6">
                <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-300 mb-3">
                    <i class="fas fa-id-card-alt mr-2"></i>Driver Compliance Guidelines
                </h3>
                <ul class="text-blue-800 dark:text-blue-400 space-y-2 text-sm">
                    <li><i class="fas fa-check mr-2"></i>Ensure the license expiry date is set correctly for automatic reminders</li>
                    <li><i class="fas fa-check mr-2"></i>Verify telephone number format for SMS/emergency contacts</li>
                    <li><i class="fas fa-check mr-2"></i>Conduct physical document verification before setting status to 'Active'</li>
                </ul>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>
