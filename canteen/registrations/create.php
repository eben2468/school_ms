<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'canteen_manager'])) {
    header("Location: ../../auth/login.php");
    exit();
}

require_once '../../config/database.php';
$database = new Database();
$db = $database->getConnection();

$user_role = $_SESSION['role'];

// Fetch all active students for dropdown selector
$students_query = "SELECT id, name, student_id FROM users WHERE role = 'student' AND status = 'active' ORDER BY name ASC";
$students_stmt = $db->query($students_query);
$students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $student_id = filter_input(INPUT_POST, 'student_id', FILTER_VALIDATE_INT);
    $registration_type = isset($_POST['registration_type']) ? trim($_POST['registration_type']) : '';
    $start_date = isset($_POST['start_date']) ? trim($_POST['start_date']) : '';
    $end_date = isset($_POST['end_date']) ? trim($_POST['end_date']) : '';
    $amount_paid = filter_input(INPUT_POST, 'amount_paid', FILTER_VALIDATE_FLOAT);
    $status = isset($_POST['status']) ? trim($_POST['status']) : 'active';

    if ($student_id && $registration_type && $start_date && $end_date && $amount_paid !== false) {
        try {
            $query = "INSERT INTO canteen_registrations (student_id, registration_type, start_date, end_date, amount_paid, status, created_at) 
                      VALUES (:student_id, :registration_type, :start_date, :end_date, :amount_paid, :status, NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':student_id', $student_id, PDO::PARAM_INT);
            $stmt->bindParam(':registration_type', $registration_type);
            $stmt->bindParam(':start_date', $start_date);
            $stmt->bindParam(':end_date', $end_date);
            $stmt->bindParam(':amount_paid', $amount_paid);
            $stmt->bindParam(':status', $status);

            if ($stmt->execute()) {
                $success_message = "Meal plan registration created successfully!";
            } else {
                $error_message = "Error creating registration.";
            }
        } catch (PDOException $e) {
            $error_message = "Database error: " . $e->getMessage();
        }
    } else {
        $error_message = "Please fill in all required fields with valid data.";
    }
}

$title = "Create Registration";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../../dashboard.php'],
    ['title' => 'Canteen', 'url' => '../index.php'],
    ['title' => 'Registrations', 'url' => 'index.php'],
    ['title' => 'New Registration']
];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen w-full overflow-x-hidden" style="margin-top: 80px;">
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
                                <h1 class="text-3xl font-bold mb-2">Register Student for Meal Plan</h1>
                                <p class="text-green-100 text-lg">Create a new subscription for the canteen meal plan</p>
                            </div>
                            <div class="hidden md:block">
                                <div class="w-32 h-32 bg-white/10 rounded-full flex items-center justify-center backdrop-blur-sm">
                                    <i class="fas fa-user-plus text-6xl text-white/80"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400 mb-6">
                    <a href="../index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Canteen</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <a href="index.php" class="hover:text-blue-600 dark:hover:text-blue-400">Registrations</a>
                    <i class="fas fa-chevron-right text-xs"></i>
                    <span class="text-gray-900 dark:text-white font-medium">New Registration</span>
                </div>

                <!-- Success/Error Messages -->
                <?php if (isset($success_message)): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-check-circle mr-2"></i>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (isset($error_message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
                    <div class="flex items-center">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-lg border border-gray-200 dark:border-gray-700">
                    <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                        <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Registration Details</h2>
                        <p class="text-gray-600 dark:text-gray-400 mt-1">Select a student and specify the plan details</p>
                    </div>

                    <form method="POST" class="p-6 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Student -->
                            <div>
                                <label for="student_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Student <span class="text-red-500">*</span>
                                </label>
                                <select id="student_id" name="student_id" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select a student</option>
                                    <?php foreach ($students as $student): ?>
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student['name']); ?> (ID: <?php echo htmlspecialchars($student['student_id'] ?? 'N/A'); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Registration Type -->
                            <div>
                                <label for="registration_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Registration Type <span class="text-red-500">*</span>
                                </label>
                                <select id="registration_type" name="registration_type" required onchange="calculateDates()"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="">Select plan type</option>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="monthly">Monthly</option>
                                    <option value="term">Term-based</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Start Date -->
                            <div>
                                <label for="start_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Start Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="start_date" name="start_date" required
                                    value="<?php echo date('Y-m-d'); ?>" onchange="calculateDates()"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- End Date -->
                            <div>
                                <label for="end_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    End Date <span class="text-red-500">*</span>
                                </label>
                                <input type="date" id="end_date" name="end_date" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Amount Paid -->
                            <div>
                                <label for="amount_paid" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Amount Paid (₵) <span class="text-red-500">*</span>
                                </label>
                                <input type="number" id="amount_paid" name="amount_paid" step="0.01" min="0" required
                                    placeholder="0.00"
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                            </div>

                            <!-- Status -->
                            <div>
                                <label for="status" class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                                    Initial Status <span class="text-red-500">*</span>
                                </label>
                                <select id="status" name="status" required
                                    class="w-full px-4 py-3 border border-gray-300 dark:border-gray-600 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 dark:bg-gray-700 dark:text-white">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                </select>
                            </div>
                        </div>

                        <!-- Form Actions -->
                        <div class="flex items-center justify-between pt-6 border-t border-gray-200 dark:border-gray-700">
                            <a href="index.php" 
                                class="inline-flex items-center px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors duration-200">
                                <i class="fas fa-arrow-left mr-2"></i>
                                Cancel
                            </a>
                            <button type="submit"
                                class="inline-flex items-center px-6 py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium rounded-lg transition-colors duration-200 shadow-lg hover:shadow-xl">
                                <i class="fas fa-save mr-2"></i>
                                Create Registration
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </main>

        <!-- Footer -->
        <div class="lg:ml-0">
            <?php include '../../includes/footer.php'; ?>
        </div>
    </div>
</div>

<script>
function calculateDates() {
    const type = document.getElementById('registration_type').value;
    const startInput = document.getElementById('start_date').value;
    if (!startInput || !type) return;

    const startDate = new Date(startInput);
    let endDate = new Date(startInput);
    let amount = 0;

    switch(type) {
        case 'daily':
            endDate.setDate(startDate.getDate());
            amount = 15; // standard daily plan price
            break;
        case 'weekly':
            endDate.setDate(startDate.getDate() + 6);
            amount = 75; // standard weekly plan price
            break;
        case 'monthly':
            endDate.setDate(startDate.getDate() + 29);
            amount = 300; // standard monthly plan price
            break;
        case 'term':
            endDate.setDate(startDate.getDate() + 90);
            amount = 800; // standard term plan price
            break;
    }

    // Format to yyyy-mm-dd
    const year = endDate.getFullYear();
    const month = String(endDate.getMonth() + 1).padStart(2, '0');
    const day = String(endDate.getDate()).padStart(2, '0');
    document.getElementById('end_date').value = `${year}-${month}-${day}`;
    
    // Auto populate price hint if empty
    const amountField = document.getElementById('amount_paid');
    if (amountField.value === '' || amountField.value == 0) {
        amountField.value = amount;
    }
}
</script>
