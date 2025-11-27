<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['super_admin', 'school_admin', 'principal'])) {
    header("Location: ../index.php");
    exit();
}

require_once '../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING);
    $class_id = filter_input(INPUT_POST, 'class_id', FILTER_SANITIZE_NUMBER_INT);
    $academic_year = filter_input(INPUT_POST, 'academic_year', FILTER_SANITIZE_STRING);
    $fee_type = filter_input(INPUT_POST, 'fee_type', FILTER_SANITIZE_STRING);
    $amount = filter_input(INPUT_POST, 'amount', FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);
    $due_date = filter_input(INPUT_POST, 'due_date', FILTER_SANITIZE_STRING);
    $description = filter_input(INPUT_POST, 'description', FILTER_SANITIZE_STRING);

    if ($name && $fee_type && $amount !== false && $academic_year) {
        try {
            $query = "INSERT INTO fee_structures (name, class_id, academic_year, fee_type, amount, due_date, description, status, created_at)
                     VALUES (:name, :class_id, :academic_year, :fee_type, :amount, :due_date, :description, 'active', NOW())";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':class_id', $class_id);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':fee_type', $fee_type);
            $stmt->bindParam(':amount', $amount);
            $stmt->bindParam(':due_date', $due_date);
            $stmt->bindParam(':description', $description);
            $stmt->execute();

            $success = "Fee structure created successfully!";
        } catch (PDOException $e) {
            $error = "Error creating fee structure: " . $e->getMessage();
        }
    } else {
        $error = "Please fill in all required fields.";
    }
}

// Get classes for dropdown
$classes_query = "SELECT id, name FROM classes ORDER BY name";
$classes_stmt = $db->query($classes_query);
$classes = $classes_stmt->fetchAll(PDO::FETCH_ASSOC);

$title = "Create Fee Structure";
$breadcrumbs = [
    ['title' => 'Dashboard', 'url' => '../dashboard.php'],
    ['title' => 'Finance', 'url' => 'index.php'],
    ['title' => 'Create Fee Structure']
];

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Main Layout Container -->
<div class="flex bg-gray-50 dark:bg-gray-900 min-h-screen" style="margin-top: 64px;">
    <!-- Sidebar Space (Fixed positioning handled in sidebar.php) -->
    <div class="transition-all duration-300 lg:block hidden" x-data x-bind:class="$store.sidebar?.collapsed ? 'w-16' : 'w-72'"></div>

    <!-- Main Content Area -->
    <div class="flex-1 flex flex-col transition-all duration-300">
        <!-- Content Wrapper -->
        <main class="p-6 lg:p-8 flex-1">
            <div class="w-full">
                <div class="flex justify-between items-center mb-6">
                    <h1 class="text-3xl font-semibold text-gray-900 dark:text-white">Create Fee Structure</h1>
                    <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        <i class="fas fa-arrow-left mr-2"></i>Back
                    </a>
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

                <!-- Create Form -->
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow border border-gray-200 dark:border-gray-700">
                    <div class="p-6">
                        <form method="POST" class="space-y-6">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div>
                                    <label for="name" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Fee Structure Name *</label>
                                    <input type="text" id="name" name="name" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                           placeholder="e.g., Term 1 Fees 2024">
                                </div>

                                <div>
                                    <label for="academic_year" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Academic Year *</label>
                                    <input type="text" id="academic_year" name="academic_year" required
                                           value="<?php echo date('Y') . '-' . (date('Y') + 1); ?>"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                           placeholder="2024-2025">
                                </div>

                                <div>
                                    <label for="class_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Class (Optional)</label>
                                    <select id="class_id" name="class_id"
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">All Classes</option>
                                        <?php foreach ($classes as $class): ?>
                                            <option value="<?php echo $class['id']; ?>"><?php echo htmlspecialchars($class['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label for="fee_type" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Fee Type *</label>
                                    <select id="fee_type" name="fee_type" required
                                            class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                        <option value="">Select Fee Type</option>
                                        <option value="tuition">Tuition Fee</option>
                                        <option value="hostel">Hostel Fee</option>
                                        <option value="transport">Transport Fee</option>
                                        <option value="library">Library Fee</option>
                                        <option value="laboratory">Laboratory Fee</option>
                                        <option value="sports">Sports Fee</option>
                                        <option value="examination">Examination Fee</option>
                                        <option value="development">Development Fee</option>
                                        <option value="other">Other</option>
                                    </select>
                                </div>

                                <div>
                                    <label for="amount" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Amount (₵) *</label>
                                    <input type="number" id="amount" name="amount" step="0.01" min="0" required
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div>
                                    <label for="due_date" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Due Date</label>
                                    <input type="date" id="due_date" name="due_date"
                                           class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white">
                                </div>

                                <div class="md:col-span-2">
                                    <label for="description" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Description</label>
                                    <textarea id="description" name="description" rows="3"
                                              class="mt-1 block w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                                              placeholder="Enter fee description, payment terms, etc."></textarea>
                                </div>
                            </div>

                            <div class="flex justify-end space-x-3">
                                <a href="index.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg">
                                    Cancel
                                </a>
                                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg">
                                    <i class="fas fa-save mr-2"></i>Create Fee Structure
                                </button>
                            </div>
                        </form>
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